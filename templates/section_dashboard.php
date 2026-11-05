<div class="tab-pane fade show active" id="dashboard" role="tabpanel">
    <div class="ui-page-head">
        <div>
            <h4 class="ui-page-title">داشبورد</h4>
            <p class="ui-page-sub">نمای کلی امروز: فروش، نقدینگی، مطالبات و کارهایی که منتظر شماست</p>
        </div>
        <div class="ui-page-actions">
            <button class="btn btn-outline-secondary quick-add-btn" data-permission="manage_transactions" data-action="expense" title="ثبت هزینه"><i class="bi bi-cash"></i>ثبت هزینه</button>
            <button class="btn btn-outline-secondary quick-add-btn" data-permission="manage_transactions" data-action="payment" title="ثبت پرداخت"><i class="bi bi-credit-card"></i>پرداخت / دریافت</button>
            <button class="btn btn-outline-primary quick-add-btn" data-permission="manage_purchase_invoices" data-action="purchase" title="فاکتور خرید جدید"><i class="bi bi-cart-plus"></i>فاکتور خرید</button>
            <button class="btn btn-primary quick-add-btn" data-permission="manage_sales_invoices" data-action="sales" title="فاکتور فروش جدید"><i class="bi bi-receipt"></i>فاکتور فروش</button>
        </div>
    </div>

    <div class="ui-kpis ui-kpis-4">
        <div class="ui-kpi">
            <div class="ui-kpi-label">
                <select class="ui-kpi-select" id="dashboard-sales-period" aria-label="بازه فروش">
                    <option value="30d">فروش ۳۰ روز گذشته</option>
                    <option value="mtd">فروش از اول ماه</option>
                </select>
                <span class="ui-kpi-icon "><i class="bi bi-graph-up-arrow"></i></span>
            </div>
            <div class="ui-kpi-value" id="dashboard-total-sales">۰ تومان</div>
            <div class="ui-kpi-note" id="dashboard-total-sales-note">جمع فاکتورهای فروش یک ماه اخیر</div>
        </div>
        <div class="ui-kpi">
            <div class="ui-kpi-label"><span>موجودی نقد</span><span class="ui-kpi-icon is-success"><i class="bi bi-wallet2"></i></span></div>
            <div class="ui-kpi-value" id="dashboard-cash-position">۰ تومان</div>
            <div class="ui-kpi-note">بانک‌ها و صندوق</div>
        </div>
        <div class="ui-kpi">
            <div class="ui-kpi-label"><span>کل مطالبات</span><span class="ui-kpi-icon is-warning"><i class="bi bi-person-lines-fill"></i></span></div>
            <div class="ui-kpi-value" id="dashboard-total-receivables">۰ تومان</div>
            <div class="ui-kpi-note">طلب ما از مشتریان</div>
        </div>
        <div class="ui-kpi">
            <div class="ui-kpi-label"><span>کل بدهی‌ها</span><span class="ui-kpi-icon is-danger"><i class="bi bi-building"></i></span></div>
            <div class="ui-kpi-value" id="dashboard-total-payables">۰ تومان</div>
            <div class="ui-kpi-note">بدهی ما به تأمین‌کنندگان</div>
        </div>
    </div>
    <div class="row mb-3" id="dashboard-operational-alerts" style="display: none;">
        <div class="col-12">
            <div class="alert alert-warning mb-0 d-flex flex-wrap align-items-center gap-3">
                <span><i class="bi bi-exclamation-triangle-fill me-1"></i><strong>نیاز به توجه:</strong></span>
                <span id="dashboard-low-stock-alert" style="display: none;"></span>
                <span id="dashboard-overdue-installments-alert" style="display: none;"></span>
            </div>
        </div>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-xl-7">
            <div class="ui-panel h-100">
                <div class="ui-panel-head">
                    <h5 class="ui-panel-title"><i class="bi bi-lightbulb text-warning"></i>هشدارهای هوشمند و نکات مدیریتی</h5>
                </div>
                <div class="list-group list-group-flush" id="smart-alerts-container">
                    <div class="list-group-item">
                        <div class="d-flex align-items-center">
                            <strong>در حال بارگذاری...</strong>
                            <div class="spinner-border spinner-border-sm ms-auto" role="status" aria-hidden="true"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-5">
            <div class="ui-panel h-100">
                <div class="ui-panel-head">
                    <h5 class="ui-panel-title"><i class="bi bi-calendar-event"></i>چک‌های نزدیک به سررسید</h5>
                </div>
                <div class="ui-subhead"><i class="bi bi-arrow-down-left text-success"></i>دریافتی</div>
                <div class="table-responsive">
                    <table class="table table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>شماره چک</th>
                                                        <th>از طرف</th>
                                                        <th>مبلغ</th>
                                                        <th>تاریخ سررسید</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="dashboard-due-received-checks"></tbody>
                                            </table>
                </div>
                <div class="ui-subhead"><i class="bi bi-arrow-up-right text-danger"></i>پرداختی</div>
                <div class="table-responsive">
                    <table class="table table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>شماره چک</th>
                                                        <th>به نام</th>
                                                        <th>مبلغ</th>
                                                        <th>تاریخ سررسید</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="dashboard-due-payable-checks"></tbody>
                                            </table>
                </div>
            </div>
        </div>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="ui-panel h-100">
                <div class="ui-panel-head">
                    <h5 class="ui-panel-title"><i class="bi bi-hourglass-split"></i>سفارش‌های در حال آماده‌سازی</h5>
                </div>
                <div class="table-responsive" style="max-height: 320px;">
                    <table class="table table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>#</th>
                                                        <th>نوع</th>
                                                        <th>طرف حساب</th>
                                                        <th>تاریخ</th>
                                                        <th>مبلغ</th>
                                                        <th>عملیات</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="dashboard-preparing-orders"></tbody>
                                            </table>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="ui-panel h-100">
                <div class="ui-panel-head">
                    <h5 class="ui-panel-title"><i class="bi bi-box-seam"></i>سفارش‌های آماده تحویل</h5>
                </div>
                <div class="table-responsive" style="max-height: 320px;">
                    <table class="table table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>#</th>
                                                        <th>نوع</th>
                                                        <th>طرف حساب</th>
                                                        <th>تاریخ</th>
                                                        <th>مبلغ</th>
                                                        <th>عملیات</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="dashboard-ready-orders"></tbody>
                                            </table>
                </div>
            </div>
        </div>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-xl-4 col-lg-6">
            <div class="ui-panel h-100">
                <div class="ui-panel-head">
                    <h5 class="ui-panel-title"><i class="bi bi-person-exclamation"></i>مشتریان تسویه‌نشده</h5>
                </div>
                <div class="table-responsive" style="max-height: 300px;">
                    <table class="table table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>نام مشتری</th>
                                                        <th>مبلغ بدهی</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="dashboard-unsettled-customers"></tbody>
                                            </table>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-lg-6">
            <div class="ui-panel h-100">
                <div class="ui-panel-head">
                    <h5 class="ui-panel-title"><i class="bi bi-bar-chart"></i>۵ تأمین‌کننده با بیشترین طلب از ما</h5>
                </div>
                <div class="ui-panel-body" style="height: 300px;">
                    <canvas id="topSuppliersChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="ui-panel h-100">
                <div class="ui-panel-head">
                    <h5 class="ui-panel-title"><i class="bi bi-pie-chart"></i>تفکیک هزینه‌ها</h5>
                </div>
                <div class="ui-panel-body" style="height: 300px;">
                    <canvas id="expensesPieChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="ui-panel" id="dashboard-warehouses" style="display: none;">
        <div class="ui-panel-head">
            <h5 class="ui-panel-title"><i class="bi bi-box-arrow-in-down"></i>انبارهای سفارش</h5>
            <small class="text-muted">خرید شده یا فروخته شده، هنوز تحویل نشده</small>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2 px-3 py-2 border-bottom" id="dashboard-order-tools">
            <div class="input-group input-group-sm flex-grow-1" style="max-width: 420px;">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="search" class="form-control" id="dashboard-order-search" placeholder="جستجو در سفارش‌ها: ابعاد (2x3)، نام، کد طرح، مشتری" autocomplete="off">
            </div>
            <label class="small text-muted mb-0" for="dashboard-order-tolerance">تطبیق با انبار تا</label>
            <select class="form-select form-select-sm w-auto" id="dashboard-order-tolerance">
                <option value="5">±۵ سانت</option>
                <option value="10" selected>±۱۰ سانت</option>
                <option value="20">±۲۰ سانت</option>
                <option value="30">±۳۰ سانت</option>
            </select>
        </div>
        <div class="d-none" id="dashboard-order-search-results"></div>
            <div class="" id="dashboard-warehouses-list"></div>
    </div>
    <div class="modal fade" id="orderStockMatchModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">موجودی مشابه در انبار</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2" id="order-stock-match-title"></p>
                    <p class="small text-muted mb-2">فرش‌های موجود در انبار فیزیکی با ابعاد نزدیک به این سفارش؛ اول همان فرش، بعد همان کد طرح با رنگ دیگر، بعد فقط سایز مشابه.</p>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>شناسه</th>
                                    <th>نام فرش</th>
                                    <th>کد طرح</th>
                                    <th>رنگ</th>
                                    <th>ابعاد</th>
                                    <th>اختلاف</th>
                                    <th>انبار</th>
                                    <th>تطبیق</th>
                                </tr>
                            </thead>
                            <tbody id="order-stock-match-body"></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">بستن</button>
                </div>
            </div>
        </div>
    </div>
    <div class="modal fade" id="convertSoldOrdersModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">انتقال فروش‌های قبلی به انبار سفارش</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-2">فرش‌هایی که فاکتور فروش دارند و هنوز تحویل نشده‌اند. با انتقال، وضعیت فروش عوض نمی‌شود و فقط تا زمان تحویل در انبار سفارش دیده می‌شوند.</p>
                    <input type="search" class="form-control form-control-sm mb-2" id="convert-orders-search" placeholder="جستجو: شناسه، نام فرش، مشتری، شماره فاکتور">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="convert-orders-select-all">
                        <label class="form-check-label" for="convert-orders-select-all">انتخاب همه</label>
                    </div>
                    <div class="table-responsive" style="max-height: 420px;">
                        <table class="table table-sm table-hover mb-0">
                            <thead>
                                <tr>
                                    <th></th>
                                    <th>شناسه</th>
                                    <th>فرش</th>
                                    <th>ابعاد</th>
                                    <th>مشتری</th>
                                    <th>فاکتور</th>
                                    <th>انبار فعلی</th>
                                </tr>
                            </thead>
                            <tbody id="convert-orders-body"></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
                    <button type="button" class="btn btn-primary" id="convert-orders-assign-btn">انتقال به انبار سفارش</button>
                </div>
            </div>
        </div>
    </div>
    <div class="modal fade" id="deliverOrderModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">تحویل از انبار سفارش</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-1" id="deliver-order-product-name"></p>
                    <p class="small text-muted mb-2" id="deliver-order-mode-note"></p>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label for="deliver-order-dimensions" class="form-label">ابعاد واقعی تحویل</label>
                            <input type="text" class="form-control" id="deliver-order-dimensions" placeholder="مثال: 2x3" autocomplete="off">
                        </div>
                        <div class="col-md-6">
                            <label for="deliver-order-serial" class="form-label">شناسه انبار</label>
                            <input type="text" class="form-control" id="deliver-order-serial" placeholder="شناسه" autocomplete="off">
                        </div>
                        <div class="col-12 d-none" id="deliver-order-target-wrap">
                            <label for="deliver-order-target-warehouse" class="form-label">انبار مقصد</label>
                            <select class="form-select" id="deliver-order-target-warehouse"></select>
                        </div>
                    </div>
                    <div class="mt-3 small border rounded p-2 bg-light" id="deliver-order-preview">
                        <div class="text-muted mb-1">پیش‌نمایش قیمت (قیمت متری ثابت)</div>
                        <div>متراژ: <span id="deliver-preview-area-old">—</span> ← <span id="deliver-preview-area-new">—</span></div>
                        <div id="deliver-order-sales-row">فروش: <span id="deliver-preview-sales-old">—</span> ← <strong id="deliver-preview-sales-new">—</strong></div>
                        <div>خرید: <span id="deliver-preview-purchase-old">—</span> ← <strong id="deliver-preview-purchase-new">—</strong></div>
                    </div>
                    <div class="alert alert-danger py-2 mt-2 mb-0 d-none" id="deliver-order-error"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
                    <button type="button" class="btn btn-success" id="deliver-order-confirm-btn">ثبت تحویل</button>
                </div>
            </div>
        </div>
    </div>
</div>