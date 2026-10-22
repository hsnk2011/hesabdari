<?php
/**
 * ZarinPal callback — verify payment and record receipt on sales invoice.
 * Booking is shared with the reconciliation job (OnlinePayment::bookVerified),
 * which also picks up payments whose callback never arrived.
 */
require_once __DIR__ . '/api/config.php';
require_once __DIR__ . '/api/core/helpers.php';
require_once __DIR__ . '/api/models/Settings.php';
require_once __DIR__ . '/api/models/OnlinePayment.php';
require_once __DIR__ . '/api/models/Payment.php';
require_once __DIR__ . '/api/helpers/ZarinpalHelper.php';

function pay_cb_redirect($token, $status, $msg = '', $ref = '') {
    $params = ['c' => $token, 'pay' => $status];
    if ($msg !== '') $params['msg'] = $msg;
    if ($ref !== '') $params['ref'] = $ref;
    header('Location: ' . rtrim(app_public_base_url(), '/') . '/i.php?' . http_build_query($params));
    exit;
}

$authority = trim((string)($_GET['Authority'] ?? $_GET['authority'] ?? ''));
$status = strtoupper(trim((string)($_GET['Status'] ?? $_GET['status'] ?? '')));

$db = @new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
if ($db->connect_error) {
    http_response_code(500);
    echo 'خطا در اتصال به سرور.';
    exit;
}
$db->set_charset('utf8mb4');
db_sync_timezone($db);

$onlineModel = new OnlinePayment($db);
$op = $authority !== '' ? $onlineModel->findByAuthority($authority) : null;
if (!$op) {
    http_response_code(404);
    echo 'تراکنش یافت نشد.';
    exit;
}

$token = $op['share_token'];

if ($op['status'] === 'paid') {
    pay_cb_redirect($token, 'ok', '', (string)($op['ref_id'] ?? ''));
}

if ($status !== 'OK') {
    $onlineModel->markFailed(intval($op['id']), 'cancelled_by_user');
    pay_cb_redirect($token, 'cancel', 'پرداخت توسط کاربر لغو شد.');
}

$settingsModel = new Settings($db);
$all = $settingsModel->getAllSettings();
$verify = ZarinpalHelper::verifyPayment($all, intval($op['amount_rial']), $authority);
if (empty($verify['ok'])) {
    // Keep it pending: a transient verify failure is retried by the
    // reconciliation job instead of losing a payment the customer made.
    pay_cb_redirect($token, 'error', ($verify['error'] ?? 'تأیید پرداخت ناموفق بود.') . ' در صورت کسر وجه، پرداخت ظرف چند دقیقه به‌صورت خودکار بررسی و ثبت می‌شود.');
}

$refId = (string)($verify['ref_id'] ?? '');
$result = OnlinePayment::bookVerified($db, $op, $refId);
if (!empty($result['ok'])) {
    pay_cb_redirect($token, 'ok', $result['message'] === 'پرداخت ثبت شد.' ? '' : $result['message'], $refId);
}
pay_cb_redirect($token, 'error', $result['message'] ?? 'ثبت پرداخت ناموفق بود.', $refId);
