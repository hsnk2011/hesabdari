// /js/cash-report-print.js
// Dedicated print form for the expenses / incomes reports: letterhead, period, search note,
// per-category summary, full list (every filtered row, not just the visible DataTables page),
// total, amount in words and signatures. Built from the report data, not the on-screen table.
const CashReportPrint = (function () {
    const esc = (s) => UI.escapeHtml(s == null ? '' : String(s));
    const fmt = (n) => UI.formatNumber(n).replace(/[٬،]/g, ',');
    const fa = (s) => String(s == null ? '' : s).replace(/[0-9]/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
    const escFa = (s) => fa(esc(s));

    const KINDS = {
        expenses: { title: 'گزارش هزینه‌ها', item: 'هزینه', account: 'حساب پرداخت' },
        incomes: { title: 'گزارش درآمدها', item: 'درآمد', account: 'حساب دریافت' }
    };

    function buildHtml(kind, rows, params, settings) {
        const k = KINDS[kind] || KINDS.expenses;
        const currency = UI.getCurrencyLabel();
        const total = rows.reduce((s, r) => s + (Number(r.amount) || 0), 0);

        const byCat = {};
        rows.forEach(r => {
            const c = r.category || 'بدون دسته';
            byCat[c] = byCat[c] || { count: 0, sum: 0 };
            byCat[c].count++;
            byCat[c].sum += Number(r.amount) || 0;
        });
        const cats = Object.entries(byCat).sort((a, b) => b[1].sum - a[1].sum);
        const catRows = cats.map(([name, v], i) => `<tr>
            <td class="c">${fa(i + 1)}</td><td>${escFa(name)}</td><td class="c">${fa(v.count)}</td>
            <td class="num">${fmt(v.sum)}</td><td class="c">${fa(total > 0 ? (v.sum / total * 100).toFixed(1) : '0')}٪</td></tr>`).join('');

        const bodyRows = rows.length ? rows.map((r, i) => `<tr class="${i % 2 ? 'alt' : ''}">
            <td class="c">${fa(i + 1)}</td>
            <td class="c nowrap">${fa(UI.gregorianToPersian(r.date))}</td>
            <td>${escFa(r.category || '-')}</td>
            <td class="desc">${escFa(r.description || '')}</td>
            <td class="col-acc">${escFa(r.accountName || '-')}</td>
            <td class="num">${fmt(r.amount)}</td></tr>`).join('')
            : `<tr><td colspan="6" class="c muted">موردی یافت نشد.</td></tr>`;

        const origin = window.location.href;
        const asset = (p) => new URL(p, origin).href;
        const logoHtml = settings.logo_url ? `<img class="logo opt-logo" src="${esc(settings.logo_url)}" alt="لوگو">` : '';
        const companyLines = [];
        if (settings.company_phone) companyLines.push(`<span><b>تلفن:</b> <bdi>${escFa(settings.company_phone)}</bdi></span>`);
        if (settings.company_address) companyLines.push(`<span><b>آدرس:</b> ${escFa(settings.company_address)}</span>`);

        const filterNote = params.search
            ? `<div class="filter-note">فیلتر جستجو: «<b>${escFa(params.search)}</b>» — ${fa(rows.length)} مورد از ${fa(params.totalCount)} مورد</div>`
            : '';
        const bodyClasses = [
            settings.show_logo ? '' : 'hide-logo',
            settings.show_company_info ? '' : 'hide-company',
            settings.show_signature_line ? '' : 'hide-sign'
        ].filter(Boolean).join(' ');

        return `<!DOCTYPE html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>${esc(k.title)}</title>
<link rel="stylesheet" href="${asset('assets/css/vazirmatn-font-face.css')}">
<style>
@page { size: A4; margin: 12mm 10mm 16mm; @bottom-center { content: "صفحه " counter(page, persian) " از " counter(pages, persian); font: 10px Vazirmatn, Tahoma, sans-serif; color: #666; } }
* { box-sizing: border-box; }
body { font-family: Vazirmatn, Tahoma, sans-serif; direction: rtl; background: #fff; color: #111; margin: 0; font-size: 12px; line-height: 1.7; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
body.compact { font-size: 10.5px; line-height: 1.5; }
.preview-toolbar { position: sticky; top: 0; z-index: 10; background: #f1f3f5; border-bottom: 1px solid #ced4da; padding: 8px 14px; display: flex; flex-wrap: wrap; gap: 14px; align-items: center; font-size: 12px; }
.preview-toolbar label { display: inline-flex; gap: 4px; align-items: center; cursor: pointer; }
.preview-toolbar .spacer { flex: 1; }
.preview-toolbar button { border: 0; border-radius: 4px; padding: 6px 16px; font: inherit; cursor: pointer; color: #fff; background: #0d6efd; }
.preview-toolbar button.close { background: #6c757d; }
.page { max-width: 190mm; margin: 0 auto; padding: 10px 0; }
.letterhead { display: flex; align-items: center; gap: 14px; border-bottom: 2px solid #212529; padding-bottom: 8px; }
.letterhead .logo { max-height: 60px; max-width: 160px; object-fit: contain; }
.letterhead .co { flex: 1; }
.letterhead h1 { margin: 0 0 2px; font-size: 18px; }
.letterhead .co-info span { display: inline-block; margin-left: 14px; font-size: 11px; color: #444; }
.letterhead .doc-meta { text-align: center; font-size: 10.5px; color: #555; white-space: nowrap; border: 1px solid #ced4da; border-radius: 4px; padding: 3px 10px; line-height: 1.5; }
.letterhead .doc-meta b { font-size: 12px; color: #111; }
.doc-title { text-align: center; margin: 10px 0 8px; }
.doc-title h2 { display: inline-block; margin: 0 0 2px; font-size: 16px; padding: 0 18px 2px; border-bottom: 2px solid #212529; }
.doc-title .period { font-size: 11.5px; color: #444; }
.filter-note { border: 1px solid #adb5bd; border-right: 4px solid #212529; border-radius: 4px; padding: 4px 12px; margin-bottom: 8px; background: #f8f9fa; font-size: 11px; }
h3 { font-size: 12.5px; margin: 12px 0 4px; }
table.grid { width: 100%; border-collapse: collapse; table-layout: fixed; }
table.grid thead { display: table-header-group; }
table.grid th { background: #343a40; color: #fff; border: 1px solid #343a40; padding: 4px 3px; font-size: 10.5px; font-weight: 600; text-align: center; white-space: nowrap; }
table.grid td { border: 1px solid #ced4da; padding: 3px 5px; vertical-align: middle; overflow-wrap: anywhere; }
table.grid tr { page-break-inside: avoid; break-inside: avoid; }
table.grid tr.alt td { background: #f6f7f9; }
table.grid tfoot td { background: #e9ecef; font-weight: 800; border: 1px solid #adb5bd; }
table.cats { width: 70%; }
td.c { text-align: center; }
td.num { text-align: left; direction: ltr; white-space: nowrap; font-weight: 600; font-size: 12.5px; }
body.compact td.num { font-size: 11.5px; }
td.nowrap { white-space: nowrap; }
.muted { color: #888; }
.totals { display: flex; gap: 14px; margin-top: 10px; page-break-inside: avoid; }
.totals .box { flex: 1; border: 1px solid #adb5bd; border-radius: 4px; padding: 5px 10px; text-align: center; }
.totals .box .k { font-size: 10.5px; color: #555; }
.totals .box .v { font-size: 16px; font-weight: 800; }
.totals .final { border: 2px solid #212529; }
.words { margin-top: 6px; font-size: 11.5px; page-break-inside: avoid; }
.signs { display: flex; gap: 20px; margin-top: 22px; page-break-inside: avoid; }
.signs .sig { flex: 1; border: 1px solid #adb5bd; border-radius: 4px; height: 70px; padding: 4px 8px; font-size: 11px; color: #555; }
body.hide-logo .opt-logo, body.hide-company .co-info, body.hide-sign .signs, body.hide-cats .opt-cats, body.hide-acc .col-acc { display: none !important; }
@media print { .preview-toolbar { display: none !important; } .page { max-width: none; padding: 0; } }
</style></head>
<body class="${bodyClasses}">
<div class="preview-toolbar no-print">
    <label><input type="checkbox" data-cls="hide-logo" ${settings.show_logo ? 'checked' : ''}> لوگو</label>
    <label><input type="checkbox" data-cls="hide-company" ${settings.show_company_info ? 'checked' : ''}> اطلاعات شرکت</label>
    <label><input type="checkbox" data-cls="hide-cats" checked> خلاصه دسته‌بندی</label>
    <label><input type="checkbox" data-cls="hide-acc" checked> ستون ${esc(k.account)}</label>
    <label><input type="checkbox" data-cls="hide-sign" ${settings.show_signature_line ? 'checked' : ''}> محل امضا</label>
    <label><input type="checkbox" data-cls="compact"> چاپ فشرده</label>
    <span class="spacer"></span>
    <button type="button" onclick="window.print()">🖨 چاپ گزارش</button>
    <button type="button" class="close" onclick="window.close()">بستن</button>
</div>
<div class="page">
    <div class="letterhead">
        ${logoHtml}
        <div class="co">
            <h1>${esc(settings.company_name || params.entityName || 'سیستم حسابداری')}</h1>
            <div class="co-info">${companyLines.join('')}</div>
        </div>
        <div class="doc-meta">تاریخ چاپ<br><b>${escFa(UI.today())}</b></div>
    </div>
    <div class="doc-title">
        <h2>${esc(k.title)}</h2>
        <div class="period">از تاریخ <b>${escFa(params.persianStartDate || '')}</b> تا تاریخ <b>${escFa(params.persianEndDate || '')}</b></div>
    </div>
    ${filterNote}
    ${cats.length > 1 ? `<div class="opt-cats"><h3>خلاصه بر اساس دسته‌بندی</h3>
    <table class="grid cats">
        <thead><tr><th style="width:34px">ردیف</th><th>دسته‌بندی</th><th style="width:60px">تعداد</th><th style="width:120px">جمع (${esc(currency)})</th><th style="width:60px">سهم</th></tr></thead>
        <tbody>${catRows}</tbody>
    </table></div>` : ''}
    <h3>ریز ${esc(k.item)}‌ها</h3>
    <table class="grid">
        <thead><tr>
            <th style="width:34px">ردیف</th>
            <th style="width:72px">تاریخ</th>
            <th style="width:110px">دسته‌بندی</th>
            <th>توضیحات</th>
            <th class="col-acc" style="width:120px">${esc(k.account)}</th>
            <th style="width:110px">مبلغ (${esc(currency)})</th>
        </tr></thead>
        <tbody>${bodyRows}</tbody>
        <tfoot><tr><td colspan="5" class="c">جمع کل (${fa(rows.length)} مورد)</td><td class="num">${fmt(total)}</td></tr></tfoot>
    </table>
    <div class="totals">
        <div class="box"><div class="k">تعداد ${esc(k.item)}‌ها</div><div class="v">${fa(rows.length)}</div></div>
        <div class="box"><div class="k">تعداد دسته‌ها</div><div class="v">${fa(cats.length)}</div></div>
        <div class="box final"><div class="k">جمع کل (${esc(currency)})</div><div class="v">${fmt(total)}</div></div>
    </div>
    <div class="words">جمع کل به حروف: <b>${esc(PersonStatementPrint.amountToWords(total))} ${esc(currency)}</b></div>
    <div class="signs">
        <div class="sig">تهیه‌کننده</div>
        <div class="sig">تأیید ${esc(settings.company_name || 'مدیریت')}</div>
    </div>
</div>
<script>
document.querySelectorAll('.preview-toolbar input[data-cls]').forEach(function (cb) {
    var cls = cb.getAttribute('data-cls');
    var apply = function () { document.body.classList.toggle(cls, cls === 'compact' ? cb.checked : !cb.checked); };
    cb.addEventListener('change', apply);
    apply();
});
</script>
</body></html>`;
    }

    function open(kind, rows, params) {
        const win = window.open('', '_blank');
        if (!win) {
            UI.showError('پنجره چاپ مسدود شد. لطفاً popup blocker مرورگر را غیرفعال کنید.');
            return false;
        }
        win.document.write(buildHtml(kind, rows || [], params || {}, UI.getPrintSettings()));
        win.document.close();
        win.focus();
        return true;
    }

    return { open, buildHtml };
})();
