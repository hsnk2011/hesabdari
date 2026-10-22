<?php
require_once __DIR__ . '/../models/Settings.php';

class PaymentGatewayController {
    private $conn;
    private $settingsModel;

    public function __construct($db) {
        $this->conn = $db;
        $this->settingsModel = new Settings($db);
    }

    private function ensureOnlinePaymentsTable() {
        require_once __DIR__ . '/../models/OnlinePayment.php';
        new OnlinePayment($this->conn);
    }

    private function maskMerchant($id) {
        $id = (string)$id;
        $len = strlen($id);
        if ($len <= 8) return $len ? str_repeat('*', $len) : '';
        return substr($id, 0, 4) . str_repeat('*', max(0, $len - 8)) . substr($id, -4);
    }

    private function readSettings() {
        $all = $this->settingsModel->getAllSettings();
        $merchant = trim((string)($all['zarinpal_merchant_id'] ?? ''));
        return [
            'zarinpal_enabled' => (($all['zarinpal_enabled'] ?? '0') === '1') ? '1' : '0',
            'zarinpal_sandbox' => (($all['zarinpal_sandbox'] ?? '0') === '1') ? '1' : '0',
            'zarinpal_currency_unit' => (($all['zarinpal_currency_unit'] ?? 'toman') === 'rial') ? 'rial' : 'toman',
            // Deposit account is per entity (accounts belong to one entity).
            'zarinpal_deposit_account_id' => trim((string)$this->entitySettings()->getValue((int)($_SESSION['current_entity_id'] ?? 0), 'zarinpal_deposit_account_id', ''))
                ?: trim((string)($all['zarinpal_deposit_account_id'] ?? '')),
            'zarinpal_merchant_id_masked' => $this->maskMerchant($merchant),
            'has_merchant_id' => $merchant !== '',
        ];
    }

    private function entitySettings() {
        require_once __DIR__ . '/../models/EntitySettings.php';
        static $es = null;
        if ($es === null) {
            $es = new EntitySettings($this->conn);
        }
        return $es;
    }

    private function toFlag($value) {
        if ($value === true || $value === 1 || $value === '1' || $value === 'true' || $value === 'on') {
            return '1';
        }
        return '0';
    }

    public function getZarinpalSettings($data) {
        check_permission('manage_settings');
        send_json([
            'success' => true,
            'settings' => $this->readSettings(),
        ]);
    }

    public function saveZarinpalSettings($data) {
        check_permission('manage_settings');
        $this->ensureOnlinePaymentsTable();

        $payload = [
            'zarinpal_enabled' => $this->toFlag($data['zarinpal_enabled'] ?? '0'),
            'zarinpal_sandbox' => $this->toFlag($data['zarinpal_sandbox'] ?? '0'),
            'zarinpal_currency_unit' => (($data['zarinpal_currency_unit'] ?? 'toman') === 'rial') ? 'rial' : 'toman',
        ];
        $depositId = (int)preg_replace('/\D+/', '', (string)($data['zarinpal_deposit_account_id'] ?? ''));
        $entityId = (int)($_SESSION['current_entity_id'] ?? 0);
        if ($depositId > 0) {
            $own = $this->conn->prepare("SELECT id FROM accounts WHERE id = ? AND entity_id = ?");
            $own->bind_param('ii', $depositId, $entityId);
            $own->execute();
            if (empty(db_stmt_to_assoc_array($own))) {
                send_json(['error' => 'حساب واریز انتخاب‌شده متعلق به مجموعه فعال نیست.'], 400);
                return;
            }
        }
        $this->entitySettings()->setValues($entityId, ['zarinpal_deposit_account_id' => $depositId > 0 ? (string)$depositId : '']);
        $merchant = trim((string)($data['zarinpal_merchant_id'] ?? ''));
        if ($merchant !== '' && strpos($merchant, '*') === false) {
            $payload['zarinpal_merchant_id'] = $merchant;
        }

        $ok = $this->settingsModel->saveAllSettings($payload);
        if (!$ok) {
            send_json(['error' => 'ذخیره تنظیمات ناموفق بود.'], 500);
            return;
        }

        send_json([
            'success' => true,
            'message' => 'تنظیمات درگاه زرین‌پال ذخیره شد.',
            'settings' => $this->readSettings(),
        ]);
    }

    /** پرداخت‌های آنلاین: list for reconciliation (current entity). */
    public function getOnlinePayments($data) {
        check_permission('manage_settings');
        require_once __DIR__ . '/../models/OnlinePayment.php';
        $status = in_array(($data['status'] ?? ''), ['pending', 'paid', 'failed', 'review'], true) ? $data['status'] : '';
        $model = new OnlinePayment($this->conn);
        send_json(['success' => true, 'items' => $model->listForEntity((int)$_SESSION['current_entity_id'], $status, 300)]);
    }

    /** Re-verify a pending/failed/review payment with ZarinPal and book it if paid. */
    public function retryOnlinePayment($data) {
        check_permission('manage_settings');
        require_once __DIR__ . '/../models/OnlinePayment.php';
        require_once __DIR__ . '/../helpers/ZarinpalHelper.php';
        $model = new OnlinePayment($this->conn);
        $op = $model->findById((int)($data['id'] ?? 0));
        if (!$op || (int)$op['entity_id'] !== (int)$_SESSION['current_entity_id']) {
            send_json(['error' => 'تراکنش یافت نشد.'], 404);
            return;
        }
        if ($op['status'] === 'paid') {
            send_json(['success' => true, 'message' => 'این تراکنش قبلاً ثبت شده است.']);
            return;
        }
        if (empty($op['authority'])) {
            send_json(['error' => 'این درخواست هرگز به درگاه ارسال نشده است.'], 400);
            return;
        }
        $verify = ZarinpalHelper::verifyPayment($this->settingsModel->getAllSettings(), (int)$op['amount_rial'], $op['authority']);
        if (empty($verify['ok'])) {
            send_json(['error' => 'زرین‌پال پرداخت را تأیید نکرد: ' . ($verify['error'] ?? '')], 400);
            return;
        }
        $res = OnlinePayment::bookVerified($this->conn, $op, (string)($verify['ref_id'] ?? ''));
        if (!empty($res['ok'])) {
            log_activity($this->conn, 'ONLINE_PAYMENT_RECONCILED', "پرداخت آنلاین #{$op['id']} پس از بررسی ثبت شد.");
            send_json(['success' => true, 'message' => $res['message']]);
        } else {
            send_json(['error' => $res['message']], 400);
        }
    }
}
