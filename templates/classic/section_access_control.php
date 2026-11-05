<h4>مدیریت نقش‌ها و دسترسی‌ها</h4>
<div class="row">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">نقش‌های کاربری</h5>
                <button class="btn btn-primary btn-sm" id="add-role-btn"><i class="bi bi-plus-circle"></i> افزودن نقش</button>
            </div>
            <ul class="list-group list-group-flush" id="roles-list-container">
                <li class="list-group-item">در حال بارگذاری...</li>
            </ul>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card">
             <div class="card-header">
                <h5 class="mb-0">تخصیص نقش و دسترسی به کاربران</h5>
            </div>
            <div class="card-body" id="user-access-list-container" style="max-height: 70vh; overflow-y: auto;">
                <p>در حال بارگذاری کاربران...</p>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="roleModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">افزودن/ویرایش نقش</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="role-form">
                    <input type="hidden" id="role-id">
                    <div class="mb-3">
                        <label for="role-name" class="form-label">نام نقش</label>
                        <input type="text" class="form-control" id="role-name" required>
                    </div>
                    <div class="mb-3">
                        <label for="role-description" class="form-label">توضیحات</label>
                        <textarea id="role-description" class="form-control" rows="2"></textarea>
                    </div>
                    <hr>
                    <h6>دسترسی‌ها</h6>
                    <div class="row" id="role-permissions-container">
                        </div>
                    <button type="submit" class="btn btn-success w-100 mt-3">ذخیره نقش</button>
                </form>
            </div>
        </div>
    </div>
</div>