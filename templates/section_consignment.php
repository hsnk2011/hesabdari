<div class="tab-pane fade" id="consignment" role="tabpanel">
    <h4>مدیریت محصولات امانی</h4>
    <div class="card">
        <div class="card-header">
            <i class="bi bi-arrow-down-left-circle-fill text-success me-2"></i>
            فاکتورهای امانی گرفته شده از تامین‌کنندگان
        </div>
        <div class="card-body">
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