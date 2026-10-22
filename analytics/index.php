<?php
// analytics/index.php - Sales Analytics (standalone page)
require_once __DIR__ . '/../api/core/session.php';
app_session_start();
if (!isset($_SESSION['user_id'])) { header('Location: ../index.php'); exit; }
if (!in_array('view_reports', (array)($_SESSION['permissions'] ?? []), true)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>دسترسی ندارید</title></head>'
        . '<body style="font-family:Tahoma,sans-serif;text-align:center;padding:3rem 1rem">'
        . '<h3>دسترسی ندارید</h3><p>برای آنالیز فروش، دسترسی «مشاهده گزارشات» لازم است.</p>'
        . '<p><a href="../index.php">بازگشت به برنامه</a></p></body></html>';
    exit;
}
// The page references versioned assets; never serve a stale copy of it.
header('Cache-Control: no-cache, must-revalidate');

require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/core/constants.php';
require_once __DIR__ . '/../api/core/helpers.php';
$analyticsAppTitle = 'حسابداری';
$analyticsCurrencyLabel = 'تومان';
try {
    $conn = @new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
    if ($conn && !$conn->connect_error) {
        $conn->set_charset('utf8mb4');
        $t = trim((string)get_app_setting($conn, 'app_title', ''));
        if ($t !== '') { $analyticsAppTitle = $t; }
        $c = trim((string)get_app_setting($conn, 'currency_label', ''));
        if ($c !== '') { $analyticsCurrencyLabel = $c; }
        $conn->close();
    }
} catch (Throwable $e) { /* defaults */ }

// Same version string as the main app (APP_VERSION) for assets and the service
// worker, so moving between pages never looks like a new release.
$appVersion = APP_VERSION;
$v = function ($file) use ($appVersion) { return $file . '?v=' . rawurlencode($appVersion); };
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>آنالیز فروش<?php echo $analyticsAppTitle !== 'حسابداری' ? ' — ' . htmlspecialchars($analyticsAppTitle) : ''; ?></title>
    <script>
      // Apply the app's saved theme before first paint (same key as js/ui.js).
      (function () { try { var t = localStorage.getItem('theme'); if (t === 'dark' || t === 'light') document.documentElement.setAttribute('data-theme', t); } catch (e) {} })();
    </script>

    <!-- PWA -->
    <link rel="manifest" href="../manifest.php">
    <meta name="theme-color" content="#2a78d6">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="<?php echo htmlspecialchars($analyticsAppTitle); ?>">
    <meta name="application-name" content="<?php echo htmlspecialchars($analyticsAppTitle); ?>">
    <link rel="icon" href="../favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="../assets/icons/favicon-32.png">
    <link rel="apple-touch-icon" href="../assets/icons/apple-touch-icon.png">

    <link rel="stylesheet" href="../assets/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="../assets/css/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/toastr.min.css">
    <link rel="stylesheet" href="../assets/css/vazirmatn-font-face.css">
    <link rel="stylesheet" href="../assets/css/persian-datepicker.min.css">
    <link rel="stylesheet" href="<?php echo $v('sales-analytics.css'); ?>">
</head>
<body>
<div class="sa-app">
    <!-- Top bar -->
    <header class="sa-topbar">
        <div class="sa-topbar-inner">
            <div class="sa-brand">
                <span class="sa-brand-icon"><i class="bi bi-graph-up-arrow"></i></span>
                <div>
                    <h1>آنالیز فروش</h1>
                    <div class="sa-sub" id="sa-entity-name">&nbsp;</div>
                </div>
            </div>
            <div class="sa-actions">
                <button class="sa-icon-btn" id="sa-refresh" title="به‌روزرسانی (بدون حافظه موقت)"><i class="bi bi-arrow-clockwise"></i><span>به‌روزرسانی</span></button>
                <button class="sa-icon-btn" id="sa-export" title="خروجی اکسل (CSV) از بخش فعلی"><i class="bi bi-file-earmark-spreadsheet"></i><span>خروجی</span></button>
                <button class="sa-icon-btn" id="sa-print" title="چاپ / ذخیره PDF"><i class="bi bi-printer"></i><span>چاپ</span></button>
                <button class="sa-icon-btn" id="sa-theme" title="حالت روشن / تیره"><i class="bi bi-moon-stars"></i></button>
                <a class="sa-icon-btn sa-back" href="../index.php" title="بازگشت به برنامه"><i class="bi bi-arrow-left"></i><span>برنامه حسابداری</span></a>
            </div>
        </div>
    </header>

    <!-- Filters -->
    <section class="sa-filters" aria-label="فیلترها">
        <div class="sa-filters-row">
            <div class="sa-field sa-date">
                <label for="sa-start">از تاریخ</label>
                <input id="sa-start" class="form-control form-control-sm" autocomplete="off" inputmode="numeric" placeholder="۱۴۰۴/۰۱/۰۱">
            </div>
            <div class="sa-field sa-date">
                <label for="sa-end">تا تاریخ</label>
                <input id="sa-end" class="form-control form-control-sm" autocomplete="off" inputmode="numeric" placeholder="۱۴۰۴/۱۲/۲۹">
            </div>
            <div class="sa-field">
                <label for="sa-compare">مقایسه با</label>
                <select id="sa-compare" class="form-select form-select-sm">
                    <option value="prev">دوره قبل (هم‌طول)</option>
                    <option value="yoy">همین بازه در سال قبل</option>
                    <option value="none">بدون مقایسه</option>
                </select>
            </div>
            <div class="sa-field">
                <label for="sa-gran">نمایش روند</label>
                <select id="sa-gran" class="form-select form-select-sm">
                    <option value="auto">خودکار</option>
                    <option value="day">روزانه</option>
                    <option value="week">هفتگی</option>
                    <option value="month">ماهانه</option>
                    <option value="quarter">فصلی</option>
                    <option value="year">سالانه</option>
                </select>
            </div>
        </div>
        <div class="sa-presets" role="group" aria-label="بازه‌های آماده">
            <button data-preset="7d">۷ روز اخیر</button>
            <button data-preset="30d">۳۰ روز اخیر</button>
            <button data-preset="this_month">این ماه</button>
            <button data-preset="last_month">ماه قبل</button>
            <button data-preset="this_season">این فصل</button>
            <button data-preset="last_season">فصل قبل</button>
            <button data-preset="this_year">امسال</button>
            <button data-preset="last_year">سال قبل</button>
            <button data-preset="12m">۱۲ ماه اخیر</button>
            <button data-preset="all">کل دوره</button>
        </div>
        <div class="sa-period-caption" id="sa-period-caption" aria-live="polite"></div>
    </section>

    <!-- Tabs -->
    <nav class="sa-tabs" role="tablist" aria-label="بخش‌های آنالیز">
        <button role="tab" data-tab="overview"><i class="bi bi-speedometer2"></i>نمای کلی</button>
        <button role="tab" data-tab="time"><i class="bi bi-calendar3"></i>زمان و روند</button>
        <button role="tab" data-tab="products"><i class="bi bi-grid-3x3-gap"></i>محصولات</button>
        <button role="tab" data-tab="customers"><i class="bi bi-people"></i>مشتریان</button>
        <button role="tab" data-tab="collections"><i class="bi bi-cash-coin"></i>وصول و نقدینگی</button>
        <button role="tab" data-tab="profit"><i class="bi bi-pie-chart"></i>سودآوری</button>
        <button role="tab" data-tab="inventory"><i class="bi bi-box-seam"></i>انبار</button>
        <button role="tab" data-tab="operations"><i class="bi bi-clipboard-check"></i>عملیات و کیفیت داده</button>
        <button role="tab" data-tab="guide"><i class="bi bi-question-circle"></i>راهنما</button>
    </nav>

    <main class="sa-main" id="sa-main">
        <section class="sa-section" data-section="overview"></section>
        <section class="sa-section" data-section="time"></section>
        <section class="sa-section" data-section="products"></section>
        <section class="sa-section" data-section="customers"></section>
        <section class="sa-section" data-section="collections"></section>
        <section class="sa-section" data-section="profit"></section>
        <section class="sa-section" data-section="inventory"></section>
        <section class="sa-section" data-section="operations"></section>
        <section class="sa-section" data-section="guide"></section>
    </main>

    <footer class="sa-footer" id="sa-footer"></footer>
</div>

<div class="sa-tooltip" id="sa-tooltip" role="tooltip" hidden></div>

<script src="../assets/js/jquery.min.js"></script>
<script src="../assets/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/toastr.min.js"></script>
<script src="../assets/js/chart.js"></script>
<script src="../assets/js/persian-date.min.js"></script>
<script src="../assets/js/persian-datepicker.min.js"></script>
<script>window.ANALYTICS_CURRENCY_LABEL = <?php echo json_encode($analyticsCurrencyLabel, JSON_UNESCAPED_UNICODE); ?>;</script>
<script src="<?php echo $v('sales-analytics.js'); ?>"></script>
<script>window.__APP_VERSION__ = <?php echo json_encode($appVersion); ?>;</script>
<script src="../js/pwa.js?v=<?php echo rawurlencode($appVersion); ?>"></script>
</body>
</html>
