<div class="card border-0 shadow-sm">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
            <div>
                <h4 class="mb-1"><i class="bi bi-bug me-2"></i>لاگ فنی برنامه</h4>
                <p class="text-muted small mb-0">
                    برای توسعه و رفع خطا. جدا از «گزارش فعالیت کاربران» است.
                    سطح‌ها: DEBUG / INFO / WARNING / ERROR / CRITICAL —
                    کانال <code>client</code> = خطای مرورگر، <code>app</code> = سرور.
                </p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-outline-primary btn-sm" id="app-log-refresh-btn">
                    <i class="bi bi-arrow-clockwise me-1"></i>بارگذاری مجدد
                </button>
                <a href="#" class="btn btn-outline-success btn-sm" id="app-log-download-btn">
                    <i class="bi bi-download me-1"></i>دانلود فایل
                </a>
                <button type="button" class="btn btn-outline-danger btn-sm" id="app-log-clear-btn">
                    <i class="bi bi-trash me-1"></i>پاک‌سازی لاگ
                </button>
            </div>
        </div>

        <div class="row g-2 mb-3" id="app-log-stats-row">
            <div class="col-auto"><span class="badge text-bg-secondary" id="app-log-stat-size">حجم: —</span></div>
            <div class="col-auto"><span class="badge text-bg-danger" id="app-log-stat-critical">CRITICAL: 0</span></div>
            <div class="col-auto"><span class="badge text-bg-danger" id="app-log-stat-error">ERROR: 0</span></div>
            <div class="col-auto"><span class="badge text-bg-warning" id="app-log-stat-warning">WARNING: 0</span></div>
            <div class="col-auto"><span class="badge text-bg-info" id="app-log-stat-info">INFO: 0</span></div>
            <div class="col-auto"><span class="badge text-bg-light text-dark" id="app-log-stat-debug">DEBUG: 0</span></div>
            <div class="col-auto"><span class="badge text-bg-dark" id="app-log-stat-debug-mode">APP_DEBUG: —</span></div>
        </div>

        <div class="row g-2 mb-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label small fw-bold">سطح</label>
                <select class="form-select form-select-sm" id="app-log-level-filter">
                    <option value="">همه سطوح</option>
                    <option value="CRITICAL">CRITICAL</option>
                    <option value="ERROR">ERROR</option>
                    <option value="WARNING">WARNING</option>
                    <option value="INFO">INFO</option>
                    <option value="DEBUG">DEBUG</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold">کانال</label>
                <select class="form-select form-select-sm" id="app-log-channel-filter">
                    <option value="">همه کانال‌ها</option>
                    <option value="app">app (سرور)</option>
                    <option value="client">client (مرورگر)</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold">جستجو</label>
                <input type="text" class="form-control form-control-sm" id="app-log-search" placeholder="متن، action، کاربر، request id...">
            </div>
            <div class="col-md-2">
                <button type="button" class="btn btn-primary btn-sm w-100" id="app-log-apply-filter-btn">اعمال فیلتر</button>
            </div>
        </div>

        <div class="table-responsive" style="max-height: 65vh; overflow: auto;">
            <table class="table table-sm table-hover align-middle mb-0" id="app-log-table">
                <thead class="table-light sticky-top">
                    <tr>
                        <th style="width: 140px;">زمان</th>
                        <th style="width: 90px;">سطح</th>
                        <th style="width: 70px;">کانال</th>
                        <th>پیام</th>
                        <th style="width: 110px;">کاربر</th>
                        <th style="width: 120px;">عملیات</th>
                        <th style="width: 90px;">RID</th>
                        <th style="width: 50px;"></th>
                    </tr>
                </thead>
                <tbody id="app-log-tbody">
                    <tr><td colspan="8" class="text-center text-muted py-4">برای مشاهده لاگ‌ها این تب را باز کنید.</td></tr>
                </tbody>
            </table>
        </div>
        <div class="small text-muted mt-2" id="app-log-meta"></div>
    </div>
</div>

<!-- Detail modal -->
<div class="modal fade" id="app-log-detail-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">جزئیات لاگ</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <pre class="bg-dark text-light p-3 rounded small mb-0" id="app-log-detail-pre" style="white-space: pre-wrap; direction: ltr; text-align: left;"></pre>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">بستن</button>
            </div>
        </div>
    </div>
</div>
