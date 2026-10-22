<script src="assets/js/jquery.min.js"></script>
<script src="assets/js/bootstrap.bundle.min.js"></script>
<link rel="stylesheet" href="assets/css/bd-datepicker.css">
<script src="assets/js/bd-datepicker.js"></script>
<script src="assets/js/dataTables.js"></script>
<script src="assets/js/dataTables.bootstrap5.js"></script>
<script src="assets/js/select2.min.js"></script>
<script src="assets/js/chart.js"></script>
<script src="assets/js/toastr.min.js"></script>
<script src="assets/js/qrious.min.js"></script>
<script src="assets/js/xlsx.full.min.js"></script>

<?php
require_once __DIR__ . '/../api/core/constants.php';
$v = defined('APP_VERSION') ? APP_VERSION : '2.0.119'; // Version for cache busting
?>
<script>window.__APP_VERSION__ = <?php echo json_encode($v); ?>;</script>
<script src="js/config.js?v=<?php echo $v; ?>"></script>
<script src="js/api.js?v=<?php echo $v; ?>"></script>
<script src="js/ui.js?v=<?php echo $v; ?>"></script>
<script src="js/image-editor.js?v=<?php echo $v; ?>"></script>
<script src="js/person-statement-print.js?v=<?php echo $v; ?>"></script>
<script src="js/reports.js?v=<?php echo $v; ?>"></script>
<script src="js/person-list-ui.js?v=<?php echo $v; ?>"></script>
<script src="js/entity-manager.js?v=<?php echo $v; ?>"></script>
<script src="js/barcode-manager.js?v=<?php echo $v; ?>"></script>
<script src="js/collection-manager.js?v=<?php echo $v; ?>"></script> 
<script src="js/price-list-manager.js?v=<?php echo $v; ?>"></script>
<script src="js/transaction-manager.js?v=<?php echo $v; ?>"></script>
<script src="js/partner-manager.js?v=<?php echo $v; ?>"></script>
<script src="js/period-closing-manager.js?v=<?php echo $v; ?>"></script>
<script src="js/account-manager.js?v=<?php echo $v; ?>"></script>
<script src="js/check-manager.js?v=<?php echo $v; ?>"></script>
<script src="js/product-manager.js?v=<?php echo $v; ?>"></script>
<script src="js/sayad-scanner.js?v=<?php echo $v; ?>"></script>
<script src="js/invoice-manager.js?v=<?php echo $v; ?>"></script>
<script src="js/return-invoice-manager.js?v=<?php echo $v; ?>"></script>
<script src="js/quotation-manager.js?v=<?php echo $v; ?>"></script>
<script src="js/backup-manager.js?v=<?php echo $v; ?>"></script> 
<script src="js/inventory-audit-manager.js?v=<?php echo $v; ?>"></script> 
<script src="js/balance-audit-manager.js?v=<?php echo $v; ?>"></script> 
<script src="js/smart-audit-manager.js?v=<?php echo $v; ?>"></script>
<script src="js/bank-statement-parsers.js?v=<?php echo $v; ?>"></script>
<script src="js/bank-reconciliation-manager.js?v=<?php echo $v; ?>"></script>
<script src="js/settings-manager.js?v=<?php echo $v; ?>"></script>
<script src="js/error-log-manager.js?v=<?php echo $v; ?>"></script>
<script src="js/app-logger.js?v=<?php echo $v; ?>"></script>
<script src="js/access-control-manager.js?v=<?php echo $v; ?>"></script>
<script src="js/dashboard.js?v=<?php echo $v; ?>"></script>
<script src="js/auth.js?v=<?php echo $v; ?>"></script>
<script src="js/global-search.js?v=<?php echo $v; ?>"></script>
<script src="js/tools-extra.js?v=<?php echo $v; ?>"></script>
<script src="js/asset-manager.js?v=<?php echo $v; ?>"></script>
<script src="js/crm-manager.js?v=<?php echo $v; ?>"></script>
<script src="js/work-manager.js?v=<?php echo $v; ?>"></script>
<script src="js/work-chat.js?v=<?php echo $v; ?>"></script>
<script src="js/work-office.js?v=<?php echo $v; ?>"></script>
<script src="js/app.js?v=<?php echo $v; ?>"></script>
<script src="js/pwa.js?v=<?php echo $v; ?>"></script>

<!-- جستجوی محصولات در مودال کلکسیون -->
<script>
$(document).ready(function() {
    // جستجو در محصولات کلکسیون
    $(document).on('keyup', '#search-collection-products', function() {
        var term = $(this).val().toLowerCase().trim();
        $('#collection-products-list .list-group-item').each(function() {
            var text = $(this).text().toLowerCase();
            if (term === '' || text.indexOf(term) > -1) {
                $(this).removeClass('d-none');
            } else {
                $(this).addClass('d-none');
            }
        });
    });
    
    // جستجو در سایر محصولات
    $(document).on('keyup', '#search-available-products', function() {
        var term = $(this).val().toLowerCase().trim();
        $('#available-products-list .list-group-item').each(function() {
            var text = $(this).text().toLowerCase();
            if (term === '' || text.indexOf(term) > -1) {
                $(this).removeClass('d-none');
            } else {
                $(this).addClass('d-none');
            }
        });
    });
    
    // انتخاب همه محصولات کلکسیون
    $(document).on('change', '#select-all-collection-products', function() {
        var isChecked = $(this).is(':checked');
        $('#collection-products-list .list-group-item:not(.d-none) .product-checkbox').prop('checked', isChecked);
    });
    
    // انتخاب همه سایر محصولات
    $(document).on('change', '#select-all-available-products', function() {
        var isChecked = $(this).is(':checked');
        $('#available-products-list .list-group-item:not(.d-none) .available-product-checkbox').prop('checked', isChecked);
    });
});
</script>