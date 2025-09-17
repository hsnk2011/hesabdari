<div class="tab-pane fade" id="checks" role="tabpanel">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4>مدیریت چک‌ها</h4>
        <button class="btn btn-primary" id="add-check-btn"><i class="bi bi-plus-circle me-2"></i>ثبت چک جدید</button>
    </div>
    <div class="input-group search-box">
        <span class="input-group-text"><i class="bi bi-search"></i></span><input type="text" class="form-control" id="check-search" placeholder="جستجو در شماره چک، نام بانک، مبلغ...">
    </div>
    <div class="table-responsive">
        <table class="table table-striped table-hover" id="checks-table">
            <thead>
                <tr>
                    <th class="sortable-header" data-sort-by="type">نوع چک <span class="sort-indicator"></span></th>
                    <th>شماره چک</th>
                    <th class="sortable-header" data-sort-by="bankName">بانک <span class="sort-indicator"></span></th>
                    <th class="sortable-header" data-sort-by="amount">مبلغ (تومان) <span class="sort-indicator"></span></th>
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
                        <div class="col-md-6 mb-3">
                            <label for="check-due-date" class="form-label">تاریخ سررسید</label>
                            <input type="text" class="form-control persian-datepicker" id="check-due-date" required>
                        </div>
                        <div class="col-md-6 mb-3">
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