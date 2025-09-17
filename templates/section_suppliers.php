<div class="tab-pane fade" id="suppliers" role="tabpanel">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4>مدیریت تأمین‌کنندگان</h4>
        <button class="btn btn-primary" id="add-supplier-btn"><i class="bi bi-plus-circle me-2"></i>افزودن تأمین‌کننده جدید</button>
    </div>
    <div class="input-group search-box">
        <span class="input-group-text"><i class="bi bi-search"></i></span><input type="text" class="form-control" id="supplier-search" placeholder="جستجو در نام، تلفن، کد اقتصادی...">
    </div>
    <div class="table-responsive">
        <table class="table table-striped table-hover" id="suppliers-table">
            <thead>
                <tr>
                    <th class="sortable-header" data-sort-by="name">نام <span class="sort-indicator"></span></th>
                    <th>تلفن</th>
                    <th>جمع فاکتورهای باز</th>
                    <th>اعتبار علی‌الحساب</th>
                    <th>وضعیت نهایی</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody id="suppliers-table-body"></tbody>
        </table>
    </div>
    <div class="pagination-container" data-table="suppliers"></div>
</div>

<div class="modal fade" id="supplierModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">افزودن/ویرایش تأمین‌کننده</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="supplier-form">
                    <div class="alert alert-danger form-error" style="display:none;"></div>
                    <input type="hidden" id="supplier-id">
                    <div class="mb-3"><label for="supplier-name" class="form-label">نام</label><input type="text" class="form-control" id="supplier-name" required></div>
                    <div class="mb-3"><label for="supplier-address" class="form-label">آدرس</label><textarea class="form-control" id="supplier-address" rows="2"></textarea></div>
                    <div class="mb-3"><label for="supplier-phone" class="form-label">تلفن</label><input type="tel" class="form-control" id="supplier-phone"></div>
                    <div class="mb-3"><label for="supplier-economic-code" class="form-label">کد اقتصادی</label><input type="text" class="form-control" id="supplier-economic-code" required></div>
                    <div class="mb-3">
                        <label for="supplier-initial-balance" class="form-label">مانده حساب اولیه (بستانکاری)</label>
                        <input type="text" class="form-control numeric-input" id="supplier-initial-balance" value="0">
                        <div class="form-text">مبلغی که شما از قبل به تامین‌کننده بدهکار هستید را وارد کنید.</div>
                    </div>
                    <button type="submit" class="btn btn-success w-100">ذخیره</button>
                </form>
            </div>
        </div>
    </div>
</div>