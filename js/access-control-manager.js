// /js/access-control-manager.js

const AccessControlManager = (function () {
    let rolesCache = [];
    let permissionsCache = [];
    let usersCache = [];
    let entitiesCache = [];

    function renderRoles() {
        const container = $('#roles-list-container').empty();
        if (!rolesCache.length) {
            container.html('<li class="list-group-item">نقشی یافت نشد.</li>');
            return;
        }
        rolesCache.forEach(role => {
            // System roles are flagged server-side (roles.is_system); the id===1
            // fallback only covers databases not yet upgraded.
            const isSystem = String(role.is_system) === '1' || (role.is_system === undefined && Number(role.id) === 1);
            const item = $(`<li class="list-group-item d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="mb-0">${UI.escapeHtml(role.role_name)}</h6>
                    <small class="text-muted">${UI.escapeHtml(role.description || '')}</small>
                </div>
                <div>
                    <button class="btn btn-sm btn-outline-warning btn-edit-role" data-id="${role.id}"><i class="bi bi-pencil"></i></button>
                    ${!isSystem ? `<button class="btn btn-sm btn-outline-danger btn-delete-role" data-id="${role.id}"><i class="bi bi-trash"></i></button>` : ''}
                </div>
            </li>`);
            container.append(item);
        });
    }

    function prepareRoleModal(role = null) {
        UI.hideModalError('#roleModal');
        $('#role-form')[0].reset();
        $('#role-id').val(role ? role.id : '');
        $('#role-name').val(role ? role.role_name : '');
        $('#role-description').val(role ? role.description : '');

        const permissionsContainer = $('#role-permissions-container').empty();
        const isSystem = !!role && (String(role.is_system) === '1' || (role.is_system === undefined && Number(role.id) === 1));
        if (isSystem) {
            permissionsContainer.append('<div class="col-12"><div class="alert alert-info py-2 small mb-2">نقش «مدیر کل» همیشه همه دسترسی‌ها را دارد و قابل کم کردن نیست؛ دسترسی‌های جدید هم خودکار به آن داده می‌شود.</div></div>');
        }
        // Grouped by area, with a short note on what each permission also opens up.
        const groups = [
            ['عمومی', ['view_dashboard', 'view_reports']],
            ['فروش', ['manage_quotations', 'manage_sales_invoices', 'manage_return_invoices', 'manage_consignment', 'manage_installments']],
            ['خرید و انبار', ['manage_purchase_invoices', 'manage_products', 'manage_collections', 'manage_stocktaking', 'view_price_list']],
            ['مالی', ['manage_transactions', 'manage_checks', 'manage_accounts', 'manage_bank_imports', 'manage_assets']],
            ['اشخاص', ['manage_customers', 'manage_suppliers', 'manage_contacts']],
            ['میز کار', ['use_crm', 'manage_crm_team', 'use_projects', 'manage_projects', 'use_messenger', 'use_cartable', 'approve_payments', 'pay_payments', 'manage_hr', 'use_ideas']],
            ['مدیریت', ['manage_settings', 'import_export_data']],
        ];
        const notes = {
            view_dashboard: 'داشبورد، اعلان‌ها و یادآوری‌ها',
            view_reports: 'همه گزارش‌ها، سود و زیان و آنالیز فروش',
            manage_sales_invoices: 'شامل دیدن فهرست مشتریان، کالاها و حساب‌ها برای ثبت فاکتور',
            manage_purchase_invoices: 'شامل دیدن فهرست تأمین‌کنندگان، کالاها، حساب‌ها و چک‌ها',
            manage_installments: 'شامل دیدن فهرست فاکتورها برای تعریف اقساط',
            manage_transactions: 'دریافت و پرداخت، هزینه و درآمد، هزینه تکراری و بودجه',
            manage_checks: 'شامل ثبت چک جدید، وصول، برگشت و خرج چک',
            manage_assets: 'ارز، طلا، سکه، اثاثیه و تجهیزات',
            manage_customers: 'شامل گروه مشتری و فروشندگان',
            use_crm: 'پرونده مشتری، ثبت تماس و قول پرداخت، کارها، بدهکارها و سرنخ‌ها',
            manage_crm_team: 'تعریف اعضای تیم (شرکا و کارکنان)',
            use_projects: 'پروژه‌هایی که عضو آن است، کارها و جلسات',
            manage_projects: 'ساختن پروژه و دیدن و مدیریت همه پروژه‌ها و جلسات؛ تصمیم درباره ایده‌ها',
            use_messenger: 'گفتگوی خصوصی و گروهی با همکاران',
            use_cartable: 'ثبت درخواست پرداخت و نامه و پیگیری آن‌ها',
            approve_payments: 'تأیید یا رد درخواست‌های پرداخت',
            pay_payments: 'ثبت پرداخت درخواست تأییدشده (هزینه در حسابداری ثبت می‌شود)',
            manage_hr: 'پرونده و سوابق پرسنل',
            use_ideas: 'ثبت ایده، نظر و رأی',
            manage_settings: 'تنظیمات، کاربران، نقش‌ها، پشتیبان، شرکا، بستن حساب و حسابرس هوشمند',
        };
        const byKey = {};
        permissionsCache.forEach(p => { byKey[p.permission_key] = p; });
        const used = new Set();
        const renderPerm = (perm) => {
            used.add(perm.permission_key);
            const isChecked = isSystem || (role ? role.permissions.includes(perm.id) : false);
            const note = notes[perm.permission_key] ? `<div class="form-text mt-0">${UI.escapeHtml(notes[perm.permission_key])}</div>` : '';
            return `<div class="col-md-6 col-lg-4 form-check form-switch mb-1">
                <input class="form-check-input" type="checkbox" value="${perm.id}" id="perm-${perm.id}" ${isChecked ? 'checked' : ''} ${isSystem ? 'disabled' : ''}>
                <label class="form-check-label" for="perm-${perm.id}">${UI.escapeHtml(perm.description)}</label>${note}
            </div>`;
        };
        groups.forEach(([title, keys]) => {
            const items = keys.map(k => byKey[k]).filter(Boolean);
            if (!items.length) return;
            permissionsContainer.append(`<div class="col-12 mt-2"><h6 class="small fw-bold text-muted border-bottom pb-1">${title}</h6></div>`);
            items.forEach(p => permissionsContainer.append(renderPerm(p)));
        });
        // Anything not in a group (permissions added later) is still shown.
        const rest = permissionsCache.filter(p => !used.has(p.permission_key));
        if (rest.length) {
            permissionsContainer.append('<div class="col-12 mt-2"><h6 class="small fw-bold text-muted border-bottom pb-1">سایر</h6></div>');
            rest.forEach(p => permissionsContainer.append(renderPerm(p)));
        }
        $('#roleModal').modal('show');
    }

    async function handleRoleFormSubmit(e) {
        e.preventDefault();
        const data = {
            id: $('#role-id').val(),
            role_name: $('#role-name').val(),
            description: $('#role-description').val(),
            permissions: $('#role-permissions-container input:checked').map((_, el) => parseInt($(el).val())).get()
        };

        const result = await Api.call('save_role', data);
        if (result?.success) {
            UI.showSuccess('نقش با موفقیت ذخیره شد.');
            $('#roleModal').modal('hide');
            load();
        }
    }

    async function handleRoleDelete(id) {
        UI.confirmAction('آیا از حذف این نقش مطمئن هستید؟ دسترسی تمام کاربران مرتبط با این نقش تغییر خواهد کرد.', async (confirmed) => {
            if (confirmed) {
                const result = await Api.call('delete_role', { id });
                if (result?.success) {
                    UI.showSuccess('نقش با موفقیت حذف شد.');
                    load();
                }
            }
        });
    }

    function renderUserAccess() {
        const container = $('#user-access-list-container').empty();
        if (!usersCache.length) return;

        usersCache.forEach(user => {
            const item = $(`<div class="card mb-2">
                <div class="card-header d-flex justify-content-between">
                    <strong>${UI.escapeHtml(user.username)}</strong> <small class="text-muted">(شناسه ${parseInt(user.id, 10)})</small>
                    <button class="btn btn-sm btn-primary btn-save-user-access" data-id="${user.id}">ذخیره دسترسی</button>
                </div>
                <div class="card-body row" data-user-id="${user.id}">
                    <div class="col-md-5">
                        <h6>نقش‌ها</h6>
                        <div class="roles-container"></div>
                    </div>
                    <div class="col-md-7">
                        <h6>مجموعه‌های مجاز</h6>
                        <div class="entities-container"></div>
                    </div>
                </div>
            </div>`);

            const rolesContainer = item.find('.roles-container');
            rolesCache.forEach(role => {
                const isChecked = (user.roles || []).map(Number).includes(Number(role.id));
                rolesContainer.append(`<div class="form-check">
                    <input class="form-check-input" type="checkbox" value="${role.id}" id="user-${user.id}-role-${role.id}" ${isChecked ? 'checked' : ''}>
                    <label class="form-check-label" for="user-${user.id}-role-${role.id}">${UI.escapeHtml(role.role_name)}</label>
                </div>`);
            });

            const entitiesContainer = item.find('.entities-container');
            // Only entities the current admin can access are assignable; the server keeps the rest unchanged.
            const myEntities = ((App.getAppState && App.getAppState().allowedEntities) || []).map(Number);
            entitiesCache.forEach(entity => {
                // Entity ids may arrive as strings (plain query) while user access ids are ints.
                const isChecked = (user.entities || []).map(Number).includes(Number(entity.id));
                const outOfScope = myEntities.length > 0 && !myEntities.includes(Number(entity.id));
                entitiesContainer.append(`<div class="form-check">
                    <input class="form-check-input" type="checkbox" value="${entity.id}" id="user-${user.id}-entity-${entity.id}" ${isChecked ? 'checked' : ''} ${outOfScope ? 'disabled' : ''}>
                    <label class="form-check-label" for="user-${user.id}-entity-${entity.id}">${UI.escapeHtml(entity.name)}</label>
                </div>`);
            });

            container.append(item);
        });
    }

    async function handleUpdateUserAccess(userId) {
        const container = $(`[data-user-id="${userId}"]`);
        const data = {
            userId: userId,
            roles: container.find('.roles-container input:checked').map((_, el) => parseInt($(el).val())).get(),
            entities: container.find('.entities-container input:checked').map((_, el) => parseInt($(el).val())).get()
        };

        const result = await Api.call('update_user_access', data);
        if (result?.success) {
            UI.showSuccess('دسترسی‌های کاربر با موفقیت بروزرسانی شد.');
            load();
            const currentSession = await Auth.checkSession();
            if (currentSession && currentSession.loggedIn) {
                App.updateAccess(currentSession);
            }
        }
    }


    function attachEvents() {
        $('#add-role-btn').on('click', () => prepareRoleModal());
        $('#role-form').on('submit', handleRoleFormSubmit);
        $('body').on('click', '.btn-edit-role', function () {
            const roleId = $(this).data('id');
            const role = rolesCache.find(r => r.id === roleId);
            prepareRoleModal(role);
        });
        $('body').on('click', '.btn-delete-role', function () {
            handleRoleDelete($(this).data('id'));
        });

        // **FIX START**: Change logic from opening a modal to switching tabs
        $('body').on('click', '.btn-edit-access', function () {
            const accessControlTabButton = document.querySelector('#settings-sub-tab button[data-bs-target="#access-control-pane"]');
            if (accessControlTabButton) {
                const tab = new bootstrap.Tab(accessControlTabButton);
                tab.show();
            }
        });
        // **FIX END**

        $('body').on('click', '.btn-save-user-access', function () {
            handleUpdateUserAccess($(this).data('id'));
        });
    }

    async function load() {
        const data = await Api.call('get_users_with_roles');
        if (data) {
            usersCache = data.users || [];
            rolesCache = data.roles || [];
            entitiesCache = data.entities || [];
            renderUserAccess();
        }

        const rolesData = await Api.call('get_roles_and_permissions');
        if (rolesData) {
            rolesCache = rolesData.roles || [];
            permissionsCache = rolesData.permissions || [];
            renderRoles();
        }
    }

    return {
        init: attachEvents,
        load,
    };
})();