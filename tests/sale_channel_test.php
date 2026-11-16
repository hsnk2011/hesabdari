<?php
/**
 * نوع فروش (sale_channel): خانگی / همکار-عمده / داخلی.
 *  - customer save keeps / sets the channel; «apply to past invoices» relabels them
 *  - a new sales invoice takes the customer's channel unless the form sends one
 *  - invoice list + customer list filters
 *  - sales analytics: default leaves internal out, filter narrows, split covers all
 *  - profit/loss salesByChannel rows add up to net sales
 * Run: php tests/sale_channel_test.php
 * saveSalesInvoice commits its own transaction, so every row this test makes is deleted at the end.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/api/config.php';
require_once $root . '/api/core/constants.php';
require_once $root . '/api/core/helpers.php';
require_once $root . '/api/models/BaseModel.php';
require_once $root . '/api/models/Customer.php';
require_once $root . '/api/models/Invoice.php';
require_once $root . '/api/models/ReportModel.php';
require_once $root . '/analytics/lib/SalesAnalytics.php';

$passed = 0;
$failed = 0;
function expect_true(bool $cond, string $msg): void {
    global $passed, $failed;
    if ($cond) { $passed++; echo "[PASS] {$msg}\n"; } else { $failed++; echo "[FAIL] {$msg}\n"; }
}

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
if ($conn->connect_error) { fwrite(STDERR, 'DB connect failed' . PHP_EOL); exit(1); }
$conn->set_charset('utf8mb4');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
$entityId = 1;
$_SESSION['current_entity_id'] = $entityId;
$_SESSION['user_id'] = 1;
$_SESSION['username'] = 'test';

$tok = 't' . substr(bin2hex(random_bytes(5)), 0, 8);
$day = '2031-07-15'; // far future: no other invoices on this day

$one = function (string $sql) use ($conn) { $r = $conn->query($sql); return $r ? $r->fetch_assoc() : null; };

$custIds = [];
$invIds = [];
try {
    $cust = new Customer($conn);
    // 1) customer channel
    $r = $cust->save(['name' => "خانگی {$tok}", 'nationalId' => '', 'initial_balance' => 0]);
    $retailId = (int)$r['id']; $custIds[] = $retailId;
    $r = $cust->save(['name' => "همکار {$tok}", 'nationalId' => '', 'initial_balance' => 0, 'sale_channel' => 'trade']);
    $tradeId = (int)$r['id']; $custIds[] = $tradeId;
    $r = $cust->save(['name' => "داخلی {$tok}", 'nationalId' => '', 'initial_balance' => 0, 'sale_channel' => 'internal']);
    $internalId = (int)$r['id']; $custIds[] = $internalId;
    expect_true($one("SELECT sale_channel FROM customers WHERE id = {$retailId}")['sale_channel'] === 'retail', 'new customer defaults to retail');
    expect_true($one("SELECT sale_channel FROM customers WHERE id = {$tradeId}")['sale_channel'] === 'trade', 'customer saved as trade');

    // A save without sale_channel (quick add / CRM) keeps the existing value
    $cust->save(['id' => $tradeId, 'name' => "همکار {$tok}", 'nationalId' => '', 'initial_balance' => 0]);
    expect_true($one("SELECT sale_channel FROM customers WHERE id = {$tradeId}")['sale_channel'] === 'trade', 'save without sale_channel keeps trade');
    $cust->save(['id' => $tradeId, 'name' => "همکار {$tok}", 'nationalId' => '', 'initial_balance' => 0, 'sale_channel' => 'bogus']);
    expect_true($one("SELECT sale_channel FROM customers WHERE id = {$tradeId}")['sale_channel'] === 'trade', 'invalid sale_channel is ignored');

    // 2) invoices (service lines: no stock needed)
    $inv = new Invoice($conn, 'sales');
    $mk = function (int $customerId, float $amount, ?string $channel = null) use ($inv, $day, &$invIds) {
        $data = ['customerId' => $customerId, 'date' => $day, 'discount' => 0, 'payments' => [],
                 'items' => [['productId' => null, 'is_service' => 1, 'item_description' => 'خدمت تست', 'quantity' => 1, 'unitPrice' => $amount]]];
        if ($channel !== null) $data['sale_channel'] = $channel;
        $res = $inv->saveSalesInvoice($data);
        if (empty($res['success'])) throw new Exception('invoice save failed: ' . json_encode($res, JSON_UNESCAPED_UNICODE));
        $invIds[] = (int)$res['id'];
        return (int)$res['id'];
    };
    $i1 = $mk($retailId, 1000);
    $i2 = $mk($tradeId, 4000);
    $i3 = $mk($tradeId, 2000, 'retail');   // a colleague buying for home
    $i4 = $mk($internalId, 9000);
    $ch = fn($id) => $one("SELECT sale_channel FROM sales_invoices WHERE id = {$id}")['sale_channel'];
    expect_true($ch($i1) === 'retail', 'invoice of a retail customer is retail');
    expect_true($ch($i2) === 'trade', 'invoice takes the customer channel (trade)');
    expect_true($ch($i3) === 'retail', 'form value overrides the customer channel');
    expect_true($ch($i4) === 'internal', 'internal customer → internal invoice');

    // 3) list filter
    $list = (new Invoice($conn, 'sales'))->getPaginated(['tableName' => 'sales_invoices', 'searchTerm' => $tok, 'saleChannelFilter' => 'trade', 'limit' => 50]);
    $ids = array_map('intval', array_column($list['data'], 'id'));
    expect_true($ids === [$i2], 'invoice list filter trade returns only the trade invoice');
    $clist = (new Customer($conn))->getPaginated(['tableName' => 'customers', 'searchTerm' => $tok, 'saleChannelFilter' => 'internal', 'limit' => 50]);
    expect_true(array_map('intval', array_column($clist['data'], 'id')) === [$internalId], 'customer list filter internal');

    // 4) apply to past invoices
    $r = $cust->save(['id' => $retailId, 'name' => "خانگی {$tok}", 'nationalId' => '', 'initial_balance' => 0, 'sale_channel' => 'trade', 'apply_channel_to_invoices' => 1]);
    expect_true(($r['invoices_updated'] ?? 0) === 1 && $ch($i1) === 'trade', 'apply_channel_to_invoices relabels past invoices');
    $cust->save(['id' => $retailId, 'name' => "خانگی {$tok}", 'nationalId' => '', 'initial_balance' => 0, 'sale_channel' => 'retail']);
    expect_true($ch($i1) === 'trade', 'without the flag past invoices keep their channel');
    // now: i1 trade 1000, i2 trade 4000, i3 retail 2000, i4 internal 9000

    // 5) analytics
    $f = ['start' => $day, 'end' => $day, 'compare' => 'none', 'granularity' => 'day'];
    $sa = new SalesAnalytics($conn, $entityId, $day);
    $ov = $sa->section('overview', $f);
    expect_true(abs($ov['summary']['net_sales'] - 7000) < 0.01, 'analytics default = retail + trade (7000), internal left out');
    $byKey = array_column($ov['channels'], null, 'key');
    expect_true(abs($byKey['trade']['net'] - 5000) < 0.01 && abs($byKey['retail']['net'] - 2000) < 0.01 && abs($byKey['internal']['net'] - 9000) < 0.01, 'channel split: trade 5000, retail 2000, internal 9000');
    expect_true(abs($byKey['trade']['share'] - 5000 / 7000 * 100) < 0.01 && $byKey['internal']['share'] === null, 'share is of retail + trade only');
    $sa2 = new SalesAnalytics($conn, $entityId, $day);
    expect_true(abs($sa2->section('overview', $f + ['channel' => 'trade'])['summary']['net_sales'] - 5000) < 0.01, 'analytics channel=trade → 5000');
    $sa3 = new SalesAnalytics($conn, $entityId, $day);
    expect_true(abs($sa3->section('overview', $f + ['channel' => 'all'])['summary']['net_sales'] - 16000) < 0.01, 'analytics channel=all → 16000');

    // 6) profit/loss
    $pl = (new ReportModel($conn))->getProfitLossReport(['startDate' => $day, 'endDate' => $day, 'calculationMethod' => 'cogs', 'entity_id' => $entityId, 'skipPartnerDetails' => 1]);
    $rows = array_column($pl['salesByChannel'], null, 'key');
    $sum = array_sum(array_column($pl['salesByChannel'], 'netSales'));
    $net = (float)$pl['grossSales'] - (float)$pl['salesDiscounts'] - (float)$pl['salesReturns'];
    expect_true(abs($sum - $net) < 0.01, 'P&L salesByChannel rows add up to net sales');
    expect_true(abs($rows['trade']['netSales'] - 5000) < 0.01 && abs($rows['internal']['netSales'] - 9000) < 0.01, 'P&L trade 5000, internal 9000');
} catch (Throwable $e) {
    $failed++;
    echo "[FAIL] exception: " . $e->getMessage() . " @ " . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    if ($invIds) {
        $in = implode(',', array_map('intval', $invIds));
        $conn->query("DELETE FROM payments WHERE invoiceType = 'sales' AND invoiceId IN ({$in})");
        $conn->query("DELETE FROM sales_invoice_items WHERE invoiceId IN ({$in})");
        try { $conn->query("DELETE FROM invoice_history WHERE invoice_type = 'sales' AND invoice_id IN ({$in})"); } catch (Throwable $e) {}
        $conn->query("DELETE FROM sales_invoices WHERE id IN ({$in})");
    }
    if ($custIds) {
        $conn->query("DELETE FROM customers WHERE id IN (" . implode(',', array_map('intval', $custIds)) . ")");
    }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
