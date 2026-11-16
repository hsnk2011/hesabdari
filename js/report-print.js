// /js/report-print.js
// Shared print form for the reports page. Every report prints through one shell
// (letterhead, title, period, options toolbar, signatures, page numbers) instead of
// sending the on-screen page to the printer:
//  - DataTables print every filtered row in the current sort order, not one page;
//  - tabs print one after another under their own heading, collapsed details open;
//  - buttons, search boxes and paging controls are dropped.
// Sales/purchase invoice lists get their own table-shaped form (invoiceListHtml).
const ReportPrint = (function () {
    const esc = (s) => UI.escapeHtml(s == null ? '' : String(s));
    const fmt = (n) => UI.formatNumber(n).replace(/[٬،]/g, ',');
    const fa = (s) => String(s == null ? '' : s).replace(/[0-9]/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
    const escFa = (s) => fa(esc(s));

    const SHELL_CSS = `
@page { size: A4; margin: 12mm 10mm 16mm; @bottom-center { content: "صفحه " counter(page, persian) " از " counter(pages, persian); font: 10px Vazirmatn, Tahoma, sans-serif; color: #666; } }
* { box-sizing: border-box; }
html, body { background: #fff !important; }
body { font-family: Vazirmatn, Tahoma, sans-serif !important; direction: rtl; color: #111; margin: 0; font-size: 12px; line-height: 1.65; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
body.compact { font-size: 10.5px; line-height: 1.45; }
.preview-toolbar { position: sticky; top: 0; z-index: 10; background: #f1f3f5; border-bottom: 1px solid #ced4da; padding: 8px 14px; display: flex; flex-wrap: wrap; gap: 14px; align-items: center; font-size: 12px; }
.preview-toolbar label { display: inline-flex; gap: 4px; align-items: center; cursor: pointer; margin: 0; }
.preview-toolbar .spacer { flex: 1; }
.preview-toolbar button { border: 0; border-radius: 4px; padding: 6px 16px; font: inherit; cursor: pointer; color: #fff; background: #0d6efd; }
.preview-toolbar button.close { background: #6c757d; }
.page { max-width: 190mm; margin: 0 auto; padding: 10px 12px; }
@media screen { body { overflow-x: hidden; } }
body.landscape .page { max-width: 277mm; }
.letterhead { display: flex; align-items: center; gap: 14px; border-bottom: 2px solid #212529; padding-bottom: 8px; }
.letterhead .logo { max-height: 60px; max-width: 160px; object-fit: contain; }
.letterhead .co { flex: 1; }
.letterhead h1 { margin: 0 0 2px; font-size: 18px; font-weight: 700; }
.letterhead .co-info span { display: inline-block; margin-left: 14px; font-size: 11px; color: #444; }
.letterhead .doc-meta { text-align: center; font-size: 10.5px; color: #555; white-space: nowrap; border: 1px solid #ced4da; border-radius: 4px; padding: 3px 10px; line-height: 1.5; }
.letterhead .doc-meta b { font-size: 12px; color: #111; }
.doc-title { text-align: center; margin: 10px 0 10px; }
.doc-title h2 { display: inline-block; margin: 0 0 2px; font-size: 16px; font-weight: 700; padding: 0 18px 2px; border-bottom: 2px solid #212529; }
.doc-title .period { font-size: 11.5px; color: #444; }
.filter-note { border: 1px solid #adb5bd; border-right: 4px solid #212529; border-radius: 4px; padding: 3px 12px; margin: 6px 0; background: #f8f9fa; font-size: 11px; }
.signs { display: flex; gap: 20px; margin-top: 22px; page-break-inside: avoid; }
.signs .sig { flex: 1; border: 1px solid #adb5bd; border-radius: 4px; height: 70px; padding: 4px 8px; font-size: 11px; color: #555; }
.footer-text { margin-top: 10px; font-size: 10.5px; color: #444; text-align: center; }
body.hide-logo .opt-logo, body.hide-company .co-info, body.hide-sign .signs, body.hide-details .rp-details { display: none !important; }
@media print { .preview-toolbar { display: none !important; } .page { max-width: none; padding: 0; } }
`;

    // Makes the on-screen bootstrap markup read as a document: flat cards, ruled tables.
    const CONTENT_CSS = `
.rp-body { font-size: inherit; }
.rp-body .card { border: 1px solid #adb5bd !important; border-radius: 4px !important; box-shadow: none !important; margin-bottom: 8px !important; page-break-inside: avoid; break-inside: avoid; background: #fff; }
.rp-body .card .card { page-break-inside: auto; }
.rp-body .card-header, .rp-body .section-header { background: #f1f3f5 !important; color: #111 !important; padding: 4px 10px !important; border-bottom: 1px solid #adb5bd !important; font-weight: 600; }
.rp-body .card-header h6, .rp-body .section-header h6 { font-size: 12.5px; margin: 0; }
.rp-body .card-body { padding: 6px 10px !important; }
.rp-body .card-footer { background: #f8f9fa !important; padding: 5px 10px !important; border-top: 1px solid #adb5bd !important; }
.rp-body .row { --bs-gutter-y: 6px; --bs-gutter-x: 8px; }
.rp-body h4, .rp-body h5 { font-size: 13.5px; font-weight: 700; margin: 10px 0 6px; }
.rp-body .card h4, .rp-body .card h5 { font-size: 14px; margin: 2px 0; }
.rp-body h6 { font-size: 12.5px; font-weight: 700; }
.rp-body .fs-4, .rp-body .fs-5 { font-size: 14px !important; }
.rp-body .fs-6 { font-size: 11px !important; }
.rp-body .small, .rp-body small { font-size: 10.5px; }
.rp-body .mb-4 { margin-bottom: 8px !important; } .rp-body .mt-4 { margin-top: 10px !important; } .rp-body .py-2 { padding-top: 3px !important; padding-bottom: 3px !important; }
.rp-body .alert { padding: 6px 10px; border-radius: 4px; font-size: 11px; }
.rp-body .badge { background: transparent !important; color: #111 !important; border: 1px solid #adb5bd; font-weight: 600; padding: 1px 6px; font-size: 10.5px !important; }
.rp-body .stat-icon, .rp-body .card-icon { display: none !important; }
.rp-body * { max-height: none !important; }
.rp-body div, .rp-body section, .rp-body ul { overflow: visible !important; }
.rp-body .text-truncate { max-width: none !important; white-space: normal !important; text-overflow: clip !important; overflow: visible !important; }
.rp-body .alert { background: #f8f9fa !important; color: #333 !important; border: 1px solid #ced4da !important; }
.rp-body .shadow, .rp-body .shadow-sm { box-shadow: none !important; }
.rp-body .table-responsive, .rp-body .report-table-scroll { overflow: visible !important; }
.rp-body table { width: 100% !important; border-collapse: collapse; margin-bottom: 6px; }
.rp-body table thead { display: table-header-group; }
.rp-body table tfoot { display: table-row-group; }
.rp-body table tr { page-break-inside: avoid; break-inside: avoid; }
.rp-body .table > :not(caption) > * > * { padding: 3px 5px; box-shadow: none !important; }
.rp-body .table th, .rp-body .table td { vertical-align: middle; }
.rp-body table.rp-table { border: 1px solid #adb5bd; }
.rp-body table.rp-table > thead > tr > th { background: #343a40 !important; color: #fff !important; border: 1px solid #343a40 !important; font-size: 10.5px; font-weight: 600; text-align: center !important; white-space: nowrap; }
.rp-body table.rp-table > tbody > tr > td { border: 1px solid #ced4da !important; }
.rp-body table.rp-table > tbody > tr:nth-child(even) > td { background-color: #f6f7f9; }
.rp-body table.rp-table > tfoot td, .rp-body table.rp-table > tfoot th { background: #e9ecef !important; font-weight: 800; border: 1px solid #adb5bd !important; }
.rp-body table:not(.rp-table) > thead th { background: #f1f3f5 !important; color: #333; font-weight: 600; }
.rp-body a { color: inherit !important; text-decoration: none !important; }
.rp-body .progress { height: 5px !important; border: 1px solid #ced4da; }
.rp-body .tab-title { font-size: 13.5px; font-weight: 700; margin: 12px 0 6px; padding: 3px 10px; background: #f1f3f5; border-right: 4px solid #212529; page-break-after: avoid; }
.rp-body .rp-tab + .rp-tab { margin-top: 10px; }
`;

    function entityName() {
        try {
            const st = App.getAppState();
            return (st.businessEntities || []).find(e => e.id == st.currentEntityId)?.name || '';
        } catch (e) { return ''; }
    }

    /**
     * Full printable document.
     * opts: { title, period, bodyHtml, css, landscape, details (bool: has optional detail blocks),
     *         extraToggles: [{cls,label,checked}], signs: [labels] }
     */
    function buildDocument(opts) {
        const settings = UI.getPrintSettings();
        const origin = window.location.href;
        const asset = (p) => new URL(p, origin).href;
        const logoHtml = settings.logo_url ? `<img class="logo opt-logo" src="${esc(settings.logo_url)}" alt="لوگو">` : '';
        const companyLines = [];
        if (settings.company_phone) companyLines.push(`<span><b>تلفن:</b> <bdi>${escFa(settings.company_phone)}</bdi></span>`);
        if (settings.company_address) companyLines.push(`<span><b>آدرس:</b> ${escFa(settings.company_address)}</span>`);
        const signs = opts.signs || ['تهیه‌کننده', `تأیید ${settings.company_name || 'مدیریت'}`];

        const bodyClasses = [
            settings.show_logo ? '' : 'hide-logo',
            settings.show_company_info ? '' : 'hide-company',
            settings.show_signature_line ? '' : 'hide-sign',
            opts.landscape ? 'landscape' : '',
            opts.details && opts.detailsOff ? 'hide-details' : ''
        ].filter(Boolean).join(' ');

        const toggles = [
            `<label><input type="checkbox" data-cls="hide-logo" ${settings.show_logo ? 'checked' : ''}> لوگو</label>`,
            `<label><input type="checkbox" data-cls="hide-company" ${settings.show_company_info ? 'checked' : ''}> اطلاعات شرکت</label>`,
            opts.details ? `<label><input type="checkbox" data-cls="hide-details" ${opts.detailsOff ? '' : 'checked'}> جزئیات</label>` : '',
            ...(opts.extraToggles || []).map(t => `<label><input type="checkbox" data-cls="${esc(t.cls)}" ${t.checked === false ? '' : 'checked'}> ${esc(t.label)}</label>`),
            `<label><input type="checkbox" data-cls="hide-sign" ${settings.show_signature_line ? 'checked' : ''}> محل امضا</label>`,
            `<label><input type="checkbox" data-cls="compact"> چاپ فشرده</label>`,
            `<label><input type="checkbox" data-cls="landscape" ${opts.landscape ? 'checked' : ''}> افقی</label>`
        ].join('');

        return `<!DOCTYPE html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>${esc(opts.title || 'گزارش')}</title>
<link rel="stylesheet" href="${asset('assets/css/bootstrap.rtl.min.css')}">
<link rel="stylesheet" href="${asset('assets/css/bootstrap-icons.css')}">
<link rel="stylesheet" href="${asset('assets/css/vazirmatn-font-face.css')}">
<style>${SHELL_CSS}${CONTENT_CSS}${opts.css || ''}</style>
<style id="page-size">${opts.landscape ? '@page { size: A4 landscape; }' : ''}</style>
</head>
<body class="${bodyClasses}">
<div class="preview-toolbar no-print">
    ${toggles}
    <span class="spacer"></span>
    <button type="button" onclick="window.print()">🖨 چاپ گزارش</button>
    <button type="button" class="close" onclick="window.close()">بستن</button>
</div>
<div class="page">
    <div class="letterhead">
        ${logoHtml}
        <div class="co">
            <h1>${esc(settings.company_name || entityName() || 'سیستم حسابداری')}</h1>
            <div class="co-info">${companyLines.join('')}</div>
        </div>
        <div class="doc-meta">تاریخ چاپ<br><b>${escFa(UI.today())}</b></div>
    </div>
    <div class="doc-title">
        <h2>${escFa(opts.title || 'گزارش')}</h2>
        ${opts.period ? `<div class="period">${opts.period}</div>` : ''}
    </div>
    <div class="rp-body">${opts.bodyHtml || ''}</div>
    <div class="signs">${signs.map(s => `<div class="sig">${esc(s)}</div>`).join('')}</div>
</div>
<script>
document.querySelectorAll('.preview-toolbar input[data-cls]').forEach(function (cb) {
    var cls = cb.getAttribute('data-cls');
    var positive = cls === 'compact' || cls === 'landscape';
    var apply = function () {
        document.body.classList.toggle(cls, positive ? cb.checked : !cb.checked);
        if (cls === 'landscape') document.getElementById('page-size').textContent = cb.checked ? '@page { size: A4 landscape; }' : '';
    };
    cb.addEventListener('change', apply);
    apply();
});
</script>
</body></html>`;
    }

    function openDocument(opts) {
        const win = window.open('', '_blank');
        if (!win) {
            UI.showError('پنجره چاپ مسدود شد. لطفاً popup blocker مرورگر را غیرفعال کنید.');
            return false;
        }
        win.document.write(buildDocument(opts));
        win.document.close();
        win.focus();
        return true;
    }

    function periodLine(params) {
        if (!params || (!params.persianStartDate && !params.persianEndDate)) return '';
        return `از تاریخ <b>${escFa(params.persianStartDate || '')}</b> تا تاریخ <b>${escFa(params.persianEndDate || '')}</b>`;
    }

    // One DataTable as a plain table: header, every filtered row in display order, footer.
    function dataTableToHtml(dt) {
        const $header = $(dt.table().header()).clone();
        $header.find('th').removeClass('sorting sorting_asc sorting_desc sorting_disabled dt-orderable-asc dt-orderable-desc dt-ordering-asc dt-ordering-desc')
            .removeAttr('style aria-sort aria-label tabindex');
        $header.find('.dt-column-order').remove();
        // DataTables 2 defers building rows that were never shown, so rows without a node are
        // rendered from their data with each column's display renderer and class.
        const colDefs = dt.settings()[0].aoColumns || [];
        const visibleCols = dt.columns().indexes().toArray().filter(c => dt.column(c).visible());
        const rows = dt.rows({ search: 'applied', order: 'applied' }).indexes().toArray().map(i => {
            const tr = dt.row(i).node();
            if (tr) {
                const $tr = $(tr).clone();
                $tr.find('td').removeAttr('style');
                return $tr.prop('outerHTML');
            }
            const cells = visibleCols.map(c => {
                const v = dt.cell(i, c).render('display');
                const cls = (colDefs[c] && colDefs[c].sClass) || '';
                return `<td${cls ? ` class="${esc(cls)}"` : ''}>${v == null ? '' : v}</td>`;
            }).join('');
            return `<tr>${cells}</tr>`;
        }).join('');
        const footerNode = dt.table().footer();
        const footer = footerNode ? $(footerNode).clone().prop('outerHTML') : '';
        const $table = $(dt.table().node());
        const cls = (($table.attr('class') || '') + ' rp-table').replace(/\bdataTable\b|\bno-footer\b|\bcollapsed\b/g, '');
        const total = dt.rows().count();
        const shown = dt.rows({ search: 'applied' }).count();
        const term = dt.search();
        const note = term
            ? `<div class="filter-note">فیلتر جستجو: «<b>${escFa(term)}</b>» — ${fa(shown)} ردیف از ${fa(total)} ردیف</div>`
            : '';
        const cols = $header.find('tr').first().children().length;
        return {
            html: `${note}<table class="${cls}">${$header.prop('outerHTML')}<tbody>${rows || `<tr><td colspan="${cols}" class="text-center text-muted">موردی یافت نشد.</td></tr>`}</tbody>${footer}</table>`,
            cols
        };
    }

    /**
     * Turns the on-screen report container into printable body HTML.
     * Returns { title, bodyHtml, wide, hasDetails }.
     */
    /**
     * Tabs build their tables when first shown, and a DataTable finishes building only after
     * its language file loads. Build every tab's table now (the visible tab stays) and wait.
     */
    async function prepareContainer($container) {
        $container.find('[data-bs-toggle="tab"], [data-bs-toggle="pill"]').trigger('shown.bs.tab');
        const pending = () => $($.fn.dataTable.tables()).filter(function () {
            return $container[0].contains(this) && !new $.fn.dataTable.Api(this).settings()[0]._bInitComplete;
        }).length;
        for (let i = 0; i < 40 && pending(); i++) {
            await new Promise(r => setTimeout(r, 100));
        }
    }

    // Window first (still inside the click, so popup blockers allow it), content when ready.
    async function openDocumentWhenReady(makeOpts) {
        const win = window.open('', '_blank');
        if (!win) {
            UI.showError('پنجره چاپ مسدود شد. لطفاً popup blocker مرورگر را غیرفعال کنید.');
            return false;
        }
        win.document.write('<p style="font-family:Tahoma;direction:rtl;padding:20px">در حال آماده‌سازی چاپ…</p>');
        const opts = await makeOpts();
        win.document.open();
        win.document.write(buildDocument(opts));
        win.document.close();
        win.focus();
        return true;
    }

    function fromContainer($container) {
        // DataTables first: their full row sets only exist on the live tables.
        const replacements = [];
        let maxCols = 0;
        // Only the real tables: with scrollX the header/footer live in extra cloned tables
        // that isDataTable() also accepts, and calling DataTable() on them would init a new one.
        $($.fn.dataTable.tables()).filter(function () { return $container[0].contains(this); }).each(function () {
            const dt = new $.fn.dataTable.Api(this);
            const $wrap = $(this).closest('.dataTables_wrapper, .dt-container');
            if (!$wrap.length || $wrap.attr('data-rp-idx') != null) return;
            const out = dataTableToHtml(dt);
            maxCols = Math.max(maxCols, out.cols);
            $wrap.attr('data-rp-idx', replacements.length);
            replacements.push(out.html);
        });

        const $c = $container.clone();
        $container.find('[data-rp-idx]').removeAttr('data-rp-idx');
        $c.find('[data-rp-idx]').each(function () {
            $(this).replaceWith(replacements[Number($(this).attr('data-rp-idx'))]);
        });

        // Amount cells ("۱۲,۰۰۰ تومان", "(۵۰۰)") stay on one line.
        const currency = UI.getCurrencyLabel();
        $c.find('table td').each(function () {
            const t = $(this).text().replace(/\s+/g, ' ').trim().replace(currency, '').trim();
            if (t && /^[-−()0-9۰-۹,٬.٫\s]+$/.test(t)) $(this).addClass('text-nowrap');
        });

        // Title: the report's first heading.
        const $title = $c.find('h4, h5').first();
        const title = $title.text().replace(/\s+/g, ' ').trim() || 'گزارش';
        $title.remove();

        // Tabs: print every pane in turn under its tab label.
        $c.find('.nav-tabs, .nav-pills').each(function () {
            const $nav = $(this);
            const labels = {};
            $nav.find('[data-bs-target], a[href^="#"]').each(function () {
                const target = $(this).attr('data-bs-target') || $(this).attr('href');
                labels[target] = $(this).text().replace(/\s+/g, ' ').trim();
            });
            const $content = $nav.nextAll('.tab-content').first();
            $content.children('.tab-pane').each(function () {
                const label = labels['#' + this.id];
                $(this).removeClass('fade').addClass('show active rp-tab');
                if (label) $(this).prepend(`<div class="tab-title">${esc(label)}</div>`);
            });
            $nav.remove();
        });

        // Collapsed details open, but can be hidden again from the toolbar.
        const hasDetails = $c.find('.collapse').length > 0;
        $c.find('.collapse').addClass('show rp-details');
        $c.find('.accordion-button').each(function () {
            $(this).replaceWith(`<div class="accordion-title">${$(this).html()}</div>`);
        });
        $c.find('.detail-chevron, .bi-chevron-left, .bi-chevron-down').remove();

        // Grid: keep small/medium columns side by side on paper, stack the large-screen ones.
        $c.find('[class*="col-"]').each(function () {
            // A column holding a table needs the full width on paper.
            if ($(this).find('table').length) {
                this.className = this.className.replace(/col(-(sm|md|lg|xl|xxl))?(-\d+)?/g, '').trim() + ' col-12';
                return;
            }
            this.className = this.className
                .replace(/\bcol-(sm|md)-(\d+)\b/g, 'col-$2')
                .replace(/\bcol-(sm|md)\b/g, 'col')
                .replace(/\bcol-(lg|xl|xxl)(-\d+)?\b/g, '');
        });

        $c.find('.no-print, button, .btn, .btn-group, input, select, textarea, .dataTables_filter, .dataTables_length, .dataTables_info, .dataTables_paginate, .dt-search, .dt-length, .dt-info, .dt-paging, .dropdown-menu').remove();
        $c.find('[style*="cursor"]').css('cursor', '');

        return { title, bodyHtml: $c.html(), wide: maxCols > 8, hasDetails };
    }

    // ---- Sales / purchase invoice list --------------------------------------------------

    const PAYMENT_LABELS = {
        cash: 'نقد', card: 'کارتی', transfer: 'انتقال', check: 'چک',
        endorse_check: 'خرج چک', pos: 'پوز', payable_check: 'چک پرداختی', setoff: 'تهاتر'
    };

    const INVOICE_CSS = `
table.inv { width: 100%; border-collapse: collapse; table-layout: fixed; }
table.inv > thead { display: table-header-group; }
table.inv > thead th { background: #343a40; color: #fff; border: 1px solid #343a40; padding: 4px 3px; font-size: 10.5px; font-weight: 600; text-align: center; white-space: nowrap; }
table.inv > tbody > tr.main > td { border: 1px solid #ced4da; padding: 3px 5px; vertical-align: middle; }
table.inv > tbody > tr.main { page-break-inside: avoid; break-inside: avoid; }
table.inv > tbody > tr.main.alt > td { background: #f6f7f9; }
table.inv > tfoot td { background: #e9ecef; font-weight: 800; border: 1px solid #adb5bd; padding: 4px 5px; }
table.inv td.c { text-align: center; }
table.inv td.num { text-align: left; direction: ltr; white-space: nowrap; font-weight: 600; }
table.inv td.rem { color: #b02a37; }
tr.det > td { padding: 0 6px 6px !important; border: 1px solid #ced4da; border-top: 0; }
.det-wrap { display: flex; gap: 10px; }
.det-wrap > div { flex: 1; }
.det-wrap .pay { flex: 0 0 38%; }
table.sub { width: 100%; border-collapse: collapse; font-size: 9.5px; line-height: 1.45; }
table.sub th { background: #f1f3f5; color: #495057; font-weight: 600; border-bottom: 1px solid #adb5bd; padding: 2px 4px; text-align: center; }
table.sub td { padding: 1px 4px; border-bottom: 1px solid #edf0f2; }
table.sub td.n { text-align: left; direction: ltr; white-space: nowrap; }
table.sub tr { page-break-inside: avoid; break-inside: avoid; }
.sum-boxes { display: flex; gap: 10px; margin: 0 0 10px; page-break-inside: avoid; }
.sum-boxes .box { flex: 1; border: 1px solid #adb5bd; border-radius: 4px; padding: 4px 8px; text-align: center; }
.sum-boxes .k { font-size: 10.5px; color: #555; }
.sum-boxes .v { font-size: 14px; font-weight: 800; }
.sum-boxes .box.rem .v { color: #b02a37; }
.muted { color: #888; }
`;

    function invoiceListOptions(type, data, params, formatItemLabel) {
        const isSales = type === 'sales';
        const invoices = (data && data.invoices) || [];
        const currency = UI.getCurrencyLabel();
        let tAmount = 0, tDiscount = 0, tPaid = 0, tRemain = 0;

        const rows = invoices.map((inv, i) => {
            tAmount += Number(inv.totalAmount) || 0;
            tDiscount += Number(inv.discount) || 0;
            tPaid += Number(inv.paidAmount) || 0;
            tRemain += Number(inv.remainingAmount) || 0;
            const person = isSales ? inv.customerName : inv.supplierName;
            const label = inv.invoice_number || inv.id;

            const items = (inv.items || []).map((it, j) => `<tr><td class="c">${fa(j + 1)}</td><td>${formatItemLabel(it)}</td><td class="n">${fmt(it.quantity)}</td><td class="n">${fmt(it.unitPrice)}</td><td class="n">${fmt((Number(it.quantity) || 0) * (Number(it.unitPrice) || 0))}</td></tr>`).join('');
            const pays = (inv.payments || []).map(p => {
                let det = p.description || '';
                if (p.checkDetails) det = `چک ${p.checkDetails.checkNumber || ''} ${p.checkDetails.bankName || ''}`;
                det = String(det).replace(/\s+/g, ' ').trim();
                if (det.length > 50) det = det.slice(0, 50) + '…';
                return `<tr><td>${esc(PAYMENT_LABELS[p.type] || p.type || '-')}</td><td class="c">${fa(UI.gregorianToPersian(p.date))}</td><td class="n">${fmt(p.amount)}</td><td>${escFa(det)}</td></tr>`;
            }).join('');
            const detail = (items || pays) ? `<tr class="det rp-details"><td colspan="8"><div class="det-wrap">
                <div>${items ? `<table class="sub"><thead><tr><th style="width:22px">#</th><th>کالا</th><th style="width:44px">تعداد</th><th style="width:80px">فی</th><th style="width:90px">جمع</th></tr></thead><tbody>${items}</tbody></table>` : '<span class="muted">بدون قلم</span>'}</div>
                <div class="pay">${pays ? `<table class="sub"><thead><tr><th>نوع</th><th>تاریخ</th><th>مبلغ</th><th>جزئیات</th></tr></thead><tbody>${pays}</tbody></table>` : '<span class="muted">پرداختی ندارد</span>'}</div>
            </div></td></tr>` : '';

            return `<tr class="main ${i % 2 ? 'alt' : ''}">
                <td class="c">${fa(i + 1)}</td>
                <td class="c">${escFa(label)}</td>
                <td class="c">${fa(UI.gregorianToPersian(inv.date))}</td>
                <td>${escFa(person || '-')}</td>
                <td class="num">${fmt(inv.totalAmount)}</td>
                <td class="num">${Number(inv.discount) ? fmt(inv.discount) : '—'}</td>
                <td class="num">${fmt(inv.paidAmount)}</td>
                <td class="num ${Number(inv.remainingAmount) > 0 ? 'rem' : ''}">${fmt(inv.remainingAmount)}</td>
            </tr>${detail}`;
        }).join('');

        const body = `
        <div class="sum-boxes">
            <div class="box"><div class="k">تعداد فاکتور</div><div class="v">${fa(invoices.length)}</div></div>
            <div class="box"><div class="k">جمع کل (${esc(currency)})</div><div class="v">${fmt(tAmount)}</div></div>
            <div class="box"><div class="k">جمع تخفیف</div><div class="v">${fmt(tDiscount)}</div></div>
            <div class="box"><div class="k">جمع پرداختی</div><div class="v">${fmt(tPaid)}</div></div>
            <div class="box rem"><div class="k">جمع مانده</div><div class="v">${fmt(tRemain)}</div></div>
        </div>
        <table class="inv">
            <thead><tr>
                <th style="width:30px">ردیف</th><th style="width:48px">شماره</th><th style="width:68px">تاریخ</th>
                <th>${isSales ? 'مشتری' : 'تأمین‌کننده'}</th>
                <th style="width:92px">مبلغ کل</th><th style="width:72px">تخفیف</th><th style="width:92px">پرداختی</th><th style="width:92px">مانده</th>
            </tr></thead>
            <tbody>${rows || '<tr class="main"><td colspan="8" class="c muted">فاکتوری در این بازه یافت نشد.</td></tr>'}</tbody>
            <tfoot><tr><td colspan="4" class="c">جمع (${fa(invoices.length)} فاکتور)</td><td class="num">${fmt(tAmount)}</td><td class="num">${fmt(tDiscount)}</td><td class="num">${fmt(tPaid)}</td><td class="num">${fmt(tRemain)}</td></tr></tfoot>
        </table>`;

        return {
            title: `گزارش ${isSales ? 'فاکتورهای فروش' : 'فاکتورهای خرید'}`,
            period: periodLine(params),
            bodyHtml: body,
            css: INVOICE_CSS,
            details: invoices.length > 0
        };
    }

    return { buildDocument, openDocument, openDocumentWhenReady, prepareContainer, fromContainer, periodLine, invoiceListOptions, dataTableToHtml };
})();
