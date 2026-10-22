<div class="tab-pane fade" id="checks" role="tabpanel">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4>مدیریت چک‌ها</h4>
        <div>
            <button class="btn btn-outline-secondary me-2" id="print-checks-btn" disabled><i class="bi bi-printer me-1"></i>چاپ انتخاب‌شده</button>
            <button class="btn btn-info me-2" id="discount-checks-btn" disabled><i class="bi bi-calculator me-2"></i>راس‌گیری و انتقال چک</button>
            <button class="btn btn-primary" id="add-check-btn"><i class="bi bi-plus-circle me-2"></i>ثبت چک جدید</button>
        </div>
    </div>

    <!-- Check Summaries -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card border-success h-100">
                <div class="card-body">
                    <h6 class="card-title text-success"><i class="bi bi-wallet2 me-2"></i>چک‌های نزد ما (دریافتی)</h6>
                    <div id="received-checks-summary" class="mt-3">
                        <button type="button" class="check-summary-filter btn btn-link text-decoration-none text-body w-100 p-0 text-start" data-check-type="received" data-check-status="in_hand" data-check-due="not_due" title="نمایش چک‌های دریافتی که سررسیدشان نرسیده">
                            <div class="d-flex justify-content-between mb-2">
                                <span>در دست (سررسید نرسیده):</span>
                                <strong id="received-in-hand-count">-</strong>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>مبلغ کل:</span>
                                <strong id="received-in-hand-amount" class="text-success">-</strong>
                            </div>
                        </button>
                        <hr>
                        <button type="button" class="check-summary-filter btn btn-link text-decoration-none text-body w-100 p-0 text-start" data-check-type="received" data-check-status="in_hand" data-check-due="past_due" title="نمایش چک‌های دریافتی سررسید گذشته">
                            <div class="d-flex justify-content-between mb-2">
                                <span>سررسید گذشته (هنوز وصول نشده):</span>
                                <strong id="received-past-due-count" class="text-danger">-</strong>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>مبلغ کل:</span>
                                <strong id="received-past-due-amount" class="text-danger">-</strong>
                            </div>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-danger h-100">
                <div class="card-body">
                    <h6 class="card-title text-danger"><i class="bi bi-cash-stack me-2"></i>چک‌های پرداختنی ما</h6>
                    <div id="payable-checks-summary" class="mt-3">
                        <button type="button" class="check-summary-filter btn btn-link text-decoration-none text-body w-100 p-0 text-start" data-check-type="payable" data-check-status="payable" data-check-due="not_due" title="نمایش چک‌های پرداختنی که سررسیدشان نرسیده">
                            <div class="d-flex justify-content-between mb-2">
                                <span>پرداخت نشده (سررسید نرسیده):</span>
                                <strong id="payable-unpaid-count">-</strong>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>مبلغ کل:</span>
                                <strong id="payable-unpaid-amount" class="text-danger">-</strong>
                            </div>
                        </button>
                        <hr>
                        <button type="button" class="check-summary-filter btn btn-link text-decoration-none text-body w-100 p-0 text-start" data-check-type="payable" data-check-status="payable" data-check-due="past_due" title="نمایش چک‌های پرداختنی سررسید گذشته">
                            <div class="d-flex justify-content-between mb-2">
                                <span>سررسید گذشته (هنوز پرداخت نشده):</span>
                                <strong id="payable-past-due-count" class="text-danger">-</strong>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>مبلغ کل:</span>
                                <strong id="payable-past-due-amount" class="text-danger">-</strong>
                            </div>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="checks-filter-info" class="mb-2"></div>
    <div class="card border-0 bg-light mb-3">
        <div class="card-body py-3">
            <div class="d-flex flex-wrap gap-2 mb-3" id="check-filter-presets" role="group" aria-label="تفکیک سریع چک‌ها">
                <button type="button" class="btn btn-sm btn-outline-secondary check-filter-preset active" data-preset="all">همه</button>
                <button type="button" class="btn btn-sm btn-outline-success check-filter-preset" data-preset="received_in_hand">دریافتی نزد ما</button>
                <button type="button" class="btn btn-sm btn-outline-danger check-filter-preset" data-preset="payable_open">پرداختنی باز</button>
                <button type="button" class="btn btn-sm btn-outline-warning check-filter-preset" data-preset="past_due">سررسید گذشته</button>
                <button type="button" class="btn btn-sm btn-outline-primary check-filter-preset" data-preset="next_7">۷ روز آینده</button>
                <button type="button" class="btn btn-sm btn-outline-dark check-filter-preset" data-preset="endorsed">واگذار شده</button>
                <button type="button" class="btn btn-sm btn-outline-secondary check-filter-preset" data-preset="cashed">وصول / پاس شده</button>
            </div>
            <div class="d-flex flex-nowrap align-items-end gap-2 overflow-auto pb-1">
                <div class="flex-grow-1" style="min-width: 180px;">
                    <label class="form-label small mb-1" for="check-search">جستجو</label>
                    <div class="input-group search-box">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" class="form-control" id="check-search" placeholder="شماره، صادرکننده، مبلغ...">
                    </div>
                </div>
                <div style="min-width: 120px; flex: 1 1 120px;">
                    <label class="form-label small mb-1" for="check-filter-type">نوع</label>
                    <select class="form-select" id="check-filter-type">
                        <option value="">همه انواع</option>
                        <option value="received">دریافتی</option>
                        <option value="payable">پرداختی</option>
                    </select>
                </div>
                <div style="min-width: 150px; flex: 1 1 150px;">
                    <label class="form-label small mb-1" for="check-filter-status">وضعیت</label>
                    <select class="form-select" id="check-filter-status">
                        <option value="">همه وضعیت‌ها</option>
                        <option value="open">باز (نزد ما / پرداختنی)</option>
                        <option value="in_hand">نزد ما</option>
                        <option value="payable">پرداختنی</option>
                        <option value="cashed">وصول / پاس شده</option>
                        <option value="endorsed">واگذار شده</option>
                    </select>
                </div>
                <div style="min-width: 130px; flex: 1 1 130px;">
                    <label class="form-label small mb-1" for="check-filter-due">سررسید</label>
                    <select class="form-select" id="check-filter-due">
                        <option value="">همه سررسیدها</option>
                        <option value="past_due">سررسید گذشته</option>
                        <option value="today">امروز</option>
                        <option value="next_7">۷ روز آینده</option>
                        <option value="next_30">۳۰ روز آینده</option>
                        <option value="not_due">هنوز نرسیده</option>
                        <option value="this_month">این ماه شمسی</option>
                    </select>
                </div>
                <div style="min-width: 140px; flex: 1 1 140px;">
                    <label class="form-label small mb-1" for="check-filter-related">ارتباط</label>
                    <select class="form-select" id="check-filter-related">
                        <option value="">همه</option>
                        <option value="invoice">متصل به فاکتور</option>
                        <option value="standalone">مستقل</option>
                    </select>
                </div>
                <div style="min-width: 110px; flex: 1 1 110px;">
                    <label class="form-label small mb-1" for="check-filter-due-from">سررسید از</label>
                    <input type="text" class="form-control persian-datepicker" id="check-filter-due-from" autocomplete="off" placeholder="تاریخ">
                </div>
                <div style="min-width: 110px; flex: 1 1 110px;">
                    <label class="form-label small mb-1" for="check-filter-due-to">سررسید تا</label>
                    <input type="text" class="form-control persian-datepicker" id="check-filter-due-to" autocomplete="off" placeholder="تاریخ">
                </div>
                <div style="min-width: 88px; width: 88px; flex: 0 0 88px;">
                    <label class="form-label small mb-1" for="check-limit">تعداد</label>
                    <select class="form-select" id="check-limit">
                        <option value="10">10</option>
                        <option value="15" selected>15</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                        <option value="1000000">همه</option>
                    </select>
                </div>
                <div style="flex: 0 0 auto;">
                    <label class="form-label small mb-1 d-block">&nbsp;</label>
                    <button type="button" class="btn btn-outline-secondary text-nowrap" id="check-filter-reset"><i class="bi bi-x-circle me-1"></i>پاک کردن</button>
                </div>
            </div>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-striped table-hover" id="checks-table">
            <thead>
                <tr>
                    <th style="width: 40px;"><input type="checkbox" id="select-all-checks" class="form-check-input" title="انتخاب همه"></th>
                    <th class="sortable-header" data-sort-by="type">نوع چک <span class="sort-indicator"></span></th>
                    <th>شماره چک</th>
                    <th class="sortable-header" data-sort-by="bankName">بانک <span class="sort-indicator"></span></th>
                    <th class="sortable-header" data-sort-by="issuerName">صادرکننده <span class="sort-indicator"></span></th>
                    <th class="sortable-header" data-sort-by="amount">مبلغ (<span data-currency-label>تومان</span>) <span class="sort-indicator"></span></th>
                    <th class="sortable-header" data-sort-by="dueDate">تاریخ سررسید <span class="sort-indicator"></span></th>
                    <th class="sortable-header" data-sort-by="status">وضعیت <span class="sort-indicator"></span></th>
                    <th>مربوط به</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody id="checks-table-body"></tbody>
        </table>
    </div>
    <div class="pagination-container" data-table="checks"></div>
</div>

<div class="modal fade" id="checkModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="check-modal-title">ثبت تراکنش چک</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="check-form">
                    <div class="alert alert-danger form-error" style="display:none;"></div>
                    <input type="hidden" id="check-id">
                    <div id="person-selection-wrapper">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="check-transaction-type" class="form-label">نوع تراکنش چک</label>
                                <select id="check-transaction-type" class="form-select" required>
                                    <option value="receipt">دریافت چک (از شخص)</option>
                                    <option value="payment">پرداخت چک (به شخص)</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="check-person-type" class="form-label">نوع شخص</label>
                                <select id="check-person-type" class="form-select" required>
                                    <option value="customer">مشتری</option>
                                    <option value="supplier">تامین کننده</option>
                                    <option value="partner">شریک</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="check-person-select" class="form-label">انتخاب شخص</label>
                            <select id="check-person-select" class="form-select" required></select>
                        </div>
                        <hr>
                    </div>
                    <h5>مشخصات چک</h5>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="check-number" class="form-label">شماره چک</label>
                            <input type="text" class="form-control" id="check-number" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="check-bank-name" class="form-label">نام بانک</label>
                            <input type="text" class="form-control" id="check-bank-name" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="check-amount" class="form-label">مبلغ</label>
                            <input type="text" class="form-control numeric-input" id="check-amount" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="check-issuer-name" class="form-label">صادرکننده چک</label>
                            <input type="text" class="form-control" id="check-issuer-name" placeholder="نام روی چک">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="check-due-date" class="form-label">تاریخ سررسید</label>
                            <input type="text" class="form-control persian-datepicker" id="check-due-date" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="check-transaction-date" class="form-label">تاریخ ثبت تراکنش</label>
                            <input type="text" class="form-control persian-datepicker" id="check-transaction-date" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="check-description" class="form-label">توضیحات (اختیاری)</label>
                        <textarea class="form-control" id="check-description" rows="2"></textarea>
                    </div>
                    <button type="submit" class="btn btn-success w-100">ثبت و ایجاد تراکنش</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Check History / Lifecycle Modal -->
<div class="modal fade" id="checkHistoryModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-clock-history me-2"></i>گزارش کامل چک</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="check-history-body">
                <div class="text-center text-muted py-4">در حال بارگذاری...</div>
            </div>
            <div class="modal-footer flex-wrap gap-2">
                <button type="button" class="btn btn-outline-primary d-none" id="print-transfer-group-btn"><i class="bi bi-printer me-1"></i>چاپ گروه انتقال</button>
                <button type="button" class="btn btn-outline-warning d-none" id="edit-transfer-payment-btn"><i class="bi bi-pencil-square me-1"></i>ویرایش انتقال</button>
                <button type="button" class="btn btn-outline-secondary" id="print-history-check-btn"><i class="bi bi-printer me-1"></i>چاپ این چک</button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">بستن</button>
            </div>
        </div>
    </div>
</div>

<!-- Check Discount Modal (راس‌گیری) -->
<div class="modal fade" id="checkDiscountModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-calculator me-2"></i>راس‌گیری و انتقال چک‌ها</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger form-error" style="display:none;"></div>
                
                <!-- Discount Parameters -->
                <div class="card mb-4">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="bi bi-gear me-2"></i>تنظیمات راس‌گیری</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label for="discount-rate" class="form-label">نرخ سود ماهانه (%)</label>
                                <input type="number" class="form-control" id="discount-rate" value="0" min="0" max="100" step="0.1">
                                <small class="form-text text-muted">صفر = بدون محاسبه سود</small>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label for="discount-base-date" class="form-label">تاریخ مبنا (امروز)</label>
                                <input type="text" class="form-control persian-datepicker" id="discount-base-date">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">تاریخ راس نهایی</label>
                                <input type="text" class="form-control bg-light" id="calculated-ras-date" readonly>
                                <small class="form-text text-muted" id="ras-days-info"></small>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">&nbsp;</label>
                                <button type="button" class="btn btn-primary w-100" id="calculate-discount-btn">
                                    <i class="bi bi-calculator me-2"></i>محاسبه
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Selected Checks Table -->
                <div class="card mb-4">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="bi bi-list-check me-2"></i>چک‌های انتخاب شده</h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm mb-0" id="discount-checks-table">
                                <thead class="table-light">
                                    <tr>
                                        <th>شماره چک</th>
                                        <th>بانک</th>
                                        <th>تاریخ سررسید</th>
                                        <th>روز تا سررسید</th>
                                        <th>مبلغ اصلی</th>
                                        <th>مبلغ تنزیل</th>
                                        <th>مبلغ راس‌گیری شده</th>
                                        <th style="width:70px">حذف</th>
                                    </tr>
                                </thead>
                                <tbody id="discount-checks-table-body"></tbody>
                                <tfoot class="table-secondary">
                                    <tr>
                                        <td colspan="4" class="fw-bold text-start">جمع کل:</td>
                                        <td class="fw-bold" id="total-original-amount">۰</td>
                                        <td class="fw-bold text-danger" id="total-discount-amount">۰</td>
                                        <td class="fw-bold text-success" id="total-discounted-amount">۰</td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Transfer Details -->
                <div class="card">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="bi bi-arrow-left-right me-2"></i>انتقال به شخص</h6>
                    </div>
                    <div class="card-body">
                        <form id="discount-transfer-form">
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="discount-person-type" class="form-label">نوع شخص</label>
                                    <select id="discount-person-type" class="form-select" required>
                                        <option value="customer">مشتری</option>
                                        <option value="supplier">تامین کننده</option>
                                        <option value="partner">شریک</option>
                                        <option value="contact">سایر اشخاص</option>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3 position-relative">
                                    <label for="discount-person-select" class="form-label">انتخاب شخص</label>
                                    <select id="discount-person-select" class="form-select" required></select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="discount-transaction-date" class="form-label">تاریخ تراکنش</label>
                                    <input type="text" class="form-control persian-datepicker" id="discount-transaction-date" required>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="discount-description" class="form-label">توضیحات (اختیاری)</label>
                                <textarea class="form-control" id="discount-description" rows="2" placeholder="توضیحات راس‌گیری و انتقال چک‌ها..."></textarea>
                            </div>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>
                                <strong>توجه:</strong> پس از تایید، تمام چک‌های انتخاب شده به شخص مورد نظر منتقل می‌شوند. 
                                مبلغ تنزیل (راس‌گیری) به عنوان هزینه مالی ثبت می‌شود.
                            </div>
                            <button type="submit" class="btn btn-success w-100">
                                <i class="bi bi-check-circle me-2"></i>تایید و ثبت انتقال چک‌ها
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
