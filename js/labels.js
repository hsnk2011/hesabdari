/* /js/labels.js */
// Requires JsBarcode and QRious (via CDN in index.html or bundle)
async function fetchLabel() {
    const id = document.getElementById('label-product-id').value.trim();
    if (!id) return;

    const json = await Api.call('label_data', { id: parseInt(id) });

    if (json && json.label) {
        document.getElementById('lbl-name').textContent = json.label.name || '';
        document.getElementById('lbl-sku').textContent = json.label.sku || '';
        document.getElementById('lbl-specs').textContent = `سایز: ${json.label.size || '-'} | شانه: ${json.label.shaneh || '-'} | تراکم: ${json.label.density || '-'}`;
        if (window.JsBarcode) JsBarcode('#barcode', json.label.sku || String(json.label.id), { displayValue: true });
        if (window.QRious) new QRious({ element: document.getElementById('qrcode'), value: json.label.sku || String(json.label.id), size: 128 });
    } else if (json && json.error) {
        UI.showError(json.error);
    }
}

async function transferStock() {
    const body = {
        product_id: parseInt(document.getElementById('wh-product-id').value || '0'),
        from_warehouse_id: parseInt(document.getElementById('wh-from').value || '0'),
        to_warehouse_id: parseInt(document.getElementById('wh-to').value || '0'),
        quantity: parseInt(document.getElementById('wh-qty').value || '0'),
        to_bin: document.getElementById('wh-bin').value || ''
    };

    const json = await Api.call('transfer_stock', body);

    if (json) {
        if (json.success) {
            UI.showSuccess('موجودی با موفقیت منتقل شد.');
        } else if (json.error) {
            UI.showError(json.error);
        }
        document.getElementById('wh-result').textContent = JSON.stringify(json, null, 2);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const btn = document.getElementById('btn-fetch-label');
    if (btn) btn.addEventListener('click', fetchLabel);
    const bt2 = document.getElementById('btn-transfer');
    if (bt2) bt2.addEventListener('click', transferStock);
});