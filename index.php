<?php
session_start();

// Generate the initial CSRF token for the session
require_once __DIR__ . '/api/core/helpers.php';
if (empty($_SESSION['csrf_token'])) {
    generate_csrf_token();
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
    <title>سیستم حسابداری بازرگانی فرش</title>
    <link rel="stylesheet" href="assets/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="assets/css/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/persian-datepicker.min.css" />
    <link rel="stylesheet" href="assets/css/dataTables.bootstrap5.css">
    <link rel="stylesheet" href="assets/css/select2.min.css">
    <link rel="stylesheet" href="assets/css/select2-bootstrap-5-theme.rtl.min.css">
    <link rel="stylesheet" href="assets/css/toastr.min.css">
    <link rel="stylesheet" href="style.css">
</head>

<body>
    <div id="loader" class="loader" style="display: none;"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></div>
    
    <?php include 'templates/login_overlay.php'; ?>

    <div class="container-fluid main-container">
        
        <?php include 'templates/header.php'; ?>
        <?php include 'templates/main_tabs.php'; ?>

        <div class="tab-content pt-3" id="mainTabContent">
            <?php include 'templates/section_dashboard.php'; ?>
            <?php include 'templates/section_sales.php'; ?>
            <?php include 'templates/section_purchases.php'; ?>
            <?php include 'templates/section_products.php'; ?>
            <?php include 'templates/section_consignment.php'; ?>
            <?php include 'templates/section_transactions.php'; ?>
            <?php include 'templates/section_checks.php'; ?>
            <?php include 'templates/section_accounts.php'; ?>
            <?php include 'templates/section_customers.php'; ?>
            <?php include 'templates/section_suppliers.php'; ?>
            <?php include 'templates/section_reports.php'; ?>
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