// /js/transaction-manager.js

const TransactionManager = (function () {
    const config = {
        tableName: 'transactions',
        apiName: 'transaction',
        nameFa: 'تراکنش',
        modalId: '#transactionModal',
        formId: '#transaction-form',
    };
    const state = AppConfig.TABLE_STATES[config.tableName];

    function renderTable(data) {
        const body = $('#transactions-table-body').empty();
        if (!data || !data.length) {
            return body.html('<tr><td colspan="7" class="text-center">تراکنشی یافت نشد.</td></tr>');
        }
        data.forEach(tx => {
            const isUncashedCheck = tx.source_table === 'check' && tx.check_status !== 'cashed';
            const isExpense = tx.source_table === 'expense';
            let rowClass = '';

            if (isUncashedCheck) {
                rowClass = 'table-secondary fst-italic';
            } else if (isExpense) {
                rowClass = 'table-warning';
            } else if (tx.credit > 0) {
                // Using a lighter green for better readability
                rowClass = 'table-success-light';
            } else if (tx.debit > 0) {
                // Using a lighter red for better readability
                rowClass = 'table-danger-light';
            }

            const persianDate = UI.gregorianToPersian(tx.date);

            let editButton = '';
            if (tx.invoiceId) {
                const invoiceType = tx.invoiceType === 'sales' ? 'salesInvoice' : 'purchaseInvoice';
                editButton = `<button class="btn btn-sm btn-info btn-edit-invoice-from-tx" data-invoice-id="${tx.invoiceId}" data-invoice-type="${invoiceType}" title="ویرایش فاکتور مربوطه"><i class="bi bi-receipt"></i></button>`;
            } else if (!isUncashedCheck) {
                const apiType = isExpense ? 'expense' : 'payment';
                const entityData = JSON.stringify(tx);
                editButton = `<button class="btn btn-sm btn-warning btn-edit" data-id="${tx.id}" data-type="${apiType}" data-entity='${entityData}' title="ویرایش تراکنش"><i class="bi bi-pencil-square"></i></button>`;
            }

            const canDelete = !tx.invoiceId;
            const deleteButtonType = isExpense ? 'expense' : (tx.source_table === 'check' ? 'check' : 'payment');
            const deleteButton = canDelete ? `<button class="btn btn-sm btn-danger btn-delete" data-type="${deleteButtonType}" data-id="${tx.id}" title="حذف"><i class="bi bi-trash"></i></button>` : '';

            const buttons = `<div class="btn-group">${editButton}${deleteButton}</div>`;

            const row = $(`<tr class="${rowClass}">
                <td>${persianDate}</td>
                <td>${tx.details || ''}</td>
                <td>${tx.accountName || '-'}</td>
                <td>${UI.formatCurrency(tx.credit)}</td>
                <td>${UI.formatCurrency(tx.debit)}</td>
                <td>${tx.description || ''}</td>
                <td>${buttons}</td>
            </tr>`);
            body.append(row);
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

    async function handleDelete(type, id) {
        const entityNameMap = {
            'expense': 'هزینه',
            'payment': 'پرداخت',
            'check': 'چک و تمام سوابق مالی مرتبط با آن'
        };
        const entityName = entityNameMap[type] || 'مورد';
        UI.confirmAction(`آیا از حذف این ${entityName} مطمئن هستید؟ این عملیات اثرات مالی را نیز بازمی‌گرداند.`, async (confirmed) => {
            if (confirmed) {
                UI.showLoader();
                const result = await Api.call(`delete_${type}`, { id });
                UI.hideLoader();
                if (result?.success) {
                    UI.showSuccess(`${entityName} با موفقیت حذف شد.`);
                    await App.updateCache(['checks', 'accounts', 'customers', 'suppliers']);
                    load();
                    AccountManager.load();
                    CheckManager.load();
                }
            }
        });
    }

    async function prepareTransactionModal(entity = null, defaults = {}) {
        const modal = $(config.modalId);
        UI.hideModalError(config.modalId);
        const form = $(config.formId)[0];
        form.reset();

        updatePaymentMethod('cash');

        form.dataset.checkId = '';

        if ($('#person-select').data('select2')) {
            $('#person-select').select2('destroy');
        }
        $('#person-select').select2({
            theme: 'bootstrap-5',
            dir: 'rtl',
            placeholder: "جستجو و انتخاب شخص...",
            dropdownParent: modal
        });

        modal.find('.modal-title').text(entity ? 'ویرایش پرداخت/دریافت' : 'ثبت پرداخت/دریافت');

        $('#transaction-id').val(entity ? entity.id : '');

        const accountSelect = $('#transaction-account-id').empty().append('<option value="">-- انتخاب کنید --</option>');
        App.getCache().accounts.forEach(acc => accountSelect.append(`<option value="${acc.id}">${acc.name}</option>`));

        if (entity) {
            $('#transaction-date').val(UI.gregorianToPersian(entity.date));
            $('#transaction-account-id').val(entity.account_id);
            $('#transaction-flow').val(entity.transaction_type);
            $('#person-type').val(entity.person_type).trigger('change');

            setTimeout(() => {
                $('#person-select').val(entity.person_id).trigger('change');
            }, 100);

            $('#payment-method').val(entity.type).trigger('change');
            $('#transaction-amount-payment').val(Number(entity.amount).toLocaleString('en-US'));
            $('#transaction-description-payment').val(entity.description);

            if (entity.type === 'check' && entity.checkId) {
                form.dataset.checkId = entity.checkId;
                setTimeout(() => {
                    const check = App.getCache().checks.find(c => c.id == entity.checkId);
                    if (check) {
                        const detailsContainer = $('#payment-details-container');
                        detailsContainer.find('.check-number').val(check.checkNumber);
                        detailsContainer.find('.check-due-date').val(UI.gregorianToPersian(check.dueDate));
                        detailsContainer.find('.check-bank-name').val(check.bankName);
                    }
                }, 100);
            } else if (entity.type === 'endorse_check' && entity.checkId) {
                form.dataset.checkId = entity.checkId;
                setTimeout(() => {
                    const detailsContainer = $('#payment-details-container');
                    detailsContainer.find('.endorsed-check-select').val(entity.checkId);
                }, 100);
            }
        } else {
            $('#transaction-date').val(UI.today());
            $('#person-type').val(defaults.person_type || 'customer').trigger('change');
            $('#transaction-flow').val(defaults.transaction_type || 'receipt');
            $('#payment-method').val(defaults.type || 'cash').trigger('change');
        }

        modal.modal('show');
    }

    function updatePersonSelect(personType) {
        const personSelect = $('#person-select').empty().append('<option value="">-- انتخاب کنید --</option>');
        let list = [];
        switch (personType) {
            case 'customer': list = App.getCache().customers; break;
            case 'supplier': list = App.getCache().suppliers; break;
            case 'partner': list = App.getCache().partners; break;
        }
        list.forEach(p => personSelect.append(`<option value="${p.id}">${p.name}</option>`));
    }

    function updatePaymentMethod(method) {
        const detailsContainer = $('#payment-details-container');
        const amountInput = $('#transaction-amount-payment');
        detailsContainer.empty().hide();

        if (method === 'check') {
            const dueDate = UI.today();
            detailsContainer.html(`<div class="row g-2 mt-1">
                <div class="col-4"><label class="form-label-sm">شماره چک</label><input type="text" class="form-control form-control-sm check-number" required></div>
                <div class="col-4"><label class="form-label-sm">تاریخ سررسید</label><input type="text" class="form-control form-control-sm persian-datepicker check-due-date" value="${dueDate}" required></div>
                <div class="col-4"><label class="form-label-sm">نام بانک</label><input type="text" class="form-control form-control-sm check-bank-name" required></div>
            </div>`);
            amountInput.prop('readonly', false);
            UI.initializeDatepickers();
            detailsContainer.show();
        } else if (method === 'endorse_check') {
            const availableChecks = (App.getCache().checks || []).filter(c => c.type === 'received' && c.status === 'in_hand');
            if (availableChecks.length === 0) {
                detailsContainer.html('<p class="text-danger small mt-2">چک قابل واگذاری برای خرج کردن وجود ندارد.</p>');
            } else {
                const options = availableChecks.map(c => `<option value="${c.id}" data-amount="${c.amount}">${c.checkNumber} - ${UI.formatCurrency(c.amount)}</option>`).join('');
                detailsContainer.html(`<div><label class="form-label-sm">انتخاب چک جهت خرج کردن</label><select class="form-select form-select-sm endorsed-check-select" required><option value="">-- انتخاب کنید --</option>${options}</select></div>`);
            }
            amountInput.prop('readonly', true);
            detailsContainer.show();
        } else { // Cash
            amountInput.prop('readonly', false);
        }
    }

    async function handleFormSubmit(e) {
        e.preventDefault();
        UI.hideModalError(config.modalId);
        const form = $(e.target)[0];
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }

        const paymentMethod = $('#payment-method').val();
        let data = {
            id: $('#transaction-id').val() || null,
            person_id: $('#person-select').val(),
            person_type: $('#person-type').val(),
            transaction_type: $('#transaction-flow').val(),
            type: paymentMethod,
            amount: $('#transaction-amount-payment').val().replace(/,/g, ''),
            date: UI.toGregorian('#transaction-date'),
            account_id: $('#transaction-account-id').val(),
            description: $('#transaction-description-payment').val(),
            checkId: form.dataset.checkId || null
        };

        if (paymentMethod === 'check') {
            data.checkDetails = {
                checkNumber: $('#payment-details-container .check-number').val(),
                dueDate: UI.toGregorian($('#payment-details-container .check-due-date')),
                bankName: $('#payment-details-container .check-bank-name').val()
            };
        } else if (paymentMethod === 'endorse_check') {
            data.checkId = $('#payment-details-container .endorsed-check-select').val();
        }

        UI.showLoader();
        const result = await Api.call('save_payment', data);
        UI.hideLoader();

        if (result?.success) {
            UI.showSuccess('تراکنش با موفقیت ذخیره شد.');
            $(config.modalId).modal('hide');
            await App.updateCache(['checks', 'accounts', 'customers', 'suppliers']);
            load();
            AccountManager.load();
            App.getManager('checks').load();
        } else if (result?.error) {
            UI.showModalError(config.modalId, result.error);
        }
    }

    function attachEvents() {
        $('#add-payment-btn-from-tx').on('click', () => prepareTransactionModal(null));
        $('#add-expense-btn-from-tx').on('click', () => App.getManager('expenses').prepareModal(null));

        $(config.formId).on('submit', handleFormSubmit);

        $('#person-type').on('change', function () { updatePersonSelect($(this).val()); });
        $('#payment-method').on('change', function () { updatePaymentMethod($(this).val()); });

        $('body').on('change', '#payment-details-container .endorsed-check-select', function () {
            const selectedOption = $(this).find('option:selected');
            const amount = selectedOption.data('amount') || 0;
            $('#transaction-amount-payment').val(Number(amount).toLocaleString('en-US'));
        });

        const searchHandler = UI.debounce((term) => {
            state.searchTerm = term;
            state.currentPage = 1;
            load();
        }, 500);
        $('#transaction-search').on('input', function () { searchHandler($(this).val()); });

        $('#transaction-filter-buttons').on('click', 'button', function () {
            const filterValue = $(this).data('filter');
            $('#transaction-filter-buttons button').removeClass('active');
            $(this).addClass('active');
            state.typeFilter = filterValue;
            state.currentPage = 1;
            load();
        });

        $('body').on('click', '#transactions-table .btn-delete', function () {
            const type = $(this).data('type');
            const id = $(this).data('id');
            handleDelete(type, id);
        });

        $('body').on('click', '#transactions-table .btn-edit', async function () {
            const type = $(this).data('type');
            const id = $(this).data('id');
            const entityString = $(this).attr('data-entity');

            let entityData = null;
            if (entityString) {
                try { entityData = JSON.parse(entityString); } catch (e) { console.error("Could not parse entity data from button."); }
            }

            if (!entityData) {
                UI.showLoader();
                entityData = await Api.call('get_entity_by_id', { entityType: type, id: id });
                UI.hideLoader();
            }

            if (entityData) {
                if (type === 'expense') {
                    App.getManager('expenses').prepareModal(entityData);
                } else {
                    prepareTransactionModal(entityData);
                }
            }
        });

        $('body').on('click', '#transactions-table .btn-edit-invoice-from-tx', async function () {
            const invoiceId = $(this).data('invoice-id');
            const invoiceType = $(this).data('invoice-type');

            UI.showLoader();
            const entityData = await Api.call('get_entity_by_id', { entityType: invoiceType, id: invoiceId });
            UI.hideLoader();

            if (entityData) {
                const type = invoiceType === 'salesInvoice' ? 'sales' : 'purchase';
                InvoiceManager.prepareInvoiceModal(type, entityData);
            }
        });
    }

    return {
        init: attachEvents,
        load,
        prepareTransactionModal
    };
})();