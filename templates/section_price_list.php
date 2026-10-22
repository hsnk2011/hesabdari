<div class="tab-pane fade" id="price-list" role="tabpanel">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h4 class="mb-1">لیست قیمت فروش کلکسیون‌ها (بر اساس هر متر مربع)</h4>
            <small class="text-muted" id="price-list-last-updated"></small>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <button class="btn btn-warning btn-sm" id="bulk-price-update-btn" data-permission-action="manage_collections">
                <i class="bi bi-percent me-1"></i>افزایش قیمت دسته‌ای
            </button>
            <button class="btn btn-success" id="print-price-list-btn" data-permission-action="view_price_list">
                <i class="bi bi-printer me-2"></i>چاپ لیست قیمت
            </button>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <p class="text-muted">
                در این جدول، قیمت فروش محاسبه‌شده برای هر سایز استاندارد بر اساس قیمت هر متر مربع که برای کلکسیون تعریف کرده‌اید، نمایش داده می‌شود.
                برای تغییر قیمت، روی آیکون مداد کنار قیمت هر متر یا قیمت کل هر سایز کلیک کنید؛ می‌توانید قیمت متری یا قیمت کل را وارد کنید.
                <span class="text-danger">محصولات بدون قیمت یا با قیمت صفر با رنگ قرمز مشخص شده‌اند.</span>
            </p>
            <div class="table-responsive" id="price-list-container">
                <div class="text-center p-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">در حال بارگذاری...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Bulk Price Update -->
<div class="modal fade" id="bulkPriceUpdateModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">افزایش قیمت با درصد</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">نوع</label>
                    <select class="form-select" id="bulk-price-type">
                        <option value="collection">کلکسیون‌ها</option>
                        <option value="product">محصولات تکی</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">انتخاب موارد</label>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="bulk-price-select-all">
                        <label class="form-check-label" for="bulk-price-select-all">انتخاب همه</label>
                    </div>
                    <div id="bulk-price-items-list" class="border rounded p-2" style="max-height: 200px; overflow-y: auto;">
                        <!-- Items will be loaded here -->
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">درصد تغییر قیمت</label>
                    <div class="input-group">
                        <input type="number" class="form-control" id="bulk-price-percentage" placeholder="مثال: 10" step="0.1">
                        <span class="input-group-text">%</span>
                    </div>
                    <small class="text-muted">عدد مثبت = افزایش، عدد منفی = کاهش</small>
                </div>
                <div class="alert alert-info small mb-0">
                    <i class="bi bi-info-circle me-1"></i>
                    فقط مواردی که قیمت آنها تعیین شده باشد (غیر صفر) آپدیت می‌شوند.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
                <button type="button" class="btn btn-warning" id="apply-bulk-price-update">
                    <i class="bi bi-check-lg me-1"></i>اعمال تغییرات
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Set single item price (meter or total) -->
<div class="modal fade" id="setPriceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">تغییر قیمت</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="fw-bold mb-3" id="set-price-item-name"></p>
                <input type="hidden" id="set-price-id">
                <input type="hidden" id="set-price-type">
                <div class="mb-3">
                    <label class="form-label" for="set-price-meter">قیمت هر متر مربع</label>
                    <div class="input-group">
                        <input type="text" class="form-control numeric-input text-start" id="set-price-meter" inputmode="decimal" placeholder="مثال: 1,500,000">
                        <span class="input-group-text" data-currency-label>تومان</span>
                    </div>
                </div>
                <div class="mb-3" id="set-price-dim-wrapper">
                    <label class="form-label" for="set-price-dimension">ابعاد (برای محاسبه قیمت کل)</label>
                    <select class="form-select" id="set-price-dimension"></select>
                </div>
                <div class="mb-0">
                    <label class="form-label" for="set-price-total">قیمت کل این ابعاد</label>
                    <div class="input-group">
                        <input type="text" class="form-control numeric-input text-start" id="set-price-total" inputmode="decimal" placeholder="مثال: 9,000,000">
                        <span class="input-group-text" data-currency-label>تومان</span>
                    </div>
                    <small class="text-muted">اگر قیمت کل را وارد کنید، قیمت هر متر از روی ابعاد انتخاب‌شده حساب می‌شود. ذخیره بر اساس قیمت هر متر است.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
                <button type="button" class="btn btn-primary" id="apply-set-price">
                    <i class="bi bi-check-lg me-1"></i>ذخیره قیمت
                </button>
            </div>
        </div>
    </div>
</div>