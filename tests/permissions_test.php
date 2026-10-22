<?php
/**
 * Static checks for the permission system:
 *  - every permission key used by the router, controllers and UI is defined;
 *  - every API route either has a permission_map entry or is a known
 *    self-checking/public action (so a new route cannot ship unprotected);
 *  - picker lists used by forms are readable by the sections that need them.
 * Run: php tests/permissions_test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);
require_once $root . '/api/core/schema.php';

$passed = 0; $failed = 0;
function expect_true(bool $c, string $m): void { global $passed, $failed; if ($c) { $passed++; echo "[PASS] $m\n"; } else { $failed++; echo "[FAIL] $m\n"; } }

$defined = array_keys(get_default_permissions());
$index = file_get_contents($root . '/api/index.php');

$block = function (string $start) use ($index) {
    $i = strpos($index, $start);
    return substr($index, $i, strpos($index, "\n];", $i) - $i);
};
$mapTxt = $block('$permission_map = [');
$routesTxt = $block('$routes = [');

// permission_map: 'action' => 'key' | null | ['a', 'b']
$map = [];
preg_match_all("/'([a-z0-9_]+)'\s*=>\s*(null|'[a-z0-9_]+'|\[[^\]]*\])/", $mapTxt, $m, PREG_SET_ORDER);
foreach ($m as $row) {
    $map[$row[1]] = $row[2] === 'null' ? [] : (preg_match_all("/'([a-z0-9_]+)'/", $row[2], $k) ? $k[1] : []);
}
preg_match_all("/'([a-z0-9_]+)'\s*=>\s*\['([A-Za-z]+)',\s*'([A-Za-z0-9_]+)'\]/", $routesTxt, $r, PREG_SET_ORDER);
$routes = [];
foreach ($r as $row) { $routes[$row[1]] = [$row[2], $row[3]]; }

// 1) keys used on the server
$used = [];
foreach ($map as $keys) { foreach ($keys as $k) { $used[$k] = true; } }
foreach (glob($root . '/api/{controllers,models,core}/*.php', GLOB_BRACE) as $f) {
    if (preg_match_all("/check_permission\('([a-z0-9_]+)'\)/", file_get_contents($f), $mm)) {
        foreach ($mm[1] as $k) { $used[$k] = true; }
    }
}
$unknown = array_diff(array_keys($used), $defined);
expect_true(empty($unknown), 'server uses only defined permissions' . ($unknown ? ': ' . implode(', ', $unknown) : ''));

// 2) keys used in the UI
$ui = [];
foreach (array_merge(glob($root . '/templates/*.php'), glob($root . '/js/*.js')) as $f) {
    $t = file_get_contents($f);
    if (preg_match_all('/data-permission(?:-action)?="([a-z0-9_]+)"/', $t, $mm)) { foreach ($mm[1] as $k) { $ui[$k] = true; } }
    if (preg_match_all('/data-permission-any="([^"]+)"/', $t, $mm)) { foreach ($mm[1] as $g) { foreach (explode(',', $g) as $k) { $ui[trim($k)] = true; } } }
    if (preg_match_all("/hasPermission\('([a-z0-9_]+)'\)/", $t, $mm)) { foreach ($mm[1] as $k) { $ui[$k] = true; } }
}
$unknownUi = array_diff(array_keys($ui), $defined);
expect_true(empty($unknownUi), 'UI uses only defined permissions' . ($unknownUi ? ': ' . implode(', ', $unknownUi) : ''));

// 3) every route is covered
$selfChecked = [
    // public / session plumbing
    'login', 'logout', 'check_session', 'get_public_settings', 'change_password', 'switch_entity',
    'get_entity_print_settings', 'report_client_error', 'check_auto_backup_status', 'run_pending_auto_backup',
    'run_automatic_backup', 'receive_remote_backup',
    // permission decided in index.php or inside the controller
    'get_paginated_data', 'get_entity_by_id', 'save_payment', 'delete_payment', 'global_search',
    'clone_invoice', 'get_invoice_history', 'get_product_recent_prices', 'prepare_purchase_to_sales',
    'generate_proforma', 'revoke_invoice_links', 'preview_invoice_sms', 'send_invoice_sms',
    'get_warehouses', 'list_lookup', 'save_lookup', 'delete_lookup', 'get_budget_report', 'get_salesperson_report',
    'upload_product_image', 'process_product_image', 'product_image_rembg_status',
];
$uncovered = [];
foreach ($routes as $action => $_) {
    if (strpos($action, 'integration_') === 0) { continue; }
    $hasEntry = array_key_exists($action, $map) && !empty($map[$action]);
    if (!$hasEntry && !in_array($action, $selfChecked, true)) { $uncovered[] = $action; }
}
expect_true(empty($uncovered), 'every route has a permission or is a known self-checking action' . ($uncovered ? ': ' . implode(', ', $uncovered) : ''));

// 4) picker lists reachable from the sections that need them
$needs = [
    'get_full_customers_list' => ['manage_sales_invoices', 'manage_quotations', 'manage_transactions', 'manage_assets'],
    'get_full_suppliers_list' => ['manage_purchase_invoices', 'manage_transactions', 'manage_assets'],
    'get_full_products_list' => ['manage_sales_invoices', 'manage_purchase_invoices', 'manage_quotations'],
    'get_full_accounts_list' => ['manage_sales_invoices', 'manage_purchase_invoices', 'manage_transactions', 'manage_checks', 'manage_assets'],
    'get_paginated_data_checks' => ['manage_purchase_invoices', 'manage_transactions', 'manage_assets'],
    'get_paginated_data_sales_invoices' => ['manage_installments'],
];
foreach ($needs as $action => $perms) {
    $missing = array_diff($perms, $map[$action] ?? []);
    expect_true(empty($missing), "{$action} readable by " . implode(', ', $perms) . ($missing ? ' (missing: ' . implode(', ', $missing) . ')' : ''));
}

// 5) client cache rules mirror the server lists
$appJs = file_get_contents($root . '/js/app.js');
foreach (['customers' => 'get_full_customers_list', 'suppliers' => 'get_full_suppliers_list', 'products' => 'get_full_products_list', 'accounts' => 'get_full_accounts_list'] as $cacheKey => $action) {
    preg_match("/\n\s*{$cacheKey}: any\(([^)]*)\)/", $appJs, $mm);
    preg_match_all("/'([a-z_]+)'/", $mm[1] ?? '', $kk);
    $diff = array_merge(array_diff($map[$action] ?? [], $kk[1]), array_diff($kk[1], $map[$action] ?? []));
    expect_true(!empty($kk[1]) && empty($diff), "client cache '{$cacheKey}' uses the same permissions as {$action}" . ($diff ? ' (diff: ' . implode(', ', $diff) . ')' : ''));
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
