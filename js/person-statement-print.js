// /js/person-statement-print.js
// Dedicated print form for person statements (صورتحساب اشخاص): letterhead, person card,
// ledger-style list with document type/number columns, totals, amount in words, signatures.
// Builds its own document from the report data instead of cloning the on-screen report.
const PersonStatementPrint = (function () {
    const esc = (s) => UI.escapeHtml(s == null ? '' : String(s));
    // Persian digits with a plain comma separator (the default Arabic thousands mark is tiny and raised in print).
    const fmt = (n) => UI.formatNumber(n).replace(/[٬،]/g, ',');

    const ONES = ['', 'یک', 'دو', 'سه', 'چهار', 'پنج', 'شش', 'هفت', 'هشت', 'نه', 'ده', 'یازده', 'دوازده', 'سیزده', 'چهارده', 'پانزده', 'شانزده', 'هفده', 'هجده', 'نوزده'];
    const TENS = ['', '', 'بیست', 'سی', 'چهل', 'پنجاه', 'شصت', 'هفتاد', 'هشتاد', 'نود'];
    const HUNDREDS = ['', 'یکصد', 'دویست', 'سیصد', 'چهارصد', 'پانصد', 'ششصد', 'هفتصد', 'هشتصد', 'نهصد'];
    const SCALES = ['', 'هزار', 'میلیون', 'میلیارد', 'هزار میلیارد'];

    function threeDigitsToWords(n) {
        const parts = [];
        if (n >= 100) parts.push(HUNDREDS[Math.floor(n / 100)]);
        const rest = n % 100;
        if (rest > 0) {
            if (rest < 20) parts.push(ONES[rest]);
            else {
                parts.push(TENS[Math.floor(rest / 10)]);
                if (rest % 10) parts.push(ONES[rest % 10]);
            }
        }
        return parts.join(' و ');
    }

    function amountToWords(value) {
        let n = Math.round(Math.abs(Number(value) || 0));
        if (n === 0) return 'صفر';
        const groups = [];
        while (n > 0) {
            groups.push(n % 1000);
            n = Math.floor(n / 1000);
        }
        if (groups.length > SCALES.length) return '';
        const parts = [];
        for (let i = groups.length - 1; i >= 0; i--) {
            if (!groups[i]) continue;
            const words = threeDigitsToWords(groups[i]);
            parts.push(!SCALES[i] ? words : (i === 1 && groups[i] === 1 ? SCALES[i] : `${words} ${SCALES[i]}`));
        }
        return parts.join(' و ');
    }

    const DOC_TYPES = {
        salesInvoice: 'فاکتور فروش',
        purchaseInvoice: 'فاکتور خرید',
        salesReturn: 'مرجوعی فروش',
        purchaseReturn: 'مرجوعی خرید',
        payment: 'پرداخت/دریافت',
        check: 'چک',
        expense: 'هزینه',
        income: 'درآمد',
        asset_document: 'دارایی',
        asset_payment: 'پرداخت دارایی'
    };
    const INVOICE_TYPES = ['salesInvoice', 'purchaseInvoice', 'salesReturn', 'purchaseReturn'];
    const PERSON_LABELS = { customer: 'مشتری', supplier: 'تأمین‌کننده', partner: 'شریک', contact: 'مخاطب' };
    const STATUS_LABELS = { pending: 'در انتظار', cashed: 'وصول شده', bounced: 'برگشتی', endorsed: 'خرج شده' };

    const isPreviousRow = (t) => t.isPreviousBalance || t.date === 'مانده از قبل' || t.date === 'مانده اولیه';

    function docNumber(t) {
        if (INVOICE_TYPES.includes(t.refType) && t.refId) return t.refId;
        return '';
    }

    function cleanDescription(t) {
        let d = String(t.description || '').replace(/^\(امانی\)\s*/, '');
        if (INVOICE_TYPES.includes(t.refType)) {
            d = d.replace(/\s*#\d+/, '').replace(/^فاکتور\s*(مرجوعی\s*)?(فروش|خرید)\s*/, '').trim();
        }
        return d;
    }

    // Turn report data into ledger rows with running balance (same rules as the on-screen report).
    function buildLedger(data, params) {
        const isCustomer = (params.personType || 'customer') === 'customer';
        const sign = (debit, credit) => (isCustomer ? debit - credit : credit - debit);
        const countable = (t) => !t.is_consignment || params.calculateConsignment;
        const all = Array.isArray(data.transactions) ? data.transactions : [];
        const list = params.includePendingChecks ? all : all.filter(t => !t.isPendingCheck);

        const showPrev = !!params.showPreviousBalance;
        const opening = showPrev
            ? Number(data.openingBalance != null ? data.openingBalance : (data.person.initial_balance || 0))
            : 0;
        const hasPrevRow = showPrev && list.some(isPreviousRow);

        let totalDebit = 0, totalCredit = 0, pendingDebit = 0, pendingCredit = 0;
        let balance = hasPrevRow ? 0 : opening;
        const rows = [];

        list.forEach(t => {
            const debit = Number(t.debit) || 0;
            const credit = Number(t.credit) || 0;
            const prev = isPreviousRow(t);
            const pending = !!t.isPendingCheck;
            const eligible = prev || countable(t);
            const counted = eligible && (!pending || !!params.calculatePendingChecks);

            if (!prev && pending && countable(t)) {
                pendingDebit += debit;
                pendingCredit += credit;
            }
            if (counted) {
                balance += sign(debit, credit);
                if (!prev) {
                    totalDebit += debit;
                    totalCredit += credit;
                }
            }
            rows.push({ t, debit, credit, prev, pending, balance, showBalance: !(pending && !params.calculatePendingChecks) });
        });

        const netMovement = isCustomer ? (totalDebit - totalCredit) : (totalCredit - totalDebit);
        return {
            rows, isCustomer, opening, totalDebit, totalCredit, pendingDebit, pendingCredit,
            closing: opening + netMovement
        };
    }

    // Balance nature as a compact chip in its own column (بد = بدهکار، بس = بستانکار).
    function natureChip(balance, isCustomer) {
        if (Math.abs(Number(balance) || 0) < 0.005) return '';
        const debtor = balance >= 0 ? isCustomer : !isCustomer;
        return debtor ? '<span class="chip deb">بد</span>' : '<span class="chip cre">بس</span>';
    }

    function balanceLabel(balance, isCustomer, personType) {
        const pos = isCustomer ? 'بدهکار' : 'بستانکار';
        const neg = isCustomer ? 'بستانکار' : 'بدهکار';
        return balance >= 0 ? pos : neg;
    }

    function itemsTableHtml(t) {
        if (!t.items || !t.items.length) return '';
        const lines = t.items.map(item => {
            const isService = item.is_service == 1;
            const name = isService
                ? (item.item_description || item.productName || 'خدمات')
                : (item.productName || item.item_description || '-');
            const bits = [`<b>${esc(name)}</b>`];
            if (!isService && item.dimensions) bits.push(`<bdi>${esc(item.dimensions)}</bdi>`);
            bits.push(`${fmt(item.quantity)} عدد × ${fmt(item.unitPrice)}`);
            if (!isService && item.serialIds && item.serialIds.length) bits.push(`شناسه: <bdi>${esc(item.serialIds.join("، "))}</bdi>`);
            return `<div class="item-line">${bits.join(' — ')}</div>`;
        }).join('');
        return `<div class="items opt-items">${lines}</div>`;
    }

    function rowHtml(r, idx, ledger, personType) {
        const t = r.t;
        const cls = [];
        if (r.prev) cls.push('prev');
        if (t.is_consignment) cls.push('consign');
        if (r.pending) cls.push('pending');

        const badges = [];
        if (t.is_consignment) badges.push('<span class="badge badge-consign">امانی</span>');
        if (r.pending) badges.push('<span class="badge badge-pending">سررسید نرسیده</span>');
        if (t.isUndeliveredOrder) badges.push('<span class="badge badge-info">سفارش تحویل‌نشده</span>');

        let desc = r.prev ? esc(t.description || 'مانده حساب از قبل (اول دوره)') : esc(cleanDescription(t));
        if (t.checkDetails) {
            const cd = t.checkDetails;
            const bits = [];
            if (cd.checkNumber) bits.push(`شماره چک: ${esc(cd.checkNumber)}`);
            if (cd.bankName) bits.push(`بانک: ${esc(cd.bankName)}`);
            if (cd.dueDate) bits.push(`سررسید: ${esc(UI.gregorianToPersian(cd.dueDate))}`);
            if (cd.status) bits.push(`وضعیت: ${esc(STATUS_LABELS[cd.status] || cd.status)}`);
            if (bits.length) desc += `<div class="sub">${bits.join(' | ')}</div>`;
        }
        const itemsHtml = itemsTableHtml(t);

        let typeLabel = r.prev ? '' : (DOC_TYPES[t.refType] || '');
        if (!r.prev && t.refType === 'payment') typeLabel = r.credit > 0 ? 'دریافت' : (r.debit > 0 ? 'پرداخت' : typeLabel);
        const docNo = r.prev ? '' : docNumber(t);
        const dateText = r.prev ? '' : UI.gregorianToPersian(t.date);

        const showBal = (r.prev || r.showBalance);
        const balanceCell = showBal
            ? fmt(Math.abs(r.balance))
            : '<span class="muted">—</span>';

        return `<tr class="${cls.join(' ')}">
            <td class="c">${r.prev ? '' : idx}</td>
            <td class="c nowrap">${dateText}</td>
            <td class="c col-type">${esc(typeLabel)}</td>
            <td class="c col-doc">${esc(docNo)}</td>
            <td class="desc">${badges.join(' ')}${badges.length && desc ? ' ' : ''}${desc}${itemsHtml}</td>
            <td class="num">${r.debit > 0 ? fmt(r.debit) : '—'}</td>
            <td class="num">${r.credit > 0 ? fmt(r.credit) : '—'}</td>
            <td class="num bal">${balanceCell}</td>
            <td class="c nat">${showBal ? natureChip(r.balance, ledger.isCustomer) : ''}</td>
        </tr>`;
    }

    function buildHtml(data, params, settings) {
        const person = data.person || {};
        const personType = params.personType || 'customer';
        const ledger = buildLedger(data, params);
        const currency = UI.getCurrencyLabel();
        const hasItems = (data.transactions || []).some(t => t.items && t.items.length);
        const personLabel = PERSON_LABELS[personType] || 'شخص';
        const closingAbs = Math.abs(ledger.closing);
        const closingLabel = balanceLabel(ledger.closing, ledger.isCustomer, personType);
        const closingNote = ledger.isCustomer
            ? (ledger.closing >= 0 ? 'این شخص به شرکت بدهکار است' : 'شرکت به این شخص بدهکار است')
            : (ledger.closing >= 0 ? 'شرکت به این شخص بدهکار است' : 'این شخص به شرکت بدهکار است');

        let counter = 0;
        const bodyRows = ledger.rows.length
            ? ledger.rows.map(r => rowHtml(r, r.prev ? 0 : ++counter, ledger, personType)).join('')
            : '<tr><td colspan="9" class="c muted">گردش حسابی در این بازه یافت نشد.</td></tr>';

        const origin = window.location.href;
        const asset = (p) => new URL(p, origin).href;
        const logoHtml = settings.logo_url ? `<img class="logo opt-logo" src="${esc(settings.logo_url)}" alt="لوگو">` : '';
        const companyLines = [];
        if (settings.company_phone) companyLines.push(`<span><b>تلفن:</b> ${esc(settings.company_phone)}</span>`);
        if (settings.company_address) companyLines.push(`<span><b>آدرس:</b> ${esc(settings.company_address)}</span>`);

        const personRows = [
            ['نام', person.name],
            ['نوع', personLabel],
            ['تلفن', person.phone || person.mobile],
            ['آدرس', person.address]
        ].filter(([, v]) => v);

        const optionNotes = ['ماهیت: بد = بدهکار به شرکت · بس = بستانکار از شرکت'];
        optionNotes.push(params.calculateConsignment ? 'فاکتورهای امانی در مانده لحاظ شده' : 'فاکتورهای امانی فقط نمایش داده شده (در مانده لحاظ نشده)');
        if (params.includePendingChecks) {
            optionNotes.push(params.calculatePendingChecks ? 'چک‌های سررسید نرسیده در مانده لحاظ شده' : 'چک‌های سررسید نرسیده در مانده لحاظ نشده');
        }
        if (data.combinedWith) optionNotes.push(`صورتحساب تجمیعی: حساب ${data.combinedWith.type === 'supplier' ? 'تأمین‌کننده' : 'مشتری'} همین شخص هم لحاظ شده`);
        if ((personType === 'supplier' || data.combinedWith) && params.includeUndeliveredOrders) optionNotes.push('سفارشات تحویل‌نشده انبار سفارش لحاظ شده');

        const pendingNet = ledger.isCustomer
            ? (ledger.pendingCredit - ledger.pendingDebit)
            : (ledger.pendingDebit - ledger.pendingCredit);
        const pendingBox = (params.includePendingChecks && (ledger.pendingDebit > 0 || ledger.pendingCredit > 0))
            ? `<div class="pending-note">چک‌های سررسید نرسیده: <b>${fmt(Math.abs(pendingNet))}</b> ${esc(currency)} ${params.calculatePendingChecks ? '(در مانده لحاظ شده)' : '(در مانده لحاظ نشده)'}</div>`
            : '';

        const bankInfo = [];
        if (settings.show_payment_info) {
            if (settings.bank_name) bankInfo.push(`بانک ${esc(settings.bank_name)}`);
            if (settings.account_holder) bankInfo.push(`به نام ${esc(settings.account_holder)}`);
            if (settings.card_number) bankInfo.push(`کارت: ${esc(settings.card_number)}`);
            if (settings.account_number) bankInfo.push(`حساب: ${esc(settings.account_number)}`);
            if (settings.sheba_number) bankInfo.push(`شبا: ${esc(settings.sheba_number)}`);
        }

        const bodyClasses = [
            settings.show_logo ? '' : 'hide-logo',
            settings.show_company_info ? '' : 'hide-company',
            settings.show_signature_line ? '' : 'hide-sign',
            hasItems ? '' : 'hide-items'
        ].filter(Boolean).join(' ');

        const title = `صورتحساب ${person.name || ''}`;

        return `<!DOCTYPE html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>${esc(title)}</title>
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
.letterhead h1 { margin: 0; font-size: 18px; }
.letterhead .co-info span { display: inline-block; margin-left: 14px; font-size: 11px; color: #444; }
.letterhead .doc-meta { text-align: left; font-size: 11px; color: #333; white-space: nowrap; }
.doc-title { text-align: center; margin: 10px 0 8px; }
.doc-title h2 { margin: 0; font-size: 16px; }
.doc-title .period { font-size: 12px; color: #444; }
.person-card { border: 1px solid #adb5bd; border-radius: 4px; padding: 6px 10px; display: flex; flex-wrap: wrap; gap: 4px 28px; margin-bottom: 8px; }
.person-card div b { color: #555; font-weight: 600; }
table.ledger { width: 100%; border-collapse: collapse; table-layout: fixed; }
table.ledger thead { display: table-header-group; }
table.ledger th { background: #e9ecef; border: 1px solid #adb5bd; padding: 4px 5px; font-size: 11px; text-align: center; }
table.ledger td { border: 1px solid #ced4da; padding: 3px 5px; vertical-align: top; }
table.ledger tbody tr:nth-child(even) { background: #f8f9fa; }
table.ledger tr { page-break-inside: avoid; }
td.c { text-align: center; }
td.num { text-align: left; direction: ltr; white-space: nowrap; font-size: 13px; font-weight: 600; color: #000; letter-spacing: 0.2px; }
td.num.bal { font-weight: 700; }
body.compact td.num { font-size: 11.5px; }
td.nowrap { white-space: nowrap; }
td.nat { padding: 3px 2px; }
.chip { display: inline-block; min-width: 22px; padding: 0 3px; border: 1px solid; border-radius: 3px; font-size: 10px; font-weight: 700; }
.chip.deb { color: #b02a37; border-color: #b02a37; }
.chip.cre { color: #146c43; border-color: #146c43; }
table.ledger td { overflow-wrap: anywhere; }
tr.prev td { background: #dee2e6 !important; font-weight: 600; }
tr.consign td { font-style: italic; }
tr.pending td { background: #fff8e1 !important; }
.muted { color: #888; }
.sub { font-size: 10px; color: #555; }
.badge { display: inline-block; font-size: 9.5px; padding: 0 5px; border-radius: 3px; border: 1px solid #888; font-style: normal; }
.badge-consign { border-color: #6f42c1; color: #6f42c1; }
.badge-pending { border-color: #b58105; color: #8a6100; }
.badge-info { border-color: #0a7aa5; color: #0a7aa5; }
.items { margin-top: 3px; padding-top: 2px; border-top: 1px dotted #adb5bd; font-size: 10px; color: #333; font-style: normal; }
.item-line { padding: 0 0 1px; }
.totals { display: flex; gap: 14px; margin-top: 10px; page-break-inside: avoid; }
.totals .box { flex: 1; border: 1px solid #adb5bd; border-radius: 4px; padding: 5px 10px; text-align: center; }
.totals .box .k { font-size: 10.5px; color: #555; }
.totals .box .v { font-size: 16px; font-weight: 800; color: #000; letter-spacing: 0.3px; }
.totals .final { border: 2px solid #212529; }
.words { margin-top: 6px; font-size: 11.5px; page-break-inside: avoid; }
.pending-note, .opts-note { margin-top: 6px; font-size: 10.5px; color: #555; page-break-inside: avoid; }
.bank { margin-top: 8px; font-size: 10.5px; border-top: 1px dashed #aaa; padding-top: 5px; page-break-inside: avoid; }
.signs { display: flex; gap: 20px; margin-top: 22px; page-break-inside: avoid; }
.signs .sig { flex: 1; border: 1px solid #adb5bd; border-radius: 4px; height: 70px; padding: 4px 8px; font-size: 11px; color: #555; }
.footer-text { margin-top: 10px; font-size: 10.5px; color: #444; text-align: center; page-break-inside: avoid; }
body.hide-logo .opt-logo, body.hide-company .co-info, body.hide-sign .signs, body.hide-items .opt-items, body.hide-type .col-type, body.hide-doc .col-doc { display: none !important; }
@media print { .preview-toolbar { display: none !important; } .page { max-width: none; padding: 0; } }
</style></head>
<body class="${bodyClasses}">
<div class="preview-toolbar no-print">
    <label><input type="checkbox" data-cls="hide-logo" ${settings.show_logo ? 'checked' : ''}> لوگو</label>
    <label><input type="checkbox" data-cls="hide-company" ${settings.show_company_info ? 'checked' : ''}> اطلاعات شرکت</label>
    <label><input type="checkbox" data-cls="hide-type" checked> ستون نوع سند</label>
    <label><input type="checkbox" data-cls="hide-doc" checked> ستون شماره سند</label>
    ${hasItems ? '<label><input type="checkbox" data-cls="hide-items" checked> جزئیات اقلام</label>' : ''}
    <label><input type="checkbox" data-cls="hide-sign" ${settings.show_signature_line ? 'checked' : ''}> محل امضا</label>
    <label><input type="checkbox" data-cls="compact"> چاپ فشرده</label>
    <span class="spacer"></span>
    <button type="button" onclick="window.print()">🖨 چاپ صورتحساب</button>
    <button type="button" class="close" onclick="window.close()">بستن</button>
</div>
<div class="page">
    <div class="letterhead">
        ${logoHtml}
        <div class="co">
            <h1>${esc(settings.company_name || 'سیستم حسابداری')}</h1>
            <div class="co-info">${companyLines.join('')}</div>
        </div>
        <div class="doc-meta">تاریخ چاپ: ${esc(UI.today())}</div>
    </div>
    ${settings.header_text ? `<div class="footer-text">${esc(settings.header_text)}</div>` : ''}
    <div class="doc-title">
        <h2>صورتحساب ${esc(personLabel)}</h2>
        <div class="period">از تاریخ ${esc(params.persianStartDate || '')} تا تاریخ ${esc(params.persianEndDate || '')}</div>
    </div>
    <div class="person-card">${personRows.map(([k, v]) => `<div><b>${k}:</b> ${esc(v)}</div>`).join('')}</div>
    <table class="ledger">
        <thead><tr>
            <th style="width:30px">ردیف</th>
            <th style="width:68px">تاریخ</th>
            <th class="col-type" style="width:66px">نوع سند</th>
            <th class="col-doc" style="width:46px">شماره</th>
            <th>شرح</th>
            <th style="width:106px">بدهکار به شرکت (${esc(currency)})</th>
            <th style="width:106px">بستانکار از شرکت (${esc(currency)})</th>
            <th style="width:112px">مانده (${esc(currency)})</th>
            <th style="width:34px">بد/بس</th>
        </tr></thead>
        <tbody>${bodyRows}</tbody>
    </table>
    <div class="totals">
        <div class="box"><div class="k">مانده از قبل</div><div class="v">${params.showPreviousBalance ? fmt(Math.abs(ledger.opening)) : '—'}</div></div>
        <div class="box"><div class="k">جمع بدهکار به شرکت</div><div class="v">${fmt(ledger.totalDebit)}</div></div>
        <div class="box"><div class="k">جمع بستانکار از شرکت</div><div class="v">${fmt(ledger.totalCredit)}</div></div>
        <div class="box final"><div class="k">مانده نهایی (${closingLabel})</div><div class="v">${fmt(closingAbs)}</div></div>
    </div>
    <div class="words">مانده نهایی به حروف: <b>${esc(amountToWords(closingAbs))} ${esc(currency)}</b> — ${closingLabel} (${closingNote})</div>
    ${pendingBox}
    <div class="opts-note">${optionNotes.map(esc).join(' · ')}</div>
    ${bankInfo.length ? `<div class="bank">${bankInfo.join(' — ')}</div>` : ''}
    <div class="signs">
        <div class="sig">مهر و امضای ${esc(settings.company_name || 'شرکت')}</div>
        <div class="sig">تأیید ${esc(personLabel)}${person.name ? ` (${esc(person.name)})` : ''}</div>
    </div>
    ${settings.footer_text ? `<div class="footer-text">${esc(settings.footer_text)}</div>` : ''}
</div>
<script>
document.querySelectorAll('.preview-toolbar input[data-cls]').forEach(function (cb) {
    var cls = cb.getAttribute('data-cls');
    var apply = function () {
        // "compact" is a positive class; every hide-* box is checked = shown.
        document.body.classList.toggle(cls, cls === 'compact' ? cb.checked : !cb.checked);
    };
    cb.addEventListener('change', apply);
    apply();
});
</script>
</body></html>`;
    }

    function open(data, params) {
        if (!data || !data.person) {
            UI.showError('اطلاعات صورتحساب برای چاپ موجود نیست.');
            return false;
        }
        const win = window.open('', '_blank');
        if (!win) {
            UI.showError('پنجره چاپ مسدود شد. لطفاً popup blocker مرورگر را غیرفعال کنید.');
            return false;
        }
        win.document.write(buildHtml(data, params || {}, UI.getPrintSettings()));
        win.document.close();
        win.focus();
        return true;
    }

    return { open, amountToWords, buildLedger };
})();
