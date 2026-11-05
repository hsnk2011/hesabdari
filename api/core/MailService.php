<?php
// /api/core/MailService.php

class MailService {
    private static $settingKeys = [
        'smtp_enabled',
        'smtp_host',
        'smtp_port',
        'smtp_encryption',
        'smtp_username',
        'smtp_password',
        'mail_from_address',
        'mail_from_name',
        'backup_email_to',
    ];

    public static function getSettings($conn) {
        $settings = [];
        $keys = implode("','", self::$settingKeys);
        $res = $conn->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('{$keys}')");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        }
        return $settings;
    }

    public static function isConfigured(array $settings) {
        return ($settings['smtp_enabled'] ?? '0') === '1'
            && trim((string)($settings['smtp_host'] ?? '')) !== ''
            && trim((string)($settings['mail_from_address'] ?? '')) !== '';
    }

    public static function parseRecipients($raw) {
        $list = [];
        foreach (preg_split('/[;,]+/', (string)$raw) as $part) {
            $email = trim($part);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $list[] = $email;
            }
        }
        return array_values(array_unique($list));
    }

    /**
     * @param array<int,array{path:string,filename:string,mime?:string}> $attachments
     */
    public static function send($conn, $to, $subject, $htmlBody, $textBody = '', array $attachments = []) {
        $settings = self::getSettings($conn);
        if (!self::isConfigured($settings)) {
            return ['error' => 'تنظیمات SMTP فعال یا کامل نیست.'];
        }

        $recipients = is_array($to) ? $to : self::parseRecipients($to);
        if (empty($recipients)) {
            return ['error' => 'آدرس ایمیل گیرنده نامعتبر است.'];
        }

        $fromEmail = trim((string)$settings['mail_from_address']);
        $fromName = trim((string)($settings['mail_from_name'] ?? ''));
        $subject = trim((string)$subject);
        if ($subject === '') {
            $subject = 'پیام از سیستم حسابداری';
        }

        if ($textBody === '') {
            $textBody = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody)));
        }

        try {
            $smtp = new SmtpMailer(
                trim((string)$settings['smtp_host']),
                intval($settings['smtp_port'] ?: 587),
                strtolower(trim((string)($settings['smtp_encryption'] ?? 'tls'))),
                trim((string)($settings['smtp_username'] ?? '')),
                (string)($settings['smtp_password'] ?? '')
            );
            $smtp->sendMail($fromEmail, $fromName, $recipients, $subject, $htmlBody, $textBody, $attachments);
            return ['success' => true, 'sent_to' => $recipients];
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    public static function sendTest($conn, $to = null) {
        $settings = self::getSettings($conn);
        $recipients = $to ? self::parseRecipients($to) : self::parseRecipients($settings['backup_email_to'] ?? '');
        if (empty($recipients)) {
            return ['error' => 'ایمیل گیرنده تست را وارد کنید یا «ایمیل دریافت بکاپ» را تنظیم کنید.'];
        }
        $site = trim((string)($settings['mail_from_name'] ?? 'سیستم حسابداری'));
        $html = '<p>این یک ایمیل آزمایشی از <strong>' . htmlspecialchars($site, ENT_QUOTES, 'UTF-8') . '</strong> است.</p>'
            . '<p>تاریخ: ' . date('Y-m-d H:i:s') . '</p>';
        $result = self::send($conn, $recipients, 'تست ایمیل — ' . $site, $html);
        if (!empty($result['success'])) {
            $result['message'] = 'ایمیل آزمایشی ارسال شد.';
        }
        return $result;
    }
}

class SmtpMailer {
    private $host;
    private $port;
    private $encryption;
    private $username;
    private $password;
    /** @var resource|null */
    private $socket;

    public function __construct($host, $port, $encryption, $username, $password) {
        $this->host = $host;
        $this->port = $port > 0 ? $port : 587;
        $this->encryption = in_array($encryption, ['none', 'tls', 'ssl'], true) ? $encryption : 'tls';
        $this->username = $username;
        $this->password = $password;
    }

    public function sendMail($fromEmail, $fromName, array $to, $subject, $htmlBody, $textBody, array $attachments) {
        $ioTimeout = empty($attachments) ? 30 : 600;
        $this->connect($ioTimeout);
        try {
            $this->ehlo();
            if ($this->encryption === 'tls') {
                $this->cmd('STARTTLS', [220]);
                if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new Exception('برقراری TLS با سرور SMTP ناموفق بود.');
                }
                $this->ehlo();
            }
            if ($this->username !== '') {
                $this->authenticate();
            }
            $this->cmd('MAIL FROM:<' . $fromEmail . '>', [250]);
            foreach ($to as $recipient) {
                $this->cmd('RCPT TO:<' . $recipient . '>', [250, 251]);
            }
            $this->cmd('DATA', [354]);
            $message = $this->buildMessage($fromEmail, $fromName, $to, $subject, $htmlBody, $textBody, $attachments);
            fwrite($this->socket, $message . "\r\n.\r\n");
            $this->expect([250]);
        } finally {
            $this->quit();
        }
    }

    private function connect($ioTimeout = 30) {
        $remote = $this->host;
        if ($this->encryption === 'ssl') {
            $remote = 'ssl://' . $this->host;
        }
        $errno = 0;
        $errstr = '';
        $this->socket = @stream_socket_client(
            $remote . ':' . $this->port,
            $errno,
            $errstr,
            $ioTimeout,
            STREAM_CLIENT_CONNECT
        );
        if (!$this->socket) {
            throw new Exception('اتصال به SMTP ناموفق: ' . ($errstr ?: $errno));
        }
        stream_set_timeout($this->socket, $ioTimeout);
        $this->expect([220]);
    }

    private function ehlo() {
        $host = gethostname() ?: 'localhost';
        $this->cmd('EHLO ' . $host, [250]);
    }

    private function authenticate() {
        $this->cmd('AUTH LOGIN', [334]);
        $this->cmd(base64_encode($this->username), [334]);
        $this->cmd(base64_encode($this->password), [235]);
    }

    private function quit() {
        if ($this->socket) {
            @fwrite($this->socket, "QUIT\r\n");
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    private function cmd($command, array $okCodes) {
        fwrite($this->socket, $command . "\r\n");
        $this->expect($okCodes);
    }

    private function expect(array $okCodes) {
        $response = '';
        while (($line = fgets($this->socket, 515)) !== false) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        if ($response === '') {
            throw new Exception('پاسخی از سرور SMTP دریافت نشد.');
        }
        $code = intval(substr($response, 0, 3));
        if (!in_array($code, $okCodes, true)) {
            throw new Exception('خطای SMTP: ' . trim($response));
        }
    }

    private function buildMessage($fromEmail, $fromName, array $to, $subject, $htmlBody, $textBody, array $attachments) {
        $boundary = 'b_' . bin2hex(random_bytes(8));
        $fromHeader = $fromName !== ''
            ? '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . $fromEmail . '>'
            : $fromEmail;
        $headers = [
            'Date: ' . date('r'),
            'From: ' . $fromHeader,
            'To: ' . implode(', ', $to),
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'MIME-Version: 1.0',
        ];

        if (empty($attachments)) {
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: base64';
            return implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($htmlBody));
        }

        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
        $body = '--' . $boundary . "\r\n";
        $body .= "Content-Type: multipart/alternative; boundary=\"{$boundary}_alt\"\r\n\r\n";
        $body .= '--' . $boundary . "_alt\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($textBody)) . "\r\n";
        $body .= '--' . $boundary . "_alt\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($htmlBody)) . "\r\n";
        $body .= '--' . $boundary . "_alt--\r\n";

        foreach ($attachments as $att) {
            $path = $att['path'] ?? '';
            $filename = $att['filename'] ?? basename($path);
            if (!is_file($path)) {
                continue;
            }
            $mime = $att['mime'] ?? 'application/octet-stream';
            $data = file_get_contents($path);
            if ($data === false) {
                continue;
            }
            $safeName = preg_replace('/[^\w\.\-]+/u', '_', $filename);
            $body .= '--' . $boundary . "\r\n";
            $body .= 'Content-Type: ' . $mime . '; name="' . $safeName . "\"\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n";
            $body .= 'Content-Disposition: attachment; filename="' . $safeName . "\"\r\n\r\n";
            $body .= chunk_split(base64_encode($data)) . "\r\n";
        }

        $body .= '--' . $boundary . "--\r\n";
        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }
}
