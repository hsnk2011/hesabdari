<div class="tab-pane fade" id="transactions" role="tabpanel">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4>مرکز تراکنشات</h4>
        <div class="btn-group">
            <button class="btn btn-success" id="add-expense-btn-from-tx"><i class="bi bi-wallet2 me-2"></i>ثبت هزینه جدید</button>
            <button class="btn btn-primary" id="add-payment-btn-from-tx"><i class="bi bi-plus-circle me-2"></i>ثبت پرداخت/دریافت</button>
        </div>
    </div>
    <div class="row align-items-center mb-3">
        <div class="col-md-8">
            <div class="input-group search-box">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" class="form-control" id="transaction-search" placeholder="جستجو در شرح، حساب، مبلغ...">
            </div>
        </div>
        <div class="col-md-4">
            <div class="btn-group w-100" role="group" id="transaction-filter-buttons">
                <button type="button" class="btn btn-outline-secondary active" data-filter="">همه</button>
                <button type="button" class="btn btn-outline-secondary" data-filter="payment">پرداخت/دریافت</button>
                <button type="button" class="btn btn-outline-secondary" data-filter="expense">هزینه‌ها</button>
            </div>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover" id="transactions-table">
            <thead class="table-light">
                <tr>
                    <th class="sortable-header" data-sort-by="date">تاریخ
                        <span class="sort-indicator"></span>
                    </th>
                    <th>شرح کلی</th>
                    <th>حساب</th>
                    <th class="sortable-header text-success" data-sort-by="credit">بستانکار (ورودی)
                        <span class="sort-indicator"></span>
                    </th>
                    <th class="sortable-header text-danger" data-sort-by="debit">بدهکار (خروجی)
                        <span class="sort-indicator"></span>
                    </th>
                    <th>توضیحات</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody id="transactions-table-body"></tbody>
        </table>
    </div>
    <div class="pagination-container" data-table="transactions"></div>
</div>

<div class="modal fade" id="transactionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">ثبت پرداخت / دریافت علی الحساب</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="transaction-form">
                    <div class="alert alert-danger form-error" style="display:none;"></div>
                    <input type="hidden" id="transaction-id">

                    <div id="payment-fields">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="person-type" class="form-label">مربوط به</label>
                                <select id="person-type" class="form-select">
                                    <option value="customer">مشتری</option>
                                    <option value="supplier">تامین کننده</option>
                                    <option value="partner">شریک</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="person-select" class="form-label">انتخاب شخص</label>
                                <select id="person-select" class="form-select" required></select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="transaction-flow" class="form-label">جریان تراکنش</label>
                                <select id="transaction-flow" class="form-select">
                                    <option value="receipt">دریافت وجه (ورود پول به شرکت)</option>
                                    <option value="payment">پرداخت وجه (خروج پول از شرکت)</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="payment-method" class="form-label">شیوه پرداخت</label>
                                <select id="payment-method" class="form-select">
                                    <option value="cash">نقد</option>
                                    <option value="check">چک جدید</option>
                                    <option value="endorse_check">خرج چک دریافتی</option>
                                </select>
                            </div>
                        </div>
                        <div id="payment-details-container"></div>
                        <div class="mb-3">
                            <label for="transaction-amount-payment" class="form-label">مبلغ</label>
                            <input type="text" class="form-control numeric-input" id="transaction-amount-payment" required>
                        </div>
                        <div class="mb-3">
                            <label for="transaction-description-payment" class="form-label">توضیحات</label>
                            <textarea class="form-control" id="transaction-description-payment" rows="2"></textarea>
                        </div>
                    </div>

                    <hr>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="transaction-account-id" class="form-label">از/به حساب</label>
                            <select class="form-select" id="transaction-account-id" required></select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="transaction-date" class="form-label">تاریخ</label>
                            <input type="text" class="form-control persian-datepicker" id="transaction-date" required>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-success w-100">ذخیره تراکنش</button>
                </form>
            </div>
        </div>
    </div>
</div>


<div class="modal fade" id="expenseModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">ثبت / ویرایش هزینه</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="expense-form">
                    <div class="alert alert-danger form-error" style="display:none;"></div>
                    <input type="hidden" id="expense-id">
                    <div class="mb-3">
                        <label for="expense-category" class="form-label">دسته‌بندی</label>
                        <select class="form-select" id="expense-category" required></select>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="expense-date" class="form-label">تاریخ</label>
                            <input type="text" class="form-control persian-datepicker" id="expense-date" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="expense-amount" class="form-label">مبلغ</label>
                            <input type="text" class="form-control numeric-input" id="expense-amount" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="expense-account-id" class="form-label">پرداخت از حساب</label>
                        <select class="form-select" id="expense-account-id" required></select>
                    </div>
                    <div class="mb-3">
                        <label for="expense-description" class="form-label">توضیحات</label>
                        <textarea class="form-control" id="expense-description" rows="2"></textarea>
                    </div>
                    <button type="submit" class="btn btn-success w-100">ذخیره هزینه</button>
                </form>
            </div>
        </div>
    </div>
</div>