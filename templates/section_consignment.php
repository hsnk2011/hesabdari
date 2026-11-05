<div class="tab-pane fade" id="consignment" role="tabpanel">
    <div class="ui-page-head">
        <div>
            <h4 class="ui-page-title">محصولات امانی</h4>
            <p class="ui-page-sub">کالای امانی گرفته‌شده از تأمین‌کنندگان و امانی داده‌شده به مشتریان</p>
        </div>
        <div class="ui-page-actions">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="consignment-invoices-show-details">
                <label class="form-check-label" for="consignment-invoices-show-details">نمایش اقلام فاکتور</label>
            </div>
        </div>
    </div>

    <div class="ui-panel">
        <div class="ui-panel-head">
            <h5 class="ui-panel-title"><i class="bi bi-box-arrow-in-down text-success"></i>امانی از تأمین‌کنندگان</h5>
            <div class="ui-page-actions">
                <button type="button" class="btn btn-sm btn-outline-primary" id="sold-consignment-review-btn" data-permission-action="manage_consignment">
                    <i class="bi bi-arrow-left-right me-1"></i>اقلام فروخته‌شده
                </button>
            </div>
        </div>
        <div class="ui-toolbar">
            <div class="ui-search search-box">
                <i class="bi bi-search"></i>
                <input type="text" class="form-control" id="consignment-purchase-invoice-search" placeholder="جستجو در شماره فاکتور، نام تأمین‌کننده، نام محصول، ابعاد، شناسه انبار، مبالغ...">
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover" id="consignment-purchases-table">
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
    <div class="ui-panel">
        <div class="ui-panel-head">
            <h5 class="ui-panel-title"><i class="bi bi-box-arrow-up"></i>امانی نزد مشتریان</h5>
        </div>
        <div class="ui-toolbar">
            <div class="ui-search search-box">
                <i class="bi bi-search"></i>
                <input type="text" class="form-control" id="consignment-sales-invoice-search" placeholder="جستجو در شماره فاکتور، نام مشتری، نام محصول، ابعاد، شناسه انبار، مبالغ...">
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover" id="consignment-sales-table">
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