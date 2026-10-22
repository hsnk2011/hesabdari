// /js/backup-manager.js

const BackupManager = (function () {
    let backupSettings = {};
    let businessEntities = [];

    function includeImagesChecked() {
        return $('#backup-include-images').is(':checked');
    }

    function formatSize(bytes) {
        const n = Number(bytes) || 0;
        if (n >= 1024 * 1024) return (n / (1024 * 1024)).toFixed(2) + ' MB';
        return (n / 1024).toFixed(2) + ' KB';
    }

    function renderBackupList(backups) {
        const tbody = $('#existing-backups-tbody').empty();
        if (!backups || backups.length === 0) {
            tbody.html('<tr><td colspan="4" class="text-center text-muted">هیچ فایل پشتیبانی یافت نشد.</td></tr>');
            return;
        }

        backups.forEach(backup => {
            const downloadUrl = `${AppConfig.API_URL}?action=download_backup&filename=${encodeURIComponent(backup.filename)}`;
            const row = $(`<tr>
                <td>${UI.escapeHtml(backup.filename)}<br><small class="text-muted">${UI.escapeHtml(backup.type || '')}</small></td>
                <td>${UI.gregorianToPersian(backup.date)}</td>
                <td>${formatSize(backup.size)}</td>
                <td>
                    <div class="btn-group">
                        <a href="${downloadUrl}" class="btn btn-sm btn-success" title="دانلود"><i class="bi bi-download"></i></a>
                        <button class="btn btn-sm btn-outline-info btn-preview-backup" data-filename="${UI.escapeHtml(backup.filename)}" title="پیش‌نمایش محتوا"><i class="bi bi-eye"></i></button>
                        <button class="btn btn-sm btn-warning btn-restore-backup" data-filename="${UI.escapeHtml(backup.filename)}" data-has-images="${backup.has_images ? 1 : 0}" title="بازیابی از این فایل"><i class="bi bi-hdd-stack-fill"></i></button>
                        <button class="btn btn-sm btn-danger btn-delete-backup" data-filename="${UI.escapeHtml(backup.filename)}" title="حذف"><i class="bi bi-trash"></i></button>
                    </div>
                </td>
            </tr>`);
            tbody.append(row);
        });
    }

    async function load() {
        UI.showLoader();
        const settings = App.getCache().settings;

        backupSettings = {
            auto_backup_enabled: settings.auto_backup_enabled == '1',
            auto_backup_retention_days: settings.auto_backup_retention_days || 7,
            auto_backup_include_images: settings.auto_backup_include_images == '1'
        };
        $('#auto-backup-enabled').prop('checked', backupSettings.auto_backup_enabled);
        $('#auto-backup-retention-days').val(backupSettings.auto_backup_retention_days);
        $('#auto-backup-include-images').prop('checked', !!backupSettings.auto_backup_include_images);

        businessEntities = App.getAppState().businessEntities;
        const entitySelect = $('#backup-entity-select').empty().append('<option value="">-- انتخاب کنید --</option>');
        businessEntities.forEach(e => entitySelect.append(`<option value="${e.id}">${UI.escapeHtml(e.name)}</option>`));

        const backups = await Api.call('list_backups');
        if (backups) {
            renderBackupList(backups);
        }
        UI.hideLoader();
    }

    async function createBackup(type, options = {}) {
        const withImages = includeImagesChecked();
        let message = `آیا از ایجاد یک پشتیبان ${type === 'full' ? 'کامل' : 'جزئی'}${withImages ? ' همراه با تصاویر محصولات' : ' (بدون تصاویر)'} مطمئن هستید؟`;
        UI.confirmAction(message, async (confirmed) => {
            if (confirmed) {
                UI.showLoader(withImages
                    ? 'در حال ایجاد پشتیبان با تصاویر محصولات...\nاین کار ممکن است چند دقیقه طول بکشد.'
                    : 'در حال ایجاد پشتیبان...');
                const data = {
                    type,
                    include_images: withImages ? 1 : 0,
                    ...options
                };
                const result = await Api.call('create_backup', data);
                if (result?.success) {
                    let msg = result.include_images
                        ? `پشتیبان با ${result.image_files || 0} فایل تصویر ایجاد شد.`
                        : 'پشتیبان با موفقیت ایجاد شد.';
                    if (result.remote_backup === 'success') {
                        msg += ' نسخه روی سرور دیگر هم ذخیره شد.';
                    } else if (result.remote_backup === 'failed') {
                        UI.showError(result.remote_backup_error || 'ارسال بکاپ به سرور دیگر ناموفق بود.');
                    }
                    if (result.email_backup === 'success') {
                        msg += ' ' + (result.email_backup_message || 'به ایمیل هم ارسال شد.');
                    } else if (result.email_backup === 'failed') {
                        UI.showError(result.email_backup_error || 'ارسال ایمیل پشتیبان ناموفق بود.');
                    }
                    UI.showSuccess(msg);
                    load();
                }
                UI.hideLoader();
            }
        });
    }

    async function deleteBackup(filename) {
        UI.confirmAction(`آیا از حذف فایل پشتیبان "${filename}" مطمئن هستید؟ این عملیات غیرقابل بازگشت است.`, async (confirmed) => {
            if (confirmed) {
                UI.showLoader();
                const result = await Api.call('delete_backup', { filename });
                if (result?.success) {
                    UI.showSuccess('فایل با موفقیت حذف شد.');
                    load();
                }
                UI.hideLoader();
            }
        });
    }

    const isEncryptedName = (name) => /\.enc$/i.test(String(name || ''));

    async function askBackupPassword(filename) {
        if (!isEncryptedName(filename)) return '';
        const v = await UI.formDialog({
            title: 'رمز پشتیبان',
            message: '<div class="small text-muted">این فایل رمزنگاری شده است. اگر رمز با رمز فعلی تنظیمات یکی است، خالی بگذارید.</div>',
            fields: [{ name: 'password', label: 'رمز پشتیبان', type: 'text' }],
            submitText: 'ادامه',
        });
        return v === null ? null : (v.password || '');
    }

    function renderPreviewHtml(res) {
        const rows = Object.keys(res.tables || {}).map(t => {
            const inFile = res.tables[t];
            const now = res.current ? res.current[t] : null;
            const diff = (now === null || now === undefined) ? '<span class="text-muted">جدول جدید</span>'
                : (inFile === now ? '<span class="text-muted">=</span>' : `<span class="${inFile < now ? 'text-danger' : 'text-success'}">${inFile - now > 0 ? '+' : ''}${(inFile - now).toLocaleString('fa-IR')}</span>`);
            return `<tr><td dir="ltr" class="text-start">${UI.escapeHtml(t)}</td><td>${Number(inFile).toLocaleString('fa-IR')}</td><td>${now === null || now === undefined ? '-' : Number(now).toLocaleString('fa-IR')}</td><td>${diff}</td></tr>`;
        }).join('');
        return `${res.header ? `<div class="small mb-2">${UI.escapeHtml(res.header)}</div>` : ''}
            ${res.images ? `<div class="small mb-2">تصاویر محصول در فایل: ${Number(res.images).toLocaleString('fa-IR')}</div>` : ''}
            ${res.is_entity_backup ? '<div class="alert alert-warning py-2 small">این پشتیبان یک مجموعه کاری است و فقط برای نگهداری/انتقال است؛ بازیابی کامل از آن ممکن نیست.</div>' : ''}
            <div class="table-responsive" style="max-height:360px"><table class="table table-sm table-bordered">
            <thead><tr><th>جدول</th><th>ردیف در فایل</th><th>ردیف فعلی</th><th>تغییر</th></tr></thead><tbody>${rows}</tbody></table></div>`;
    }

    async function previewBackup(filename) {
        const password = await askBackupPassword(filename);
        if (password === null) return;
        UI.showLoader('در حال بررسی فایل پشتیبان...');
        const res = await Api.call('preview_backup', { filename, backup_password: password });
        UI.hideLoader();
        if (!res?.success) return;
        await UI.formDialog({ title: 'پیش‌نمایش پشتیبان: ' + filename, message: renderPreviewHtml(res), fields: [], submitText: 'بستن', size: 'modal-lg' });
    }

    async function restoreFromServer(filename, hasImages) {
        const restoreImages = hasImages ? $('#restore-include-images').is(':checked') : false;
        const imgNote = hasImages
            ? (restoreImages ? ' تصاویر محصولات نیز بازیابی می‌شوند.' : ' تصاویر بازیابی نمی‌شوند.')
            : '';
        const password = await askBackupPassword(filename);
        if (password === null) return;
        const ok = await UI.confirm(`هشدار! داده‌های فعلی با محتوای فایل «${UI.escapeHtml(filename)}» جایگزین می‌شوند.${imgNote}<br><small class="text-muted">پیش از بازیابی، یک «نقطه بازگشت» خودکار از داده‌های فعلی ساخته می‌شود تا در صورت اشتباه قابل برگشت باشد. برای دیدن محتوای فایل از دکمه پیش‌نمایش استفاده کنید.</small>`, 'بازیابی اطلاعات');
        if (!ok) return;
        UI.showLoader('در حال ساخت نقطه بازگشت و بازیابی اطلاعات...\nلطفاً صفحه را نبندید.');
        const result = await Api.call('restore_from_server', {
            filename,
            restore_images: restoreImages ? 1 : 0,
            backup_password: password,
        });
        UI.hideLoader();
        if (result?.success) {
            UI.showSuccess(result.message);
            setTimeout(() => location.reload(), 2500);
        }
    }

    function handleRestoreFormState() {
        const fileSelected = $('#restore-file-input').get(0).files.length > 0;
        const confirmed = $('#restore-confirm-checkbox').is(':checked');
        $('#restore-btn').prop('disabled', !(fileSelected && confirmed));
    }

    async function handleRestoreClick(e) {
        e.preventDefault();
        const restoreImages = $('#restore-include-images').is(':checked');
        UI.confirmAction("هشدار بسیار مهم! تمام داده‌های فعلی شما با اطلاعات فایل پشتیبان جایگزین خواهد شد. پیش از بازیابی، یک نقطه بازگشت خودکار ساخته می‌شود. آیا مطمئن هستید؟", async (confirmed) => {
            if (confirmed) {
                const fileInput = $('#restore-file-input')[0];
                if (fileInput.files.length === 0) {
                    UI.showError('لطفا ابتدا یک فایل پشتیبان را انتخاب کنید.');
                    return;
                }
                const formData = new FormData();
                formData.append('backupFile', fileInput.files[0]);
                formData.append('restore_images', restoreImages ? '1' : '0');
                const pw = await askBackupPassword(fileInput.files[0].name);
                if (pw === null) return;
                formData.append('backup_password', pw);

                UI.showLoader('در حال آپلود و بازیابی...\nلطفاً صفحه را نبندید.');
                try {
                    const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
                    const response = await fetch(`${AppConfig.API_URL}?action=upload_and_restore`, {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': csrfToken },
                        body: formData
                    });
                    const result = await response.json();

                    if (response.ok && result.success) {
                        UI.showSuccess(result.message);
                        setTimeout(() => location.reload(), 2000);
                    } else {
                        UI.showError(result.error || 'خطای ناشناخته در بازیابی اطلاعات.');
                    }
                } catch (error) {
                    UI.showError('خطای شدید در ارتباط با سرور.');
                } finally {
                    UI.hideLoader();
                }
            }
        });
    }

    let autoBackupInFlight = null;

    async function maybeRunAutoBackup() {
        // Prevent parallel runs (e.g. double init / overlapping cache refresh) from
        // creating two backups and showing two success toasts.
        if (autoBackupInFlight) {
            return autoBackupInFlight;
        }

        autoBackupInFlight = (async () => {
            try {
                const status = await Api.call('check_auto_backup_status', {}, false);
                if (!status || !status.due) return;
                const withImages = !!status.include_images;
                UI.showLoader(withImages
                    ? 'پشتیبان‌گیری خودکار در حال انجام است (همراه تصاویر)...\nلطفاً چند لحظه صبر کنید؛ سیستم هنگ نکرده است.'
                    : 'پشتیبان‌گیری خودکار روزانه در حال انجام است...\nلطفاً چند لحظه صبر کنید؛ سیستم هنگ نکرده است.');
                const result = await Api.call('run_pending_auto_backup', {});
                UI.hideLoader();
                if (result?.success && !result.skipped) {
                    let msg = result.message || 'پشتیبان خودکار تهیه شد.';
                    if (result.remote_backup === 'success') {
                        msg += ' (نسخه روی سرور دیگر هم ذخیره شد)';
                    } else if (result.remote_backup === 'failed') {
                        UI.showError(result.remote_backup_error || 'ارسال بکاپ به سرور دیگر ناموفق بود.');
                    }
                    if (result.email_backup === 'success') {
                        msg += ' (' + (result.email_backup_message || 'به ایمیل ارسال شد') + ')';
                    } else if (result.email_backup === 'failed') {
                        UI.showError(result.email_backup_error || 'ارسال ایمیل پشتیبان ناموفق بود.');
                    }
                    UI.showSuccess(msg, 'پشتیبان خودکار');
                } else if (result?.error) {
                    UI.showError(result.error);
                }
            } catch (e) {
                UI.hideLoader();
            }
        })();

        try {
            return await autoBackupInFlight;
        } finally {
            autoBackupInFlight = null;
        }
    }

    function init() {
        $('#create-full-backup-btn').on('click', () => createBackup('full'));

        $('#create-entity-backup-btn').on('click', () => {
            const entityId = $('#backup-entity-select').val();
            if (!entityId) {
                UI.showError('لطفا ابتدا یک مجموعه کاری را انتخاب کنید.');
                return;
            }
            createBackup('entity', { entityId });
        });

        $('#create-partial-backup-btn').on('click', () => {
            const selectedTables = $('#backup-partial-select').val();
            if (!selectedTables || selectedTables.length === 0) {
                UI.showError('لطفا حداقل یک بخش را برای پشتیبان‌گیری انتخاب کنید.');
                return;
            }
            createBackup('partial', { tables: selectedTables });
        });

        $('#backup-entity-select').on('change', function () {
            const entityId = $(this).val();
            $('#create-entity-backup-btn').prop('disabled', !entityId);
        });

        $('body').on('click', '.btn-delete-backup', function () {
            deleteBackup($(this).data('filename'));
        });

        $('body').on('click', '.btn-preview-backup', function () {
            previewBackup($(this).data('filename'));
        });

        $('body').on('click', '.btn-restore-backup', function () {
            restoreFromServer($(this).data('filename'), String($(this).data('has-images')) === '1');
        });

        $('#restore-file-input, #restore-confirm-checkbox').on('change', handleRestoreFormState);
        $('#restore-btn').on('click', handleRestoreClick);
    }

    return {
        init,
        load,
        maybeRunAutoBackup,
    };
})();
