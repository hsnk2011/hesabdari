<div class="tab-pane fade" id="accounts" role="tabpanel">
    <div class="row">
        <div class="col-md-4">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">لیست حساب‌ها</h5>
                    <button class="btn btn-primary btn-sm" id="add-account-btn"><i class="bi bi-plus-circle"></i> افزودن</button>
                </div>
                <div id="accounts-list-container" class="list-group list-group-flush">
                </div>
            </div>
        </div>
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0" id="account-ledger-title">گردش حساب: --</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover" id="account-transactions-table">
                            <thead>
                                <tr>
                                    <th class="text-end">تاریخ</th>
                                    <th class="text-end">شرح</th>
                                    <th class="text-end text-success">بستانکار (واریز)</th>
                                    <th class="text-end text-danger">بدهکار (برداشت)</th>
                                    <th class="text-end">مانده</th>
                                </tr>
                            </thead>
                            <tbody id="account-transactions-table-body">
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="accountModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="account-modal-title">افزودن حساب جدید</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="account-form">
                    <div class="alert alert-danger form-error" style="display:none;"></div>
                    <input type="hidden" id="account-id">
                    <div class="mb-3">
                        <label for="account-name" class="form-label">نام حساب</label>
                        <input type="text" class="form-control" id="account-name" required placeholder="مثال: بانک ملت شعبه مرکزی یا صندوق">
                    </div>
                    <div class="mb-3">
                        <label for="account-bank-name" class="form-label">نام بانک</label>
                        <input type="text" class="form-control" id="account-bank-name" placeholder="مثال: ملت">
                    </div>
                    <div class="mb-3">
                        <label for="account-number" class="form-label">شماره حساب</label>
                        <input type="text" class="form-control" id="account-number">
                    </div>
                    <div class="mb-3">
                        <label for="account-card-number" class="form-label">شماره کارت</label>
                        <input type="text" class="form-control" id="account-card-number">
                    </div>
                    <div class="mb-3" id="initial-balance-wrapper">
                        <label for="account-initial-balance" class="form-label">موجودی اولیه</label>
                        <input type="text" class="form-control numeric-input" id="account-initial-balance" value="0">
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch" id="account-is-cash">
                        <label class="form-check-label" for="account-is-cash">این حساب صندوق وجه نقد است</label>
                    </div>
                    <button type="submit" class="btn btn-success w-100">ذخیره</button>
                </form>
            </div>
        </div>
    </div>
</div>