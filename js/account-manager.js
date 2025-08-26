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

    function renderTransactionsTable(apiResponse) {
        const transactions = apiResponse || [];

        if (transactionsDataTable) {
            transactionsDataTable.destroy();
        }

        const tableData = [];
        transactions.forEach(tx => {
            let description = tx.type;
            if (tx.description && !description.includes(tx.description)) {
                description += ` - ${tx.description}`;
            }

            let bostankar = 0, bedehkar = 0;
            const source = tx.source || '';

            if (source.endsWith('_in') || source === 'check') bostankar = tx.amount;
            else if (source.endsWith('_out')) bedehkar = tx.amount;

            if (source.startsWith('partner_personal')) {
                bostankar = source.endsWith('_in') ? tx.amount : 0;
                bedehkar = source.endsWith('_out') ? tx.amount : 0;
            }

            tableData.push({
                date: tx.date,
                description: description,
                bostankar: bostankar,
                bedehkar: bedehkar
            });
        });

        transactionsDataTable = $('#account-transactions-table').DataTable({
            language: { url: 'assets/js/i18n/fa.json' },
            data: tableData,
            order: [[0, 'asc']],
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
                    data: null, // Placeholder for running balance
                    className: 'text-end fw-bold',
                    orderable: false,
                    defaultContent: ''
                }
            ],
            // Calculate running balance after table is drawn
            "drawCallback": function (settings) {
                const api = this.api();
                const rows = api.rows({ page: 'current' }).nodes();
                let balance = 0; // In a full implementation, this should start with an opening balance.

                // This is a simplified running balance for the current page view.
                // A full solution would require calculating balance across all pages.
                api.rows({ page: 'current' }).data().each(function (data, i) {
                    balance += (data.bostankar - data.bedehkar);
                    $(rows).eq(i).find('td:last-child').html(UI.formatCurrency(balance));
                });
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

        renderTransactionsTable(response);
    }

    async function load() {
        const accounts = await Api.call('get_full_accounts_list');
        renderAccountsList(accounts);
    }

    function prepareModal(account = null) {
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
            $('#accountModal').modal('hide');
            load();
            App.fetchInitialCache();
        }
    }

    async function handleDelete(accountId) {
        UI.confirmAction('آیا از حذف این حساب مطمئن هستید؟ این عملیات قابل بازگشت نیست.', async (confirmed) => {
            if (confirmed) {
                const result = await Api.call('delete_account', { id: accountId });
                if (result?.success) {
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