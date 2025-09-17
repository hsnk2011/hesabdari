<div class="modal fade" id="changePasswordModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">تغییر رمز عبور</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="change-password-form">
                    <div class="alert alert-danger form-error" style="display:none;"></div>
                    <div class="mb-3">
                        <label for="current-password" class="form-label">رمز عبور فعلی</label>
                        <input type="password" class="form-control" id="current-password" required>
                    </div>
                    <div class="mb-3">
                        <label for="new-password" class="form-label">رمز عبور جدید</label>
                        <input type="password" class="form-control" id="new-password" required minlength="6">
                    </div>
                    <div class="mb-3">
                        <label for="new-password-confirm" class="form-label">تکرار رمز عبور جدید</label>
                        <input type="password" class="form-control" id="new-password-confirm" required minlength="6">
                    </div>
                    <button type="submit" class="btn btn-success w-100">ذخیره تغییرات</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="cashCheckModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">وصول چک</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="cash-check-form">
                    <div class="alert alert-danger form-error" style="display:none;"></div>
                    <input type="hidden" id="cash-check-id">
                    <p>چک به کدام حساب واریز شود؟</p>
                    <div class="mb-3">
                        <label for="cash-check-account-id" class="form-label">انتخاب حساب</label>
                        <select class="form-select" id="cash-check-account-id" required></select>
                    </div>
                    <button type="submit" class="btn btn-success w-100">ثبت</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="confirmationModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="confirmationModalTitle">تأیید عملیات</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p id="confirmationModalBody">آیا از انجام این عملیات مطمئن هستید؟</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
                <button type="button" class="btn btn-danger" id="confirmActionBtn">تأیید</button>
            </div>
        </div>
    </div>
</div>