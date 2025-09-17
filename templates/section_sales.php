<div class="tab-pane fade" id="sales-invoices" role="tabpanel">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4>فاکتورهای فروش</h4>
        <button class="btn btn-primary" id="add-sales-invoice-btn"><i class="bi bi-plus-circle me-2"></i>ایجاد فاکتور فروش جدید</button>
    </div>
    <div class="input-group search-box">
        <span class="input-group-text"><i class="bi bi-search"></i></span><input type="text" class="form-control" id="sales-invoice-search" placeholder="جستجو در شماره فاکتور، نام مشتری، مبالغ...">
    </div>
    <div class="table-responsive">
        <table class="table table-striped table-hover" id="sales-invoices-table">
            <thead>
                <tr>
                    <th class="sortable-header" data-sort-by="id"># <span class="sort-indicator"></span></th>
                    <th class="sortable-header" data-sort-by="date">تاریخ <span class="sort-indicator"></span></th>
                    <th class="sortable-header" data-sort-by="customerName">مشتری <span class="sort-indicator"></span></th>
                    <th class="sortable-header" data-sort-by="totalAmount">مبلغ کل <span class="sort-indicator"></span></th>
                    <th class="sortable-header" data-sort-by="discount">تخفیف<span class="sort-indicator"></span></th>
                    <th class="sortable-header" data-sort-by="paidAmount">مبلغ پرداختی <span class="sort-indicator"></span></th>
                    <th class="sortable-header" data-sort-by="remainingAmount">مبلغ باقی‌مانده <span class="sort-indicator"></span></th>
                    <th>وضعیت</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody id="sales-invoices-table-body"></tbody>
        </table>
    </div>
    <div class="pagination-container" data-table="sales-invoices"></div>
</div>

<div class="modal fade" id="salesInvoiceModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="sales-invoice-modal-title">ایجاد/ویرایش فاکتور فروش</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="sales-invoice-form" novalidate>
                    <div class="alert alert-danger form-error" style="display:none;"></div>
                    <input type="hidden" id="sales-invoice-id">
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="sales-invoice-customer" class="form-label">مشتری</label>
                            <div class="input-group">
                                <select class="form-select" id="sales-invoice-customer" required></select>
                                <button class="btn btn-outline-success" type="button" id="add-customer-from-invoice-btn" title="افزودن مشتری جدید"><i class="bi bi-plus-lg"></i></button>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="sales-invoice-date" class="form-label">تاریخ فاکتور</label><input type="text" class="form-control persian-datepicker" id="sales-invoice-date" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="sales-invoice-description" class="form-label">توضیحات فاکتور</label>
                            <textarea class="form-control" id="sales-invoice-description" rows="1"></textarea>
                        </div>
                    </div>
                    <hr>
                    <h6><i class="bi bi-list-ul me-2"></i>آیتم‌های فاکتور</h6>
                    <div id="sales-invoice-items-container"></div>
                    <button type="button" class="btn btn-outline-primary btn-sm mb-3" id="add-sales-invoice-item-btn"><i class="bi bi-plus"></i> افزودن ردیف</button>
                    <hr>
                    <h6><i class="bi bi-cash-stack me-2"></i>بخش پرداخت</h6>
                    <div id="sales-invoice-payments-container"></div>
                    <button type="button" class="btn btn-outline-secondary btn-sm mb-3" id="add-sales-invoice-payment-btn"><i class="bi bi-plus"></i> افزودن ردیف پرداخت</button>
                    <hr>
                    <div class="row justify-content-end">
                        <div class="col-md-5">
                            <ul class="list-group">
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    جمع تعداد اقلام
                                    <span class="badge bg-secondary rounded-pill fs-6" id="sales-invoice-total-quantity">۰</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    جمع متراژ
                                    <span class="badge bg-secondary rounded-pill fs-6" id="sales-invoice-total-area">۰ متر مربع</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center">جمع کل
                                    فاکتور<span class="badge bg-primary rounded-pill fs-6" id="sales-invoice-total">۰ تومان</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center">تخفیف
                                    <div class="input-group input-group-sm" style="width: 150px;">
                                        <input type="text" class="form-control numeric-input" id="sales-invoice-discount" value="0"><span class="input-group-text">تومان</span>
                                    </div>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center">جمع
                                    پرداختی<span class="badge bg-success rounded-pill fs-6" id="sales-invoice-paid">۰ تومان</span>
                                </li>
                                <li
                                    class="list-group-item d-flex justify-content-between align-items-center fw-bold">
                                    مبلغ
                                    باقی‌مانده<span class="badge bg-danger rounded-pill fs-6" id="sales-invoice-remaining">۰ تومان</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button><button type="button" class="btn btn-success" id="save-sales-invoice-btn">ذخیره فاکتور</button>
            </div>
        </div>
    </div>
</div>