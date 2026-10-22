<div class="tab-pane fade" id="purchase-invoices" role="tabpanel">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4>فاکتورهای خرید</h4>
        <div class="d-flex align-items-center gap-3">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="purchase-invoices-show-details" data-table="purchase_invoices">
                <label class="form-check-label" for="purchase-invoices-show-details">نمایش جزییات فاکتور</label>
            </div>
            <button class="btn btn-primary" id="add-purchase-invoice-btn"><i class="bi bi-plus-circle me-2"></i>ایجاد فاکتور خرید جدید</button>
        </div>
    </div>
    <div id="purchase_invoices-filter-info" class="mb-2"></div>
     <div class="row align-items-center mb-3">
        <div class="col-md-4">
            <div class="input-group search-box">
                <span class="input-group-text"><i class="bi bi-search"></i></span><input type="text" class="form-control" id="purchase-invoice-search" placeholder="جستجو در شماره فاکتور، نام تأمین‌کننده، نام محصول، ابعاد، شناسه انبار، مبالغ...">
            </div>
        </div>
        <div class="col-md-3">
            <div class="input-group">
                <label class="input-group-text" for="purchase-delivery-status-filter"><i class="bi bi-funnel"></i></label>
                <select class="form-select delivery-status-filter" id="purchase-delivery-status-filter" data-type="purchase">
                    <option value="">همه وضعیت‌ها</option>
                    <option value="preparing">در حال آماده‌سازی</option>
                    <option value="ready">آماده تحویل</option>
                    <option value="delivered">تحویل شده</option>
                </select>
            </div>
        </div>
        <div class="col-md-3">
            <div class="input-group">
                <label class="input-group-text" for="purchase-warehouse-type-filter"><i class="bi bi-building-gear"></i></label>
                <select class="form-select" id="purchase-warehouse-type-filter">
                    <option value="">همه انبارها</option>
                    <option value="order">سفارش به کارخانه</option>
                    <option value="physical">سایر انبارها</option>
                </select>
            </div>
        </div>
        <div class="col-md-2">
            <div class="input-group">
                <label class="input-group-text" for="purchase-invoices-limit-select">نمایش</label>
                <select class="form-select pagination-limit-select" id="purchase-invoices-limit-select" data-table="purchase_invoices">
                    <option value="10" selected>10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                    <option value="-1">همه</option>
                </select>
                <span class="input-group-text">ردیف</span>
            </div>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-striped table-hover" id="purchase-invoices-table">
            <thead>
                <tr>
                    <th class="sortable-header" data-sort-by="id"># <span class="sort-indicator"></span></th>
                    <th class="sortable-header" data-sort-by="date">تاریخ <span class="sort-indicator"></span></th>
                    <th class="sortable-header" data-sort-by="supplierName">تأمین‌کننده <span class="sort-indicator"></span></th>
                    <th class="sortable-header" data-sort-by="totalAmount">مبلغ کل <span class="sort-indicator"></span></th>
                    <th class="sortable-header" data-sort-by="discount">تخفیف<span class="sort-indicator"></span></th>
                    <th class="sortable-header" data-sort-by="paidAmount">مبلغ پرداختی <span class="sort-indicator"></span></th>
                    <th class="sortable-header" data-sort-by="remainingAmount">مبلغ باقی‌مانده <span class="sort-indicator"></span></th>
                    <th>وضعیت پرداخت</th>
                    <th>وضعیت تحویل</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody id="purchase-invoices-table-body"></tbody>
        </table>
    </div>
    <div class="pagination-container" data-table="purchase-invoices"></div>
</div>

<div class="modal fade" id="purchaseInvoiceModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-fullscreen modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="purchase-invoice-modal-title">ایجاد/ویرایش فاکتور خرید</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="purchase-invoice-form" class="invoice-form" novalidate>
                    <div class="alert alert-danger form-error" style="display:none;"></div>
                    <input type="hidden" id="purchase-invoice-id">
                    <input type="hidden" id="purchase-invoice-tax-rate" value="0">

                    <section class="invoice-section invoice-section-meta">
                        <div class="invoice-section-head">
                            <h6 class="invoice-section-title mb-0"><i class="bi bi-truck me-2"></i>اطلاعات فاکتور</h6>
                        </div>
                        <div class="invoice-section-body">
                    <div class="row g-2 mb-0 invoice-meta-row-main">
                        <div class="col-12 col-md-4 invoice-meta-person">
                            <label for="purchase-invoice-supplier" class="form-label mb-0">تأمین‌کننده</label>
                            <div class="input-group input-group-sm">
                                <select class="form-select form-select-sm" id="purchase-invoice-supplier" required></select>
                                <button class="btn btn-outline-success btn-sm" type="button" id="add-supplier-from-invoice-btn" data-permission="manage_suppliers" title="افزودن تامین‌کننده جدید"><i class="bi bi-plus-lg"></i></button>
                            </div>
                        </div>
                        <div class="col-6 col-md-2 invoice-meta-date">
                            <label for="purchase-invoice-date" class="form-label mb-0">تاریخ</label>
                            <input type="text" class="form-control form-control-sm persian-datepicker" id="purchase-invoice-date" required>
                        </div>
                        <div class="col-12 col-md-3 invoice-meta-warehouse">
                            <label for="purchase-invoice-warehouse" class="form-label mb-0">انبار مقصد</label>
                            <select class="form-select form-select-sm" id="purchase-invoice-warehouse">
                                <option value="">بدون انبار</option>
                            </select>
                        </div>
                        <div class="col-6 col-md-2 invoice-meta-number">
                            <label for="purchase-invoice-number" class="form-label mb-0">شماره فاکتور</label>
                            <input type="text" class="form-control form-control-sm" id="purchase-invoice-number" placeholder="خودکار">
                        </div>
                        <div class="col-12 col-md-2 invoice-meta-desc">
                            <label for="purchase-invoice-description" class="form-label mb-0">توضیحات</label>
                            <textarea class="form-control form-control-sm" id="purchase-invoice-description" rows="1"></textarea>
                        </div>
                        <div class="col-6 col-md-2 invoice-meta-terms">
                            <label for="purchase-invoice-payment-terms" class="form-label mb-0">شرایط پرداخت</label>
                            <select class="form-select form-select-sm" id="purchase-invoice-payment-terms">
                                <option value="cash" selected>نقدی (پیش‌فرض)</option>
                                <option value="net15">۱۵ روزه</option>
                                <option value="net30">۳۰ روزه</option>
                                <option value="net60">۶۰ روزه</option>
                                <option value="net90">۹۰ روزه</option>
                                <option value="installment">اقساطی</option>
                            </select>
                        </div>
                        <div class="col-6 col-md-2 invoice-meta-due" id="purchase-due-date-wrapper" style="display:none;">
                            <label for="purchase-invoice-due-date" class="form-label mb-0">تاریخ سررسید</label>
                            <input type="text" class="form-control form-control-sm persian-datepicker" id="purchase-invoice-due-date">
                        </div>
                        <div class="col-6 col-md-auto invoice-meta-tax">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="purchase-invoice-tax-enabled">
                                <label class="form-check-label" for="purchase-invoice-tax-enabled">اعمال مالیات</label>
                            </div>
                        </div>
                    </div>
                        </div>
                    </section>

                    <section class="invoice-section invoice-section-items">
                        <div class="invoice-section-head d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <h6 class="invoice-section-title mb-0"><i class="bi bi-list-ul me-2"></i>آیتم‌های فاکتور</h6>
                            <small class="text-muted"><i class="bi bi-info-circle me-1"></i>جستجو با شناسه انبار، کد طرح یا نام</small>
                        </div>
                        <div class="invoice-section-body">
                    <div id="purchase-invoice-items-container" class="invoice-items-list"></div>
                    <div class="d-flex gap-2 flex-wrap">
                        <button type="button" class="btn btn-outline-primary btn-sm" id="add-purchase-invoice-item-btn"><i class="bi bi-plus"></i> افزودن کالا</button>
                        <button type="button" class="btn btn-outline-info btn-sm" id="add-purchase-service-item-btn"><i class="bi bi-tools"></i> افزودن خدمات/هزینه</button>
                    </div>
                        </div>
                    </section>

                    <section class="invoice-section invoice-section-payments">
                        <div class="invoice-section-head">
                            <h6 class="invoice-section-title mb-0"><i class="bi bi-cash-stack me-2"></i>بخش پرداخت</h6>
                        </div>
                        <div class="invoice-section-body">
                    <div id="purchase-invoice-payments-container" class="invoice-payments-list"></div>
                    <button type="button" class="btn btn-outline-secondary btn-sm mb-2" id="add-purchase-invoice-payment-btn"><i class="bi bi-plus"></i> افزودن ردیف پرداخت</button>
                    <div class="card border-0 bg-white invoice-advance-card" id="purchase-advance-log-card">
                        <div class="card-header py-2 px-3 bg-transparent border-0 border-top">
                            <button type="button" class="btn btn-link btn-sm text-decoration-none text-dark w-100 text-start d-flex justify-content-between align-items-center p-0"
                                    data-bs-toggle="collapse" data-bs-target="#purchase-advance-log-collapse" aria-expanded="false" aria-controls="purchase-advance-log-collapse">
                                <span class="small fw-semibold"><i class="bi bi-clock-history me-1"></i>۱۰ تراکنش آخر علی‌الحساب تأمین‌کننده</span>
                                <i class="bi bi-chevron-down small text-muted"></i>
                            </button>
                        </div>
                        <div id="purchase-advance-log-collapse" class="collapse">
                            <div class="card-body py-2 px-2">
                                <div class="d-flex justify-content-end mb-2">
                                    <button type="button" class="btn btn-sm btn-outline-primary" id="purchase-attach-advance-btn" disabled>
                                        <i class="bi bi-link-45deg"></i> افزودن انتخاب‌شده به فاکتور
                                    </button>
                                </div>
                                <div id="purchase-advance-log" class="small text-muted">ابتدا تأمین‌کننده را انتخاب کنید.</div>
                            </div>
                        </div>
                    </div>
                        </div>
                    </section>

                    <section class="invoice-section invoice-section-totals">
                        <div class="invoice-section-head">
                            <h6 class="invoice-section-title mb-0"><i class="bi bi-calculator me-2"></i>جمع و مانده</h6>
                        </div>
                        <div class="invoice-section-body">
                    <div class="row justify-content-end">
                        <div class="col-lg-5 col-md-7">
                            <ul class="list-group list-group-flush invoice-totals-list">
                                <li class="list-group-item d-flex justify-content-between align-items-center py-1">
                                    جمع تعداد اقلام
                                    <span class="badge bg-secondary rounded-pill fs-6" id="purchase-invoice-total-quantity">۰</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center py-1">
                                    جمع متراژ
                                    <span class="badge bg-secondary rounded-pill fs-6" id="purchase-invoice-total-area">۰ متر مربع</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center py-1">جمع کل
                                    <span class="badge bg-primary rounded-pill fs-6" id="purchase-invoice-total">۰ تومان</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center py-1">تخفیف
                                    <div class="input-group input-group-sm" style="width: 150px;">
                                        <input type="text" class="form-control numeric-input" id="purchase-invoice-discount" value="0"><span class="input-group-text">ت</span>
                                    </div>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center py-1 purchase-tax-row" style="display:none;">مالیات
                                    <div class="input-group input-group-sm" style="width: 150px;">
                                        <input type="text" class="form-control numeric-input" id="purchase-invoice-tax-amount" value="0" readonly><span class="input-group-text">ت</span>
                                    </div>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center py-1 fw-bold">جمع نهایی<span class="badge bg-info rounded-pill fs-6" id="purchase-invoice-grand-total">۰ تومان</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center py-1">پرداختی<span class="badge bg-success rounded-pill fs-6" id="purchase-invoice-paid">۰ تومان</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center py-1 fw-bold">باقی‌مانده<span class="badge bg-danger rounded-pill fs-6" id="purchase-invoice-remaining">۰ تومان</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                        </div>
                    </section>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-primary me-auto d-none" id="copy-purchase-invoice-btn">
                    <i class="bi bi-clipboard me-1"></i>کپی فاکتور
                </button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button><button type="button" class="btn btn-success" id="save-purchase-invoice-btn">ذخیره فاکتور</button>
            </div>
        </div>
    </div>
</div>