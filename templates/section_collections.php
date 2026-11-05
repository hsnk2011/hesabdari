<h4>مدیریت کلکسیون‌ها و مشخصات</h4>
<div class="row">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">انواع مشخصات</h5>
                <button class="btn btn-secondary btn-sm" id="add-attribute-type-btn"><i class="bi bi-plus-circle"></i> افزودن نوع مشخصه</button>
            </div>
            <div class="card-body">
                <p class="text-muted small">در این بخش فیلدهایی که برای تعریف کلکسیون‌ها نیاز دارید را مشخص کنید (مانند شانه، تراکم، جنس نخ و...).</p>
                <ul class="list-group" id="attribute-types-list-container">
                    <li class="list-group-item">در حال بارگذاری...</li>
                </ul>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card">
             <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">کلکسیون‌ها</h5>
                 <button class="btn btn-primary btn-sm" id="add-collection-btn"><i class="bi bi-plus-circle"></i> افزودن کلکسیون</button>
            </div>
            <div class="card-body">
                <p class="text-muted small">در این بخش کلکسیون‌های مختلف محصولات خود را تعریف کرده و برای هرکدام مشخصات و قیمت پایه را وارد کنید.</p>
                <div id="collections-list-container">
                    <p>در حال بارگذاری کلکسیون‌ها...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="attributeTypeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="attributeTypeModalTitle">افزودن نوع مشخصه</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="attribute-type-form">
                    <div class="alert alert-danger form-error" style="display:none;"></div>
                    <input type="hidden" id="attribute-type-id">
                    <div class="mb-3">
                        <label for="attribute-type-name" class="form-label">نام مشخصه</label>
                        <input type="text" class="form-control" id="attribute-type-name" required placeholder="مثال: جنس نخ">
                    </div>
                    <div class="mb-3">
                        <label for="attribute-type-applicable-to" class="form-label">قابل استفاده برای</label>
                        <select id="attribute-type-applicable-to" class="form-select">
                            <option value="both">هردو (ماشینی و دستبافت)</option>
                            <option value="machine">فقط ماشینی</option>
                            <option value="handmade">فقط دستبافت</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-success w-100">ذخیره</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="collectionModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="collectionModalTitle">افزودن/ویرایش کلکسیون</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="collection-form">
                    <div class="alert alert-danger form-error" style="display:none;"></div>
                    <input type="hidden" id="collection-id">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="collection-name" class="form-label">نام کلکسیون</label>
                            <input type="text" class="form-control" id="collection-name" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="collection-price" class="form-label">قیمت پایه فروش (هر متر مربع)</label>
                            <input type="text" class="form-control numeric-input" id="collection-price" required value="0">
                        </div>
                    </div>
                     <div class="mb-3">
                        <label class="form-label">نوع</label>
                        <div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="collection-type" id="collection-type-machine" value="machine" checked>
                                <label class="form-check-label" for="collection-type-machine">ماشینی</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="collection-type" id="collection-type-handmade" value="handmade">
                                <label class="form-check-label" for="collection-type-handmade">دستبافت</label>
                            </div>
                        </div>
                    </div>
                    <hr>
                    <h6>مشخصات فنی این کلکسیون</h6>
                    <div class="row" id="collection-attributes-container">
                        <p class="text-muted">ابتدا نوع کلکسیون (ماشینی/دستبافت) را انتخاب کنید تا فیلدهای مربوطه نمایش داده شوند.</p>
                    </div>
                    <hr>
                    <h6>ابعاد موجود و قیمت‌های سفارشی</h6>
                    <p class="text-muted small">ابعادی را که این کلکسیون پشتیبانی می‌کند، انتخاب کنید. اگر برای یک بعد خاص قیمت سفارشی (متفاوت از محاسبه متراژ × قیمت پایه) دارید، آن را وارد کنید. در غیر این صورت، فیلد را خالی بگذارید.</p>
                    <div class="row" id="collection-dimensions-container"></div>
                    <button type="submit" class="btn btn-success w-100 mt-3">ذخیره کلکسیون</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Manage Collection Products Modal -->
<div class="modal fade" id="manageCollectionProductsModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="manageCollectionProductsModalTitle">مدیریت محصولات کلکسیون</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger form-error" style="display:none;"></div>
                <div class="row">
                    <!-- Products in Collection -->
                    <div class="col-md-6">
                        <div class="card h-100">
                            <div class="card-header bg-primary text-white">
                                <h6 class="mb-0"><i class="bi bi-box-seam me-2"></i>محصولات این کلکسیون</h6>
                            </div>
                            <div class="card-body p-0">
                                <div class="p-2 border-bottom">
                                    <input type="text" class="form-control form-control-sm" id="search-collection-products" placeholder="جستجو در محصولات کلکسیون...">
                                </div>
                                <div class="p-2 border-bottom bg-light">
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input" id="select-all-collection-products">
                                        <label class="form-check-label" for="select-all-collection-products">انتخاب همه</label>
                                    </div>
                                </div>
                                <div class="list-group list-group-flush" id="collection-products-list" style="max-height: 400px; overflow-y: auto;">
                                    <!-- Products will be loaded here -->
                                </div>
                            </div>
                            <div class="card-footer">
                                <button type="button" class="btn btn-danger btn-sm w-100" id="btn-remove-products-from-collection">
                                    <i class="bi bi-x-circle me-1"></i>حذف محصولات انتخاب شده از کلکسیون
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Available Products -->
                    <div class="col-md-6">
                        <div class="card h-100">
                            <div class="card-header bg-success text-white">
                                <h6 class="mb-0"><i class="bi bi-plus-circle me-2"></i>سایر محصولات</h6>
                            </div>
                            <div class="card-body p-0">
                                <div class="p-2 border-bottom">
                                    <input type="text" class="form-control form-control-sm" id="search-available-products" placeholder="جستجو در سایر محصولات...">
                                </div>
                                <div class="p-2 border-bottom bg-light">
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input" id="select-all-available-products">
                                        <label class="form-check-label" for="select-all-available-products">انتخاب همه</label>
                                    </div>
                                </div>
                                <div class="list-group list-group-flush" id="available-products-list" style="max-height: 400px; overflow-y: auto;">
                                    <!-- Products will be loaded here -->
                                </div>
                            </div>
                            <div class="card-footer">
                                <button type="button" class="btn btn-success btn-sm w-100" id="btn-add-products-to-collection">
                                    <i class="bi bi-plus-circle me-1"></i>افزودن محصولات انتخاب شده به کلکسیون
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="alert alert-info mt-3 mb-0">
                    <i class="bi bi-info-circle me-2"></i>
                    <strong>راهنما:</strong> با انتقال محصول به یک کلکسیون، قیمت آن محصول بر اساس قیمت پایه کلکسیون محاسبه خواهد شد.
                </div>
            </div>
        </div>
    </div>
</div>

