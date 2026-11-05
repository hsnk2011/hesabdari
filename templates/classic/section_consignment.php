<div class="tab-pane fade" id="consignment" role="tabpanel">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0">مدیریت محصولات امانی</h4>
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" id="consignment-invoices-show-details">
            <label class="form-check-label" for="consignment-invoices-show-details">نمایش جزییات فاکتور</label>
        </div>
    </div>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span>
                <i class="bi bi-arrow-down-left-circle-fill text-success me-2"></i>
                فاکتورهای امانی گرفته شده از تامین‌کنندگان
            </span>
            <button type="button" class="btn btn-sm btn-outline-primary" id="sold-consignment-review-btn" data-permission-action="manage_consignment">
                <i class="bi bi-arrow-left-right me-1"></i>اقلام فروخته‌شده
            </button>
        </div>
        <div class="card-body">
            <div class="row align-items-center mb-3">
                <div class="col-md-6">
                    <div class="input-group search-box">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" class="form-control" id="consignment-purchase-invoice-search" placeholder="جستجو در شماره فاکتور، نام تأمین‌کننده، نام محصول، ابعاد، شناسه انبار، مبالغ...">
                    </div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-striped table-hover" id="consignment-purchases-table">
                    <thead>
                        <tr>
                            <th class="sortable-header" data-sort-by="id">#
                                <span class="sort-indicator"></span>
                            </th>
                            <th class="sortable-header" data-sort-by="date">تاریخ
                                <span class="sort-indicator"></span>
                            </th>
                            <th class="sortable-header" data-sort-by="supplierName">تأمین‌کننده
                                <span class="sort-indicator"></span>
                            </th>
                            <th class="sortable-header" data-sort-by="totalAmount">مبلغ کل
                                <span class="sort-indicator"></span>
                            </th>
                            <th>تخفیف</th>
                            <th>وضعیت</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody id="consignment-purchases-table-body"></tbody>
                </table>
            </div>
            <div class="pagination-container" data-table="consignment-purchases"></div>
        </div>
    </div>
    <div class="card">
        <div class="card-header">
            <i class="bi bi-arrow-up-right-circle-fill text-primary me-2"></i>
            فاکتورهای امانی داده شده به مشتریان
        </div>
        <div class="card-body">
            <div class="row align-items-center mb-3">
                <div class="col-md-6">
                    <div class="input-group search-box">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" class="form-control" id="consignment-sales-invoice-search" placeholder="جستجو در شماره فاکتور، نام مشتری، نام محصول، ابعاد، شناسه انبار، مبالغ...">
                    </div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-striped table-hover" id="consignment-sales-table">
                    <thead>
                        <tr>
                            <th class="sortable-header" data-sort-by="id">#
                                <span class="sort-indicator"></span>
                            </th>
                            <th class="sortable-header" data-sort-by="date">تاریخ
                                <span class="sort-indicator"></span>
                            </th>
                            <th class="sortable-header" data-sort-by="customerName">مشتری
                                <span class="sort-indicator"></span>
                            </th>
                            <th class="sortable-header" data-sort-by="totalAmount">مبلغ کل
                                <span class="sort-indicator"></span>
                            </th>
                            <th>تخفیف</th>
                            <th>وضعیت</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody id="consignment-sales-table-body"></tbody>
                </table>
            </div>
            <div class="pagination-container" data-table="consignment-sales"></div>
        </div>
    </div>
</div>