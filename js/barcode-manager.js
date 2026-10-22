// /js/barcode-manager.js
// سیستم چاپ لیبل - 15 لیبل در هر صفحه A4 (3 ستون × 5 ردیف)

const BarcodeManager = (function () {

    /**
     * تولید HTML یک لیبل
     */
    function generateLabelHtml(label) {
        if (!label) return '';

        // ایجاد SKU از اطلاعات موجود
        const sku = `${label.serialized_id}-${label.collection_name || ''}-${label.design_code || ''}`.replace(/ /g, '-').toUpperCase();
        // Absolute scan URL that also works when the app is served from a sub-directory.
        const scanUrl = label.scan_url || new URL(`scan.php?id=${encodeURIComponent(label.serialized_id)}`, window.location.href).href;

        // Brand + contact come from print settings (print_company_name / print_company_phone)
        const printSettings = (typeof UI !== 'undefined' && UI.getPrintSettings) ? UI.getPrintSettings() : {};
        const brandName = (printSettings.company_name || '').trim()
            || ((typeof App !== 'undefined' && App.getCache && App.getCache().publicSettings.app_title) || '').trim();
        const phones = (printSettings.company_phone || '')
            .split(/[،,]/)
            .map(p => p.trim())
            .filter(Boolean);
        const phonesHtml = phones.map(p => `<span>📞 ${p}</span>`).join('');

        return `
            <div class="label-item">
                <div class="label-header">
                    <span class="brand-name">${brandName}</span>
                </div>
                <div class="label-content">
                    <div class="label-info">
                        ${label.product_name ? `<div class="info-row product-title">${label.product_name}</div>` : ''}
                        ${label.collection_name ? `<div class="info-row"><span class="info-label">کلکسیون:</span> ${label.collection_name}</div>` : ''}
                        ${label.design_code ? `<div class="info-row"><span class="info-label">کد طرح:</span> ${label.design_code}</div>` : ''}
                        ${label.dimensions ? `<div class="info-row"><span class="info-label">ابعاد:</span> ${label.dimensions}</div>` : ''}
                        ${label.colorway ? `<div class="info-row"><span class="info-label">رنگ:</span> ${label.colorway}</div>` : ''}
                    </div>
                    <div class="label-qr">
                        <canvas class="qr-canvas" data-url="${scanUrl}"></canvas>
                    </div>
                </div>
                <div class="label-footer">
                    <div class="label-contact">
                        ${phonesHtml}
                    </div>
                    <div class="label-code">
                        <span class="serial-id">${label.serialized_id}#</span>
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * استایل‌های چاپ لیبل
     */
    function getPrintStyles() {
        return `
            @font-face {
                font-family: Vazirmatn;
                src: url('assets/fonts/Vazirmatn-Regular.woff2') format('woff2');
                font-weight: 400;
            }
            @font-face {
                font-family: Vazirmatn;
                src: url('assets/fonts/Vazirmatn-Bold.woff2') format('woff2');
                font-weight: 700;
            }
            
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            
            body {
                font-family: 'Vazirmatn', sans-serif;
                direction: rtl;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            @page {
                size: A4 portrait;
                margin: 3mm;
            }
            
            .labels-page {
                width: 204mm;
                min-height: 291mm;
                padding: 3mm;
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                grid-template-rows: repeat(5, 1fr);
                gap: 2mm;
                page-break-after: always;
            }
            
            .labels-page:last-child {
                page-break-after: auto;
            }
            
            .label-item {
                border: 1px solid #333;
                border-radius: 3px;
                padding: 2mm;
                display: flex;
                flex-direction: column;
                height: 56mm;
                width: 65mm;
                overflow: hidden;
                background: #fff;
            }
            
            .label-header {
                border-bottom: 1px solid #ddd;
                padding-bottom: 1.5mm;
                margin-bottom: 1.5mm;
                text-align: center;
            }
            
            .brand-name {
                font-size: 9pt;
                font-weight: 700;
                color: #000;
                display: block;
                line-height: 1.2;
            }
            
            .label-content {
                display: flex;
                flex: 1;
                gap: 1.5mm;
                align-items: stretch;
                min-height: 0;
            }
            
            .label-info {
                flex: 1;
                font-size: 6.5pt;
                line-height: 1.25;
                overflow: hidden;
                min-width: 0;
            }
            
            .product-title {
                font-weight: 700;
                font-size: 7.5pt;
                margin-bottom: 1mm;
                color: #000;
            }
            
            .info-row {
                margin-bottom: 0.4mm;
            }
            
            .info-label {
                font-weight: 600;
                color: #555;
            }
            
            .label-qr {
                width: 30mm;
                flex: 0 0 30mm;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            .qr-canvas {
                width: 28mm !important;
                height: 28mm !important;
                image-rendering: pixelated;
            }
            
            .label-footer {
                border-top: 1px solid #ddd;
                padding-top: 1.5mm;
                margin-top: auto;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            
            .label-contact {
                font-size: 6pt;
                color: #333;
                display: flex;
                flex-direction: column;
                gap: 0.5mm;
            }
            
            .label-code {
                font-size: 7pt;
                color: #666;
            }
            
            .serial-id {
                font-family: monospace;
                font-weight: 600;
            }
            
            /* لیبل خالی برای پر کردن صفحه */
            .label-empty {
                border: 1px dashed #ccc;
                background: #f9f9f9;
            }
            
            @media print {
                body {
                    background: white;
                }
                .labels-page {
                    margin: 0;
                    padding: 3mm;
                }
            }
        `;
    }

    /**
     * چاپ دسته‌ای لیبل‌ها
     */
    async function printBatch(serializedIds) {
        if (!serializedIds || serializedIds.length === 0) {
            UI.showError('هیچ آیتمی برای چاپ انتخاب نشده است.');
            return;
        }

        UI.showLoader();
        const result = await Api.call('get_batch_label_data', { ids: serializedIds });
        UI.hideLoader();

        if (!result || !result.labels) {
            UI.showError(result?.error || 'خطا در دریافت اطلاعات لیبل‌ها');
            return;
        }

        printLabelsDirectly(result.labels);
    }

    /**
     * چاپ مستقیم لیبل‌ها از آرایه داده‌ها (بدون فراخوانی API)
     */
    function printLabelsDirectly(labels) {
        if (!labels || labels.length === 0) {
            UI.showError('هیچ لیبلی برای چاپ وجود ندارد.');
            return;
        }

        const labelsPerPage = 15;
        const totalPages = Math.ceil(labels.length / labelsPerPage);
        
        let pagesHtml = '';
        
        for (let page = 0; page < totalPages; page++) {
            const startIdx = page * labelsPerPage;
            const pageLabels = labels.slice(startIdx, startIdx + labelsPerPage);
            
            let pageHtml = '<div class="labels-page">';
            
            // اضافه کردن لیبل‌ها
            pageLabels.forEach(label => {
                pageHtml += generateLabelHtml(label);
            });
            
            // پر کردن جاهای خالی با لیبل خالی (اختیاری)
            const emptyCount = labelsPerPage - pageLabels.length;
            for (let i = 0; i < emptyCount; i++) {
                pageHtml += '<div class="label-item label-empty"></div>';
            }
            
            pageHtml += '</div>';
            pagesHtml += pageHtml;
        }

        // باز کردن پنجره چاپ
        const printWindow = window.open('', '_blank');
        if (!printWindow) {
            UI.showError('پنجره چاپ مسدود شد. لطفاً popup blocker مرورگر را غیرفعال کنید.');
            return;
        }

        printWindow.document.write(`
            <!DOCTYPE html>
            <html lang="fa" dir="rtl">
            <head>
                <meta charset="UTF-8">
                <title>چاپ لیبل - ${labels.length} عدد</title>
                <style>${getPrintStyles()}</style>
                <script src="assets/js/qrious.min.js"></script>
            </head>
            <body>
                ${pagesHtml}
                <script>
                    window.onload = function() {
                        try {
                            // تولید QR Code ها
                            const canvases = document.querySelectorAll('.qr-canvas');
                            canvases.forEach(canvas => {
                                if (window.QRious) {
                                    new QRious({
                                        element: canvas,
                                        value: canvas.dataset.url,
                                        size: 320,
                                        padding: 2,
                                        level: 'M',
                                        background: '#ffffff',
                                        foreground: '#000000'
                                    });
                                }
                            });
                            
                            // چاپ با تأخیر برای رندر QR
                            setTimeout(() => {
                                window.print();
                            }, 400);
                            
                        } catch(e) {
                            console.error("خطا در تولید QR Code:", e);
                            window.print();
                        }
                    };
                    
                    window.onafterprint = function() {
                        window.close();
                    };
                </script>
            </body>
            </html>
        `);
        
        printWindow.document.close();
    }

    /**
     * پیش‌نمایش لیبل‌ها در مودال
     */
    async function previewLabels(serializedIds) {
        if (!serializedIds || serializedIds.length === 0) {
            UI.showError('هیچ آیتمی انتخاب نشده است.');
            return;
        }

        UI.showLoader();
        const result = await Api.call('get_batch_label_data', { ids: serializedIds });
        UI.hideLoader();

        if (!result || !result.labels) {
            UI.showError(result?.error || 'خطا در دریافت اطلاعات');
            return;
        }

        let previewHtml = `<div class="label-preview-container">`;
        result.labels.forEach(label => {
            previewHtml += generateLabelHtml(label);
        });
        previewHtml += `</div>`;

        $('#barcode-modal-body').html(previewHtml);
        $('#barcodePrintModal').modal('show');
        
        // تولید QR برای پیش‌نمایش
        setTimeout(() => {
            $('#barcode-modal-body .qr-canvas').each(function() {
                if (window.QRious) {
                    new QRious({
                        element: this,
                        value: $(this).data('url'),
                        size: 180,
                        padding: 2,
                        level: 'M'
                    });
                }
            });
        }, 100);
    }

    function init() {
        // Event handlers در entity-manager.js هندل می‌شوند
    }

    return {
        init,
        printBatch,
        printLabelsDirectly,
        previewLabels,
        generateLabelHtml
    };

})();

// Expose for invoice preview popup (window.opener.BarcodeManager)
window.BarcodeManager = BarcodeManager;