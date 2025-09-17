<div class="d-flex justify-content-between align-items-center mb-3">
    <h4>مدیریت کاربران سیستم</h4>
    <button class="btn btn-primary" id="show-register-modal-btn"><i class="bi bi-person-plus-fill me-2"></i>افزودن کاربر جدید</button>
</div>
<div class="table-responsive">
    <table class="table table-striped table-hover" id="users-table">
        <thead>
            <tr>
                <th class="sortable-header" data-sort-by="id">شناسه <span class="sort-indicator"></span></th>
                <th class="sortable-header" data-sort-by="username">نام کاربری <span class="sort-indicator"></span></th>
                <th>عملیات</th>
            </tr>
        </thead>
        <tbody id="users-table-body"></tbody>
    </table>
</div>
<div class="pagination-container" data-table="users"></div>

<div class="modal fade" id="registerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">ثبت نام کاربر جدید</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="register-form">
                    <div class="alert alert-danger form-error" style="display:none;"></div>
                    <div class="mb-3">
                        <label for="register-username" class="form-label">نام کاربری</label>
                        <input type="text" class="form-control" id="register-username" required>
                    </div>
                    <div class="mb-3">
                        <label for="register-password" class="form-label">رمز عبور</label>
                        <input type="password" class="form-control" id="register-password" required minlength="6">
                    </div>
                    <div class="mb-3">
                        <label for="register-password-confirm" class="form-label">تکرار رمز عبور</label>
                        <input type="password" class="form-control" id="register-password-confirm" required minlength="6">
                    </div>
                    <button type="submit" class="btn btn-primary w-100">ثبت نام</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="resetPasswordModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">بازنشانی رمز عبور کاربر</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="reset-password-form">
                    <div class="alert alert-danger form-error" style="display:none;"></div>
                    <input type="hidden" id="reset-user-id">
                    <p>شما در حال تغییر رمز عبور برای کاربر <strong id="reset-username-display"></strong> هستید.</p>
                    <div class="mb-3">
                        <label for="reset-new-password" class="form-label">رمز عبور جدید</label>
                        <input type="password" class="form-control" id="reset-new-password" required minlength="6">
                    </div>
                    <div class="mb-3">
                        <label for="reset-new-password-confirm" class="form-label">تکرار رمز عبور جدید</label>
                        <input type="password" class="form-control" id="reset-new-password-confirm" required minlength="6">
                    </div>
                    <button type="submit" class="btn btn-success w-100">ذخیره رمز جدید</button>
                </form>
            </div>
        </div>
    </div>
</div>