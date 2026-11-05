// /js/inventory-audit-manager.js

const InventoryAuditManager = (function () {
    let unlinkedItemsCache = [];
    let currentStatus = 'in_stock';

    const statusLabels = {
        in_stock: 'موجود',
        sold: 'فروخته‌شده',
        reserved: 'رزرو',
        consignment_out: 'امانی',
        returned: 'مرجوع',
        returned_to_supplier: 'مرجوع به تامین‌کننده',
        damaged: 'آسیب‌دیده'
    };

    function renderList(items) {
        const container = $('#unlinked-items-container').empty();
        if (!items || items.length === 0) {
            container.html('<div class="alert alert-success text-center">هیچ آیتم نیازمند تطبیق یافت نشد.</div>');
            return;
        }

        const table = $(`<table class="table table-sm table-hover">
            <thead>
                <tr>
                    <th>محصول</th>
                    <th>شناسه انبار</th>
                    <th>سریال</th>
                    <th>ابعاد</th>
                    <th>وضعیت</th>
                    <th>تاریخ ورود</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>`);
        const tbody = table.find('tbody');

        items.forEach(item => {
            const statusLabel = statusLabels[item.status] || item.status || '—';
            const serial = item.serial_id ? UI.escapeHtml(item.serial_id) : '—';
            const row = $(`<tr>
                <td>
                    <div>${UI.escapeHtml(item.product_name)}</div>
                    <small class="text-muted">کد محصول: ${item.product_id}</small>
                </td>
                <td><strong class="text-primary">${item.inventory_item_id}</strong></td>
                <td>${serial}</td>
                <td>${UI.escapeHtml(item.dimensions || '—')}</td>
                <td><span class="badge bg-secondary">${statusLabel}</span></td>
                <td>${UI.gregorianToPersian(item.entry_date)}</td>
                <td>
                    <button class="btn btn-sm btn-info btn-show-matches"
                        data-item-id="${item.inventory_item_id}"
                        data-product-id="${item.product_id}"
                        data-dimensions="${UI.escapeHtml(item.dimensions || '')}">
                        <i class="bi bi-link-45deg me-2"></i>اتصال به فاکتور
                    </button>
                </td>
            </tr>`);
            tbody.append(row);
        });

        container.append(table);
    }

    async function showMatchesModal(inventoryItemId, productId, dimensions) {
        const modal = $('#linkItemModal');
        const body = $('#link-item-modal-body').html('<p class="text-center">در حال جستجوی فاکتورهای خرید مرتبط...</p>');
        modal.modal('show');

        $('#link-item-id-input').val(inventoryItemId);

        const matches = await Api.call('get_possible_purchase_matches', { product_id: productId, dimensions });

        if (!matches || matches.length === 0) {
            body.html(`
                <div class="alert alert-warning mb-0">
                    <strong>فاکتور خرید منطبق یافت نشد.</strong>
                    <ul class="mb-0 mt-2 small">
                        <li>ممکن است فاکتور با <strong>کد محصول دیگر</strong> ثبت شده باشد.</li>
                        <li>یا فاکتور خرید منطبق قبلاً به آیتم انبار دیگری (مثلاً شناسه ۱۰۹۳ در کاردکس) متصل شده و ظرفیت پر است.</li>
                        <li>اگر این آیتم تکراری است، آن را از بخش محصولات حذف کنید.</li>
                    </ul>
                </div>
            `);
            return;
        }

        const available = matches.filter(m => (m.available_slots || 0) > 0);
        const full = matches.filter(m => (m.available_slots || 0) <= 0);

        let html = `<p class="small text-muted mb-2">اتصال برای <strong>شناسه انبار ${inventoryItemId}</strong> — ابعاد: <strong>${UI.escapeHtml(dimensions || '—')}</strong></p>`;

        if (available.length > 0) {
            html += '<p class="fw-bold small mb-1">فاکتورهای قابل اتصال:</p>';
            available.forEach(match => {
                const escapedSupplierName = UI.escapeHtml(match.supplier_name || 'نامشخص');
                const invDims = match.invoice_dimensions ? ` — ابعاد فاکتور: ${UI.escapeHtml(match.invoice_dimensions)}` : '';
                html += `
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="purchaseLink" id="link-${match.purchase_invoice_item_id}" value="${match.purchase_invoice_item_id}">
                        <label class="form-check-label" for="link-${match.purchase_invoice_item_id}">
                            <strong>فاکتور خرید #${match.purchase_invoice_id}</strong> (${escapedSupplierName})
                            — ${UI.gregorianToPersian(match.date)} — ${UI.formatCurrency(match.unitPrice)}
                            ${invDims}
                            <span class="badge bg-success ms-1">${match.available_slots} ظرفیت خالی</span>
                        </label>
                    </div>
                `;
            });
        }

        if (full.length > 0) {
            html += '<hr><p class="fw-bold small text-muted mb-1">فاکتورهایی که ظرفیتشان پر است (قبلاً متصل شده‌اند):</p>';
            full.forEach(match => {
                const escapedSupplierName = UI.escapeHtml(match.supplier_name || 'نامشخص');
                const linkedIds = match.linked_inventory_ids ? ` → شناسه انبار: ${UI.escapeHtml(match.linked_inventory_ids)}` : '';
                html += `
                    <div class="form-check mb-2 opacity-75">
                        <input class="form-check-input" type="radio" disabled>
                        <label class="form-check-label text-muted">
                            فاکتور خرید #${match.purchase_invoice_id} (${escapedSupplierName})
                            — ${UI.gregorianToPersian(match.date)}
                            <span class="badge bg-secondary ms-1">ظرفیت پر (${match.linked_count}/${match.quantity})</span>
                            ${linkedIds}
                        </label>
                    </div>
                `;
            });
        }

        if (available.length === 0) {
            html += '<div class="alert alert-info mt-2 mb-0 small">فاکتور خرید مرتبط وجود دارد اما همه ظرفیت‌ها پر شده‌اند. این آیتم انبار احتمالاً <strong>رکورد تکراری</strong> است و باید حذف شود.</div>';
        }

        body.html(html);
    }

    async function handleLinkSubmit(e) {
        e.preventDefault();
        const inventoryItemId = $('#link-item-id-input').val();
        const purchaseInvoiceItemId = $('input[name="purchaseLink"]:checked').val();

        if (!purchaseInvoiceItemId) {
            UI.showError('لطفاً یک ردیف فاکتور قابل اتصال را انتخاب کنید.');
            return;
        }

        UI.showLoader();
        const result = await Api.call('link_inventory_item', {
            inventory_item_id: inventoryItemId,
            purchase_invoice_item_id: purchaseInvoiceItemId
        });
        UI.hideLoader();

        if (result?.success) {
            UI.showSuccess('آیتم با موفقیت به فاکتور خرید متصل شد.');
            $('#linkItemModal').modal('hide');
            load();
        }
    }

    async function handleAutoLink() {
        UI.confirmAction(
            'این عملیات به صورت خودکار کالاهایی را که فاکتور خرید منطبق با ظرفیت خالی دارند متصل می‌کند. آیا ادامه می‌دهید؟',
            async (confirmed) => {
                if (confirmed) {
                    UI.showLoader();
                    const result = await Api.call('auto_link_inventory_items');
                    UI.hideLoader();
                    if (result?.success) {
                        UI.showSuccess(result.message || 'عملیات اتصال خودکار انجام شد.');
                        load();
                    }
                }
            }
        );
    }

    async function load() {
        UI.showLoader();
        const items = await Api.call('get_unlinked_inventory_items', { status: currentStatus });
        UI.hideLoader();
        if (items) {
            unlinkedItemsCache = items;
            renderList(items);
        }
        const autoLinkBtn = document.getElementById('auto-link-btn');
        if (autoLinkBtn) autoLinkBtn.style.display = currentStatus === 'in_stock' ? '' : 'none';
    }

    function setMode(status) {
        if (['all', 'in_stock', 'sold', 'reserved', 'consignment_out', 'returned', 'returned_to_supplier', 'damaged'].includes(status)) {
            currentStatus = status;
            $('.audit-status-btn').removeClass('active');
            $(`.audit-status-btn[data-status="${status}"]`).addClass('active');
        }
    }

    function init() {
        $('body').on('click', '.btn-show-matches', function () {
            const button = $(this);
            showMatchesModal(button.data('item-id'), button.data('product-id'), button.data('dimensions'));
        });

        $('body').on('click', '.audit-status-btn', function () {
            setMode($(this).data('status'));
            load();
        });

        $('#link-item-form').on('submit', handleLinkSubmit);
        $('#refresh-audit-list-btn').on('click', load);
        $('#auto-link-btn').on('click', handleAutoLink);
    }

    return {
        init,
        load,
        setMode
    };
})();
