<?php
/**
 * Start ZarinPal payment for a public invoice share link.
 * POST: token, amount
 */
require_once __DIR__ . '/api/config.php';
require_once __DIR__ . '/api/core/helpers.php';
require_once __DIR__ . '/api/models/Settings.php';
require_once __DIR__ . '/api/models/OnlinePayment.php';
require_once __DIR__ . '/api/helpers/ZarinpalHelper.php';

function pay_fail_redirect($token, $msg) {
    $q = http_build_query(['c' => $token, 'pay' => 'error', 'msg' => $msg]);
    header('Location: ' . rtrim(app_public_base_url(), '/') . '/i.php?' . $q);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

$token = trim((string)($_POST['token'] ?? ''));
$amount = floatval(str_replace(',', '', (string)($_POST['amount'] ?? '0')));

$tokenOk = $token !== '' && (
    preg_match('/^[a-f0-9]{64}$/', $token)
    || preg_match('/^[A-Za-z0-9]{6,16}$/', $token)
);
if (!$tokenOk) {
    http_response_code(400);
    echo 'لینک نامعتبر است.';
    exit;
}

$db = @new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
if ($db->connect_error) {
    http_response_code(500);
    echo 'خطا در اتصال به سرور.';
    exit;
}
$db->set_charset('utf8mb4');

$settingsModel = new Settings($db);
$all = $settingsModel->getAllSettings();
if (($all['zarinpal_enabled'] ?? '0') !== '1') {
    pay_fail_redirect($token, 'پرداخت آنلاین غیرفعال است.');
}
if (trim((string)($all['zarinpal_merchant_id'] ?? '')) === '') {
    pay_fail_redirect($token, 'درگاه پرداخت پیکربندی نشده است.');
}

$stmt = $db->prepare("SELECT invoice_type, invoice_id, expires_at FROM proforma_links WHERE token = ? AND revoked_at IS NULL LIMIT 1");
$stmt->bind_param('s', $token);
$stmt->execute();
$link = (db_stmt_to_assoc_array($stmt)[0] ?? null);
if (!$link) {
    pay_fail_redirect($token, 'لینک یافت نشد.');
}
if (strtotime($link['expires_at']) < time()) {
    pay_fail_redirect($token, 'مهلت لینک به پایان رسیده است.');
}
if (($link['invoice_type'] ?? '') !== 'sales') {
    pay_fail_redirect($token, 'پرداخت آنلاین فقط برای فاکتور فروش فعال است.');
}

$invoiceId = intval($link['invoice_id']);
$istmt = $db->prepare("SELECT * FROM sales_invoices WHERE id = ? LIMIT 1");
$istmt->bind_param('i', $invoiceId);
$istmt->execute();
$invoice = (db_stmt_to_assoc_array($istmt)[0] ?? null);
if (!$invoice) {
    pay_fail_redirect($token, 'فاکتور یافت نشد.');
}

$grand = floatval($invoice['totalAmount']) - floatval($invoice['discount']) + floatval($invoice['tax_amount'] ?? 0);
$remaining = $grand - floatval($invoice['paidAmount'] ?? 0);
if ($remaining <= 0) {
    pay_fail_redirect($token, 'مانده‌ای برای پرداخت وجود ندارد.');
}

if ($amount <= 0) {
    $amount = $remaining;
}
$amount = round($amount, 0);
if ($amount > $remaining) {
    $amount = $remaining;
}
if ($amount < 1) {
    pay_fail_redirect($token, 'مبلغ پرداخت نامعتبر است.');
}

$unit = (($all['zarinpal_currency_unit'] ?? 'toman') === 'rial') ? 'rial' : 'toman';
$amountRial = ZarinpalHelper::toRials($amount, $unit, $all);
if ($amountRial < ZarinpalHelper::MIN_AMOUNT_RIAL) {
    pay_fail_redirect($token, 'مبلغ کمتر از حداقل درگاه است.');
}

$mobile = '';
$customerId = intval($invoice['customerId'] ?? 0);
if ($customerId > 0) {
    $cstmt = $db->prepare("SELECT phone FROM customers WHERE id = ? LIMIT 1");
    $cstmt->bind_param('i', $customerId);
    $cstmt->execute();
    $crow = db_stmt_to_assoc_array($cstmt)[0] ?? null;
    $mobile = $crow['phone'] ?? '';
}

$invNo = $invoice['invoice_number'] ?: $invoice['id'];
$desc = 'پرداخت فاکتور فروش #' . $invNo;

try {
    $onlineModel = new OnlinePayment($db);
    $opId = $onlineModel->createPending([
        'entity_id' => intval($invoice['entity_id'] ?? (defined('DEFAULT_ENTITY_ID') ? DEFAULT_ENTITY_ID : 0)),
        'invoice_type' => 'sales',
        'invoice_id' => $invoiceId,
        'share_token' => $token,
        'amount' => $amount,
        'amount_rial' => $amountRial,
        'customer_mobile' => $mobile,
        'description' => $desc,
    ]);

    $callbackUrl = rtrim(app_public_base_url(), '/') . '/pay_callback.php';
    $req = ZarinpalHelper::requestPayment($all, $amountRial, $callbackUrl, $desc, $mobile);
    if (empty($req['ok'])) {
        $onlineModel->markFailed($opId, $req['error'] ?? 'request failed');
        pay_fail_redirect($token, $req['error'] ?? 'خطا در اتصال به درگاه');
    }
    $onlineModel->setAuthority($opId, $req['authority']);
    header('Location: ' . $req['start_url']);
    exit;
} catch (Throwable $e) {
    pay_fail_redirect($token, $e->getMessage() ?: 'خطای پرداخت');
}
