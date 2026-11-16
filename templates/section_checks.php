<div class="tab-pane fade" id="checks" role="tabpanel">
    <div class="ui-page-head">
        <div>
            <h4 class="ui-page-title">چک‌ها</h4>
            <p class="ui-page-sub">چک‌های دریافتی نزد ما و چک‌های پرداختنی، با سررسید و وضعیت هر کدام</p>
        </div>
        <div class="ui-page-actions">
            <button class="btn btn-outline-secondary" id="print-checks-btn" disabled><i class="bi bi-printer"></i>چاپ انتخاب‌شده</button>
            <button class="btn btn-outline-primary" id="discount-checks-btn" disabled><i class="bi bi-calculator"></i>راس‌گیری و انتقال چک</button>
            <button class="btn btn-primary" id="add-check-btn"><i class="bi bi-plus-lg"></i>چک جدید</button>
        </div>
    </div>

    <div class="ui-kpis ui-kpis-4">
        <div id="received-checks-summary" class="ui-contents">
            <button type="button" class="check-summary-filter ui-kpi ui-kpi-btn" data-check-type="received" data-check-status="in_hand" data-check-due="not_due" title="نمایش چک‌های دریافتی که سررسیدشان نرسیده">
                <div class="ui-kpi-label"><span>دریافتی نزد ما · سررسید نرسیده</span><span class="ui-kpi-icon is-success"><i class="bi bi-wallet2"></i></span></div>
                <div class="ui-kpi-value text-success" id="received-in-hand-amount">-</div>
                <div class="ui-kpi-note"><strong id="received-in-hand-count">-</strong> فقره</div>
            </button>
            <button type="button" class="check-summary-filter ui-kpi ui-kpi-btn" data-check-type="received" data-check-status="in_hand" data-check-due="past_due" title="نمایش چک‌های دریافتی سررسید گذشته">
                <div class="ui-kpi-label"><span>دریافتی · سررسید گذشته، وصول نشده</span><span class="ui-kpi-icon is-danger"><i class="bi bi-exclamation-circle"></i></span></div>
                <div class="ui-kpi-value text-danger" id="received-past-due-amount">-</div>
                <div class="ui-kpi-note"><strong id="received-past-due-count">-</strong> فقره</div>
            </button>
        </div>
        <div id="payable-checks-summary" class="ui-contents">
            <button type="button" class="check-summary-filter ui-kpi ui-kpi-btn" data-check-type="payable" data-check-status="payable" data-check-due="not_due" title="نمایش چک‌های پرداختنی که سررسیدشان نرسیده">
                <div class="ui-kpi-label"><span>پرداختنی ما · سررسید نرسیده</span><span class="ui-kpi-icon is-warning"><i class="bi bi-cash-stack"></i></span></div>
                <div class="ui-kpi-value text-danger" id="payable-unpaid-amount">-</div>
                <div class="ui-kpi-note"><strong id="payable-unpaid-count">-</strong> فقره</div>
            </button>
            <button type="button" class="check-summary-filter ui-kpi ui-kpi-btn" data-check-type="payable" data-check-status="payable" data-check-due="past_due" title="نمایش چک‌های پرداختنی سررسید گذشته">
                <div class="ui-kpi-label"><span>پرداختنی · سررسید گذشته، پرداخت نشده</span><span class="ui-kpi-icon is-danger"><i class="bi bi-exclamation-triangle"></i></span></div>
                <div class="ui-kpi-value text-danger" id="payable-past-due-amount">-</div>
                <div class="ui-kpi-note"><strong id="payable-past-due-count">-</strong> فقره</div>
            </button>
        </div>
    </div>

    <div class="ui-panel" id="checks-maturity-panel">
        <div class="ui-panel-head">
            <h5 class="ui-panel-title"><i class="bi bi-calendar3"></i> سررسید چک‌های باز</h5>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <div class="btn-group ui-segmented" id="checks-maturity-view-toggle" role="group" aria-label="نمای سررسید">
                    <button type="button" class="btn active" data-maturity-view="chart">نمودار</button>
                    <button type="button" class="btn" data-maturity-view="calendar">تقویم</button>
                </div>
                <div class="btn-group" id="checks-maturity-month-nav" hidden>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="checks-maturity-prev-month" title="ماه قبل"><i class="bi bi-chevron-right"></i></button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="checks-maturity-next-month" title="ماه بعد"><i class="bi bi-chevron-left"></i></button>
                </div>
            </div>
        </div>
        <div class="ui-panel-body">
            <div id="checks-maturity-chart-wrap">
                <canvas id="checks-maturity-chart" height="120" aria-label="نمودار سررسید شش ماه آینده"></canvas>
                <p class="small text-muted mb-0 mt-2" id="checks-maturity-chart-hint">کلیک روی هر ماه → تقویم همان ماه و فیلتر جدول</p>
            </div>
            <div id="checks-maturity-calendar-wrap" hidden>
                <div class="checks-maturity-cal-meta d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                    <strong id="checks-maturity-cal-label">—</strong>
                    <span class="small text-muted">سبز: دریافتی · قرمز: پرداختی · کلیک روز = فیلتر جدول</span>
                </div>
                <div id="checks-maturity-calendar" class="checks-maturity-cal" role="grid" aria-label="تقویم سررسید ماه"></div>
            </div>
        </div>
    </div>

    <div class="ui-panel">
        <div class="ui-toolbar ui-toolbar-stack">
            <div class="btn-group ui-segmented ui-segmented-wrap" id="check-filter-presets" role="group" aria-label="تفکیک سریع چک‌ها">
                <button type="button" class="btn check-filter-preset active" data-preset="all">همه</button>
                <button type="button" class="btn check-filter-preset" data-preset="received_in_hand">دریافتی نزد ما</button>
                <button type="button" class="btn check-filter-preset" data-preset="payable_open">پرداختنی باز</button>
                <button type="button" class="btn check-filter-preset" data-preset="past_due">سررسید گذشته</button>
                <button type="button" class="btn check-filter-preset" data-preset="next_7">۷ روز آینده</button>
                <button type="button" class="btn check-filter-preset" data-preset="endorsed">واگذار شده</button>
                <button type="button" class="btn check-filter-preset" data-preset="cashed">وصول / پاس شده</button>
            </div>
            <div class="ui-filter-grid">
                <div class="ui-field ui-field-grow">
                    <label class="form-label" for="check-search">جستجو</label>
                    <div class="ui-search search-box">
                        <i class="bi bi-search"></i>
                        <input type="text" class="form-control" id="check-search" placeholder="شماره، صادرکننده، مبلغ...">
                    </div>
                </div>
                <div class="ui-field">
                    <label class="form-label" for="check-filter-type">نوع</label>
                    <select class="form-select" id="check-filter-type">
                        <option value="">همه انواع</option>
                        <option value="received">دریافتی</option>
                        <option value="payable">پرداختی</option>
                    </select>
                </div>
                <div class="ui-field">
                    <label class="form-label" for="check-filter-status">وضعیت</label>
                    <select class="form-select" id="check-filter-status">
                        <option value="">همه وضعیت‌ها</option>
                        <option value="open">باز (نزد ما / پرداختنی)</option>
                        <option value="in_hand">نزد ما</option>
                        <option value="payable">پرداختنی</option>
                        <option value="cashed">وصول / پاس شده</option>
                        <option value="endorsed">واگذار شده</option>
                    </select>
                </div>
                <div class="ui-field">
                    <label class="form-label" for="check-filter-due">سررسید</label>
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
                <div class="ui-field">
                    <label class="form-label" for="check-filter-related">ارتباط</label>
                    <select class="form-select" id="check-filter-related">
                        <option value="">همه</option>
                        <option value="invoice">متصل به فاکتور</option>
                        <option value="standalone">مستقل</option>
                    </select>
                </div>
                <div class="ui-field">
                    <label class="form-label" for="check-filter-due-from">سررسید از</label>
                    <input type="text" class="form-control persian-datepicker" id="check-filter-due-from" autocomplete="off" placeholder="تاریخ">
                </div>
                <div class="ui-field">
                    <label class="form-label" for="check-filter-due-to">سررسید تا</label>
                    <input type="text" class="form-control persian-datepicker" id="check-filter-due-to" autocomplete="off" placeholder="تاریخ">
                </div>
                <div class="ui-field ui-field-narrow">
                    <label class="form-label" for="check-limit">تعداد</label>
                    <select class="form-select" id="check-limit">
                        <option value="10">10</option>
                        <option value="15" selected>15</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                        <option value="1000000">همه</option>
                    </select>
                </div>
                <div class="ui-field ui-field-btn">
                    <button type="button" class="btn btn-outline-secondary text-nowrap" id="check-filter-reset"><i class="bi bi-x-circle"></i> پاک کردن</button>
                </div>
            </div>
        </div>
        <div id="checks-filter-info" class="ui-filter-info"></div>
        <div class="table-responsive">
            <table class="table table-hover" id="checks-table">
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
                <button type="button" class="btn btn-outline-warning d-none" id="edit-transfer-payment-btn"><i class="bi bi-pencil-square me-1"></i>ویرایش این انتقال</button>
                <button type="button" class="btn btn-outline-warning d-none" id="edit-transfer-group-btn"><i class="bi bi-collection me-1"></i>ویرایش گروه انتقال</button>
                <button type="button" class="btn btn-outline-secondary" id="print-history-check-btn"><i class="bi bi-printer me-1"></i>چاپ این چک</button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">بستن</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Transfer Group Modal (ویرایش راس‌گیری / انتقال گروهی) -->
<div class="modal fade" id="editTransferGroupModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>ویرایش گروه انتقال چک</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger form-error" style="display:none;"></div>
                <div class="small text-muted mb-3" id="edit-transfer-group-info"></div>
                <div class="mb-3">
                    <label for="edit-transfer-group-description" class="form-label">توضیحات انتقال</label>
                    <textarea id="edit-transfer-group-description" class="form-control" rows="2" placeholder="خالی = توضیح خودکار برای هر چک"></textarea>
                </div>
                <h6 class="mb-2">چک‌های این گروه</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-3">
                        <thead class="table-light"><tr><th>شماره</th><th>بانک</th><th>سررسید</th><th>مبلغ</th><th>وضعیت</th><th style="width:70px">حذف</th></tr></thead>
                        <tbody id="edit-transfer-group-body"></tbody>
                        <tfoot class="table-secondary"><tr><td colspan="3" class="fw-bold text-start">جمع پس از ذخیره:</td><td class="fw-bold" id="edit-transfer-group-total">۰</td><td colspan="2"></td></tr></tfoot>
                    </table>
                </div>
                <label for="edit-transfer-group-add" class="form-label">افزودن چک «نزد ما» به این انتقال</label>
                <select id="edit-transfer-group-add" class="form-select" multiple></select>
                <div class="small text-muted mt-2">چک‌های خارج‌شده به «نزد ما» برمی‌گردند و چک‌های افزوده با همان تاریخ و شخص مقصد منتقل می‌شوند. هزینه تنزیلی که هنگام راس‌گیری ثبت شده خودکار تغییر نمی‌کند؛ در صورت نیاز آن را از بخش هزینه‌ها اصلاح کنید.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
                <button type="button" class="btn btn-primary" id="save-transfer-group-btn"><i class="bi bi-check-lg me-1"></i>ذخیره تغییرات</button>
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
