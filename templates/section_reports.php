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
                                <option value="accounts">گردش حساب بانک/صندوق</option>
                                <option value="inventory">موجودی انبار</option>
                                <option value="inventory-value">ارزش موجودی انبار</option>
                                <option value="inventory-ledger">کاردکس کالا (گردش محصول)</option>
                                <option value="expenses">هزینه‌ها</option>
                                <option value="persons">صورتحساب اشخاص</option>
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
                <div class="col-md-4">
                    <div class="btn-group w-100">
                        <button class="btn btn-info" id="generate-report-btn"><i class="bi bi-play-fill me-2"></i>تولید گزارش</button>
                        <button class="btn btn-success" id="export-report-btn" style="display: none;"><i class="bi bi-file-earmark-excel-fill me-2"></i>خروجی CSV</button>
                        <button class="btn btn-secondary" id="print-report-btn" style="display: none;"><i class="bi bi-printer me-2"></i>چاپ</button>
                    </div>
                </div>
            </div>
            <div class="row g-3 align-items-end mt-1">
                <div class="col-md-3" id="report-entity-controls">
                    <label for="report-entity-select" class="form-label">برای مجموعه تجاری</label>
                    <select class="form-select" id="report-entity-select">
                        </select>
                </div>
                 <div class="col-md-3" id="report-date-filter-start">
                    <label for="report-start-date" class="form-label">از تاریخ</label>
                    <input type="text" class="form-control persian-datepicker" id="report-start-date">
                </div>
                <div class="col-md-3" id="report-date-filter-end">
                    <label for="report-end-date" class="form-label">تا تاریخ</label>
                    <input type="text" class="form-control persian-datepicker" id="report-end-date">
                </div>
                <div class="col-md-3 d-flex align-items-end" id="cogs-calc-wrapper">
                    <div class="form-check form-switch ms-3">
                        <input class="form-check-input" type="checkbox" role="switch" id="cogs-calculation-checkbox">
                        <label class="form-check-label" for="cogs-calculation-checkbox">
                                    محاسبه دقیق سود
                                </label>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div id="report-results-container"></div>
</div>