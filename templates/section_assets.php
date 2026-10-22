<div class="tab-pane fade" id="assets" role="tabpanel">
    <div class="d-flex justify-content-between align-items-center mb-1 flex-wrap gap-2">
        <h4 class="mb-0"><i class="bi bi-gem me-2"></i>دارایی‌ها</h4>
        <div class="d-flex flex-wrap gap-2">
            <button class="btn btn-primary" id="asset-buy-btn"><i class="bi bi-cart-plus me-1"></i>خرید</button>
            <button class="btn btn-success" id="asset-sell-btn"><i class="bi bi-cash-coin me-1"></i>فروش</button>
            <div class="dropdown">
                <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">بیشتر</button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><button class="dropdown-item" type="button" id="asset-opening-btn"><i class="bi bi-box-seam me-2"></i>ثبت چیزهایی که از قبل دارم</button></li>
                    <li data-permission="manage_purchase_invoices"><button class="dropdown-item" type="button" id="asset-convert-btn"><i class="bi bi-arrow-left-right me-2"></i>انتقال از فاکتور خرید</button></li>
                    <li><button class="dropdown-item" type="button" id="asset-new-btn"><i class="bi bi-plus-circle me-2"></i>تعریف دارایی</button></li>
                    <li><button class="dropdown-item" type="button" id="asset-refresh-prices-btn"><i class="bi bi-arrow-repeat me-2"></i>به‌روزرسانی قیمت‌ها</button></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><label class="dropdown-item mb-0"><input class="form-check-input me-2" type="checkbox" id="assets-show-archived">نمایش بایگانی‌شده‌ها</label></li>
                </ul>
            </div>
        </div>
    </div>
    <p class="text-muted small mb-3">ارز، سکه، طلا، وسایل و تجهیزات — هر چیزی جز فرش. این‌ها در انبار و فروش فرش حساب نمی‌شوند.</p>

    <div class="row g-2 mb-3" id="assets-summary"></div>

    <div class="card mb-3">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="assets-table">
                    <thead class="table-light">
                        <tr>
                            <th>دارایی</th>
                            <th class="d-none d-md-table-cell">مقدار</th>
                            <th class="d-none d-md-table-cell">مبلغ خرید</th>
                            <th>ارزش امروز</th>
                            <th class="d-none d-md-table-cell">سود / زیان</th>
                            <th class="text-end"></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span class="fw-bold">خریدها و فروش‌ها</span>
                    <select class="form-select form-select-sm w-auto" id="asset-docs-filter">
                        <option value="">همه</option>
                        <option value="purchase">فقط خریدها</option>
                        <option value="sale">فقط فروش‌ها</option>
                    </select>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0" id="asset-docs-table">
                            <thead class="table-light">
                                <tr>
                                    <th class="d-none d-md-table-cell">تاریخ</th>
                                    <th>شرح</th>
                                    <th>مبلغ</th>
                                    <th class="d-none d-md-table-cell">وضعیت</th>
                                    <th class="text-end"></th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header fw-bold d-flex justify-content-between align-items-center">
                    <span>قیمت امروز</span>
                    <button type="button" class="btn btn-link btn-sm p-0" id="asset-prices-toggle">همه قیمت‌ها</button>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0" id="asset-prices-table"><tbody></tbody></table>
                </div>
                <div class="card-footer small text-muted" id="asset-prices-footer"></div>
            </div>
        </div>
    </div>
</div>

<!-- سند خرید / فروش دارایی — same layout as the invoice forms -->
<div class="modal fade" id="assetDocModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-fullscreen modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="asset-doc-title">سند خرید دارایی</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="asset-doc-form" class="invoice-form" novalidate onsubmit="return false;">
                    <div class="alert alert-danger d-none" id="asset-doc-error"></div>
                    <input type="hidden" id="asset-doc-id">
                    <input type="hidden" id="asset-doc-type">

                    <section class="invoice-section invoice-section-meta">
                        <div class="invoice-section-head">
                            <h6 class="invoice-section-title mb-0"><i class="bi bi-gem me-2"></i>اطلاعات سند</h6>
                        </div>
                        <div class="invoice-section-body">
                            <div class="row g-2 mb-0 invoice-meta-row-main">
                                <div class="col-12 col-md-4 invoice-meta-person" id="asset-doc-person-wrap">
                                    <label for="asset-doc-person" class="form-label mb-0" id="asset-doc-person-label">فروشنده</label>
                                    <select class="form-select form-select-sm" id="asset-doc-person"></select>
                                </div>
                                <div class="col-6 col-md-2 invoice-meta-date">
                                    <label for="asset-doc-date" class="form-label mb-0">تاریخ</label>
                                    <input type="text" class="form-control form-control-sm persian-datepicker" id="asset-doc-date" autocomplete="off">
                                </div>
                                <div class="col-6 col-md-2 invoice-meta-number">
                                    <label for="asset-doc-number" class="form-label mb-0">شماره سند</label>
                                    <input type="text" class="form-control form-control-sm" id="asset-doc-number" placeholder="خودکار">
                                </div>
                                <div class="col-12 col-md-2 invoice-meta-desc">
                                    <label for="asset-doc-description" class="form-label mb-0">توضیحات</label>
                                    <textarea class="form-control form-control-sm" id="asset-doc-description" rows="1"></textarea>
                                </div>
                                <div class="col-12 col-md-auto invoice-meta-tax" id="asset-doc-opening-wrap">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="asset-doc-opening">
                                        <label class="form-check-label" for="asset-doc-opening" title="دارایی‌ای که از قبل داشته‌اید؛ پولی پرداخت نمی‌شود">از قبل داشته‌ام</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="invoice-section invoice-section-items">
                        <div class="invoice-section-head d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <h6 class="invoice-section-title mb-0"><i class="bi bi-list-ul me-2"></i>اقلام سند</h6>
                            <small class="text-muted"><i class="bi bi-info-circle me-1"></i>برای ارز، سکه و طلا قیمت امروز خودکار نوشته می‌شود</small>
                        </div>
                        <div class="invoice-section-body">
                            <div id="asset-doc-items" class="invoice-items-list"></div>
                            <button type="button" class="btn btn-outline-primary btn-sm" id="asset-doc-add-item"><i class="bi bi-plus"></i> افزودن قلم</button>
                        </div>
                    </section>

                    <section class="invoice-section invoice-section-payments" id="asset-doc-pay-section">
                        <div class="invoice-section-head">
                            <h6 class="invoice-section-title mb-0"><i class="bi bi-cash-stack me-2"></i><span id="asset-doc-pay-title">بخش پرداخت</span></h6>
                        </div>
                        <div class="invoice-section-body">
                            <div id="asset-doc-payments" class="invoice-payments-list"></div>
                            <button type="button" class="btn btn-outline-secondary btn-sm mb-2" id="asset-doc-add-payment"><i class="bi bi-plus"></i> افزودن ردیف پرداخت</button>
                        </div>
                    </section>

                    <section class="invoice-section invoice-section-totals" id="asset-doc-totals">
                        <div class="invoice-section-head">
                            <h6 class="invoice-section-title mb-0"><i class="bi bi-calculator me-2"></i>جمع و مانده</h6>
                        </div>
                        <div class="invoice-section-body">
                            <div class="row justify-content-end">
                                <div class="col-lg-5 col-md-7">
                                    <ul class="list-group list-group-flush invoice-totals-list">
                                        <li class="list-group-item d-flex justify-content-between align-items-center py-1">تعداد اقلام
                                            <span class="badge bg-secondary rounded-pill fs-6" id="asset-doc-item-count">۰</span>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between align-items-center py-1 fw-bold">جمع نهایی
                                            <span class="badge bg-info rounded-pill fs-6" id="asset-doc-total">۰</span>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between align-items-center py-1"><span id="asset-doc-paid-label">پرداختی</span>
                                            <span class="badge bg-success rounded-pill fs-6" id="asset-doc-paid">۰</span>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between align-items-center py-1 fw-bold">باقی‌مانده
                                            <span class="badge bg-danger rounded-pill fs-6" id="asset-doc-remaining">۰</span>
                                        </li>
                                    </ul>
                                    <div class="small text-muted mt-1" id="asset-doc-remaining-hint"></div>
                                </div>
                            </div>
                        </div>
                    </section>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
                <button type="button" class="btn btn-success" id="asset-doc-save">ذخیره سند</button>
            </div>
        </div>
    </div>
</div>

<!-- انتقال از فاکتور خرید -->
<div class="modal fade" id="assetConvertModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">انتقال از فاکتور خرید</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="بستن"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted">اگر خرید ارز، سکه یا وسیله‌ای را قبلاً با فاکتور خرید ثبت کرده‌اید، فاکتورش را انتخاب کنید تا به اینجا منتقل شود. پول و چک‌های آن دست نمی‌خورد.</p>
                <input type="text" class="form-control mb-2" id="asset-convert-search" placeholder="جستجو: شماره فاکتور، فروشنده یا نام کالا">
                <div id="asset-convert-list"></div>
            </div>
        </div>
    </div>
</div>
