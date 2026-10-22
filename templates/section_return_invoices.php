<!-- /templates/section_return_invoices.php -->
<div class="tab-pane fade" id="return-invoices" role="tabpanel">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4>فاکتورهای مرجوعی</h4>
        <div class="d-flex align-items-center gap-3">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="return-invoices-show-details" data-table="return_invoices">
                <label class="form-check-label" for="return-invoices-show-details">نمایش جزییات فاکتور</label>
            </div>
            <div class="btn-group">
                <button class="btn btn-primary" id="new-sales-return-btn"><i class="bi bi-plus-circle me-2"></i>ثبت مرجوعی فروش جدید</button>
                <button class="btn btn-success" id="new-purchase-return-btn"><i class="bi bi-plus-circle me-2"></i>ثبت مرجوعی خرید جدید</button>
            </div>
        </div>
    </div>
    <div class="row align-items-center mb-3">
        <div class="col-md-7">
            <div class="input-group search-box">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" class="form-control table-search" data-table="return_invoices" placeholder="جستجو در شماره فاکتور، نام طرف حساب، شناسه انبار، مبالغ...">
            </div>
        </div>
        <div class="col-md-2">
            <select class="form-select" id="return-invoice-type-filter">
                <option value="">همه انواع</option>
                <option value="sales">مرجوعی فروش</option>
                <option value="purchase">مرجوعی خرید</option>
            </select>
        </div>
        <div class="col-md-3">
            <div class="input-group">
                <label class="input-group-text">نمایش</label>
                <select class="form-select pagination-limit-select" data-table="return_invoices">
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
        <table class="table table-striped table-hover">
            <thead>
                <tr>
                    <th class="sortable-header" data-table="return_invoices" data-sort-by="id"># <span class="sort-indicator"></span></th>
                    <th class="sortable-header" data-table="return_invoices" data-sort-by="type">نوع <span class="sort-indicator"></span></th>
                    <th class="sortable-header" data-table="return_invoices" data-sort-by="date">تاریخ <span class="sort-indicator"></span></th>
                    <th class="sortable-header" data-table="return_invoices" data-sort-by="personName">طرف حساب <span class="sort-indicator"></span></th>
                    <th class="sortable-header" data-table="return_invoices" data-sort-by="totalAmount">مبلغ کل <span class="sort-indicator"></span></th>
                    <th class="sortable-header" data-table="return_invoices" data-sort-by="paidAmount">مبلغ پرداختی <span class="sort-indicator"></span></th>
                    <th class="sortable-header" data-table="return_invoices" data-sort-by="remainingAmount">مبلغ باقی‌مانده <span class="sort-indicator"></span></th>
                    <th>وضعیت</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody id="return-invoices-table-body"></tbody>
        </table>
    </div>
    <div class="pagination-container" data-table="return_invoices"></div>
</div>

<!-- Sales Return Invoice Modal -->
<div class="modal fade" id="sales-return-invoice-modal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-fullscreen modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">ثبت فاکتور مرجوعی فروش</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form novalidate>
                    <input type="hidden" id="sales-return-invoice-id">
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="sales-return-invoice-person" class="form-label">مشتری <span class="text-danger">*</span></label>
                            <select class="form-select" id="sales-return-invoice-person" required></select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="sales-return-invoice-date" class="form-label">تاریخ فاکتور <span class="text-danger">*</span></label>
                            <input type="text" class="form-control persian-datepicker" id="sales-return-invoice-date" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="sales-return-invoice-description" class="form-label">توضیحات</label>
                            <textarea class="form-control" id="sales-return-invoice-description" rows="1"></textarea>
                        </div>
                    </div>
                    <hr>
                    <h6><i class="bi bi-arrow-return-right me-2"></i>کالاهای مرجوعی</h6>
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label">انتخاب فاکتور اصلی <small class="text-muted">(اختیاری - برای فیلتر کردن آیتم‌ها)</small></label>
                            <select class="form-select" id="sales-return-original-invoice-select" disabled>
                                <option value="">همه فاکتورها</option>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-md-2">
                            <label class="form-label">محصول</label>
                            <select class="form-select" id="sales-return-product-select"></select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">ابعاد</label>
                            <select class="form-select" id="sales-return-dimensions-select" disabled>
                                <option value="">ابتدا محصول را انتخاب کنید</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">شناسه‌های فروخته شده <small class="text-muted">(چند انتخابی)</small></label>
                            <select class="form-select" id="sales-return-identifiers-select" multiple size="3" disabled>
                                <option value="">ابتدا محصول و ابعاد را انتخاب کنید</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">قیمت واحد</label>
                            <input type="text" class="form-control numeric-input" id="sales-return-unit-price-input">
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <button type="button" class="btn btn-primary w-100" id="add-sales-return-item-btn"><i class="bi bi-plus"></i> افزودن</button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>محصول</th>
                                    <th>ابعاد</th>
                                    <th>شناسه‌ها</th>
                                    <th>تعداد</th>
                                    <th>قیمت واحد</th>
                                    <th>جمع</th>
                                    <th>حذف</th>
                                </tr>
                            </thead>
                            <tbody id="sales-return-invoice-items-container"></tbody>
                        </table>
                    </div>
                    <hr>
                    <h6><i class="bi bi-cash-stack me-2"></i>بخش پرداخت</h6>
                    <div class="row mb-2">
                        <div class="col-md-2">
                            <label class="form-label">نوع پرداخت</label>
                            <select class="form-select" id="sales-return-payment-type">
                                <option value="cash">نقدی</option>
                                <option value="check">چک جدید</option>
                                <option value="endorse_check">خرج چک دریافتی</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">مبلغ</label>
                            <input type="text" class="form-control numeric-input" id="sales-return-payment-amount">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">تاریخ</label>
                            <input type="text" class="form-control persian-datepicker" id="sales-return-payment-date">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">حساب</label>
                            <select class="form-select" id="sales-return-payment-account"></select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">توضیحات</label>
                            <input type="text" class="form-control" id="sales-return-payment-description">
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="button" class="btn btn-secondary w-100" id="add-sales-return-payment-btn"><i class="bi bi-plus"></i> افزودن</button>
                        </div>
                    </div>
                    <div id="sales-return-payment-details-container"></div>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>نوع</th>
                                    <th>مبلغ</th>
                                    <th>تاریخ</th>
                                    <th>حساب</th>
                                    <th>توضیحات</th>
                                    <th>حذف</th>
                                </tr>
                            </thead>
                            <tbody id="sales-return-invoice-payments-container"></tbody>
                        </table>
                    </div>
                    <hr>
                    <div class="row justify-content-end">
                        <div class="col-md-5">
                            <ul class="list-group">
                                <li class="list-group-item d-flex justify-content-between">
                                    <span>جمع تعداد</span>
                                    <span class="badge bg-secondary fs-6" id="sales-return-invoice-total-quantity">0</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between">
                                    <span>جمع متراژ</span>
                                    <span class="badge bg-secondary fs-6"><span id="sales-return-invoice-total-area">0</span> متر مربع</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between">
                                    <span>جمع کل</span>
                                    <span class="badge bg-primary fs-6" id="sales-return-invoice-total-amount">0 تومان</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span>تخفیف <i class="bi bi-info-circle text-muted" data-bs-toggle="tooltip" title="تخفیف به‌صورت خودکار بر اساس فاکتور اصلی محاسبه می‌شود"></i></span>
                                    <input type="text" class="form-control form-control-sm numeric-input text-end" id="sales-return-invoice-discount" placeholder="0" style="max-width: 150px;">
                                </li>
                                <li class="list-group-item d-flex justify-content-between">
                                    <span>پرداخت شده</span>
                                    <span class="badge bg-success fs-6" id="sales-return-invoice-paid-amount">0 تومان</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between">
                                    <span>باقی‌مانده</span>
                                    <span class="badge bg-warning fs-6" id="sales-return-invoice-remaining-amount">0 تومان</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
                <button type="button" class="btn btn-primary" id="save-sales-return-invoice-btn">ذخیره</button>
            </div>
        </div>
    </div>
</div>

<!-- Purchase Return Invoice Modal -->
<div class="modal fade" id="purchase-return-invoice-modal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-fullscreen modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">ثبت فاکتور مرجوعی خرید</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form novalidate>
                    <input type="hidden" id="purchase-return-invoice-id">
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="purchase-return-invoice-person" class="form-label">تامین‌کننده <span class="text-danger">*</span></label>
                            <select class="form-select" id="purchase-return-invoice-person" required></select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="purchase-return-invoice-date" class="form-label">تاریخ فاکتور <span class="text-danger">*</span></label>
                            <input type="text" class="form-control persian-datepicker" id="purchase-return-invoice-date" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="purchase-return-invoice-description" class="form-label">توضیحات</label>
                            <textarea class="form-control" id="purchase-return-invoice-description" rows="1"></textarea>
                        </div>
                    </div>
                    <hr>
                    <h6><i class="bi bi-arrow-return-left me-2"></i>کالاهای مرجوعی</h6>
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label">انتخاب فاکتور اصلی <small class="text-muted">(اختیاری - برای فیلتر کردن آیتم‌ها)</small></label>
                            <select class="form-select" id="purchase-return-original-invoice-select" disabled>
                                <option value="">همه فاکتورها</option>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-md-2">
                            <label class="form-label">محصول</label>
                            <select class="form-select" id="purchase-return-product-select"></select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">ابعاد</label>
                            <select class="form-select" id="purchase-return-dimensions-select" disabled>
                                <option value="">ابتدا محصول را انتخاب کنید</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">شناسه‌های موجود <small class="text-muted">(چند انتخابی)</small></label>
                            <select class="form-select" id="purchase-return-identifiers-select" multiple size="3" disabled>
                                <option value="">ابتدا محصول و ابعاد را انتخاب کنید</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">قیمت واحد</label>
                            <input type="text" class="form-control numeric-input" id="purchase-return-unit-price-input">
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <button type="button" class="btn btn-primary w-100" id="add-purchase-return-item-btn"><i class="bi bi-plus"></i> افزودن</button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>محصول</th>
                                    <th>ابعاد</th>
                                    <th>شناسه‌ها</th>
                                    <th>تعداد</th>
                                    <th>قیمت واحد</th>
                                    <th>جمع</th>
                                    <th>حذف</th>
                                </tr>
                            </thead>
                            <tbody id="purchase-return-invoice-items-container"></tbody>
                        </table>
                    </div>
                    <hr>
                    <h6><i class="bi bi-cash-stack me-2"></i>بخش دریافت</h6>
                    <div class="row mb-2">
                        <div class="col-md-2">
                            <label class="form-label">نوع دریافت</label>
                            <select class="form-select" id="purchase-return-payment-type">
                                <option value="cash">نقدی</option>
                                <option value="check">چک جدید</option>
                                <option value="endorse_check">خرج چک پرداختی</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">مبلغ</label>
                            <input type="text" class="form-control numeric-input" id="purchase-return-payment-amount">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">تاریخ</label>
                            <input type="text" class="form-control persian-datepicker" id="purchase-return-payment-date">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">حساب</label>
                            <select class="form-select" id="purchase-return-payment-account"></select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">توضیحات</label>
                            <input type="text" class="form-control" id="purchase-return-payment-description">
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="button" class="btn btn-secondary w-100" id="add-purchase-return-payment-btn"><i class="bi bi-plus"></i> افزودن</button>
                        </div>
                    </div>
                    <div id="purchase-return-payment-details-container"></div>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>نوع</th>
                                    <th>مبلغ</th>
                                    <th>تاریخ</th>
                                    <th>حساب</th>
                                    <th>توضیحات</th>
                                    <th>حذف</th>
                                </tr>
                            </thead>
                            <tbody id="purchase-return-invoice-payments-container"></tbody>
                        </table>
                    </div>
                    <hr>
                    <div class="row justify-content-end">
                        <div class="col-md-5">
                            <ul class="list-group">
                                <li class="list-group-item d-flex justify-content-between">
                                    <span>جمع تعداد</span>
                                    <span class="badge bg-secondary fs-6" id="purchase-return-invoice-total-quantity">0</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between">
                                    <span>جمع متراژ</span>
                                    <span class="badge bg-secondary fs-6"><span id="purchase-return-invoice-total-area">0</span> متر مربع</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between">
                                    <span>جمع کل</span>
                                    <span class="badge bg-primary fs-6" id="purchase-return-invoice-total-amount">0 تومان</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span>تخفیف <i class="bi bi-info-circle text-muted" data-bs-toggle="tooltip" title="تخفیف به‌صورت خودکار بر اساس فاکتور اصلی محاسبه می‌شود"></i></span>
                                    <input type="text" class="form-control form-control-sm numeric-input text-end" id="purchase-return-invoice-discount" placeholder="0" style="max-width: 150px;">
                                </li>
                                <li class="list-group-item d-flex justify-content-between">
                                    <span>دریافت شده</span>
                                    <span class="badge bg-success fs-6" id="purchase-return-invoice-paid-amount">0 تومان</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between">
                                    <span>باقی‌مانده</span>
                                    <span class="badge bg-warning fs-6" id="purchase-return-invoice-remaining-amount">0 تومان</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
                <button type="button" class="btn btn-primary" id="save-purchase-return-invoice-btn">ذخیره</button>
            </div>
        </div>
    </div>
</div>
