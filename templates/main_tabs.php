<ul class="nav nav-tabs" id="mainTab" role="tablist">
    <li class="nav-item" role="presentation" data-permission="view_dashboard">
        <button class="nav-link active" id="dashboard-tab" data-bs-toggle="tab" data-bs-target="#dashboard" type="button" role="tab"><i class="bi bi-grid-1x2-fill me-2"></i>داشبورد</button>
    </li>
    <li class="nav-item" role="presentation" data-permission-any="use_crm,use_projects,use_messenger,use_cartable,use_ideas,manage_hr">
        <button class="nav-link" id="crm-tab" data-bs-toggle="tab" data-bs-target="#crm" type="button" role="tab"><i class="bi bi-kanban me-2"></i>میز کار</button>
    </li>
    <li class="nav-item" role="presentation" data-permission="manage_quotations">
        <button class="nav-link" id="quotations-tab" data-bs-toggle="tab" data-bs-target="#quotations" type="button" role="tab" data-table-name="quotations"><i class="bi bi-file-earmark-text me-2"></i>پیش فاکتور</button>
    </li>
    <li class="nav-item" role="presentation" data-permission="manage_sales_invoices">
        <button class="nav-link" id="sales-invoices-tab" data-bs-toggle="tab" data-bs-target="#sales-invoices" type="button" role="tab" data-table-name="sales_invoices"><i class="bi bi-receipt-cutoff me-2"></i>فاکتورهای فروش</button>
    </li>
    <li class="nav-item" role="presentation" data-permission="manage_purchase_invoices">
        <button class="nav-link" id="purchase-invoices-tab" data-bs-toggle="tab" data-bs-target="#purchase-invoices" type="button" role="tab" data-table-name="purchase_invoices"><i class="bi bi-cart-check-fill me-2"></i>فاکتورهای خرید</button>
    </li>
    <li class="nav-item" role="presentation" data-permission="manage_return_invoices">
        <button class="nav-link" id="return-invoices-tab" data-bs-toggle="tab" data-bs-target="#return-invoices" type="button" role="tab"><i class="bi bi-arrow-counterclockwise me-2"></i>فاکتورهای مرجوعی</button>
    </li>
    <li class="nav-item" role="presentation" data-permission="manage_products">
        <button class="nav-link" id="products-tab" data-bs-toggle="tab" data-bs-target="#products" type="button" role="tab" data-table-name="products"><i class="bi bi-box-seam-fill me-2"></i>انبار و محصولات</button>
    </li>
    <li class="nav-item" role="presentation" data-permission="manage_consignment">
        <button class="nav-link" id="consignment-tab" data-bs-toggle="tab" data-bs-target="#consignment" type="button" role="tab"><i class="bi bi-truck me-2"></i>محصولات امانی</button>
    </li>
    <li class="nav-item" role="presentation" data-permission="manage_transactions">
        <button class="nav-link" id="transactions-tab" data-bs-toggle="tab" data-bs-target="#transactions" type="button" role="tab" data-table-name="transactions"><i class="bi bi-arrow-down-up me-2"></i>تراکنشات</button>
    </li>
    <li class="nav-item" role="presentation" data-permission="manage_checks">
        <button class="nav-link" id="checks-tab" data-bs-toggle="tab" data-bs-target="#checks" type="button" role="tab" data-table-name="checks"><i class="bi bi-bank me-2"></i>مدیریت چک‌ها</button>
    </li>
    <li class="nav-item" role="presentation" data-permission="manage_accounts">
        <button class="nav-link" id="accounts-tab" data-bs-toggle="tab" data-bs-target="#accounts" type="button" role="tab" data-table-name="accounts"><i class="bi bi-collection-fill me-2"></i>بانک‌ها و صندوق</button>
    </li>
    <li class="nav-item" role="presentation" data-permission="manage_assets">
        <button class="nav-link" id="assets-tab" data-bs-toggle="tab" data-bs-target="#assets" type="button" role="tab"><i class="bi bi-gem me-2"></i>دارایی‌ها</button>
    </li>
    <li class="nav-item dropdown d-none d-md-block" id="persons-dropdown">
        <button type="button" class="nav-link dropdown-toggle" id="persons-dropdown-btn" data-bs-toggle="dropdown" data-bs-auto-close="true" aria-expanded="false">
            <i class="bi bi-people-fill me-2"></i>اشخاص
        </button>
        <ul class="dropdown-menu" aria-labelledby="persons-dropdown-btn">
            <li data-permission="manage_customers"><button type="button" class="dropdown-item" data-bs-toggle="tab" data-bs-target="#customers" id="customers-tab" data-table-name="customers">مشتریان</button></li>
            <li data-permission="manage_suppliers"><button type="button" class="dropdown-item" data-bs-toggle="tab" data-bs-target="#suppliers" id="suppliers-tab" data-table-name="suppliers">تأمین‌کنندگان</button></li>
            <li data-permission="manage_contacts"><button type="button" class="dropdown-item" data-bs-toggle="tab" data-bs-target="#contacts" id="contacts-tab" data-table-name="contacts">سایر اشخاص</button></li>
        </ul>
    </li>
    <li class="nav-item d-md-none" role="presentation" data-permission="manage_customers" id="customers-tab-mobile-item">
        <button class="nav-link" id="customers-tab-mobile" data-bs-toggle="tab" data-bs-target="#customers" type="button" role="tab" data-table-name="customers"><i class="bi bi-person-fill me-2"></i>مشتریان</button>
    </li>
    <li class="nav-item d-md-none" role="presentation" data-permission="manage_suppliers" id="suppliers-tab-mobile-item">
        <button class="nav-link" id="suppliers-tab-mobile" data-bs-toggle="tab" data-bs-target="#suppliers" type="button" role="tab" data-table-name="suppliers"><i class="bi bi-truck me-2"></i>تأمین‌کنندگان</button>
    </li>
    <li class="nav-item d-md-none" role="presentation" data-permission="manage_contacts" id="contacts-tab-mobile-item">
        <button class="nav-link" id="contacts-tab-mobile" data-bs-toggle="tab" data-bs-target="#contacts" type="button" role="tab" data-table-name="contacts"><i class="bi bi-person-badge me-2"></i>سایر اشخاص</button>
    </li>
    <li class="nav-item" role="presentation" data-permission="view_price_list">
        <button class="nav-link" id="price-list-tab" data-bs-toggle="tab" data-bs-target="#price-list" type="button" role="tab" data-table-name="price_list"><i class="bi bi-cash-stack me-2"></i>لیست قیمت</button>
    </li>
    <li class="nav-item" role="presentation" data-permission="view_reports">
        <button class="nav-link" id="reports-tab" data-bs-toggle="tab" data-bs-target="#reports" type="button" role="tab"><i class="bi bi-graph-up-arrow me-2"></i>گزارشات</button>
    </li>
    <li class="nav-item" role="presentation" data-permission="manage_settings">
        <button class="nav-link" id="smart-audit-tab" data-bs-toggle="tab" data-bs-target="#section-smart-audit" type="button" role="tab"><i class="bi bi-robot me-2"></i>حسابرس هوشمند</button>
    </li>
    <li class="nav-item" role="presentation" data-permission-any="manage_stocktaking,manage_installments,manage_bank_imports,import_export_data,manage_customers,manage_transactions,view_reports,manage_settings">
        <button class="nav-link" id="tools-tab" data-bs-toggle="tab" data-bs-target="#tools" type="button" role="tab"><i class="bi bi-tools me-2"></i>ابزارها</button>
    </li>
     <li class="nav-item" role="presentation" data-permission-any="manage_settings,manage_collections">
        <button class="nav-link" id="settings-tab" data-bs-toggle="tab" data-bs-target="#settings" type="button" role="tab" data-table-name="settings"><i class="bi bi-gear-wide-connected me-2"></i>تنظیمات</button>
    </li>
</ul>