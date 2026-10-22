<div class="tab-pane fade" id="suppliers" role="tabpanel">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0">مدیریت تأمین‌کنندگان</h4>
        <button class="btn btn-primary" id="add-supplier-btn"><i class="bi bi-plus-circle me-2"></i>افزودن تأمین‌کننده جدید</button>
    </div>
    <div id="suppliers-filter-info"></div>
    <div class="row g-2 align-items-center mb-3">
        <div class="col-md-5">
            <div class="input-group search-box">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" class="form-control" id="supplier-search" placeholder="جستجو در نام، تلفن، کد اقتصادی...">
            </div>
        </div>
        <div class="col-md-2">
            <select class="form-select pagination-limit-select" id="suppliers-limit-select" data-table="suppliers">
                <option value="10">10 ردیف</option>
                <option value="15">15 ردیف</option>
                <option value="25" selected>25 ردیف</option>
                <option value="50">50 ردیف</option>
                <option value="100">100 ردیف</option>
            </select>
        </div>
        <div class="col-md-3">
            <select class="form-select" id="suppliers-balance-filter">
                <option value="">همه وضعیت‌ها</option>
                <option value="positive">فقط بستانکار (بدهی ما)</option>
                <option value="negative">فقط بدهکار (طلب ما)</option>
                <option value="settled">تسویه‌شده</option>
            </select>
        </div>
        <div class="col-md-2 text-end">
            <div class="dropdown">
                <button class="btn btn-outline-secondary dropdown-toggle w-100" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-layout-three-columns me-1"></i>ستون‌ها
                </button>
                <div class="dropdown-menu dropdown-menu-end p-2 shadow" id="suppliers-column-menu" style="min-width: 220px;"></div>
            </div>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-striped table-hover" id="suppliers-table">
            <thead><tr></tr></thead>
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
