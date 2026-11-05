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
// Each script's URL also carries its modification time, so a changed file is
// fetched again even when APP_VERSION was not bumped.
$jsV = function (string $file) use ($v) {
    return $file . '?v=' . $v . '.' . (int)@filemtime(dirname(__DIR__) . '/' . $file);
};
?>
<script>window.__APP_VERSION__ = <?php echo json_encode($v); ?>;</script>
<script src="<?php echo $jsV('js/config.js'); ?>"></script>
<script src="<?php echo $jsV('js/api.js'); ?>"></script>
<script src="<?php echo $jsV('js/ui.js'); ?>"></script>
<script src="<?php echo $jsV('js/image-editor.js'); ?>"></script>
<script src="<?php echo $jsV('js/person-statement-print.js'); ?>"></script>
<script src="<?php echo $jsV('js/reports.js'); ?>"></script>
<script src="<?php echo $jsV('js/person-list-ui.js'); ?>"></script>
<script src="<?php echo $jsV('js/entity-manager.js'); ?>"></script>
<script src="<?php echo $jsV('js/barcode-manager.js'); ?>"></script>
<script src="<?php echo $jsV('js/collection-manager.js'); ?>"></script> 
<script src="<?php echo $jsV('js/stock-share-manager.js'); ?>"></script>
<script src="<?php echo $jsV('js/price-list-manager.js'); ?>"></script>
<script src="<?php echo $jsV('js/transaction-manager.js'); ?>"></script>
<script src="<?php echo $jsV('js/partner-manager.js'); ?>"></script>
<script src="<?php echo $jsV('js/period-closing-manager.js'); ?>"></script>
<script src="<?php echo $jsV('js/account-manager.js'); ?>"></script>
<script src="<?php echo $jsV('js/check-manager.js'); ?>"></script>
<script src="<?php echo $jsV('js/product-manager.js'); ?>"></script>
<script src="<?php echo $jsV('js/sayad-scanner.js'); ?>"></script>
<script src="<?php echo $jsV('js/invoice-manager.js'); ?>"></script>
<script src="<?php echo $jsV('js/return-invoice-manager.js'); ?>"></script>
<script src="<?php echo $jsV('js/quotation-manager.js'); ?>"></script>
<script src="<?php echo $jsV('js/backup-manager.js'); ?>"></script> 
<script src="<?php echo $jsV('js/inventory-audit-manager.js'); ?>"></script> 
<script src="<?php echo $jsV('js/balance-audit-manager.js'); ?>"></script> 
<script src="<?php echo $jsV('js/smart-audit-manager.js'); ?>"></script>
<script src="<?php echo $jsV('js/bank-statement-parsers.js'); ?>"></script>
<script src="<?php echo $jsV('js/bank-reconciliation-manager.js'); ?>"></script>
<script src="<?php echo $jsV('js/settings-manager.js'); ?>"></script>
<script src="<?php echo $jsV('js/error-log-manager.js'); ?>"></script>
<script src="<?php echo $jsV('js/app-logger.js'); ?>"></script>
<script src="<?php echo $jsV('js/access-control-manager.js'); ?>"></script>
<script src="<?php echo $jsV('js/dashboard.js'); ?>"></script>
<script src="<?php echo $jsV('js/auth.js'); ?>"></script>
<script src="<?php echo $jsV('js/global-search.js'); ?>"></script>
<script src="<?php echo $jsV('js/tools-extra.js'); ?>"></script>
<script src="<?php echo $jsV('js/asset-manager.js'); ?>"></script>
<script src="<?php echo $jsV('js/crm-manager.js'); ?>"></script>
<script src="<?php echo $jsV('js/work-manager.js'); ?>"></script>
<script src="<?php echo $jsV('js/work-chat.js'); ?>"></script>
<script src="<?php echo $jsV('js/work-office.js'); ?>"></script>
<script src="<?php echo $jsV('js/ui-nav.js'); ?>"></script>
<script src="<?php echo $jsV('js/table-sort.js'); ?>"></script>
<script src="<?php echo $jsV('js/app.js'); ?>"></script>
<script src="<?php echo $jsV('js/pwa.js'); ?>"></script>

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