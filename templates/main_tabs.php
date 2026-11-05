<?php
// Grouped navigation (included by header.php). Every section button keeps the
// id, data-bs-target and data-table-name of the classic tab list, so app.js
// and global-search.js drive it unchanged. A group shows when the user may
// open at least one of its sections (data-permission-any = its sections).
?>
<ul class="nav ui-mainnav" id="mainTab" role="tablist">
    <li class="nav-item" role="presentation" data-permission="view_dashboard">
        <button class="nav-link active" id="dashboard-tab" data-bs-toggle="tab" data-bs-target="#dashboard" type="button" role="tab"><i class="bi bi-grid-1x2"></i><span>داشبورد</span></button>
    </li>
    <li class="nav-item" role="presentation" data-permission-any="use_crm,use_projects,use_messenger,use_cartable,use_ideas,manage_hr">
        <button class="nav-link" id="crm-tab" data-bs-toggle="tab" data-bs-target="#crm" type="button" role="tab"><i class="bi bi-kanban"></i><span>میز کار</span></button>
    </li>

    <li class="nav-item dropdown ui-navgroup" id="ui-navgroup-trade" data-permission-any="manage_quotations,manage_sales_invoices,manage_purchase_invoices,manage_return_invoices">
        <button type="button" class="nav-link dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-receipt"></i><span>فروش و خرید</span></button>
        <ul class="dropdown-menu">
            <li data-permission="manage_sales_invoices"><button type="button" class="dropdown-item" id="sales-invoices-tab" data-bs-toggle="tab" data-bs-target="#sales-invoices" role="tab" data-table-name="sales_invoices"><i class="bi bi-receipt-cutoff"></i>فاکتورهای فروش</button></li>
            <li data-permission="manage_purchase_invoices"><button type="button" class="dropdown-item" id="purchase-invoices-tab" data-bs-toggle="tab" data-bs-target="#purchase-invoices" role="tab" data-table-name="purchase_invoices"><i class="bi bi-cart-check"></i>فاکتورهای خرید</button></li>
            <li data-permission="manage_return_invoices"><button type="button" class="dropdown-item" id="return-invoices-tab" data-bs-toggle="tab" data-bs-target="#return-invoices" role="tab"><i class="bi bi-arrow-counterclockwise"></i>فاکتورهای مرجوعی</button></li>
            <li data-permission="manage_quotations"><button type="button" class="dropdown-item" id="quotations-tab" data-bs-toggle="tab" data-bs-target="#quotations" role="tab" data-table-name="quotations"><i class="bi bi-file-earmark-text"></i>پیش فاکتور</button></li>
        </ul>
    </li>

    <li class="nav-item dropdown ui-navgroup" id="ui-navgroup-stock" data-permission-any="manage_products,manage_consignment,view_price_list">
        <button type="button" class="nav-link dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-box-seam"></i><span>انبار</span></button>
        <ul class="dropdown-menu">
            <li data-permission="manage_products"><button type="button" class="dropdown-item" id="products-tab" data-bs-toggle="tab" data-bs-target="#products" role="tab" data-table-name="products"><i class="bi bi-box-seam"></i>انبار و محصولات</button></li>
            <li data-permission="manage_consignment"><button type="button" class="dropdown-item" id="consignment-tab" data-bs-toggle="tab" data-bs-target="#consignment" role="tab"><i class="bi bi-truck"></i>محصولات امانی</button></li>
            <li data-permission="view_price_list"><button type="button" class="dropdown-item" id="price-list-tab" data-bs-toggle="tab" data-bs-target="#price-list" role="tab" data-table-name="price_list"><i class="bi bi-tags"></i>لیست قیمت</button></li>
        </ul>
    </li>

    <li class="nav-item dropdown ui-navgroup" id="ui-navgroup-money" data-permission-any="manage_transactions,manage_checks,manage_accounts,manage_assets">
        <button type="button" class="nav-link dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-wallet2"></i><span>مالی</span></button>
        <ul class="dropdown-menu">
            <li data-permission="manage_transactions"><button type="button" class="dropdown-item" id="transactions-tab" data-bs-toggle="tab" data-bs-target="#transactions" role="tab" data-table-name="transactions"><i class="bi bi-arrow-down-up"></i>تراکنشات</button></li>
            <li data-permission="manage_checks"><button type="button" class="dropdown-item" id="checks-tab" data-bs-toggle="tab" data-bs-target="#checks" role="tab" data-table-name="checks"><i class="bi bi-credit-card-2-front"></i>مدیریت چک‌ها</button></li>
            <li data-permission="manage_accounts"><button type="button" class="dropdown-item" id="accounts-tab" data-bs-toggle="tab" data-bs-target="#accounts" role="tab" data-table-name="accounts"><i class="bi bi-bank"></i>بانک‌ها و صندوق</button></li>
            <li data-permission="manage_assets"><button type="button" class="dropdown-item" id="assets-tab" data-bs-toggle="tab" data-bs-target="#assets" role="tab"><i class="bi bi-gem"></i>دارایی‌ها</button></li>
        </ul>
    </li>

    <li class="nav-item dropdown ui-navgroup" id="persons-dropdown">
        <button type="button" class="nav-link dropdown-toggle" id="persons-dropdown-btn" data-bs-toggle="dropdown" data-bs-auto-close="true" aria-expanded="false"><i class="bi bi-people"></i><span>اشخاص</span></button>
        <ul class="dropdown-menu" aria-labelledby="persons-dropdown-btn">
            <li data-permission="manage_customers"><button type="button" class="dropdown-item" data-bs-toggle="tab" data-bs-target="#customers" id="customers-tab" data-table-name="customers"><i class="bi bi-person"></i>مشتریان</button></li>
            <li data-permission="manage_suppliers"><button type="button" class="dropdown-item" data-bs-toggle="tab" data-bs-target="#suppliers" id="suppliers-tab" data-table-name="suppliers"><i class="bi bi-truck"></i>تأمین‌کنندگان</button></li>
            <li data-permission="manage_contacts"><button type="button" class="dropdown-item" data-bs-toggle="tab" data-bs-target="#contacts" id="contacts-tab" data-table-name="contacts"><i class="bi bi-person-badge"></i>سایر اشخاص</button></li>
        </ul>
    </li>
    <?php // Kept for app.js (the classic layout shows these on phones); the drawer above serves phones here. ?>
    <li class="nav-item ui-legacy-hidden" role="presentation" data-permission="manage_customers" id="customers-tab-mobile-item">
        <button class="nav-link" id="customers-tab-mobile" data-bs-toggle="tab" data-bs-target="#customers" type="button" role="tab" data-table-name="customers" tabindex="-1">مشتریان</button>
    </li>
    <li class="nav-item ui-legacy-hidden" role="presentation" data-permission="manage_suppliers" id="suppliers-tab-mobile-item">
        <button class="nav-link" id="suppliers-tab-mobile" data-bs-toggle="tab" data-bs-target="#suppliers" type="button" role="tab" data-table-name="suppliers" tabindex="-1">تأمین‌کنندگان</button>
    </li>
    <li class="nav-item ui-legacy-hidden" role="presentation" data-permission="manage_contacts" id="contacts-tab-mobile-item">
        <button class="nav-link" id="contacts-tab-mobile" data-bs-toggle="tab" data-bs-target="#contacts" type="button" role="tab" data-table-name="contacts" tabindex="-1">سایر اشخاص</button>
    </li>

    <li class="nav-item dropdown ui-navgroup" id="ui-navgroup-reports" data-permission-any="view_reports,manage_settings">
        <button type="button" class="nav-link dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-graph-up-arrow"></i><span>گزارش</span></button>
        <ul class="dropdown-menu">
            <li data-permission="view_reports"><button type="button" class="dropdown-item" id="reports-tab" data-bs-toggle="tab" data-bs-target="#reports" role="tab"><i class="bi bi-graph-up-arrow"></i>گزارشات</button></li>
            <li data-permission="manage_settings"><button type="button" class="dropdown-item" id="smart-audit-tab" data-bs-toggle="tab" data-bs-target="#section-smart-audit" role="tab"><i class="bi bi-robot"></i>حسابرس هوشمند</button></li>
            <li data-permission="view_reports"><a class="dropdown-item" href="analytics/index.php"><i class="bi bi-bar-chart-line"></i>آنالیز فروش</a></li>
        </ul>
    </li>

    <li class="nav-item ui-nav-end" role="presentation" data-permission-any="manage_stocktaking,manage_installments,manage_bank_imports,import_export_data,manage_customers,manage_transactions,view_reports,manage_settings">
        <button class="nav-link" id="tools-tab" data-bs-toggle="tab" data-bs-target="#tools" type="button" role="tab"><i class="bi bi-tools"></i><span>ابزارها</span></button>
    </li>
    <li class="nav-item" role="presentation" data-permission-any="manage_settings,manage_collections">
        <button class="nav-link" id="settings-tab" data-bs-toggle="tab" data-bs-target="#settings" type="button" role="tab" data-table-name="settings"><i class="bi bi-gear"></i><span>تنظیمات</span></button>
    </li>
</ul>
