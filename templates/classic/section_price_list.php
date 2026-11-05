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
            <button class="btn btn-info btn-sm" id="stock-share-btn" data-permission-action="manage_collections">
                <i class="bi bi-share me-1"></i>لینک موجودی برای همکار
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
                <input type="hidden" id="set-price-field" value="sale">
                <div class="mb-3">
                    <label class="form-label" for="set-price-meter" id="set-price-meter-label">قیمت هر متر مربع</label>
                    <div class="input-group">
                        <input type="text" class="form-control numeric-input text-start" id="set-price-meter" inputmode="decimal" placeholder="مثال: 1,500,000">
                        <span class="input-group-text" data-currency-label>تومان</span>
                    </div>
                </div>
                <p class="small text-muted d-none" id="set-price-partner-hint">خالی یا صفر = قیمت فروش منهای درصد همکاری لینک.</p>
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

<!-- Modal: Stock share links for colleagues (public live stock list, stock.php) -->
<div class="modal fade" id="stockShareModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-share me-1"></i>لینک موجودی برای همکار</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">لینک زنده است: همکار هر بار باز کند موجودی همان لحظه را با عکس و قیمت می‌بیند. لینک منقضی نمی‌شود تا خودتان باطلش کنید. قیمت خرید، تأمین‌کننده و نام انبار هرگز در لینک نمی‌آید.</p>
                <form id="stock-share-form" autocomplete="off">
                    <input type="hidden" id="stock-share-id">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="stock-share-title">نام لینک (برای چه کسی)</label>
                            <input type="text" class="form-control" id="stock-share-title" maxlength="255" placeholder="مثال: آقای رضایی — فروشگاه قم">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label d-block">قیمت</label>
                            <div class="btn-group" role="group">
                                <input type="radio" class="btn-check" name="stock_share_price_mode" id="stock-share-mode-sale" value="sale" checked>
                                <label class="btn btn-outline-primary" for="stock-share-mode-sale">قیمت فروش</label>
                                <input type="radio" class="btn-check" name="stock_share_price_mode" id="stock-share-mode-partner" value="partner">
                                <label class="btn btn-outline-primary" for="stock-share-mode-partner">قیمت همکاری</label>
                                <input type="radio" class="btn-check" name="stock_share_price_mode" id="stock-share-mode-none" value="none">
                                <label class="btn btn-outline-primary" for="stock-share-mode-none">بدون قیمت</label>
                            </div>
                        </div>
                        <div class="col-md-6" id="stock-share-pct-wrap">
                            <label class="form-label" for="stock-share-pct">درصد همکاری</label>
                            <div class="input-group" style="max-width: 220px;">
                                <input type="number" class="form-control" id="stock-share-pct" min="0" max="100" step="0.5">
                                <span class="input-group-text">٪ کمتر</span>
                            </div>
                            <small class="text-muted">برای کلکسیون‌ها و محصولاتی که «قیمت همکاری هر متر» اختصاصی ندارند.</small>
                        </div>
                        <div class="col-12">
                            <label class="form-label">انبارها <small class="text-muted">(هیچ‌کدام = همه)</small></label>
                            <div class="d-flex flex-wrap gap-1" id="stock-share-warehouses"></div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">کلکسیون‌ها / محصولات تکی <small class="text-muted">(هیچ‌کدام = همه)</small></label>
                            <div class="d-flex flex-wrap gap-1 border rounded p-2" id="stock-share-collections" style="max-height: 160px; overflow-y: auto;"></div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">سایزها <small class="text-muted">(هیچ‌کدام = همه)</small></label>
                            <div class="d-flex flex-wrap gap-1 border rounded p-2" id="stock-share-sizes" style="max-height: 160px; overflow-y: auto;"></div>
                        </div>
                        <div class="col-12 d-flex flex-wrap gap-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="stock-share-show-serials" checked>
                                <label class="form-check-label" for="stock-share-show-serials">نمایش شناسهٔ انبار هر تخته</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="stock-share-show-counts" checked>
                                <label class="form-check-label" for="stock-share-show-counts">نمایش تعداد موجود</label>
                            </div>
                        </div>
                        <div class="col-12 d-flex gap-2">
                            <button type="submit" class="btn btn-primary" id="stock-share-save"><i class="bi bi-link-45deg me-1"></i>ساخت لینک</button>
                            <button type="button" class="btn btn-outline-secondary d-none" id="stock-share-cancel-edit">انصراف از ویرایش</button>
                        </div>
                    </div>
                </form>
                <hr>
                <h6 class="mb-2">لینک‌های ساخته‌شده</h6>
                <div class="table-responsive" id="stock-share-links"></div>
            </div>
        </div>
    </div>
</div>
