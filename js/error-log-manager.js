// =================================================================
// ERROR LOG MANAGER — settings tab for AppLogger entries
// =================================================================
const ErrorLogManager = (function () {
    let loadedOnce = false;
    let detailCache = [];

    function levelBadge(level) {
        const map = {
            CRITICAL: 'danger',
            ERROR: 'danger',
            WARNING: 'warning',
            INFO: 'info',
            DEBUG: 'secondary'
        };
        const cls = map[level] || 'secondary';
        return `<span class="badge text-bg-${cls}">${UI.escapeHtml(level || '-')}</span>`;
    }

    function formatBytes(n) {
        n = Number(n) || 0;
        if (n < 1024) return n + ' B';
        if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
        return (n / (1024 * 1024)).toFixed(2) + ' MB';
    }

    async function loadStats() {
        const stats = await Api.call('get_app_log_stats', {});
        if (!stats || stats.error) return;
        const c = stats.counts || {};
        $('#app-log-stat-size').text('حجم: ' + formatBytes(stats.file_size));
        $('#app-log-stat-critical').text('CRITICAL: ' + (c.CRITICAL || 0));
        $('#app-log-stat-error').text('ERROR: ' + (c.ERROR || 0));
        $('#app-log-stat-warning').text('WARNING: ' + (c.WARNING || 0));
        $('#app-log-stat-info').text('INFO: ' + (c.INFO || 0));
        $('#app-log-stat-debug').text('DEBUG: ' + (c.DEBUG || 0));
        $('#app-log-stat-debug-mode').text('APP_DEBUG: ' + (stats.app_debug ? 'ON' : 'OFF'));
    }

    async function load() {
        const level = $('#app-log-level-filter').val() || '';
        const channel = $('#app-log-channel-filter').val() || '';
        const search = ($('#app-log-search').val() || '').trim();
        const tbody = $('#app-log-tbody').empty();
        tbody.html('<tr><td colspan="8" class="text-center text-muted py-3">در حال بارگذاری...</td></tr>');

        const [result] = await Promise.all([
            Api.call('get_app_logs', { limit: 150, offset: 0, level, channel, search }),
            loadStats()
        ]);

        if (!result || result.error) {
            tbody.html(`<tr><td colspan="8" class="text-center text-danger">خطا در بارگذاری لاگ</td></tr>`);
            return;
        }

        detailCache = result.entries || [];
        if (!detailCache.length) {
            tbody.html('<tr><td colspan="8" class="text-center text-muted py-4">لاگی با این فیلتر یافت نشد.</td></tr>');
            $('#app-log-meta').text('');
            return;
        }

        detailCache.forEach((entry, idx) => {
            const msg = entry.msg || '';
            const shortMsg = msg.length > 120 ? msg.slice(0, 120) + '…' : msg;
            tbody.append(`<tr>
                <td class="small text-nowrap">${UI.escapeHtml(entry.ts || '-')}</td>
                <td>${levelBadge(entry.level)}</td>
                <td class="small">${UI.escapeHtml(entry.channel || '-')}</td>
                <td class="small" title="${UI.escapeHtml(msg)}">${UI.escapeHtml(shortMsg)}</td>
                <td class="small">${UI.escapeHtml(entry.username || '-')}</td>
                <td class="small">${UI.escapeHtml(entry.action || '-')}</td>
                <td class="small font-monospace" dir="ltr">${UI.escapeHtml(entry.rid || '-')}</td>
                <td>
                    <button type="button" class="btn btn-sm btn-outline-secondary app-log-detail-btn" data-idx="${idx}" title="جزئیات">
                        <i class="bi bi-eye"></i>
                    </button>
                </td>
            </tr>`);
        });

        const matched = result.total_matched != null ? result.total_matched : detailCache.length;
        $('#app-log-meta').text(
            `نمایش ${detailCache.length.toLocaleString('fa-IR')} از ${matched.toLocaleString('fa-IR')} مورد مطابق · حجم فایل ${formatBytes(result.file_size)}`
        );
        loadedOnce = true;
    }

    function showDetail(idx) {
        const entry = detailCache[idx];
        if (!entry) return;
        $('#app-log-detail-pre').text(JSON.stringify(entry, null, 2));
        const modalEl = document.getElementById('app-log-detail-modal');
        if (modalEl && typeof bootstrap !== 'undefined') {
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }
    }

    async function clearLogs() {
        if (!await UI.confirmText('همه لاگ‌های فنی پاک شوند؟ این عمل قابل بازگشت نیست.')) return;
        const res = await Api.call('clear_app_logs', {});
        if (res && res.success) {
            UI.showSuccess('لاگ‌ها پاک شد.');
            load();
        }
    }

    function init() {
        $('button[data-bs-toggle="tab"][data-bs-target="#error-log-pane"]')
            .off('shown.bs.tab.errorlog')
            .on('shown.bs.tab.errorlog', function () {
                load();
            });

        $('#app-log-refresh-btn').off('click.errorlog').on('click.errorlog', load);
        $('#app-log-apply-filter-btn').off('click.errorlog').on('click.errorlog', load);
        $('#app-log-clear-btn').off('click.errorlog').on('click.errorlog', clearLogs);
        $('#app-log-search').off('keydown.errorlog').on('keydown.errorlog', function (e) {
            if (e.key === 'Enter') load();
        });

        $('#app-log-download-btn').off('click.errorlog').on('click.errorlog', function (e) {
            e.preventDefault();
            window.location.href = `${AppConfig.API_URL}?action=download_app_logs`;
        });

        $(document).off('click.errorlog', '.app-log-detail-btn').on('click.errorlog', '.app-log-detail-btn', function () {
            showDetail(parseInt($(this).data('idx'), 10));
        });
    }

    return { init, load };
})();
