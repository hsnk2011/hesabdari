// /js/check-manager.js

const CheckManager = (function () {
    const config = {
        tableName: 'checks',
        apiName: 'check',
        nameFa: 'چک',
        modalId: '#transactionModal', // Use the unified transaction modal
    };
    const state = AppConfig.TABLE_STATES[config.tableName];

    function renderTable(data) {
        const getCheckStatusText = (s, t) => {
            if (!s) return '';
            const receivedStatus = { in_hand: 'نزد ما', endorsed: 'واگذار شده', cashed: 'وصول شده', bounced: 'برگشتی' };
            const payableStatus = { payable: 'پرداختنی', cashed: 'پاس شده', bounced: 'برگشتی' };
            return t === 'received' ? (receivedStatus[s] || s) : (payableStatus[s] || s);
        };

        const body = $('#checks-table-body').empty();
        if (!data || !data.length) {
            body.html('<tr><td colspan="8" class="text-center">چکی یافت نشد.</td></tr>');
            return;
        }

        data.forEach(c => {
            const typeText = c.type === 'received' ? 'دریافتی' : 'پرداختی';
            const typeClass = c.type === 'received' ? 'text-success' : 'text-danger';
            let related = c.invoiceId ? `فاکتور ${c.invoiceType === 'sales' ? 'فروش' : 'خرید'} #${c.invoiceId}` : 'مستقل (علی الحساب)';

            let actions = '';
            if (c.type === 'received' && c.status === 'in_hand') {
                actions += `<button class="btn btn-sm btn-success btn-cash-check" data-id="${c.id}" title="وصول چک"><i class="bi bi-check-circle-fill"></i></button> `;
            } else if (c.type === 'payable' && c.status === 'payable') {
                actions += `<button class="btn btn-sm btn-primary btn-clear-check" data-id="${c.id}" title="ثبت پاس شدن چک"><i class="bi bi-check-all"></i></button> `;
            }

            // Edit button on the payment record, not the check itself, is more accurate now.
            // Let's find the related payment to edit it.
            actions += `<button class="btn btn-sm btn-warning btn-edit-payment-from-check" data-check-id="${c.id}" title="ویرایش تراکنش چک"><i class="bi bi-pencil-square"></i></button> `;


            if (!c.invoiceId && !['cashed', 'endorsed'].includes(c.status)) {
                actions += `<button class="btn btn-sm btn-danger btn-delete" data-type="check" data-id="${c.id}" title="حذف چک و تراکنش"><i class="bi bi-trash"></i></button>`;
            }

            const dueDateStr = UI.gregorianToPersian(c.dueDate);

            body.append(`<tr>
                <td class="${typeClass}">${typeText}</td>
                <td>${c.checkNumber}</td>
                <td>${c.bankName || '-'}</td>
                <td>${UI.formatCurrency(c.amount)}</td>
                <td>${dueDateStr}</td>
                <td>${getCheckStatusText(c.status, c.type)}</td>
                <td>${related}</td>
                <td><div class="btn-group">${actions}</div></td>
            </tr>`);
        });
    }

    async function load() {
        UI.showLoader();
        state.tableName = config.tableName;
        const response = await Api.call('get_paginated_data', state);
        if (response) {
            renderTable(response.data);
            UI.renderPagination(config.tableName, response.totalRecords, state);
            UI.updateSortIndicators(config.tableName, state);
        }
        UI.hideLoader();
    }

    async function handleDelete(id) {
        UI.confirmAction(`آیا از حذف این چک و تراکنش مالی مرتبط با آن مطمئن هستید؟ این عملیات قابل بازگشت نیست.`, async (confirmed) => {
            if (confirmed) {
                UI.showLoader();
                const result = await Api.call('delete_check', { id });
                UI.hideLoader();
                if (result?.success) {
                    UI.showSuccess('چک با موفقیت حذف شد.');
                    await App.updateCache(['checks', 'accounts']);
                    load();
                    App.getManager('transactions').load();
                }
            }
        });
    }

    function attachEvents() {
        // This button now opens the generic transaction modal with defaults for a new check.
        $('#add-check-btn').on('click', () => {
            App.getManager('transactions').prepareTransactionModal(null, { type: 'check' });
        });

        const searchHandler = UI.debounce((term) => {
            state.searchTerm = term;
            state.currentPage = 1;
            load();
        }, 500);
        $('#check-search').on('input', function () { searchHandler($(this).val()); });

        $('body').on('click', '#checks-table .btn-delete', function () {
            handleDelete($(this).data('id'));
        });

        $('body').on('click', '#checks-table .btn-edit-payment-from-check', async function () {
            const checkId = $(this).data('check-id');
            if (!checkId) return;

            UI.showLoader();
            // We need to find the payment associated with this checkId
            const payment = await Api.call('get_entity_by_id', { entityType: 'payment', id: checkId, by: 'checkId' });
            UI.hideLoader();

            if (payment) {
                App.getManager('transactions').prepareTransactionModal(payment);
            } else {
                UI.showError('تراکنش مرتبط با این چک یافت نشد.');
            }
        });
    }

    return {
        init: attachEvents,
        load
    };
})();