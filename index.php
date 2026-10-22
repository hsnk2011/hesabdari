<?php
require_once __DIR__ . '/api/core/session.php';
app_session_start();

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Generate the initial CSRF token for the session
require_once __DIR__ . '/api/core/helpers.php';
require_once __DIR__ . '/api/core/constants.php';
if (empty($_SESSION['csrf_token'])) {
    generate_csrf_token();
}
$assetV = defined('APP_VERSION') ? APP_VERSION : '2.0.119';

// Brand title from settings (best-effort; falls back to a neutral default).
require_once __DIR__ . '/api/config.php';
$appTitle = 'سیستم حسابداری';
try {
    $titleConn = @new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
    if ($titleConn && !$titleConn->connect_error) {
        $titleConn->set_charset('utf8mb4');
        $t = trim((string)get_app_setting($titleConn, 'app_title', ''));
        if ($t !== '') {
            $appTitle = $t;
        }
        $titleConn->close();
    }
} catch (Throwable $e) { /* keep default title */ }

$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$baseHref = ($basePath === '' ? '' : $basePath) . '/';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <base href="<?php echo htmlspecialchars($baseHref, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
    <title><?php echo htmlspecialchars($appTitle, ENT_QUOTES, 'UTF-8'); ?></title>

    <!-- PWA -->
    <link rel="manifest" href="manifest.php">
    <meta name="theme-color" content="#4f46e5">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="<?php echo htmlspecialchars($appTitle, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="application-name" content="<?php echo htmlspecialchars($appTitle, ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="icon" href="favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/icons/favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/icons/favicon-16.png">
    <link rel="apple-touch-icon" href="assets/icons/apple-touch-icon.png">
    <link rel="apple-touch-icon" sizes="152x152" href="assets/icons/icon-152.png">
    <link rel="apple-touch-icon" sizes="192x192" href="assets/icons/icon-192.png">
    <meta name="msapplication-TileColor" content="#4f46e5">
    <meta name="msapplication-TileImage" content="assets/icons/mstile-150.png">
    <meta name="msapplication-config" content="browserconfig.xml">
    <link rel="stylesheet" href="assets/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="assets/css/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/dataTables.bootstrap5.css">
    <link rel="stylesheet" href="assets/css/select2.min.css">
    <link rel="stylesheet" href="assets/css/select2-bootstrap-5-theme.rtl.min.css">
    <link rel="stylesheet" href="assets/css/toastr.min.css">
    <link rel="stylesheet" href="style.css?v=<?php echo $assetV . "." . (int)@filemtime(__DIR__ . "/style.css"); ?>">
    <link rel="stylesheet" href="assets/css/print-label.css">
    <link rel="stylesheet" href="assets/css/dark-mode.css?v=<?php echo $assetV; ?>">
</head>

<body>
    <div id="loader" class="loader" style="display: none;">
        <div class="loader-inner text-center">
            <div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div>
            <div id="loader-message" class="loader-message mt-3" style="display:none;"></div>
        </div>
    </div>
    
    <?php include 'templates/login_overlay.php'; ?>

    <div class="container-fluid main-container">
        
        <?php include 'templates/header.php'; ?>
        <?php include 'templates/main_tabs.php'; ?>

        <div class="tab-content pt-3" id="mainTabContent">
            <?php include 'templates/section_dashboard.php'; ?>
            <?php include 'templates/section_crm.php'; ?>
            <?php include 'templates/section_quotations.php'; ?>
            <?php include 'templates/section_sales.php'; ?>
            <?php include 'templates/section_purchases.php'; ?>
            <?php include 'templates/section_return_invoices.php'; ?>
            <?php include 'templates/section_products.php'; ?>
            <?php include 'templates/section_price_list.php'; ?>
            <?php include 'templates/section_consignment.php'; ?>
            <?php include 'templates/section_transactions.php'; ?>
            <?php include 'templates/section_checks.php'; ?>
            <?php include 'templates/section_accounts.php'; ?>
            <?php include 'templates/section_assets.php'; ?>
            <?php include 'templates/section_customers.php'; ?>
            <?php include 'templates/section_suppliers.php'; ?>
            <?php include 'templates/section_contacts.php'; ?>
            <?php include 'templates/section_reports.php'; ?>
            <?php include 'templates/section_smart_audit.php'; ?>
            <?php include 'templates/section_tools.php'; ?>
            <?php include 'templates/section_settings.php'; ?>
        </div>
    </div>

    <?php 
    // Centralized modals
    include 'templates/modals_all.php'; 
    include 'templates/modals_general.php'; 
    ?>
    
    <?php include 'templates/scripts.php'; ?>
</body>

</html>