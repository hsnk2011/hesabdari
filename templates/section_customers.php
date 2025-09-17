<div class="tab-pane fade" id="customers" role="tabpanel">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4>مدیریت مشتریان</h4>
        <button class="btn btn-primary" id="add-customer-btn"><i class="bi bi-plus-circle me-2"></i>افزودن مشتری جدید</button>
    </div>
    <div class="input-group search-box">
        <span class="input-group-text"><i class="bi bi-search"></i></span><input type="text" class="form-control" id="customer-search" placeholder="جستجو در نام، آدرس، تلفن، کد ملی...">
    </div>
    <div class="table-responsive">
        <table class="table table-striped table-hover" id="customers-table">
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
            <tbody id="customers-table-body"></tbody>
        </table>
    </div>
    <div class="pagination-container" data-table="customers"></div>
</div>

<div class="modal fade" id="customerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">افزودن/ویرایش مشتری</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="customer-form">
                    <div class="alert alert-danger form-error" style="display:none;"></div>
                    <input type="hidden" id="customer-id">
                    <div class="mb-3"><label for="customer-name" class="form-label">نام</label><input type="text" class="form-control" id="customer-name" required></div>
                    <div class="mb-3"><label for="customer-address" class="form-label">آدرس</label><textarea class="form-control" id="customer-address" rows="2"></textarea></div>
                    <div class="mb-3"><label for="customer-phone" class="form-label">تلفن</label><input type="tel" class="form-control" id="customer-phone"></div>
                    <div class="mb-3"><label for="customer-national-id" class="form-label">کد ملی</label><input type="text" class="form-control" id="customer-national-id" required></div>
                    <div class="mb-3">
                        <label for="customer-initial-balance" class="form-label">مانده حساب اولیه (بدهکاری)</label>
                        <input type="text" class="form-control numeric-input" id="customer-initial-balance" value="0">
                        <div class="form-text">مبلغی که مشتری از قبل به شما بدهکار است را وارد کنید.</div>
                    </div>
                    <button type="submit" class="btn btn-success w-100">ذخیره</button>
                </form>
            </div>
        </div>
    </div>
</div>