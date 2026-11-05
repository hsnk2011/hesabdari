<div class="tab-pane fade" id="contacts" role="tabpanel">
    <div class="ui-page-head">
        <div>
            <h4 class="ui-page-title">سایر اشخاص</h4>
            <p class="ui-page-sub">طرف‌حساب‌هایی که مشتری، تأمین‌کننده یا شریک نیستند (پیمانکار، اداره مالیات، مالک و …)؛ فاکتور برای آن‌ها ثبت نمی‌شود</p>
        </div>
        <div class="ui-page-actions">
            <button class="btn btn-primary" id="add-contact-btn"><i class="bi bi-plus-lg"></i>شخص جدید</button>
        </div>
    </div>

    <div class="ui-panel">
        <div class="ui-toolbar">
            <div class="ui-search search-box">
                <i class="bi bi-search"></i>
                <input type="text" class="form-control" id="contact-search" placeholder="جستجو در نام، تلفن، کد ملی، یادداشت...">
            </div>
            <div class="ui-filter">
                <label class="ui-filter-label" for="contacts-balance-filter"><i class="bi bi-funnel"></i></label>
                <select class="form-select" id="contacts-balance-filter">
                    <option value="">همه وضعیت‌ها</option>
                    <option value="positive">فقط بستانکار</option>
                    <option value="negative">فقط بدهکار</option>
                    <option value="settled">تسویه‌شده</option>
                </select>
            </div>
            <div class="ui-toolbar-end">
                <label class="ui-limit" for="contacts-limit-select">نمایش
                    <select class="form-select pagination-limit-select form-select-sm" id="contacts-limit-select" data-table="contacts">
                        <option value="10">10</option>
                        <option value="15">15</option>
                        <option value="25" selected>25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                ردیف</label>
                <div class="dropdown">
                    <button class="btn btn-outline-secondary dropdown-toggle btn-sm" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-layout-three-columns"></i> ستون‌ها
                    </button>
                    <div class="dropdown-menu dropdown-menu-end p-2 shadow" id="contacts-column-menu" style="min-width: 220px;"></div>
                </div>
            </div>
        </div>
        <div id="contacts-filter-info" class="ui-filter-info"></div>
        <div class="table-responsive">
            <table class="table table-hover" id="contacts-table">
                <thead><tr></tr></thead>
                <tbody id="contacts-table-body"></tbody>
            </table>
        </div>
        <div class="pagination-container" data-table="contacts"></div>
    </div>
</div>

<div class="modal fade" id="contactModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">افزودن/ویرایش سایر شخص</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="contact-form">
                    <div class="alert alert-danger form-error" style="display:none;"></div>
                    <input type="hidden" id="contact-id">
                    <div class="mb-3"><label for="contact-name" class="form-label">نام</label><input type="text" class="form-control" id="contact-name" required></div>
                    <div class="mb-3"><label for="contact-notes" class="form-label">یادداشت / آدرس</label><textarea class="form-control" id="contact-notes" rows="2"></textarea></div>
                    <div class="mb-3">
                        <label class="form-label">تلفن‌ها</label>
                        <div id="contact-phones-list"></div>
                        <button type="button" class="btn btn-sm btn-outline-primary mt-1" id="contact-add-phone-btn">
                            <i class="bi bi-plus-lg me-1"></i>افزودن تلفن
                        </button>
                    </div>
                    <div class="mb-3"><label for="contact-national-id" class="form-label">کد ملی / شناسه</label><input type="text" class="form-control" id="contact-national-id"></div>
                    <div class="mb-3"><label for="contact-job" class="form-label">شغل <span class="text-muted fw-normal">(اختیاری)</span></label><input type="text" class="form-control" id="contact-job" placeholder="مثلاً وکیل، راننده"></div>
                    <div class="mb-3">
                        <label for="contact-initial-balance" class="form-label">مانده حساب اولیه (بستانکاری ما به ایشان)</label>
                        <input type="text" class="form-control numeric-input" id="contact-initial-balance" value="0">
                        <div class="form-text">اگر از قبل به این شخص بدهکار هستید، مبلغ را وارد کنید.</div>
                    </div>
                    <button type="submit" class="btn btn-success w-100">ذخیره</button>
                </form>
            </div>
        </div>
    </div>
</div>
