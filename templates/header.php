<header class="d-flex justify-content-between align-items-center p-3 mb-3 bg-white rounded shadow-sm">
    <div>
        <h3 class="mb-0 d-inline-block" id="app-title-header"><?php echo htmlspecialchars($appTitle ?? 'سیستم حسابداری', ENT_QUOTES, 'UTF-8'); ?></h3>
        <span id="user-info" class="ms-3"></span>
    </div>
    <div class="d-flex align-items-center">
        <div class="dropdown me-3">
            <button type="button" class="btn btn-outline-secondary dropdown-toggle btn-sm" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false" id="entity-switcher-btn" title="تغییر مجموعه">
                <i class="bi bi-building me-1"></i>
                <span id="current-entity-name">...</span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end" id="entity-switcher-list"></ul>
        </div>

        <div class="dropdown me-3" id="notification-bell" style="display: none;">
            <a href="#" class="nav-link text-secondary" data-bs-toggle="dropdown" aria-expanded="false" id="notification-link">
                <i class="bi bi-bell-fill fs-4 position-relative">
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" id="notification-count" style="font-size: 0.6em;">
                        0
                    </span>
                </i>
            </a>
            <ul class="dropdown-menu dropdown-menu-end" id="notification-list" style="min-width: 350px; max-height: 400px; overflow-y: auto;">
                <li><h6 class="dropdown-header">اعلان‌ها و یادآوری‌ها</h6></li>
                <li><hr class="dropdown-divider"></li>
                <li id="notif-section-checks"></li>
                <li id="notif-section-reminders"></li>
                <li class="text-center py-2">
                    <button class="btn btn-sm btn-outline-primary" id="generate-reminders-header-btn"><i class="bi bi-arrow-clockwise me-1"></i>بررسی یادآوری‌ها</button>
                </li>
            </ul>
        </div>
        
        <button type="button" class="btn btn-sm btn-outline-secondary me-2 d-inline-flex align-items-center" id="quick-nav-btn" title="جستجو در کل برنامه (Ctrl+K)">
            <i class="bi bi-search me-1"></i><span class="d-none d-md-inline small text-muted">Ctrl+K</span>
        </button>

        <!-- Dark mode toggle -->
        <button id="dark-mode-toggle" class="btn btn-sm btn-outline-secondary me-2" title="تغییر تم">
            <i class="bi bi-moon-fill" id="dark-mode-icon"></i>
        </button>
        
        <!-- Analytics icon-only button -->
        <a href="analytics/index.php" class="btn btn-sm btn-outline-primary me-2" data-permission="view_reports" title="آنالیز فروش"><i class="bi bi-graph-up-arrow"></i></a>

        <button id="show-change-password-modal-btn" class="btn btn-sm btn-outline-secondary me-2 d-none" title="تغییر رمز"><i class="bi bi-key-fill"></i></button>
        <button id="logout-btn" class="btn btn-sm btn-outline-danger d-none" title="خروج"><i class="bi bi-box-arrow-right"></i></button>
    </div>
</header>