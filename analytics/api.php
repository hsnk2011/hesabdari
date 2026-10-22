<?php
// analytics/api.php - JSON endpoints for the Sales Analytics page.
//
//   GET ?action=meta
//   GET ?action=section&section=overview|time|products|customers|collections|profit|inventory|operations
//        &startDate=Y-m-d&endDate=Y-m-d&compare=prev|yoy|none&granularity=auto|day|week|month|quarter|year
//        [&collection=ID&ptype=machine|handmade] [&refresh=1]
//   GET ?action=inventory_items&collectionId=&keyword=&includeConsignment=1
require_once __DIR__ . '/../api/core/session.php';
app_session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
ini_set('display_errors', '0');

require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/core/constants.php';
require_once __DIR__ . '/../api/core/helpers.php';
require_once __DIR__ . '/../api/core/SimpleCache.php';
require_once __DIR__ . '/lib/SalesAnalytics.php';

check_permission('view_reports');

$entityId = (int)($_SESSION['current_entity_id'] ?? 0);
if ($entityId <= 0) { send_json(['error' => 'مجموعه فعال نامعتبر است.'], 403); }

$mysqli = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
if ($mysqli->connect_errno) { send_json(['error' => 'اتصال به پایگاه داده برقرار نشد.'], 500); }
$mysqli->set_charset('utf8mb4');

function param(string $name, $default = null) { return isset($_GET[$name]) ? trim((string)$_GET[$name]) : $default; }
function safe_date($d) { return (is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false) ? $d : null; }
function json_out($data) { echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE); exit; }

$engine = new SalesAnalytics($mysqli, $entityId);
$action = param('action', 'section');

try {
    switch ($action) {
        case 'meta':
            json_out($engine->meta());

        case 'section': {
            $section = param('section', 'overview');
            $allowed = ['overview', 'time', 'products', 'customers', 'collections', 'profit', 'inventory', 'operations'];
            if (!in_array($section, $allowed, true)) send_json(['error' => 'بخش ناشناخته است.'], 400);
            $start = safe_date(param('startDate'));
            $end = safe_date(param('endDate'));
            if (!$start || !$end) send_json(['error' => 'بازه تاریخی نامعتبر است.'], 400);
            $compare = param('compare', 'prev');
            if (!in_array($compare, ['prev', 'yoy', 'none'], true)) $compare = 'prev';
            $ptype = param('ptype', '');
            if (!in_array($ptype, ['', 'machine', 'handmade'], true)) $ptype = '';
            $filters = [
                'start' => $start, 'end' => $end, 'compare' => $compare,
                'granularity' => param('granularity', 'auto'),
                'collection' => max(0, (int)param('collection', 0)),
                'ptype' => $ptype,
            ];
            // Short-lived cache: analytics can be heavy, but a just-saved invoice
            // should show up quickly — the page's refresh button bypasses it.
            $cache = new SimpleCache(sys_get_temp_dir() . '/hesabdari_cache/', 120);
            $key = 'analytics2_' . $section . '_' . md5(json_encode([$entityId, $filters, date('Y-m-d')]));
            $val = param('refresh') === '1' ? null : $cache->get($key);
            if ($val === null) {
                $val = $engine->section($section, $filters);
                $val['generated_at'] = date('Y-m-d H:i:s');
                $cache->set($key, $val);
            }
            json_out($val);
        }

        case 'inventory_items':
            json_out($engine->inventoryExplorer(
                ((int)param('collectionId', 0)) ?: null,
                (string)param('keyword', ''),
                param('includeConsignment', '1') === '1'
            ));

        default:
            send_json(['error' => 'عملیات ناشناخته است.'], 400);
    }
} catch (Throwable $e) {
    error_log('Analytics API Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    send_json(['error' => safe_exception_message($e, 'خطا در محاسبه آنالیز. لطفاً دوباره تلاش کنید.')], 500);
}
