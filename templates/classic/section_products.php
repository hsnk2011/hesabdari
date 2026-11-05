<div class="tab-pane fade" id="products" role="tabpanel">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4><i class="bi bi-box-seam me-2"></i>مدیریت انبار و محصولات</h4>
        <div class="btn-group">
            <button class="btn btn-success" id="bulk-print-labels-btn" data-permission-action="manage_products" style="display:none;">
                <i class="bi bi-printer me-1"></i>چاپ لیبل (<span id="bulk-print-count">0</span>)
            </button>
            <button class="btn btn-outline-primary" id="bulk-transfer-products-btn" data-permission-action="manage_products" style="display:none;">
                <i class="bi bi-arrow-left-right me-1"></i>انتقال موجودی (<span id="bulk-transfer-count">0</span>)
            </button>
            <button class="btn btn-outline-success" id="print-all-labels-btn" data-permission-action="manage_products" title="چاپ لیبل تمام محصولات موجود در انبار">
                <i class="bi bi-printer me-1"></i>چاپ همه لیبل‌ها
            </button>
            <button class="btn btn-secondary" id="manage-warehouses-btn" data-permission-action="manage_products">
                <i class="bi bi-building me-1"></i>انبارها
            </button>
            <button class="btn btn-info" id="manage-product-options-btn" data-permission-action="manage_collections">
                <i class="bi bi-sliders me-1"></i>کلکسیون‌ها
            </button>
            <button class="btn btn-primary" id="add-product-btn" data-permission-action="manage_products">
                <i class="bi bi-plus-circle me-1"></i>محصول جدید
            </button>
        </div>
    </div>
    
    <div id="products-filter-info" class="mb-2"></div>
    
    <!-- فیلترهای پیشرفته (تک‌ردیف) -->
    <div class="card mb-3">
        <div class="card-body py-2">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <div class="flex-grow-1" style="min-width: 180px;">
                    <div class="input-group input-group-sm search-box">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" class="form-control" id="product-search" placeholder="جستجو در نام، کد طرح، کد رنگ، شناسه انبار...">
                    </div>
                </div>
                <select class="form-select form-select-sm" id="product-collection-filter" style="width: 140px;" title="کلکسیون">
                    <option value="">همه کلکسیون‌ها</option>
                </select>
                <select class="form-select form-select-sm" id="product-warehouse-filter" style="width: 125px;" title="انبار">
                    <option value="">همه انبارها</option>
                </select>
                <select class="form-select form-select-sm" id="product-stock-filter" style="width: 125px;" title="وضعیت موجودی">
                    <option value="in_stock" selected>موجود</option>
                    <option value="out_of_stock">ناموجود</option>
                    <option value="all">همه وضعیت‌ها</option>
                    <option value="sold">فروخته‌شده</option>
                    <option value="reserved">رزرو شده</option>
                    <option value="consignment_out">امانی خارج شده</option>
                    <option value="returned">مرجوع شده</option>
                    <option value="returned_to_supplier">مرجوع به تامین‌کننده</option>
                    <option value="damaged">آسیب‌دیده</option>
                    <option value="low_stock">کمبود موجودی</option>
                </select>
                <select class="form-select form-select-sm" id="product-size-filter" style="width: 140px;" title="متراژ / شکل فرش — از روی ابعاد موجودی انبار">
                    <option value="">همه متراژها</option>
                    <option value="kenareh" title="نسبت ضلع بلند به کوتاه حداقل ۱.۷۵">کناره</option>
                    <option value="0-2">تا ۲ متری</option>
                    <option value="0-3">تا ۳ متری</option>
                    <option value="0-4">زیر ۴ متری</option>
                    <option value="0-6">تا ۶ متری</option>
                    <option value="2-4">۲ تا ۴ متر</option>
                    <option value="4-6">۴ تا ۶ متر</option>
                    <option value="6-9">۶ تا ۹ متر</option>
                    <option value="6-12">۶ تا ۱۲ متر</option>
                    <option value="9-12">۹ تا ۱۲ متر</option>
                    <option value="12-">بالای ۱۲ متر</option>
                    <option value="custom">بازه دلخواه...</option>
                </select>
                <input type="text" inputmode="decimal" class="form-control form-control-sm" id="product-min-area" style="width: 80px;" placeholder="از (م²)" title="حداقل متراژ (مترمربع)">
                <input type="text" inputmode="decimal" class="form-control form-control-sm" id="product-max-area" style="width: 80px;" placeholder="تا (م²)" title="حداکثر متراژ (مترمربع)">
                <button type="button" class="btn btn-sm btn-outline-secondary" id="product-size-clear" title="پاک کردن فیلتر اندازه"><i class="bi bi-x-lg"></i></button>
                <div class="btn-group btn-group-sm" role="group">
                    <button type="button" class="btn btn-outline-secondary active" id="view-table-btn" title="نمای جدولی">
                        <i class="bi bi-list"></i>
                    </button>
                    <button type="button" class="btn btn-outline-secondary" id="view-card-btn" title="نمای کارتی">
                        <i class="bi bi-grid-3x3-gap"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- نمای جدولی -->
    <div id="products-table-view">
        <div class="table-responsive">
            <table class="table table-striped table-hover" id="products-table">
                <thead>
                    <tr>
                        <th style="width: 40px;"><input type="checkbox" id="select-all-products" title="انتخاب همه"></th>
                        <th style="width: 60px;">تصویر</th>
                        <th class="sortable-header" data-sort-by="name">نام محصول <span class="sort-indicator"></span></th>
                        <th class="sortable-header" data-sort-by="collectionName">کلکسیون<span class="sort-indicator"></span></th>
                        <th>انبارها</th>
                        <th class="sortable-header" data-sort-by="total_stock">موجودی <span class="sort-indicator"></span></th>
                        <th>توضیحات</th>
                        <th style="width: 140px;">عملیات</th>
                    </tr>
                </thead>
                <tbody id="products-table-body"></tbody>
            </table>
        </div>
    </div>
    
    <!-- نمای کارتی -->
    <div id="products-card-view" class="row g-3" style="display: none;"></div>
    
    <div class="d-flex justify-content-between align-items-center mt-3">
        <div class="d-flex align-items-center gap-2">
            <label class="text-muted small">نمایش:</label>
            <select class="form-select form-select-sm pagination-limit-select" id="products-limit-select" data-table="products" style="width: auto;">
                <option value="12">12</option>
                <option value="24" selected>24</option>
                <option value="48">48</option>
                <option value="96">96</option>
                <option value="-1">همه</option>
            </select>
        </div>
        <div class="pagination-container" data-table="products"></div>
    </div>
</div>

<!-- مودال محصول -->
<div class="modal fade" id="productModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-light">
                <h5 class="modal-title"><i class="bi bi-box me-2"></i>افزودن/ویرایش محصول</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                
                <ul class="nav nav-tabs mb-3" id="product-modal-tabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="product-main-tab" data-bs-toggle="tab" data-bs-target="#product-main-pane" type="button" role="tab">
                            <i class="bi bi-info-circle me-1"></i>مشخصات اصلی
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="product-images-tab" data-bs-toggle="tab" data-bs-target="#product-images-pane" type="button" role="tab">
                            <i class="bi bi-images me-1"></i>تصاویر
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="product-inventory-tab" data-bs-toggle="tab" data-bs-target="#product-inventory-pane" type="button" role="tab">
                            <i class="bi bi-boxes me-1"></i>موجودی انبار
                        </button>
                    </li>
                </ul>

                <div class="tab-content" id="product-modal-tab-content">
                    <!-- تب مشخصات اصلی -->
                    <div class="tab-pane fade show active" id="product-main-pane" role="tabpanel">
                        <form id="product-form">
                            <div class="alert alert-danger form-error" style="display:none;"></div>
                            <input type="hidden" id="product-id">
                            <div class="mb-3">
                                <label for="product-name" class="form-label">نام محصول <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="product-name" required>
                            </div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="product-collection-id" class="form-label">کلکسیون</label>
                                    <select class="form-select" id="product-collection-id">
                                        <option value="">-- بدون کلکسیون (محصول تکی) --</option>
                                    </select>
                                </div>
                                <div class="col-md-2 mb-3">
                                    <label for="product-design-code" class="form-label">کد طرح</label>
                                    <input type="text" class="form-control" id="product-design-code">
                                </div>
                                <div class="col-md-2 mb-3">
                                    <label for="product-colorway" class="form-label">کد رنگ</label>
                                    <input type="text" class="form-control" id="product-colorway">
                                </div>
                            </div>
                            <div class="row" id="product-price-wrapper">
                                <div class="col-md-4 mb-3">
                                    <label for="product-price-dimension" class="form-label">ابعاد محاسبه (از انبار)</label>
                                    <input type="text" class="form-control" id="product-price-dimension" readonly tabindex="-1" placeholder="بدون موجودی انبار">
                                    <div class="form-text" id="product-price-dimension-hint"></div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="product-price" class="form-label">قیمت متری</label>
                                    <input type="text" class="form-control numeric-input" id="product-price">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="product-price-total" class="form-label">قیمت کل</label>
                                    <input type="text" class="form-control numeric-input" id="product-price-total">
                                </div>
                                <div class="col-12 mb-2">
                                    <div class="form-text">برای محصولات بدون کلکسیون. ابعاد از موجودی انبار خوانده می‌شود. با داشتن ابعاد، تغییر قیمت متری یا کل یکدیگر را محاسبه می‌کند. ذخیره بر اساس قیمت متری است.</div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="product-weight" class="form-label">وزن (کیلوگرم)</label>
                                    <input type="number" class="form-control" id="product-weight" step="0.001" min="0">
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="product-description" class="form-label">توضیحات</label>
                                <textarea class="form-control" id="product-description" rows="2"></textarea>
                            </div>
                        </form>
                    </div>

                    <!-- تب تصاویر -->
                    <div class="tab-pane fade" id="product-images-pane" role="tabpanel">
                        <div class="mb-3">
                            <label class="form-label">آپلود تصویر</label>
                            <div id="product-image-dropzone" class="border rounded p-4 text-center bg-light" style="cursor: pointer; border-style: dashed !important;">
                                <i class="bi bi-cloud-arrow-up fs-1 text-muted"></i>
                                <p class="mb-0 text-muted">برای آپلود تصویر کلیک کنید یا فایل را بکشید و رها کنید</p>
                                <small class="text-muted">فرمت‌های مجاز: JPEG, PNG, WebP (حداکثر 5 مگابایت)</small>
                                <input type="file" id="product-image-input" accept="image/*" multiple style="display: none;">
                            </div>
                        </div>
                        <div id="product-images-gallery" class="row g-2">
                            <p class="text-center text-muted col-12">برای آپلود تصویر، ابتدا محصول را ذخیره کنید.</p>
                        </div>
                    </div>

                    <!-- تب موجودی انبار -->
                    <div class="tab-pane fade" id="product-inventory-pane" role="tabpanel">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="mb-0">لیست آیتم‌های موجود در انبار</h6>
                            <div>
                                <button type="button" class="btn btn-success btn-sm" id="print-selected-labels-btn" data-permission-action="manage_products">
                                    <i class="bi bi-printer"></i> چاپ لیبل
                                </button>
                                <button type="button" class="btn btn-primary btn-sm" id="add-inventory-item-btn" data-permission-action="manage_products">
                                    <i class="bi bi-plus-circle"></i> افزودن آیتم
                                </button>
                            </div>
                        </div>
                        <div id="serialized-items-container" class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                            <p class="text-center text-muted p-3">برای مشاهده لیست آیتم‌ها، ابتدا محصول را ذخیره کنید.</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
                <button type="button" class="btn btn-success" id="save-product-btn-modal"><i class="bi bi-check-lg me-1"></i>ذخیره</button>
            </div>
        </div>
    </div>
</div>

<!-- مودال مدیریت انبارها -->
<div class="modal fade" id="warehousesModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-light">
                <h5 class="modal-title"><i class="bi bi-building me-2"></i>مدیریت انبارها</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-tabs mb-3" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#warehouse-tab-list" type="button">انبارها</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="warehouse-tab-stock-btn" data-bs-toggle="tab" data-bs-target="#warehouse-tab-stock" type="button">موجودی و انتقال</button>
                    </li>
                </ul>
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="warehouse-tab-list">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <h6 class="mb-1">لیست انبارها</h6>
                                <small class="text-muted" id="warehouses-unassigned-note"></small>
                            </div>
                            <button type="button" class="btn btn-primary btn-sm" id="add-warehouse-btn">
                                <i class="bi bi-plus-circle me-1"></i>انبار جدید
                            </button>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover" id="warehouses-table">
                                <thead>
                                    <tr>
                                        <th>نام انبار</th>
                                        <th>آدرس</th>
                                        <th>تلفن</th>
                                        <th>فرش قابل‌انتقال</th>
                                        <th>پیش‌فرض</th>
                                        <th>داشبورد</th>
                                        <th>وضعیت</th>
                                        <th style="width: 180px;">عملیات</th>
                                    </tr>
                                </thead>
                                <tbody id="warehouses-table-body">
                                    <tr><td colspan="8" class="text-center text-muted">در حال بارگذاری...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="warehouse-tab-stock">
                        <div class="d-flex flex-wrap gap-2 align-items-center mb-2" id="warehouse-stock-summary"></div>
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                            <div class="flex-grow-1" style="min-width: 160px;">
                                <input type="text" class="form-control form-control-sm" id="warehouse-stock-search" placeholder="جستجو (شناسه انبار، نام، ابعاد) — مثلاً INV-001266">
                            </div>
                            <select class="form-select form-select-sm" id="warehouse-stock-filter" style="width: 140px;" title="انبار مبدأ"></select>
                            <select class="form-select form-select-sm" id="warehouse-stock-dest" style="width: 140px;" title="انبار مقصد"></select>
                            <button type="button" class="btn btn-primary btn-sm text-nowrap" id="warehouse-transfer-selected-btn">انتقال انتخاب‌شده</button>
                            <select class="form-select form-select-sm" id="warehouse-stock-size" style="width: 140px;" title="متراژ / شکل فرش">
                                <option value="">همه متراژها</option>
                                <option value="kenareh" title="نسبت ضلع بلند به کوتاه حداقل ۱.۷۵">کناره</option>
                                <option value="0-2">تا ۲ متری</option>
                                <option value="0-3">تا ۳ متری</option>
                                <option value="0-4">زیر ۴ متری</option>
                                <option value="0-6">تا ۶ متری</option>
                                <option value="2-4">۲ تا ۴ متر</option>
                                <option value="4-6">۴ تا ۶ متر</option>
                                <option value="6-9">۶ تا ۹ متر</option>
                                <option value="6-12">۶ تا ۱۲ متر</option>
                                <option value="9-12">۹ تا ۱۲ متر</option>
                                <option value="12-">بالای ۱۲ متر</option>
                                <option value="custom">بازه دلخواه...</option>
                            </select>
                            <input type="text" inputmode="decimal" class="form-control form-control-sm" id="warehouse-stock-min-area" style="width: 80px;" placeholder="از (م²)" title="حداقل متراژ (مترمربع)">
                            <input type="text" inputmode="decimal" class="form-control form-control-sm" id="warehouse-stock-max-area" style="width: 80px;" placeholder="تا (م²)" title="حداکثر متراژ (مترمربع)">
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="warehouse-size-clear" title="پاک کردن فیلتر اندازه"><i class="bi bi-x-lg"></i></button>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div class="form-check mb-0">
                                <input class="form-check-input" type="checkbox" id="warehouse-stock-select-all">
                                <label class="form-check-label small" for="warehouse-stock-select-all">انتخاب همه در این لیست</label>
                            </div>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="warehouse-move-all-btn">انتقال همهٔ این فیلتر</button>
                        </div>
                        <div class="table-responsive" style="max-height: 420px;">
                            <table class="table table-sm table-hover mb-0">
                                <thead class="table-light sticky-top">
                                    <tr>
                                        <th style="width: 36px;"></th>
                                        <th style="width: 52px;">تصویر</th>
                                        <th>شناسه</th>
                                        <th>محصول</th>
                                        <th>ابعاد</th>
                                        <th>انبار</th>
                                        <th>وضعیت</th>
                                    </tr>
                                </thead>
                                <tbody id="warehouse-stock-body">
                                    <tr><td colspan="7" class="text-center text-muted">برای دیدن فرش‌ها تب را باز کنید.</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <small class="text-muted d-block mt-2">فقط فرش‌های موجود یا مرجوع‌شده منتقل می‌شوند. فرش فروخته‌شده جابه‌جا نمی‌شود.</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- مودال افزودن/ویرایش انبار -->
<div class="modal fade" id="warehouseFormModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-light">
                <h5 class="modal-title" id="warehouseFormModalTitle"><i class="bi bi-building me-2"></i>افزودن انبار</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="warehouse-form">
                    <div class="alert alert-danger form-error" style="display:none;"></div>
                    <input type="hidden" id="warehouse-id">
                    <div class="mb-3">
                        <label for="warehouse-name" class="form-label">نام انبار <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="warehouse-name" required>
                    </div>
                    <div class="mb-3">
                        <label for="warehouse-address" class="form-label">آدرس</label>
                        <textarea class="form-control" id="warehouse-address" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="warehouse-phone" class="form-label">تلفن</label>
                        <input type="text" class="form-control" id="warehouse-phone">
                    </div>
                    <div class="row">
                        <div class="col-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="warehouse-is-default">
                                <label class="form-check-label" for="warehouse-is-default">انبار پیش‌فرض</label>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="warehouse-is-active" checked>
                                <label class="form-check-label" for="warehouse-is-active">فعال</label>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3 mt-3">
                        <label class="form-label">نوع انبار</label>
                        <select class="form-select" id="warehouse-type">
                            <option value="physical">فیزیکی</option>
                            <option value="order">انبار سفارش</option>
                        </select>
                        <div class="form-text">انبار سفارش برای فرش‌هایی است که هنوز وارد انبار فیزیکی نشده‌اند. بعد از فاکتور فروش هم تا زمان تحویل همین‌جا می‌مانند و هنگام تحویل می‌توان سایز را اصلاح کرد.</div>
                    </div>
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" id="warehouse-show-on-dashboard">
                        <label class="form-check-label" for="warehouse-show-on-dashboard">نمایش در داشبورد</label>
                        <div class="form-text">انبار سفارش به‌صورت خودکار در داشبورد نشان داده می‌شود.</div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
                <button type="button" class="btn btn-success" id="save-warehouse-btn"><i class="bi bi-check-lg me-1"></i>ذخیره</button>
            </div>
        </div>
    </div>
</div>

<!-- ویرایشگر تصویر توسط js/image-editor.js ساخته می‌شود -->

<!-- نمایش تصویر اصلی فرش (لیست محصولات / موجودی انبار / گالری) -->
<div class="modal fade" id="product-image-viewer-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content bg-dark text-white">
            <div class="modal-header border-0 py-2">
                <h6 class="modal-title text-truncate" id="product-image-viewer-title">تصویر فرش</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="بستن"></button>
            </div>
            <div class="modal-body text-center p-2" style="min-height: 200px;">
                <img id="product-image-viewer-img" src="" alt="تصویر اصلی فرش"
                     style="max-width: 100%; max-height: 70vh; object-fit: contain; border-radius: 6px; background: #111;">
            </div>
            <div class="modal-footer border-0 justify-content-between py-2">
                <small class="text-white-50" id="product-image-viewer-hint">تصویر اصلی — برای بستن کلیک بیرون یا دکمه ضربدر</small>
                <div class="d-flex gap-2">
                    <a id="product-image-viewer-open" href="#" target="_blank" rel="noopener" class="btn btn-sm btn-outline-light">
                        <i class="bi bi-box-arrow-up-right me-1"></i>باز کردن در تب جدید
                    </a>
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">بستن</button>
                </div>
            </div>
        </div>
    </div>
</div>
