<div class="tab-pane fade show active" id="dashboard" role="tabpanel">
    <!-- Quick actions -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="btn-group" role="group">
            <button class="btn btn-outline-primary btn-sm quick-add-btn" data-permission="manage_sales_invoices" data-action="sales" title="فاکتور فروش جدید"><i class="bi bi-receipt me-1"></i>فروش</button>
            <button class="btn btn-outline-success btn-sm quick-add-btn" data-permission="manage_purchase_invoices" data-action="purchase" title="فاکتور خرید جدید"><i class="bi bi-cart-plus me-1"></i>خرید</button>
            <button class="btn btn-outline-warning btn-sm quick-add-btn" data-permission="manage_transactions" data-action="expense" title="ثبت هزینه"><i class="bi bi-cash me-1"></i>هزینه</button>
            <button class="btn btn-outline-info btn-sm quick-add-btn" data-permission="manage_transactions" data-action="payment" title="ثبت پرداخت"><i class="bi bi-credit-card me-1"></i>پرداخت</button>
        </div>
    </div>
    
    <!-- Summary cards row -->
    <div class="row">
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card text-white bg-primary h-100">
                <div class="card-body">
                    <h6 class="card-title"><i class="bi bi-graph-up me-1"></i>فروش ۳۰ روز گذشته</h6>
                    <p class="card-text fs-4" id="dashboard-total-sales">۰ تومان</p>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card text-white bg-success h-100">
                <div class="card-body">
                    <h6 class="card-title"><i class="bi bi-wallet2 me-1"></i>موجودی نقد</h6>
                    <p class="card-text fs-4" id="dashboard-cash-position">۰ تومان</p>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card text-white bg-warning h-100">
                <div class="card-body">
                    <h6 class="card-title"><i class="bi bi-people me-1"></i>کل مطالبات</h6>
                    <p class="card-text fs-4" id="dashboard-total-receivables">۰ تومان</p>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card text-white bg-danger h-100">
                <div class="card-body">
                    <h6 class="card-title"><i class="bi bi-building me-1"></i>کل بدهی‌ها</h6>
                    <p class="card-text fs-4" id="dashboard-total-payables">۰ تومان</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Warehouses pinned for separation (e.g. factory orders, not physical stock) -->
    <div class="card mb-3" id="dashboard-warehouses" style="display: none;">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
            <span><i class="bi bi-box-arrow-in-down me-1"></i>انبارهای سفارش</span>
            <small class="text-muted">خرید شده یا فروخته شده، هنوز تحویل نشده</small>
        </div>
            <div class="card-body p-0" id="dashboard-warehouses-list"></div>
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

    <!-- Operational alerts -->
    <div class="row mb-3" id="dashboard-operational-alerts" style="display: none;">
        <div class="col-12">
            <div class="alert alert-warning mb-0 d-flex flex-wrap align-items-center gap-3">
                <span><i class="bi bi-exclamation-triangle-fill me-1"></i><strong>نیاز به توجه:</strong></span>
                <span id="dashboard-low-stock-alert" style="display: none;"></span>
                <span id="dashboard-overdue-installments-alert" style="display: none;"></span>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-5 mb-3">
            <div class="card h-100">
                <div class="card-header">۵ تامین‌کننده‌ای که بیشترین بدهی را به آن‌ها دارید</div>
                <div class="card-body" style="height: 250px;">
                    <canvas id="topSuppliersChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header">مشتریان تسویه نشده</div>
                <div class="card-body">
                    <div class="table-responsive" style="max-height: 250px;">
                        <table class="table table-sm table-striped">
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
        </div>
    </div>
    <div class="row mt-3">
        <div class="col-lg-7">
             <div class="card h-100">
                <div class="card-header bg-warning">
                    <h5 class="mb-0"><i class="bi bi-exclamation-triangle-fill me-2"></i>هشدارهای هوشمند و نکات مدیریتی</h5>
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
        <div class="col-lg-5">
             <div class="card h-100">
                <div class="card-header">نمودار تفکیک هزینه‌ها</div>
                <div class="card-body" style="height: 300px;">
                    <canvas id="expensesPieChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="row mt-3">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-secondary text-white"><i class="bi bi-hourglass-split me-2"></i>سفارشات در حال آماده‌سازی</div>
                <div class="card-body">
                    <div class="table-responsive" style="max-height: 300px;">
                        <table class="table table-sm table-striped">
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
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-info text-white"><i class="bi bi-box-seam me-2"></i>سفارشات آماده تحویل</div>
                <div class="card-body">
                    <div class="table-responsive" style="max-height: 300px;">
                        <table class="table table-sm table-striped">
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
    </div>
    <div class="row mt-3">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">۵ چک دریافتی نزدیک به سررسید</div>
                <div class="card-body">
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
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">۵ چک پرداختی نزدیک به سررسید</div>
                <div class="card-body">
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
    </div>
</div>