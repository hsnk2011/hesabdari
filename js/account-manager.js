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
            renderTransactionsTable([]); // Render an empty table if no accounts exist
            return;
        }

        accounts.forEach(acc => {
            const icon = acc.is_cash ? '<i class="bi bi-cash-stack text-success"></i>' : '<i class="bi bi-bank2 text-primary"></i>';
            const item = $(`
                <a href="#" class="list-group-item list-group-item-action" data-id="${acc.id}">
                    <div class="d-flex w-100 justify-content-between align-items-center">
                        <h6 class="mb-1">${icon} ${acc.name}</h6>
                        <div>
                            <button class="btn btn-sm btn-outline-warning btn-edit-account py-0 px-1" data-id="${acc.id}" title="ویرایش"><i class="bi bi-pencil"></i></button>
                            <button class="btn btn-sm btn-outline-danger btn-delete-account py-0 px-1" data-id="${acc.id}" title="حذف"><i class="bi bi-trash"></i></button>
                        </div>
                    </div>
                    <p class="mb-1 fw-bold ${acc.current_balance >= 0 ? '' : 'text-danger'}">${UI.formatCurrency(acc.current_balance)}</p>
                    <small>${acc.is_cash ? 'صندوق' : acc.bank_name || ''}</small>
                </a>
            `);
            container.append(item);
        });

        if (accounts.length > 0 && !accounts.some(a => a.id == currentAccountId)) {
            loadAccountTransactions(accounts[0].id);
        } else if (currentAccountId) {
            $(`#accounts-list-container .list-group-item[data-id="${currentAccountId}"]`).addClass('active');
        }
    }

    function renderTransactionsTable(transactions) {
        const body = $('#account-transactions-table-body').empty();
        const selectedAccount = accountsCache.find(acc => acc.id == currentAccountId);

        if (!selectedAccount) return;

        if (!transactions || !transactions.length) {
            body.html('<tr><td colspan="5" class="text-center">تراکنشی برای این حساب یافت نشد.</td></tr>');
            return;
        }

        let runningBalance = parseFloat(selectedAccount.current_balance || 0);

        transactions.forEach(tx => {
            let description = tx.description || '';
            let bostankar = 0;
            let bedehkar = 0;

            switch (tx.source) {
                case 'payment':
                    bostankar = tx.invoiceType === 'sales' ? tx.amount : 0;
                    bedehkar = tx.invoiceType === 'purchase' ? tx.amount : 0;
                    description = `فاکتور ${tx.invoiceType === 'sales' ? 'فروش' : 'خرید'} #${tx.invoiceId} - ${description}`;
                    break;
                case 'expense':
                    bedehkar = tx.amount;
                    description = `${tx.type} - ${description}`;
                    break;
                case 'partner':
                    bostankar = tx.type === 'واریز شریک' ? tx.amount : 0;
                    bedehkar = tx.type === 'برداشت شریک' ? tx.amount : 0;
                    description = `${tx.type} - ${description}`;
                    break;
                case 'check':
                    bostankar = tx.amount;
                    break;
            }

            const row = $(`
                <tr>
                    <td>${tx.date}</td>
                    <td>${description}</td>
                    <td class="text-success">${bostankar !== 0 ? UI.formatCurrency(bostankar) : '-'}</td>
                    <td class="text-danger">${bedehkar !== 0 ? `(${UI.formatCurrency(bedehkar)})` : '-'}</td>
                    <td class="fw-bold">${UI.formatCurrency(runningBalance)}</td>
                </tr>
            `);
            body.append(row);

            runningBalance -= (bostankar - bedehkar);
        });

        transactionsDataTable = $('#account-transactions-table').DataTable({
            language: { url: 'assets/js/i18n/fa.json' },
            order: [[0, 'desc']],
            pageLength: 10,
            lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "همه"]]
        });
    }

    async function loadAccountTransactions(accountId) {
        currentAccountId = accountId;

        $('#accounts-list-container .list-group-item').removeClass('active');
        $(`#accounts-list-container .list-group-item[data-id="${accountId}"]`).addClass('active');

        const selectedAccount = accountsCache.find(acc => acc.id == accountId);
        $('#account-ledger-title').text(`گردش حساب: ${selectedAccount ? selectedAccount.name : '--'}`);

        // --- KEY CHANGE: Destroy DataTable before loading new data ---
        if (transactionsDataTable) {
            transactionsDataTable.destroy();
            transactionsDataTable = null;
        }
        $('#account-transactions-table-body').html('<tr><td colspan="5" class="text-center"><div class="spinner-border spinner-border-sm"></div></td></tr>');

        const transactions = await Api.call('get_account_transactions', { accountId });
        renderTransactionsTable(transactions);
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
        }
    }

    async function handleDelete(accountId) {
        if (confirm('آیا از حذف این حساب مطمئن هستید؟ این عملیات قابل بازگشت نیست.')) {
            const result = await Api.call('delete_account', { id: accountId });
            if (result?.success) {
                currentAccountId = null;
                load();
            }
        }
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