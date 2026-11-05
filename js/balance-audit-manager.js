// /js/balance-audit-manager.js

const BalanceAuditManager = (function () {
    let currentAnalysis = null;
    let transactionsDataTable = null;
    // *** START FIX: Add a variable to store the ID of the account being analyzed ***
    let currentlyAnalyzingAccountId = null;
    // *** END FIX ***

    function renderMismatchedList(accounts, message) {
        const container = $('#balance-audit-main-view').empty();
        let listHtml = `<div class="alert alert-warning">${message}</div>
                        <p><strong>حساب‌های زیر دارای مغایرت هستند.</strong> برای مشاهده جزئیات و اصلاح، روی نام هر حساب کلیک کنید:</p>
                        <div class="list-group">`;
        accounts.forEach(acc => {
            listHtml += `<a href="#" class="list-group-item list-group-item-action analyze-specific-account" data-account-id="${acc.id}">${acc.name}</a>`;
        });
        listHtml += '</div><hr><button class="btn btn-sm btn-secondary" id="show-all-accounts-btn">نمایش همه حساب‌ها برای بررسی دستی</button>';
        container.html(listHtml);
    }

    function renderAccountDropdown() {
        const container = $('#balance-audit-main-view').empty();
        const dropdownHtml = `
            <div class="row align-items-end border rounded p-3">
                <div class="col-md-8">
                    <label for="balance-audit-account-select" class="form-label">۱. یک حساب را برای بررسی انتخاب کنید:</label>
                    <select class="form-select" id="balance-audit-account-select" data-placeholder="انتخاب حساب..."></select>
                </div>
                <div class="col-md-4">
                    <button class="btn btn-primary w-100" id="analyze-account-balance-btn" disabled>
                        <i class="bi bi-clipboard2-data-fill me-2"></i>۲. بررسی و تحلیل حساب
                    </button>
                </div>
            </div>`;
        container.html(dropdownHtml);

        const select = $('#balance-audit-account-select');
        select.empty().append('<option></option>');
        const accounts = App.getCache().accounts || [];
        accounts.forEach(acc => {
            select.append(`<option value="${acc.id}">${acc.name} (مجموعه #${acc.entity_id})</option>`);
        });
        select.select2({
            theme: 'bootstrap-5',
            dir: 'rtl',
            placeholder: "یک حساب برای بررسی انتخاب کنید..."
        });
    }

    function renderResults() {
        if (!currentAnalysis) {
            $('#balance-audit-results-container').hide();
            return;
        }

        const { stored_balance, calculated_balance, difference, transactions, opening_balance, is_partner_account } = currentAnalysis;
        const summaryContainer = $('#balance-summary-container').empty();
        const tableContainer = $('#balance-audit-table-container').empty();

        const isOk = Math.abs(difference) < 0.01;
        const diffClass = isOk ? 'text-success' : 'text-danger';
        const diffIcon = isOk ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill';

        const summaryHtml = `
            <div class="col-md-4">
                <div class="card card-body text-center">
                    <div class="text-muted">موجودی ذخیره شده</div>
                    <div class="fs-4 fw-bold">${UI.formatCurrency(stored_balance)}</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card card-body text-center">
                    <div class="text-muted">موجودی محاسبه شده</div>
                    <div class="fs-4 fw-bold">${UI.formatCurrency(calculated_balance)}</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card card-body text-center ${isOk ? 'bg-success-light' : 'bg-danger-light'}">
                    <div class="text-muted">میزان اختلاف</div>
                    <div class="fs-4 fw-bold ${diffClass}">
                        <i class="bi ${diffIcon} me-2"></i>${UI.formatCurrency(difference)}
                    </div>
                </div>
            </div>
        `;
        summaryContainer.html(summaryHtml);

        if (!isOk) {
            const fixButton = $('<div class="text-center mt-3"><button class="btn btn-warning" id="fix-account-balance-btn"><i class="bi bi-magic me-2"></i>اصلاح خودکار موجودی ذخیره شده</button></div>');
            summaryContainer.append(fixButton);
        }

        if (is_partner_account) {
            const partnerNote = $('<div class="alert alert-info mt-3 small"><strong>توجه:</strong> این یک حساب شخصی شریک است. مانده اولیه و گردش آن بر اساس تمام تراکنش‌های مرتبط با این شریک محاسبه می‌شود.</div>');
            tableContainer.append(partnerNote);
        }

        const table = $('<table id="balance-audit-table" class="table table-sm table-bordered" style="width:100%"><thead class="table-light"><tr><th>تاریخ</th><th>شرح</th><th>بستانکار (ورودی)</th><th>بدهکار (خروجی)</th><th>مانده محاسبه شده</th></tr></thead><tbody></tbody></table>');
        tableContainer.append(table);

        if (transactionsDataTable) {
            transactionsDataTable.destroy();
        }

        let runningBalance = opening_balance;
        const tableData = transactions.map(tx => {
            const amount = parseFloat(tx.amount);
            let credit = 0, debit = 0;

            if (tx.source.endsWith('_out') || tx.source === 'expense') {
                debit = amount;
                runningBalance -= amount;
            } else {
                credit = amount;
                runningBalance += amount;
            }
            return {
                date: UI.gregorianToPersian(tx.date),
                desc: tx.type || tx.desc,
                credit: credit,
                debit: debit,
                balance: runningBalance
            };
        });

        tableData.unshift({
            date: '---',
            desc: '<strong>مانده اولیه محاسبه شده</strong>',
            credit: '',
            debit: '',
            balance: opening_balance
        });

        transactionsDataTable = $('#balance-audit-table').DataTable({
            language: { url: 'assets/js/i18n/fa.json' },
            data: tableData,
            ordering: false,
            paging: false,
            searching: false,
            info: false,
            columns: [
                { data: 'date' },
                { data: 'desc' },
                { data: 'credit', className: 'text-success', render: data => data ? UI.formatCurrency(data) : '-' },
                { data: 'debit', className: 'text-danger', render: data => data ? `(${UI.formatCurrency(data)})` : '-' },
                { data: 'balance', className: 'fw-bold', render: data => UI.formatCurrency(data) }
            ]
        });


        $('#balance-audit-results-container').show();
    }

    async function handleAnalysis(accountId) {
        if (!accountId) {
            UI.showError('شناسه حساب نامعتبر است.');
            return;
        }

        // *** START FIX: Store the ID of the account being analyzed ***
        currentlyAnalyzingAccountId = accountId;
        // *** END FIX ***

        UI.showLoader();
        const result = await Api.call('get_account_balance_analysis', { accountId });
        UI.hideLoader();

        if (result) {
            currentAnalysis = result;
            renderResults();
        }
    }

    async function handleFix() {
        // *** START FIX: Use the reliably stored account ID ***
        if (!currentAnalysis || !currentlyAnalyzingAccountId) {
            UI.showError('هیچ حسابی برای اصلاح انتخاب نشده است.');
            return;
        }

        const accountId = currentlyAnalyzingAccountId;
        // *** END FIX ***

        const accountName = App.getCache().accounts.find(a => a.id == accountId)?.name || `حساب #${accountId}`;
        const message = `آیا مطمئن هستید که می‌خواهید موجودی ذخیره شده حساب «${accountName}» را به مبلغ ${UI.formatCurrency(currentAnalysis.calculated_balance)} اصلاح کنید؟`;

        UI.confirmAction(message, async (confirmed) => {
            if (confirmed) {
                UI.showLoader();
                const result = await Api.call('fix_account_balance', { accountId });
                UI.hideLoader();
                if (result?.success) {
                    UI.showSuccess(result.message);
                    await App.updateCache(['accounts']);
                    await handleAnalysis(accountId); // Re-analyze to show it's fixed
                }
            }
        });
    }

    function init() {
        // Event delegation for dynamically added elements
        $('body').on('click', '#analyze-account-balance-btn', () => handleAnalysis($('#balance-audit-account-select').val()));
        $('body').on('click', '.analyze-specific-account', function (e) {
            e.preventDefault();
            const accountId = $(this).data('account-id');
            $('.analyze-specific-account').removeClass('active');
            $(this).addClass('active');
            handleAnalysis(accountId);
        });
        $('body').on('click', '#show-all-accounts-btn', load);
        $('body').on('change', '#balance-audit-account-select', function () {
            $('#analyze-account-balance-btn').prop('disabled', !$(this).val());
            currentAnalysis = null;
            $('#balance-audit-results-container').hide();
        });
        $('body').on('click', '#fix-account-balance-btn', handleFix);
    }

    function load(filters = null) {
        currentAnalysis = null;
        // *** START FIX: Reset the stored ID when the view is reloaded ***
        currentlyAnalyzingAccountId = null;
        // *** END FIX ***
        $('#balance-audit-results-container').hide();

        if (filters && filters.mismatchedAccounts) {
            renderMismatchedList(filters.mismatchedAccounts, filters.message);
        } else {
            renderAccountDropdown();
        }
    }

    return {
        init,
        load,
    };
})();