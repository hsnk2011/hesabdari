<div class="tab-pane fade" id="sales-invoices" role="tabpanel">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4>فاکتورهای فروش</h4>
        <div class="d-flex align-items-center gap-3">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="sales-invoices-show-details" data-table="sales_invoices">
                <label class="form-check-label" for="sales-invoices-show-details">نمایش جزییات فاکتور</label>
            </div>
            <button class="btn btn-outline-success" id="add-sales-invoice-btn"><i class="bi bi-plus-circle me-2"></i>ایجاد فاکتور فروش جدید</button>
            <button class="btn btn-outline-info" id="filter-online-store-invoices-btn" title="فاکتورهای ثبت‌شده از فروشگاه آنلاین">
                <i class="bi bi-shop me-1"></i>فروشگاه آنلاین
            </button>
        </div>
    </div>
    <div id="sales_invoices-filter-info" class="mb-2"></div> <div class="row align-items-center mb-3">
        <div class="col-md-6">
            <div class="input-group search-box">
                <span class="input-group-text"><i class="bi bi-search"></i></span><input type="text" class="form-control" id="sales-invoice-search" placeholder="جستجو در شماره فاکتور، نام مشتری، نام محصول، ابعاد، شناسه انبار، مبالغ...">
            </div>
        </div>
        <div class="col-md-3">
            <div class="input-group">
                <label class="input-group-text" for="sales-delivery-status-filter"><i class="bi bi-funnel"></i></label>
                <select class="form-select delivery-status-filter" id="sales-delivery-status-filter" data-type="sales">
                    <option value="">همه وضعیت‌ها</option>
                    <option value="preparing">در حال آماده‌سازی</option>
                    <option value="ready">آماده تحویل</option>
                    <option value="delivered">تحویل شده</option>
                </select>
            </div>
        </div>
        <div class="col-md-3">
            <div class="input-group">
                <label class="input-group-text" for="sales-invoices-limit-select">نمایش</label>
                <select class="form-select pagination-limit-select" id="sales-invoices-limit-select" data-table="sales_invoices">
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
        <table class="table table-striped table-hover" id="sales-invoices-table">
            <thead>
                <tr>
                    <th class="sortable-header" data-sort-by="id"># <span class="sort-indicator"></span></th>
                    <th class="sortable-header" data-sort-by="date">تاریخ <span class="sort-indicator"></span></th>
                    <th class="sortable-header" data-sort-by="customerName">مشتری <span class="sort-indicator"></span></th>
                    <th class="sortable-header" data-sort-by="totalAmount">مبلغ کل <span class="sort-indicator"></span></th>
                    <th class="sortable-header" data-sort-by="discount">تخفیف<span class="sort-indicator"></span></th>
                    <th class="sortable-header" data-sort-by="paidAmount">مبلغ پرداختی <span class="sort-indicator"></span></th>
                    <th class="sortable-header" data-sort-by="remainingAmount">مبلغ باقی‌مانده <span class="sort-indicator"></span></th>
                    <th>وضعیت پرداخت</th>
                    <th>وضعیت تحویل</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody id="sales-invoices-table-body"></tbody>
        </table>
    </div>
    <div class="pagination-container" data-table="sales-invoices"></div>
</div>

<div class="modal fade" id="salesInvoiceModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-fullscreen modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="sales-invoice-modal-title">ایجاد/ویرایش فاکتور فروش</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="sales-invoice-form" class="invoice-form" novalidate>
                    <div class="alert alert-danger form-error" style="display:none;"></div>
                    <input type="hidden" id="sales-invoice-id">

                    <section class="invoice-section invoice-section-meta">
                        <div class="invoice-section-head">
                            <h6 class="invoice-section-title mb-0"><i class="bi bi-person-lines-fill me-2"></i>اطلاعات فاکتور</h6>
                        </div>
                        <div class="invoice-section-body">
                    <div class="row g-2 mb-0 invoice-meta-row-main">
                        <div class="col-12 col-md-4 invoice-meta-person">
                            <label for="sales-invoice-customer" class="form-label">مشتری</label>
                            <div class="input-group">
                                <select class="form-select" id="sales-invoice-customer" required></select>
                                <button class="btn btn-outline-success" type="button" id="add-customer-from-invoice-btn" data-permission="manage_customers" title="افزودن مشتری جدید"><i class="bi bi-plus-lg"></i></button>
                            </div>
                        </div>
                        <div class="col-6 col-md-2 invoice-meta-date">
                            <label for="sales-invoice-date" class="form-label">تاریخ فاکتور</label><input type="text" class="form-control persian-datepicker" id="sales-invoice-date" required>
                        </div>
                        <div class="col-6 col-md-2 invoice-meta-number">
                            <label for="sales-invoice-number" class="form-label">شماره فاکتور</label>
                            <input type="text" class="form-control" id="sales-invoice-number" placeholder="خودکار">
                        </div>
                        <div class="col-12 col-md-2 invoice-meta-desc">
                            <label for="sales-invoice-description" class="form-label">توضیحات</label>
                            <textarea class="form-control" id="sales-invoice-description" rows="1"></textarea>
                        </div>
                        <div class="col-6 col-md-2 invoice-meta-terms">
                            <label for="sales-invoice-payment-terms" class="form-label">شرایط پرداخت</label>
                            <select class="form-select" id="sales-invoice-payment-terms">
                                <option value="cash" selected>نقدی (پیش‌فرض)</option>
                                <option value="net15">۱۵ روزه</option>
                                <option value="net30">۳۰ روزه</option>
                                <option value="net60">۶۰ روزه</option>
                                <option value="net90">۹۰ روزه</option>
                                <option value="installment">اقساطی</option>
                            </select>
                        </div>
                        <div class="col-6 col-md-2 invoice-meta-due" id="sales-due-date-wrapper" style="display:none;">
                            <label for="sales-invoice-due-date" class="form-label">تاریخ سررسید</label>
                            <input type="text" class="form-control persian-datepicker" id="sales-invoice-due-date">
                        </div>
                        <div class="col-6 col-md-2 salesperson-wrapper" style="display:none;">
                            <label for="sales-invoice-salesperson" class="form-label">فروشنده</label>
                            <select class="form-select" id="sales-invoice-salesperson"></select>
                        </div>
                        <div class="col-12 customer-group-hint" style="display:none;"></div>
                        <div class="col-6 col-md-auto invoice-meta-tax">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="sales-invoice-tax-enabled">
                                <label class="form-check-label" for="sales-invoice-tax-enabled">اعمال مالیات</label>
                            </div>
                            <input type="hidden" id="sales-invoice-tax-rate" value="0">
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
                    <div id="sales-invoice-items-container" class="invoice-items-list"></div>
                    <div class="d-flex gap-2 flex-wrap">
                        <button type="button" class="btn btn-outline-primary btn-sm" id="add-sales-invoice-item-btn"><i class="bi bi-plus"></i> افزودن کالا</button>
                        <button type="button" class="btn btn-outline-info btn-sm" id="add-sales-service-item-btn"><i class="bi bi-tools"></i> افزودن خدمات/هزینه</button>
                    </div>
                        </div>
                    </section>

                    <section class="invoice-section invoice-section-payments">
                        <div class="invoice-section-head">
                            <h6 class="invoice-section-title mb-0"><i class="bi bi-cash-stack me-2"></i>بخش پرداخت</h6>
                        </div>
                        <div class="invoice-section-body">
                    <div id="sales-invoice-payments-container" class="invoice-payments-list"></div>
                    <button type="button" class="btn btn-outline-secondary btn-sm mb-2" id="add-sales-invoice-payment-btn"><i class="bi bi-plus"></i> افزودن ردیف پرداخت</button>
                    <div class="card border-0 bg-white invoice-advance-card" id="sales-advance-log-card">
                        <div class="card-header py-2 px-3 bg-transparent border-0 border-top">
                            <button type="button" class="btn btn-link btn-sm text-decoration-none text-dark w-100 text-start d-flex justify-content-between align-items-center p-0"
                                    data-bs-toggle="collapse" data-bs-target="#sales-advance-log-collapse" aria-expanded="false" aria-controls="sales-advance-log-collapse">
                                <span class="small fw-semibold"><i class="bi bi-clock-history me-1"></i>۱۰ تراکنش آخر علی‌الحساب مشتری</span>
                                <i class="bi bi-chevron-down small text-muted"></i>
                            </button>
                        </div>
                        <div id="sales-advance-log-collapse" class="collapse">
                            <div class="card-body py-2 px-2">
                                <div class="d-flex justify-content-end mb-2">
                                    <button type="button" class="btn btn-sm btn-outline-primary" id="sales-attach-advance-btn" disabled>
                                        <i class="bi bi-link-45deg"></i> افزودن انتخاب‌شده به فاکتور
                                    </button>
                                </div>
                                <div id="sales-advance-log" class="small text-muted">ابتدا مشتری را انتخاب کنید.</div>
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
                                    <span class="badge bg-secondary rounded-pill fs-6" id="sales-invoice-total-quantity">۰</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center py-1">
                                    جمع متراژ
                                    <span class="badge bg-secondary rounded-pill fs-6" id="sales-invoice-total-area">۰ متر مربع</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center py-1">جمع کل
                                    <span class="badge bg-primary rounded-pill fs-6" id="sales-invoice-total">۰ تومان</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center py-1">تخفیف
                                    <div class="input-group input-group-sm" style="width: 150px;">
                                        <input type="text" class="form-control numeric-input" id="sales-invoice-discount" value="0"><span class="input-group-text">ت</span>
                                    </div>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center py-1 sales-tax-row" style="display:none;">مالیات
                                    <div class="input-group input-group-sm" style="width: 150px;">
                                        <input type="text" class="form-control numeric-input" id="sales-invoice-tax-amount" value="0" readonly><span class="input-group-text">ت</span>
                                    </div>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center py-1 fw-bold">جمع نهایی<span class="badge bg-info rounded-pill fs-6" id="sales-invoice-grand-total">۰ تومان</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center py-1">پرداختی<span class="badge bg-success rounded-pill fs-6" id="sales-invoice-paid">۰ تومان</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center py-1 fw-bold">باقی‌مانده<span class="badge bg-danger rounded-pill fs-6" id="sales-invoice-remaining">۰ تومان</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                        </div>
                    </section>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button><button type="button" class="btn btn-success" id="save-sales-invoice-btn">ذخیره فاکتور</button>
            </div>
        </div>
    </div>
</div>