// /js/transaction-manager.js

const TransactionManager = (function () {
    const config = {
        tableName: 'transactions',
        apiName: 'transaction',
        nameFa: 'تراکنش',
        modalId: '#transactionModal', // Use the unified transaction modal
        formId: '#transaction-form' // *** FIX: Added missing formId ***
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
            const isIncome = tx.source_table === 'income';
            let rowClass = '';

            if (isUncashedCheck) {
                rowClass = 'table-secondary fst-italic';
            } else if (isExpense) {
                rowClass = 'table-warning';
            } else if (isIncome) {
                rowClass = 'table-success';
            } else if (tx.credit > 0) {
                rowClass = 'table-success-light';
            } else if (tx.debit > 0) {
                rowClass = 'table-danger-light';
            }

            const persianDate = UI.gregorianToPersian(tx.date);
            // Use the details from backend directly - it now includes all check information
            let detailsText = tx.details || '';
            
            // Convert any Gregorian dates in details to Persian (format: YYYY-MM-DD)
            detailsText = detailsText.replace(/(\d{4})-(\d{2})-(\d{2})/g, (match) => {
                try {
                    return UI.gregorianToPersian(new Date(match));
                } catch (e) {
                    return match; // Return original if conversion fails
                }
            });

            // *** START FIX: Modify button logic based on transaction_class ***
            let editButton = '';
            const transactionClass = tx.transaction_class || (isExpense ? 'expense' : (isIncome ? 'income' : 'payment')); // Default for old data

            if (tx.invoiceId) {
                let invoiceType, perm_prefix;
                if (tx.invoiceType === 'return') {
                    const isReturnSales = (tx.transaction_type === 'payment');
                    invoiceType = isReturnSales ? 'salesReturn' : 'purchaseReturn';
                    perm_prefix = 'return_invoices';
                } else if (tx.transaction_type === 'payment' && tx.person_type === 'customer') {
                    invoiceType = 'salesReturn';
                    perm_prefix = 'return_invoices';
                } else if (tx.transaction_type === 'receipt' && tx.person_type === 'supplier') {
                    invoiceType = 'purchaseReturn';
                    perm_prefix = 'return_invoices';
                } else if (tx.invoiceType === 'sales') {
                    invoiceType = 'salesInvoice';
                    perm_prefix = 'sales_invoices';
                } else {
                    invoiceType = 'purchaseInvoice';
                    perm_prefix = 'purchase_invoices';
                }
                editButton = `<button class="btn btn-sm btn-info btn-edit-invoice-from-tx" data-invoice-id="${tx.invoiceId}" data-invoice-type="${invoiceType}" title="ویرایش فاکتور مربوطه" data-permission-action="manage_${perm_prefix}"><i class="bi bi-receipt"></i></button>`;
            } else if (transactionClass === 'transfer') {
                // No edit button for transfers from this screen. Must be deleted and recreated.
                editButton = ''; 
            } else if (!isUncashedCheck) {
                const apiType = isExpense ? 'expense' : (isIncome ? 'income' : 'payment');
                const entityData = JSON.stringify(tx);
                editButton = `<button class="btn btn-sm btn-warning btn-edit" data-id="${tx.id}" data-type="${apiType}" data-entity='${entityData}' title="ویرایش تراکنش" data-permission-action="manage_transactions"><i class="bi bi-pencil-square"></i></button>`;
            }

            // Allow deleting transfers, but use a specific type 'transfer'
            const canDelete = !tx.invoiceId;
            let deleteButtonType = isExpense ? 'expense' : (isIncome ? 'income' : (tx.source_table === 'check' ? 'check' : 'payment'));
            if (transactionClass === 'transfer') {
                deleteButtonType = 'transfer';
            }
            const deletePerm = (deleteButtonType === 'check') ? 'manage_checks' : ((deleteButtonType === 'transfer') ? 'manage_accounts' : 'manage_transactions');
            const deleteButton = canDelete ? `<button class="btn btn-sm btn-danger btn-delete" data-type="${deleteButtonType}" data-id="${tx.id}" title="حذف" data-permission-action="${deletePerm}"><i class="bi bi-trash"></i></button>` : '';

            const buttons = `<div class="btn-group">${editButton}${deleteButton}</div>`;
            // *** END FIX ***

            const row = $(`<tr class="${rowClass}">
                <td>${UI.escapeHtml(persianDate)}</td>
                <td>${UI.escapeHtml(detailsText)}</td>
                <td>${UI.escapeHtml(tx.accountName || '-')}</td>
                <td>${UI.formatCurrency(tx.credit)}</td>
                <td>${UI.formatCurrency(tx.debit)}</td>
                <td>${UI.escapeHtml(tx.description || '')}</td>
                <td>${buttons}</td>
            </tr>`);
            body.append(row);
        });
        // FIX: Call the function directly on the App object
        App.applyPermissions();
    }

    function populateAccountFilter() {
        const sel = $('#transaction-account-filter');
        if (!sel.length) return;
        const current = state.accountFilter || sel.val() || '';
        sel.empty().append('<option value="">همه حساب‌ها</option>');
        (App.getCache().accounts || []).forEach(acc => {
            const tag = (acc.is_cash == 1 || acc.is_cash === true || acc.is_cash === '1') ? ' (صندوق)' : '';
            sel.append(`<option value="${acc.id}">${UI.escapeHtml(acc.name)}${tag}</option>`);
        });
        if (current) sel.val(String(current));
    }

    async function load() {
        UI.showLoader();
        populateAccountFilter();
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
        // *** START FIX: Add logic for 'transfer' type ***
        const entityNameMap = {
            'expense': 'هزینه',
            'income': 'درآمد',
            'payment': 'پرداخت',
            'check': 'چک و تمام سوابق مالی مرتبط با آن',
            'transfer': 'تراکنش انتقال وجه (هر دو طرف)'
        };
        const entityName = entityNameMap[type] || 'مورد';
        let message = `آیا از حذف این ${entityName} مطمئن هستید؟`;
        if (type === 'transfer') {
            message = "آیا از حذف این انتقال وجه مطمئن هستید؟ این عمل هر دو رکورد (واریز و برداشت) را حذف کرده و موجودی هر دو حساب را اصلاح می‌کند.";
        }

        UI.confirmAction(message, async (confirmed) => {
            if (confirmed) {
                UI.showLoader();
                
                let result;
                if (type === 'transfer') {
                    result = await Api.call('delete_transfer', { paymentId: id });
                } else {
                    result = await Api.call(`delete_${type}`, { id });
                }

                UI.hideLoader();
                if (result?.success) {
                    UI.showSuccess(`${entityName} با موفقیت حذف شد.`);
                    await App.updateCache(['checks', 'accounts', 'customers', 'suppliers']);
                    load();
                    AccountManager.load();
                    if (type === 'check') {
                        CheckManager.load();
                    }
                }
            }
        });
        // *** END FIX ***
    }

    async function prepareTransactionModal(entity = null, defaults = {}) {
        const modal = $(config.modalId);
        UI.hideModalError(config.modalId);
        const form = $(config.formId)[0];
        form.reset();

        updatePaymentMethod('cash');

        form.dataset.checkId = '';
        form.dataset.invoiceId = '';
        form.dataset.invoiceType = '';

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

            if (entity.checkId) {
                form.dataset.checkId = entity.checkId;
            }
            if (entity.invoiceId) {
                form.dataset.invoiceId = entity.invoiceId;
                form.dataset.invoiceType = entity.invoiceType || '';
            }

            // Resolve check details for endorse edit (endorsed checks are not in the actionable cache)
            let selectedCheck = null;
            if (entity.type === 'endorse_check' && entity.checkId) {
                selectedCheck = (App.getCache().checks || []).find(c => String(c.id) === String(entity.checkId)) || null;
                if (!selectedCheck && entity.checkNumber) {
                    // Transaction list already joins check fields — use them without extra API call
                    selectedCheck = {
                        id: entity.checkId,
                        checkNumber: entity.checkNumber,
                        amount: entity.amount,
                        bankName: entity.bankName || '',
                        dueDate: entity.check_dueDate || entity.dueDate || null,
                        issuerName: entity.issuerName || ''
                    };
                }
                if (!selectedCheck) {
                    UI.showLoader();
                    const fetched = await Api.call('get_entity_by_id', { entityType: 'check', id: entity.checkId });
                    UI.hideLoader();
                    if (fetched && !fetched.error) {
                        selectedCheck = fetched;
                    }
                }
            }

            // Build payment-method UI with selected check included, then set values
            updatePaymentMethod(entity.type, {
                selectedCheckId: entity.checkId || null,
                selectedCheck: selectedCheck
            });
            $('#payment-method').val(entity.type);
            $('#transaction-amount-payment').val(Number(entity.amount).toLocaleString('en-US'));
            $('#transaction-description-payment').val(entity.description);

            if (entity.type === 'check' && entity.checkId) {
                setTimeout(() => {
                    const check = (App.getCache().checks || []).find(c => String(c.id) === String(entity.checkId)) || selectedCheck;
                    if (check) {
                        const detailsContainer = $('#payment-details-container');
                        detailsContainer.find('.check-number').val(check.checkNumber);
                        detailsContainer.find('.check-due-date').val(UI.gregorianToPersian(check.dueDate));
                        detailsContainer.find('.check-bank-name').val(check.bankName);
                        detailsContainer.find('.check-issuer-name').val(check.issuerName || '');
                    }
                }, 50);
            } else if (entity.type === 'endorse_check' && entity.checkId) {
                setTimeout(() => {
                    const select = $('#payment-details-container .endorsed-check-select');
                    if (select.length) {
                        select.val(String(entity.checkId)).trigger('change');
                    }
                }, 50);
            }
        } else {
            $('#transaction-date').val(UI.today());
            $('#person-type').val(defaults.person_type || 'customer').trigger('change');
            $('#transaction-flow').val(defaults.transaction_type || 'receipt');
            $('#payment-method').val(defaults.type || 'cash');
            updatePaymentMethod(defaults.type || 'cash');
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
            case 'contact': list = App.getCache().contacts; break;
        }
        list.forEach(p => personSelect.append(`<option value="${p.id}">${p.name}</option>`));
    }

    function formatCheckOptionLabel(c, isCurrent = false) {
        const issuer = (c.issuerName && String(c.issuerName).trim()) ? ` — ${c.issuerName}` : '';
        const statusNote = isCurrent ? ' (چک فعلی این تراکنش)' : '';
        return `${c.checkNumber} - ${UI.formatCurrency(c.amount)}${issuer}${statusNote}`;
    }

    function updatePaymentMethod(method, options = {}) {
        const detailsContainer = $('#payment-details-container');
        const amountInput = $('#transaction-amount-payment');
        const accountSelect = $('#transaction-account-id');
        const accountHelpText = $('#account-help-text');
        const selectedCheckId = options.selectedCheckId || null;
        const selectedCheck = options.selectedCheck || null;
        
        detailsContainer.empty().hide();
        
        // Remove previous help text
        accountHelpText.remove();

        if (method === 'check') {
            const dueDate = UI.today();
            detailsContainer.html(`<div class="row g-2 mt-1">
                <div class="col-3"><label class="form-label-sm">شماره چک</label><input type="text" class="form-control form-control-sm check-number" required></div>
                <div class="col-3"><label class="form-label-sm">تاریخ سررسید</label><input type="text" class="form-control form-control-sm persian-datepicker check-due-date" value="${dueDate}" required></div>
                <div class="col-3"><label class="form-label-sm">نام بانک</label><input type="text" class="form-control form-control-sm check-bank-name" required></div>
                <div class="col-3"><label class="form-label-sm">صادرکننده چک</label><input type="text" class="form-control form-control-sm check-issuer-name" placeholder="نام روی چک"></div>
            </div>`);
            amountInput.prop('readonly', false);
            
            // Disable account selection for new checks - also remove required
            accountSelect.val('').prop('disabled', true).prop('required', false);
            accountSelect.parent().append('<small id="account-help-text" class="form-text text-muted">حساب در زمان وصول/پاس چک مشخص می‌شود</small>');
            
            UI.initializeDatepickers();
            detailsContainer.show();
        } else if (method === 'endorse_check') {
            const availableChecks = (App.getCache().checks || []).filter(c => c.type === 'received' && c.status === 'in_hand');
            let optionsHtml = availableChecks.map(c =>
                `<option value="${c.id}" data-amount="${c.amount}">${UI.escapeHtml(formatCheckOptionLabel(c))}</option>`
            ).join('');

            // When editing an old endorse, the check is already endorsed and missing from cache — inject it
            const hasSelected = selectedCheckId && availableChecks.some(c => String(c.id) === String(selectedCheckId));
            if (selectedCheckId && !hasSelected) {
                const c = selectedCheck || { id: selectedCheckId, checkNumber: `#${selectedCheckId}`, amount: amountInput.val()?.replace(/,/g, '') || 0 };
                optionsHtml = `<option value="${c.id}" data-amount="${c.amount}" selected>${UI.escapeHtml(formatCheckOptionLabel(c, true))}</option>` + optionsHtml;
            }

            if (!optionsHtml) {
                detailsContainer.html('<p class="text-danger small mt-2">چک قابل واگذاری برای خرج کردن وجود ندارد.</p>');
            } else {
                detailsContainer.html(`<div><label class="form-label-sm">انتخاب چک جهت خرج کردن</label><select class="form-select form-select-sm endorsed-check-select" required><option value="">-- انتخاب کنید --</option>${optionsHtml}</select></div>`);
                const select = detailsContainer.find('.endorsed-check-select');
                select.on('change', function () {
                    const selectedAmount = $(this).find('option:selected').data('amount');
                    if (selectedAmount) {
                        amountInput.val(Number(selectedAmount).toLocaleString('en-US'));
                    }
                });
                if (selectedCheckId) {
                    select.val(String(selectedCheckId));
                }
            }
            amountInput.prop('readonly', true);
            
            // Disable account selection for endorsed checks - also remove required
            accountSelect.val('').prop('disabled', true).prop('required', false);
            accountSelect.parent().append('<small id="account-help-text" class="form-text text-muted">خرج چک به حساب نیاز ندارد - چک به شخص منتقل می‌شود</small>');
            
            detailsContainer.show();
        } else { // Cash
            amountInput.prop('readonly', false);
            
            // Enable account selection for cash transactions - also set required
            accountSelect.prop('disabled', false).prop('required', true);
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
        const personType = $('#person-type').val();
        let data = {
            id: $('#transaction-id').val() || null,
            person_id: $('#person-select').val(),
            person_type: personType,
            transaction_type: $('#transaction-flow').val(),
            type: paymentMethod,
            amount: $('#transaction-amount-payment').val().replace(/,/g, ''),
            date: UI.toGregorian('#transaction-date'),
            account_id: $('#transaction-account-id').val(),
            description: $('#transaction-description-payment').val(),
            checkId: form.dataset.checkId || null,
            invoiceId: form.dataset.invoiceId || null,
            invoiceType: form.dataset.invoiceType || null
        };

        if (!data.person_id) {
            UI.showModalError(config.modalId, 'انتخاب شخص الزامی است.');
            return;
        }

        if (paymentMethod === 'check') {
            data.checkDetails = {
                checkNumber: $('#payment-details-container .check-number').val(),
                dueDate: UI.toGregorian($('#payment-details-container .check-due-date')),
                bankName: $('#payment-details-container .check-bank-name').val(),
                issuerName: $('#payment-details-container .check-issuer-name').val()
            };
        } else if (paymentMethod === 'endorse_check') {
            data.checkId = $('#payment-details-container .endorsed-check-select').val();
        }

        UI.showLoader();
        const result = await Api.call('save_payment', data);
        UI.hideLoader();

        if (result?.success) {
            let msg = 'تراکنش با موفقیت ذخیره شد.';
            if (result.split) {
                msg = result.message || `بخشی به فاکتور و ${UI.formatCurrency(result.split.advance_amount)} به علی‌الحساب ثبت شد.`;
            } else if (result.allocated > 0.01) {
                msg += ` ${UI.formatCurrency(result.allocated)} به فاکتورهای باز تخصیص داده شد.`;
            } else if (result.overpay_converted > 0.01) {
                msg += ` مازاد ${UI.formatCurrency(result.overpay_converted)} به علی‌الحساب منتقل شد.`;
            }
            UI.showSuccess(msg);
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
        $('#add-income-btn-from-tx').on('click', () => App.getManager('incomes').prepareModal(null));

        $(config.formId).on('submit', handleFormSubmit);

        $('#person-type').on('change', function () { updatePersonSelect($(this).val()); });
        $('#payment-method').on('change', function () {
            const form = $(config.formId)[0];
            const selectedCheckId = form?.dataset?.checkId || null;
            updatePaymentMethod($(this).val(), selectedCheckId ? { selectedCheckId } : {});
        });

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

        $('#transaction-limit').on('change', function() {
            state.limit = parseInt($(this).val());
            state.currentPage = 1;
            load();
        });

        $('#transaction-filter-buttons').on('click', 'button', function () {
            const filterValue = $(this).data('filter');
            $('#transaction-filter-buttons button').removeClass('active');
            $(this).addClass('active');
            state.typeFilter = filterValue;
            state.currentPage = 1;
            load();
        });

        $('#transaction-account-filter').on('change', function () {
            state.accountFilter = $(this).val() || '';
            state.currentPage = 1;
            load();
        });

        const applyDateFilter = () => {
            const from = UI.toGregorian('#transaction-date-from') || '';
            const to = UI.toGregorian('#transaction-date-to') || '';
            if (from && to && from > to) {
                UI.showError('تاریخ شروع نمی‌تواند بعد از تاریخ پایان باشد.');
                return;
            }
            state.dateFrom = from;
            state.dateTo = to;
            state.currentPage = 1;
            load();
        };
        $('#transaction-date-from, #transaction-date-to').on('change', applyDateFilter);
        $('#transaction-date-clear').on('click', function () {
            if (!state.dateFrom && !state.dateTo && !$('#transaction-date-from').val() && !$('#transaction-date-to').val()) return;
            $('#transaction-date-from, #transaction-date-to').val('');
            applyDateFilter();
        });

        const pendingChecksBtn = $('#transaction-show-pending-checks');
        const syncPendingChecksBtn = () => {
            pendingChecksBtn.toggleClass('active btn-info', !!state.includeUncashedChecks);
            pendingChecksBtn.toggleClass('btn-outline-info', !state.includeUncashedChecks);
        };
        syncPendingChecksBtn();
        const TX_UNCASHED_KEY = (typeof AppConfig !== 'undefined' && AppConfig.STORAGE_KEYS && AppConfig.STORAGE_KEYS.TX_INCLUDE_UNCASHED) || 'hesabdari_tx_include_uncashed';
        pendingChecksBtn.on('click', function () {
            state.includeUncashedChecks = !state.includeUncashedChecks;
            try {
                localStorage.setItem(TX_UNCASHED_KEY, state.includeUncashedChecks ? '1' : '0');
            } catch (e) { /* ignore */ }
            syncPendingChecksBtn();
            state.currentPage = 1;
            load();
        });
        try {
            const saved = localStorage.getItem(TX_UNCASHED_KEY);
            if (saved !== null) {
                state.includeUncashedChecks = saved === '1';
                syncPendingChecksBtn();
            }
        } catch (e) { /* ignore */ }

        $('body').on('click', '#transactions-table .btn-delete', function () {
            const type = $(this).data('type');
            const id = $(this).data('id');
            handleDelete(type, id);
        });

        // *** START FIX: Remove handler for .btn-edit-transfer ***
        // (Event handler removed)
        // *** END FIX ***

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
                } else if (type === 'income') {
                    App.getManager('incomes').prepareModal(entityData);
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
                if (invoiceType === 'salesReturn' || invoiceType === 'purchaseReturn') {
                    // فاکتور مرجوعی
                    const returnType = invoiceType === 'salesReturn' ? 'sales_return' : 'purchase_return';
                    if (window.ReturnInvoiceManager) {
                        // Switch to return invoices tab
                        const returnInvoicesTab = document.querySelector('#return-invoices-tab');
                        if (returnInvoicesTab) {
                            const tab = new bootstrap.Tab(returnInvoicesTab);
                            tab.show();
                        }
                        // Wait a bit for tab to load, then open modal
                        setTimeout(() => {
                            ReturnInvoiceManager.openModal(returnType, entityData);
                        }, 300);
                    }
                } else {
                    // فاکتور عادی
                    const type = invoiceType === 'salesInvoice' ? 'sales' : 'purchase';
                    InvoiceManager.prepareInvoiceModal(type, entityData);
                }
            }
        });
    }

    return {
        init: attachEvents,
        load,
        prepareTransactionModal
    };
})();
