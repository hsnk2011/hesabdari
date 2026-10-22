// /js/period-closing-manager.js

const PeriodClosingManager = (function () {
    let status = {};
    let closings = [];
    let shareHistory = [];
    let currentPreview = null;
    let wizardStep = 1;
    let nextPartnersRows = [];
    let closeBackupFilename = null;

    const closeTypeLabels = {
        annual: 'سالانه',
        interim: 'میان‌دوره‌ای',
        partner_restructure: 'تغییر شرکا',
    };

    function checkStatusLabel(status) {
        const labels = (typeof AppConfig !== 'undefined' && AppConfig.CHECK_STATUS_LABELS && AppConfig.CHECK_STATUS_LABELS.period_closing) || {
            in_hand: 'نزد ما',
            payable: 'پرداختنی',
            endorsed: 'خرج‌شده',
            cashed: 'وصول/پاس‌شده',
            bounced: 'برگشتی',
        };
        return labels[status] || status || '—';
    }

    function formatDateDisplay(isoDate) {
        if (!isoDate) return '—';
        if (typeof UI !== 'undefined' && UI.gregorianToPersian) {
            return UI.gregorianToPersian(isoDate);
        }
        return isoDate;
    }

    function renderStatus() {
        $('#closing-last-date').text(formatDateDisplay(status.lastClosedDate));
        $('#closing-suggested-start').text(formatDateDisplay(status.suggestedStartDate));
        $('#closing-count').text(status.closingCount || 0);

        if (status.isLocked && status.lastClosedDate) {
            $('#closing-lock-alert').show();
            $('#closing-lock-date').text(formatDateDisplay(status.lastClosedDate));
        } else {
            $('#closing-lock-alert').hide();
        }
    }

    function renderClosingsList() {
        const tbody = $('#closings-list-tbody').empty();
        if (!closings.length) {
            tbody.append('<tr><td colspan="6" class="text-center text-muted py-3">هنوز بستن حسابی ثبت نشده است.</td></tr>');
            return;
        }

        const latestCompletedId = closings.find(x => x.status === 'completed')?.id;
        closings.forEach((c) => {
            // Server allows reopening the latest completed closing (not necessarily row 0
            // after a newer closing was reopened and sits above it as "reopened").
            const canReopen = c.status === 'completed' && c.id === latestCompletedId;
            const statusBadge = c.status === 'completed'
                ? '<span class="badge bg-success">بسته</span>'
                : '<span class="badge bg-secondary">بازگشایی شده</span>';

            const row = $(`<tr>
                <td>${UI.escapeHtml(c.title)}</td>
                <td class="small">${formatDateDisplay(c.period_start_date)} تا ${formatDateDisplay(c.period_end_date)}</td>
                <td>${closeTypeLabels[c.close_type] || UI.escapeHtml(c.close_type)}</td>
                <td class="text-end">${UI.formatCurrency(c.net_profit)}</td>
                <td>${statusBadge}</td>
                <td class="text-nowrap">
                    <button class="btn btn-sm btn-outline-primary btn-view-closing" data-id="${c.id}"><i class="bi bi-eye"></i></button>
                    ${canReopen ? `<button class="btn btn-sm btn-outline-danger btn-reopen-closing" data-id="${c.id}" title="بازگشایی"><i class="bi bi-unlock"></i></button>` : ''}
                </td>
            </tr>`);
            tbody.append(row);
        });
        App.applyPermissions();
    }

    function renderShareHistory() {
        const tbody = $('#share-history-tbody').empty();
        if (!shareHistory.length) {
            tbody.append('<tr><td colspan="4" class="text-center text-muted py-2">تاریخچه‌ای ثبت نشده</td></tr>');
            return;
        }
        shareHistory.forEach(h => {
            const actionNote = h.action !== 'unchanged' ? ` <span class="badge bg-info">${h.action}</span>` : '';
            tbody.append(`<tr>
                <td>${UI.escapeHtml(h.partner_name)}${actionNote}</td>
                <td>${(parseFloat(h.share) * 100).toFixed(1)}%</td>
                <td class="small">${formatDateDisplay(h.effective_from)}</td>
                <td class="small">${h.effective_to ? formatDateDisplay(h.effective_to) : 'جاری'}</td>
            </tr>`);
        });
    }

    function resetWizard() {
        wizardStep = 1;
        currentPreview = null;
        nextPartnersRows = [];
        $('#close-wizard-steps .nav-link').removeClass('active').prop('disabled', true);
        $('#close-wizard-steps .nav-link').first().addClass('active').prop('disabled', false);
        $('#close-step-1').addClass('show active');
        $('#close-step-2, #close-step-3, #close-step-4').removeClass('show active');
        $('#close-wizard-prev-btn').hide();
        $('#close-wizard-next-btn').show().text('پیش‌نمایش و ادامه');
        $('#close-execute-btn').hide();
        $('#close-type').val('annual');
        $('#close-calc-method').val('standard');
        $('#close-title').val('');
        $('#close-notes').val('');
        $('#close-post-profit').prop('checked', false);
        closeBackupFilename = null;
        $('#close-backup-status').html('هنوز پشتیبان برای این بستن ایجاد نشده است.').removeClass('text-success text-danger').addClass('text-muted');
        $('#close-execute-btn').prop('disabled', true);
        if (status.suggestedStartDate) {
            $('#close-period-start').val(formatDateDisplay(status.suggestedStartDate));
            if (status.lastClosedDate) {
                $('#close-period-start').prop('readonly', true);
            } else {
                $('#close-period-start').prop('readonly', false);
            }
        } else {
            $('#close-period-start').prop('readonly', false);
        }
        if (typeof UI !== 'undefined' && UI.today) {
            $('#close-period-end').val(UI.today());
        }
    }

    function getWizardPayload() {
        return {
            closeType: $('#close-type').val(),
            calculationMethod: $('#close-calc-method').val(),
            periodStartDate: UI.toGregorian('#close-period-start') || $('#close-period-start').val(),
            periodEndDate: UI.toGregorian('#close-period-end') || $('#close-period-end').val(),
            title: $('#close-title').val(),
            notes: $('#close-notes').val(),
            newPartnersConfig: collectNextPartnersConfig(),
        };
    }

    function collectNextPartnersConfig() {
        return nextPartnersRows.map(row => ({
            id: row.id || null,
            name: row.name,
            // UI stores percent 0–100; API expects fraction 0–1.
            share: (parseFloat(row.share) || 0) / 100,
            action: row.action || (row.id ? 'modified' : 'added'),
        }));
    }

    function renderMiniTable(headers, rows, emptyMsg) {
        if (!rows || !rows.length) {
            return `<p class="text-muted small mb-0">${emptyMsg}</p>`;
        }
        let h = '<thead class="table-light"><tr>' + headers.map(h => `<th>${h}</th>`).join('') + '</tr></thead>';
        let b = '<tbody>' + rows.join('') + '</tbody>';
        return `<div class="table-responsive" style="max-height:220px;"><table class="table table-sm mb-0">${h}${b}</table></div>`;
    }

    function renderClosingSnapshotHtml(snap, warnings) {
        if (!snap) return '<p class="text-muted">داده‌ای موجود نیست.</p>';
        const pl = snap.profitLoss || {};
        const bs = snap.balanceSheet || {};
        const assets = bs.assets || {};
        const liabilities = bs.liabilities || {};
        const equity = bs.equity || {};
        const cap = snap.capital || {};
        const liq = snap.liquidity || {};
        const cust = snap.customers || {};
        const sup = snap.suppliers || {};
        const chk = snap.checks || {};
        const inv = snap.inventory || {};
        const prt = snap.partners || {};
        const mov = snap.periodCashMovements || {};
        const opening = snap.openingEntry || {};

        let warningsHtml = '';
        (warnings || []).forEach(w => {
            const cls = w.level === 'error' ? 'danger' : 'warning';
            warningsHtml += `<div class="alert alert-${cls} py-2 small mb-2">${UI.escapeHtml(w.message)}</div>`;
        });

        const balanceOk = bs.isBalanced ? 'success' : 'warning';
        const customerRows = (cust.customers || []).slice(0, 30).map(c =>
            `<tr><td>${UI.escapeHtml(c.name)}</td><td class="text-end">${UI.formatCurrency(c.receivable)}</td><td class="text-end text-muted small">${UI.formatCurrency(c.pendingChecks || 0)}</td></tr>`
        );
        const supplierRows = (sup.suppliers || []).slice(0, 30).map(s =>
            `<tr><td>${UI.escapeHtml(s.name)}</td><td class="text-end">${UI.formatCurrency(s.debt)}</td><td class="text-end text-muted small">${UI.formatCurrency(s.pendingChecks || 0)}</td></tr>`
        );
        const accountRows = (liq.allAccounts || []).map(a => {
            const asOf = Number(a.balance) || 0;
            const stored = a.storedBalance != null ? Number(a.storedBalance) : null;
            const showCurrent = stored != null && Math.abs(asOf - stored) > 0.5;
            return `<tr>
                <td>${UI.escapeHtml(a.name)}</td>
                <td class="text-end">${UI.formatCurrency(asOf)}${showCurrent ? `<div class="small text-muted">فعلی: ${UI.formatCurrency(stored)}</div>` : ''}</td>
                <td class="text-end ${Math.abs(a.difference) > 0.01 ? 'text-danger' : 'text-muted'}">${UI.formatCurrency(a.difference)}</td>
            </tr>`;
        });
        const checkRows = [...(chk.received || []), ...(chk.payable || [])].slice(0, 25).map(c =>
            `<tr><td>${UI.escapeHtml(c.checkNumber)}</td><td>${UI.escapeHtml(c.personName || '—')}</td><td>${formatDateDisplay(c.dueDate)}</td><td class="text-end">${UI.formatCurrency(c.amount)}</td><td><span class="badge bg-secondary">${UI.escapeHtml(checkStatusLabel(c.status))}</span></td></tr>`
        );
        const partnerRows = (prt.list || []).map(p =>
            `<tr><td>${UI.escapeHtml(p.name)}</td><td>${(p.share * 100).toFixed(1)}%</td><td class="text-end">${UI.formatCurrency(p.accountBalance)}</td><td class="text-end">${UI.formatCurrency(p.periodDeposits)}</td><td class="text-end">${UI.formatCurrency(p.periodWithdrawals)}</td></tr>`
        );
        const openingRows = (opening.lines || []).slice(0, 40).map(ln =>
            `<tr><td><span class="badge ${ln.side === 'asset' ? 'bg-success' : ln.side === 'liability' ? 'bg-danger' : 'bg-primary'}">${ln.side === 'asset' ? 'دارایی' : ln.side === 'liability' ? 'بدهی' : 'سرمایه'}</span></td><td>${UI.escapeHtml(ln.name)}</td><td class="text-end">${UI.formatCurrency(ln.amount)}</td><td class="small text-muted">${UI.escapeHtml(ln.note || '')}</td></tr>`
        );

        return `
            ${warningsHtml}
            <div class="alert alert-${balanceOk} py-2 small">
                <strong>وضعیت تراز در تاریخ ${formatDateDisplay(snap.asOfDate)}:</strong>
                دارایی ${UI.formatCurrency(assets.total)} − بدهی ${UI.formatCurrency(liabilities.total)} − سرمایه ${UI.formatCurrency(equity.total)}
                = اختلاف ${UI.formatCurrency(bs.difference || 0)}
                ${bs.isBalanced ? ' (قابل قبول)' : ''}
                ${!bs.isBalanced ? '<div class="mt-1 text-muted">اختلاف یعنی دارایی‌ها با جمع بدهی و سرمایه برابر نیست.</div>' : '<div class="mt-1 text-muted">مانده حساب‌ها به‌تاریخ بستن است؛ اگر با موجودی فعلی فرق دارد، تراکنش‌های بعد از تاریخ بستن را ببینید.</div>'}
            </div>
            <div class="row g-2 mb-3">
                <div class="col-6 col-md-3"><div class="border rounded p-2 text-center"><div class="small text-muted">سود خالص دوره</div><strong class="text-success">${UI.formatCurrency(pl.netOperatingProfit)}</strong></div></div>
                <div class="col-6 col-md-3"><div class="border rounded p-2 text-center"><div class="small text-muted">نقدینگی</div><strong>${UI.formatCurrency(liq.total)}</strong></div></div>
                <div class="col-6 col-md-3"><div class="border rounded p-2 text-center"><div class="small text-muted">مطالبات مشتریان</div><strong>${UI.formatCurrency(cust.totalReceivable)}</strong></div></div>
                <div class="col-6 col-md-3"><div class="border rounded p-2 text-center"><div class="small text-muted">بدهی تأمین‌کنندگان</div><strong>${UI.formatCurrency(sup.totalDebt)}</strong></div></div>
                <div class="col-6 col-md-3"><div class="border rounded p-2 text-center"><div class="small text-muted">ارزش انبار (ملکی)</div><strong>${UI.formatCurrency(inv.ownValue)}</strong></div></div>
                <div class="col-6 col-md-3"><div class="border rounded p-2 text-center"><div class="small text-muted">چک دریافتی</div><strong>${UI.formatCurrency(chk.totals?.receivedInHand)}</strong></div></div>
                <div class="col-6 col-md-3"><div class="border rounded p-2 text-center"><div class="small text-muted">چک پرداختنی</div><strong>${UI.formatCurrency(chk.totals?.payableOutstanding)}</strong></div></div>
                <div class="col-6 col-md-3"><div class="border rounded p-2 text-center"><div class="small text-muted">شروع دوره بعد</div><strong>${formatDateDisplay(snap.nextPeriodStart)}</strong></div></div>
            </div>

            <ul class="nav nav-tabs mb-2" role="tablist">
                <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#close-tab-pl" type="button">سود و زیان</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#close-tab-bs" type="button">ترازنامه</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#close-tab-ar" type="button">مشتریان</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#close-tab-ap" type="button">تأمین‌کنندگان</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#close-tab-cash" type="button">نقدینگی</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#close-tab-chk" type="button">چک‌ها</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#close-tab-inv" type="button">انبار</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#close-tab-prt" type="button">شرکا</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#close-tab-open" type="button">افتتاحیه</button></li>
            </ul>
            <div class="tab-content border rounded p-3">
                <div class="tab-pane fade show active" id="close-tab-pl">
                    <div class="row g-2 small">
                        <div class="col-md-4">فروش ناخالص: <strong>${UI.formatCurrency(pl.grossSales)}</strong></div>
                        <div class="col-md-4">برگشت فروش: <strong>${UI.formatCurrency(pl.salesReturns)}</strong></div>
                        <div class="col-md-4">تخفیفات: <strong>${UI.formatCurrency(pl.salesDiscounts)}</strong></div>
                        <div class="col-md-4">سود ناخالص: <strong>${UI.formatCurrency(pl.grossProfit)}</strong></div>
                        <div class="col-md-4">هزینه‌ها: <strong>${UI.formatCurrency(pl.totalCompanyExpenses)}</strong></div>
                        <div class="col-md-4">سود خالص: <strong class="text-success">${UI.formatCurrency(pl.netOperatingProfit)}</strong></div>
                        <div class="col-md-4">واریز شرکا (دوره): <strong>${UI.formatCurrency(cap.periodDeposits)}</strong></div>
                        <div class="col-md-4">برداشت شرکا (دوره): <strong>${UI.formatCurrency(cap.periodWithdrawals)}</strong></div>
                        <div class="col-md-4">دریافت نقدی (دوره): <strong>${UI.formatCurrency(mov.cashReceipts)}</strong></div>
                        <div class="col-md-4">پرداخت نقدی (دوره): <strong>${UI.formatCurrency(mov.cashPayments)}</strong></div>
                    </div>
                </div>
                <div class="tab-pane fade" id="close-tab-bs">
                    <div class="row">
                        <div class="col-md-4"><h6 class="text-success">دارایی‌ها</h6><ul class="list-unstyled small">
                            <li>نقد و بانک: ${UI.formatCurrency(assets.liquidity)}</li>
                            <li>چک دریافتی: ${UI.formatCurrency(assets.checksReceived)}</li>
                            <li>مشتریان: ${UI.formatCurrency(assets.customerReceivables)}</li>
                            <li>انبار: ${UI.formatCurrency(assets.inventory)}</li>
                            ${(assets.vatReceivable > 0.01) ? `<li>طلب مالیات بر ارزش افزوده: ${UI.formatCurrency(assets.vatReceivable)}</li>` : ''}
                            <li class="fw-bold border-top pt-1">جمع: ${UI.formatCurrency(assets.total)}</li>
                        </ul></div>
                        <div class="col-md-4"><h6 class="text-danger">بدهی‌ها</h6><ul class="list-unstyled small">
                            <li>تأمین‌کنندگان: ${UI.formatCurrency(liabilities.supplierDebts)}</li>
                            <li>چک پرداختنی: ${UI.formatCurrency(liabilities.checksPayable)}</li>
                            ${(liabilities.vatPayable > 0.01) ? `<li>بدهی مالیات بر ارزش افزوده: ${UI.formatCurrency(liabilities.vatPayable)}</li>` : ''}
                            <li class="fw-bold border-top pt-1">جمع: ${UI.formatCurrency(liabilities.total)}</li>
                        </ul></div>
                        <div class="col-md-4"><h6 class="text-primary">سرمایه</h6><ul class="list-unstyled small">
                            <li>سرمایه شرکا (واریز−برداشت): ${UI.formatCurrency(cap.partnerCapital != null ? cap.partnerCapital : cap.closingCapital)}</li>
                            <li>سود/زیان این دوره (تحلیلی): ${UI.formatCurrency(cap.netProfit)}</li>
                            <li>اندوخته/تعدیل (باقیمانده تراز): ${UI.formatCurrency(equity.unallocatedEarnings != null ? equity.unallocatedEarnings : (cap.unallocatedEarnings || 0))}</li>
                            <li class="fw-bold border-top pt-1">جمع سرمایه (خالص دارایی): ${UI.formatCurrency(equity.total)}</li>
                        </ul>
                        ${(bs.partnersMemo && (bs.partnersMemo.receivables || bs.partnersMemo.payables)) ? `<p class="small text-muted mt-2 mb-0">یادداشت شرکا (خارج از معادله): بدهکار ${UI.formatCurrency(bs.partnersMemo.receivables)} | بستانکار ${UI.formatCurrency(bs.partnersMemo.payables)}</p>` : ''}
                        </div>
                    </div>
                </div>
                <div class="tab-pane fade" id="close-tab-ar">
                    <p class="small">جمع مطالبات: <strong>${UI.formatCurrency(cust.totalReceivable)}</strong> | چک معوق: ${UI.formatCurrency(cust.totalPendingChecks)}</p>
                    ${renderMiniTable(['مشتری', 'مانده', 'چک معوق'], customerRows, 'مشتری بدهکار ندارد.')}
                </div>
                <div class="tab-pane fade" id="close-tab-ap">
                    <p class="small">جمع بدهی: <strong>${UI.formatCurrency(sup.totalDebt)}</strong> | چک پرداختنی: ${UI.formatCurrency(sup.totalPendingChecks)}</p>
                    ${renderMiniTable(['تأمین‌کننده', 'بدهی', 'چک'], supplierRows, 'بدهی به تأمین‌کننده ثبت نشده.')}
                </div>
                <div class="tab-pane fade" id="close-tab-cash">
                    <p class="small">جمع نقدینگی: <strong>${UI.formatCurrency(liq.total)}</strong> | در موقعیت نقدی: ${UI.formatCurrency(liq.totalInCashPosition)}</p>
                    ${renderMiniTable(['حساب', 'مانده', 'مغایرت'], accountRows, 'حسابی یافت نشد.')}
                </div>
                <div class="tab-pane fade" id="close-tab-chk">
                    <p class="small">دریافتی: ${UI.formatCurrency(chk.totals?.receivedInHand)} | پرداختنی: ${UI.formatCurrency(chk.totals?.payableOutstanding)} | خرج‌شده: ${UI.formatCurrency(chk.totals?.endorsed)}</p>
                    ${renderMiniTable(['شماره', 'شخص', 'سررسید', 'مبلغ', 'وضعیت'], checkRows, 'چک باز ثبت نشده.')}
                </div>
                <div class="tab-pane fade" id="close-tab-inv">
                    <ul class="list-unstyled small">
                        <li>ارزش FIFO در تاریخ بستن: <strong>${UI.formatCurrency(inv.fifoValueAtClose)}</strong></li>
                        <li>ارزش گزارش انبار (ملکی): <strong>${UI.formatCurrency(inv.ownValue)}</strong></li>
                        <li>امانی نزد دیگران: ${UI.formatCurrency(inv.consignmentOutValue)}</li>
                        <li>امانی نزد ما: ${UI.formatCurrency(inv.consignmentInValue)}</li>
                        <li>تعداد موجودی: ${inv.inStockCount || inv.ownQuantity} قلم</li>
                    </ul>
                </div>
                <div class="tab-pane fade" id="close-tab-prt">
                    ${renderMiniTable(['شریک', 'سهم', 'مانده حساب', 'واریز دوره', 'برداشت دوره'], partnerRows, 'شریکی تعریف نشده.')}
                </div>
                <div class="tab-pane fade" id="close-tab-open">
                    <p class="small text-primary"><strong>${UI.escapeHtml(opening.title || '')}</strong> — این پیش‌نویس مانده‌ها در اسنپ‌شات بستن ذخیره می‌شود و مبنای تحلیلی دوره از ${formatDateDisplay(opening.effectiveDate)} است (ثبت خودکار در دفتر نیست).</p>
                    ${renderMiniTable(['نوع', 'شرح', 'مبلغ', 'توضیح'], openingRows, '')}
                    <p class="small mb-0 mt-2">جمع دارایی افتتاحیه: ${UI.formatCurrency(opening.totals?.assets)} | بدهی: ${UI.formatCurrency(opening.totals?.liabilities)} | سرمایه: ${UI.formatCurrency(opening.totals?.equity)}</p>
                </div>
            </div>
        `;
    }

    function renderPreviewFinancial(preview) {
        const snap = preview.closingSnapshot;
        $('#close-preview-financial').html(renderClosingSnapshotHtml(snap, preview.warnings));
    }

    function initNextPartnersFromPreview(preview) {
        nextPartnersRows = (preview.nextPartnersConfig || []).map(p => ({
            id: p.id,
            name: p.name,
            // Editor works in percent; convert from API fraction.
            share: Math.round((parseFloat(p.share) || 0) * 10000) / 100,
            action: p.action || 'unchanged',
            currentShare: (preview.currentPartners || []).find(cp => cp.id == p.id)?.share ?? p.share,
        }));
        renderPartnersEditor(preview);
    }

    function renderPartnersEditor(preview) {
        const tbody = $('#close-partners-tbody').empty();
        const isRestructure = $('#close-type').val() === 'partner_restructure';

        $('#close-add-partner-row-btn').toggle(isRestructure);

        nextPartnersRows.forEach((row, idx) => {
            if (row.action === 'removed') return;
            const currentPct = row.currentShare != null ? (parseFloat(row.currentShare) * 100).toFixed(1) + '%' : '—';
            const removeBtn = row.id
                ? `<button type="button" class="btn btn-sm btn-outline-danger remove-partner-row-btn" data-idx="${idx}"><i class="bi bi-person-x"></i></button>`
                : `<button type="button" class="btn btn-sm btn-outline-danger remove-partner-row-btn" data-idx="${idx}"><i class="bi bi-trash"></i></button>`;
            const tr = $(`<tr data-idx="${idx}">
                <td><input type="text" class="form-control form-control-sm partner-name-input" value="${UI.escapeHtml(row.name || '')}" ${row.id && !isRestructure ? 'readonly' : ''}></td>
                <td class="text-muted">${currentPct}</td>
                <td><input type="number" class="form-control form-control-sm partner-share-input" min="0" max="100" step="0.01" value="${row.share}" title="درصد سهم (مثلاً ۶۰)" ${!isRestructure ? 'readonly' : ''}></td>
                <td>${isRestructure ? removeBtn : ''}</td>
            </tr>`);
            tbody.append(tr);
        });

        let allocHtml = '<table class="table table-sm"><thead><tr><th>شریک</th><th>سهم</th><th class="text-end">سود خالص</th></tr></thead><tbody>';
        (preview.periodPartnerAllocation || []).forEach(p => {
            allocHtml += `<tr><td>${UI.escapeHtml(p.name)}</td><td>${p.sharePercent}%</td><td class="text-end">${UI.formatCurrency(p.netProfitShare)}</td></tr>`;
        });
        allocHtml += '</tbody></table>';
        $('#close-period-allocation').html(allocHtml);
    }

    function renderFinalSummary() {
        if (!currentPreview) return;
        const pl = currentPreview.profitLoss || {};
        const totalNextShare = collectNextPartnersConfig()
            .filter(p => p.action !== 'removed' && p.share > 0)
            .reduce((s, p) => s + p.share, 0);

        const snap = currentPreview.closingSnapshot || {};
        const bs = snap.balanceSheet || {};
        $('#close-final-summary').html(`
            <h6>تأیید بستن کامل دوره و شروع فصل جدید</h6>
            <ul class="mb-2">
                <li>بازه: <strong>${formatDateDisplay(currentPreview.periodStart)}</strong> تا <strong>${formatDateDisplay(currentPreview.periodEnd)}</strong></li>
                <li>شروع دوره بعد: <strong>${formatDateDisplay(snap.nextPeriodStart)}</strong></li>
                <li>سود خالص: <strong>${UI.formatCurrency(pl.netOperatingProfit)}</strong></li>
                <li>جمع دارایی: <strong>${UI.formatCurrency(bs.assets?.total)}</strong> | بدهی: <strong>${UI.formatCurrency(bs.liabilities?.total)}</strong> | سرمایه: <strong>${UI.formatCurrency(bs.equity?.total)}</strong></li>
                <li>جمع سهم شرکای دوره بعد: <strong>${(totalNextShare * 100).toFixed(1)}%</strong></li>
                <li>ثبت سود در حساب شرکا: <strong>${$('#close-post-profit').is(':checked') ? 'بله' : 'خیر'}</strong></li>
            </ul>
            <p class="small text-muted mb-0">با تأیید، اسنپ‌شات کامل (مشتریان، تأمین‌کنندگان، چک‌ها، نقدینگی، انبار، شرکا) و پیش‌نویس سند افتتاحیه در سوابق ذخیره می‌شود (ثبت خودکار در دفتر روزنامه انجام نمی‌شود). تراکنش‌های این دوره قفل می‌شوند.</p>
        `);
    }

    function goToStep(step) {
        wizardStep = step;
        $(`#close-wizard-steps button[data-bs-target="#close-step-${step}"]`).tab('show');
        $('#close-wizard-prev-btn').toggle(step > 1);
        $('#close-wizard-next-btn').toggle(step < 4);
        const onStep4 = step === 4;
        $('#close-execute-btn').toggle(onStep4).prop('disabled', onStep4 && !closeBackupFilename);
        if (onStep4) renderFinalSummary();
    }

    async function createFullBackupBeforeClose() {
        UI.showLoader();
        const result = await Api.call('create_backup', { type: 'full' });
        UI.hideLoader();
        if (result?.success && result.filename) {
            closeBackupFilename = result.filename;
            $('#close-backup-status')
                .removeClass('text-muted text-danger')
                .addClass('text-success')
                .html(`<i class="bi bi-check-circle-fill me-1"></i>پشتیبان کامل ایجاد شد: <strong>${result.filename}</strong>`);
            $('#close-execute-btn').prop('disabled', false);
            UI.showSuccess('پشتیبان کامل با موفقیت ایجاد شد. اکنون می‌توانید بستن حساب را تأیید کنید.');
        } else {
            $('#close-backup-status')
                .removeClass('text-muted text-success')
                .addClass('text-danger')
                .text(result?.error || 'خطا در ایجاد پشتیبان.');
            closeBackupFilename = null;
            $('#close-execute-btn').prop('disabled', true);
        }
    }

    async function loadPreviewAndAdvance() {
        const payload = getWizardPayload();
        if (!payload.periodEndDate) {
            UI.showError('تاریخ پایان دوره الزامی است.');
            return;
        }
        UI.showLoader();
        const result = await Api.call('preview_account_close', payload);
        UI.hideLoader();
        if (result?.error) {
            UI.showError(result.error);
            return;
        }
        currentPreview = result;
        initNextPartnersFromPreview(result);
        renderPreviewFinancial(result);
        $('#close-wizard-steps .nav-link').prop('disabled', false);
        goToStep(2);
    }

    async function showClosingDetail(id) {
        UI.showLoader();
        const detail = await Api.call('get_account_closing_detail', { id });
        UI.hideLoader();
        if (detail?.error) {
            UI.showError(detail.error);
            return;
        }

        const alloc = detail.partnerAllocation || [];
        let allocRows = alloc.map(p =>
            `<tr><td>${UI.escapeHtml(p.name)}</td><td>${p.sharePercent}%</td><td class="text-end">${UI.formatCurrency(p.netProfitShare)}</td></tr>`
        ).join('');

        const snap = detail.snapshot || {};
        $('#closing-detail-title').text(detail.title);
        $('#closing-detail-body').html(`
            <div class="mb-3 small">
                <span class="me-3"><strong>بازه:</strong> ${formatDateDisplay(detail.period_start_date)} تا ${formatDateDisplay(detail.period_end_date)}</span>
                <span class="me-3"><strong>نوع:</strong> ${closeTypeLabels[detail.close_type] || UI.escapeHtml(detail.close_type)}</span>
                <span class="me-3"><strong>وضعیت:</strong> ${detail.status === 'completed' ? 'بسته' : 'بازگشایی شده'}</span>
                ${detail.notes ? `<span><strong>یادداشت:</strong> ${UI.escapeHtml(detail.notes)}</span>` : ''}
            </div>
            <h6 class="mb-2">تسهیم سود شرکا</h6>
            <table class="table table-sm mb-3"><thead><tr><th>شریک</th><th>سهم</th><th class="text-end">سود</th></tr></thead><tbody>${allocRows || '<tr><td colspan="3" class="text-muted">—</td></tr>'}</tbody></table>
            ${renderClosingSnapshotHtml(snap, [])}
        `);
        $('#closing-detail-panel').show();
    }

    async function executeClose() {
        if (!closeBackupFilename) {
            UI.showError('ابتدا پشتیبان کامل ایجاد کنید.');
            return;
        }
        syncPartnerRowsFromDom();
        const payload = getWizardPayload();
        if (!currentPreview) {
            UI.showError('پیش‌نمایش منقضی شده است. لطفاً دوباره پیش‌نمایش بگیرید.');
            return;
        }
        const previewFp = previewPayloadFingerprint({
            closeType: currentPreview.closeType,
            calculationMethod: currentPreview.calculationMethod,
            periodStartDate: currentPreview.periodStart,
            periodEndDate: currentPreview.periodEnd,
        });
        const currentFp = previewPayloadFingerprint(payload);
        if (previewFp !== currentFp) {
            invalidatePreview('تنظیمات نسبت به پیش‌نمایش تغییر کرده است.');
            return;
        }
        UI.confirmAction('آیا از بستن حساب این دوره مطمئن هستید؟ این عمل قابل بازگشت فقط برای آخرین بستن است.', async (ok) => {
            if (!ok) return;
            payload.postProfitToPartners = $('#close-post-profit').is(':checked');
            payload.backupFilename = closeBackupFilename;
            UI.showLoader();
            const result = await Api.call('execute_account_close', payload);
            UI.hideLoader();
            if (result?.success) {
                UI.showSuccess(result.message || 'حساب با موفقیت بسته شد.');
                $('#accountCloseModal').modal('hide');
                await load();
                await App.updateCache(['partners', 'accounts']);
            } else {
                UI.showError(result?.error || 'خطا در بستن حساب');
            }
        });
    }

    async function reopenClosing(id) {
        UI.confirmAction('بازگشایی آخرین بستن حساب، قفل دوره را برمی‌دارد و ثبت‌های سود (در صورت وجود) را برمی‌گرداند. ادامه می‌دهید؟', async (ok) => {
            if (!ok) return;
            UI.showLoader();
            const result = await Api.call('reopen_account_closing', { id });
            UI.hideLoader();
            if (result?.success) {
                UI.showSuccess(result.message);
                await load();
                await App.updateCache(['partners', 'accounts']);
            } else {
                UI.showError(result?.error || 'خطا در بازگشایی');
            }
        });
    }

    function attachEvents() {
        $('#new-account-close-btn').on('click', () => {
            resetWizard();
            $('#accountCloseModal').modal('show');
        });

        $('#refresh-closings-btn').on('click', () => load());

        $('#close-wizard-next-btn').on('click', async () => {
            if (wizardStep === 1) {
                await loadPreviewAndAdvance();
            } else if (wizardStep === 2) {
                syncPartnerRowsFromDom();
                goToStep(3);
            } else if (wizardStep === 3) {
                syncPartnerRowsFromDom();
                goToStep(4);
            }
        });

        $('#close-wizard-prev-btn').on('click', () => {
            if (wizardStep > 1) goToStep(wizardStep - 1);
        });

        $('#close-execute-btn').on('click', executeClose);
        $('#close-create-backup-btn').on('click', createFullBackupBeforeClose);

        $('#close-add-partner-row-btn').on('click', () => {
            nextPartnersRows.push({ id: null, name: '', share: 0, action: 'added', currentShare: 0 });
            renderPartnersEditor(currentPreview);
        });

        $('body').on('click', '.remove-partner-row-btn', function () {
            const idx = parseInt($(this).data('idx'), 10);
            if (nextPartnersRows[idx]?.id) {
                nextPartnersRows[idx].action = 'removed';
            } else {
                nextPartnersRows.splice(idx, 1);
            }
            renderPartnersEditor(currentPreview);
        });

        $('body').on('click', '.btn-view-closing', function () {
            showClosingDetail($(this).data('id'));
        });

        $('body').on('click', '.btn-reopen-closing', function () {
            reopenClosing($(this).data('id'));
        });

        $('#close-detail-panel-btn').on('click', () => $('#closing-detail-panel').hide());

        $('#close-wizard-steps .nav-link').on('shown.bs.tab', function () {
            const target = $(this).data('bs-target') || '';
            const match = String(target).match(/close-step-(\d)/);
            if (match) {
                syncPartnerRowsFromDom();
                wizardStep = parseInt(match[1], 10);
                $('#close-wizard-prev-btn').toggle(wizardStep > 1);
                $('#close-wizard-next-btn').toggle(wizardStep < 4);
                const onStep4 = wizardStep === 4;
                $('#close-execute-btn').toggle(onStep4).prop('disabled', onStep4 && !closeBackupFilename);
                if (onStep4) renderFinalSummary();
            }
        });

        $('#close-type').on('change', function () {
            if (currentPreview) {
                invalidatePreview('نوع بستن تغییر کرد؛ پیش‌نمایش را دوباره بگیرید.');
                return;
            }
        });

        $('#close-calc-method, #close-period-start, #close-period-end').on('change', function () {
            if (currentPreview) {
                invalidatePreview('تنظیمات دوره تغییر کرد؛ پیش‌نمایش را دوباره بگیرید.');
            }
        });
    }

    function invalidatePreview(reason) {
        if (!currentPreview && !closeBackupFilename) {
            return;
        }
        currentPreview = null;
        closeBackupFilename = null;
        nextPartnersRows = [];
        $('#close-preview-financial').html(
            `<div class="alert alert-warning py-2 small mb-0">${UI.escapeHtml(reason || 'تنظیمات تغییر کرد؛ پیش‌نمایش را دوباره بگیرید.')}</div>`
        );
        $('#close-backup-status').html('هنوز پشتیبان برای این بستن ایجاد نشده است.')
            .removeClass('text-success text-danger').addClass('text-muted');
        $('#close-execute-btn').prop('disabled', true);
        $('#close-wizard-steps .nav-link').not(':first').prop('disabled', true);
        goToStep(1);
        if (reason) {
            UI.showError(reason);
        }
    }

    function previewPayloadFingerprint(payload) {
        return JSON.stringify({
            closeType: payload.closeType,
            calculationMethod: payload.calculationMethod,
            periodStartDate: payload.periodStartDate,
            periodEndDate: payload.periodEndDate,
        });
    }

    function syncPartnerRowsFromDom() {
        $('#close-partners-tbody tr').each(function () {
            const idx = parseInt($(this).data('idx'), 10);
            if (nextPartnersRows[idx] && nextPartnersRows[idx].action !== 'removed') {
                nextPartnersRows[idx].name = $(this).find('.partner-name-input').val();
                nextPartnersRows[idx].share = parseFloat($(this).find('.partner-share-input').val()) || 0;
                if (nextPartnersRows[idx].id) {
                    const origPct = (parseFloat(nextPartnersRows[idx].currentShare) || 0) * 100;
                    if (Math.abs(origPct - nextPartnersRows[idx].share) > 0.01) {
                        nextPartnersRows[idx].action = 'modified';
                    }
                }
            }
        });
    }

    async function load() {
        UI.showLoader();
        const [statusRes, listRes, histRes] = await Promise.all([
            Api.call('get_account_closing_status'),
            Api.call('get_account_closings'),
            Api.call('get_partner_share_history'),
        ]);
        UI.hideLoader();

        status = statusRes || {};
        closings = listRes?.closings || [];
        shareHistory = histRes?.history || [];
        renderStatus();
        renderClosingsList();
        renderShareHistory();
    }

    function init() {
        attachEvents();
    }

    return { init, load };
})();
