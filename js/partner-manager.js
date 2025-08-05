// /js/partner-manager.js

const PartnerManager = (function () {
    let editingTransactionId = null;

    const transactionManagerConfig = {
        tableName: 'partner_transactions',
        apiName: 'partnerTransaction',
        nameFa: 'تراکنش شریک',
        renderTable: renderPartnerTransactionsTable,
        refreshTables: ['partner_transactions', 'accounts'],
    };
    const transactionManager = createEntityManager(transactionManagerConfig);

    function renderPartnersList() {
        const list = $('#partners-list').empty();
        const partners = App.getCache().partners || [];

        partners.forEach(p => {
            const item = $(`<li class="list-group-item d-flex justify-content-between align-items-center">
                <div><h6 class="my-0">${p.name}</h6><small class="text-muted">سهم: ${p.share * 100}%</small></div>
                <span>
                    <button class="btn btn-sm btn-outline-warning btn-edit" data-type="partner"><i class="bi bi-pencil-square"></i></button> 
                    <button class="btn btn-sm btn-outline-danger btn-delete" data-type="partner" data-id="${p.id}"><i class="bi bi-trash"></i></button>
                </span>
            </li>`);
            item.find('.btn-edit').data('entity', p);
            list.append(item);
        });
    }

    function renderPartnerSelect() {
        const select = $('#partner-select').empty();
        const partners = App.getCache().partners || [];
        partners.forEach(p => select.append(`<option value="${p.id}">${p.name}</option>`));
    }

    function preparePartnerModal(partner = null) {
        $('#partner-form')[0].reset();
        $('#partner-id').val('');
        if (partner) {
            $('#partnerModalTitle').text('ویرایش شریک');
            $('#partner-id').val(partner.id);
            $('#partner-name').val(partner.name);
            $('#partner-share').val(partner.share);
        } else {
            $('#partnerModalTitle').text('افزودن شریک');
        }
        $('#partnerModal').modal('show');
    }

    async function handlePartnerFormSubmit() {
        const data = {
            id: $('#partner-id').val(),
            name: $('#partner-name').val(),
            share: $('#partner-share').val()
        };
        UI.showLoader();
        const result = await Api.call('save_partner', data);
        UI.hideLoader();
        if (result?.success) {
            $('#partnerModal').modal('hide');
            await App.fetchInitialCache();
        }
    }

    async function handlePartnerDelete(id) {
        if (confirm(`آیا از حذف این شریک مطمئن هستید؟`)) {
            UI.showLoader();
            const result = await Api.call(`delete_partner`, { id });
            UI.hideLoader();
            if (result?.success) {
                await App.fetchInitialCache();
            }
        }
    }

    function resetTransactionForm() {
        editingTransactionId = null;
        $('#partner-transaction-form')[0].reset();
        $('#partner-transaction-date').val(UI.today());
        $('#partner-transaction-form button[type="submit"]').text('ثبت تراکنش').removeClass('btn-warning').addClass('btn-success');
        $('#partner-transaction-form').find('.cancel-edit-btn').remove();
    }

    function prepareTransactionFormForEdit(transaction) {
        editingTransactionId = transaction.id;
        $('#partner-select').val(transaction.partnerId);
        $('#partner-transaction-type').val(transaction.type);
        $('#partner-transaction-date').val(transaction.date);
        $('#partner-transaction-amount').val(Number(transaction.amount).toLocaleString('en-US'));
        $('#partner-transaction-account-id').val(transaction.account_id);
        $('#partner-transaction-desc').val(transaction.description);

        const form = $('#partner-transaction-form');
        form.find('button[type="submit"]').text('ذخیره تغییرات').removeClass('btn-success').addClass('btn-warning');
        if (form.find('.cancel-edit-btn').length === 0) {
            const cancelButton = $('<button type="button" class="btn btn-secondary mt-2 w-100 cancel-edit-btn">انصراف از ویرایش</button>');
            form.append(cancelButton);
        }
        form.find('label').first()[0].scrollIntoView({ behavior: 'smooth' });
    }

    async function handleTransactionFormSubmit(e) {
        e.preventDefault();
        const data = {
            id: editingTransactionId,
            partnerId: $('#partner-select').val(),
            type: $('#partner-transaction-type').val(),
            date: $('#partner-transaction-date').val(),
            amount: $('#partner-transaction-amount').val().replace(/,/g, ''),
            account_id: $('#partner-transaction-account-id').val(),
            description: $('#partner-transaction-desc').val()
        };
        UI.showLoader();
        const result = await Api.call('save_partner_transaction', data);
        UI.hideLoader();
        if (result?.success) {
            resetTransactionForm();
            transactionManager.load();
            AccountManager.load();
        }
    }

    function renderPartnerTransactionsTable(data) {
        const body = $('#partner-transactions-table-body').empty();
        if (!data || !data.length) {
            return body.html('<tr><td colspan="7" class="text-center">تراکنشی برای شرکا ثبت نشده است.</td></tr>');
        }
        data.forEach(pt => {
            const typeText = pt.type === 'DEPOSIT' ? 'واریز' : 'برداشت';
            const amountClass = pt.type === 'DEPOSIT' ? 'text-success' : 'text-danger';
            const row = $(`<tr>
                <td>${pt.partnerName || ''}</td>
                <td>${pt.date}</td>
                <td>${typeText}</td>
                <td class="${amountClass}">${UI.formatCurrency(pt.amount)}</td>
                <td>${pt.accountName || 'N/A'}</td>
                <td>${pt.description || ''}</td>
                <td>
                    <button class="btn btn-sm btn-warning btn-edit-tx"><i class="bi bi-pencil-square"></i></button>
                    <button class="btn btn-sm btn-danger btn-delete" data-type="partnerTransaction" data-id="${pt.id}"><i class="bi bi-trash"></i></button>
                </td>
            </tr>`);
            row.find('.btn-edit-tx').data('entity', pt);
            body.append(row);
        });
    }

    function attachEvents() {
        transactionManager.init();

        $('#add-partner-btn').on('click', () => preparePartnerModal(null));
        $('#partner-form').on('submit', (e) => { e.preventDefault(); handlePartnerFormSubmit(); });
        $('#partner-transaction-form').on('submit', handleTransactionFormSubmit);

        $('body').on('click', '#partners-list .btn-edit', function () {
            preparePartnerModal($(this).data('entity'));
        });
        $('body').on('click', '#partners-list .btn-delete', function () {
            handlePartnerDelete($(this).data('id'));
        });

        $('body').on('click', '#partner-transactions-table .btn-edit-tx', function () {
            const transactionData = $(this).data('entity');
            prepareTransactionFormForEdit(transactionData);
        });

        $('body').on('click', '#partner-transaction-form .cancel-edit-btn', function () {
            resetTransactionForm();
        });
    }

    function refreshUI() {
        renderPartnersList();
        renderPartnerSelect();

        const accountSelect = $('#partner-transaction-account-id').empty().append('<option value="">-- انتخاب کنید --</option>');
        App.getCache().accounts.forEach(acc => {
            accountSelect.append(`<option value="${acc.id}">${acc.name}</option>`);
        });
    }

    async function load() {
        refreshUI();
        resetTransactionForm();
        await transactionManager.load();
    }

    function init() {
        attachEvents();
        App.registerManager('partner_transactions', transactionManager);
    }

    return {
        init: init,
        load,
        refreshUI,
    };
})();