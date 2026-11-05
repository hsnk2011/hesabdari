<div class="modal fade" id="barcodePrintModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-qr-code me-2"></i>پیش‌نمایش لیبل‌ها</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="barcode-modal-body">
                <div class="text-center py-4">
                    <p>در حال تولید پیش‌نمایش لیبل...</p>
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <div class="text-muted small">
                    <i class="bi bi-info-circle me-1"></i>
                    در هر صفحه A4 تعداد 10 لیبل چاپ می‌شود (2 ستون × 5 ردیف)
                </div>
                <div>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">بستن</button>
                    <button type="button" class="btn btn-primary" id="execute-print-btn">
                        <i class="bi bi-printer me-2"></i>چاپ مستقیم
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="inventoryItemModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="inventoryItemModalTitle">افزودن آیتم به انبار</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="inventory-item-form">
                    <div class="alert alert-danger form-error" style="display:none;"></div>
                    <input type="hidden" id="inventory-item-id">
                    <input type="hidden" id="inventory-item-product-id">
                    <div class="mb-3" id="inventory-item-serial-wrapper" style="display:none;">
                        <label for="inventory-item-serial-id" class="form-label">شناسه آیتم</label>
                        <input type="text" class="form-control" id="inventory-item-serial-id" placeholder="شناسه یکتا (اختیاری - در صورت خالی بودن خودکار تولید می‌شود)">
                        <div class="form-text">می‌توانید شناسه را تغییر دهید. این شناسه روی لیبل چاپ می‌شود.</div>
                    </div>
                    <div class="mb-3">
                        <label for="inventory-item-dimensions" class="form-label">ابعاد</label>
                        <input type="text" class="form-control" id="inventory-item-dimensions" required placeholder="مثال: 2x3">
                    </div>
                    <div class="mb-3">
                        <label for="inventory-item-warehouse" class="form-label">انبار</label>
                        <select class="form-select" id="inventory-item-warehouse">
                            <option value="">-- بدون انبار --</option>
                        </select>
                    </div>
                    <div class="mb-3" id="inventory-item-count-wrapper">
                        <label for="inventory-item-count" class="form-label">تعداد</label>
                        <input type="number" class="form-control" id="inventory-item-count" min="1" value="1" required>
                    </div>
                    <button type="submit" class="btn btn-success w-100">ذخیره</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- مودال انتقال آیتم‌ها بین انبارها -->
<div class="modal fade" id="transferItemsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">انتقال آیتم‌ها به انبار دیگر</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="transfer-items-form">
                    <div class="alert alert-danger form-error" style="display:none;"></div>
                    <input type="hidden" id="transfer-item-ids">
                    <input type="hidden" id="transfer-product-id">
                    <div class="mb-3">
                        <label class="form-label">انتخاب: <span id="transfer-items-count" class="badge bg-primary">0</span></label>
                        <div class="form-text" id="transfer-items-hint">فقط فرش موجود یا مرجوع‌شده منتقل می‌شود.</div>
                    </div>
                    <div class="mb-3">
                        <label for="transfer-to-warehouse" class="form-label">انبار مقصد</label>
                        <select class="form-select" id="transfer-to-warehouse" required>
                            <option value="">-- انتخاب انبار --</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-arrow-left-right me-1"></i>انتقال</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="linkItemModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">اتصال آیتم انبار به فاکتور خرید</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>لطفا از لیست زیر، ردیف فاکتور خریدی که این کالا از طریق آن وارد انبار شده است را انتخاب کنید.</p>
                <form id="link-item-form">
                    <input type="hidden" id="link-item-id-input">
                    <div id="link-item-modal-body" class="p-3 bg-light rounded" style="max-height: 400px; overflow-y: auto;">
                        </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
                        <button type="submit" class="btn btn-primary">ثبت اتصال</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>