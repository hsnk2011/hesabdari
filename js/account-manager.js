// /js/account-manager.js

const AccountManager = (function () {
    let currentAccountId = null;
    let accountsCache = [];
    let transactionsDataTable = null;

    function renderAccountsList(accounts) {
        accountsCache = accounts;
        const container = $('#accounts-list-container').empty();
        if (!accounts || !accounts.length) {
            container.html('<div class="p-3 text-center">حسابی ثبت نشده است.</div>');
            $('#account-ledger-title').text('گردش حساب: --');
            destroyTransactionsTable();
            return;
        }

        accounts.forEach(acc => {
            let icon = acc.partner_id ? '<i class="bi bi-person-badge text-info"></i>' : (acc.is_cash ? '<i class="bi bi-cash-stack text-success"></i>' : '<i class="bi bi-bank2 text-primary"></i>');
            let buttons = acc.partner_id ? '' : `<button class="btn btn-sm btn-outline-warning btn-edit-account py-0 px-1" data-id="${acc.id}" title="ویرایش" data-permission-action="manage_accounts"><i class="bi bi-pencil"></i></button>
                                                 <button class="btn btn-sm btn-outline-danger btn-delete-account py-0 px-1" data-id="${acc.id}" title="حذف" data-permission-action="manage_accounts"><i class="bi bi-trash"></i></button>`;

            const item = $(`<a href="#" class="list-group-item list-group-item-action" data-id="${acc.id}">
                <div class="d-flex w-100 justify-content-between align-items-center">
                    <h6 class="mb-1">${icon} ${UI.escapeHtml(acc.name)}</h6>
                    <div>${buttons}</div>
                </div>
                <p class="mb-1 fw-bold ${acc.current_balance >= 0 ? '' : 'text-danger'}">${UI.formatCurrency(acc.current_balance)}</p>
                <small>${acc.is_cash ? 'صندوق' : acc.bank_name || ''}</small>
            </a>`);
            container.append(item);
        });

        // FIX: Call the function directly on the App object
        App.applyPermissions();

        if (accounts.length > 0 && !accounts.some(a => a.id == currentAccountId)) {
            loadAccountTransactions(accounts[0].id);
        } else if (currentAccountId) {
            $(`#accounts-list-container .list-group-item[data-id="${currentAccountId}"]`).addClass('active');
        }
    }

    function destroyTransactionsTable() {
        if ($.fn.DataTable.isDataTable('#account-transactions-table')) {
            try {
                $('#account-transactions-table').DataTable().clear().destroy();
            } catch (e) { /* ignore */ }
        }
        transactionsDataTable = null;

        // DataTables گاهی بعد از destroy، wrapper خالی/تودرتو می‌گذارد و عرض ستون‌ها خراب می‌شود
        const $table = $('#account-transactions-table');
        const $responsive = $table.closest('.card-body').find('.table-responsive').first();
        if ($responsive.length) {
            $responsive.find('.dataTables_wrapper').each(function () {
                const $w = $(this);
                const $innerTable = $w.find('table#account-transactions-table');
                if ($innerTable.length) {
                    $responsive.append($innerTable);
                }
                $w.remove();
            });
            if (!$responsive.find('#account-transactions-table').length && $table.length) {
                $responsive.append($table);
            }
        }
        $('#account-transactions-table tbody').empty();
    }

    function renderTransactionsTable(account, transactions) {
        if (!account || !transactions) return;

        destroyTransactionsTable();
        const $table = $('#account-transactions-table');

        // Use the initial balance provided by the ledger function
        const openingBalance = parseFloat(account.initial_balance);
        let runningBalance = openingBalance;

        // Sort transactions by date server-side is more reliable, but client-side sort as fallback
        transactions.sort((a, b) => new Date(a.date).getTime() - new Date(b.date).getTime());

        const moneyCell = (data, wrapParens = false) => {
            if (!data) return '-';
            const text = UI.formatCurrency(data).replace(/\s+/g, '\u00A0');
            return wrapParens ? `(${text})` : text;
        };

        const tableData = transactions.map(tx => {
            const amount = parseFloat(tx.amount);
            let bostankar = 0, bedehkar = 0;

            // Determine debit/credit based on source type (consistent with getLedger logic)
            if (tx.source.endsWith('_out') || tx.source === 'expense') {
                bedehkar = amount;
                runningBalance -= amount;
            } else { // _in or check_in
                bostankar = amount;
                runningBalance += amount;
            }

            return {
                date: UI.gregorianToPersian(tx.date),
                description: tx.type, // Use 'type' which holds the generated description
                bostankar: bostankar,
                bedehkar: bedehkar,
                balance: runningBalance
            };
        });

        transactionsDataTable = $table.DataTable({
            language: { url: 'assets/js/i18n/fa.json' },
            data: tableData,
            order: [[0, 'desc']], // Default sort by date descending (newest first)
            searching: false,
            lengthChange: false,
            paging: true,
            info: true,
            autoWidth: false,
            deferRender: true,
            columns: [
                { data: 'date', className: 'text-nowrap', width: '7rem' },
                { data: 'description', className: 'account-tx-desc', render: $.fn.dataTable.render.text() },
                {
                    data: 'bostankar',
                    className: 'text-success text-nowrap',
                    width: '9rem',
                    render: (data) => moneyCell(data)
                },
                {
                    data: 'bedehkar',
                    className: 'text-danger text-nowrap',
                    width: '9rem',
                    render: (data) => moneyCell(data, true)
                },
                {
                    data: 'balance',
                    className: 'fw-bold text-nowrap',
                    width: '9rem',
                    render: (data) => moneyCell(data)
                }
            ]
        });

        requestAnimationFrame(() => {
            if (transactionsDataTable) {
                transactionsDataTable.columns.adjust();
            }
        });
    }

    async function loadAccountTransactions(accountId) {
        currentAccountId = accountId;

        $('#accounts-list-container .list-group-item').removeClass('active');
        $(`#accounts-list-container .list-group-item[data-id="${accountId}"]`).addClass('active');

        const selectedAccount = accountsCache.find(acc => acc.id == accountId);
        $('#account-ledger-title').text(`گردش حساب: ${selectedAccount ? selectedAccount.name : '--'}`);
        UI.showLoader();
        const response = await Api.call('get_account_transactions', { accountId });
        UI.hideLoader();

        if (response && response.account && response.transactions) {
            // Pass account data which now reliably includes initial_balance
            renderTransactionsTable(response.account, response.transactions);
        } else {
            destroyTransactionsTable();
        }
    }

    async function load() {
        const accounts = await Api.call('get_full_accounts_list');
        renderAccountsList(accounts);
    }

    function prepareModal(account = null) {
        UI.hideModalError('#accountModal');
        const form = $('#account-form')[0];
        form.reset();
        $('#account-modal-title').text(account ? 'ویرایش حساب' : 'افزودن حساب جدید');
        $('#account-id').val(account ? account.id : '');
        $('#account-name').val(account ? account.name : '');
        $('#account-bank-name').val(account ? account.bank_name : '');
        $('#account-number').val(account ? account.account_number : '');
        $('#account-card-number').val(account ? account.card_number : '');
        $('#account-is-cash').prop('checked', account ? account.is_cash : false);
        $('#account-include-cash-position').prop('checked', account ? (account.include_in_cash_position != 0) : true);

        const initialBalanceWrapper = $('#initial-balance-wrapper');
        if (account) {
            initialBalanceWrapper.hide(); // Hide initial balance when editing
            // If you need to display it, add a read-only field
        } else {
            initialBalanceWrapper.show();
            $('#account-initial-balance').val('0');
        }

        $('#accountModal').modal('show');
    }

    async function handleFormSubmit(e) {
        e.preventDefault();
        UI.hideModalError('#accountModal');
        
        // Client-side validation
        const validation = UI.validateForm('#account-form');
        if (!validation.valid) {
            UI.showModalError('#accountModal', validation.errors.join('<br>'));
            return;
        }
        
        const data = {
            id: $('#account-id').val(),
            name: $('#account-name').val(),
            bank_name: $('#account-bank-name').val(),
            account_number: $('#account-number').val(),
            card_number: $('#account-card-number').val(),
            is_cash: $('#account-is-cash').is(':checked') ? 1 : 0,
            include_in_cash_position: $('#account-include-cash-position').is(':checked') ? 1 : 0,
            // Only send initial_balance if it's a new account
            initial_balance: !$('#account-id').val() ? ($('#account-initial-balance').val().replace(/,/g, '') || 0) : undefined
        };

        UI.showLoader();
        const result = await Api.call('save_account', data);
        UI.hideLoader();
        
        if (result?.success) {
            UI.showSuccess('حساب با موفقیت ذخیره شد.');
            $('#accountModal').modal('hide');
            load();
            App.fetchInitialCache(); // Ensure cache is updated elsewhere too
        } else if (result?.error) {
            UI.showModalError('#accountModal', result.error);
        }
    }

    async function handleDelete(accountId) {
        UI.confirmAction('آیا از حذف این حساب مطمئن هستید؟ این عملیات قابل بازگشت نیست.', async (confirmed) => {
            if (confirmed) {
                const result = await Api.call('delete_account', { id: accountId });
                if (result?.success) {
                    UI.showSuccess('حساب با موفقیت حذف شد.');
                    currentAccountId = null;
                    load();
                    App.fetchInitialCache();
                }
            }
        });
    }

    function prepareTransferModal() {
        UI.hideModalError('#transferMoneyModal');
        const form = $('#transfer-money-form')[0];
        form.reset();
        
        // Populate account dropdowns
        const fromSelect = $('#transfer-from-account').empty();
        const toSelect = $('#transfer-to-account').empty();
        
        fromSelect.append('<option value="">انتخاب کنید...</option>');
        toSelect.append('<option value="">انتخاب کنید...</option>');
        
        accountsCache.forEach(acc => {
            // *** FIX START: Removed the !acc.partner_id condition ***
            const option = `<option value="${acc.id}">${UI.escapeHtml(acc.name)} (${UI.formatCurrency(acc.current_balance)})</option>`;
            fromSelect.append(option);
            toSelect.append(option);
            // *** FIX END ***
        });
        
        // Set today's date
        $('#transfer-date').val(UI.today());
        $('#transfer-amount').val('0');
        
        $('#transferMoneyModal').modal('show');
        
        // Initialize datepicker after modal is shown
        setTimeout(() => {
            UI.initializeDatepickers();
        }, 100);
    }

    async function handleTransferSubmit(e) {
        e.preventDefault();
        UI.hideModalError('#transferMoneyModal');
        
        const data = {
            fromAccountId: $('#transfer-from-account').val(),
            toAccountId: $('#transfer-to-account').val(),
            amount: $('#transfer-amount').val().replace(/,/g, ''),
            date: UI.toGregorian('#transfer-date'),
            description: $('#transfer-description').val()
        };
        
        if (!data.fromAccountId || !data.toAccountId) {
            UI.showModalError('#transferMoneyModal', 'لطفاً هر دو حساب را انتخاب کنید.');
            return;
        }
        
        if (data.fromAccountId === data.toAccountId) {
            UI.showModalError('#transferMoneyModal', 'حساب مبدا و مقصد نمی‌توانند یکسان باشند.');
            return;
        }
        
        const result = await Api.call('transfer_money', data);
        if (result?.success) {
            UI.showSuccess('انتقال وجه با موفقیت انجام شد.');
            $('#transferMoneyModal').modal('hide');
            load();
            App.fetchInitialCache();
        } else if (result?.error) {
            UI.showModalError('#transferMoneyModal', result.error);
        }
    }

    function init() {
        $('#add-account-btn').on('click', () => prepareModal(null));
        $('#account-form').on('submit', handleFormSubmit);
        $('#transfer-money-btn').on('click', prepareTransferModal);
        $('#transfer-money-form').on('submit', handleTransferSubmit);

        $('#accounts-list-container').on('click', '.list-group-item', function (e) {
            e.preventDefault();
            const target = $(e.target);
            // Prevent triggering if clicking on buttons within the list item
            if (target.closest('.btn').length) {
                return;
            }
            const accountId = $(this).data('id');
            if (accountId !== currentAccountId) {
                loadAccountTransactions(accountId);
            }
        });

        $('#accounts-list-container').on('click', '.btn-edit-account', function (e) {
            e.stopPropagation(); // Prevent list item click
            const accountId = $(this).data('id');
            const account = accountsCache.find(acc => acc.id == accountId);
            prepareModal(account);
        });

        $('#accounts-list-container').on('click', '.btn-delete-account', function (e) {
            e.stopPropagation(); // Prevent list item click
            const accountId = $(this).data('id');
            handleDelete(accountId);
        });

        // وقتی تب بانک‌ها نمایش داده می‌شود، عرض ستون‌های DataTables را دوباره محاسبه کن
        $('#accounts-tab').on('shown.bs.tab', function () {
            if (transactionsDataTable) {
                setTimeout(() => {
                    try {
                        transactionsDataTable.columns.adjust().draw(false);
                    } catch (err) { /* ignore */ }
                }, 30);
            }
        });
    }

    return {
        init,
        load,
        loadAccountTransactions,
        prepareModal,
        config: {
            tableName: 'accounts',
            apiName: 'account',
            nameFa: 'حساب',
        }
    };
})();
