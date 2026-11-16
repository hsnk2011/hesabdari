<div class="tab-pane fade" id="customers" role="tabpanel">
    <div class="ui-page-head">
        <div>
            <h4 class="ui-page-title">مشتریان</h4>
            <p class="ui-page-sub">مانده حساب، فاکتورها و پرداخت‌های هر مشتری</p>
        </div>
        <div class="ui-page-actions">
            <button class="btn btn-primary" id="add-customer-btn"><i class="bi bi-plus-lg"></i>مشتری جدید</button>
        </div>
    </div>

    <div class="ui-panel">
        <div class="ui-toolbar">
            <div class="ui-search search-box">
                <i class="bi bi-search"></i>
                <input type="text" class="form-control" id="customer-search" placeholder="جستجو در نام، آدرس، تلفن، کد ملی...">
            </div>
            <div class="ui-filter">
                <label class="ui-filter-label" for="customers-balance-filter"><i class="bi bi-funnel"></i></label>
                <select class="form-select" id="customers-balance-filter">
                    <option value="">همه وضعیت‌ها</option>
                    <option value="positive">فقط بدهکار</option>
                    <option value="negative">فقط بستانکار</option>
                    <option value="settled">تسویه‌شده</option>
                </select>
            </div>
            <div class="ui-filter">
                <label class="ui-filter-label" for="customers-channel-filter"><i class="bi bi-shop"></i></label>
                <select class="form-select" id="customers-channel-filter" title="نوع فروش">
                    <option value="">همه انواع</option>
                    <option value="retail">خانگی</option>
                    <option value="trade">همکار/عمده</option>
                    <option value="internal">داخلی</option>
                </select>
            </div>
            <div class="ui-toolbar-end">
                <label class="ui-limit" for="customers-limit-select">نمایش
                    <select class="form-select pagination-limit-select form-select-sm" id="customers-limit-select" data-table="customers">
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
                    <div class="dropdown-menu dropdown-menu-end p-2 shadow" id="customers-column-menu" style="min-width: 220px;"></div>
                </div>
            </div>
        </div>
        <div id="customers-filter-info" class="ui-filter-info"></div>
        <div class="table-responsive">
            <table class="table table-hover" id="customers-table">
                <thead><tr></tr></thead>
                <tbody id="customers-table-body"></tbody>
            </table>
        </div>
        <div class="pagination-container" data-table="customers"></div>
    </div>
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
                    <div class="mb-3">
                        <label for="customer-sale-channel" class="form-label">نوع فروش</label>
                        <select class="form-select" id="customer-sale-channel">
                            <option value="retail">خانگی</option>
                            <option value="trade">همکار/عمده</option>
                            <option value="internal">داخلی (درون‌گروهی — در آمار فروش نمی‌آید)</option>
                        </select>
                        <div class="form-text">فاکتورهای تازهٔ این مشتری با همین نوع ثبت می‌شوند و در فرم فاکتور قابل تغییرند.</div>
                        <div class="form-check mt-1 customer-channel-apply-wrap" style="display:none;">
                            <input class="form-check-input" type="checkbox" id="customer-channel-apply" checked>
                            <label class="form-check-label small" for="customer-channel-apply">فاکتورهای قبلی این مشتری هم همین نوع شوند</label>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-success w-100">ذخیره</button>
                </form>
            </div>
        </div>
    </div>
</div>
