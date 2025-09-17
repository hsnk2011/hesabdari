<div class="tab-pane fade" id="products" role="tabpanel">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4>مدیریت انبار و محصولات</h4>
        <button class="btn btn-primary" id="add-product-btn"><i class="bi bi-plus-circle me-2"></i>افزودن محصول جدید</button>
    </div>
    <div class="input-group search-box">
        <span class="input-group-text"><i class="bi bi-search"></i></span><input type="text" class="form-control" id="product-search" placeholder="جستجو در نام، ابعاد، توضیحات...">
    </div>
    <div class="table-responsive">
        <table class="table table-striped table-hover" id="products-table">
            <thead>
                <tr>
                    <th class="sortable-header" data-sort-by="name">نام طرح <span class="sort-indicator"></span></th>
                    <th>توضیحات</th>
                    <th class="sortable-header" data-sort-by="total_stock">موجودی ابعاد <span class="sort-indicator"></span></th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody id="products-table-body"></tbody>
        </table>
    </div>
    <div class="pagination-container" data-table="products"></div>
</div>

<div class="modal fade" id="productModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">افزودن/ویرایش محصول</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="product-form">
                    <div class="alert alert-danger form-error" style="display:none;"></div>
                    <input type="hidden" id="product-id">
                    <div class="mb-3">
                        <label for="product-name" class="form-label">نام طرح</label>
                        <input type="text" class="form-control" id="product-name" required>
                    </div>
                    <div class="mb-3">
                        <label for="product-description" class="form-label">توضیحات</label>
                        <textarea class="form-control" id="product-description" rows="2"></textarea>
                    </div>
                    <hr>
                    <h6>موجودی و ابعاد</h6>
                    <div id="product-stock-container">
                    </div>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="add-product-stock-row">
                        <i class="bi bi-plus"></i> افزودن ردیف ابعاد
                    </button>
                    <button type="submit" class="btn btn-success w-100 mt-3">ذخیره</button>
                </form>
            </div>
        </div>
    </div>
</div>