/**
 * Bank statement import + reconciliation workspace
 */
const BankReconciliationManager = (() => {
    let parsedPayload = null; // { rows, summary, fileName }
    let workspaceItems = [];
    let pendingProposals = [];

    // Labels come from the parser registry; the literal fallback only covers an
    // old cached copy of bank-statement-parsers.js.
    const formatLabels = (typeof BankStatementParsers !== 'undefined' && BankStatementParsers.BANK_FORMATS)
        ? Object.fromEntries(Object.entries(BankStatementParsers.BANK_FORMATS).map(([k, v]) => [k, v.label.replace(/\s*\((CSV|Excel)\)\s*/g, '')]))
        : { mellat: 'ملت', dey: 'دی', melli: 'ملی', blubank: 'بلو (سامان)', generic: 'عمومی' };
    const statusLabels = {
        unmatched: '<span class="badge bg-warning text-dark">تطبیق‌نشده</span>',
        matched: '<span class="badge bg-success">تطبیق‌شده</span>',
        ignored: '<span class="badge bg-secondary">نادیده</span>',
        in_progress: '<span class="badge bg-info">در جریان</span>',
        completed: '<span class="badge bg-success">کامل</span>',
        draft: '<span class="badge bg-light text-dark">پیش‌نویس</span>'
    };

    /** Mirror of BankImport::buildBankDetailSnippet / composeEnrichedDescription */
    function buildBankDetailSnippet(bankItem) {
        if (!bankItem) return '';
        const parts = [];
        const date = String(bankItem.transaction_date || '').trim();
        if (date) parts.push('تاریخ: ' + UI.gregorianToPersian(date));
        const time = String(bankItem.transaction_time || '').trim();
        if (time) parts.push('ساعت: ' + time);
        const amt = parseFloat(bankItem.amount || 0);
        if (Math.abs(amt) > 0.0001) {
            parts.push('مبلغ بانک: ' + Math.abs(amt).toLocaleString('en-US') + (amt >= 0 ? ' (واریز)' : ' (برداشت)'));
        }
        const desc = String(bankItem.description || '').trim();
        if (desc) parts.push('شرح: ' + desc);
        const cp = String(bankItem.counterparty || '').trim();
        if (cp) parts.push('طرف: ' + cp);
        const track = String(bankItem.tracking_number || '').trim();
        if (track) parts.push('پیگیری: ' + track);
        const ref = String(bankItem.reference_number || '').trim();
        if (ref && ref !== track) parts.push('مرجع: ' + ref);
        const doc = String(bankItem.document_number || '').trim();
        if (doc) parts.push('سند: ' + doc);
        const branch = String(bankItem.branch || '').trim();
        if (branch) parts.push('شعبه: ' + branch);
        const note = String(bankItem.note || '').trim();
        if (note) parts.push('یادداشت: ' + note);
        return parts.join(' | ');
    }

    function composeEnrichedDescription(oldDesc, bankItem) {
        const base = String(oldDesc || '').trim();
        const marker = '[جزئیات بانک]';
        if (base.indexOf(marker) !== -1) return base;
        const snippet = buildBankDetailSnippet(bankItem);
        if (!snippet) return base;
        const extra = marker + ' ' + snippet;
        return base === '' ? extra : (base + '\n' + extra);
    }

    function populateAccounts() {
        const list = App.getCache().accounts || [];
        const current = $('#bank-import-account').val();
        destroySearchableSelect('#bank-import-account');
        const sel = $('#bank-import-account').empty();
        list.forEach(a => {
            const tag = (a.is_cash == 1 || a.is_cash === true || a.is_cash === '1') ? ' (صندوق)' : '';
            sel.append(`<option value="${a.id}">${UI.escapeHtml(a.name)}${tag}</option>`);
        });
        if (current) sel.val(current);
        applySearchableSelect('#bank-import-account', {
            placeholder: 'جستجو و انتخاب حساب...',
            language: { noResults: () => 'موردی یافت نشد' }
        });
    }

    function renderPreview(summary) {
        const box = $('#bank-import-preview').removeClass('d-none');
        const fmt = formatLabels[summary.format] || summary.format;
        const accountNo = summary.meta && summary.meta.account_number ? summary.meta.account_number : '';
        const accountId = $('#bank-import-account').val();
        const account = (App.getCache().accounts || []).find(a => String(a.id) === String(accountId));
        const bookBal = account ? parseFloat(account.current_balance || 0) : null;
        let balanceHtml = '';
        if (summary.opening_balance != null || summary.closing_balance != null || bookBal != null) {
            const stmtClose = summary.closing_balance;
            const closeDiff = (stmtClose != null && bookBal != null) ? (bookBal - stmtClose) : null;
            balanceHtml = `<div class="col-12"><div class="border rounded p-2 bg-white mt-1">
                <div class="small fw-semibold mb-1">مقایسه مانده (دفتر / صورتحساب)</div>
                <div class="row g-2 small">
                    ${summary.opening_balance != null ? `<div class="col-md-4"><span class="text-muted">افتتاحیه بانک:</span> ${UI.formatCurrency(summary.opening_balance)}</div>` : ''}
                    ${stmtClose != null ? `<div class="col-md-4"><span class="text-muted">اختتامیه بانک:</span> ${UI.formatCurrency(stmtClose)}</div>` : ''}
                    ${bookBal != null ? `<div class="col-md-4"><span class="text-muted">موجودی فعلی دفتر:</span> ${UI.formatCurrency(bookBal)}</div>` : ''}
                    ${closeDiff != null ? `<div class="col-md-4"><span class="text-muted">مغایرت (دفتر − بانک):</span> <span class="${Math.abs(closeDiff) < 1 ? 'text-success' : 'text-danger'}">${UI.formatCurrency(closeDiff)}</span></div>` : ''}
                </div>
            </div></div>`;
        }
        const rowsHtml = (summary.preview || []).map(r => {
            const amtClass = r.amount >= 0 ? 'text-success' : 'text-danger';
            return `<tr>
                <td>${UI.gregorianToPersian(r.date)}</td>
                <td class="small">${UI.escapeHtml((r.description || '').slice(0, 80))}</td>
                <td class="text-end ${amtClass}">${UI.formatCurrency(r.amount)}</td>
            </tr>`;
        }).join('');
        box.html(`
            <div class="border rounded p-3 bg-light">
                <div class="row g-2 small mb-2">
                    <div class="col-md-3"><strong>قالب:</strong> ${fmt}</div>
                    <div class="col-md-3"><strong>تعداد:</strong> ${summary.row_count}</div>
                    <div class="col-md-3"><strong>واریز:</strong> <span class="text-success">${UI.formatCurrency(summary.credit_total)}</span></div>
                    <div class="col-md-3"><strong>برداشت:</strong> <span class="text-danger">${UI.formatCurrency(summary.debit_total)}</span></div>
                    <div class="col-md-6"><strong>بازه:</strong> ${UI.gregorianToPersian(summary.period_start)} تا ${UI.gregorianToPersian(summary.period_end)}</div>
                    <div class="col-md-6 text-muted">${UI.escapeHtml(summary.currency_note || '')}${accountNo ? ' | حساب: ' + UI.escapeHtml(accountNo) : ''}</div>
                    ${balanceHtml}
                </div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0"><thead><tr><th>تاریخ</th><th>شرح</th><th class="text-end">مبلغ</th></tr></thead>
                    <tbody>${rowsHtml}</tbody></table>
                </div>
            </div>
        `);
    }

    async function parseSelectedFile() {
        const fileInput = document.getElementById('bank-import-file');
        if (!fileInput.files.length) {
            UI.showError('لطفاً فایل انتخاب کنید.');
            return null;
        }
        if (typeof BankStatementParsers === 'undefined') {
            UI.showError('ماژول پارس صورتحساب بارگذاری نشده است.');
            return null;
        }
        try {
            UI.showLoader();
            const result = await BankStatementParsers.parseFile(fileInput.files[0]);
            parsedPayload = {
                rows: result.rows,
                summary: result.summary,
                fileName: fileInput.files[0].name
            };
            renderPreview(result.summary);
            return parsedPayload;
        } catch (e) {
            UI.showError(e.message || 'خطا در خواندن فایل');
            parsedPayload = null;
            $('#bank-import-preview').addClass('d-none').empty();
            return null;
        } finally {
            UI.hideLoader();
        }
    }

    async function loadBankImportsList() {
        const tbody = $('#bank-imports-list-tbody').html('<tr><td colspan="8">بارگذاری...</td></tr>');
        const result = await Api.call('get_bank_imports');
        tbody.empty();
        const items = result?.items || [];
        if (!items.length) {
            tbody.html('<tr><td colspan="8" class="text-center text-muted">صورتحساب بانکی وارد نشده.</td></tr>');
            return;
        }
        items.forEach(bi => {
            const period = (bi.period_start && bi.period_end)
                ? `${UI.gregorianToPersian(bi.period_start)} — ${UI.gregorianToPersian(bi.period_end)}`
                : '-';
            tbody.append(`<tr>
                <td>${UI.gregorianToPersian((bi.import_date || '').slice(0, 10))}</td>
                <td>${UI.escapeHtml(bi.account_name || '-')}</td>
                <td>${formatLabels[bi.bank_format] || bi.bank_format || '-'}</td>
                <td class="small">${period}</td>
                <td>${bi.total_rows || 0}</td>
                <td>${bi.matched_rows || 0}</td>
                <td>${statusLabels[bi.status] || bi.status || '-'}</td>
                <td class="text-nowrap">
                    <button class="btn btn-sm btn-info bank-import-open" data-id="${bi.id}" title="میز کار تطبیق"><i class="bi bi-eye"></i></button>
                    <button class="btn btn-sm btn-outline-danger bank-import-delete" data-id="${bi.id}" title="حذف"><i class="bi bi-trash"></i></button>
                </td>
            </tr>`);
        });
    }

    function renderSummary(summary, imp) {
        const fmtDiff = (val) => {
            if (val === null || val === undefined) return '-';
            const cls = Math.abs(val) < 1 ? 'text-success' : 'text-danger';
            return `<span class="${cls}">${UI.formatCurrency(val)}</span>`;
        };
        const stmtOpen = summary.statement_opening_balance;
        const stmtClose = summary.statement_closing_balance;
        const bookCurrent = summary.account_balance_current ?? summary.account_balance;
        const bookStart = summary.book_balance_period_start;
        const bookEnd = summary.book_balance_period_end;
        const closingDiff = summary.closing_diff ?? summary.current_vs_statement_closing_diff;
        const openingDiff = summary.opening_diff;

        $('#bank-reconcile-summary').html(`
            <div class="col-md-2"><div class="border rounded p-2 h-100"><div class="small text-muted">پیشرفت</div><div class="fw-bold">${summary.progress_pct || 0}%</div></div></div>
            <div class="col-md-2"><div class="border rounded p-2 h-100"><div class="small text-muted">تطبیق‌شده</div><div class="fw-bold text-success">${summary.matched || 0}</div></div></div>
            <div class="col-md-2"><div class="border rounded p-2 h-100"><div class="small text-muted">باقیمانده</div><div class="fw-bold text-warning">${summary.unmatched || 0}</div></div></div>
            <div class="col-12"><div class="border rounded p-2 bg-light">
                <div class="small fw-semibold mb-2">تطبیق مانده حساب (دفتر ↔ صورتحساب)</div>
                <div class="row g-2 small">
                    <div class="col-md-3"><div class="text-muted">مانده دفتر (فعلی)</div><div class="fw-bold">${UI.formatCurrency(bookCurrent)}</div></div>
                    <div class="col-md-3"><div class="text-muted">مانده دفتر (پایان بازه)</div><div>${bookEnd != null ? UI.formatCurrency(bookEnd) : '-'}</div></div>
                    <div class="col-md-3"><div class="text-muted">مانده بانک (اختتامیه)</div><div>${stmtClose != null ? UI.formatCurrency(stmtClose) : '-'}</div></div>
                    <div class="col-md-3"><div class="text-muted">مغایرت پایان بازه</div><div class="fw-bold">${fmtDiff(closingDiff)}</div></div>
                    ${stmtOpen != null || bookStart != null ? `
                    <div class="col-md-3"><div class="text-muted">مانده دفتر (شروع بازه)</div><div>${bookStart != null ? UI.formatCurrency(bookStart) : '-'}</div></div>
                    <div class="col-md-3"><div class="text-muted">مانده بانک (افتتاحیه)</div><div>${stmtOpen != null ? UI.formatCurrency(stmtOpen) : '-'}</div></div>
                    <div class="col-md-3"><div class="text-muted">مغایرت شروع بازه</div><div>${fmtDiff(openingDiff)}</div></div>` : ''}
                </div>
            </div></div>
            <div class="col-md-3"><div class="border rounded p-2 h-100"><div class="small text-muted">واریز/برداشت تطبیق‌نشده</div>
                <div class="small"><span class="text-success">+${UI.formatCurrency(summary.unmatched_credit || 0)}</span>
                / <span class="text-danger">-${UI.formatCurrency(summary.unmatched_debit || 0)}</span></div></div></div>
        `);
        $('#bank-reconcile-subtitle').text(`${imp.account_name || ''} | ${formatLabels[imp.bank_format] || ''} | ${imp.file_name || ''}`);
    }

    function filterLocalItems(items) {
        const q = ($('#bank-reconcile-search').val() || '').trim().toLowerCase();
        if (!q) return items;
        return items.filter(it => {
            const hay = `${it.description || ''} ${it.counterparty || ''} ${it.tracking_number || ''} ${it.reference_number || ''}`.toLowerCase();
            return hay.includes(q);
        });
    }

    function resolvePreviewAfter(changePreview) {
        if (!changePreview || changePreview.error) return null;
        return changePreview.after_match || changePreview.after_adjust || null;
    }

    function formatChangeDiff(before, after, feeExpense) {
        if (!before || !after) return '';
        const lines = [];
        const bd = String(before.description || '').trim();
        const ad = String(after.description || '').trim();
        if (bd !== ad) {
            lines.push(`شرح قبل: ${bd || '(خالی)'}`);
            lines.push(`شرح بعد: ${ad || '(خالی)'}`);
        }
        if (feeExpense && feeExpense.amount) {
            lines.push(`هزینه کارمزد جدید: ${UI.formatCurrency(feeExpense.amount)} (${feeExpense.category || 'بانک / کارمزد'})`);
            if (feeExpense.description) {
                lines.push(`شرح هزینه: ${feeExpense.description}`);
            }
        }
        return lines.join('\n');
    }

    function renderChangePreviewHtml(changePreview, includeFee) {
        if (!changePreview || changePreview.error) return '';
        const before = changePreview.before;
        const after = resolvePreviewAfter(changePreview);
        if (!before || !after) return '';
        const bd = String(before.description || '').trim();
        const ad = String(after.description || '').trim();
        const descChanged = bd !== ad;
        const fee = includeFee ? changePreview.fee_expense : null;
        if (!descChanged && !fee) {
            return '<div class="small text-muted mt-1">بدون تغییر شرح؛ مبلغ اصلی ثابت می‌ماند</div>';
        }
        let html = '<div class="small mt-1 border-top pt-1 bank-txn-change">';
        html += '<div class="fw-semibold text-primary mb-1">تغییرات پس از تأیید</div>';
        html += `<div class="text-muted">مبلغ اصلی تراکنش ثابت می‌ماند: <strong>${UI.formatCurrency(before.amount)}</strong></div>`;
        if (descChanged) {
            html += `<div class="text-muted">شرح قبل: ${UI.escapeHtml(bd || '(خالی)')}</div>`;
            html += `<div>شرح بعد: ${UI.escapeHtml(ad || '(خالی)')}</div>`;
        }
        if (fee && fee.amount) {
            html += `<div class="text-warning mt-1">ثبت هزینه کارمزد: <strong>${UI.formatCurrency(fee.amount)}</strong> — ${UI.escapeHtml(fee.category || 'بانک / کارمزد')}</div>`;
            html += `<div class="text-muted">${UI.escapeHtml((fee.description || '').slice(0, 160))}</div>`;
        }
        html += '</div>';
        return html;
    }

    function buildConfirmMessageForPreview(changePreview, includeFee, intro) {
        let msg = intro || 'این پیشنهاد تطبیق تأیید و ثبت شود؟';
        const before = changePreview && changePreview.before;
        const after = resolvePreviewAfter(changePreview);
        const fee = includeFee && changePreview ? changePreview.fee_expense : null;
        const diff = formatChangeDiff(before, after, fee);
        if (diff) {
            msg += '\n\nتغییرات:\n' + diff;
        } else if (changePreview && changePreview.supports_description) {
            msg += '\n\nجزئیات ردیف بانکی به شرح تراکنش اضافه می‌شود (در صورت تکراری نبودن).';
        }
        msg += '\n\nمبلغ اصلی تراکنش تغییر نمی‌کند.';
        return msg;
    }

    function hasFeeExpense(item) {
        return !!(item && (item.needs_fee_expense || (item.change_preview && item.change_preview.fee_expense)));
    }

    function reasonsHtml(reasons) {
        if (!reasons || !reasons.length) return '';
        return `<div class="small text-muted mt-1">${reasons.slice(0, 4).map(r => UI.escapeHtml(r)).join(' · ')}</div>`;
    }

    function renderWorkspaceItems(items) {
        const tbody = $('#bank-reconcile-items-tbody').empty();
        const filtered = filterLocalItems(items);
        if (!filtered.length) {
            tbody.html('<tr><td colspan="8" class="text-center text-muted">موردی نیست.</td></tr>');
            return;
        }
        filtered.forEach(it => {
            const amt = parseFloat(it.amount || 0);
            const amtClass = amt >= 0 ? 'text-success' : 'text-danger';
            const suggestions = it.suggestions || [];
            let suggestHtml = '';
            if (it.match_status === 'unmatched' && suggestions.length) {
                const top = suggestions[0];
                const feeHint = hasFeeExpense(top)
                    ? `<div class="small text-warning mt-1">کارمزد ${UI.formatCurrency(top.fee || (top.bank_abs_amount - top.book_abs_amount))} به‌صورت هزینه جدا ثبت می‌شود (مبلغ اصلی ثابت)</div>`
                    : '';
                const previewHtml = renderChangePreviewHtml(top.change_preview, hasFeeExpense(top));
                const priorBadge = top.prior_match
                    ? '<span class="badge bg-info text-dark ms-1">تطبیق قبلی</span>'
                    : '';
                suggestHtml = `<div class="small mb-1">
                    <span class="badge bg-primary">پیشنهاد ${top.score}</span>${priorBadge}
                    ${UI.escapeHtml((top.label || '').slice(0, 60))}
                    ${reasonsHtml(top.reasons)}
                    ${feeHint}
                    ${previewHtml}
                </div>
                <button class="btn btn-sm btn-success bank-apply-suggestion"
                    data-id="${it.id}" data-source="${top.source}" data-ref="${top.ref_id}"
                    data-needs-fee="${hasFeeExpense(top) ? 1 : 0}"
                    data-fee="${top.fee || ''}"
                    data-book="${top.book_abs_amount || ''}" data-bank="${top.bank_abs_amount || ''}">تأیید این پیشنهاد</button>`;
            }
            let actions = '';
            if (it.match_status === 'unmatched') {
                actions = `
                    ${suggestHtml}
                    <div class="btn-group btn-group-sm mt-1">
                        <button class="btn btn-outline-primary bank-manual-match" data-id="${it.id}">دستی</button>
                        <button class="btn btn-outline-success bank-create-from" data-id="${it.id}">ثبت</button>
                        <button class="btn btn-outline-secondary bank-ignore-item" data-id="${it.id}">نادیده</button>
                    </div>`;
            } else if (it.match_status === 'matched') {
                actions = `<button class="btn btn-sm btn-outline-warning bank-unmatch-item" data-id="${it.id}">لغو تطبیق</button>
                    <div class="small text-muted mt-1">${UI.escapeHtml(ledgerSourceLabel(it.matched_source))} #${it.matched_ref_id || it.matched_payment_id || ''}</div>`;
            } else {
                actions = `<button class="btn btn-sm btn-outline-primary bank-unignore-item" data-id="${it.id}">بازگردانی</button>`;
            }
            tbody.append(`<tr data-id="${it.id}">
                <td class="small text-nowrap">${UI.gregorianToPersian(it.transaction_date)}${it.transaction_time ? `<br><span class="text-muted">${UI.escapeHtml(it.transaction_time)}</span>` : ''}</td>
                <td class="small">${UI.escapeHtml((it.description || '').slice(0, 120))}${it.note ? `<br><span class="text-muted">${UI.escapeHtml(it.note)}</span>` : ''}</td>
                <td class="small">${UI.escapeHtml(it.counterparty || '-')}</td>
                <td class="text-end ${amtClass} text-nowrap">${UI.formatCurrency(amt)}</td>
                <td class="text-end small">${it.balance_after != null ? UI.formatCurrency(it.balance_after) : '-'}</td>
                <td class="small">${UI.escapeHtml(it.tracking_number || it.reference_number || '-')}</td>
                <td>${statusLabels[it.match_status] || it.match_status}</td>
                <td>${actions}</td>
            </tr>`);
        });
    }

    function showProposalsModal(proposals) {
        pendingProposals = proposals || [];
        const tbody = $('#bank-proposals-tbody').empty();
        const empty = $('#bank-proposals-empty');
        if (!pendingProposals.length) {
            tbody.empty();
            empty.removeClass('d-none');
            $('#bank-proposals-count').text('۰ پیشنهاد');
        } else {
            empty.addClass('d-none');
            $('#bank-proposals-count').text(`${pendingProposals.length} پیشنهاد`);
            pendingProposals.forEach((p, idx) => {
                const bankAmtClass = p.bank_amount >= 0 ? 'text-success' : 'text-danger';
                const ledAmtClass = p.ledger_amount >= 0 ? 'text-success' : 'text-danger';
                const feeAmt = p.fee || (p.change_preview && p.change_preview.fee_expense
                    ? p.change_preview.fee_expense.amount : 0);
                const feeRow = hasFeeExpense(p)
                    ? `<div class="form-check mt-1">
                        <input class="form-check-input bank-proposal-fee" type="checkbox" data-idx="${idx}" id="fee-${idx}" checked>
                        <label class="form-check-label small text-warning" for="fee-${idx}">
                            ثبت کارمزد ${UI.formatCurrency(feeAmt)} به‌صورت هزینه جدا (مبلغ اصلی تراکنش ثابت می‌ماند)
                        </label>
                       </div>`
                    : '';
                tbody.append(`<tr>
                    <td><input type="checkbox" class="form-check-input bank-proposal-check" data-idx="${idx}" checked></td>
                    <td><span class="badge bg-primary">${p.score}</span>${p.prior_match ? ' <span class="badge bg-info text-dark">قبلی</span>' : ''}</td>
                    <td class="small">
                        <div>${UI.gregorianToPersian(p.transaction_date)} · <span class="${bankAmtClass}">${UI.formatCurrency(p.bank_amount)}</span></div>
                        <div class="text-muted">${UI.escapeHtml((p.bank_counterparty || p.bank_description || '').slice(0, 90))}</div>
                    </td>
                    <td class="small">
                        <div>${UI.gregorianToPersian(p.ledger_date)} · <span class="${ledAmtClass}">${UI.formatCurrency(p.ledger_amount)}</span></div>
                        <div>${UI.escapeHtml((p.label || '').slice(0, 90))}</div>
                        ${feeRow}
                        <div class="bank-proposal-preview" data-idx="${idx}">${renderChangePreviewHtml(p.change_preview, hasFeeExpense(p))}</div>
                    </td>
                    <td class="small text-muted">${(p.reasons || []).slice(0, 5).map(r => UI.escapeHtml(r)).join('<br>')}</td>
                </tr>`);
            });
        }
        bootstrap.Modal.getOrCreateInstance(document.getElementById('bankProposalsModal')).show();
    }

    async function runSmartPropose() {
        const importId = $('#bank-reconcile-import-id').val();
        if (!importId) return;
        const dateWindow = parseInt($('#bank-reconcile-date-window').val(), 10) || 2;
        UI.showLoader();
        const result = await Api.call('propose_bank_matches', {
            import_id: importId,
            date_window: dateWindow
        });
        UI.hideLoader();
        if (!result || result.error) {
            UI.showError(result?.error || 'خطا در پیشنهاد هوشمند');
            return;
        }
        showProposalsModal(result.proposals || []);
        if (!(result.proposals || []).length) {
            UI.showInfo('پیشنهاد مطمئنی با معیارهای فعلی یافت نشد. بازه تاریخ را افزایش دهید یا تطبیق دستی کنید.');
        }
    }

    async function confirmSelectedProposals() {
        const importId = $('#bank-reconcile-import-id').val();
        const matches = [];
        let feeCount = 0;
        let descChangeCount = 0;
        const detailLines = [];
        $('.bank-proposal-check:checked').each(function () {
            const idx = parseInt($(this).data('idx'), 10);
            const p = pendingProposals[idx];
            if (!p) return;
            const createFee = hasFeeExpense(p) && $(`.bank-proposal-fee[data-idx="${idx}"]`).is(':checked');
            if (createFee) feeCount++;
            const before = p.change_preview && p.change_preview.before;
            const after = resolvePreviewAfter(p.change_preview);
            const fee = createFee && p.change_preview ? p.change_preview.fee_expense : null;
            if (before && after) {
                const bd = String(before.description || '').trim();
                const ad = String(after.description || '').trim();
                if (bd !== ad) descChangeCount++;
                if (detailLines.length < 3) {
                    const diff = formatChangeDiff(before, after, fee);
                    if (diff) detailLines.push(`#${p.item_id}\n${diff}`);
                }
            } else if (p.change_preview && p.change_preview.supports_description) {
                descChangeCount++;
            }
            matches.push({
                item_id: p.item_id,
                source: p.source,
                ref_id: p.ref_id,
                ...(hasFeeExpense(p) ? { create_fee_expense: !!createFee } : {})
            });
        });
        if (!matches.length) {
            UI.showError('حداقل یک مورد را انتخاب کنید.');
            return;
        }
        let msg = `${matches.length} تطبیق انتخاب‌شده ثبت شود؟`;
        msg += '\n\nمبلغ اصلی تراکنش‌ها تغییر نمی‌کند.';
        if (descChangeCount > 0) {
            msg += `\nبرای ${descChangeCount} مورد، جزئیات صورتحساب بانکی به شرح اضافه می‌شود.`;
        }
        if (feeCount > 0) {
            msg += `\nبرای ${feeCount} مورد، کارمزد به‌صورت هزینه جداگانه روی حساب مبدأ ثبت می‌شود.`;
        }
        if (detailLines.length) {
            msg += '\n\nنمونه تغییرات:\n' + detailLines.join('\n\n');
        }
        if (!await UI.confirmText(msg)) return;
        UI.showLoader();
        const result = await Api.call('confirm_bank_matches', {
            import_id: importId,
            matches
        });
        UI.hideLoader();
        if (result?.success) {
            UI.showSuccess(result.message || 'تطبیق‌ها ثبت شد.');
            bootstrap.Modal.getInstance(document.getElementById('bankProposalsModal'))?.hide();
            await refreshWorkspace();
            loadBankImportsList();
        } else {
            UI.showError(result?.error || 'خطا در ثبت تطبیق');
        }
    }

    async function openWorkspace(importId, options = {}) {
        $('#bank-reconcile-import-id').val(importId);
        const status = $('#bank-reconcile-status-filter').val();
        const dateWindow = parseInt($('#bank-reconcile-date-window').val(), 10) || 2;
        UI.showLoader();
        const result = await Api.call('get_bank_import_detail', {
            import_id: importId,
            status: status || null,
            date_window: dateWindow,
            include_suggestions: true
        });
        UI.hideLoader();
        if (!result || result.error) {
            UI.showError(result?.error || 'خطا در بارگذاری');
            return;
        }
        workspaceItems = result.items || [];
        $('#bank-reconcile-account-id').val(result.import.account_id);
        renderSummary(result.summary || {}, result.import || {});
        renderWorkspaceItems(workspaceItems);
        const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('bankReconcileModal'));
        modal.show();
        if (options.proposals && options.proposals.length) {
            setTimeout(() => showProposalsModal(options.proposals), 350);
        }
    }

    async function refreshWorkspace() {
        const importId = $('#bank-reconcile-import-id').val();
        if (importId) await openWorkspace(importId);
    }

    function applySearchableSelect(selector, options = {}) {
        const el = $(selector);
        if (!el.length || typeof el.select2 !== 'function') return;
        if (el.data('select2')) el.select2('destroy');
        el.select2(Object.assign({
            theme: 'bootstrap-5',
            dir: 'rtl',
            width: '100%'
        }, options));
    }

    function destroySearchableSelect(selector) {
        const el = $(selector);
        if (el.length && typeof el.select2 === 'function' && el.data('select2')) {
            el.select2('destroy');
        }
    }

    function ledgerSourceLabel(source) {
        const map = {
            payment: 'پرداخت/دریافت',
            expense: 'هزینه',
            income: 'درآمد',
            check: 'چک'
        };
        return map[source] || source || '';
    }

    function getExpenseCategories() {
        const settings = (App.getCache() || {}).publicSettings || {};
        return String(settings.expense_categories || '')
            .split(',')
            .map(c => c.trim())
            .filter(Boolean);
    }

    function getIncomeCategories() {
        const settings = (App.getCache() || {}).publicSettings || {};
        return String(settings.income_categories || 'سود سپرده بانکی,برگشت کارمزد,اجاره دریافتی,سایر درآمدها,متفرقه')
            .split(',')
            .map(c => c.trim())
            .filter(Boolean);
    }

    function guessCategoryForItem(item) {
        const text = String((item && item.description) || '');
        if (!/کارمزد|fee|commission/i.test(text)) return '';
        const feeCategory = getExpenseCategories().find(c => /کارمزد|بانک/.test(c));
        return feeCategory || 'بانک / کارمزد';
    }

    function fillCategorySelect(selected = '', kind = 'expense') {
        const sel = $('#bank-create-category').empty().append('<option value=""></option>');
        const categories = kind === 'income' ? getIncomeCategories() : getExpenseCategories();
        if (selected && !categories.includes(selected)) categories.push(selected);
        categories.forEach(cat => {
            sel.append(`<option value="${UI.escapeHtml(cat)}">${UI.escapeHtml(cat)}</option>`);
        });
        sel.val(selected || '');
        applySearchableSelect('#bank-create-category', {
            tags: true,
            placeholder: 'جستجو یا افزودن دسته‌بندی...',
            dropdownParent: $('#bankCreateFromItemModal'),
            language: { noResults: () => 'موردی یافت نشد' }
        });
        sel.trigger('change.select2');
    }

    function fillPersonSelect(personType, targetSelector = '#bank-create-person-id') {
        const cache = App.getCache() || {};
        const map = {
            customer: cache.customers || [],
            supplier: cache.suppliers || [],
            partner: cache.partners || [],
            contact: cache.contacts || []
        };
        const sel = $(targetSelector);
        destroySearchableSelect(targetSelector);
        sel.removeClass('d-none');
        sel.empty().append('<option value=""></option>');
        (map[personType] || []).forEach(p => {
            sel.append(`<option value="${p.id}">${UI.escapeHtml(p.name)}</option>`);
        });
        applySearchableSelect(targetSelector, {
            placeholder: 'جستجو و انتخاب شخص...',
            dropdownParent: $('#bankCreateFromItemModal'),
            language: { noResults: () => 'موردی یافت نشد' }
        });
        sel.val('').trigger('change.select2');
    }

    function fillOtherAccountSelect(excludeAccountId) {
        const list = App.getCache().accounts || [];
        destroySearchableSelect('#bank-create-other-account');
        const sel = $('#bank-create-other-account').empty().append('<option value=""></option>');
        list.forEach(a => {
            if (String(a.id) === String(excludeAccountId)) return;
            const tag = (a.is_cash == 1 || a.is_cash === true || a.is_cash === '1') ? ' (صندوق)' : '';
            sel.append(`<option value="${a.id}">${UI.escapeHtml(a.name)}${tag} — ${UI.formatCurrency(a.current_balance || 0)}</option>`);
        });
        applySearchableSelect('#bank-create-other-account', {
            placeholder: 'جستجو و انتخاب حساب...',
            dropdownParent: $('#bankCreateFromItemModal'),
            language: { noResults: () => 'موردی یافت نشد' }
        });
    }

    function getBankCreateAmountAbs() {
        return Math.abs(parseFloat($('#bank-create-bank-amount').val() || '0') || 0);
    }

    function parseAmountInput(raw) {
        return Math.abs(parseFloat(String(raw || '').replace(/,/g, '')) || 0);
    }

    function formatAmountInput(num) {
        const n = Math.round(parseFloat(num) || 0);
        return n.toLocaleString('en-US');
    }

    function updateTransferHint() {
        const amt = parseFloat($('#bank-create-bank-amount').val() || '0');
        const bankName = (App.getCache().accounts || []).find(a => String(a.id) === String($('#bank-create-bank-account-id').val()));
        const bankLabel = bankName ? bankName.name : 'حساب صورتحساب';
        if (amt < 0) {
            $('#bank-create-transfer-label').text('انتقال به حساب (مقصد)');
            $('#bank-create-transfer-hint').text(`مبلغ از «${bankLabel}» برداشت و به حساب انتخاب‌شده واریز می‌شود.`);
        } else {
            $('#bank-create-transfer-label').text('انتقال از حساب (مبدأ)');
            $('#bank-create-transfer-hint').text(`مبلغ از حساب انتخاب‌شده برداشت و به «${bankLabel}» واریز می‌شود.`);
        }
    }

    function updateSplitTotalLine() {
        const target = getBankCreateAmountAbs();
        let sum = 0;
        $('#bank-create-split-rows .bank-split-amount').each(function () {
            sum += parseAmountInput($(this).val());
        });
        const diff = target - sum;
        const ok = Math.abs(diff) < 1;
        $('#bank-create-split-total-line').html(
            `جمع سهم‌ها: <strong>${UI.formatCurrency(sum)}</strong> / مبلغ بانک: <strong>${UI.formatCurrency(target)}</strong>`
            + (ok ? ' <span class="text-success">✓</span>' : ` <span class="text-danger">(باقیمانده: ${UI.formatCurrency(diff)})</span>`)
        );
    }

    function fillSplitPersonSelect($row, personType) {
        const $sel = $row.find('.bank-split-person-id');
        const cache = App.getCache() || {};
        const map = {
            customer: cache.customers || [],
            supplier: cache.suppliers || [],
            partner: cache.partners || [],
            contact: cache.contacts || []
        };
        $sel.empty().append('<option value=""></option>');
        (map[personType] || []).forEach(p => {
            $sel.append(`<option value="${p.id}">${UI.escapeHtml(p.name)}</option>`);
        });
    }

    function addSplitRow(defaults = {}) {
        const idx = $('#bank-create-split-rows tr').length;
        const personType = defaults.person_type || 'partner';
        const row = $(`
            <tr data-row="${idx}">
                <td>
                    <select class="form-select form-select-sm bank-split-person-type">
                        <option value="customer">مشتری</option>
                        <option value="supplier">تامین‌کننده</option>
                        <option value="partner" selected>شریک</option>
                        <option value="contact">سایر اشخاص</option>
                    </select>
                </td>
                <td><select class="form-select form-select-sm bank-split-person-id"></select></td>
                <td><input type="text" class="form-control form-control-sm numeric-input text-end bank-split-amount" value="${defaults.amount ? formatAmountInput(defaults.amount) : '0'}"></td>
                <td><button type="button" class="btn btn-sm btn-outline-danger bank-split-remove" title="حذف"><i class="bi bi-x"></i></button></td>
            </tr>
        `);
        row.find('.bank-split-person-type').val(personType);
        fillSplitPersonSelect(row, personType);
        if (defaults.person_id) {
            row.find('.bank-split-person-id').val(String(defaults.person_id));
        }
        $('#bank-create-split-rows').append(row);
        updateSplitTotalLine();
    }

    function initSplitRows(count = 2) {
        $('#bank-create-split-rows').empty();
        const total = getBankCreateAmountAbs();
        const base = count > 0 ? Math.floor(total / count) : 0;
        let remainder = total - (base * count);
        for (let i = 0; i < count; i++) {
            const amt = base + (i === 0 ? remainder : 0);
            addSplitRow({ person_type: 'partner', amount: amt });
        }
        const amt = parseFloat($('#bank-create-bank-amount').val() || '0');
        const hint = amt >= 0
            ? 'برای واریز بانکی، برای هر سهم یک «دریافت» جدا ثبت می‌شود.'
            : 'برای برداشت بانکی، برای هر سهم یک «پرداخت» جدا ثبت می‌شود.';
        $('#bank-create-split-txn-hint').text(hint);
        updateSplitTotalLine();
    }

    function equalSplitRows() {
        const count = $('#bank-create-split-rows tr').length;
        if (count < 1) return;
        initSplitRows(count);
    }

    function collectSplitPayload() {
        const splits = [];
        $('#bank-create-split-rows tr').each(function () {
            const personType = $(this).find('.bank-split-person-type').val();
            const personId = parseInt($(this).find('.bank-split-person-id').val(), 10);
            const amount = parseAmountInput($(this).find('.bank-split-amount').val());
            if (!personId || amount <= 0) return;
            splits.push({ person_type: personType, person_id: personId, amount });
        });
        return splits;
    }

    function configureBankCreateTypeOptions(isCredit) {
        const sel = $('#bank-create-type');
        const current = sel.val();
        sel.find('option').prop('disabled', false);
        if (isCredit) {
            sel.find('option[value="expense"], option[value="payment"]').prop('disabled', true);
        } else {
            sel.find('option[value="receipt"], option[value="income"]').prop('disabled', true);
        }
        const firstEnabled = sel.find('option:not(:disabled)').first().val();
        if (sel.find('option:selected').prop('disabled')) {
            sel.val(firstEnabled);
        } else if (current) {
            sel.val(current);
        }
    }

    function updateBankCreateTypePanels() {
        const t = $('#bank-create-type').val();
        $('#bank-create-category-wrap, #bank-create-person-wrap, #bank-create-transfer-wrap, #bank-create-split-wrap')
            .addClass('d-none');
        destroySearchableSelect('#bank-create-person-id');
        destroySearchableSelect('#bank-create-other-account');

        if (t === 'expense' || t === 'income') {
            $('#bank-create-category-label').text(t === 'income' ? 'دسته‌بندی درآمد' : 'دسته‌بندی هزینه');
            $('#bank-create-category-wrap').removeClass('d-none');
            fillCategorySelect($('#bank-create-category').val() || '', t);
            $('#bank-create-person-label').text('طرف‌حساب (اختیاری)');
            $('#bank-create-person-wrap').removeClass('d-none');
            fillPersonSelect($('#bank-create-person-type').val());
        } else if (t === 'receipt' || t === 'payment') {
            $('#bank-create-person-label').text('شخص');
            $('#bank-create-person-wrap').removeClass('d-none');
            fillPersonSelect($('#bank-create-person-type').val());
        } else if (t === 'transfer') {
            $('#bank-create-transfer-wrap').removeClass('d-none');
            fillOtherAccountSelect($('#bank-create-bank-account-id').val());
            updateTransferHint();
        } else if (t === 'split') {
            $('#bank-create-split-wrap').removeClass('d-none');
            if (!$('#bank-create-split-rows tr').length) {
                initSplitRows(2);
            } else {
                updateSplitTotalLine();
            }
        }
    }

    function bindEvents() {
        $('button[data-bs-toggle="tab"][data-bs-target="#bank-import-pane"]').on('shown.bs.tab', function () {
            populateAccounts();
            loadBankImportsList();
        });

        $('#bank-import-preview-btn').on('click', async function () {
            await parseSelectedFile();
        });

        $('#bank-import-file').on('change', async function () {
            if (this.files.length) await parseSelectedFile();
        });

        $('#bank-import-btn').on('click', async function () {
            const accountId = $('#bank-import-account').val();
            if (!accountId) { UI.showError('حساب بانکی را انتخاب کنید.'); return; }
            if (!parsedPayload) {
                const ok = await parseSelectedFile();
                if (!ok) return;
            }
            const dateWindow = parseInt($('#bank-import-date-window').val(), 10) || 2;
            const s = parsedPayload.summary;
            UI.showLoader();
            const result = await Api.call('import_bank_statement', {
                account_id: accountId,
                file_name: parsedPayload.fileName,
                bank_format: s.format,
                rows: parsedPayload.rows,
                period_start: s.period_start,
                period_end: s.period_end,
                opening_balance: s.opening_balance,
                closing_balance: s.closing_balance,
                credit_total: s.credit_total,
                debit_total: s.debit_total,
                auto_match: true,
                date_window: dateWindow
            });
            UI.hideLoader();
            if (result?.success) {
                UI.showSuccess(result.message || 'ورود شد.');
                document.getElementById('bank-import-file').value = '';
                parsedPayload = null;
                $('#bank-import-preview').addClass('d-none').empty();
                await loadBankImportsList();
                if (result.import_id) {
                    await openWorkspace(result.import_id, { proposals: result.proposals || [] });
                }
            } else {
                UI.showError(result?.error || 'خطا در ورود');
            }
        });

        $('body').on('click', '.bank-import-open', function () {
            openWorkspace($(this).data('id'));
        });

        $('body').on('click', '.bank-import-delete', async function () {
            const id = $(this).data('id');
            if (!await UI.confirmText('این ورود صورتحساب حذف شود؟\n\nتراکنش‌های ثبت‌شده در دفتر (دریافت/پرداخت/هزینه/درآمد) پاک نمی‌شوند.\nتطبیق‌های انجام‌شده برای ورود مجدد ذخیره می‌مانند.')) return;
            const result = await Api.call('delete_bank_import', { import_id: id });
            if (result?.success) {
                UI.showSuccess(result.message || 'حذف شد.');
                loadBankImportsList();
            } else UI.showError(result?.error || 'خطا');
        });

        $('#bank-reconcile-status-filter, #bank-reconcile-date-window').on('change', refreshWorkspace);
        $('#bank-reconcile-search').on('input', function () {
            renderWorkspaceItems(workspaceItems);
        });
        $('#bank-reconcile-refresh-btn').on('click', refreshWorkspace);
        $('#bank-reconcile-smart-btn').on('click', runSmartPropose);
        $('#bank-proposals-select-all').on('click', function () { $('.bank-proposal-check').prop('checked', true); });
        $('#bank-proposals-select-none').on('click', function () { $('.bank-proposal-check').prop('checked', false); });
        $('#bank-proposals-confirm-btn').on('click', confirmSelectedProposals);

        $('body').on('change', '.bank-proposal-fee', function () {
            const idx = parseInt($(this).data('idx'), 10);
            const p = pendingProposals[idx];
            if (!p) return;
            const includeFee = $(this).is(':checked');
            $(`.bank-proposal-preview[data-idx="${idx}"]`).html(renderChangePreviewHtml(p.change_preview, includeFee));
        });

        $('body').on('click', '.bank-apply-suggestion', async function () {
            const needsFee = String($(this).data('needs-fee')) === '1';
            const feeAmt = $(this).data('fee');
            const itemId = $(this).data('id');
            const item = workspaceItems.find(x => String(x.id) === String(itemId));
            const top = item && item.suggestions && item.suggestions[0] ? item.suggestions[0] : null;
            const changePreview = top && top.change_preview ? top.change_preview : null;

            let createFee = true;
            if (needsFee) {
                createFee = await UI.confirmText(
                    `کارمزد ${UI.formatCurrency(feeAmt)} به‌صورت یک هزینه جداگانه («بانک / کارمزد») در تاریخ همان ردیف بانکی ثبت شود؟\n\nمبلغ اصلی تراکنش تغییر نمی‌کند.\nتأیید = ثبت هزینه کارمزد\nانصراف = فقط تطبیق بدون ثبت کارمزد`
                );
            }
            if (!await UI.confirmText(buildConfirmMessageForPreview(changePreview, createFee && needsFee, 'این پیشنهاد تطبیق تأیید و ثبت شود؟'))) return;

            const payload = {
                id: itemId,
                source: $(this).data('source'),
                ref_id: $(this).data('ref')
            };
            // فقط وقتی از کاربر درباره کارمزد سؤال شده، انتخابش را بفرست؛ وگرنه سرور خودش در صورت اختلاف ثبت می‌کند
            if (needsFee) {
                payload.create_fee_expense = createFee;
            }

            const result = await Api.call('match_bank_item', payload);
            if (result?.success) { UI.showSuccess(result.message || 'تطبیق شد.'); refreshWorkspace(); loadBankImportsList(); }
            else UI.showError(result?.error || 'خطا');
        });

        $('body').on('click', '.bank-ignore-item', async function () {
            const result = await Api.call('ignore_bank_item', { id: $(this).data('id') });
            if (result?.success) { refreshWorkspace(); loadBankImportsList(); }
            else UI.showError(result?.error || 'خطا');
        });

        $('body').on('click', '.bank-unmatch-item, .bank-unignore-item', async function () {
            const result = await Api.call('unmatch_bank_item', { id: $(this).data('id') });
            if (result?.success) { refreshWorkspace(); loadBankImportsList(); }
            else UI.showError(result?.error || 'خطا');
        });

        $('body').on('click', '.bank-create-from', function () {
            const id = $(this).data('id');
            const item = workspaceItems.find(x => String(x.id) === String(id));
            if (!item) return;
            const amt = parseFloat(item.amount || 0);
            const isCredit = amt > 0;
            $('#bank-create-item-id').val(id);
            $('#bank-create-bank-account-id').val($('#bank-reconcile-account-id').val() || '');
            $('#bank-create-bank-amount').val(String(amt));
            $('#bank-create-item-info').text(`${UI.gregorianToPersian(item.transaction_date)} | ${UI.formatCurrency(item.amount)} | ${(item.description || '').slice(0, 80)}`);
            $('#bank-create-description').val(composeEnrichedDescription(item.description || '', item));
            configureBankCreateTypeOptions(isCredit);
            $('#bank-create-type').val(isCredit ? 'income' : 'expense');
            fillCategorySelect(isCredit ? '' : guessCategoryForItem(item), isCredit ? 'income' : 'expense');
            $('#bank-create-split-rows').empty();
            $('#bank-create-type').trigger('change');
            bootstrap.Modal.getOrCreateInstance(document.getElementById('bankCreateFromItemModal')).show();
        });

        $('#bankCreateFromItemModal').on('hidden.bs.modal', function () {
            destroySearchableSelect('#bank-create-category');
            destroySearchableSelect('#bank-create-person-id');
            destroySearchableSelect('#bank-create-other-account');
            $('#bank-create-split-rows').empty();
        });

        $('#bank-create-type').on('change', function () {
            updateBankCreateTypePanels();
        });

        $('#bank-create-person-type').on('change', function () {
            fillPersonSelect($(this).val());
        });

        $('#bank-create-split-add-btn').on('click', function () {
            addSplitRow({ person_type: 'partner', amount: 0 });
        });

        $('#bank-create-split-equal-btn').on('click', equalSplitRows);

        $('body').on('click', '.bank-split-remove', function () {
            if ($('#bank-create-split-rows tr').length <= 2) {
                UI.showError('حداقل ۲ سهم لازم است.');
                return;
            }
            $(this).closest('tr').remove();
            updateSplitTotalLine();
        });

        $('body').on('change', '.bank-split-person-type', function () {
            fillSplitPersonSelect($(this).closest('tr'), $(this).val());
        });

        $('body').on('input', '.bank-split-amount', updateSplitTotalLine);

        $('#bank-create-submit-btn').on('click', async function () {
            const createType = $('#bank-create-type').val();
            const category = ($('#bank-create-category').val() || '').trim();
            const personId = $('#bank-create-person-id').val();
            const payload = {
                id: $('#bank-create-item-id').val(),
                create_type: createType,
                description: $('#bank-create-description').val()
            };

            if (createType === 'expense' && !category) {
                UI.showError('دسته‌بندی هزینه را انتخاب کنید.');
                return;
            }
            if (createType === 'income' && !category) {
                UI.showError('دسته‌بندی درآمد را انتخاب کنید.');
                return;
            }
            if ((createType === 'receipt' || createType === 'payment') && !personId) {
                UI.showError('شخص را انتخاب کنید.');
                return;
            }
            if (createType === 'transfer') {
                const otherAccountId = $('#bank-create-other-account').val();
                if (!otherAccountId) {
                    UI.showError('حساب طرف انتقال را انتخاب کنید.');
                    return;
                }
                payload.other_account_id = otherAccountId;
            }
            if (createType === 'split') {
                const splits = collectSplitPayload();
                if (splits.length < 2) {
                    UI.showError('حداقل ۲ سهم با شخص و مبلغ معتبر وارد کنید.');
                    return;
                }
                const target = getBankCreateAmountAbs();
                const sum = splits.reduce((a, s) => a + s.amount, 0);
                if (Math.abs(sum - target) > 0.01) {
                    UI.showError('جمع سهم‌ها باید دقیقاً برابر مبلغ ردیف بانک باشد.');
                    return;
                }
                payload.splits = splits;
            } else {
                payload.category = category;
                payload.person_type = $('#bank-create-person-type').val();
                payload.person_id = personId || null;
            }

            UI.showLoader();
            const result = await Api.call('create_from_bank_item', payload);
            UI.hideLoader();
            if (result?.success) {
                UI.showSuccess(result.message || 'ثبت و تطبیق شد.');
                bootstrap.Modal.getInstance(document.getElementById('bankCreateFromItemModal'))?.hide();
                refreshWorkspace();
                loadBankImportsList();
                App.fetchInitialCache?.();
            } else UI.showError(result?.error || 'خطا');
        });

        $('body').on('click', '.bank-manual-match', async function () {
            const id = $(this).data('id');
            const item = workspaceItems.find(x => String(x.id) === String(id));
            if (!item) return;
            $('#bank-manual-item-id').val(id);
            $('#bank-manual-search').val('');
            await runManualSearch(item);
            bootstrap.Modal.getOrCreateInstance(document.getElementById('bankManualMatchModal')).show();
        });

        $('#bank-manual-search-btn').on('click', async function () {
            const id = $('#bank-manual-item-id').val();
            const item = workspaceItems.find(x => String(x.id) === String(id));
            if (item) await runManualSearch(item);
        });

        $('body').on('click', '.bank-pick-ledger', async function () {
            const source = $(this).data('source');
            let msg = 'این مورد از دفتر به ردیف بانکی وصل شود؟';
            if (source === 'payment' || source === 'expense' || source === 'income') {
                msg += '\n\nجزئیات ردیف بانکی به شرح تراکنش دفتر اضافه می‌شود.';
            }
            if (!await UI.confirmText(msg)) return;
            const result = await Api.call('match_bank_item', {
                id: $('#bank-manual-item-id').val(),
                source: source,
                ref_id: $(this).data('ref')
            });
            if (result?.success) {
                UI.showSuccess(result.message || 'تطبیق شد.');
                bootstrap.Modal.getInstance(document.getElementById('bankManualMatchModal'))?.hide();
                refreshWorkspace();
                loadBankImportsList();
            } else UI.showError(result?.error || 'خطا');
        });
    }

    async function runManualSearch(item) {
        const box = $('#bank-manual-results').html('<div class="text-muted p-2">جستجو...</div>');
        const result = await Api.call('search_bank_ledger', {
            account_id: $('#bank-reconcile-account-id').val(),
            amount: Math.abs(parseFloat(item.amount)),
            bank_amount: parseFloat(item.amount),
            date: item.transaction_date,
            date_window: parseInt($('#bank-reconcile-date-window').val(), 10) || 2,
            q: $('#bank-manual-search').val()
        });
        const items = result?.items || [];
        if (!items.length) {
            box.html('<div class="text-muted p-2">موردی یافت نشد.</div>');
            return;
        }
        box.empty();
        items.forEach(c => {
            const signed = c.direction > 0 ? c.amount : -c.amount;
            box.append(`<button type="button" class="list-group-item list-group-item-action bank-pick-ledger"
                data-source="${c.source}" data-ref="${c.ref_id}">
                <div class="d-flex justify-content-between">
                    <span>${c.score != null ? `<span class="badge bg-secondary me-1">${c.score}</span>` : ''}${UI.escapeHtml(c.label || '')}</span>
                    <span class="${signed >= 0 ? 'text-success' : 'text-danger'}">${UI.formatCurrency(signed)}</span>
                </div>
                <small class="text-muted">${UI.gregorianToPersian(c.date)} | ${UI.escapeHtml(ledgerSourceLabel(c.source))} #${c.ref_id}${(c.reasons && c.reasons.length) ? ' — ' + UI.escapeHtml(c.reasons.slice(0, 2).join(' · ')) : ''}</small>
            </button>`);
        });
    }

    function init() {
        bindEvents();
        const fmtEl = document.getElementById('bank-import-supported-formats');
        if (fmtEl && typeof BankStatementParsers !== 'undefined' && BankStatementParsers.supportedFormatsLabel) {
            fmtEl.textContent = 'پشتیبانی: ' + BankStatementParsers.supportedFormatsLabel();
        }
    }

    return {
        init,
        loadBankImportsList,
        openWorkspace
    };
})();
