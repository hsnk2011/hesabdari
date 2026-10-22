<!-- /templates/section_quotations.php -->
<div class="tab-pane fade" id="quotations" role="tabpanel">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4>پیش فاکتورها</h4>
        <button class="btn btn-primary" id="add-quotation-btn"><i class="bi bi-plus-circle me-2"></i>ایجاد پیش فاکتور جدید</button>
    </div>
    <div class="row align-items-center mb-3">
        <div class="col-md-7">
            <div class="input-group search-box">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" class="form-control table-search" data-table="quotations" placeholder="جستجو در شماره پیش فاکتور، نام مشتری، مبالغ...">
            </div>
        </div>
        <div class="col-md-2">
            <select class="form-select" id="quotation-status-filter">
                <option value="">همه وضعیت‌ها</option>
                <option value="pending">در انتظار</option>
                <option value="converted">تبدیل شده</option>
                <option value="cancelled">لغو شده</option>
                <option value="expired">منقضی شده</option>
            </select>
        </div>
        <div class="col-md-3">
            <div class="input-group">
                <label class="input-group-text">نمایش</label>
                <select class="form-select pagination-limit-select" data-table="quotations">
                    <option value="10" selected>10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                    <option value="-1">همه</option>
                </select>
                <span class="input-group-text">ردیف</span>
            </div>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-striped table-hover" id="quotations-table">
            <thead>
                <tr>
                    <th class="sortable-header" data-table="quotations" data-sort-by="id"># <span class="sort-indicator"></span></th>
                    <th class="sortable-header" data-table="quotations" data-sort-by="date">تاریخ <span class="sort-indicator"></span></th>
                    <th class="sortable-header" data-table="quotations" data-sort-by="valid_until">اعتبار تا <span class="sort-indicator"></span></th>
                    <th class="sortable-header" data-table="quotations" data-sort-by="customerName">مشتری <span class="sort-indicator"></span></th>
                    <th class="sortable-header" data-table="quotations" data-sort-by="totalAmount">مبلغ کل <span class="sort-indicator"></span></th>
                    <th class="sortable-header" data-table="quotations" data-sort-by="discount">تخفیف <span class="sort-indicator"></span></th>
                    <th class="sortable-header" data-table="quotations" data-sort-by="netAmount">مبلغ نهایی <span class="sort-indicator"></span></th>
                    <th class="sortable-header" data-table="quotations" data-sort-by="status">وضعیت <span class="sort-indicator"></span></th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody id="quotations-table-body"></tbody>
        </table>
    </div>
    <div class="pagination-container" data-table="quotations"></div>
</div>

<!-- Quotation Modal -->
<div class="modal fade" id="quotationModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-fullscreen modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="quotation-modal-title">ایجاد پیش فاکتور جدید</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="quotation-form" novalidate>
                    <div class="alert alert-danger form-error" style="display:none;"></div>
                    <input type="hidden" id="quotation-id">
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label for="quotation-customer" class="form-label">مشتری <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <select class="form-select" id="quotation-customer" required></select>
                                <button class="btn btn-outline-success" type="button" id="add-customer-from-quotation-btn" data-permission="manage_customers" title="افزودن مشتری جدید"><i class="bi bi-plus-lg"></i></button>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="quotation-date" class="form-label">تاریخ پیش فاکتور <span class="text-danger">*</span></label>
                            <input type="text" class="form-control persian-datepicker" id="quotation-date" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="quotation-valid-until" class="form-label">معتبر تا تاریخ</label>
                            <input type="text" class="form-control persian-datepicker" id="quotation-valid-until">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="quotation-description" class="form-label">توضیحات</label>
                            <textarea class="form-control" id="quotation-description" rows="1"></textarea>
                        </div>
                    </div>
                    <hr>
                    <h6><i class="bi bi-list-ul me-2"></i>آیتم‌های پیش فاکتور</h6>
                    <div id="quotation-items-container"></div>
                    <button type="button" class="btn btn-outline-primary btn-sm mb-3" id="add-quotation-item-btn"><i class="bi bi-plus"></i> افزودن ردیف</button>
                    <hr>
                    <div class="row justify-content-end">
                        <div class="col-md-5">
                            <ul class="list-group">
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    جمع تعداد اقلام
                                    <span class="badge bg-secondary rounded-pill fs-6" id="quotation-total-quantity">۰</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    جمع متراژ
                                    <span class="badge bg-secondary rounded-pill fs-6" id="quotation-total-area">۰ متر مربع</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    جمع کل پیش فاکتور
                                    <span class="badge bg-primary rounded-pill fs-6" id="quotation-total">۰ تومان</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    تخفیف
                                    <div class="input-group input-group-sm" style="width: 150px;">
                                        <input type="text" class="form-control numeric-input" id="quotation-discount" value="0">
                                        <span class="input-group-text" data-currency-label>تومان</span>
                                    </div>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center fw-bold">
                                    مبلغ نهایی
                                    <span class="badge bg-success rounded-pill fs-6" id="quotation-net-total">۰ تومان</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
                <button type="button" class="btn btn-success" id="save-quotation-btn">ذخیره پیش فاکتور</button>
            </div>
        </div>
    </div>
</div>

<!-- Quotation View Modal (for converted quotations) -->
<div class="modal fade" id="quotationViewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">مشاهده پیش فاکتور</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="quotation-view-content">
                <!-- Content will be populated dynamically -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">بستن</button>
                <button type="button" class="btn btn-info" id="print-quotation-btn"><i class="bi bi-printer me-2"></i>چاپ</button>
            </div>
        </div>
    </div>
</div>
