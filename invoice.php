<?php
/**
 * Public invoice viewer via share token (no login required).
 * URL: invoice.php?token=... (legacy 64-hex) or i.php?c=... / invoice.php?c=... (short)
 */
require_once __DIR__ . '/api/config.php';
require_once __DIR__ . '/api/core/helpers.php';

$token = trim((string)($_GET['c'] ?? $_GET['t'] ?? $_GET['token'] ?? ''));
$error = null;
$invoice = null;
$items = [];
$person = ['name' => '-', 'phone' => '-', 'address' => '-'];
$company = [
    'name' => '',
    'phone' => '',
    'address' => '',
    'footer' => '',
    'header' => '',
    'logo_url' => '',
    'show_logo' => false,
];
$paymentInfo = [
    'show' => false,
    'bank_name' => '',
    'account_number' => '',
    'card_number' => '',
    'sheba_number' => '',
    'account_holder' => '',
];
$invoicePayments = [];
$zarinpal = [
    'enabled' => false,
];
$title = 'فاکتور';
$isSales = true;
$payFlash = [
    'type' => trim((string)($_GET['pay'] ?? '')),
    'msg' => trim((string)($_GET['msg'] ?? '')),
    'ref' => trim((string)($_GET['ref'] ?? '')),
];

$tokenOk = $token !== '' && (
    preg_match('/^[a-f0-9]{64}$/', $token)
    || preg_match('/^[A-Za-z0-9]{6,16}$/', $token)
);

if (!$tokenOk) {
    $error = 'لینک نامعتبر است.';
} else {
    $db = @new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
    if ($db->connect_error) {
        $error = 'خطا در اتصال به سرور.';
    } else {
        $db->set_charset('utf8mb4');
        $stmt = $db->prepare(
            "SELECT invoice_type, invoice_id, expires_at
             FROM proforma_links WHERE token = ? AND revoked_at IS NULL LIMIT 1"
        );
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $linkRows = db_stmt_to_assoc_array($stmt);
        $link = $linkRows[0] ?? null;

        if (!$link) {
            $error = 'لینک یافت نشد یا باطل شده است.';
        } elseif (strtotime($link['expires_at']) < time()) {
            $error = 'مهلت این لینک به پایان رسیده است.';
        } else {
            $type = $link['invoice_type'];
            $invoiceId = intval($link['invoice_id']);
            $isSales = $type === 'sales';
            $table = $isSales ? 'sales_invoices' : 'purchase_invoices';
            $itemsTable = $isSales ? 'sales_invoice_items' : 'purchase_invoice_items';
            $personTable = $isSales ? 'customers' : 'suppliers';
            $personFk = $isSales ? 'customerId' : 'supplierId';
            $title = $isSales ? 'فاکتور فروش' : 'فاکتور خرید';

            $istmt = $db->prepare("SELECT * FROM `{$table}` WHERE id = ? LIMIT 1");
            $istmt->bind_param('i', $invoiceId);
            $istmt->execute();
            $invRows = db_stmt_to_assoc_array($istmt);
            $invoice = $invRows[0] ?? null;

            if (!$invoice) {
                $error = 'فاکتور مربوط به این لینک یافت نشد.';
            } else {
                $personId = intval($invoice[$personFk] ?? 0);
                if ($personId > 0) {
                    $pstmt = $db->prepare("SELECT name, phone, address FROM `{$personTable}` WHERE id = ? LIMIT 1");
                    $pstmt->bind_param('i', $personId);
                    $pstmt->execute();
                    $prows = db_stmt_to_assoc_array($pstmt);
                    if (!empty($prows[0])) {
                        $person = [
                            'name' => $prows[0]['name'] ?: '-',
                            'phone' => $prows[0]['phone'] ?: '-',
                            'address' => $prows[0]['address'] ?: '-',
                        ];
                    }
                }

                $itStmt = $db->prepare(
                    "SELECT i.*, p.name AS product_name
                     FROM `{$itemsTable}` i
                     LEFT JOIN products p ON p.id = i.productId
                     WHERE i.invoiceId = ?
                     ORDER BY i.id ASC"
                );
                $itStmt->bind_param('i', $invoiceId);
                $itStmt->execute();
                $items = db_stmt_to_assoc_array($itStmt);

                // جزئیات پرداخت‌های ثبت‌شده روی فاکتور (مثل چاپ عادی)
                $invoiceTypeKey = $isSales ? 'sales' : 'purchase';
                $entityIdForPay = intval($invoice['entity_id'] ?? 0);
                $payStmt = $db->prepare(
                    "SELECT * FROM payments
                     WHERE invoiceType = ? AND invoiceId = ?
                       AND (entity_id = ? OR ? = 0)
                     ORDER BY date ASC, id ASC"
                );
                $payStmt->bind_param('siii', $invoiceTypeKey, $invoiceId, $entityIdForPay, $entityIdForPay);
                $payStmt->execute();
                $rawPayments = db_stmt_to_assoc_array($payStmt);
                $checkIds = [];
                foreach ($rawPayments as $p) {
                    if (!empty($p['checkId'])) {
                        $checkIds[] = intval($p['checkId']);
                    }
                }
                $checksById = [];
                if (!empty($checkIds)) {
                    $checkIds = array_values(array_unique($checkIds));
                    $ph = implode(',', array_fill(0, count($checkIds), '?'));
                    $types = str_repeat('i', count($checkIds));
                    $cStmt = $db->prepare("SELECT * FROM checks WHERE id IN ($ph)");
                    $cStmt->bind_param($types, ...$checkIds);
                    $cStmt->execute();
                    foreach (db_stmt_to_assoc_array($cStmt) as $chk) {
                        $checksById[intval($chk['id'])] = $chk;
                    }
                }
                foreach ($rawPayments as $p) {
                    $type = $p['type'] ?? '';
                    // Setoff/tehatar is internal — customer sees it as a normal cash receipt.
                    $isSetoff = ($type === 'setoff');
                    $desc = '';
                    if (!$isSetoff) {
                        $desc = trim((string)($p['description'] ?? ''));
                        if ($type === 'check' && !empty($p['checkId']) && isset($checksById[intval($p['checkId'])])) {
                            $cd = $checksById[intval($p['checkId'])];
                            $dueFa = function_exists('gregorian_to_jalali') && !empty($cd['dueDate'])
                                ? gregorian_to_jalali($cd['dueDate'])
                                : ($cd['dueDate'] ?? '');
                            $desc = 'شماره: ' . ($cd['checkNumber'] ?? '-')
                                . '، سررسید: ' . ($dueFa ?: '-')
                                . '، بانک: ' . ($cd['bankName'] ?? '-');
                            if (!empty($cd['issuerName'])) {
                                $desc .= '، صادرکننده: ' . $cd['issuerName'];
                            }
                        } elseif ($type === 'endorse_check' && !empty($p['checkId']) && isset($checksById[intval($p['checkId'])])) {
                            $cd = $checksById[intval($p['checkId'])];
                            $desc = 'چک شماره: ' . ($cd['checkNumber'] ?? '-');
                        }
                    }
                    $typeLabel = match ($type) {
                        'cash', 'setoff' => 'نقد',
                        'check' => 'چک',
                        'endorse_check' => 'خرج چک',
                        default => ($type ?: '-'),
                    };
                    $txn = $p['transaction_type'] ?? ($isSales ? 'receipt' : 'payment');
                    $dirLabel = ($txn === 'payment') ? 'پرداخت' : 'دریافت';
                    if ($isSales) {
                        $settlingSign = ($txn === 'receipt') ? 1 : -1;
                    } else {
                        $settlingSign = ($txn === 'payment') ? 1 : -1;
                    }
                    $invoicePayments[] = [
                        'type' => $isSetoff ? 'cash' : $type,
                        'type_label' => $typeLabel,
                        'transaction_type' => $txn,
                        'direction_label' => $dirLabel,
                        'settling_sign' => $settlingSign,
                        'date' => $p['date'] ?? '',
                        'date_fa' => (function_exists('gregorian_to_jalali') && !empty($p['date']))
                            ? gregorian_to_jalali($p['date'])
                            : ($p['date'] ?? ''),
                        'amount' => floatval($p['amount'] ?? 0),
                        // Customer link never shows payment notes (internal / setoff text).
                        'description' => '',
                    ];
                }

                $entityId = intval($invoice['entity_id'] ?? 0);
                $entityName = '';
                if ($entityId > 0) {
                    $entStmt = $db->prepare('SELECT name FROM business_entities WHERE id = ? LIMIT 1');
                    $entStmt->bind_param('i', $entityId);
                    $entStmt->execute();
                    $entRows = db_stmt_to_assoc_array($entStmt);
                    if (!empty($entRows[0]['name'])) {
                        $entityName = trim((string)$entRows[0]['name']);
                    }
                }

                $printKeys = [
                    'print_company_name','print_company_phone','print_company_address','print_footer_text','print_header_text',
                    'print_logo_url','print_show_logo','print_show_payment_info',
                    'print_bank_name','print_account_number','print_card_number','print_sheba_number','print_account_holder',
                    'app_title','zarinpal_enabled','zarinpal_merchant_id',
                ];
                $placeholders = implode(',', array_fill(0, count($printKeys), '?'));
                $types = str_repeat('s', count($printKeys));
                $mergedPrint = [];
                $sstmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ($placeholders)");
                $sstmt->bind_param($types, ...$printKeys);
                $sstmt->execute();
                foreach (db_stmt_to_assoc_array($sstmt) as $row) {
                    $mergedPrint[$row['setting_key']] = $row['setting_value'];
                }

                $entityPrint = [];
                if ($entityId > 0) {
                    $entityKeys = [
                        'print_company_name','print_company_phone','print_company_address','print_footer_text','print_header_text',
                        'print_logo_url','print_show_logo','print_show_payment_info',
                        'print_bank_name','print_account_number','print_card_number','print_sheba_number','print_account_holder',
                    ];
                    $eph = implode(',', array_fill(0, count($entityKeys), '?'));
                    $etypes = 'i' . str_repeat('s', count($entityKeys));
                    $esStmt = $db->prepare(
                        "SELECT setting_key, setting_value FROM entity_settings
                         WHERE entity_id = ? AND setting_key IN ($eph)"
                    );
                    $esParams = array_merge([$entityId], $entityKeys);
                    $esStmt->bind_param($etypes, ...$esParams);
                    $esStmt->execute();
                    foreach (db_stmt_to_assoc_array($esStmt) as $row) {
                        $entityPrint[$row['setting_key']] = $row['setting_value'];
                        $mergedPrint[$row['setting_key']] = $row['setting_value'];
                    }
                }

                $companyName = trim((string)($entityPrint['print_company_name'] ?? ''));
                if ($companyName === '') {
                    $companyName = $entityName;
                }
                if ($companyName === '') {
                    $companyName = trim((string)($mergedPrint['print_company_name'] ?? ''));
                }
                if ($companyName === '') {
                    $companyName = trim((string)($mergedPrint['app_title'] ?? ''));
                }

                $pick = function ($key) use ($entityPrint, $mergedPrint) {
                    if (array_key_exists($key, $entityPrint) && trim((string)$entityPrint[$key]) !== '') {
                        return trim((string)$entityPrint[$key]);
                    }
                    return trim((string)($mergedPrint[$key] ?? ''));
                };

                $company = [
                    'name' => $companyName,
                    'phone' => $pick('print_company_phone'),
                    'address' => $pick('print_company_address'),
                    'footer' => $pick('print_footer_text'),
                    'header' => $pick('print_header_text'),
                    'logo_url' => $pick('print_logo_url'),
                    'show_logo' => (($entityPrint['print_show_logo'] ?? $mergedPrint['print_show_logo'] ?? '0') === '1'),
                ];

                $paymentInfo = [
                    'bank_name' => $pick('print_bank_name'),
                    'account_number' => $pick('print_account_number'),
                    'card_number' => $pick('print_card_number'),
                    'sheba_number' => $pick('print_sheba_number'),
                    'account_holder' => $pick('print_account_holder'),
                ];
                $hasAnyPaymentDetail = $paymentInfo['bank_name'] || $paymentInfo['account_number']
                    || $paymentInfo['card_number'] || $paymentInfo['sheba_number'] || $paymentInfo['account_holder'];
                // فقط اگر در تنظیمات چاپ «نمایش اطلاعات پرداخت» روشن باشد
                $showPaymentInfo = (($mergedPrint['print_show_payment_info'] ?? '0') === '1');
                $paymentInfo['show'] = $showPaymentInfo && $hasAnyPaymentDetail;

                $zarinpalEnabled = (($mergedPrint['zarinpal_enabled'] ?? '0') === '1');
                $zarinpalMerchant = trim((string)($mergedPrint['zarinpal_merchant_id'] ?? ''));
                $zarinpal = [
                    'enabled' => $isSales && $zarinpalEnabled && $zarinpalMerchant !== '',
                    'configured' => $zarinpalMerchant !== '',
                ];
            }
        }
        $db->close();
    }
}

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function money($n) {
    return number_format((float)$n, 0, '', ',');
}

$dateFa = '';
$net = 0;
$grand = 0;
$remaining = 0;
if ($invoice) {
    $dateFa = function_exists('gregorian_to_jalali') ? gregorian_to_jalali($invoice['date']) : $invoice['date'];
    $net = floatval($invoice['totalAmount']) - floatval($invoice['discount']);
    $grand = $net + floatval($invoice['tax_amount'] ?? 0);
    $remaining = $grand - floatval($invoice['paidAmount'] ?? 0);
}
$canPayOnline = !empty($zarinpal['enabled']) && $remaining > 0;
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo h($title); ?><?php echo $invoice ? ' #' . h($invoice['invoice_number'] ?: $invoice['id']) : ''; ?></title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Tahoma, Arial, sans-serif; margin: 0; padding: 20px; background: #f3f4f6; color: #111; }
        .card { max-width: 860px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 24px; box-shadow: 0 8px 24px rgba(0,0,0,.08); }
        .header { display: flex; justify-content: space-between; gap: 16px; border-bottom: 2px solid #111; padding-bottom: 12px; margin-bottom: 16px; }
        .brand { display: flex; gap: 12px; align-items: flex-start; }
        .brand img { max-height: 64px; max-width: 120px; object-fit: contain; }
        h1 { margin: 0; font-size: 20px; }
        h2 { margin: 0 0 10px; font-size: 16px; }
        .muted { color: #666; font-size: 13px; }
        .header-text { margin-top: 6px; font-size: 13px; color: #333; }
        .grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 16px; background: #f8fafc; padding: 12px; border-radius: 8px; }
        table { width: 100%; border-collapse: collapse; margin: 12px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: right; font-size: 13px; }
        th { background: #f1f5f9; }
        .totals { width: auto; margin-right: auto; }
        .totals td { border: none; padding: 4px 10px; }
        .totals tr:last-child { font-weight: bold; background: #e0f2fe; }
        .totals tr.remaining-credit td { background: #ecfdf5; color: #166534; }
        .totals tr.remaining-debt td { background: #fef2f2; color: #9b1c1c; }
        tr.pay-reverse { background: #fff5f5; }
        .amt-out { color: #b91c1c; font-weight: 600; }
        .amt-in { color: #166534; }
        .error { max-width: 640px; margin: 60px auto; background: #fff; padding: 28px; border-radius: 12px; text-align: center; color: #b91c1c; }
        .actions { margin-top: 18px; display: flex; gap: 8px; flex-wrap: wrap; }
        .actions button, .btn { border: 0; background: #0f172a; color: #fff; padding: 10px 14px; border-radius: 8px; cursor: pointer; font-size: 14px; }
        .actions .btn-download { background: #1d4ed8; }
        .btn-pay { background: #059669; width: 100%; }
        .btn-outline { background: #fff; color: #0f172a; border: 1px solid #cbd5e1; }
        .panel { margin-top: 18px; padding: 14px; border: 1px solid #e2e8f0; border-radius: 10px; background: #f8fafc; }
        .panel.pay { background: #ecfdf5; border-color: #a7f3d0; }
        .pay-row { display: flex; gap: 8px; flex-wrap: wrap; align-items: end; margin-top: 10px; }
        .pay-row label { display: block; font-size: 12px; color: #475569; margin-bottom: 4px; }
        .pay-row input { width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; }
        .pay-amount-wrap { flex: 1; min-width: 160px; }
        .flash { margin-bottom: 14px; padding: 12px 14px; border-radius: 8px; font-size: 13px; }
        .flash.ok { background: #dcfce7; color: #166534; }
        .flash.error { background: #fee2e2; color: #991b1b; }
        .flash.cancel { background: #fef3c7; color: #92400e; }
        .pay-details { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px 16px; font-size: 13px; }
        .pay-details div span { color: #64748b; display: block; font-size: 12px; }
        .pay-details strong { direction: ltr; unicode-bidi: plaintext; }
        @media print {
            body { background: #fff; padding: 0; }
            .actions, .no-print { display: none !important; }
            .card { box-shadow: none; border-radius: 0; }
        }
        @media (max-width: 640px) {
            .grid, .pay-details { grid-template-columns: 1fr; }
            .header { flex-direction: column; }
        }
    </style>
</head>
<body>
<?php if ($error): ?>
    <div class="error">
        <h1>مشاهده فاکتور</h1>
        <p><?php echo h($error); ?></p>
    </div>
<?php else: ?>
    <div class="card">
        <?php if ($payFlash['type'] === 'ok'): ?>
            <div class="flash ok no-print">
                پرداخت با موفقیت انجام شد.
                <?php if ($payFlash['ref']): ?> کد پیگیری: <strong dir="ltr"><?php echo h($payFlash['ref']); ?></strong><?php endif; ?>
            </div>
        <?php elseif ($payFlash['type'] === 'cancel'): ?>
            <div class="flash cancel no-print"><?php echo h($payFlash['msg'] ?: 'پرداخت لغو شد.'); ?></div>
        <?php elseif ($payFlash['type'] === 'error'): ?>
            <div class="flash error no-print"><?php echo h($payFlash['msg'] ?: 'خطا در پرداخت.'); ?></div>
        <?php endif; ?>

        <div class="header">
            <div class="brand">
                <?php
                $logoSrc = '';
                if (!empty($company['show_logo']) && !empty($company['logo_url'])) {
                    $logoSrc = $company['logo_url'];
                    if (!preg_match('#^(https?:)?//#i', $logoSrc) && strpos($logoSrc, 'data:') !== 0) {
                        $logoSrc = rtrim(app_public_base_url(), '/') . '/' . ltrim($logoSrc, '/');
                    }
                }
                ?>
                <?php if ($logoSrc): ?>
                    <img src="<?php echo h($logoSrc); ?>" alt="">
                <?php endif; ?>
                <div>
                    <h1><?php echo h($company['name'] ?: $title); ?></h1>
                    <div class="muted"><?php echo h($title); ?></div>
                    <?php if ($company['header']): ?><div class="header-text"><?php echo nl2br(h($company['header'])); ?></div><?php endif; ?>
                    <?php if ($company['phone']): ?><div class="muted">تلفن: <?php echo h($company['phone']); ?></div><?php endif; ?>
                    <?php if ($company['address']): ?><div class="muted">آدرس: <?php echo h($company['address']); ?></div><?php endif; ?>
                </div>
            </div>
            <div>
                <div><strong>شماره:</strong> <?php echo h($invoice['invoice_number'] ?: $invoice['id']); ?></div>
                <div><strong>تاریخ:</strong> <?php echo h($dateFa); ?></div>
            </div>
        </div>

        <div class="grid">
            <div><strong><?php echo $isSales ? 'مشتری' : 'تأمین‌کننده'; ?>:</strong> <?php echo h($person['name']); ?></div>
            <div><strong>تلفن:</strong> <?php echo h($person['phone']); ?></div>
            <div><strong>آدرس:</strong> <?php echo h($person['address']); ?></div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>شرح</th>
                    <th>ابعاد</th>
                    <th>تعداد</th>
                    <th>قیمت واحد</th>
                    <th>مبلغ</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $idx => $item):
                $name = !empty($item['is_service']) ? ($item['item_description'] ?: 'خدمات') : ($item['product_name'] ?: 'کالا');
                $dims = !empty($item['is_service']) ? '-' : ($item['dimensions'] ?: '-');
                $line = floatval($item['quantity']) * floatval($item['unitPrice']);
            ?>
                <tr>
                    <td><?php echo $idx + 1; ?></td>
                    <td><?php echo h($name); ?><?php if (!empty($item['is_service'])): ?> <small>(خدمات)</small><?php endif; ?></td>
                    <td><?php echo h($dims); ?></td>
                    <td><?php echo h($item['quantity']); ?></td>
                    <td><?php echo money($item['unitPrice']); ?></td>
                    <td><?php echo money($line); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if (!empty($invoicePayments)): ?>
        <h2 style="margin-top:18px;">جزئیات پرداخت</h2>
        <table>
            <thead>
                <tr>
                    <th>جهت</th>
                    <th>نوع</th>
                    <th>تاریخ</th>
                    <th>مبلغ</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($invoicePayments as $pay):
                $isReverse = (($pay['settling_sign'] ?? 1) < 0);
            ?>
                <tr class="<?php echo $isReverse ? 'pay-reverse' : ''; ?>">
                    <td><?php echo h($pay['direction_label'] ?? $pay['type_label']); ?></td>
                    <td><?php echo h($pay['type_label']); ?></td>
                    <td><?php echo h($pay['date_fa']); ?></td>
                    <td>
                        <?php if ($isReverse): ?>
                            <span class="amt-out">− <?php echo money($pay['amount']); ?></span>
                        <?php else: ?>
                            <span class="amt-in"><?php echo money($pay['amount']); ?></span>
                        <?php endif; ?>
                        <div class="muted">تومان</div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <table class="totals">
            <tr><td>جمع</td><td><?php echo money($invoice['totalAmount']); ?></td></tr>
            <?php if (floatval($invoice['discount'] ?? 0) > 0.01): ?>
            <tr><td>تخفیف</td><td><?php echo money($invoice['discount']); ?></td></tr>
            <?php endif; ?>
            <?php if (floatval($invoice['tax_amount'] ?? 0) > 0): ?>
            <tr><td>مالیات</td><td><?php echo money($invoice['tax_amount']); ?></td></tr>
            <?php endif; ?>
            <tr><td>قابل پرداخت</td><td><?php echo money($grand); ?></td></tr>
            <tr><td>پرداخت‌شده</td><td><?php echo money($invoice['paidAmount']); ?></td></tr>
            <tr><td>مانده</td><td><?php echo money($remaining); ?></td></tr>
        </table>

        <?php if (!empty($paymentInfo['show'])): ?>
        <div class="panel">
            <h2>اطلاعات پرداخت / کارت‌به‌کارت</h2>
            <div class="pay-details">
                <?php if ($paymentInfo['bank_name']): ?>
                    <div><span>بانک</span><strong><?php echo h($paymentInfo['bank_name']); ?></strong></div>
                <?php endif; ?>
                <?php if ($paymentInfo['account_holder']): ?>
                    <div><span>صاحب حساب</span><strong><?php echo h($paymentInfo['account_holder']); ?></strong></div>
                <?php endif; ?>
                <?php if ($paymentInfo['account_number']): ?>
                    <div><span>شماره حساب</span><strong><?php echo h($paymentInfo['account_number']); ?></strong></div>
                <?php endif; ?>
                <?php if ($paymentInfo['card_number']): ?>
                    <div><span>شماره کارت</span><strong><?php echo h($paymentInfo['card_number']); ?></strong></div>
                <?php endif; ?>
                <?php if ($paymentInfo['sheba_number']): ?>
                    <div><span>شبا</span><strong><?php echo h($paymentInfo['sheba_number']); ?></strong></div>
                <?php endif; ?>
            </div>
            <?php if ($remaining > 0): ?>
                <p class="muted" style="margin:10px 0 0;">مبلغ قابل واریز (مانده): <strong><?php echo money($remaining); ?></strong> تومان</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($canPayOnline): ?>
        <div class="panel pay no-print">
            <h2>پرداخت آنلاین</h2>
            <p class="muted" style="margin:0;">می‌توانید کل مانده یا مبلغ دلخواه تا سقف مانده را آنلاین پرداخت کنید.</p>
            <form method="post" action="pay.php" id="online-pay-form">
                <input type="hidden" name="token" value="<?php echo h($token); ?>">
                <input type="hidden" name="amount" id="pay-amount-raw" value="<?php echo (int)$remaining; ?>">
                <div class="pay-row">
                    <div class="pay-amount-wrap">
                        <label for="pay-amount">مبلغ پرداخت (تومان)</label>
                        <input type="text" id="pay-amount" inputmode="numeric" autocomplete="off"
                               value="<?php echo money($remaining); ?>" required
                               style="direction:ltr;text-align:left;font-variant-numeric:tabular-nums;">
                    </div>
                    <button type="button" class="btn btn-outline" id="btn-pay-full">کل مانده</button>
                </div>
                <div style="margin-top:12px;">
                    <button type="submit" class="btn btn-pay">پرداخت از طریق زرین‌پال</button>
                </div>
            </form>
        </div>
        <?php elseif ($isSales && $remaining <= 0): ?>
        <div class="panel no-print">
            <p class="muted" style="margin:0;">این فاکتور تسویه شده است.</p>
        </div>
        <?php endif; ?>

        <?php if ($company['footer']): ?>
            <p class="muted" style="margin-top:20px;"><?php echo nl2br(h($company['footer'])); ?></p>
        <?php endif; ?>

        <div class="actions">
            <button type="button" onclick="window.print()">چاپ</button>
            <button type="button" class="btn-download" id="btn-download-invoice">دانلود PDF</button>
        </div>
    </div>
    <script src="assets/js/html2canvas.min.js"></script>
    <script src="assets/js/jspdf.umd.min.js"></script>
    <script>
    (function () {
        var downloadBtn = document.getElementById('btn-download-invoice');
        if (!downloadBtn) return;
        var invNo = <?php echo json_encode($invoice ? (string)($invoice['invoice_number'] ?: $invoice['id']) : 'invoice', JSON_UNESCAPED_UNICODE); ?>;
        var fileBase = 'invoice-' + String(invNo).replace(/[^\w\-]+/g, '_');

        function setBusy(on) {
            downloadBtn.disabled = !!on;
            downloadBtn.textContent = on ? 'در حال آماده‌سازی…' : 'دانلود PDF';
        }

        function downloadBlob(blob, filename) {
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            a.remove();
            setTimeout(function () { URL.revokeObjectURL(url); }, 2000);
        }

        function buildPdfFromCanvas(canvas) {
            var jsPDF = (window.jspdf && window.jspdf.jsPDF) ? window.jspdf.jsPDF : null;
            if (!jsPDF) throw new Error('jsPDF unavailable');
            var pdf = new jsPDF({ orientation: 'p', unit: 'mm', format: 'a4' });
            var pageWidth = pdf.internal.pageSize.getWidth();
            var pageHeight = pdf.internal.pageSize.getHeight();
            var margin = 8;
            var usableWidth = pageWidth - margin * 2;
            var usableHeight = pageHeight - margin * 2;
            var imgWidth = usableWidth;
            var imgHeight = (canvas.height * imgWidth) / canvas.width;
            var imgData = canvas.toDataURL('image/jpeg', 0.92);

            if (imgHeight <= usableHeight) {
                pdf.addImage(imgData, 'JPEG', margin, margin, imgWidth, imgHeight);
                return pdf;
            }

            // چندصفحه‌ای برای فاکتورهای بلند
            var pageCanvas = document.createElement('canvas');
            var pageCtx = pageCanvas.getContext('2d');
            var sliceHeightPx = Math.floor((usableHeight * canvas.width) / imgWidth);
            var rendered = 0;
            var pageIndex = 0;
            while (rendered < canvas.height) {
                var h = Math.min(sliceHeightPx, canvas.height - rendered);
                pageCanvas.width = canvas.width;
                pageCanvas.height = h;
                pageCtx.fillStyle = '#ffffff';
                pageCtx.fillRect(0, 0, pageCanvas.width, pageCanvas.height);
                pageCtx.drawImage(canvas, 0, rendered, canvas.width, h, 0, 0, canvas.width, h);
                var sliceData = pageCanvas.toDataURL('image/jpeg', 0.92);
                var sliceMm = (h * imgWidth) / canvas.width;
                if (pageIndex > 0) pdf.addPage();
                pdf.addImage(sliceData, 'JPEG', margin, margin, imgWidth, sliceMm);
                rendered += h;
                pageIndex += 1;
            }
            return pdf;
        }

        downloadBtn.addEventListener('click', async function () {
            var card = document.querySelector('.card');
            if (!card) return;
            if (typeof html2canvas !== 'function') {
                alert('امکان ساخت فایل دانلود در این مرورگر وجود ندارد.');
                return;
            }

            var hidden = [];
            card.querySelectorAll('.no-print, .actions').forEach(function (el) {
                hidden.push([el, el.style.display]);
                el.style.display = 'none';
            });

            setBusy(true);
            try {
                var canvas = await html2canvas(card, {
                    scale: Math.min(2, window.devicePixelRatio || 1.5),
                    useCORS: true,
                    allowTaint: true,
                    backgroundColor: '#ffffff',
                    logging: false,
                    windowWidth: Math.max(card.scrollWidth, 800)
                });

                try {
                    var pdf = buildPdfFromCanvas(canvas);
                    var pdfBlob = pdf.output('blob');
                    downloadBlob(pdfBlob, fileBase + '.pdf');
                } catch (pdfErr) {
                    // fallback: تصویر JPEG
                    canvas.toBlob(function (blob) {
                        if (!blob) {
                            alert('ساخت فایل دانلود ناموفق بود.');
                            return;
                        }
                        downloadBlob(blob, fileBase + '.jpg');
                    }, 'image/jpeg', 0.92);
                }
            } catch (e) {
                alert('خطا در آماده‌سازی فایل دانلود. لطفاً دوباره تلاش کنید.');
            } finally {
                hidden.forEach(function (pair) {
                    pair[0].style.display = pair[1];
                });
                setBusy(false);
            }
        });
    })();
    </script>
    <?php if ($canPayOnline): ?>
    <script>
    (function () {
        var remaining = <?php echo (int)$remaining; ?>;
        var input = document.getElementById('pay-amount');
        var raw = document.getElementById('pay-amount-raw');
        var fullBtn = document.getElementById('btn-pay-full');
        var form = document.getElementById('online-pay-form');

        function digitsOnly(v) {
            return String(v || '').replace(/[^\d]/g, '');
        }
        function formatGrouped(n) {
            n = Math.floor(Number(n) || 0);
            return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }
        function parseAmount() {
            return parseInt(digitsOnly(input.value), 10) || 0;
        }
        function setAmount(n) {
            n = Math.max(0, Math.floor(Number(n) || 0));
            if (n > remaining) n = remaining;
            input.value = n ? formatGrouped(n) : '';
            raw.value = n;
        }

        input.addEventListener('input', function () {
            var n = parseAmount();
            if (n > remaining) n = remaining;
            var formatted = n ? formatGrouped(n) : '';
            input.value = formatted;
            raw.value = n;
            // keep caret near end for simple RTL/LTR amount typing
            try { input.setSelectionRange(formatted.length, formatted.length); } catch (e) {}
        });
        input.addEventListener('blur', function () {
            setAmount(parseAmount());
        });
        if (fullBtn) fullBtn.addEventListener('click', function () { setAmount(remaining); });
        if (form) form.addEventListener('submit', function (e) {
            var val = parseAmount();
            if (val < 1 || val > remaining) {
                e.preventDefault();
                alert('مبلغ باید بین ۱ و ' + formatGrouped(remaining) + ' تومان باشد.');
                return false;
            }
            raw.value = val;
        });
    })();
    </script>
    <?php endif; ?>
<?php endif; ?>
</body>
</html>
