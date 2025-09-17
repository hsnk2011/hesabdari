<div class="tab-pane fade" id="settings" role="tabpanel">
    <h4>تنظیمات و مدیریت</h4>

    <ul class="nav nav-pills mb-3" id="settings-sub-tab" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="general-settings-tab" data-bs-toggle="tab" data-bs-target="#general-settings-pane" type="button" role="tab">
                <i class="bi bi-gear-fill me-2"></i>تنظیمات کلی
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="partners-settings-tab" data-bs-toggle="tab" data-bs-target="#partners-pane" type="button" role="tab">
                <i class="bi bi-people-fill me-2"></i>مدیریت شرکا
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="users-settings-tab" data-bs-toggle="tab" data-bs-target="#users-pane" type="button" role="tab">
                <i class="bi bi-person-gear me-2"></i>مدیریت کاربران
            </button>
        </li>
         <li class="nav-item" role="presentation">
            <button class="nav-link" id="activity-log-settings-tab" data-bs-toggle="tab" data-bs-target="#activity-log-pane" type="button" role="tab">
                <i class="bi bi-clipboard2-data-fill me-2"></i>گزارش فعالیت کاربران
            </button>
        </li>
    </ul>

    <div class="tab-content" id="settings-sub-tab-content">
        <div class="tab-pane fade show active" id="general-settings-pane" role="tabpanel">
             <div class="row">
                <div class="col-md-8 mx-auto">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">تنظیمات عمومی برنامه</h5>
                        </div>
                        <div class="card-body">
                            <form id="settings-form">
                                <div class="alert alert-danger form-error" style="display:none;"></div>
                                <div class="mb-3">
                                    <label for="setting-app-title" class="form-label">عنوان برنامه</label>
                                    <input type="text" class="form-control" id="setting-app-title" data-setting-key="app_title">
                                    <div class="form-text">این عنوان در هدر صفحه نمایش داده می‌شود.</div>
                                </div>
                                <hr>
                                <h6 class="mb-3">مدیریت مجموعه‌های تجاری</h6>
                                <div id="business-entities-container"></div>
                                <button type="submit" class="btn btn-success w-100 mt-3"><i class="bi bi-check-lg me-2"></i>ذخیره تغییرات</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="tab-pane fade" id="partners-pane" role="tabpanel">
            <?php include __DIR__ . '/section_partners.php'; ?>
        </div>
        <div class="tab-pane fade" id="users-pane" role="tabpanel">
            <?php include __DIR__ . '/section_users.php'; ?>
        </div>
        <div class="tab-pane fade" id="activity-log-pane" role="tabpanel">
            <?php include __DIR__ . '/section_activity_log.php'; ?>
        </div>
    </div>
</div>