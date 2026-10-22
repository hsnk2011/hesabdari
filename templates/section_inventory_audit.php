<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">ابزار تطبیق انبار با فاکتورهای خرید</h5>
        <div class="d-flex align-items-center">
            <div class="btn-group me-2" role="group" aria-label="Status Mode">
                    <button type="button" class="btn btn-sm btn-outline-secondary audit-status-btn" data-status="all">
                        همه وضعیت‌ها
                    </button>
                <button type="button" class="btn btn-sm btn-outline-secondary audit-status-btn active" data-status="in_stock">
                    موجودی انبار
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary audit-status-btn" data-status="sold">
                    اقلام فروخته‌شده
                </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary audit-status-btn" data-status="reserved">
                        رزرو شده
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary audit-status-btn" data-status="consignment_out">
                        امانی
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary audit-status-btn" data-status="returned">
                        مرجوع شده
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary audit-status-btn" data-status="damaged">
                        آسیب‌دیده
                    </button>
            </div>
            <button class="btn btn-sm btn-success" id="auto-link-btn" data-permission-action="manage_products">
                <i class="bi bi-magic me-2"></i>اتصال خودکار موارد ساده
            </button>
            <button class="btn btn-sm btn-primary ms-2" id="refresh-audit-list-btn">
                <i class="bi bi-arrow-clockwise me-2"></i>بارگذاری مجدد لیست
            </button>
        </div>
    </div>
    <div class="card-body">
        <p class="text-muted">
            این ابزار آیتم‌های انبار را که به فاکتور خرید متصل نیستند نشان می‌دهد.
            <strong>شناسه انبار</strong> همان فیلد <code>inventory_items.serial_id</code> است
            (مثل <code>INV-001266</code>) که در تب «موجودی انبار» محصول ستون «شناسه» نمایش داده می‌شود —
            نه کد محصول و نه شناسه رکورد انبار فیزیکی (warehouse_id).
            اگر فاکتور خرید وجود دارد ولی اتصال ممکن نیست، معمولاً آن فاکتور قبلاً به آیتم دیگری متصل شده و این رکورد تکراری است.
        </p>
        <div class="table-responsive" id="unlinked-items-container">
            <p class="text-center p-5">در حال بارگذاری لیست آیتم‌ها...</p>
        </div>
    </div>
</div>