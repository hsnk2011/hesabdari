<!-- templates/section_smart_audit.php — Smart Audit Center v2 -->
<div class="tab-pane fade" id="section-smart-audit" role="tabpanel" aria-labelledby="smart-audit-tab">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h2 class="mb-0"><i class="bi bi-robot me-2"></i>مرکز حسابرسی هوشمند</h2>
        <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-outline-secondary" id="audit-export-excel-btn">
                <i class="bi bi-file-earmark-excel me-1"></i>خروجی Excel
            </button>
            <button type="button" class="btn btn-outline-secondary" id="audit-compare-btn">
                <i class="bi bi-diagram-3 me-1"></i>مقایسه با قبل
            </button>
            <button type="button" class="btn btn-primary" id="audit-run-btn">
                <i class="bi bi-play-fill me-1"></i>شروع حسابرسی
            </button>
        </div>
    </div>

    <div id="audit-last-run-info" class="alert alert-light mb-3" style="display:none;">
        <i class="bi bi-clock me-1"></i>
        آخرین حسابرسی: <strong id="audit-last-run-time">-</strong>
        <span class="ms-3">مدت: <strong id="audit-run-duration">-</strong></span>
        <span class="ms-3">اجرا #<strong id="audit-run-id">-</strong></span>
        <span class="ms-3 text-success">جدید: <strong id="audit-cmp-new">0</strong></span>
        <span class="ms-2 text-secondary">تکراری: <strong id="audit-cmp-recurring">0</strong></span>
        <span class="ms-2 text-primary">رفع‌شده: <strong id="audit-cmp-resolved">0</strong></span>
    </div>

    <ul class="nav nav-pills mb-3" id="audit-center-tabs" role="tablist">
        <li class="nav-item"><button class="nav-link active" data-audit-center="results" type="button">نتایج</button></li>
        <li class="nav-item"><button class="nav-link" data-audit-center="history" type="button">تاریخچه</button></li>
        <li class="nav-item" data-permission="manage_products"><button class="nav-link" data-audit-center="inventory_link" type="button">ویزارد لینک انبار</button></li>
        <li class="nav-item" data-permission="manage_settings"><button class="nav-link" data-audit-center="balance" type="button">تحلیل مانده حساب</button></li>
    </ul>

    <div id="audit-center-panel-results" class="audit-center-panel">
        <div class="row mb-3 g-3">
            <div class="col-md-4">
                <div class="card text-center h-100">
                    <div class="card-body">
                        <h6 class="text-muted">امتیاز سلامت</h6>
                        <div class="display-4 fw-bold text-success" id="audit-health-score">-</div>
                        <div class="progress mt-2" style="height:8px;">
                            <div class="progress-bar" id="audit-health-progress" style="width:0%"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-8">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <h6 class="card-title mb-0">خلاصه</h6>
                            <button type="button" class="btn btn-sm btn-outline-danger" id="fix-all-critical-btn" style="display:none;">
                                <i class="bi bi-wrench me-1"></i>اصلاح همه safe_auto بحرانی
                            </button>
                        </div>
                        <div class="row text-center mt-3">
                            <div class="col-4"><h3 class="text-danger mb-0" id="audit-critical-count">0</h3><small class="text-muted">بحرانی</small></div>
                            <div class="col-4"><h3 class="text-warning mb-0" id="audit-warning-count">0</h3><small class="text-muted">هشدار</small></div>
                            <div class="col-4"><h3 class="text-info mb-0" id="audit-info-count">0</h3><small class="text-muted">پیشنهاد</small></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div id="audit-loading" class="text-center py-5" style="display:none;">
                    <div class="spinner-border text-primary"></div>
                    <p class="mt-2 text-muted mb-0">در حال اجرای قوانین حسابرسی...</p>
                </div>
                <div id="audit-empty-state" class="text-center text-muted py-5">
                    <i class="bi bi-clipboard-check display-6 d-block mb-2"></i>
                    هنوز حسابرسی انجام نشده. روی «شروع حسابرسی» کلیک کنید.
                </div>
                <div id="audit-content" style="display:none;">
                    <div id="audit-bulk-actions" class="alert alert-info d-flex justify-content-between align-items-center mb-3" style="display:none !important;">
                        <div><span class="fw-bold" id="selected-count">0</span> مورد انتخاب شده</div>
                        <button type="button" class="btn btn-sm btn-outline-danger" id="audit-ignore-selected-btn">
                            <i class="bi bi-eye-slash me-1"></i>نادیده گرفتن
                        </button>
                    </div>
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <select id="audit-filter-category" class="form-select form-select-sm" style="width:auto;">
                            <option value="all">همه دسته‌ها</option>
                            <option value="financial">مالی</option>
                            <option value="inventory">انبار</option>
                            <option value="entity">اشخاص</option>
                            <option value="system">سیستمی</option>
                            <option value="operations">عملیاتی</option>
                            <option value="ignored">نادیده گرفته‌شده</option>
                        </select>
                        <select id="audit-filter-severity" class="form-select form-select-sm" style="width:auto;">
                            <option value="all">همه شدت‌ها</option>
                            <option value="critical">بحرانی</option>
                            <option value="warning">هشدار</option>
                            <option value="info">پیشنهاد</option>
                        </select>
                        <select id="audit-filter-compare" class="form-select form-select-sm" style="width:auto;">
                            <option value="all">همه (مقایسه)</option>
                            <option value="new">فقط جدید</option>
                            <option value="recurring">فقط تکراری</option>
                        </select>
                        <input type="search" id="audit-filter-search" class="form-control form-control-sm" style="max-width:220px;" placeholder="جستجو در پیام...">
                    </div>
                    <div id="audit-issues-grouped"></div>
                    <div id="audit-ignored-section" class="mt-4" style="display:none;">
                        <h6 class="text-muted">نادیده گرفته‌شده‌ها</h6>
                        <div id="audit-issues-list-ignored" class="list-group"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="audit-center-panel-history" class="audit-center-panel d-none">
        <div class="card">
            <div class="card-header">تاریخچه اجراها</div>
            <div class="card-body">
                <div id="audit-history-list" class="list-group"></div>
            </div>
        </div>
    </div>

    <div id="audit-center-panel-inventory_link" class="audit-center-panel d-none" data-permission="manage_products">
        <?php include __DIR__ . '/section_inventory_audit.php'; ?>
    </div>

    <div id="audit-center-panel-balance" class="audit-center-panel d-none" data-permission="manage_settings">
        <?php include __DIR__ . '/section_balance_audit.php'; ?>
    </div>
</div>
