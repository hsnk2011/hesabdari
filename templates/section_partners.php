<h4>مدیریت شرکا</h4>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        لیست شرکا
        <button class="btn btn-primary btn-sm" id="add-partner-btn"><i class="bi bi-plus-circle"></i> افزودن شریک</button>
    </div>
    <ul class="list-group list-group-flush" id="partners-list">
    </ul>
</div>

<div class="modal fade" id="partnerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="partnerModalTitle">افزودن شریک</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="partner-form">
                    <div class="alert alert-danger form-error" style="display:none;"></div>
                    <input type="hidden" id="partner-id">
                    <div class="mb-3"><label for="partner-name" class="form-label">نام شریک</label><input type="text" class="form-control" id="partner-name" required></div>
                    <div class="mb-3">
                        <label for="partner-share" class="form-label">سهم (مثال: 0.25 برای ۲۵٪)</label><input type="number" step="0.01" max="1" min="0" class="form-control" id="partner-share" required>
                    </div><button type="submit" class="btn btn-success w-100">ذخیره</button>
                </form>
            </div>
        </div>
    </div>
</div>