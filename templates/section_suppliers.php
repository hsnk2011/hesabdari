<div class="tab-pane fade" id="suppliers" role="tabpanel">
    <div class="ui-page-head">
        <div>
            <h4 class="ui-page-title">تأمین‌کنندگان</h4>
            <p class="ui-page-sub">مانده حساب، خریدها و پرداخت‌های هر تأمین‌کننده</p>
        </div>
        <div class="ui-page-actions">
            <button class="btn btn-primary" id="add-supplier-btn"><i class="bi bi-plus-lg"></i>تأمین‌کننده جدید</button>
        </div>
    </div>

    <div class="ui-panel">
        <div class="ui-toolbar">
            <div class="ui-search search-box">
                <i class="bi bi-search"></i>
                <input type="text" class="form-control" id="supplier-search" placeholder="جستجو در نام، تلفن، کد اقتصادی...">
            </div>
            <div class="ui-filter">
                <label class="ui-filter-label" for="suppliers-balance-filter"><i class="bi bi-funnel"></i></label>
                <select class="form-select" id="suppliers-balance-filter">
                    <option value="">همه وضعیت‌ها</option>
                    <option value="positive">فقط بستانکار (بدهی ما)</option>
                    <option value="negative">فقط بدهکار (طلب ما)</option>
                    <option value="settled">تسویه‌شده</option>
                </select>
            </div>
            <div class="ui-toolbar-end">
                <label class="ui-limit" for="suppliers-limit-select">نمایش
                    <select class="form-select pagination-limit-select form-select-sm" id="suppliers-limit-select" data-table="suppliers">
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
                    <div class="dropdown-menu dropdown-menu-end p-2 shadow" id="suppliers-column-menu" style="min-width: 220px;"></div>
                </div>
            </div>
        </div>
        <div id="suppliers-filter-info" class="ui-filter-info"></div>
        <div class="table-responsive">
            <table class="table table-hover" id="suppliers-table">
                <thead><tr></tr></thead>
                <tbody id="suppliers-table-body"></tbody>
            </table>
        </div>
        <div class="pagination-container" data-table="suppliers"></div>
    </div>
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
                    <div class="mb-3">
                        <label class="form-label">تلفن‌ها</label>
                        <div id="supplier-phones-list"></div>
                        <button type="button" class="btn btn-sm btn-outline-primary mt-1" id="supplier-add-phone-btn">
                            <i class="bi bi-plus-lg me-1"></i>افزودن تلفن
                        </button>
                    </div>
                    <div class="mb-3"><label for="supplier-economic-code" class="form-label">کد اقتصادی</label><input type="text" class="form-control" id="supplier-economic-code"></div>
                    <div class="mb-3"><label for="supplier-job" class="form-label">شغل <span class="text-muted fw-normal">(اختیاری)</span></label><input type="text" class="form-control" id="supplier-job" placeholder="مثلاً تولیدکننده، عمده‌فروش"></div>
                    <div class="mb-3 form-check">
                        <input class="form-check-input" type="checkbox" id="supplier-is-both">
                        <label class="form-check-label" for="supplier-is-both">مشتری هم هست (به این شخص فروش هم داریم)</label>
                        <div class="form-text">این شخص در هر دو بخش مشتریان و تأمین‌کنندگان نمایش داده می‌شود؛ حساب هر بخش جداگانه محاسبه می‌شود و مانده خالص هم کنار آن می‌آید. نام، تلفن، آدرس و شغل دو طرف همیشه یکسان می‌ماند.</div>
                    </div>
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
