<?php
/**
 * ZarinPal REST API v4 client
 */
class ZarinpalHelper {
    /** ZarinPal platform minimum transaction amount in rials. */
    const MIN_AMOUNT_RIAL = 1000;

    /**
     * Rial↔accounting-unit factor. Prefers the installation setting, then the
     * config constant, then the historical default of 10 (toman).
     */
    public static function rialDivisor($settings = null) {
        $fromSettings = is_array($settings) ? floatval($settings['currency_rial_divisor'] ?? 0) : 0;
        if ($fromSettings > 0) return $fromSettings;
        if (defined('INTEGRATION_RIAL_TO_ACCOUNTING_DIVISOR') && floatval(INTEGRATION_RIAL_TO_ACCOUNTING_DIVISOR) > 0) {
            return floatval(INTEGRATION_RIAL_TO_ACCOUNTING_DIVISOR);
        }
        return 10.0;
    }

    public static function isSandbox($settings) {
        return (($settings['zarinpal_sandbox'] ?? '0') === '1');
    }

    public static function apiBase($sandbox) {
        return $sandbox
            ? 'https://sandbox.zarinpal.com/pg/v4/payment/'
            : 'https://api.zarinpal.com/pg/v4/payment/';
    }

    public static function startPayUrl($authority, $sandbox) {
        $host = $sandbox ? 'https://sandbox.zarinpal.com' : 'https://www.zarinpal.com';
        return $host . '/pg/StartPay/' . rawurlencode($authority);
    }

    /**
     * Convert accounting amount (usually toman) to ZarinPal rials.
     */
    public static function toRials($amountAccounting, $currencyUnit = 'toman', $settings = null) {
        $amount = floatval($amountAccounting);
        if ($amount <= 0) return 0;
        if (($currencyUnit ?? 'toman') === 'rial') {
            return (int) round($amount);
        }
        return (int) round($amount * self::rialDivisor($settings));
    }

    public static function requestPayment(array $settings, $amountRial, $callbackUrl, $description, $mobile = null) {
        $merchantId = trim((string)($settings['zarinpal_merchant_id'] ?? ''));
        if ($merchantId === '') {
            return ['ok' => false, 'error' => 'مرچنت‌آیدی زرین‌پال تنظیم نشده است.'];
        }
        if ($amountRial < self::MIN_AMOUNT_RIAL) {
            return ['ok' => false, 'error' => 'حداقل مبلغ پرداخت ' . number_format(self::MIN_AMOUNT_RIAL) . ' ریال است.'];
        }

        $payload = [
            'merchant_id' => $merchantId,
            'amount' => (int)$amountRial,
            'callback_url' => $callbackUrl,
            'description' => mb_substr((string)$description, 0, 255),
        ];
        $meta = [];
        if ($mobile) {
            $meta['mobile'] = preg_replace('/\D+/', '', (string)$mobile);
        }
        if ($meta) {
            $payload['metadata'] = $meta;
        }

        $sandbox = self::isSandbox($settings);
        $res = self::postJson(self::apiBase($sandbox) . 'request.json', $payload);
        if (!$res['ok']) {
            return $res;
        }
        $code = intval($res['data']['data']['code'] ?? $res['data']['errors']['code'] ?? 0);
        $authority = $res['data']['data']['authority'] ?? '';
        if ($code !== 100 || $authority === '') {
            $msg = $res['data']['errors']['message']
                ?? $res['data']['data']['message']
                ?? 'خطا در ایجاد تراکنش زرین‌پال';
            return ['ok' => false, 'error' => $msg, 'code' => $code, 'raw' => $res['data']];
        }
        return [
            'ok' => true,
            'authority' => $authority,
            'fee' => $res['data']['data']['fee'] ?? null,
            'start_url' => self::startPayUrl($authority, $sandbox),
        ];
    }

    public static function verifyPayment(array $settings, $amountRial, $authority) {
        $merchantId = trim((string)($settings['zarinpal_merchant_id'] ?? ''));
        if ($merchantId === '' || $authority === '') {
            return ['ok' => false, 'error' => 'اطلاعات تأیید پرداخت ناقص است.'];
        }
        $payload = [
            'merchant_id' => $merchantId,
            'amount' => (int)$amountRial,
            'authority' => $authority,
        ];
        $sandbox = self::isSandbox($settings);
        $res = self::postJson(self::apiBase($sandbox) . 'verify.json', $payload);
        if (!$res['ok']) {
            return $res;
        }
        $code = intval($res['data']['data']['code'] ?? 0);
        // 100 = first verify, 101 = already verified
        if ($code !== 100 && $code !== 101) {
            $msg = $res['data']['errors']['message']
                ?? $res['data']['data']['message']
                ?? 'تأیید پرداخت ناموفق بود';
            return ['ok' => false, 'error' => $msg, 'code' => $code, 'raw' => $res['data']];
        }
        return [
            'ok' => true,
            'code' => $code,
            'ref_id' => (string)($res['data']['data']['ref_id'] ?? ''),
            'card_pan' => $res['data']['data']['card_pan'] ?? null,
            'raw' => $res['data'],
        ];
    }

    private static function postJson($url, array $payload) {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_TIMEOUT => 45,
                CURLOPT_CONNECTTIMEOUT => 15,
            ]);
            $raw = curl_exec($ch);
            $err = curl_error($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($raw === false) {
                return ['ok' => false, 'error' => 'ارتباط با زرین‌پال برقرار نشد: ' . $err];
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                return ['ok' => false, 'error' => 'پاسخ نامعتبر از زرین‌پال (HTTP ' . $status . ')'];
            }
            return ['ok' => true, 'data' => $data, 'http' => $status];
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content' => $body,
                'timeout' => 45,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            return ['ok' => false, 'error' => 'ارتباط با زرین‌پال برقرار نشد.'];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return ['ok' => false, 'error' => 'پاسخ نامعتبر از زرین‌پال'];
        }
        return ['ok' => true, 'data' => $data];
    }
}
