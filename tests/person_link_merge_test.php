<?php
/**
 * شخصی که هم مشتری و هم تأمین‌کننده است + ادغام اشخاص:
 * اتصال مشتری و تأمین‌کننده، مانده خالص در لیست‌ها، صورتحساب تجمیعی،
 * همگام‌سازی مشخصات، و ادغام دو شخص هم‌نوع (انتقال اسناد و حذف تکراری).
 * Run: php tests/person_link_merge_test.php
 * Uses a DB transaction that is always rolled back.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/api/config.php';
require_once $root . '/api/core/helpers.php';
require_once $root . '/api/models/BaseModel.php';
require_once $root . '/api/models/Customer.php';
require_once $root . '/api/models/Supplier.php';
require_once $root . '/api/models/PersonLink.php';
require_once $root . '/api/models/ReportModel.php';

$passed = 0;
$failed = 0;

function expect_true(bool $cond, string $msg): void {
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "[PASS] {$msg}\n";
    } else {
        $failed++;
        echo "[FAIL] {$msg}\n";
    }
}

function near($a, $b): bool {
    return abs((float)$a - (float)$b) < 0.01;
}

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
if ($conn->connect_error) {
    fwrite(STDERR, 'DB connect failed: ' . $conn->connect_error . PHP_EOL);
    exit(1);
}
$conn->set_charset('utf8mb4');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$entityId = 1;
$_SESSION['current_entity_id'] = $entityId;
$_SESSION['permissions'] = ['manage_customers', 'manage_suppliers'];
$tok = 'plk' . substr(bin2hex(random_bytes(4)), 0, 8);
$date = '2026-03-10';

$q = function (string $sql) use ($conn): array {
    $res = $conn->query($sql);
    return $res === true ? [] : $res->fetch_all(MYSQLI_ASSOC);
};
$insCustomer = function (string $name, float $initial, string $phone = '') use ($conn, $entityId): int {
    $st = $conn->prepare("INSERT INTO customers (entity_id, name, phone, nationalId, initial_balance) VALUES (?, ?, ?, '', ?)");
    $st->bind_param('issd', $entityId, $name, $phone, $initial);
    $st->execute();
    return (int)$conn->insert_id;
};
$insSupplier = function (string $name, float $initial, string $phone = '') use ($conn, $entityId): int {
    $st = $conn->prepare("INSERT INTO suppliers (entity_id, name, phone, economicCode, initial_balance) VALUES (?, ?, ?, '', ?)");
    $st->bind_param('issd', $entityId, $name, $phone, $initial);
    $st->execute();
    return (int)$conn->insert_id;
};

$conn->begin_transaction();
try {
    $link = new PersonLink($conn);
    $name = "{$tok} علی";

    // Same person entered twice: customer owes 100 + 1000, we owe the supplier 50 + 300.
    $c = $insCustomer($name, 100, '0912');
    $s = $insSupplier($name, 50, '0935');
    $q("INSERT INTO sales_invoices (entity_id, customerId, date, totalAmount, discount, tax_amount, paidAmount, is_consignment) VALUES ({$entityId}, {$c}, '{$date}', 1000, 0, 0, 0, 0)");
    $q("INSERT INTO purchase_invoices (entity_id, supplierId, date, totalAmount, discount, tax_amount, paidAmount, is_consignment) VALUES ({$entityId}, {$s}, '{$date}', 300, 0, 0, 0, 0)");

    // --- customer + supplier = link
    $plan = $link->mergePlan('customer', $c, 'supplier', $s);
    expect_true(($plan['mode'] ?? '') === 'link', 'customer + supplier plan is a link');
    $res = $link->merge('customer', $c, 'supplier', $s, false);
    expect_true(!empty($res['success']), 'link succeeds');
    expect_true($link->twinId('customer', $c) === $s && $link->twinId('supplier', $s) === $c, 'twin found from both sides');
    $phones = $q("SELECT phone FROM customers WHERE id = {$c}")[0]['phone'];
    expect_true($phones === '0935 / 0912', 'phones combined, target first (' . $phones . ')');
    $again = $link->mergePlan('supplier', $s, 'customer', $c);
    expect_true(!empty($again['error']), 'linking an already linked pair is refused');

    // --- lists: own balance + combined net
    $cRow = (new Customer($conn))->getPaginated(['searchTerm' => $tok, 'limit' => 50])['data'];
    $cRow = array_values(array_filter($cRow, fn($r) => (int)$r['id'] === $c))[0] ?? [];
    expect_true(near($cRow['net_balance'] ?? null, 1100), 'customer list keeps own balance 1100');
    expect_true((int)($cRow['linked_id'] ?? 0) === $s && near($cRow['combined_balance'] ?? null, 750), 'customer list combined net = 750 (owes us)');
    $sRow = (new Supplier($conn))->getPaginated(['searchTerm' => $tok, 'limit' => 50])['data'];
    $sRow = array_values(array_filter($sRow, fn($r) => (int)$r['id'] === $s))[0] ?? [];
    expect_true(near($sRow['net_balance'] ?? null, 350), 'supplier list keeps own balance 350');
    expect_true(near($sRow['combined_balance'] ?? null, -750), 'supplier list combined net = -750 (they owe us)');

    // --- combined statement
    $report = new ReportModel($conn);
    $base = ['startDate' => '2026-03-01', 'endDate' => '2026-03-31', 'showPreviousBalance' => true, 'combineLinked' => true];
    $stC = $report->getPersonStatement($base + ['personType' => 'customer', 'personId' => $c]);
    expect_true(($stC['combinedWith']['id'] ?? 0) === $s, 'customer statement combined with the supplier');
    expect_true(near($stC['openingBalance'], 50), 'combined opening = 100 - 50');
    $net = (float)$stC['openingBalance'];
    foreach ($stC['transactions'] as $t) {
        if (empty($t['isPreviousBalance'])) $net += (float)$t['debit'] - (float)$t['credit'];
    }
    expect_true(near($net, 750), 'combined customer statement closes at 750');
    $stS = $report->getPersonStatement($base + ['personType' => 'supplier', 'personId' => $s]);
    $netS = (float)$stS['openingBalance'];
    foreach ($stS['transactions'] as $t) {
        if (empty($t['isPreviousBalance'])) $netS += (float)$t['credit'] - (float)$t['debit'];
    }
    expect_true(near($stS['openingBalance'], -50) && near($netS, -750), 'combined supplier statement closes at -750');
    $plain = $report->getPersonStatement(array_merge($base, ['personType' => 'customer', 'personId' => $c, 'combineLinked' => false]));
    expect_true(empty($plain['combinedWith']) && count($plain['transactions']) === 2, 'statement without the option stays separate');

    // --- profile sync and name conflicts
    $q("UPDATE customers SET name = '{$tok} علی آزمایشی', job = 'بنکدار' WHERE id = {$c}");
    $link->afterSave('customer', $c, null);
    $sup = $q("SELECT name, job FROM suppliers WHERE id = {$s}")[0];
    expect_true($sup['name'] === "{$tok} علی آزمایشی" && $sup['job'] === 'بنکدار', 'shared fields copied to the supplier twin');
    $insSupplier("{$tok} دیگری", 0);
    expect_true($link->precheckSave('customer', $c, "{$tok} دیگری", null) !== null, 'rename clashing with another supplier is refused');

    // --- unchecking "both" unlinks; checking it on a new person creates the twin
    $link->afterSave('customer', $c, false);
    expect_true($link->twinId('customer', $c) === 0, 'unchecking both drops the link');
    $d = $insCustomer("{$tok} جدید", 0, '0901');
    $r = $link->afterSave('customer', $d, true);
    $twin = $q("SELECT * FROM suppliers WHERE id = " . (int)($r['twin_id'] ?? 0))[0] ?? null;
    expect_true($twin && $twin['name'] === "{$tok} جدید" && $twin['phone'] === '0901' && near($twin['initial_balance'], 0), 'checking both creates a linked supplier');

    // --- checking both on a person whose name already exists as a supplier links to it
    $k = $insCustomer("{$tok} هم‌نام", 0, '0911');
    $ks = $insSupplier("{$tok} هم‌نام", 70, '0922');
    $r = $link->afterSave('customer', $k, true);
    $ksRow = $q("SELECT phone, initial_balance FROM suppliers WHERE id = {$ks}")[0];
    expect_true((int)($r['twin_id'] ?? 0) === $ks && !empty($r['adopted']), 'existing same-name supplier is adopted, not duplicated');
    expect_true($ksRow['phone'] === '0911 / 0922' && near($ksRow['initial_balance'], 70), 'adopted supplier keeps its phones and balance');

    // --- same-type merge: duplicate customer folded into $c
    $link->merge('customer', $c, 'supplier', $s, false); // relink
    $e = $insCustomer("{$tok} علی تکراری", 20, '0912');
    $q("INSERT INTO sales_invoices (entity_id, customerId, date, totalAmount, discount, tax_amount, paidAmount, is_consignment) VALUES ({$entityId}, {$e}, '{$date}', 200, 0, 0, 0, 0)");
    $eInv = (int)$conn->insert_id;
    $q("INSERT INTO payments (entity_id, person_id, person_type, transaction_type, type, amount, date) VALUES ({$entityId}, {$e}, 'customer', 'receipt', 'cash', 50, '{$date}')");
    $ePay = (int)$conn->insert_id;
    $plan = $link->mergePlan('customer', $e, 'customer', $c);
    expect_true(($plan['mode'] ?? '') === 'merge' && ($plan['documents']['فاکتور فروش'] ?? 0) === 1 && ($plan['documents']['تراکنش/پرداخت'] ?? 0) === 1, 'merge plan counts the documents');
    $res = $link->merge('customer', $e, 'customer', $c, false);
    expect_true(!empty($res['success']), 'same-type merge succeeds');
    expect_true((int)$q("SELECT customerId FROM sales_invoices WHERE id = {$eInv}")[0]['customerId'] === $c, 'invoice moved to the target');
    expect_true((int)$q("SELECT person_id FROM payments WHERE id = {$ePay}")[0]['person_id'] === $c, 'payment moved to the target');
    expect_true(empty($q("SELECT id FROM customers WHERE id = {$e}")), 'duplicate deleted');
    $cNow = $q("SELECT initial_balance, linked_supplier_id FROM customers WHERE id = {$c}")[0];
    expect_true(near($cNow['initial_balance'], 120) && (int)$cNow['linked_supplier_id'] === $s, 'opening balances added, link kept');

    // --- the duplicate's link moves to the survivor
    $f = $insCustomer("{$tok} اف", 0);
    $g = (int)$link->afterSave('customer', $f, true)['twin_id'];
    $h = $insCustomer("{$tok} اچ", 0);
    $link->merge('customer', $f, 'customer', $h, false);
    expect_true($link->twinId('customer', $h) === $g, 'source link moved to the target');
    $q("UPDATE suppliers SET name = '{$tok} اچ' WHERE id = {$g}");
    expect_true(!empty($link->mergePlan('customer', $c, 'customer', $h)['error']), 'merging two customers linked to different suppliers is refused');

    // --- deleting a linked supplier clears the link
    $del = (new Supplier($conn))->delete($g);
    expect_true(!empty($del['success']) && $link->twinId('customer', $h) === 0, 'deleting the supplier twin clears the link');

} catch (Throwable $e) {
    echo "[FAIL] exception: " . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine() . "\n";
    $failed++;
} finally {
    $conn->rollback();
    $conn->close();
}

echo "\nPassed: {$passed}, Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
