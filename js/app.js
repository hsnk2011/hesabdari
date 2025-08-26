// /js/app.js

const App = (function () {
    let appCache = { partners: [], accounts: [] }; // Cache is now smaller
    let managers = {};

    function getManager(name) {
        return managers[name];
    }

    function registerManager(name, manager) {
        managers[name] = manager;
    }

    function getCache() {
        return appCache;
    }

    async function fetchInitialCache() {
        UI.showLoader();
        // REMOVED calls for customers, suppliers, products, and checks to improve performance.
        const [partners, accounts] = await Promise.all([
            Api.call('get_partners'),
            Api.call('get_full_accounts_list')
        ]);
        appCache = {
            // customers, suppliers, products are no longer cached globally.
            partners: partners || [],
            accounts: accounts || [],
        };

        PartnerManager.refreshUI();
        // These functions are now re-written to use AJAX instead of cache.
        populatePersonSelectForReports();
        populateAccountSelectForReports();
        populateProductSelectForReports();
        UI.hideLoader();
    }

    function populatePersonSelectForReports() {
        const personSelect = $('#report-person-select');
        if (personSelect.data('select2')) { personSelect.select2('destroy'); }
        personSelect.empty().append('<option></option>'); // Clear previous options

        personSelect.select2({
            theme: 'bootstrap-5',
            dir: 'rtl',
            placeholder: "جستجو و انتخاب شخص...",
            ajax: {
                transport: async function (params, success, failure) {
                    // This custom transport uses our Api.call module
                    const entityMap = {
                        'مشتریان': 'customers',
                        'تامین‌کنندگان': 'suppliers',
                        'شرکا': 'partners' // A small list, but we keep it consistent
                    };
                    const entityType = entityMap[params.data.group] || null;

                    // For partners, just filter the local cache as it's small and already loaded
                    if (entityType === 'partners') {
                        const filteredPartners = App.getCache().partners
                            .filter(p => p.name.includes(params.data.term || ''))
                            .map(p => ({ id: `partner-${p.id}`, text: p.name }));
                        return success({ results: filteredPartners });
                    }
                    
                    // For customers and suppliers, make an API call
                    if (entityType) {
                        const data = await Api.call('search_entities', {
                            entityType: entityType,
                            term: params.data.term || ''
                        });
                        if (data && data.results) {
                            // Format the ID to include the type
                            const formattedResults = data.results.map(item => ({
                                ...item,
                                id: `${entityType.slice(0, -1)}-${item.id}`
                            }));
                            success({ results: formattedResults });
                        } else {
                            failure();
                        }
                    }
                },
                processResults: function (data) {
                    return {
                        results: data.results
                    };
                },
                cache: true
            },
            // Custom template to show optgroups in results
            templateResult: function (data) {
                if (!data.id) { return data.text; }
                const $container = $(
                    '<span>' + data.text + '</span>'
                );
                return $container;
            },
            // A custom query function to create optgroups for searching
            query: function (params) {
                const results = [];
                if (params.term && params.term !== '') {
                    results.push({ id: 'group_customers', text: 'جستجو در مشتریان', group: 'مشتریان', term: params.term });
                    results.push({ id: 'group_suppliers', text: 'جستجو در تامین‌کنندگان', group: 'تامین‌کنندگان', term: params.term });
                } else {
                    // Show partners by default if search is empty
                    const partners = App.getCache().partners.map(p => ({ id: `partner-${p.id}`, text: p.name }));
                    results.push({ text: 'شرکا', children: partners });
                }
                params.callback({ results: results });
            }
        });
    }


    function populateAccountSelectForReports() {
        // This remains the same as accounts are still cached (small dataset)
        const accountSelect = $('#report-account-select').empty().append('<option></option>');
        (appCache.accounts || []).forEach(acc => accountSelect.append(`<option value="${acc.id}">${acc.name}</option>`));
        if (accountSelect.data('select2')) { accountSelect.select2('destroy'); }
        accountSelect.select2({ theme: 'bootstrap-5', dir: 'rtl', placeholder: "جستجو و انتخاب حساب..." });
    }

    function populateProductSelectForReports() {
        const productSelect = $('#report-product-select');
        if (productSelect.data('select2')) { productSelect.select2('destroy'); }
        productSelect.empty().append('<option></option>'); // Clear previous options

        productSelect.select2({
            theme: 'bootstrap-5',
            dir: 'rtl',
            placeholder: "جستجو و انتخاب محصول...",
            ajax: {
                transport: async function (params, success, failure) {
                    const data = await Api.call('search_entities', { entityType: 'products', term: params.data.q || '' });
                    if (data && data.results) {
                        success(data);
                    } else {
                        failure();
                    }
                },
                processResults: function (data) {
                    return {
                        results: data.results
                    };
                },
                delay: 250, // Add a small delay to avoid excessive requests
                cache: true
            }
        });
    }

    function initEntityManagers() {
        managers['customers'] = createEntityManager({
            tableName: 'customers', apiName: 'customer', nameFa: 'مشتری',
            addBtnId: '#add-customer-btn', modalId: '#customerModal', formId: '#customer-form',
            renderTable: (data) => {
                const body = $('#customers-table-body').empty();
                if (!data || !data.length) return body.html('<tr><td colspan="5" class="text-center">مشتری یافت نشد.</td></tr>');
                data.forEach(c => {
                    const row = $(`<tr><td>${c.name}</td><td>${c.address || ''}</td><td>${c.phone || ''}</td><td>${c.nationalId || ''}</td><td><button class="btn btn-sm btn-warning btn-edit" data-type="customer"><i class="bi bi-pencil-square"></i></button> <button class="btn btn-sm btn-danger btn-delete" data-type="customer" data-id="${c.id}"><i class="bi bi-trash"></i></button></td></tr>`);
                    row.find('.btn-edit').data('entity', c);
                    body.append(row);
                });
            },
            prepareModal: (customer = null) => {
                $('#customer-form')[0].reset();
                $('#customer-id').val(customer ? customer.id : '');
                $('#customer-name').val(customer ? customer.name : '');
                $('#customer-address').val(customer ? customer.address : '');
                $('#customer-phone').val(customer ? customer.phone : '');
                $('#customer-national-id').val(customer ? customer.nationalId : '');
                $('#customer-initial-balance').val(customer ? Number(customer.initial_balance).toLocaleString('en-US') : '0');
                $('#customerModal').modal('show');
            },
            getFormData: () => ({
                id: $('#customer-id').val(),
                name: $('#customer-name').val(),
                address: $('#customer-address').val(),
                phone: $('#customer-phone').val(),
                nationalId: $('#customer-national-id').val(),
                initial_balance: $('#customer-initial-balance').val().replace(/,/g, '')
            }),
            refreshCache: false // No longer needs to refresh the main cache
        });

        managers['suppliers'] = createEntityManager({
            tableName: 'suppliers', apiName: 'supplier', nameFa: 'تأمین‌کننده',
            addBtnId: '#add-supplier-btn', modalId: '#supplierModal', formId: '#supplier-form',
            renderTable: (data) => {
                const body = $('#suppliers-table-body').empty();
                if (!data || !data.length) return body.html('<tr><td colspan="5" class="text-center">تأمین‌کننده‌ای یافت نشد.</td></tr>');
                data.forEach(s => {
                    const row = $(`<tr><td>${s.name}</td><td>${s.address || ''}</td><td>${s.phone || ''}</td><td>${s.economicCode || ''}</td><td><button class="btn btn-sm btn-warning btn-edit" data-type="supplier"><i class="bi bi-pencil-square"></i></button> <button class="btn btn-sm btn-danger btn-delete" data-type="supplier" data-id="${s.id}"><i class="bi bi-trash"></i></button></td></tr>`);
                    row.find('.btn-edit').data('entity', s);
                    body.append(row);
                });
            },
            prepareModal: (supplier = null) => {
                $('#supplier-form')[0].reset();
                $('#supplier-id').val(supplier ? supplier.id : '');
                $('#supplier-name').val(supplier ? supplier.name : '');
                $('#supplier-address').val(supplier ? supplier.address : '');
                $('#supplier-phone').val(supplier ? supplier.phone : '');
                $('#supplier-economic-code').val(supplier ? supplier.economicCode : '');
                $('#supplier-initial-balance').val(supplier ? Number(supplier.initial_balance).toLocaleString('en-US') : '0');
                $('#supplierModal').modal('show');
            },
            getFormData: () => ({
                id: $('#supplier-id').val(),
                name: $('#supplier-name').val(),
                address: $('#supplier-address').val(),
                phone: $('#supplier-phone').val(),
                economicCode: $('#supplier-economic-code').val(),
                initial_balance: $('#supplier-initial-balance').val().replace(/,/g, '')
            }),
            refreshCache: false // No longer needs to refresh the main cache
        });

        managers['products'] = createEntityManager({
            tableName: 'products', apiName: 'product', nameFa: 'محصول',
            addBtnId: '#add-product-btn', modalId: '#productModal', formId: '#product-form',
            renderTable: (data) => {
                const body = $('#products-table-body').empty();
                if (!data || !data.length) return body.html('<tr><td colspan="4" class="text-center">محصولی یافت نشد.</td></tr>');
                data.forEach(p => {
                    let stockHtml = '<ul class="list-unstyled mb-0">';
                    if (p.stock && p.stock.length > 0) p.stock.filter(s => s.quantity > 0).forEach(s => stockHtml += `<li><strong>${s.dimensions}:</strong> ${s.quantity} عدد</li>`);
                    else stockHtml += '<li>موجودی ثبت نشده</li>';
                    stockHtml += '</ul>';
                    const row = $(`<tr><td>${p.name}</td><td>${p.description || ''}</td><td>${stockHtml}</td><td><button class="btn btn-sm btn-warning btn-edit" data-type="product"><i class="bi bi-pencil-square"></i></button> <button class="btn btn-sm btn-danger btn-delete" data-type="product" data-id="${p.id}"><i class="bi bi-trash"></i></button></td></tr>`);
                    row.find('.btn-edit').data('entity', p);
                    body.append(row);
                });
            },
            prepareModal: (product = null) => {
                $('#product-form')[0].reset();
                $('#product-id').val('');
                $('#product-stock-container').empty();
                if (product) {
                    $('#product-id').val(product.id);
                    $('#product-name').val(product.name);
                    $('#product-description').val(product.description);
                    if (product.stock?.length) product.stock.forEach(s => managers['products'].addStockRow(s));
                    else managers['products'].addStockRow();
                } else managers['products'].addStockRow();
                $('#productModal').modal('show');
            },
            getFormData: () => {
                const stockData = [];
                $('#product-stock-container .dynamic-row').each(function () {
                    const finalDim = $(this).find('.standard-dims').val() || $(this).find('.custom-dims').val().trim();
                    if (finalDim) stockData.push({ dimensions: finalDim, quantity: parseInt($(this).find('.quantity').val()) || 0 });
                });
                return { id: $('#product-id').val(), name: $('#product-name').val(), description: $('#product-description').val(), stock: stockData };
            },
            addStockRow: (stock = null) => {
                const options = AppConfig.STANDARD_CARPET_DIMENSIONS.map(dim => `<option value="${dim}">${dim}</option>`).join('');
                const row = $(`<div class="row dynamic-row align-items-end mb-2"><div class="col-md-5"><label class="form-label">ابعاد استاندارد</label><select class="form-select standard-dims"><option value="">انتخاب یا سفارشی وارد کنید</option>${options}</select></div><div class="col-md-4"><label class="form-label">ابعاد سفارشی</label><input type="text" class="form-control custom-dims" placeholder="مثال: 2.1x3.2"></div><div class="col-md-2"><label class="form-label">تعداد</label><input type="number" class="form-control quantity" value="0" min="0"></div><div class="col-md-1"><button type="button" class="btn btn-danger btn-sm remove-stock-btn"><i class="bi bi-x-lg"></i></button></div></div>`);
                if (stock) {
                    if (AppConfig.STANDARD_CARPET_DIMENSIONS.includes(stock.dimensions)) {
                        row.find('.standard-dims').val(stock.dimensions);
                        row.find('.custom-dims').prop('disabled', true);
                    } else {
                        row.find('.custom-dims').val(stock.dimensions);
                    }
                    row.find('.quantity').val(stock.quantity);
                }
                $('#product-stock-container').append(row);
            },
            refreshCache: false // No longer needs to refresh the main cache
        });

        managers['expenses'] = createEntityManager({
            tableName: 'expenses', apiName: 'expense', nameFa: 'هزینه',
            addBtnId: '#add-expense-btn', modalId: '#expenseModal', formId: '#expense-form',
            renderTable: (data) => {
                const body = $('#expenses-table-body').empty();
                if (!data || !data.length) return body.html('<tr><td colspan="7" class="text-center">هزینه‌ای یافت نشد.</td></tr>');
                data.forEach(e => {
                    const row = $(`<tr>
                        <td>${e.id}</td>
                        <td>${e.date}</td>
                        <td>${e.category}</td>
                        <td>${UI.formatCurrency(e.amount)}</td>
                        <td>${e.accountName || '-'}</td>
                        <td>${e.description || ''}</td>
                        <td>
                            <button class="btn btn-sm btn-warning btn-edit" data-type="expense"><i class="bi bi-pencil-square"></i></button>
                            <button class="btn btn-sm btn-danger btn-delete" data-type="expense" data-id="${e.id}"><i class="bi bi-trash"></i></button>
                        </td>
                    </tr>`);
                    row.find('.btn-edit').data('entity', e);
                    body.append(row);
                });
            },
            prepareModal: (expense = null) => {
                $('#expense-form')[0].reset();
                const accountSelect = $('#expense-account-id').empty().append('<option value="">-- انتخاب کنید --</option>');
                App.getCache().accounts.forEach(acc => {
                    accountSelect.append(`<option value="${acc.id}">${acc.name}</option>`);
                });
                const categorySelect = $('#expense-category').empty().append('<option value="">-- انتخاب کنید --</option>');
                AppConfig.EXPENSE_CATEGORIES.forEach(cat => {
                    categorySelect.append(`<option value="${cat}">${cat}</option>`);
                });
                $('#expense-id').val(expense ? expense.id : '');
                $('#expense-date').val(expense ? expense.date : UI.today());
                $('#expense-category').val(expense ? expense.category : '');
                $('#expense-amount').val(expense ? Number(expense.amount).toLocaleString('en-US') : '0');
                $('#expense-account-id').val(expense ? expense.account_id : '');
                $('#expense-description').val(expense ? expense.description : '');
                $('#expenseModal').modal('show');
            },
            getFormData: () => ({
                id: $('#expense-id').val(),
                category: $('#expense-category').val(),
                date: $('#expense-date').val(),
                amount: $('#expense-amount').val().replace(/,/g, ''),
                account_id: $('#expense-account-id').val(),
                description: $('#expense-description').val()
            }),
            refreshTables: ['expenses', 'accounts']
        });

        managers['checks'] = createEntityManager({
            tableName: 'checks', apiName: 'check',
            renderTable: (data) => {
                const getCheckStatusText = (s, t) => {
                    if (!s) return '';
                    return t === 'received' ? { in_hand: 'نزد ما', endorsed: 'واگذار شده', cashed: 'وصول شده', bounced: 'برگشتی' }[s] || s : { payable: 'پرداختنی', cashed: 'پاس شده', bounced: 'برگشتی' }[s] || s;
                };
                const body = $('#checks-table-body').empty();
                if (!data || !data.length) return body.html('<tr><td colspan="8" class="text-center">چکی یافت نشد.</td></tr>');
                data.forEach(c => {
                    const typeText = c.type === 'received' ? 'دریافتی' : 'پرداختی';
                    const typeClass = c.type === 'received' ? 'text-success' : 'text-danger';
                    let related = '';
                    if (c.status === 'endorsed' && c.endorsedToInvoiceId) related = `واگذار به فاکتور خرید #${c.endorsedToInvoiceId}`;
                    else if (c.invoiceId) related = `فاکتور ${c.invoiceType === 'sales' ? 'فروش' : 'خرید'} #${c.invoiceId}`;

                    let actions = '';
                    if (c.type === 'received' && c.status === 'in_hand') {
                        actions = `<button class="btn btn-sm btn-success btn-cash-check" data-id="${c.id}" title="وصول چک"><i class="bi bi-check-circle-fill"></i></button>`;
                    }
                    else if (c.type === 'payable' && c.status === 'payable') {
                        actions = `<button class="btn btn-sm btn-primary btn-clear-check" data-id="${c.id}" title="ثبت پاس شدن چک"><i class="bi bi-check-all"></i></button>`;
                    }

                    body.append(`<tr>
                        <td class="${typeClass}">${typeText}</td>
                        <td>${c.checkNumber}</td>
                        <td>${c.bankName || '-'}</td>
                        <td>${UI.formatCurrency(c.amount)}</td>
                        <td>${c.dueDate || ''}</td>
                        <td>${getCheckStatusText(c.status, c.type)}</td>
                        <td>${related}</td>
                        <td>${actions}</td>
                    </tr>`);
                });
            }
        });

        managers['users'] = createEntityManager({
            tableName: 'users', apiName: 'user', nameFa: 'کاربر',
            renderTable: (data) => {
                const body = $('#users-table-body').empty();
                if (!data || !data.length) return;
                const currentUser = Auth.getCurrentUser();
                data.forEach(user => {
                    let actions = '';
                    if (user.username !== currentUser) {
                        actions = `<button class="btn btn-sm btn-warning btn-reset-password" data-id="${user.id}" data-username="${user.username}"><i class="bi bi-key"></i></button> 
                                   <button class="btn btn-sm btn-danger btn-delete-user" data-id="${user.id}" data-username="${user.username}"><i class="bi bi-trash"></i></button>`;
                    }
                    body.append(`<tr><td>${user.id}</td><td>${user.username}</td><td>${actions}</td></tr>`);
                });
            },
            handleDelete: (id, username) => {
                UI.confirmAction(`آیا از حذف کاربر «${username}» مطمئن هستید؟`, async (confirmed) => {
                    if (confirmed) {
                        const result = await Api.call('delete_user', { userId: id });
                        if (result?.success) {
                            managers['users'].load();
                        }
                    }
                });
            }
        });

        managers['activity_log'] = createEntityManager({
            tableName: 'activity_log',
            renderTable: (data) => {
                const body = $('#activity-log-table-body').empty();
                if (!data || !data.length) return body.html('<tr><td colspan="4" class="text-center">هیچ فعالیتی ثبت نشده است.</td></tr>');
                data.forEach(log => body.append(`<tr><td>${log.username}</td><td>${log.action_type}</td><td>${log.description}</td><td>${new Date(log.timestamp).toLocaleString('fa-IR')}</td></tr>`));
            }
        });

        for (const key in managers) {
            if (managers[key].init) managers[key].init();
        }

        registerManager('accounts', AccountManager);

        $('body').on('click', '#add-product-stock-row', () => managers['products'].addStockRow());
        $('body').on('click', '.remove-stock-btn', function () { $(this).closest('.dynamic-row').remove(); });
        $('body').on('change', '.standard-dims', function () {
            const customInput = $(this).closest('.row').find('.custom-dims');
            if ($(this).val()) customInput.val('').prop('disabled', true);
            else customInput.prop('disabled', false);
        });
        $('body').on('click', '.btn-delete-user', function (e) {
            e.preventDefault();
            managers['users'].handleDelete($(this).data('id'), $(this).data('username'));
        });
    }

    function attachMainEvents() {
        $('body').on('click', '.pagination-container .page-link', function (e) {
            e.preventDefault();
            const page = $(this).data('page');
            const tableName = $(this).data('table');
            if (page && tableName) {
                const manager = getManager(tableName);
                const state = AppConfig.TABLE_STATES[tableName];
                if (state) {
                    state.currentPage = page;
                    if (manager) manager.load();
                    else InvoiceManager.load(tableName);
                }
            }
        });

        $('body').on('click', '.sortable-header', function () {
            const newSortBy = $(this).data('sort-by');
            const tableName = $(this).closest('table').attr('id').replace(/-table$/, '').replace(/-/g, '_');
            const manager = getManager(tableName);
            const state = AppConfig.TABLE_STATES[tableName];

            if (state) {
                if (state.sortBy === newSortBy) {
                    state.sortOrder = state.sortOrder === 'ASC' ? 'DESC' : 'ASC';
                } else {
                    state.sortBy = newSortBy;
                    state.sortOrder = 'ASC';
                }
                state.currentPage = 1;

                if (manager) manager.load();
                else InvoiceManager.load(tableName);
            }
        });

        $('[data-bs-toggle="tab"]').on('shown.bs.tab', async function (e) {
            $('.modal.show').modal('hide');
            const target = $(e.target);
            const tableName = target.data('table-name');

            if (target.attr('id') === 'dashboard-tab') {
                await Dashboard.load();
            } else if (target.attr('id') === 'consignment-tab') {
                await InvoiceManager.load('consignment_sales');
                await InvoiceManager.load('consignment_purchases');
            } else if (target.attr('href') === '#partners') {
                await PartnerManager.load();
            } else if (tableName) {
                const manager = getManager(tableName);
                if (manager) {
                    await manager.load();
                } else {
                    await InvoiceManager.load(tableName);
                }
            }
        });

        $('#report-type').on('change', function () {
            const reportType = $(this).val();
            $('#report-person-controls').toggle(reportType === 'persons');
            $('#report-account-controls').toggle(reportType === 'accounts');
            $('#report-product-controls').toggle(reportType === 'inventory-ledger');
            $('#report-date-filter').toggle(!['inventory', 'inventory-value'].includes(reportType));
        }).trigger('change');

        $('#generate-report-btn').on('click', function () {
            const reportType = $('#report-type').val();
            const container = $('#report-results-container');
            ReportGenerator.generate(reportType, container);
        });

        $('body').on('hidden.bs.modal', '.modal', function () {
            if (document.activeElement && document.activeElement.blur) {
                document.activeElement.blur();
            }
        });

        $('body').on('click', '.report-link', async function (e) {
            e.preventDefault();
            const id = $(this).data('id');
            const type = $(this).data('type');

            if (!id || !type) return;

            UI.showLoader();
            const entityData = await Api.call('get_entity_by_id', { entityType: type, id: id });
            UI.hideLoader();

            if (entityData) {
                if (type === 'salesInvoice') {
                    InvoiceManager.prepareInvoiceModal('sales', entityData);
                } else if (type === 'purchaseInvoice') {
                    InvoiceManager.prepareInvoiceModal('purchase', entityData);
                } else if (type === 'expense') {
                    App.getManager('expenses').prepareModal(entityData);
                }
            }
        });

        $('body').on('click', '.btn-clear-check', function () {
            const checkId = $(this).data('id');
            const modal = $('#cashCheckModal');
            modal.find('.modal-title').text('ثبت پاس شدن چک');
            modal.find('p').text('چک از کدام حساب برداشت (پاس) شد؟');
            $('#cash-check-id').val(checkId);
            $('#cash-check-form').data('action', 'clear_payable_check');

            const accountSelect = $('#cash-check-account-id').empty().append('<option value="">-- انتخاب کنید --</option>');
            App.getCache().accounts.forEach(acc => {
                accountSelect.append(`<option value="${acc.id}">${acc.name}</option>`);
            });

            modal.modal('show');
        });

        $('body').on('click', '.btn-cash-check', function () {
            const checkId = $(this).data('id');
            const modal = $('#cashCheckModal');
            modal.find('.modal-title').text('وصول چک');
            modal.find('p').text('چک به کدام حساب واریز شود؟');
            $('#cash-check-id').val(checkId);
            $('#cash-check-form').data('action', 'cash_check');

            const accountSelect = $('#cash-check-account-id').empty().append('<option value="">-- انتخاب کنید --</option>');
            App.getCache().accounts.forEach(acc => {
                accountSelect.append(`<option value="${acc.id}">${acc.name}</option>`);
            });

            modal.modal('show');
        });

        $('#cash-check-form').on('submit', async function (e) {
            e.preventDefault();
            const action = $(this).data('action');
            const data = {
                checkId: $('#cash-check-id').val(),
                accountId: $('#cash-check-account-id').val()
            };

            UI.showLoader();
            const result = await Api.call(action, data);
            UI.hideLoader();

            if (result?.success) {
                $('#cashCheckModal').modal('hide');
                App.getManager('checks').load();
                App.getManager('accounts').load();
            }
        });
    }

    function initMainApp(username) {
        UI.initializeDatepickers();
        UI.initNumericFormatting();

        $('#report-start-date').val(UI.firstDayOfMonth());
        $('#report-end-date').val(UI.today());

        initEntityManagers();
        PartnerManager.init();
        InvoiceManager.init();
        AccountManager.init();

        ReportGenerator.init();

        $('#add-sales-invoice-btn').on('click', () => InvoiceManager.prepareInvoiceModal('sales'));
        $('#add-purchase-invoice-btn').on('click', () => InvoiceManager.prepareInvoiceModal('purchase'));

        fetchInitialCache().then(() => {
            Dashboard.load();
        });
    }

    function init() {
        $(document).ready(function () {
            attachMainEvents();
            Auth.init();
        });
    }

    return {
        init,
        initMainApp,
        fetchInitialCache,
        getManager,
        registerManager,
        getCache,
    };

})();

App.init();