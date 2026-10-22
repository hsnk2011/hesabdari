<div class="tab-pane fade" id="reports" role="tabpanel">
    <h4>گزارش‌گیری مالی</h4>
    <div class="card mb-4">
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label for="report-type" class="form-label">نوع گزارش</label>
                    <select class="form-select" id="report-type">
                                <option value="profit-loss">سود و زیان</option>
                                <option value="cogs-profit">سود و زیان بر اساس فروش کالا</option>
                                <option value="sales-invoices">فاکتورهای فروش</option>
                                <option value="purchase-invoices">فاکتورهای خرید</option>
                                <option value="delivery-report">گزارش وضعیت تحویل</option>
                                <option value="accounts">گردش حساب بانک/صندوق</option>
                                <option value="inventory">موجودی انبار</option>
                                <option value="inventory-value">ارزش موجودی انبار</option>
                                <option value="inventory-ledger">کاردکس کالا (گردش محصول)</option>
                                <option value="expenses">هزینه‌ها</option>
                                <option value="incomes">درآمدها</option>
                                <option value="persons">صورتحساب اشخاص</option>
                                <option value="aged-receivables">سن بدهی (بدهکاران/بستانکاران)</option>
                                <option value="cash-flow">جریان وجوه نقد</option>
                                <option value="checks">گزارش چک‌ها</option>
                                <option value="partner-profit">سود شرکا</option>
                                <option value="custom-report">گزارش سفارشی</option>
                    </select>
                </div>
                <div class="col-md-5" id="report-person-controls" style="display: none;">
                    <label for="report-person-select" class="form-label">انتخاب شخص</label>
                    <select class="form-select" id="report-person-select" data-placeholder="جستجو و انتخاب شخص..."></select>
                </div>
                <div class="col-md-5" id="report-account-controls" style="display: none;">
                    <label for="report-account-select" class="form-label">انتخاب حساب</label>
                    <select class="form-select" id="report-account-select" data-placeholder="جستجو و انتخاب حساب..."></select>
                </div>
                <div class="col-md-5" id="report-product-controls" style="display: none;">
                    <label for="report-product-select" class="form-label">انتخاب محصول</label>
                    <select class="form-select" id="report-product-select" data-placeholder="جستجو و انتخاب محصول..."></select>
                </div>
                <div class="col-12 col-md-4">
                    <div class="btn-group w-100 report-action-btns" role="group">
                        <button class="btn btn-info" id="generate-report-btn"><i class="bi bi-play-fill me-2"></i>تولید گزارش</button>
                        <button class="btn btn-success" id="export-report-btn" style="display: none;"><i class="bi bi-file-earmark-excel-fill me-2"></i>خروجی CSV</button>
                        <button class="btn btn-secondary" id="print-report-btn" style="display: none;"><i class="bi bi-printer me-2"></i>چاپ</button>
                    </div>
                </div>
            </div>
            
            <div class="row g-3 align-items-end mt-1">
                <div class="col-md-4" id="report-date-filter-start">
                    <label for="report-start-date" class="form-label">از تاریخ</label>
                    <input type="text" class="form-control persian-datepicker" id="report-start-date">
                </div>
                <div class="col-md-4" id="report-date-filter-end">
                    <label for="report-end-date" class="form-label">تا تاریخ</label>
                    <input type="text" class="form-control persian-datepicker" id="report-end-date">
                </div>
                
                <div class="col-12 col-md-4 d-flex align-items-end" id="profit-calc-method-wrapper" style="display: none;">
                    <label class="form-label me-3 mb-md-0">روش محاسبه سود:</label>
                    <div class="btn-group btn-group-sm flex-wrap" role="group">
                        <input type="radio" class="btn-check" name="profit-calc-method" id="calc-standard" value="standard" autocomplete="off">
                        <label class="btn btn-outline-secondary" for="calc-standard">ساده (فروش-خرید)</label>

                        <input type="radio" class="btn-check" name="profit-calc-method" id="calc-inventory" value="inventory" autocomplete="off">
                        <label class="btn btn-outline-secondary" for="calc-inventory">موجودی انبار</label>

                        <input type="radio" class="btn-check" name="profit-calc-method" id="calc-cogs" value="cogs" autocomplete="off" checked>
                        <label class="btn btn-outline-secondary" for="calc-cogs">بهای تمام شده (COGS)</label>
                    </div>
                </div>
                
                <!-- Person statement options -->
                <div class="col-md-12 mt-3" id="person-statement-options" style="display: none;">
                    <div class="card bg-light">
                        <div class="card-body py-2">
                            <div class="row g-3 align-items-center">
                                <div class="col-auto">
                                    <span class="fw-bold text-muted"><i class="bi bi-gear me-1"></i>گزینه‌های صورتحساب:</span>
                                </div>
                                <div class="col-auto">
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="checkbox" id="show-previous-balance" checked>
                                        <label class="form-check-label" for="show-previous-balance">نمایش مانده از قبل</label>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="checkbox" id="include-pending-checks" checked>
                                        <label class="form-check-label" for="include-pending-checks">نمایش چک‌های سررسید نرسیده</label>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="checkbox" id="calculate-pending-checks" checked>
                                        <label class="form-check-label" for="calculate-pending-checks">محاسبه چک‌های سررسید نرسیده در مانده</label>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="checkbox" id="show-invoice-details">
                                        <label class="form-check-label" for="show-invoice-details">نمایش جزئیات محصولات فاکتورها</label>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="checkbox" id="calculate-consignment">
                                        <label class="form-check-label" for="calculate-consignment" title="فاکتورهای امانی همیشه در لیست نمایش داده می‌شوند؛ با روشن کردن این گزینه مبلغ آن‌ها در مانده هم محاسبه می‌شود">محاسبه فاکتورهای امانی در مانده</label>
                                    </div>
                                </div>
                                <div class="col-auto" id="linked-person-combine-option" style="display: none;">
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="checkbox" id="combine-linked-person">
                                        <label class="form-check-label" for="combine-linked-person" title="این شخص هم مشتری و هم تأمین‌کننده است؛ ردیف‌های هر دو حساب در یک صورتحساب با مانده خالص نمایش داده می‌شود">صورتحساب تجمیعی (مشتری + تأمین‌کننده)</label>
                                    </div>
                                </div>
                                <div class="col-auto" id="supplier-undelivered-orders-option" style="display: none;">
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="checkbox" id="include-undelivered-orders">
                                        <label class="form-check-label" for="include-undelivered-orders" title="فاکتورهای خرید ثبت‌شده در انبار سفارش که هنوز تحویل نشده‌اند">محاسبه و نمایش سفارشات تحویل‌نشده (انبار سفارش)</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Checks report options -->
                <div class="col-md-12 mt-3" id="checks-report-options" style="display: none;">
                    <div class="card bg-light">
                        <div class="card-body py-2">
                            <div class="row g-3 align-items-end">
                                <div class="col-auto">
                                    <span class="fw-bold text-muted"><i class="bi bi-bank me-1"></i>فیلتر چک:</span>
                                </div>
                                <div class="col-md-2">
                                    <label for="report-check-type" class="form-label mb-1">نوع</label>
                                    <select class="form-select form-select-sm" id="report-check-type">
                                        <option value="all">همه</option>
                                        <option value="received">دریافتی</option>
                                        <option value="payable">پرداختی</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label for="report-check-status" class="form-label mb-1">وضعیت</label>
                                    <select class="form-select form-select-sm" id="report-check-status">
                                        <option value="all">همه</option>
                                        <option value="in_hand">نزد ما</option>
                                        <option value="payable">پرداختنی</option>
                                        <option value="endorsed">واگذار شده</option>
                                        <option value="cashed">وصول/پاس شده</option>
                                        <option value="bounced">برگشتی</option>
                                    </select>
                                </div>
                                <div class="col-auto">
                                    <div class="form-check form-check-inline mb-1">
                                        <input class="form-check-input" type="checkbox" id="report-checks-only-past-due">
                                        <label class="form-check-label" for="report-checks-only-past-due">فقط سررسید گذشته</label>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <small class="text-muted">بازه تاریخ بر اساس تاریخ سررسید چک اعمال می‌شود.</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Custom report options -->
                <div class="col-12 mt-3" id="custom-report-options" style="display: none;">
                    <div class="card bg-light border-0">
                        <div class="card-body py-3">
                            <div class="row g-3">
                                <div class="col-12 col-md-3">
                                    <label for="custom-report-source" class="form-label fw-semibold mb-1">
                                        <i class="bi bi-sliders me-1"></i>منبع داده
                                    </label>
                                    <select class="form-select form-select-sm" id="custom-report-source">
                                        <option value="sales_invoices">فاکتورهای فروش</option>
                                        <option value="purchase_invoices">فاکتورهای خرید</option>
                                        <option value="expenses">هزینه‌ها</option>
                                        <option value="incomes">درآمدها</option>
                                        <option value="payments">پرداخت‌ها / دریافت‌ها</option>
                                    </select>
                                </div>
                                <div class="col-12 col-md-6">
                                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-1">
                                        <label class="form-label fw-semibold mb-0">ستون‌ها</label>
                                        <div class="btn-group btn-group-sm" role="group">
                                            <button type="button" class="btn btn-outline-secondary" id="custom-report-cols-all">همه</button>
                                            <button type="button" class="btn btn-outline-secondary" id="custom-report-cols-none">هیچ‌کدام</button>
                                        </div>
                                    </div>
                                    <div id="custom-report-columns" class="custom-report-columns-grid border rounded p-2 bg-white"></div>
                                    <div class="form-text">روی موبایل با لمس انتخاب کنید؛ حداقل یک ستون لازم است.</div>
                                </div>
                                <div class="col-12 col-md-3">
                                    <label for="custom-report-group-by" class="form-label fw-semibold mb-1">گروه‌بندی</label>
                                    <select class="form-select form-select-sm" id="custom-report-group-by">
                                        <option value="">بدون گروه‌بندی</option>
                                    </select>
                                    <div class="form-text">با گروه‌بندی، جمع مبالغ هر گروه نمایش داده می‌شود.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
            </div>
        </div>
    </div>
    <div id="report-results-container"></div>
</div>

<script>
    // Show/hide calculation method based on report type
    document.getElementById('report-type').addEventListener('change', function() {
        const methodWrapper = document.getElementById('profit-calc-method-wrapper');
        const personOptions = document.getElementById('person-statement-options');
        const customOptions = document.getElementById('custom-report-options');
        const checksOptions = document.getElementById('checks-report-options');
        
        if (this.value === 'profit-loss') {
            methodWrapper.style.display = 'flex';
        } else {
            methodWrapper.style.display = 'none';
        }
        
        if (this.value === 'persons') {
            personOptions.style.display = 'block';
        } else {
            personOptions.style.display = 'none';
        }

        if (this.value === 'checks') {
            checksOptions.style.display = 'block';
        } else {
            checksOptions.style.display = 'none';
        }
        
        if (this.value === 'custom-report') {
            customOptions.style.display = 'block';
        } else {
            customOptions.style.display = 'none';
        }
    });
    
    // When "include pending checks" is unchecked, auto-uncheck "calculate pending checks"
    document.getElementById('include-pending-checks').addEventListener('change', function() {
        if (!this.checked) {
            document.getElementById('calculate-pending-checks').checked = false;
            document.getElementById('calculate-pending-checks').disabled = true;
        } else {
            document.getElementById('calculate-pending-checks').disabled = false;
        }
    });

    // Undelivered-orders / combined-statement options are toggled in reports.js.

    // Trigger change initially
    document.getElementById('report-type').dispatchEvent(new Event('change'));
</script>
