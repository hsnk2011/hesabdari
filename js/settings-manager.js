// /js/settings-manager.js

const SettingsManager = (function () {
    const config = {
        tableName: 'settings',
        apiName: 'app_settings',
        formId: '#settings-form',
    };
    let appSettings = {};
    let businessEntities = [];
    let entityPrintSettings = {};

    function renderForm() {
        $('#setting-app-title').val(appSettings.app_title || 'سیستم حسابداری');
        $('#setting-standard-dimensions').val(appSettings.standard_dimensions || 'پادری,1x1.5,1.5x2,1x2,1x3,1x4,1.5x2.25,1.7x2.4,2x3,2.5x3.5,3x4');
        $('#setting-expense-categories').val(appSettings.expense_categories || 'اجاره,حقوق و دستمزد,قبوض,حمل و نقل,ملزومات,تبلیغات,متفرقه');
        $('#setting-income-categories').val(appSettings.income_categories || 'سود سپرده بانکی,برگشت کارمزد,اجاره دریافتی,سایر درآمدها,متفرقه');
        $('#setting-default-tax-rate').val(appSettings.default_tax_rate || 0);
        $('#setting-currency-label').val(appSettings.currency_label || 'تومان');
        $('#setting-currency-rial-divisor').val(appSettings.currency_rial_divisor || '10');
        $('#setting-dimension-cm-threshold').val(appSettings.dimension_cm_threshold || '15');
        $('#setting-dimension-area-map').val(appSettings.dimension_area_map || '{"پادری":0.4}');
        $('#setting-ar-aging-buckets').val(appSettings.ar_aging_buckets || '30,60,90');
        $('#setting-check-discount-days').val(appSettings.check_discount_days_per_month || '30');
        $('#setting-share-link-ttl').val(appSettings.share_link_ttl_hours || '168');
        $('#setting-support-url').val(appSettings.support_url || '');
        $('#setting-bank-import-common-fees').val(appSettings.bank_import_common_fees || '7000,12000,15000,18000');
        $('#setting-bank-import-fee-cap').val(appSettings.bank_import_fee_cap_ratio || '0.02');
        $('#setting-invoice-price-history-enabled').prop('checked', String(appSettings.invoice_price_history_enabled ?? '1') !== '0');
        $('#setting-invoice-price-history-similar').prop('checked', String(appSettings.invoice_price_history_similar ?? '1') !== '0');
        $('#setting-invoice-price-history-person').prop('checked', String(appSettings.invoice_price_history_person_prefer ?? '1') !== '0');
        $('#setting-invoice-price-history-limit').val(appSettings.invoice_price_history_limit || '3');
        $('#setting-negative-stock-policy').val(appSettings.negative_stock_policy || 'allow');
        $('#setting-credit-limit-policy').val(appSettings.credit_limit_policy || 'warn');
        $('#setting-cron-mode').val(appSettings.cron_mode || 'request');
        $('#setting-workspace-enabled').prop('checked', String(appSettings.workspace_enabled ?? '1') !== '0');
        $('#setting-reminder-sms-enabled').prop('checked', String(appSettings.reminder_sms_enabled || '0') === '1');
        $('#setting-reminder-installment-days').val(appSettings.reminder_installment_days ?? '2');
        $('#setting-reminder-overdue-every').val(appSettings.reminder_overdue_every_days ?? '7');
        $('#setting-sms-verify-template-reminder').val(appSettings.sms_verify_template_reminder || '');
        const pwaColor = (appSettings.pwa_theme_color || '').trim();
        $('#setting-pwa-theme-color').val(/^#[0-9a-fA-F]{6}$/.test(pwaColor) ? pwaColor : '#4f46e5');

        // Populate cash accounts for the default-cash-account select.
        const cashSelect = $('#setting-default-cash-account');
        cashSelect.find('option:not(:first)').remove();
        const accounts = (App.getCache().accounts || []).filter(a => a.is_cash == 1 || a.is_cash === true);
        accounts.forEach(a => {
            cashSelect.append($('<option>', { value: a.id, text: a.name }));
        });
        cashSelect.val(appSettings.default_cash_account_id || '');

        const entitiesContainer = $('#business-entities-container').empty();
        if (businessEntities && businessEntities.length > 0) {
            businessEntities.forEach(entity => {
                const field = $(`<div class="mb-2 row">
                    <label for="entity-name-${entity.id}" class="col-sm-3 col-form-label">نام مجموعه #${entity.id}</label>
                    <div class="col-sm-9">
                        <div class="input-group">
                            <input type="text" class="form-control" id="entity-name-${entity.id}" data-entity-id="${entity.id}" maxlength="255">
                            <button type="button" class="btn btn-outline-danger delete-entity-btn" data-entity-id="${entity.id}" title="حذف مجموعه">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>`);
                field.find('input').val(entity.name);
                entitiesContainer.append(field);
            });
        }
    }

    function syncEntitiesToApp(result) {
        businessEntities = result.entities || businessEntities;
        App.setBusinessEntities(businessEntities, result.allowed_entities);
        renderForm();
    }

    async function handleAddEntity() {
        const input = $('#new-entity-name');
        const name = (input.val() || '').trim();
        if (!name) {
            UI.showError('نام مجموعه را وارد کنید.');
            return;
        }
        UI.showLoader();
        const result = await Api.call('create_entity', { name });
        UI.hideLoader();
        if (result?.success) {
            input.val('');
            syncEntitiesToApp(result);
            UI.showSuccess(`مجموعه «${name}» ایجاد شد.`);
        }
    }

    async function handleDeleteEntity(entityId) {
        const entity = businessEntities.find(e => Number(e.id) === Number(entityId));
        if (!entity) return;
        const ok = await UI.confirm(`مجموعه «${UI.escapeHtml(entity.name)}» حذف شود؟ این عملیات غیرقابل بازگشت است.`, 'حذف مجموعه');
        if (!ok) return;
        UI.showLoader();
        const result = await Api.call('delete_entity', { id: entityId });
        UI.hideLoader();
        if (result?.success) {
            syncEntitiesToApp(result);
            UI.showSuccess('مجموعه حذف شد.');
        }
    }

    async function load() {
        UI.showLoader();
        try {
            const response = await Api.call('get_app_settings');
            if (response) {
                appSettings = response.settings || {};
                businessEntities = response.entities || [];
                renderForm();

                // Also load backup settings state
                // --- FIX START: Changed strict comparison '===' to loose '==' to handle both string and number values ---
                $('#auto-backup-enabled').prop('checked', appSettings.auto_backup_enabled == '1');
                // --- FIX END ---
                $('#auto-backup-include-images').prop('checked', appSettings.auto_backup_include_images == '1');
                $('#auto-backup-retention-days').val(appSettings.auto_backup_retention_days || 7);
                $('#auto-backup-secret').val(appSettings.auto_backup_secret || '');

                // Email / SMTP settings
                $('#smtp-enabled').prop('checked', appSettings.smtp_enabled == '1');
                $('#smtp-host').val(appSettings.smtp_host || '');
                $('#smtp-port').val(appSettings.smtp_port || 587);
                $('#smtp-encryption').val(appSettings.smtp_encryption || 'tls');
                $('#smtp-username').val(appSettings.smtp_username || '');
                $('#smtp-password').val(appSettings.smtp_password || '');
                $('#mail-from-address').val(appSettings.mail_from_address || '');
                $('#mail-from-name').val(appSettings.mail_from_name || '');
                $('#backup-email-to').val(appSettings.backup_email_to || '');

                // Remote backup settings
                $('#remote-backup-enabled').prop('checked', appSettings.remote_backup_enabled == '1');
                $('#remote-backup-url').val(appSettings.remote_backup_url || '');
                $('#remote-backup-secret').val(appSettings.remote_backup_secret || '');
                $('#remote-backup-receive-secret').val(appSettings.remote_backup_receive_secret || '');
                $('#remote-backup-retention-days').val(appSettings.remote_backup_retention_days || 30);
                $('#backup-encryption-password').val(appSettings.backup_encryption_password || '');
                
                // Load entity print settings after app settings
                await loadEntityPrintSettings();
            }
        } finally {
            UI.hideLoader();
        }
    }

    async function handleGeneralFormSubmit(e) {
        e.preventDefault();
        UI.hideModalError(config.formId);

        // CORRECTED: This function now ONLY saves general settings.
        const settingsData = {
            app_title: $('#setting-app-title').val(),
            standard_dimensions: $('#setting-standard-dimensions').val(),
            expense_categories: $('#setting-expense-categories').val(),
            income_categories: $('#setting-income-categories').val(),
            default_tax_rate: $('#setting-default-tax-rate').val() || '0',
            currency_label: ($('#setting-currency-label').val() || '').trim() || 'تومان',
            currency_rial_divisor: ($('#setting-currency-rial-divisor').val() || '').trim() || '10',
            dimension_cm_threshold: ($('#setting-dimension-cm-threshold').val() || '').trim() || '15',
            dimension_area_map: ($('#setting-dimension-area-map').val() || '').trim() || '{"پادری":0.4}',
            ar_aging_buckets: ($('#setting-ar-aging-buckets').val() || '').trim() || '30,60,90',
            check_discount_days_per_month: ($('#setting-check-discount-days').val() || '').trim() || '30',
            share_link_ttl_hours: ($('#setting-share-link-ttl').val() || '').trim() || '168',
            default_cash_account_id: $('#setting-default-cash-account').val() || '',
            support_url: ($('#setting-support-url').val() || '').trim(),
            bank_import_common_fees: ($('#setting-bank-import-common-fees').val() || '').trim(),
            bank_import_fee_cap_ratio: ($('#setting-bank-import-fee-cap').val() || '').trim() || '0.02',
            pwa_theme_color: ($('#setting-pwa-theme-color').val() || '').trim(),
            invoice_price_history_enabled: $('#setting-invoice-price-history-enabled').is(':checked') ? '1' : '0',
            invoice_price_history_similar: $('#setting-invoice-price-history-similar').is(':checked') ? '1' : '0',
            invoice_price_history_person_prefer: $('#setting-invoice-price-history-person').is(':checked') ? '1' : '0',
            invoice_price_history_limit: String(Math.max(1, Math.min(5, parseInt($('#setting-invoice-price-history-limit').val(), 10) || 3))),
            negative_stock_policy: $('#setting-negative-stock-policy').val() || 'allow',
            credit_limit_policy: $('#setting-credit-limit-policy').val() || 'warn',
            cron_mode: $('#setting-cron-mode').val() || 'request',
            workspace_enabled: $('#setting-workspace-enabled').is(':checked') ? '1' : '0',
            reminder_sms_enabled: $('#setting-reminder-sms-enabled').is(':checked') ? '1' : '0',
            reminder_installment_days: String(Math.max(0, Math.min(30, parseInt($('#setting-reminder-installment-days').val(), 10) || 0))),
            reminder_overdue_every_days: String(Math.max(0, Math.min(90, parseInt($('#setting-reminder-overdue-every').val(), 10) || 0))),
            sms_verify_template_reminder: ($('#setting-sms-verify-template-reminder').val() || '').trim()
        };

        const entitiesData = [];
        $('#business-entities-container input').each(function () {
            entitiesData.push({
                id: $(this).data('entity-id'),
                name: $(this).val()
            });
        });

        const dataToSend = {
            settings: settingsData,
            entities: entitiesData
        };

        UI.showLoader();
        const result = await Api.call('save_app_settings', dataToSend);
        UI.hideLoader();

        if (result?.success) {
            UI.showSuccess('تنظیمات با موفقیت ذخیره شد.');
            // Turning «میز کار» on or off changes the tabs and permissions: reload to show it.
            if (settingsData.workspace_enabled !== (String(appSettings.workspace_enabled ?? '1') !== '0' ? '1' : '0')) {
                setTimeout(() => window.location.reload(), 800);
                return;
            }
            // Keep the header entity switcher in sync with renamed entities.
            businessEntities = businessEntities.map(entity => {
                const edited = entitiesData.find(e => Number(e.id) === Number(entity.id));
                return edited ? { ...entity, name: edited.name } : entity;
            });
            App.setBusinessEntities(businessEntities);
            await App.updateCache(['settings']);
        } else if (result?.error) {
            const errorDiv = $(`${config.formId} .form-error`);
            if (errorDiv.length) {
                errorDiv.text(result.error).slideDown();
            }
        }
    }

    const handleAutoBackupSettingsChange = UI.debounce(async function () {
        const settingsData = {
            auto_backup_enabled: $('#auto-backup-enabled').is(':checked') ? '1' : '0',
            auto_backup_include_images: $('#auto-backup-include-images').is(':checked') ? '1' : '0',
            auto_backup_retention_days: $('#auto-backup-retention-days').val(),
            auto_backup_secret: $('#auto-backup-secret').val(),

            smtp_enabled: $('#smtp-enabled').is(':checked') ? '1' : '0',
            smtp_host: $('#smtp-host').val(),
            smtp_port: $('#smtp-port').val(),
            smtp_encryption: $('#smtp-encryption').val(),
            smtp_username: $('#smtp-username').val(),
            smtp_password: $('#smtp-password').val(),
            mail_from_address: $('#mail-from-address').val(),
            mail_from_name: $('#mail-from-name').val(),
            backup_email_to: $('#backup-email-to').val(),

            remote_backup_enabled: $('#remote-backup-enabled').is(':checked') ? '1' : '0',
            remote_backup_url: $('#remote-backup-url').val(),
            remote_backup_secret: $('#remote-backup-secret').val(),
            remote_backup_receive_secret: $('#remote-backup-receive-secret').val(),
            remote_backup_retention_days: $('#remote-backup-retention-days').val(),
            backup_encryption_password: $('#backup-encryption-password').val()
        };

        const result = await Api.call('save_app_settings', { settings: settingsData });

        if (result?.success) {
            UI.showSuccess('تنظیمات پشتیبان‌گیری خودکار ذخیره شد.', 'ذخیره شد', { timeOut: 2000 });
            await App.updateCache(['settings']);
        } else {
            UI.showError('خطا در ذخیره تنظیمات پشتیبان‌گیری خودکار.');
        }
    }, 1000); // Debounce to avoid too many API calls

    async function testRemoteBackupConnection() {
        UI.showLoader();
        const result = await Api.call('test_remote_backup');
        UI.hideLoader();
        if (result?.success) {
            UI.showSuccess(result.message || 'اتصال با سرور مقصد برقرار شد.');
        } else {
            UI.showError(result?.error || 'تست اتصال ناموفق بود.');
        }
    }

    async function testEmailSettingsConnection() {
        const to = ($('#test-email-to').val() || '').trim();
        UI.showLoader();
        const result = await Api.call('test_email_settings', to ? { to } : {});
        UI.hideLoader();
        if (result?.success) {
            UI.showSuccess(result.message || 'ایمیل آزمایشی ارسال شد.');
        } else {
            UI.showError(result?.error || 'تست ایمیل ناموفق بود.');
        }
    }

    function loadPrintSettings() {
        const global = appSettings;
        // Use entity settings (override global) if present
        const settings = { ...global, ...entityPrintSettings };
        
        $('#print-company-name').val(settings.print_company_name || '');
        $('#print-company-phone').val(settings.print_company_phone || '');
        $('#print-company-address').val(settings.print_company_address || '');
        $('#print-logo-url').val(settings.print_logo_url || '');
        $('#print-bank-name').val(settings.print_bank_name || '');
        $('#print-account-number').val(settings.print_account_number || '');
        $('#print-card-number').val(settings.print_card_number || '');
        $('#print-sheba-number').val(settings.print_sheba_number || '');
        $('#print-account-holder').val(settings.print_account_holder || '');
        
        // Fix boolean checks - handle both string '1' and number 1
        const isTrue = (val) => val === '1' || val === 1 || val === true;
        $('#print-show-logo').prop('checked', isTrue(settings.print_show_logo));
        $('#print-show-payment-info').prop('checked', isTrue(settings.print_show_payment_info));
        $('#print-show-company-info').prop('checked', isTrue(settings.print_show_company_info));
        $('#print-show-signature-line').prop('checked', isTrue(settings.print_show_signature_line));
        $('#print-template').val(
            (settings.print_template === 'compact' || settings.print_template === 'images')
                ? settings.print_template
                : 'classic'
        );
        
        $('#print-header-text').val(settings.print_header_text || '');
        $('#print-footer-text').val(settings.print_footer_text || '');
        $('#print-terms').val(settings.print_terms || '');
        
        // Update logo preview
        updateLogoPreview(settings.print_logo_url);
    }

    async function loadEntityPrintSettings() {
        try {
            // First try to get from cache
            const cache = App?.getCache?.() || {};
            if (cache.entityPrintSettings && Object.keys(cache.entityPrintSettings).length > 0) {
                entityPrintSettings = cache.entityPrintSettings;
                loadPrintSettings();
                return;
            }
            
            // If not in cache, fetch from API
            const result = await Api.call('get_entity_print_settings');
            
            // result is null if API threw an error (handled by Api.call)
            if (result === null || (result && result.error)) {
                entityPrintSettings = {};
            } else {
                entityPrintSettings = result || {};
            }
            
            loadPrintSettings();
        } catch (e) {
            console.error('Exception in loadEntityPrintSettings:', e);
            entityPrintSettings = {};
            loadPrintSettings();
        }
    }

    function updateLogoPreview(url) {
        const img = $('#logo-preview-img');
        const text = $('#logo-preview-text');
        if (url && url.trim()) {
            img.attr('src', url).show().on('error', function() {
                $(this).hide();
                text.text('خطا در بارگذاری تصویر').show();
            }).on('load', function() {
                text.hide();
            });
        } else {
            img.hide();
            text.text('لوگویی انتخاب نشده').show();
        }
    }

    async function handlePrintSettingsSubmit(e) {
        e.preventDefault();
        UI.hideModalError('#print-settings-form');

        const settingsData = {
            print_company_name: $('#print-company-name').val(),
            print_company_phone: $('#print-company-phone').val(),
            print_company_address: $('#print-company-address').val(),
            print_logo_url: $('#print-logo-url').val(),
            print_bank_name: $('#print-bank-name').val(),
            print_account_number: $('#print-account-number').val(),
            print_card_number: $('#print-card-number').val(),
            print_sheba_number: $('#print-sheba-number').val(),
            print_account_holder: $('#print-account-holder').val(),
            print_show_logo: $('#print-show-logo').is(':checked') ? '1' : '0',
            print_show_payment_info: $('#print-show-payment-info').is(':checked') ? '1' : '0',
            print_show_company_info: $('#print-show-company-info').is(':checked') ? '1' : '0',
            print_show_signature_line: $('#print-show-signature-line').is(':checked') ? '1' : '0',
            print_template: (function () {
                const t = $('#print-template').val();
                return (t === 'compact' || t === 'images') ? t : 'classic';
            })(),
            print_header_text: $('#print-header-text').val(),
            print_footer_text: $('#print-footer-text').val(),
            print_terms: $('#print-terms').val()
        };

        console.log('Saving print settings:', settingsData);

        UI.showLoader();
        const result = await Api.call('save_entity_print_settings', { settings: settingsData });
        console.log('Save result:', result);
        UI.hideLoader();

        if (result?.success) {
            UI.showSuccess('تنظیمات چاپ مجموعه با موفقیت ذخیره شد. در حال بارگذاری مجدد...');
            setTimeout(() => {
                location.reload();
            }, 1500);
        } else if (result?.error) {
            UI.showError(result.error);
        }
    }

    function previewPrintSettings() {
        const settings = {
            company_name: $('#print-company-name').val() || 'نام شرکت',
            company_phone: $('#print-company-phone').val(),
            company_address: $('#print-company-address').val(),
            logo_url: $('#print-logo-url').val(),
            bank_name: $('#print-bank-name').val(),
            account_number: $('#print-account-number').val(),
            card_number: $('#print-card-number').val(),
            sheba_number: $('#print-sheba-number').val(),
            account_holder: $('#print-account-holder').val(),
            show_logo: $('#print-show-logo').is(':checked'),
            show_payment_info: $('#print-show-payment-info').is(':checked'),
            show_company_info: $('#print-show-company-info').is(':checked'),
            show_signature_line: $('#print-show-signature-line').is(':checked'),
            print_template: (function () {
                const t = $('#print-template').val();
                return (t === 'compact' || t === 'images') ? t : 'classic';
            })(),
            header_text: $('#print-header-text').val(),
            footer_text: $('#print-footer-text').val(),
            terms: $('#print-terms').val()
        };

        // Generate sample invoice preview
        const sampleInvoice = {
            id: 12345,
            date: new Date().toISOString().split('T')[0],
            customerName: 'مشتری نمونه',
            customerAddress: 'تهران، خیابان ولیعصر',
            customerPhone: '09123456789',
            items: [
                { name: 'فرش دستباف 2x3', dimensions: '2x3', quantity: 2, unitPrice: 5000000 },
                { name: 'گلیم سنتی 1.5x2', dimensions: '1.5x2', quantity: 1, unitPrice: 2500000 }
            ],
            totalAmount: 12500000,
            discount: 500000,
            paidAmount: 10000000
        };

        const printHtml = UI.generateInvoicePrintHtml(sampleInvoice, settings, 'sales');
        
        const printWin = window.open('', '_blank', 'width=800,height=600');
        if (!printWin) {
            UI.showError('پنجره پیش‌نمایش مسدود شد. لطفاً popup blocker را غیرفعال کنید.');
            return;
        }
        printWin.document.write(printHtml);
        printWin.document.close();
    }

    function init() {
        $(config.formId).on('submit', handleGeneralFormSubmit);
        $('#add-entity-btn').on('click', handleAddEntity);
        $('#new-entity-name').on('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                handleAddEntity();
            }
        });
        $('#business-entities-container').on('click', '.delete-entity-btn', function () {
            handleDeleteEntity($(this).data('entity-id'));
        });
        $('#print-settings-form').on('submit', handlePrintSettingsSubmit);
        
        // Logo URL preview - use closure to capture correct element
        const debouncedLogoPreview = UI.debounce(function(url) {
            updateLogoPreview(url);
        }, 500);
        $('#print-logo-url').on('input', function() {
            debouncedLogoPreview($(this).val());
        });
        
        // Preview button
        $('#preview-print-settings-btn').on('click', previewPrintSettings);
        
        // Load print settings when tab is shown
        $('button[data-bs-toggle="tab"][data-bs-target="#print-settings-pane"]').on('shown.bs.tab', function () {
            loadEntityPrintSettings();
        });

        $('button[data-bs-toggle="tab"][data-bs-target="#sms-settings-pane"]').off('shown.bs.tab.sms').on('shown.bs.tab.sms', function () {
            loadSmsSettings();
            loadSmsLocalLogs(true);
            loadSmsOutboxStats();
        });
        $('button[data-bs-toggle="tab"][data-bs-target="#payment-gateway-settings-pane"]').off('shown.bs.tab.zp').on('shown.bs.tab.zp', function () {
            loadZarinpalSettings();
            loadOnlinePayments();
        });
        $('#zarinpal-settings-form').off('submit.zp').on('submit.zp', handleZarinpalSettingsSubmit);
        $('#online-payments-status').off('change.op').on('change.op', loadOnlinePayments);
        $('#online-payments-refresh').off('click.op').on('click.op', loadOnlinePayments);
        $('#online-payments-tbody').off('click.op').on('click.op', '.retry-online-payment', async function () {
            const id = $(this).data('id');
            UI.showLoader('در حال استعلام از زرین‌پال...');
            const res = await Api.call('retry_online_payment', { id });
            UI.hideLoader();
            if (res?.success) {
                UI.showSuccess(res.message || 'ثبت شد.');
                loadOnlinePayments();
            }
        });
        $('#zarinpal-enabled').off('change.zp').on('change.zp', function () {
            updateZarinpalEnabledLabel();
            saveZarinpalEnabledOnly($(this).is(':checked'));
        });
        $('button[data-bs-toggle="tab"][data-bs-target="#integration-settings-pane"]').off('shown.bs.tab.int').on('shown.bs.tab.int', function () {
            loadIntegrationSettings();
        });
        $('#integration-settings-form').off('submit.int').on('submit.int', handleIntegrationSettingsSubmit);
        $('#integration-create-token-btn').off('click.int').on('click.int', createIntegrationToken);
        $('#integration-ping-btn').off('click.int').on('click.int', pingIntegration);
        $('#integration-tokens-tbody').off('click.int', '.btn-revoke-token').on('click.int', '.btn-revoke-token', function () {
            const id = $(this).data('id');
            revokeIntegrationToken(id);
        });
        $('#sms-settings-form').off('submit.sms').on('submit.sms', handleSmsSettingsSubmit);
        $('#sms-mode').off('change.sms').on('change.sms', updateSmsModeUI);
        $('#sms-test-send-btn').off('click.sms').on('click.sms', handleSmsTestSend);
        $('#sms-toggle-api-key').off('click.sms').on('click.sms', function () {
            const input = $('#sms-api-key');
            const isPass = input.attr('type') === 'password';
            input.attr('type', isPass ? 'text' : 'password');
            $(this).find('i').toggleClass('bi-eye bi-eye-slash');
        });
        $('#sms-local-logs-refresh-btn').off('click.sms').on('click.sms', () => loadSmsLocalLogs(true));
        $('#sms-refresh-status-btn').off('click.sms').on('click.sms', handleSmsRefreshStatus);
        $('#sms-outbox-refresh-btn').off('click.sms').on('click.sms', () => loadSmsOutbox(true));
        $('#sms-inbox-refresh-btn').off('click.sms').on('click.sms', () => loadSmsInbox(true));
        $('#sms-stats-refresh-btn').off('click.sms').on('click.sms', () => loadSmsOutboxStats(true));
        $('#sms-outbox-tab').off('shown.bs.tab.sms').on('shown.bs.tab.sms', () => loadSmsOutbox(false));
        $('#sms-inbox-tab').off('shown.bs.tab.sms').on('shown.bs.tab.sms', () => loadSmsInbox(false));
        $('#sms-local-logs-tab').off('shown.bs.tab.sms').on('shown.bs.tab.sms', () => loadSmsLocalLogs(false));
        $('#sms-check-alert-run-btn').off('click.sms').on('click.sms', async function () {
            UI.confirmAction('اعلان چک‌های نزدیک به سررسید برای شرکای انتخاب‌شده الان ارسال شود؟', async (ok) => {
                if (!ok) return;
                if (!$('#sms-enabled').is(':checked')) {
                    UI.showError('ابتدا «فعال‌سازی ارسال پیامک» را روشن کنید و تنظیمات را ذخیره کنید.');
                    return;
                }
                const partners = $('#sms-check-alert-partners').val() || [];
                if (!partners.length) {
                    UI.showError('حداقل یک شریک را برای دریافت اعلان انتخاب کنید.');
                    return;
                }
                UI.showLoader('در حال ذخیره تنظیمات و ارسال اعلان چک...');
                // Save current form first so run uses latest settings
                await handleSmsSettingsSubmit({ preventDefault() {} });
                const res = await Api.call('run_partner_check_alerts', { force: 1 });
                UI.hideLoader();
                if (res?.success) {
                    if (res.skipped) {
                        UI.showError(res.message || 'ارسال انجام نشد.');
                    } else if ((res.sent || 0) > 0) {
                        UI.showSuccess(res.message || `ارسال انجام شد: ${res.sent} پیامک`);
                        if (res.errors?.length) {
                            UI.showInfo('برخی ارسال‌ها ناموفق بود: ' + res.errors.join(' | '));
                        }
                    } else {
                        UI.showInfo(res.message || 'هیچ پیامکی ارسال نشد.');
                    }
                } else if (res?.error) {
                    UI.showError(res.error);
                }
            });
        });

        // Auto-save for backup settings
        $('#auto-backup-enabled').on('change', handleAutoBackupSettingsChange);
        $('#auto-backup-include-images').on('change', handleAutoBackupSettingsChange);
        $('#auto-backup-retention-days').on('input', handleAutoBackupSettingsChange);
        $('#auto-backup-secret').on('input', handleAutoBackupSettingsChange);
        $('#smtp-enabled, #smtp-host, #smtp-port, #smtp-encryption, #smtp-username, #smtp-password, #mail-from-address, #mail-from-name, #backup-email-to')
            .on('change input', handleAutoBackupSettingsChange);
        $('#remote-backup-enabled').on('change', handleAutoBackupSettingsChange);
        $('#remote-backup-url').on('input', handleAutoBackupSettingsChange);
        $('#remote-backup-secret').on('input', handleAutoBackupSettingsChange);
        $('#remote-backup-receive-secret').on('input', handleAutoBackupSettingsChange);
        $('#remote-backup-retention-days').on('input', handleAutoBackupSettingsChange);
        $('#backup-encryption-password').on('input', handleAutoBackupSettingsChange);
        $('#test-remote-backup-btn').on('click', testRemoteBackupConnection);
        $('#test-email-settings-btn').on('click', testEmailSettingsConnection);

        $('#backup-partial-select').select2({
            theme: 'bootstrap-5',
            dir: 'rtl',
            placeholder: "یک یا چند بخش را انتخاب کنید..."
        });

        $('button[data-bs-toggle="tab"][data-bs-target="#access-control-pane"]').on('shown.bs.tab', function () {
            App.getManager('access_control').load();
        });

        $('button[data-bs-toggle="tab"][data-bs-target="#collections-pane"]').on('shown.bs.tab', function () {
            App.getManager('collections').load();
        });

        $('button[data-bs-toggle="tab"][data-bs-target="#backup-restore-pane"]').on('shown.bs.tab', function () {
            App.getManager('backup').load();
        });

        // Load Account Closing when its tab is shown
        $('button[data-bs-toggle="tab"][data-bs-target="#account-closing-pane"]').on('shown.bs.tab', function () {
            App.getManager('account_closing').load();
        });

        // === Import/Export Data (Full Rewrite) ===
        let exportLoadedItems = [];
        let exportHeaders = [];
        let exportColumns = [];
        let exportNameCol = 'name';
        let stocktakingProducts = [];
        let stocktakingWarehouses = [];
        let stocktakingCurrentId = null;
        let stocktakingCurrentStatus = 'draft';

        // راهنمای ستون‌ها برای ورود داده
        const importColumnHints = {
            customers: 'ستون‌ها: نام، تلفن، آدرس، کد ملی، مانده اولیه',
            suppliers: 'ستون‌ها: نام، تلفن، آدرس، کد اقتصادی، مانده اولیه',
            products: 'ستون‌ها: نام، توضیحات، قیمت، واحد، کد SKU، کد طرح، رنگبندی',
            accounts: 'ستون‌ها: نام حساب، نام بانک، شماره حساب، شماره کارت، مانده اولیه، صندوق نقدی (0 یا 1)',
            partners: 'ستون‌ها: نام، سهم (مثلاً 0.25)',
            product_categories: 'ستون‌ها: نام، توضیحات، ترتیب',
            warehouses: 'ستون‌ها: نام، آدرس، تلفن',
            expenses: 'ستون‌ها: دسته‌بندی، تاریخ (YYYY-MM-DD)، مبلغ، توضیحات، شناسه حساب',
            incomes: 'ستون‌ها: دسته‌بندی، تاریخ (YYYY-MM-DD)، مبلغ، توضیحات، شناسه حساب',
        };

        // نمایش راهنمای ستون‌ها هنگام تغییر نوع داده ورودی
        $('#import-csv-type').on('change', function () {
            const hint = importColumnHints[$(this).val()] || '';
            $('#import-columns-hint').html(hint ? `<code>${hint}</code>` : '');
        }).trigger('change');

        // بارگذاری آیتم‌ها برای انتخاب
        $('#export-load-items-btn').on('click', async function () {
            const type = $('#export-data-type').val();
            const btn = $(this);
            btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>بارگذاری...');

            const result = await Api.call('get_export_items', { type });
            btn.prop('disabled', false).html('<i class="bi bi-search me-1"></i>بارگذاری آیتم‌ها');

            if (!result?.items) {
                UI.showError(result?.error || 'خطا در بارگذاری آیتم‌ها');
                return;
            }

            exportLoadedItems = result.items;
            exportHeaders = result.headers;
            exportColumns = result.columns;
            exportNameCol = result.name_col || 'name';

            // ساخت هدر جدول
            const thead = $('#export-items-thead');
            thead.empty();
            thead.append('<th><input type="checkbox" id="export-select-all-th"></th>');
            thead.append('<th>#</th>');
            // فقط چند ستون کلیدی نمایش داده شود (حداکثر 6 ستون)
            const displayCols = exportColumns.slice(0, 6);
            const displayHeaders = exportHeaders.slice(0, 6);
            displayHeaders.forEach(h => thead.append(`<th>${h}</th>`));

            // ساخت ردیف‌ها
            const tbody = $('#export-items-tbody');
            tbody.empty();
            exportLoadedItems.forEach((item, idx) => {
                let row = `<tr data-id="${item.id || idx}">`;
                row += `<td><input type="checkbox" class="export-item-check" value="${item.id || idx}"></td>`;
                row += `<td>${idx + 1}</td>`;
                displayCols.forEach(col => {
                    let val = item[col] ?? '';
                    if (typeof val === 'string' && val.length > 40) val = val.substring(0, 40) + '...';
                    row += `<td>${val}</td>`;
                });
                row += '</tr>';
                tbody.append(row);
            });

            // نمایش ابزار و جدول
            $('#export-toolbar').show();
            $('#export-items-container').show();
            $('#export-csv-btn, #export-all-btn').prop('disabled', false);
            $('#export-total-badge').text(`کل: ${exportLoadedItems.length}`);
            $('#export-selected-badge').text('انتخاب شده: 0');
            $('#export-select-all, #export-select-all-th').prop('checked', false);

            UI.showSuccess(`${result.label}: ${exportLoadedItems.length} مورد بارگذاری شد.`);
        });

        // انتخاب/عدم انتخاب همه
        $(document).on('change', '#export-select-all, #export-select-all-th', function () {
            const checked = $(this).is(':checked');
            $('#export-select-all, #export-select-all-th').prop('checked', checked);
            $('#export-items-tbody .export-item-check:visible').prop('checked', checked);
            updateExportSelectedCount();
        });

        // شمارش انتخاب شده‌ها
        $(document).on('change', '.export-item-check', function () {
            updateExportSelectedCount();
        });

        function updateExportSelectedCount() {
            const count = $('#export-items-tbody .export-item-check:checked').length;
            $('#export-selected-badge').text(`انتخاب شده: ${count}`);
        }

        // جستجو در آیتم‌ها
        $('#export-search').on('input', function () {
            const q = $(this).val().toLowerCase();
            $('#export-items-tbody tr').each(function () {
                const text = $(this).text().toLowerCase();
                $(this).toggle(text.includes(q));
            });
        });

        // دانلود انتخاب‌شده‌ها
        $('#export-csv-btn').on('click', async function () {
            const type = $('#export-data-type').val();
            const format = $('#export-format').val();
            const checkedIds = [];
            $('#export-items-tbody .export-item-check:checked').each(function () {
                checkedIds.push($(this).val());
            });
            if (checkedIds.length === 0) {
                UI.showError('لطفاً حداقل یک آیتم انتخاب کنید یا از دکمه «دانلود همه» استفاده کنید.');
                return;
            }
            await doExport(type, format, checkedIds);
        });

        // دانلود همه
        $('#export-all-btn').on('click', async function () {
            const type = $('#export-data-type').val();
            const format = $('#export-format').val();
            await doExport(type, format, []);
        });

        async function doExport(type, format, ids) {
            const btn = ids.length > 0 ? $('#export-csv-btn') : $('#export-all-btn');
            btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>...');

            const result = await Api.call('export_csv_data', { type, format, ids });

            btn.prop('disabled', false);
            if (ids.length > 0) {
                btn.html('<i class="bi bi-download me-1"></i>دانلود انتخاب‌شده‌ها');
            } else {
                btn.html('<i class="bi bi-download me-1"></i>دانلود همه');
            }

            if (format === 'json') {
                if (!result?.rows) { UI.showError(result?.error || 'خطا در خروج داده'); return; }
                const blob = new Blob([JSON.stringify(result.rows, null, 2)], { type: 'application/json;charset=utf-8;' });
                downloadBlob(blob, `${type}_export.json`);
                UI.showSuccess(`${result.label}: ${result.rows.length} رکورد دانلود شد.`);
            } else {
                if (!result?.csv) { UI.showError(result?.error || 'خطا در خروج داده'); return; }
                const BOM = '\uFEFF';
                const blob = new Blob([BOM + result.csv], { type: 'text/csv;charset=utf-8;' });
                downloadBlob(blob, `${type}_export.csv`);
                UI.showSuccess(`${result.label}: ${result.count} رکورد دانلود شد.`);
            }
        }

        function downloadBlob(blob, filename) {
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }

        // خروجی کامل JSON
        $('#export-all-json-btn').on('click', async function () {
            if (!await UI.confirmText('آیا از خروجی کل داده‌ها به صورت JSON مطمئن هستید؟ این فایل ممکن است حجیم باشد.')) return;
            const btn = $(this);
            btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>...');

            const result = await Api.call('export_all_json', {});
            btn.prop('disabled', false).html('<i class="bi bi-file-earmark-code me-1"></i>خروجی کامل JSON');

            if (!result?.export) { UI.showError(result?.error || 'خطا در خروجی'); return; }

            const blob = new Blob([JSON.stringify(result.export, null, 2)], { type: 'application/json;charset=utf-8;' });
            downloadBlob(blob, `full_data_export_${new Date().toISOString().split('T')[0]}.json`);
            UI.showSuccess('خروجی کامل JSON با موفقیت دانلود شد.');
        });

        // ورود داده (Import): first a dry-run preview, then explicit confirmation.
        const renderImportPreview = (result, onConfirm) => {
            const statusLabel = { new: 'جدید', update: 'به‌روزرسانی', unchanged: 'بدون تغییر', error: 'خطا' };
            const statusClass = { new: 'text-success', update: 'text-primary', unchanged: 'text-muted', error: 'text-danger' };
            const rows = (result.preview || []).map(r => `<tr>
                <td>${r.row}</td><td>${UI.escapeHtml(r.name || '')}</td>
                <td>${r.amount !== undefined ? UI.formatCurrency(r.amount) : ''}</td>
                <td>${r.date ? UI.gregorianToPersian(r.date) : ''}</td>
                <td class="${statusClass[r.status] || ''}">${statusLabel[r.status] || r.status}${r.error ? ' — ' + UI.escapeHtml(r.error) : ''}</td>
            </tr>`).join('');
            const html = `<div class="alert alert-info mb-2"><i class="bi bi-eye me-1"></i>${UI.escapeHtml(result.message || '')}</div>
                <div class="table-responsive" style="max-height:320px">
                  <table class="table table-sm table-bordered mb-2"><thead><tr><th>ردیف</th><th>عنوان</th><th>مبلغ</th><th>تاریخ</th><th>نتیجه</th></tr></thead><tbody>${rows || '<tr><td colspan="5" class="text-center text-muted">ردیفی برای نمایش نیست</td></tr>'}</tbody></table>
                </div>
                ${(result.preview || []).length >= 50 ? '<small class="text-muted d-block mb-2">فقط ۵۰ ردیف اول نمایش داده شده است.</small>' : ''}
                ${result.would_insert > 0 ? '<button type="button" class="btn btn-success btn-sm" id="import-csv-confirm"><i class="bi bi-check2 me-1"></i>تأیید و ورود نهایی</button>' : ''}
                <button type="button" class="btn btn-outline-secondary btn-sm ms-1" id="import-csv-cancel">انصراف</button>`;
            const resultDiv = $('#import-result').html(html).show();
            resultDiv.find('#import-csv-confirm').on('click', onConfirm);
            resultDiv.find('#import-csv-cancel').on('click', () => resultDiv.empty().hide());
        };

        $('#import-csv-btn').on('click', async function () {
            const type = $('#import-csv-type').val();
            const fileInput = document.getElementById('import-csv-file');
            if (!fileInput.files.length) { UI.showError('لطفاً یک فایل CSV انتخاب کنید.'); return; }

            const btn = $(this);
            const resetBtn = () => btn.prop('disabled', false).html('<i class="bi bi-upload me-1"></i>ورود داده');
            btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>در حال بررسی...');

            const reader = new FileReader();
            reader.onload = async function (e) {
                const csvText = e.target.result;
                const preview = await Api.call('import_csv_data', { type, csv_data: csvText, dry_run: true });
                resetBtn();
                if (!preview?.success) return;

                renderImportPreview(preview, async () => {
                    UI.showLoader('در حال ورود داده...');
                    const result = await Api.call('import_csv_data', { type, csv_data: csvText });
                    UI.hideLoader();
                    const resultDiv = $('#import-result');
                    if (result?.success) {
                        let msg = `<div class="alert alert-success"><i class="bi bi-check-circle-fill me-2"></i>`;
                        msg += `<strong>${UI.escapeHtml(result.message)}</strong>`;
                        if (result.inserted) msg += `<br>وارد شده: <strong>${result.inserted}</strong>`;
                        if (result.skipped) msg += ` | رد شده: <strong>${result.skipped}</strong>`;
                        if (result.errors && result.errors.length > 0) {
                            msg += `<br><small class="text-warning">خطاها: ${result.errors.map(x => UI.escapeHtml(x)).join(' | ')}</small>`;
                        }
                        msg += '</div>';
                        resultDiv.html(msg).show();
                        fileInput.value = '';
                        UI.showSuccess(result.message || 'ورود داده با موفقیت انجام شد.');
                        if (App.fetchInitialCache) await App.fetchInitialCache();
                    }
                });
            };
            reader.readAsText(fileInput.files[0]);
        });

        // === Stocktaking ===
        let stocktakingPrintPreviewItems = [];

        $('button[data-bs-toggle="tab"][data-bs-target="#stocktaking-pane"]').on('shown.bs.tab', function () {
            loadStocktakingList();
            loadEntityPrintSettings();
        });

        function getStocktakingWarehouseLabel(warehouseVal) {
            if (!warehouseVal || warehouseVal === 'all') return 'همه انبارها';
            const wh = stocktakingWarehouses.find(w => String(w.id) === String(warehouseVal));
            return wh ? wh.name : `انبار #${warehouseVal}`;
        }

        function isStocktakingPrintMultiWarehouse() {
            const v = $('#stocktaking-print-warehouse').val();
            return !v || v === 'all';
        }

        function toggleStocktakingPrintWarehouseColumn() {
            const multi = isStocktakingPrintMultiWarehouse();
            $('.stocktaking-print-warehouse-col').toggle(multi);
        }

        function collectStocktakingPrintItemsFromModal() {
            const items = [];
            $('#stocktaking-items-tbody .stocktaking-row').each(function () {
                const $row = $(this);
                const productId = parseInt($row.find('.stocktaking-product').val() || 0);
                if (!productId) return;

                const catalogProduct = stocktakingProducts.find(p => String(p.id) === String(productId));
                let product_name = catalogProduct?.name || '';
                if (!product_name) {
                    product_name = $row.find('.small.text-muted').first().text().trim()
                        || $row.find('.stocktaking-product option:selected').text().trim();
                }
                const whVal = $row.find('.stocktaking-warehouse').val();
                const whName = $row.find('.stocktaking-warehouse option:selected').text() || 'بدون انبار';

                items.push({
                    product_id: productId,
                    product_name,
                    design_code: catalogProduct?.design_code || '',
                    colorway: catalogProduct?.colorway || '',
                    dimensions: $row.find('.stocktaking-dimensions').val() || '',
                    warehouse_id: parseInt(whVal || 0, 10),
                    warehouse_name: whName,
                    // شناسه انبار = serial_id مثل INV-001266 (نه warehouse_id)
                    serial_ids: $row.attr('data-serial-ids') || '',
                    system_qty: parseInt($row.find('.stocktaking-system').val() || 0, 10)
                });
            });
            return items;
        }

        function printStocktakingSheet(meta, items, warehouseVal, options = {}) {
            const blankMode = !!options.blankMode;
            if (!blankMode && (!items || items.length === 0)) {
                UI.showError('ردیفی برای چاپ وجود ندارد. ابتدا موجودی را بارگذاری کنید.');
                return false;
            }
            const multi = !warehouseVal || warehouseVal === 'all';
            const ok = UI.printStocktakingCountSheet(meta, items || [], {
                showWarehouseCol: multi,
                blankMode,
                blankRowCount: options.blankRowCount
            });
            if (!ok) {
                UI.showError('پنجره چاپ مسدود شد. popup blocker را غیرفعال کنید.');
            }
            return ok;
        }

        function buildStocktakingPrintMeta($dateInput, $titleInput, warehouseVal, defaults = {}) {
            const dateVal = $dateInput.val() || UI.today();
            const dateValidation = UI.validatePersianDate(UI.toEnglishDigits(dateVal));
            if (!dateValidation.valid) {
                UI.showError(dateValidation.error);
                return null;
            }
            let gregorianDate = dateVal;
            if (UI.toGregorian) {
                const g = UI.toGregorian($dateInput);
                if (g) gregorianDate = g;
            }
            return {
                title: ($titleInput.val() || '').trim() || defaults.title || 'انبارگردانی',
                date: gregorianDate,
                warehouseLabel: getStocktakingWarehouseLabel(warehouseVal),
                sessionId: defaults.sessionId || '-',
                notes: defaults.notes || ''
            };
        }

        function syncStocktakingBlankRowInputs(value) {
            $('.stocktaking-blank-rows-input').each(function () {
                if (String($(this).val()) !== String(value)) {
                    $(this).val(value);
                }
            });
        }

        function getStocktakingBlankRowCount() {
            const $visible = $('.stocktaking-blank-rows-input').filter(':visible');
            const $source = $visible.length ? $visible.first() : $('.stocktaking-blank-rows-input').first();
            const raw = UI.toEnglishDigits(($source.val() || '').toString().trim());
            const n = parseInt(raw, 10);
            if (!Number.isFinite(n) || n < 1) {
                UI.showError('تعداد ردیف فرم خام را به‌صورت عدد بزرگ‌تر از صفر وارد کنید.');
                return null;
            }
            if (n > 500) {
                UI.showError('تعداد ردیف فرم خام حداکثر ۵۰۰ است.');
                return null;
            }
            syncStocktakingBlankRowInputs(n);
            return n;
        }

        function printCurrentStocktakingSheet() {
            const items = collectStocktakingPrintItemsFromModal();
            const warehouseVal = $('#stocktaking-warehouse').val() || 'all';
            const meta = buildStocktakingPrintMeta(
                $('#stocktaking-date'),
                $('#stocktaking-title'),
                warehouseVal,
                {
                    title: 'انبارگردانی',
                    sessionId: $('#stocktaking-id').val() || '',
                    notes: ($('#stocktaking-notes').val() || '').trim()
                }
            );
            if (!meta) return false;
            return printStocktakingSheet(meta, items, warehouseVal);
        }

        function printCurrentStocktakingBlankSheet() {
            const blankRowCount = getStocktakingBlankRowCount();
            if (!blankRowCount) return false;
            const warehouseVal = $('#stocktaking-warehouse').val() || 'all';
            const meta = buildStocktakingPrintMeta(
                $('#stocktaking-date'),
                $('#stocktaking-title'),
                warehouseVal,
                {
                    title: 'فرم خام شمارش فیزیکی',
                    sessionId: $('#stocktaking-id').val() || '-',
                    notes: ($('#stocktaking-notes').val() || '').trim()
                }
            );
            if (!meta) return false;
            return printStocktakingSheet(meta, [], warehouseVal, {
                blankMode: true,
                blankRowCount
            });
        }

        async function openStocktakingPrintModal(options = {}) {
            await ensureStocktakingCatalog();
            await loadEntityPrintSettings();
            stocktakingPrintPreviewItems = [];
            $('#stocktaking-print-preview-tbody').html('<tr><td colspan="7" class="text-center text-muted">برای پیش‌نمایش، «بارگذاری» را بزنید.</td></tr>');
            $('#print-stocktaking-print-modal-btn').prop('disabled', true);

            const warehouseSelect = $('#stocktaking-print-warehouse').empty();
            warehouseSelect.append('<option value="all">همه انبارها</option>');
            stocktakingWarehouses.forEach(warehouse => {
                warehouseSelect.append(`<option value="${warehouse.id}">${UI.escapeHtml(warehouse.name || `انبار #${warehouse.id}`)}</option>`);
            });

            $('#stocktaking-print-title').val(options.prefillTitle || '');
            $('#stocktaking-print-date').val(options.prefillDate || UI.today());
            warehouseSelect.val(options.prefillWarehouse || 'all');
            $('#stocktaking-print-search').val(options.prefillSearch || '');

            toggleStocktakingPrintWarehouseColumn();
            $('#stocktaking-print-modal').modal('show');
            setTimeout(() => UI.initializeDatepickers(), 100);
        }

        function renderStocktakingPrintPreview() {
            const tbody = $('#stocktaking-print-preview-tbody').empty();
            const multi = isStocktakingPrintMultiWarehouse();
            toggleStocktakingPrintWarehouseColumn();

            if (!stocktakingPrintPreviewItems.length) {
                tbody.html('<tr><td colspan="8" class="text-center text-muted">ردیفی برای نمایش نیست.</td></tr>');
                return;
            }

            stocktakingPrintPreviewItems.forEach((item, idx) => {
                tbody.append(`<tr>
                    <td>${idx + 1}</td>
                    <td>${UI.escapeHtml(item.product_name || '-')}</td>
                    <td>${UI.escapeHtml(item.design_code || '-')}</td>
                    <td>${UI.escapeHtml(item.colorway || '-')}</td>
                    <td>${UI.escapeHtml(item.dimensions || '-')}</td>
                    <td class="small">${UI.escapeHtml(item.serial_ids || '-')}</td>
                    ${multi ? `<td>${UI.escapeHtml(item.warehouse_name || 'بدون انبار')}</td>` : ''}
                    <td class="text-end">${UI.formatNumber(item.system_qty || 0)}</td>
                </tr>`);
            });
        }

        async function loadStocktakingPrintPreview() {
            await ensureStocktakingCatalog();
            const warehouseId = $('#stocktaking-print-warehouse').val() || 'all';
            const search = $('#stocktaking-print-search').val() || '';
            const btn = $('#load-stocktaking-print-preview-btn');
            btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>بارگذاری...');

            const result = await Api.call('get_stocktaking_snapshot', { warehouse_id: warehouseId, search });
            btn.prop('disabled', false).html('<i class="bi bi-boxes me-1"></i>بارگذاری');

            if (!result || result.error) {
                UI.showError(result?.error || 'خطا در بارگذاری موجودی');
                return;
            }

            stocktakingPrintPreviewItems = result.items || [];
            renderStocktakingPrintPreview();
            $('#print-stocktaking-print-modal-btn').prop('disabled', stocktakingPrintPreviewItems.length === 0);
            if (stocktakingPrintPreviewItems.length === 0) {
                UI.showInfo('موجودی برای این فیلتر پیدا نشد.');
            } else {
                UI.showSuccess(`${stocktakingPrintPreviewItems.length} ردیف بارگذاری شد. اکنون برگه را چاپ کنید.`);
            }
        }

        async function printFromStocktakingPrintModal() {
            if (!stocktakingPrintPreviewItems.length) {
                UI.showError('ابتدا موجودی را بارگذاری کنید.');
                return;
            }
            const warehouseVal = $('#stocktaking-print-warehouse').val() || 'all';
            const meta = buildStocktakingPrintMeta(
                $('#stocktaking-print-date'),
                $('#stocktaking-print-title'),
                warehouseVal,
                { title: 'برگه شمارش فیزیکی' }
            );
            if (!meta) return;
            printStocktakingSheet(meta, stocktakingPrintPreviewItems, warehouseVal);
        }

        function printBlankFromStocktakingPrintModal() {
            const blankRowCount = getStocktakingBlankRowCount();
            if (!blankRowCount) return;
            const warehouseVal = $('#stocktaking-print-warehouse').val() || 'all';
            const meta = buildStocktakingPrintMeta(
                $('#stocktaking-print-date'),
                $('#stocktaking-print-title'),
                warehouseVal,
                { title: 'فرم خام شمارش فیزیکی' }
            );
            if (!meta) return;
            printStocktakingSheet(meta, [], warehouseVal, {
                blankMode: true,
                blankRowCount
            });
        }

        function printBlankFromStocktakingPane() {
            const blankRowCount = getStocktakingBlankRowCount();
            if (!blankRowCount) return;
            const $tmpDate = $('<input>').val(UI.today());
            const $tmpTitle = $('<input>').val('فرم خام شمارش فیزیکی');
            const meta = buildStocktakingPrintMeta($tmpDate, $tmpTitle, 'all', { title: 'فرم خام شمارش فیزیکی' });
            if (!meta) return;
            printStocktakingSheet(meta, [], 'all', {
                blankMode: true,
                blankRowCount
            });
        }

        async function openWarehouseInOutPrintModal() {
            await loadEntityPrintSettings();
            if (!$('#warehouse-inout-print-blank-rows').val()) {
                $('#warehouse-inout-print-blank-rows').val(15);
            }
            $('#warehouse-inout-print-modal').modal('show');
        }

        function getWarehouseInOutBlankRowCount() {
            const raw = UI.toEnglishDigits(($('#warehouse-inout-print-blank-rows').val() || '').toString().trim());
            const n = parseInt(raw, 10);
            if (!Number.isFinite(n) || n < 1) {
                UI.showError('تعداد ردیف را به‌صورت عدد بزرگ‌تر از صفر وارد کنید.');
                return null;
            }
            if (n > 500) {
                UI.showError('تعداد ردیف حداکثر ۵۰۰ است.');
                return null;
            }
            return n;
        }

        function printFromWarehouseInOutModal() {
            const blankRowCount = getWarehouseInOutBlankRowCount();
            if (!blankRowCount) return;
            const ok = UI.printWarehouseInOutSheet({}, { blankRowCount });
            if (!ok) {
                UI.showError('پنجره چاپ مسدود شد. popup blocker را غیرفعال کنید.');
            }
        }

        async function ensureStocktakingCatalog() {
            if (!Array.isArray(stocktakingProducts) || stocktakingProducts.length === 0) {
                const cachedProducts = App.getCache().products || [];
                if (Array.isArray(cachedProducts) && cachedProducts.length > 0) {
                    stocktakingProducts = cachedProducts;
                } else {
                    const productsResult = await Api.call('get_stocktaking_products');
                    const products = productsResult?.products || productsResult?.data || productsResult?.items || [];
                    stocktakingProducts = Array.isArray(products) ? products : [];
                }
            }

            if (!Array.isArray(stocktakingWarehouses) || stocktakingWarehouses.length === 0) {
                const warehousesResult = await Api.call('get_stocktaking_warehouses');
                const warehouses = warehousesResult?.warehouses || warehousesResult?.data || [];
                stocktakingWarehouses = Array.isArray(warehouses) ? warehouses : [];
            }
        }

        function renderStocktakingWarehouseOptions(selectedId = '') {
            const normalized = String(selectedId ?? '');
            let html = `<option value="0" ${normalized === '0' || normalized === '' ? 'selected' : ''}>بدون انبار</option>`;
            if (!Array.isArray(stocktakingWarehouses)) return html;
            stocktakingWarehouses.forEach(warehouse => {
                const selected = String(warehouse.id) === normalized ? 'selected' : '';
                html += `<option value="${warehouse.id}" ${selected}>${UI.escapeHtml(warehouse.name || `انبار #${warehouse.id}`)}</option>`;
            });
            return html;
        }

        function isStocktakingMultiWarehouse() {
            const v = $('#stocktaking-warehouse').val();
            return !v || v === 'all';
        }

        function toggleStocktakingWarehouseColumn() {
            const multi = isStocktakingMultiWarehouse();
            $('.stocktaking-warehouse-col').toggle(multi);
            if (!multi) {
                const wh = $('#stocktaking-warehouse').val();
                $('#stocktaking-items-tbody .stocktaking-row .stocktaking-warehouse').val(wh);
            }
        }

        function renderStocktakingProductOptions(selectedId = '') {
            if (!Array.isArray(stocktakingProducts) || stocktakingProducts.length === 0) {
                return '<option value="">محصولی یافت نشد</option>';
            }

            const normalizedSelected = String(selectedId || '');
            return stocktakingProducts.map(product => {
                const labelParts = [product.name || `محصول #${product.id}`];
                if (product.design_code) labelParts.push(`کد ${product.design_code}`);
                if (product.colorway) labelParts.push(product.colorway);
                const label = UI.escapeHtml(labelParts.join(' | '));
                const selected = String(product.id) === normalizedSelected ? 'selected' : '';
                return `<option value="${product.id}" ${selected}>${label}</option>`;
            }).join('');
        }

        function setStocktakingSummary() {
            const rows = $('#stocktaking-items-tbody .stocktaking-row');
            let systemTotal = 0;
            let countedTotal = 0;
            let diffTotal = 0;

            rows.each(function () {
                const systemQty = parseInt($(this).find('.stocktaking-system').val() || 0);
                const countedQty = parseInt($(this).find('.stocktaking-counted').val() || 0);
                const diff = countedQty - systemQty;
                systemTotal += systemQty;
                countedTotal += countedQty;
                diffTotal += diff;
            });

            $('#stocktaking-summary-rows').text(UI.formatNumber(rows.length));
            $('#stocktaking-summary-system').text(UI.formatNumber(systemTotal));
            $('#stocktaking-summary-counted').text(UI.formatNumber(countedTotal));
            $('#stocktaking-summary-diff').text(UI.formatNumber(diffTotal));
        }

        function refreshStocktakingRow($row) {
            const systemQty = parseInt($row.find('.stocktaking-system').val() || 0);
            const countedQty = parseInt($row.find('.stocktaking-counted').val() || 0);
            const diff = countedQty - systemQty;
            const diffEl = $row.find('.stocktaking-diff');
            diffEl.val(diff);
            diffEl.toggleClass('text-success', diff > 0);
            diffEl.toggleClass('text-danger', diff < 0);
            diffEl.toggleClass('text-muted', diff === 0);
            setStocktakingSummary();
        }

        function buildStocktakingRow(item = {}) {
            const productId = item.product_id || '';
            const dimensions = item.dimensions || '';
            const warehouseId = item.warehouse_id ?? 0;
            const systemQty = parseInt(item.system_qty ?? 0);
            const countedQty = parseInt(item.counted_qty ?? systemQty);
            const notes = item.notes || '';
            const productLabel = item.product_name
                ? `${item.product_name}${item.design_code ? ` | ${item.design_code}` : ''}${item.colorway ? ` | ${item.colorway}` : ''}`
                : '';

            return `
                <tr class="stocktaking-row" data-product-id="${productId}" data-dimensions="${UI.escapeHtml(dimensions)}" data-serial-ids="${UI.escapeHtml(item.serial_ids || '')}">
                    <td>
                        <select class="form-select form-select-sm stocktaking-product">
                            <option value="">انتخاب محصول</option>
                            ${renderStocktakingProductOptions(productId)}
                        </select>
                        ${productLabel ? `<div class="small text-muted mt-1">${UI.escapeHtml(productLabel)}</div>` : ''}
                    </td>
                    <td class="stocktaking-warehouse-col">
                        <select class="form-select form-select-sm stocktaking-warehouse">${renderStocktakingWarehouseOptions(warehouseId)}</select>
                    </td>
                    <td><input type="text" class="form-control form-control-sm stocktaking-dimensions" value="${UI.escapeHtml(dimensions)}" placeholder="ابعاد یا مشخصه"></td>
                    <td><input type="number" min="0" class="form-control form-control-sm text-end stocktaking-system" value="${systemQty}"></td>
                    <td><input type="number" min="0" class="form-control form-control-sm text-end stocktaking-counted" value="${countedQty}"></td>
                    <td><input type="text" class="form-control form-control-sm text-end stocktaking-diff" value="${countedQty - systemQty}" readonly></td>
                    <td><input type="text" class="form-control form-control-sm stocktaking-row-notes" value="${UI.escapeHtml(notes)}" placeholder="یادداشت"></td>
                    <td class="text-center">
                        <button type="button" class="btn btn-sm btn-outline-danger remove-stocktaking-row"><i class="bi bi-x"></i></button>
                    </td>
                </tr>
            `;
        }

        function addStocktakingRow(item = {}) {
            $('#stocktaking-items-tbody').append(buildStocktakingRow(item));
            const $row = $('#stocktaking-items-tbody .stocktaking-row').last();
            if (!isStocktakingMultiWarehouse()) {
                $row.find('.stocktaking-warehouse').val($('#stocktaking-warehouse').val());
            }
            refreshStocktakingRow($row);
        }

        function clearStocktakingRows() {
            $('#stocktaking-items-tbody').empty();
            setStocktakingSummary();
        }

        function setStocktakingEditable(isEditable) {
            $('#stocktaking-form').find('input, select, textarea').prop('disabled', !isEditable);
            $('#stocktaking-form').find('.remove-stocktaking-row').prop('disabled', !isEditable);
            $('#save-stocktaking-draft-btn').prop('disabled', !isEditable);
            $('#save-and-apply-stocktaking-btn').prop('disabled', !isEditable);
            $('#load-stocktaking-snapshot-btn').prop('disabled', !isEditable);
            $('#add-stocktaking-row-btn').prop('disabled', !isEditable);
            $('#clear-stocktaking-rows-btn').prop('disabled', !isEditable);
        }

        function collectStocktakingItems() {
            const items = [];
            const sessionWarehouse = $('#stocktaking-warehouse').val();
            const multi = isStocktakingMultiWarehouse();

            $('#stocktaking-items-tbody .stocktaking-row').each(function () {
                const $row = $(this);
                const productId = parseInt($row.find('.stocktaking-product').val() || 0);
                const dimensions = $row.find('.stocktaking-dimensions').val() || '';
                const systemQty = parseInt($row.find('.stocktaking-system').val() || 0);
                const countedQty = parseInt($row.find('.stocktaking-counted').val() || 0);
                const notes = $row.find('.stocktaking-row-notes').val() || '';

                if (!productId) return;

                let warehouse_id = 0;
                if (multi) {
                    warehouse_id = parseInt($row.find('.stocktaking-warehouse').val() || 0);
                } else if (sessionWarehouse && sessionWarehouse !== 'all') {
                    warehouse_id = parseInt(sessionWarehouse);
                }

                items.push({
                    product_id: productId,
                    dimensions,
                    warehouse_id,
                    system_qty: systemQty,
                    counted_qty: countedQty,
                    notes
                });
            });
            return items;
        }

        async function loadStocktakingWarehousesToModal(selectedId = '') {
            await ensureStocktakingCatalog();
            const select = $('#stocktaking-warehouse').empty();
            select.append('<option value="all">همه انبارها</option>');
            stocktakingWarehouses.forEach(warehouse => {
                const selected = String(warehouse.id) === String(selectedId) ? 'selected' : '';
                select.append(`<option value="${warehouse.id}" ${selected}>${UI.escapeHtml(warehouse.name || `انبار #${warehouse.id}`)}</option>`);
            });
        }

        async function openStocktakingModal(stocktakingId = null) {
            await ensureStocktakingCatalog();
            stocktakingCurrentId = null;
            stocktakingCurrentStatus = 'draft';
            setStocktakingEditable(true);
            $('#stocktaking-modal-title').text('انبارگردانی جدید');
            $('#stocktaking-id').val('');
            $('#stocktaking-title').val('');
            $('#stocktaking-date').val(UI.today());
            $('#stocktaking-notes').val('');
            $('#stocktaking-status').val('draft');
            $('#stocktaking-snapshot-search').val('');
            await loadStocktakingWarehousesToModal('all');
            clearStocktakingRows();

            if (stocktakingId) {
                const result = await Api.call('get_stocktaking', { id: stocktakingId });
                if (!result || result.error) {
                    UI.showError(result?.error || 'خطا در بارگذاری انبارگردانی');
                    return;
                }

                stocktakingCurrentId = result.id;
                stocktakingCurrentStatus = result.status || 'draft';
                $('#stocktaking-modal-title').text(`ویرایش انبارگردانی #${result.id}`);
                $('#stocktaking-id').val(result.id);
                $('#stocktaking-title').val(result.title || '');
                $('#stocktaking-date').val(result.date ? UI.gregorianToPersian(result.date) : UI.today());
                $('#stocktaking-notes').val(result.notes || '');
                $('#stocktaking-status').val(result.status === 'completed' ? 'draft' : (result.status || 'draft'));
                await loadStocktakingWarehousesToModal(result.warehouse_id || 'all');
                clearStocktakingRows();

                (result.items || []).forEach(item => addStocktakingRow(item));
                setStocktakingSummary();

                if (result.status === 'completed') {
                    setStocktakingEditable(false);
                    UI.showInfo('این انبارگردانی قبلاً اعمال شده و فقط قابل مشاهده است.');
                }
            } else {
                addStocktakingRow();
            }

            $('#stocktaking-modal').modal('show');
            toggleStocktakingWarehouseColumn();
            setTimeout(() => UI.initializeDatepickers(), 100);
        }

        async function loadStocktakingSnapshot() {
            if ($('#stocktaking-items-tbody .stocktaking-row').length > 0) {
                if (!await UI.confirmText('بارگذاری موجودی فعلی، ردیف‌های فعلی را پاک می‌کند. ادامه می‌دهید؟')) return;
            }
            await ensureStocktakingCatalog();
            const warehouseId = $('#stocktaking-warehouse').val() || 'all';
            const search = $('#stocktaking-snapshot-search').val() || '';
            const btn = $('#load-stocktaking-snapshot-btn');
            btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>بارگذاری...');

            const result = await Api.call('get_stocktaking_snapshot', { warehouse_id: warehouseId, search });
            btn.prop('disabled', false).html('<i class="bi bi-boxes me-2"></i>بارگذاری موجودی فعلی');

            if (!result || result.error) {
                UI.showError(result?.error || 'خطا در دریافت snapshot موجودی');
                return;
            }

            clearStocktakingRows();
            (result.items || []).forEach(item => {
                addStocktakingRow({
                    product_id: item.product_id,
                    product_name: item.product_name,
                    design_code: item.design_code,
                    colorway: item.colorway,
                    dimensions: item.dimensions,
                    warehouse_id: item.warehouse_id ?? 0,
                    // شناسه انبار = serial_id مثل INV-001266
                    serial_ids: item.serial_ids || '',
                    system_qty: item.system_qty,
                    counted_qty: item.system_qty,
                    notes: item.unlinked_qty > 0
                        ? `انبار: ${item.warehouse_name || 'بدون انبار'} | بدون لینک: ${item.unlinked_qty}`
                        : (item.warehouse_name ? `انبار: ${item.warehouse_name}` : '')
                });
            });
            toggleStocktakingWarehouseColumn();

            if ((result.items || []).length === 0) {
                UI.showInfo('موجودی فعلی برای این فیلتر پیدا نشد. می‌توانید ردیف‌ها را به‌صورت دستی اضافه کنید.');
            } else {
                UI.showSuccess(`${result.total_rows} ردیف موجودی بارگذاری شد.`);
            }
            setStocktakingSummary();
        }

        async function saveStocktaking(forceStatus = null, applyAfterSave = false) {
            await ensureStocktakingCatalog();
            const id = parseInt($('#stocktaking-id').val() || 0);
            const title = ($('#stocktaking-title').val() || '').trim();
            const notes = ($('#stocktaking-notes').val() || '').trim();
            const warehouse_id = $('#stocktaking-warehouse').val() || 'all';
            const dateValue = $('#stocktaking-date').val() || UI.today();
            const dateValidation = UI.validatePersianDate(UI.toEnglishDigits(dateValue));
            const items = collectStocktakingItems();

            if (!title) {
                UI.showError('عنوان انبارگردانی الزامی است.');
                return null;
            }

            if (!dateValidation.valid) {
                UI.showError(dateValidation.error);
                return null;
            }

            if (items.length === 0) {
                UI.showError('حداقل یک ردیف انبارگردانی ثبت کنید.');
                return null;
            }

            const btn = applyAfterSave ? $('#save-and-apply-stocktaking-btn') : $('#save-stocktaking-draft-btn');
            const originalHtml = btn.html();
            btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>در حال ذخیره...');

            const status = forceStatus || ($('#stocktaking-status').val() || 'draft');
            const payload = {
                id,
                title,
                date: UI.toGregorian ? UI.toGregorian('#stocktaking-date') : dateValue,
                warehouse_id,
                notes,
                status,
                items
            };

            const result = await Api.call('save_stocktaking', payload);
            btn.prop('disabled', false).html(originalHtml);

            if (!result || result.error) {
                UI.showError(result?.error || 'خطا در ذخیره انبارگردانی');
                return null;
            }

            stocktakingCurrentId = result.id;
            $('#stocktaking-id').val(result.id);
            stocktakingCurrentStatus = status;
            UI.showSuccess('انبارگردانی ذخیره شد.');
            loadStocktakingList();
            return result;
        }

        async function saveAndApplyStocktaking() {
            const saveResult = await saveStocktaking('in_progress', false);
            if (!saveResult) return;

            if (!await UI.confirmText('آیا از اعمال نهایی انبارگردانی مطمئن هستید؟ این عملیات موجودی را تغییر می‌دهد.')) return;

            const result = await Api.call('apply_stocktaking', { id: saveResult.id });
            if (result?.success) {
                UI.showSuccess(result.message || 'انبارگردانی اعمال شد.');
                $('#stocktaking-modal').modal('hide');
                loadStocktakingList();
            } else {
                UI.showError(result?.error || 'خطا در اعمال انبارگردانی');
            }
        }

        $('#stocktaking-warehouse').on('change', toggleStocktakingWarehouseColumn);

        $('#stocktaking-pane-print-btn').on('click', function () { openStocktakingPrintModal(); });
        $('#stocktaking-pane-blank-print-btn').on('click', printBlankFromStocktakingPane);
        $('#warehouse-inout-print-btn').on('click', function () { openWarehouseInOutPrintModal(); });
        $('#print-warehouse-inout-modal-btn').on('click', printFromWarehouseInOutModal);
        $('#load-stocktaking-print-preview-btn').on('click', loadStocktakingPrintPreview);
        $('#print-stocktaking-print-modal-btn').on('click', printFromStocktakingPrintModal);
        $('#print-stocktaking-blank-modal-btn').on('click', printBlankFromStocktakingPrintModal);
        $('#stocktaking-print-warehouse').on('change', toggleStocktakingPrintWarehouseColumn);
        $('#print-stocktaking-sheet-btn').on('click', printCurrentStocktakingSheet);
        $('#print-stocktaking-modal-footer-btn').on('click', printCurrentStocktakingSheet);
        $('#print-stocktaking-blank-sheet-btn').on('click', printCurrentStocktakingBlankSheet);
        $('#print-stocktaking-blank-footer-btn').on('click', printCurrentStocktakingBlankSheet);
        $(document).on('change input', '.stocktaking-blank-rows-input', function () {
            const raw = UI.toEnglishDigits(($(this).val() || '').toString().trim());
            const n = parseInt(raw, 10);
            if (Number.isFinite(n) && n >= 1 && n <= 500) {
                syncStocktakingBlankRowInputs(n);
            }
        });

        $('#new-stocktaking-btn').on('click', function () {
            openStocktakingModal();
        });

        $('#refresh-stocktaking-btn').on('click', loadStocktakingList);

        $('body').on('click', '.edit-stocktaking', function () {
            openStocktakingModal($(this).data('id'));
        });

        $('body').on('click', '.apply-stocktaking', async function () {
            const id = $(this).data('id');
            if (!await UI.confirmText('آیا از اعمال نتایج انبارگردانی مطمئن هستید؟ این عملیات غیرقابل بازگشت است.')) return;
            const result = await Api.call('apply_stocktaking', { id });
            if (result?.success) {
                UI.showSuccess(result.message || 'اعمال شد.');
                loadStocktakingList();
            } else {
                UI.showError(result?.error || 'خطا');
            }
        });

        $('body').on('click', '.cancel-stocktaking', async function () {
            const id = $(this).data('id');
            if (!await UI.confirmText('انبارگردانی لغو شود؟')) return;
            const result = await Api.call('cancel_stocktaking', { id });
            if (result?.success) {
                UI.showSuccess(result.message || 'لغو شد.');
                loadStocktakingList();
            } else {
                UI.showError(result?.error || 'خطا');
            }
        });

        $('body').on('click', '.delete-stocktaking', async function () {
            const id = $(this).data('id');
            if (!await UI.confirmText('حذف شود؟')) return;
            const result = await Api.call('delete_stocktaking', { id });
            if (result?.success) { UI.showSuccess('حذف شد.'); loadStocktakingList(); }
            else { UI.showError(result?.error || 'خطا'); }
        });

        $('#load-stocktaking-snapshot-btn').on('click', loadStocktakingSnapshot);
        $('#add-stocktaking-row-btn').on('click', function () { addStocktakingRow(); });
        $('#clear-stocktaking-rows-btn').on('click', function () { clearStocktakingRows(); addStocktakingRow(); });
        $('#save-stocktaking-draft-btn').on('click', function () { saveStocktaking('draft', false); });
        $('#save-and-apply-stocktaking-btn').on('click', saveAndApplyStocktaking);

        $('body').on('change input', '.stocktaking-product, .stocktaking-system, .stocktaking-counted, .stocktaking-dimensions, .stocktaking-row-notes', function () {
            const $row = $(this).closest('.stocktaking-row');
            refreshStocktakingRow($row);
        });

        $('body').on('click', '.remove-stocktaking-row', function () {
            $(this).closest('.stocktaking-row').remove();
            setStocktakingSummary();
            if ($('#stocktaking-items-tbody .stocktaking-row').length === 0) {
                addStocktakingRow();
            }
        });

        // === Installments ===
        $('button[data-bs-toggle="tab"][data-bs-target="#installments-pane"]').on('shown.bs.tab', loadInstallmentsList);

        // ثبت پرداخت قسط
        $('body').on('click', '.pay-installment', async function () {
            const id = $(this).data('id');
            const currentAmount = Number($(this).data('amount') || 0);
            const accounts = (App.getCache().accounts || []).map(a => ({ value: a.id, label: a.name }));
            const values = await UI.formDialog({
                title: 'ثبت پرداخت قسط',
                fields: [
                    { name: 'amount', label: `مبلغ (مانده: ${UI.formatCurrency(currentAmount)})`, type: 'money', value: currentAmount, required: true },
                    { name: 'date', label: 'تاریخ پرداخت', type: 'date', required: true },
                    { name: 'account_id', label: 'حساب بانک/صندوق', type: 'select', options: accounts, required: true },
                ],
                submitText: 'ثبت پرداخت',
            });
            if (!values) return;
            UI.showLoader();
            const result = await Api.call('pay_installment', { id, paid_amount: values.amount, paid_date: values.date, account_id: values.account_id });
            UI.hideLoader();
            if (result?.success) {
                UI.showSuccess(result.message || 'ثبت شد.');
                loadInstallmentsList();
                if (App.updateCache) await App.updateCache(['accounts']);
            }
        });

        // ایجاد طرح اقساط جدید
        $('#new-installment-btn').on('click', function () {
            $('#installment-modal').modal('show');
            $('#installment-form')[0]?.reset();
            $('#installment-rows').empty();
            $('#installment-start-date').val(UI.today());
            $('#installment-duration-months').val(6);
            $('#installment-count').val(6);
            $('#installment-invoice-search').val('');
            $('#installment-invoice-info').html('');
            addInstallmentRow();
            // بارگذاری فاکتورهای فروش و خرید
            populateInstallmentInvoices();
            // فعال‌سازی دیت‌پیکر شمسی
            setTimeout(() => UI.initializeDatepickers(), 100);
        });

        // افزودن ردیف قسط
        $('body').on('click', '#add-installment-row', function () {
            addInstallmentRow();
        });

        // حذف ردیف قسط
        $('body').on('click', '.remove-installment-row', function () {
            $(this).closest('.installment-row').remove();
        });

        // محاسبه خودکار اقساط (شمسی) - بر اساس مدت کل (ماه)
        $('body').on('click', '#auto-calc-installments', function () {
            const totalAmount = parseFloat($('#installment-total-amount').val()?.replace(/,/g, '') || 0);
            const count = parseInt($('#installment-count').val() || 0);
            const startDateStr = UI.toEnglishDigits($('#installment-start-date').val() || '');
            const durationMonths = parseInt($('#installment-duration-months').val() || 0);
            if (!totalAmount || !count || !startDateStr) {
                UI.showError('مبلغ کل، تعداد اقساط و تاریخ شروع الزامی است.');
                return;
            }
            if (!durationMonths || durationMonths < 1) {
                UI.showError('مدت کل (ماه) الزامی است.');
                return;
            }
            // اعتبارسنجی تاریخ شمسی
            const dateValidation = UI.validatePersianDate(startDateStr);
            if (!dateValidation.valid) {
                UI.showError(dateValidation.error);
                return;
            }
            const perAmount = Math.ceil(totalAmount / count);
            // تبدیل تاریخ شمسی شروع به میلادی
            const isoStr = UI.toGregorian('#installment-start-date');
            const baseDate = new Date(isoStr);
            if (isNaN(baseDate.getTime())) {
                UI.showError('تاریخ شروع نامعتبر است.');
                return;
            }
            // محاسبه تاریخ پایان بر اساس مدت کل (ماه)
            const endDate = new Date(baseDate);
            endDate.setMonth(endDate.getMonth() + durationMonths);
            // فاصله زمانی کل بر حسب روز
            const totalDays = Math.round((endDate - baseDate) / (1000 * 60 * 60 * 24));
            // توزیع مساوی اقساط در مدت تعیین‌شده
            // قسط اول: (totalDays / count) روز بعد از شروع
            // قسط آخر: دقیقاً در انتهای مدت
            const intervalDays = totalDays / count;
            $('#installment-rows').empty();
            for (let i = 0; i < count; i++) {
                const dt = new Date(baseDate);
                dt.setDate(dt.getDate() + Math.round((i + 1) * intervalDays));
                const jalaliDate = UI.gregorianToPersian(`${dt.getFullYear()}-${String(dt.getMonth()+1).padStart(2,'0')}-${String(dt.getDate()).padStart(2,'0')}`);
                addInstallmentRow(perAmount, jalaliDate, `قسط ${i + 1}`);
            }
            // نمایش خلاصه محاسبه
            const firstDate = $('#installment-rows .installment-row:first-child .inst-date').val();
            const lastDate = $('#installment-rows .installment-row:last-child .inst-date').val();
            $('#installment-calc-info').html(`<i class="bi bi-check-circle me-1"></i><strong>${count} قسط</strong> هر کدام <strong>${UI.formatCurrency(perAmount)}</strong> | اولین قسط: <strong>${firstDate}</strong> | آخرین قسط: <strong>${lastDate}</strong>`);
            // فعال‌سازی دیت‌پیکرهای شمسی روی ردیف‌های جدید
            setTimeout(() => UI.initializeDatepickers(), 100);
        });

        // ذخیره طرح اقساط
        $('body').on('click', '#save-installment-plan', async function () {
            const invoiceVal = $('#installment-invoice-id').val();
            if (!invoiceVal) {
                UI.showError('فاکتور را انتخاب کنید.');
                return;
            }
            const ivParts = invoiceVal.split('_');
            const invoiceType = ivParts[0];
            const invoiceId = parseInt(ivParts[1]);
            if (!invoiceId || !invoiceType) {
                UI.showError('فاکتور را انتخاب کنید.');
                return;
            }
            const installments = [];
            let dateError = false;
            $('#installment-rows .installment-row').each(function () {
                const amount = parseFloat($(this).find('.inst-amount').val()?.replace(/,/g, '') || 0);
                const jalaliDate = UI.toEnglishDigits($(this).find('.inst-date').val() || '');
                const description = $(this).find('.inst-desc').val() || '';
                if (amount > 0 && jalaliDate) {
                    // اعتبارسنجی و تبدیل تاریخ شمسی به میلادی
                    const v = UI.validatePersianDate(jalaliDate);
                    if (!v.valid) { dateError = true; UI.showError(`تاریخ نامعتبر: ${jalaliDate} - ${v.error}`); return; }
                    const due_date = UI.toGregorian($(this).find('.inst-date'));
                    installments.push({ amount, due_date, description });
                }
            });
            if (dateError) return;
            if (installments.length === 0) {
                UI.showError('حداقل یک قسط با مبلغ و تاریخ معتبر الزامی است.');
                return;
            }
            const result = await Api.call('save_installment_plan', {
                invoice_id: invoiceId,
                invoice_type: invoiceType,
                installments
            });
            if (result?.success) {
                UI.showSuccess(result.message || 'طرح تقسیط ذخیره شد.');
                $('#installment-modal').modal('hide');
                loadInstallmentsList();
            } else {
                UI.showError(result?.error || 'خطا در ذخیره طرح تقسیط');
            }
        });

        // حذف طرح اقساط
        $('body').on('click', '.delete-installment-plan', async function () {
            const invoiceId = $(this).data('invoice-id');
            const invoiceType = $(this).data('invoice-type');
            if (!await UI.confirmText('آیا از حذف این طرح اقساط مطمئن هستید؟')) return;
            const result = await Api.call('delete_installment_plan', {
                invoice_id: invoiceId,
                invoice_type: invoiceType
            });
            if (result?.success) {
                UI.showSuccess(result.message || 'حذف شد.');
                loadInstallmentsList();
            } else {
                UI.showError(result?.error || 'خطا');
            }
        });

        // === Bank Import (delegated to BankReconciliationManager) ===
        if (typeof BankReconciliationManager !== 'undefined') {
            BankReconciliationManager.init();
        }
    }

    // ---- Helper loaders for new panes ----
    async function loadStocktakingList() {
        const tbody = $('#stocktaking-list-tbody').html('<tr><td colspan="9">بارگذاری...</td></tr>');
        const result = await Api.call('get_stocktaking_list');
        tbody.empty();
        const items = result?.items || [];
        const totalSessions = items.length;
        const openSessions = items.filter(s => s.status === 'draft' || s.status === 'in_progress').length;
        const netDiff = items.reduce((sum, s) => sum + parseFloat(s.net_difference || 0), 0);
        $('#stocktaking-total-sessions').text(UI.formatNumber(totalSessions));
        $('#stocktaking-open-sessions').text(UI.formatNumber(openSessions));
        $('#stocktaking-net-diff').text(UI.formatNumber(netDiff));

        if (items && items.length > 0) {
            items.forEach(s => {
                let statusLabel = 'پیش‌نویس';
                let statusBadge = 'bg-warning text-dark';
                if (s.status === 'completed') {
                    statusLabel = 'اعمال شده';
                    statusBadge = 'bg-success';
                } else if (s.status === 'in_progress') {
                    statusLabel = 'در جریان';
                    statusBadge = 'bg-info';
                } else if (s.status === 'cancelled') {
                    statusLabel = 'لغو شده';
                    statusBadge = 'bg-secondary';
                }
                const canEdit = s.status === 'draft' || s.status === 'in_progress';
                const canDelete = s.status === 'draft' || s.status === 'cancelled';
                tbody.append(`<tr>
                    <td>${s.id}</td>
                    <td>${UI.gregorianToPersian(s.date)}</td>
                    <td>${s.warehouse_name || 'همه انبارها'}</td>
                    <td>${s.title || '-'}</td>
                    <td><span class="badge ${statusBadge}">${statusLabel}</span></td>
                    <td>${UI.formatNumber(s.item_count || 0)}</td>
                    <td>${UI.formatNumber(s.net_difference || 0)}</td>
                    <td><span class="text-success">+${UI.formatNumber(s.surplus_qty || 0)}</span> / <span class="text-danger">-${UI.formatNumber(s.shortage_qty || 0)}</span></td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary edit-stocktaking" data-id="${s.id}"><i class="bi bi-eye"></i></button>
                        ${canEdit ? `<button class="btn btn-sm btn-success apply-stocktaking" data-id="${s.id}"><i class="bi bi-check-lg"></i> اعمال</button>` : ''}
                        ${canEdit ? `<button class="btn btn-sm btn-outline-warning cancel-stocktaking" data-id="${s.id}"><i class="bi bi-x-circle"></i></button>` : ''}
                        ${canDelete ? `<button class="btn btn-sm btn-danger delete-stocktaking" data-id="${s.id}"><i class="bi bi-trash"></i></button>` : ''}
                    </td>
                </tr>`);
            });
        } else {
            tbody.html('<tr><td colspan="9" class="text-center text-muted">انبارگردانی ثبت نشده است.</td></tr>');
        }
    }

    async function loadInstallmentsList() {
        const tbody = $('#installments-list-tbody').html('<tr><td colspan="10">بارگذاری...</td></tr>');
        const result = await Api.call('get_installments');
        tbody.empty();
        const items = result?.items || [];
        if (items.length > 0) {
            // گروه‌بندی اقساط بر اساس فاکتور
            const grouped = {};
            items.forEach(inst => {
                const key = `${inst.invoice_type}_${inst.invoice_id}`;
                if (!grouped[key]) {
                    grouped[key] = {
                        invoice_id: inst.invoice_id,
                        invoice_type: inst.invoice_type,
                        invoice_number: inst.invoice_number,
                        person_name: inst.person_name,
                        person_phone: inst.person_phone,
                        installments: []
                    };
                }
                grouped[key].installments.push(inst);
            });

            Object.values(grouped).forEach(group => {
                const total = group.installments.length;
                const paidCount = group.installments.filter(i => i.status === 'paid').length;
                const allPaid = paidCount === total;
                const hasOverdue = group.installments.some(i => i.status === 'overdue');
                const typeLabel = group.invoice_type === 'sales' ? 'فروش' : 'خرید';

                // ردیف سربرگ گروه
                const groupBadge = allPaid ? '<span class="badge bg-success">تکمیل</span>' :
                    hasOverdue ? '<span class="badge bg-danger">سررسید گذشته</span>' :
                    '<span class="badge bg-info">فعال</span>';
                const totalAmount = group.installments.reduce((s, i) => s + parseFloat(i.amount || 0), 0);
                const totalPaid = group.installments.reduce((s, i) => s + parseFloat(i.paid_amount || 0), 0);

                tbody.append(`<tr class="table-light fw-bold">
                    <td colspan="2"><i class="bi bi-receipt me-1"></i>فاکتور ${typeLabel} #${UI.escapeHtml(group.invoice_number || group.invoice_id)}${group.person_name ? ` — ${UI.escapeHtml(group.person_name)}` : ''}${group.person_phone ? ` <small class="text-muted">(${UI.escapeHtml(group.person_phone)})</small>` : ''}</td>
                    <td>${total} قسط</td>
                    <td>جمع: ${UI.formatCurrency(totalAmount)}</td>
                    <td>پرداخت‌شده: ${UI.formatCurrency(totalPaid)}</td>
                    <td>مانده: ${UI.formatCurrency(totalAmount - totalPaid)}</td>
                    <td>${paidCount} / ${total}</td>
                    <td>${groupBadge}</td>
                    <td colspan="2">
                        ${!allPaid ? `<button class="btn btn-sm btn-outline-danger delete-installment-plan" data-invoice-id="${group.invoice_id}" data-invoice-type="${group.invoice_type}"><i class="bi bi-trash"></i> حذف طرح</button>` : ''}
                    </td>
                </tr>`);

                // ردیف‌های جزئیات هر قسط
                group.installments.forEach(inst => {
                    const remaining = parseFloat(inst.amount) - parseFloat(inst.paid_amount || 0);
                    const statusMap = {
                        'paid': '<span class="badge bg-success">پرداخت شده</span>',
                        'partially_paid': '<span class="badge bg-warning">پرداخت ناقص</span>',
                        'overdue': '<span class="badge bg-danger">سررسید گذشته</span>',
                        'pending': '<span class="badge bg-secondary">در انتظار</span>'
                    };
                    const statusBadge = statusMap[inst.status] || `<span class="badge bg-secondary">${inst.status}</span>`;
                    tbody.append(`<tr>
                        <td class="ps-4">${inst.installment_number}</td>
                        <td>${inst.description || `قسط ${inst.installment_number}`}</td>
                        <td>${UI.formatCurrency(inst.amount)}</td>
                        <td>${UI.formatCurrency(inst.paid_amount || 0)}</td>
                        <td>${UI.formatCurrency(remaining)}</td>
                        <td>${UI.gregorianToPersian ? UI.gregorianToPersian(inst.due_date) : inst.due_date}</td>
                        <td>${inst.paid_date ? UI.gregorianToPersian(inst.paid_date) : '-'}</td>
                        <td>${statusBadge}</td>
                        <td>
                            ${inst.status !== 'paid' ? `<button class="btn btn-sm btn-primary pay-installment" data-id="${inst.id}" data-amount="${remaining}"><i class="bi bi-cash me-1"></i>پرداخت</button>` : '<i class="bi bi-check-circle-fill text-success"></i>'}
                        </td>
                    </tr>`);
                });
            });
        } else {
            tbody.html('<tr><td colspan="10" class="text-center text-muted">طرح اقساطی یافت نشد.</td></tr>');
        }
    }

    function addInstallmentRow(amount = '', date = '', desc = '') {
        const idx = $('#installment-rows .installment-row').length + 1;
        if (!date) date = UI.today();
        $('#installment-rows').append(`
            <div class="installment-row d-flex gap-2 mb-2 align-items-center">
                <span class="badge bg-secondary">${idx}</span>
                <input type="number" class="form-control form-control-sm inst-amount" placeholder="مبلغ" value="${amount}" style="max-width:150px">
                <input type="text" class="form-control form-control-sm inst-date persian-datepicker" value="${date}" placeholder="1404/01/01" autocomplete="off" style="max-width:160px">
                <input type="text" class="form-control form-control-sm inst-desc" placeholder="توضیحات" value="${desc}">
                <button type="button" class="btn btn-sm btn-outline-danger remove-installment-row"><i class="bi bi-x"></i></button>
            </div>
        `);
        // فعال‌سازی دیت‌پیکر شمسی روی فیلد جدید
        const newDateInput = $('#installment-rows .installment-row:last-child .inst-date')[0];
        if (newDateInput && typeof BDDatepicker !== 'undefined') {
            new BDDatepicker(newDateInput);
        }
    }

    // داده‌های فاکتورها برای جستجو
    let _installmentAllInvoices = [];

    async function populateInstallmentInvoices() {
        const sel = $('#installment-invoice-id').empty();
        _installmentAllInvoices = [];

        // بارگذاری همه فاکتورهای فروش (limit: -1 = همه)
        const salesResult = await Api.call('get_paginated_data', { tableName: 'sales_invoices', page: 1, limit: -1, sortColumn: 'id', sortDirection: 'DESC' });
        const salesInvoices = salesResult?.data || [];
        if (Array.isArray(salesInvoices)) {
            salesInvoices.forEach(inv => {
                const remaining = parseFloat(inv.remainingAmount || (inv.totalAmount - (inv.discount || 0) + (inv.tax_amount || 0) - (inv.paidAmount || 0)));
                const personName = inv.customerName || inv.customer_name || '';
                _installmentAllInvoices.push({
                    id: inv.id,
                    type: 'sales',
                    label: `فروش #${inv.invoice_number || inv.id} - ${personName} - مانده: ${UI.formatCurrency(remaining)}`,
                    personName,
                    remaining,
                    totalAmount: parseFloat(inv.totalAmount || 0),
                    invoiceNumber: inv.invoice_number || inv.id,
                    date: inv.date
                });
            });
        }
        // بارگذاری همه فاکتورهای خرید
        const purchaseResult = await Api.call('get_paginated_data', { tableName: 'purchase_invoices', page: 1, limit: -1, sortColumn: 'id', sortDirection: 'DESC' });
        const purchaseInvoices = purchaseResult?.data || [];
        if (Array.isArray(purchaseInvoices)) {
            purchaseInvoices.forEach(inv => {
                const remaining = parseFloat(inv.remainingAmount || (inv.totalAmount - (inv.discount || 0) + (inv.tax_amount || 0) - (inv.paidAmount || 0)));
                const personName = inv.supplierName || inv.supplier_name || '';
                _installmentAllInvoices.push({
                    id: inv.id,
                    type: 'purchase',
                    label: `خرید #${inv.invoice_number || inv.id} - ${personName} - مانده: ${UI.formatCurrency(remaining)}`,
                    personName,
                    remaining,
                    totalAmount: parseFloat(inv.totalAmount || 0),
                    invoiceNumber: inv.invoice_number || inv.id,
                    date: inv.date
                });
            });
        }
        // رندر لیست
        renderInstallmentInvoiceList('');

        // جستجوی فاکتور
        $('#installment-invoice-search').off('input').on('input', function () {
            renderInstallmentInvoiceList($(this).val());
        });

        // انتخاب فاکتور
        sel.off('change').on('change', function () {
            const selectedVal = $(this).val();
            if (!selectedVal) return;
            const parts = selectedVal.split('_');
            const type = parts[0];
            const id = parseInt(parts[1]);
            $('#installment-invoice-type').val(type);
            const inv = _installmentAllInvoices.find(i => i.id === id && i.type === type);
            if (inv) {
                $('#installment-total-amount').val(Math.round(inv.remaining));
                const dateStr = inv.date ? UI.gregorianToPersian(inv.date) : '';
                $('#installment-invoice-info').html(
                    `<span class="text-success"><i class="bi bi-check-circle me-1"></i><strong>${inv.personName}</strong> | مبلغ کل: ${UI.formatCurrency(inv.totalAmount)} | مانده: <strong>${UI.formatCurrency(inv.remaining)}</strong>${dateStr ? ' | تاریخ: ' + dateStr : ''}</span>`
                );
            }
        });
    }

    function renderInstallmentInvoiceList(searchQuery) {
        const sel = $('#installment-invoice-id').empty();
        const q = (searchQuery || '').toLowerCase().trim();
        let filtered = _installmentAllInvoices;
        if (q) {
            filtered = _installmentAllInvoices.filter(inv =>
                inv.label.toLowerCase().includes(q) ||
                String(inv.id).includes(q) ||
                String(inv.invoiceNumber).includes(q) ||
                inv.personName.toLowerCase().includes(q)
            );
        }
        if (filtered.length === 0) {
            sel.append('<option value="" disabled>فاکتوری یافت نشد</option>');
        } else {
            filtered.forEach(inv => {
                sel.append(`<option value="${inv.type}_${inv.id}">${inv.label}</option>`);
            });
        }
    }

    let smsOutboxLoaded = false;
    let smsInboxLoaded = false;
    let smsLocalLogsLoaded = false;
    let smsStatsLoaded = false;
    let smsStatsPromise = null;

    function smsStatusBadge(category, label, code) {
        const text = label || (code != null ? `وضعیت ${code}` : 'نامشخص');
        let cls = 'bg-secondary';
        if (category === 'delivered') cls = 'bg-success';
        else if (category === 'failed') cls = 'bg-danger';
        else if (category === 'pending') cls = 'bg-info text-dark';
        return `<span class="badge ${cls}" title="کد: ${code != null ? code : '—'}">${UI.escapeHtml(text)}</span>`;
    }

    function formatSmsDate(value) {
        if (!value) return '—';
        try {
            const d = value instanceof Date ? value : new Date(String(value).replace(' ', 'T'));
            if (isNaN(d.getTime())) return UI.escapeHtml(String(value));
            return d.toLocaleString('fa-IR');
        } catch (e) {
            return UI.escapeHtml(String(value));
        }
    }

    function truncateSmsText(text, maxLen = 80) {
        const t = (text || '').toString().replace(/\s+/g, ' ').trim();
        if (!t) return '—';
        if (t.length <= maxLen) return UI.escapeHtml(t);
        return `<span title="${UI.escapeHtml(t)}">${UI.escapeHtml(t.slice(0, maxLen))}…</span>`;
    }

    function smsSourceLabel(source, templateKey) {
        const map = {
            invoice: 'فاکتور',
            test: 'آزمایشی',
            app: 'سیستم',
        };
        let label = map[source] || source || '—';
        if (templateKey === 'ready') label += ' / آماده تحویل';
        else if (templateKey === 'sales') label += ' / فروش';
        else if (templateKey === 'test') label += '';
        return UI.escapeHtml(label);
    }

    async function loadSmsSettings() {
        UI.showLoader();
        try {
            const res = await Api.call('get_sms_settings');
            if (!res || !res.settings) return;
            const s = res.settings;
            $('#sms-enabled').prop('checked', s.sms_enabled == '1');
            $('#sms-mode').val(s.sms_mode === 'simple' ? 'simple' : 'verify');
            $('#sms-sender').val(s.sms_sender || '');
            $('#sms-template-sales').val(s.sms_template_sales || '');
            $('#sms-template-ready').val(s.sms_template_ready || '');
            $('#sms-verify-template-sales').val(s.sms_verify_template_sales || 'invoicesales');
            $('#sms-verify-template-ready').val(s.sms_verify_template_ready || 'invoiceready');
            $('#sms-check-alert-enabled').prop('checked', s.sms_check_alert_enabled == '1');
            $('#sms-check-alert-days').val(s.sms_check_alert_days || 3);
            $('#sms-check-alert-template').val(s.sms_check_alert_template || '');
            $('#sms-check-alert-verify-template').val(s.sms_check_alert_verify_template || 'checkdue');

            let alertTypes = [];
            let alertStatuses = [];
            let partnerIds = [];
            try { alertTypes = JSON.parse(s.sms_check_alert_types || '[]'); } catch (e) { alertTypes = []; }
            try { alertStatuses = JSON.parse(s.sms_check_alert_statuses || '[]'); } catch (e) { alertStatuses = []; }
            try { partnerIds = JSON.parse(s.sms_check_alert_partner_ids || '[]').map(String); } catch (e) { partnerIds = []; }
            if (!alertTypes.length) alertTypes = ['received', 'payable'];
            if (!alertStatuses.length) alertStatuses = ['in_hand', 'payable'];
            $('#sms-check-type-received').prop('checked', alertTypes.includes('received'));
            $('#sms-check-type-payable').prop('checked', alertTypes.includes('payable'));
            $('#sms-check-status-in-hand').prop('checked', alertStatuses.includes('in_hand'));
            $('#sms-check-status-payable').prop('checked', alertStatuses.includes('payable'));
            $('#sms-check-status-endorsed').prop('checked', alertStatuses.includes('endorsed'));

            const partnerSelect = $('#sms-check-alert-partners');
            if (partnerSelect.data('select2')) {
                partnerSelect.select2('destroy');
            }
            partnerSelect.empty();
            (App.getCache().partners || []).forEach(p => {
                const phoneNote = p.phone ? '' : ' (بدون تلفن)';
                partnerSelect.append(`<option value="${p.id}">${UI.escapeHtml(p.name)}${phoneNote}</option>`);
            });
            partnerSelect.val(partnerIds).select2({
                theme: 'bootstrap-5',
                dir: 'rtl',
                placeholder: 'انتخاب شرکا...',
                width: '100%'
            });

            $('#sms-api-key').val('').attr('placeholder', s.sms_api_key_set
                ? 'کلید فعلی ذخیره شده — برای تغییر، کلید جدید وارد کنید'
                : 'کلید API را وارد کنید');
            $('#sms-api-key-hint').text(s.sms_api_key_set
                ? `کلید تنظیم شده: ${s.sms_api_key_masked}`
                : 'هنوز کلید API ذخیره نشده است.');
            updateSmsModeUI();
            if (res.credit !== null && res.credit !== undefined) {
                $('#sms-credit-badge').removeClass('bg-secondary bg-danger bg-warning').addClass('bg-success')
                    .text('اعتبار: ' + Number(res.credit).toLocaleString('fa-IR'));
            } else if (s.sms_api_key_set) {
                $('#sms-credit-badge').removeClass('bg-success bg-secondary').addClass('bg-warning text-dark')
                    .text('اعتبار: نامشخص');
            } else {
                $('#sms-credit-badge').removeClass('bg-success bg-warning').addClass('bg-secondary')
                    .text('اعتبار: —');
            }
            if (res.account && res.account.expiredate_iso) {
                $('#sms-expire-badge').removeClass('bg-secondary').addClass('bg-dark')
                    .text('انقضا: ' + formatSmsDate(res.account.expiredate_iso));
            } else {
                $('#sms-expire-badge').removeClass('bg-dark').addClass('bg-secondary').text('انقضا: —');
            }
        } finally {
            UI.hideLoader();
        }
    }

    function updateSmsModeUI() {
        const isVerify = $('#sms-mode').val() !== 'simple';
        $('#sms-verify-guide-card').toggle(isVerify);
        // Sender is useful for inbox/outbox filters even in verify mode — keep visible but optional
        $('#sms-sender-wrap').show();
        $('#sms-test-message-wrap').toggle(!isVerify);
    }

    async function handleSmsSettingsSubmit(e) {
        e.preventDefault();
        UI.hideModalError('#sms-settings-form');
        const payload = {
            sms_enabled: $('#sms-enabled').is(':checked') ? '1' : '0',
            sms_auto_send_sales: '0',
            sms_mode: $('#sms-mode').val() === 'simple' ? 'simple' : 'verify',
            sms_sender: $('#sms-sender').val().trim(),
            sms_template_sales: $('#sms-template-sales').val(),
            sms_template_ready: $('#sms-template-ready').val(),
            sms_verify_template_sales: $('#sms-verify-template-sales').val().trim(),
            sms_verify_template_ready: $('#sms-verify-template-ready').val().trim(),
            sms_check_alert_enabled: $('#sms-check-alert-enabled').is(':checked') ? '1' : '0',
            sms_check_alert_days: $('#sms-check-alert-days').val(),
            sms_check_alert_template: $('#sms-check-alert-template').val(),
            sms_check_alert_verify_template: $('#sms-check-alert-verify-template').val().trim(),
            sms_check_alert_partner_ids: $('#sms-check-alert-partners').val() || [],
            sms_check_alert_types: [
                $('#sms-check-type-received').is(':checked') ? 'received' : null,
                $('#sms-check-type-payable').is(':checked') ? 'payable' : null,
            ].filter(Boolean),
            sms_check_alert_statuses: [
                $('#sms-check-status-in-hand').is(':checked') ? 'in_hand' : null,
                $('#sms-check-status-payable').is(':checked') ? 'payable' : null,
                $('#sms-check-status-endorsed').is(':checked') ? 'endorsed' : null,
            ].filter(Boolean),
        };
        const apiKey = $('#sms-api-key').val().trim();
        if (apiKey && apiKey.indexOf('*') === -1) {
            payload.sms_api_key = apiKey;
        }
        UI.showLoader();
        const res = await Api.call('save_sms_settings', payload);
        UI.hideLoader();
        if (res?.success) {
            UI.showSuccess(res.message || 'تنظیمات پیامک ذخیره شد.');
            $('#sms-api-key').val('');
            await loadSmsSettings();
        }
    }

    async function handleSmsTestSend() {
        const receptor = $('#sms-test-receptor').val().trim();
        const message = $('#sms-test-message').val().trim();
        if (!receptor) {
            UI.showError('شماره موبایل را وارد کنید.');
            return;
        }
        UI.confirmAction(`آیا از ارسال پیامک آزمایشی به ${receptor} مطمئن هستید؟`, async (confirmed) => {
            if (!confirmed) return;
            UI.showLoader();
            const res = await Api.call('test_sms', { receptor, message, user_confirmed: true });
            UI.hideLoader();
            if (res?.success) {
                UI.showSuccess(res.message || 'پیامک آزمایشی ارسال شد.');
                smsLocalLogsLoaded = false;
                await loadSmsLocalLogs(true);
                await loadSmsOutboxStats(true);
            }
        });
    }

    async function loadSmsLocalLogs(force) {
        if (smsLocalLogsLoaded && !force) return;
        const body = $('#sms-local-logs-body');
        body.html('<tr><td colspan="6" class="text-center text-muted py-4">در حال بارگذاری…</td></tr>');
        const res = await Api.call('get_sms_logs', { limit: 50, offset: 0, refresh_status: force ? 1 : 0 });
        if (!res?.success) {
            body.html(`<tr><td colspan="6" class="text-center text-danger py-4">${UI.escapeHtml(res?.error || 'خطا در دریافت تاریخچه')}</td></tr>`);
            return;
        }
        smsLocalLogsLoaded = true;
        const logs = res.logs || [];
        $('#sms-local-logs-count').text(`${Number(res.total || logs.length).toLocaleString('fa-IR')} پیامک ثبت‌شده`);
        if (!logs.length) {
            body.html('<tr><td colspan="6" class="text-center text-muted py-4">هنوز پیامکی از این سیستم ارسال نشده است.</td></tr>');
            return;
        }
        body.empty();
        logs.forEach(log => {
            const msgPreview = log.message
                || (log.verify_template ? `الگو: ${log.verify_template}` : '—');
            body.append(`<tr>
                <td class="text-nowrap small">${formatSmsDate(log.sent_at || log.created_at)}</td>
                <td dir="ltr" class="text-nowrap">${UI.escapeHtml(log.receptor || '—')}</td>
                <td class="small">${truncateSmsText(msgPreview, 70)}</td>
                <td class="small">${smsSourceLabel(log.source, log.template_key)}</td>
                <td>${smsStatusBadge(log.status_category, log.status_label || log.status_text, log.status)}</td>
                <td dir="ltr" class="small text-muted">${UI.escapeHtml(log.messageid || '—')}</td>
            </tr>`);
        });
    }

    async function handleSmsRefreshStatus() {
        UI.showLoader();
        const res = await Api.call('refresh_sms_status', {});
        UI.hideLoader();
        if (res?.success) {
            UI.showSuccess(res.message || 'وضعیت‌ها به‌روز شد.');
            smsLocalLogsLoaded = false;
            await loadSmsLocalLogs(true);
        }
    }

    function getSmsSenderFromForm() {
        return ($('#sms-sender').val() || '').toString().trim();
    }

    async function loadSmsOutbox(force) {
        if (smsOutboxLoaded && !force) return;
        const body = $('#sms-outbox-body');
        body.html('<tr><td colspan="6" class="text-center text-muted py-4">در حال بارگذاری…</td></tr>');
        const payload = { pagesize: 50 };
        const sender = getSmsSenderFromForm();
        if (sender) payload.sender = sender;
        const res = await Api.call('get_kavenegar_outbox', payload);
        if (!res?.success) {
            body.html(`<tr><td colspan="6" class="text-center text-danger py-4">${UI.escapeHtml(res?.error || 'خطا در دریافت صندوق خروجی')}</td></tr>`);
            return;
        }
        smsOutboxLoaded = true;
        if (res.hint && !(res.messages || []).length) {
            body.html(`<tr><td colspan="6" class="text-center text-muted py-4">${UI.escapeHtml(res.hint)}</td></tr>`);
            return;
        }
        const messages = res.messages || [];
        if (!messages.length) {
            body.html('<tr><td colspan="6" class="text-center text-muted py-4">پیامکی در صندوق خروجی یافت نشد.</td></tr>');
            return;
        }
        body.empty();
        messages.forEach(m => {
            body.append(`<tr>
                <td class="text-nowrap small">${formatSmsDate(m.date_iso)}</td>
                <td dir="ltr" class="text-nowrap">${UI.escapeHtml(m.receptor || '—')}</td>
                <td dir="ltr" class="text-nowrap small">${UI.escapeHtml(m.sender || '—')}</td>
                <td class="small">${truncateSmsText(m.message, 70)}</td>
                <td>${smsStatusBadge(m.status_category, m.status_label, m.status)}</td>
                <td class="small">${m.cost != null ? Number(m.cost).toLocaleString('fa-IR') : '—'}</td>
            </tr>`);
        });
    }

    async function loadSmsInbox(force) {
        if (smsInboxLoaded && !force) return;
        const body = $('#sms-inbox-body');
        body.html('<tr><td colspan="4" class="text-center text-muted py-4">در حال بارگذاری…</td></tr>');
        const payload = { isread: 2 };
        const sender = getSmsSenderFromForm();
        if (sender) {
            payload.linenumber = sender;
            payload.sender = sender;
        }
        const res = await Api.call('get_kavenegar_inbox', payload);
        if (!res?.success) {
            body.html(`<tr><td colspan="4" class="text-center text-muted py-4">${UI.escapeHtml(res?.error || 'خطا در دریافت صندوق ورودی')}</td></tr>`);
            return;
        }
        smsInboxLoaded = true;
        if (res.hint && !(res.messages || []).length) {
            body.html(`<tr><td colspan="4" class="text-center text-muted py-4">${UI.escapeHtml(res.hint)}</td></tr>`);
            return;
        }
        renderInboxRows(body, res.messages || []);
    }

    function renderInboxRows(body, messages) {
        if (!messages.length) {
            body.html('<tr><td colspan="4" class="text-center text-muted py-4">پیامک ورودی‌ای یافت نشد (یا خط اختصاصی تنظیم نشده).</td></tr>');
            return;
        }
        body.empty();
        messages.forEach(m => {
            body.append(`<tr>
                <td class="text-nowrap small">${formatSmsDate(m.date_iso)}</td>
                <td dir="ltr" class="text-nowrap">${UI.escapeHtml(m.sender || '—')}</td>
                <td dir="ltr" class="text-nowrap small">${UI.escapeHtml(m.receptor || '—')}</td>
                <td class="small">${truncateSmsText(m.message, 100)}</td>
            </tr>`);
        });
    }

    function updateZarinpalEnabledLabel() {
        const on = $('#zarinpal-enabled').is(':checked');
        $('#zarinpal-enabled-label').text(on ? 'فعال' : 'غیرفعال');
        $('#zarinpal-enabled-hint').text(
            on
                ? 'پرداخت آنلاین روی لینک فاکتور مشتری نمایش داده می‌شود.'
                : 'پرداخت آنلاین روی لینک فاکتور مشتری خاموش است.'
        );
    }

    function applyZarinpalSettingsToForm(s) {
        $('#zarinpal-enabled').prop('checked', String(s.zarinpal_enabled) === '1');
        $('#zarinpal-sandbox').prop('checked', String(s.zarinpal_sandbox) === '1');
        $('#zarinpal-currency-unit').val(s.zarinpal_currency_unit === 'rial' ? 'rial' : 'toman');
        $('#zarinpal-merchant-id').val('');
        $('#zarinpal-merchant-hint').text(
            s.has_merchant_id
                ? `وضعیت مرچنت‌آیدی: ذخیره شده (${s.zarinpal_merchant_id_masked || '****'})`
                : 'وضعیت مرچنت‌آیدی: هنوز تنظیم نشده'
        );
        const $acc = $('#zarinpal-deposit-account').empty().append('<option value="">— انتخاب حساب —</option>');
        const accounts = (typeof App !== 'undefined' && App.getCache) ? (App.getCache().accounts || []) : [];
        accounts.forEach((a) => {
            $acc.append(`<option value="${a.id}">${a.name || ('حساب #' + a.id)}</option>`);
        });
        if (s.zarinpal_deposit_account_id) {
            $acc.val(String(s.zarinpal_deposit_account_id));
        }
        updateZarinpalEnabledLabel();
    }

    async function loadOnlinePayments() {
        const tbody = $('#online-payments-tbody');
        if (!tbody.length) return;
        tbody.html('<tr><td colspan="8" class="text-center text-muted">در حال بارگذاری...</td></tr>');
        const res = await Api.call('get_online_payments', { status: $('#online-payments-status').val() || '' }, false);
        const items = res?.items || [];
        if (!items.length) {
            tbody.html('<tr><td colspan="8" class="text-center text-muted">موردی یافت نشد.</td></tr>');
            return;
        }
        const labels = { paid: ['ثبت‌شده', 'success'], pending: ['در انتظار', 'secondary'], failed: ['ناموفق/لغو', 'danger'], review: ['نیازمند بررسی', 'warning'] };
        tbody.html(items.map(op => {
            const st = labels[op.status] || [op.status, 'secondary'];
            const created = (op.created_at || '').slice(0, 10);
            const canRetry = ['pending', 'review', 'failed'].includes(op.status) && op.authority;
            return `<tr>
                <td>${op.id}</td>
                <td>${created ? UI.gregorianToPersian(created) : '-'}</td>
                <td>#${UI.escapeHtml(op.invoice_number || op.invoice_id)}</td>
                <td>${UI.escapeHtml(op.customer_name || '-')}</td>
                <td>${UI.formatCurrency(op.amount)}</td>
                <td><span class="badge bg-${st[1]}">${st[0]}</span></td>
                <td dir="ltr">${UI.escapeHtml(op.ref_id || '-')}</td>
                <td>${canRetry ? `<button class="btn btn-sm btn-outline-primary retry-online-payment" data-id="${op.id}" title="استعلام و ثبت"><i class="bi bi-search"></i></button>` : ''}</td>
            </tr>`;
        }).join(''));
    }

    async function loadZarinpalSettings() {
        UI.showLoader();
        try {
            const res = await Api.call('get_zarinpal_settings', {});
            if (!res?.success) {
                $('#zarinpal-enabled-hint').text(res?.error || 'بارگذاری تنظیمات ناموفق بود.');
                return;
            }
            applyZarinpalSettingsToForm(res.settings || {});
        } finally {
            UI.hideLoader();
        }
    }

    async function saveZarinpalEnabledOnly(enabled) {
        const payload = {
            zarinpal_enabled: enabled ? '1' : '0',
            zarinpal_sandbox: $('#zarinpal-sandbox').is(':checked') ? '1' : '0',
            zarinpal_currency_unit: $('#zarinpal-currency-unit').val() === 'rial' ? 'rial' : 'toman',
            zarinpal_deposit_account_id: $('#zarinpal-deposit-account').val() || '',
        };
        $('#zarinpal-enabled').prop('disabled', true);
        const res = await Api.call('save_zarinpal_settings', payload);
        $('#zarinpal-enabled').prop('disabled', false);
        if (res?.success) {
            if (res.settings) applyZarinpalSettingsToForm(res.settings);
            else updateZarinpalEnabledLabel();
            UI.showSuccess(enabled ? 'پرداخت آنلاین فعال شد.' : 'پرداخت آنلاین غیرفعال شد.');
            return;
        }
        // برگرداندن سوییچ در صورت خطا
        $('#zarinpal-enabled').prop('checked', !enabled);
        updateZarinpalEnabledLabel();
        UI.showError(res?.error || 'ذخیره وضعیت فعال‌سازی ناموفق بود.');
    }

    async function handleZarinpalSettingsSubmit(e) {
        e.preventDefault();
        const payload = {
            zarinpal_enabled: $('#zarinpal-enabled').is(':checked') ? '1' : '0',
            zarinpal_sandbox: $('#zarinpal-sandbox').is(':checked') ? '1' : '0',
            zarinpal_currency_unit: $('#zarinpal-currency-unit').val() === 'rial' ? 'rial' : 'toman',
            zarinpal_deposit_account_id: $('#zarinpal-deposit-account').val() || '',
        };
        const merchant = $('#zarinpal-merchant-id').val().trim();
        if (merchant && merchant.indexOf('*') === -1) {
            payload.zarinpal_merchant_id = merchant;
        }
        UI.showLoader();
        const res = await Api.call('save_zarinpal_settings', payload);
        UI.hideLoader();
        if (res?.success) {
            UI.showSuccess(res.message || 'تنظیمات درگاه ذخیره شد.');
            if (res.settings) applyZarinpalSettingsToForm(res.settings);
            else await loadZarinpalSettings();
        }
    }

    async function loadIntegrationSettings() {
        UI.showLoader();
        try {
            const [settingsRes, tokensRes] = await Promise.all([
                Api.call('integration_get_settings', {}),
                Api.call('integration_list_api_tokens', {}),
            ]);
            const s = settingsRes?.settings || {};
            $('#integration-medusa-webhook-url').val(s.medusa_webhook_url || '');
            $('#integration-webhook-secret').val('');
            // Server already falls back to the configured DEFAULT_ENTITY_ID.
            $('#integration-default-entity').val(s.default_entity_id || '');
            $('#integration-sso-users').val(s.sso_allowed_user_ids || '');
            $('#integration-secret-hint').text(
                s.has_webhook_secret ? 'وضعیت رمز: ذخیره شده است' : 'وضعیت رمز: هنوز تنظیم نشده'
            );

            const $acc = $('#integration-online-account').empty().append('<option value="">— انتخاب حساب —</option>');
            const accounts = (typeof App !== 'undefined' && App.getCache) ? (App.getCache().accounts || []) : [];
            accounts.forEach((a) => {
                $acc.append(`<option value="${a.id}">${UI.escapeHtml(a.name || ('حساب #' + a.id))}</option>`);
            });
            if (s.default_online_account_id) {
                $acc.val(String(s.default_online_account_id));
            }

            renderIntegrationTokens(tokensRes?.tokens || []);
        } finally {
            UI.hideLoader();
        }
    }

    function renderIntegrationTokens(tokens) {
        const $tb = $('#integration-tokens-tbody').empty();
        if (!tokens.length) {
            $tb.append('<tr><td colspan="4" class="text-muted text-center">توکنی ساخته نشده است.</td></tr>');
            return;
        }
        tokens.forEach((t) => {
            const active = String(t.is_active) === '1' || t.is_active === true;
            $tb.append(`<tr class="${active ? '' : 'table-secondary'}">
                <td>${UI.escapeHtml(t.name || '—')}${active ? '' : ' <span class="badge bg-secondary">غیرفعال</span>'}</td>
                <td dir="ltr"><code>${t.token_prefix || ''}…</code></td>
                <td>${t.entity_id || '—'}</td>
                <td class="text-end">
                    ${active ? `<button type="button" class="btn btn-sm btn-outline-danger btn-revoke-token" data-id="${t.id}" title="ابطال"><i class="bi bi-x-lg"></i></button>` : ''}
                </td>
            </tr>`);
        });
    }

    async function handleIntegrationSettingsSubmit(e) {
        e.preventDefault();
        const payload = {
            medusa_webhook_url: $('#integration-medusa-webhook-url').val().trim(),
            default_online_account_id: $('#integration-online-account').val() || '',
            // Empty string = keep the server-side default (DEFAULT_ENTITY_ID).
            default_entity_id: $('#integration-default-entity').val() || '',
            sso_allowed_user_ids: ($('#integration-sso-users').val() || '').trim(),
        };
        const secret = $('#integration-webhook-secret').val().trim();
        if (secret) payload.webhook_secret = secret;

        UI.showLoader();
        const res = await Api.call('integration_save_settings', payload);
        UI.hideLoader();
        if (res?.success) {
            UI.showSuccess('تنظیمات اتصال ذخیره شد.');
            $('#integration-webhook-secret').val('');
            await loadIntegrationSettings();
        }
    }

    async function createIntegrationToken() {
        const form = await UI.formDialog({
            title: 'ساخت توکن اتصال',
            fields: [{ name: 'name', label: 'نام توکن', type: 'text', value: 'medusa', required: true, placeholder: 'مثلاً medusa' }],
            submitText: 'ساخت توکن',
        });
        if (!form) return;
        const name = form.name;
        // Server resolves the entity when omitted (settings → DEFAULT_ENTITY_ID).
        const entityId = parseInt($('#integration-default-entity').val(), 10) || 0;
        UI.showLoader();
        const res = await Api.call('integration_create_api_token', {
            name: (name || 'medusa').trim(),
            entity_id: entityId > 0 ? entityId : undefined,
            scopes: ['*'],
        });
        UI.hideLoader();
        if (res?.success && res.token) {
            $('#integration-new-token-box').removeClass('d-none');
            $('#integration-new-token-value').text(res.token);
            try {
                await navigator.clipboard.writeText(res.token);
                UI.showSuccess('توکن ساخته و در کلیپ‌بورد کپی شد.');
            } catch (_) {
                UI.showSuccess('توکن ساخته شد. همین الان آن را کپی کنید.');
            }
            const list = await Api.call('integration_list_api_tokens', {});
            renderIntegrationTokens(list?.tokens || []);
        }
    }

    async function revokeIntegrationToken(id) {
        if (!id) return;
        UI.confirmAction('این توکن باطل شود؟ فروشگاه دیگر با آن وصل نمی‌شود.', async (ok) => {
            if (!ok) return;
            UI.showLoader();
            const res = await Api.call('integration_revoke_api_token', { id: parseInt(id, 10) });
            UI.hideLoader();
            if (res?.success) {
                UI.showSuccess('توکن باطل شد.');
                const list = await Api.call('integration_list_api_tokens', {});
                renderIntegrationTokens(list?.tokens || []);
            }
        });
    }

    async function pingIntegration() {
        UI.showLoader();
        const res = await Api.call('integration_ping', {});
        UI.hideLoader();
        if (res?.success) {
            UI.showSuccess(`سرویس یکپارچه‌سازی فعال است — ${res.time || ''}`);
        }
    }

    async function loadSmsOutboxStats(force) {
        if (smsStatsLoaded && !force) return;
        if (smsStatsPromise) return smsStatsPromise;
        smsStatsPromise = (async () => {
            try {
                const res = await Api.call('get_kavenegar_outbox_stats', {
                    days: 7,
                    ...(getSmsSenderFromForm() ? { sender: getSmsSenderFromForm() } : {})
                });
                if (!res?.success) {
                    $('#sms-stat-total, #sms-stat-delivered, #sms-stat-undelivered').text('—');
                    return;
                }
                smsStatsLoaded = true;
                const fmt = (n) => (n === null || n === undefined) ? '—' : Number(n).toLocaleString('fa-IR');
                $('#sms-stat-total').text(fmt(res.total));
                $('#sms-stat-delivered').text(fmt(res.delivered));
                $('#sms-stat-undelivered').text(fmt(res.undelivered));
                if (res.hint) {
                    $('#sms-stats-hint').text(res.hint);
                } else if (res.source === 'local') {
                    $('#sms-stats-hint').text(`آمار ${Number(res.days || 7).toLocaleString('fa-IR')} روز اخیر از تاریخچه سیستم`);
                } else {
                    $('#sms-stats-hint').text(`آمار ${Number(res.days || 7).toLocaleString('fa-IR')} روز اخیر از کاوه‌نگار`);
                }
            } finally {
                smsStatsPromise = null;
            }
        })();
        return smsStatsPromise;
    }

    return {
        init,
        load,
        loadSmsSettings,
        loadSmsLocalLogs,
        config
    };
})();