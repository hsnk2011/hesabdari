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
            if (transactionsDataTable) {
                transactionsDataTable.clear().draw();
            }
            return;
        }

        accounts.forEach(acc => {
            let icon = acc.partner_id ? '<i class="bi bi-person-badge text-info"></i>' : (acc.is_cash ? '<i class="bi bi-cash-stack text-success"></i>' : '<i class="bi bi-bank2 text-primary"></i>');
            let buttons = acc.partner_id ? '' : `<button class="btn btn-sm btn-outline-warning btn-edit-account py-0 px-1" data-id="${acc.id}" title="ویرایش"><i class="bi bi-pencil"></i></button>
                                                <button class="btn btn-sm btn-outline-danger btn-delete-account py-0 px-1" data-id="${acc.id}" title="حذف"><i class="bi bi-trash"></i></button>`;

            const item = $(`<a href="#" class="list-group-item list-group-item-action" data-id="${acc.id}">
                <div class="d-flex w-100 justify-content-between align-items-center">
                    <h6 class="mb-1">${icon} ${acc.name}</h6>
                    <div>${buttons}</div>
                </div>
                <p class="mb-1 fw-bold ${acc.current_balance >= 0 ? '' : 'text-danger'}">${UI.formatCurrency(acc.current_balance)}</p>
                <small>${acc.is_cash ? 'صندوق' : acc.bank_name || ''}</small>
            </a>`);
            container.append(item);
        });

        if (accounts.length > 0 && !accounts.some(a => a.id == currentAccountId)) {
            loadAccountTransactions(accounts[0].id);
        } else if (currentAccountId) {
            $(`#accounts-list-container .list-group-item[data-id="${currentAccountId}"]`).addClass('active');
        }
    }

    function renderTransactionsTable(account, transactions) {
        if (!account || !transactions) return;

        if (transactionsDataTable) {
            transactionsDataTable.destroy();
        }

        transactions.sort((a, b) => new Date(a.date).getTime() - new Date(b.date).getTime());

        const openingBalance = parseFloat(account.initial_balance_calculated);

        let runningBalance = openingBalance;
        const tableData = transactions.map(tx => {
            const amount = parseFloat(tx.amount);
            let bostankar = 0, bedehkar = 0;

            if (tx.source.endsWith('_out') || tx.source === 'expense') {
                bedehkar = amount;
                runningBalance -= amount;
            } else {
                bostankar = amount;
                runningBalance += amount;
            }

            return {
                date: UI.gregorianToPersian(tx.date),
                description: tx.type,
                bostankar: bostankar,
                bedehkar: bedehkar,
                balance: runningBalance
            };
        });


        transactionsDataTable = $('#account-transactions-table').DataTable({
            language: { url: 'assets/js/i18n/fa.json' },
            data: tableData,
            order: [[0, 'desc']],
            searching: false,
            lengthChange: false,
            paging: true,
            info: true,
            columns: [
                { data: 'date', className: 'text-end' },
                { data: 'description', className: 'text-end' },
                {
                    data: 'bostankar',
                    className: 'text-end text-success',
                    render: (data) => data !== 0 ? UI.formatCurrency(data) : '-'
                },
                {
                    data: 'bedehkar',
                    className: 'text-end text-danger',
                    render: (data) => data !== 0 ? `(${UI.formatCurrency(data)})` : '-'
                },
                {
                    data: 'balance',
                    className: 'text-end fw-bold',
                    render: (data) => UI.formatCurrency(data)
                }
            ]
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
            renderTransactionsTable(response.account, response.transactions);
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

        const initialBalanceWrapper = $('#initial-balance-wrapper');
        if (account) {
            initialBalanceWrapper.hide();
        } else {
            initialBalanceWrapper.show();
            $('#account-initial-balance').val('0');
        }

        $('#accountModal').modal('show');
    }

    async function handleFormSubmit(e) {
        e.preventDefault();
        UI.hideModalError('#accountModal');
        const data = {
            id: $('#account-id').val(),
            name: $('#account-name').val(),
            bank_name: $('#account-bank-name').val(),
            account_number: $('#account-number').val(),
            card_number: $('#account-card-number').val(),
            is_cash: $('#account-is-cash').is(':checked') ? 1 : 0,
            current_balance: $('#account-initial-balance').val().replace(/,/g, '')
        };

        const result = await Api.call('save_account', data);
        if (result?.success) {
            UI.showSuccess('حساب با موفقیت ذخیره شد.');
            $('#accountModal').modal('hide');
            load();
            App.fetchInitialCache();
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

    function init() {
        $('#add-account-btn').on('click', () => prepareModal(null));
        $('#account-form').on('submit', handleFormSubmit);

        $('#accounts-list-container').on('click', '.list-group-item', function (e) {
            e.preventDefault();
            const target = $(e.target);
            if (target.is('.btn') || target.parent().is('.btn')) {
                return;
            }
            const accountId = $(this).data('id');
            if (accountId !== currentAccountId) {
                loadAccountTransactions(accountId);
            }
        });

        $('#accounts-list-container').on('click', '.btn-edit-account', function (e) {
            e.stopPropagation();
            const accountId = $(this).data('id');
            const account = accountsCache.find(acc => acc.id == accountId);
            prepareModal(account);
        });

        $('#accounts-list-container').on('click', '.btn-delete-account', function (e) {
            e.stopPropagation();
            const accountId = $(this).data('id');
            handleDelete(accountId);
        });
    }

    return {
        init,
        load,
        config: {
            tableName: 'accounts',
            apiName: 'account',
            nameFa: 'حساب',
        }
    };
})();