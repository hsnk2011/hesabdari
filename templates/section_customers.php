<div class="tab-pane fade" id="customers" role="tabpanel">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0">مدیریت مشتریان</h4>
        <button class="btn btn-primary" id="add-customer-btn"><i class="bi bi-plus-circle me-2"></i>افزودن مشتری جدید</button>
    </div>
    <div id="customers-filter-info"></div>
    <div class="row g-2 align-items-center mb-3">
        <div class="col-md-5">
            <div class="input-group search-box">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" class="form-control" id="customer-search" placeholder="جستجو در نام، آدرس، تلفن، کد ملی...">
            </div>
        </div>
        <div class="col-md-2">
            <select class="form-select pagination-limit-select" id="customers-limit-select" data-table="customers">
                <option value="10">10 ردیف</option>
                <option value="15">15 ردیف</option>
                <option value="25" selected>25 ردیف</option>
                <option value="50">50 ردیف</option>
                <option value="100">100 ردیف</option>
            </select>
        </div>
        <div class="col-md-3">
            <select class="form-select" id="customers-balance-filter">
                <option value="">همه وضعیت‌ها</option>
                <option value="positive">فقط بدهکار</option>
                <option value="negative">فقط بستانکار</option>
                <option value="settled">تسویه‌شده</option>
            </select>
        </div>
        <div class="col-md-2 text-end">
            <div class="dropdown">
                <button class="btn btn-outline-secondary dropdown-toggle w-100" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-layout-three-columns me-1"></i>ستون‌ها
                </button>
                <div class="dropdown-menu dropdown-menu-end p-2 shadow" id="customers-column-menu" style="min-width: 220px;"></div>
            </div>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-striped table-hover" id="customers-table">
            <thead><tr></tr></thead>
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
                    <div class="mb-3">
                        <label class="form-label">تلفن‌ها</label>
                        <div id="customer-phones-list"></div>
                        <button type="button" class="btn btn-sm btn-outline-primary mt-1" id="customer-add-phone-btn">
                            <i class="bi bi-plus-lg me-1"></i>افزودن تلفن
                        </button>
                    </div>
                    <div class="mb-3"><label for="customer-national-id" class="form-label">کد ملی</label><input type="text" class="form-control" id="customer-national-id"></div>
                    <div class="mb-3"><label for="customer-job" class="form-label">شغل <span class="text-muted fw-normal">(اختیاری)</span></label><input type="text" class="form-control" id="customer-job" placeholder="مثلاً پزشک، مهندس، مغازه‌دار"></div>
                    <div class="mb-3 form-check">
                        <input class="form-check-input" type="checkbox" id="customer-is-both">
                        <label class="form-check-label" for="customer-is-both">تأمین‌کننده هم هست (از این شخص خرید هم می‌کنیم)</label>
                        <div class="form-text">این شخص در هر دو بخش مشتریان و تأمین‌کنندگان نمایش داده می‌شود؛ حساب هر بخش جداگانه محاسبه می‌شود و مانده خالص هم کنار آن می‌آید. نام، تلفن، آدرس و شغل دو طرف همیشه یکسان می‌ماند.</div>
                    </div>
                    <div class="mb-3">
                        <label for="customer-initial-balance" class="form-label">مانده حساب اولیه (بدهکاری)</label>
                        <input type="text" class="form-control numeric-input" id="customer-initial-balance" value="0">
                        <div class="form-text">مبلغی که مشتری از قبل به شما بدهکار است را وارد کنید.</div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label for="customer-credit-limit" class="form-label">سقف اعتبار (نسیه)</label>
                            <input type="text" class="form-control numeric-input" id="customer-credit-limit" placeholder="بدون سقف">
                            <div class="form-text">خالی = بدون محدودیت</div>
                        </div>
                        <div class="col-6">
                            <label for="customer-group" class="form-label">گروه مشتری</label>
                            <select class="form-select" id="customer-group"><option value="">— بدون گروه —</option></select>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-success w-100">ذخیره</button>
                </form>
            </div>
        </div>
    </div>
</div>
