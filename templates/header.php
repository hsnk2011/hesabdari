<?php
// Redesigned app header: the dark bar holds the brand and the grouped
// navigation (main_tabs.php, the #mainTab tab list); the light bar under it
// holds the current-section path, global search and the user's tools.
?>
<header class="ui-appbar" id="ui-appbar">
    <div class="ui-appbar-main">
        <button type="button" class="ui-appbar-burger d-lg-none" data-bs-toggle="offcanvas" data-bs-target="#ui-nav-drawer" aria-controls="ui-nav-drawer" title="منو">
            <i class="bi bi-list"></i>
        </button>
        <div class="ui-brand">
            <span class="ui-brand-mark"><i class="bi bi-journal-bookmark-fill"></i></span>
            <span class="ui-brand-name" id="app-title-header"><?php echo htmlspecialchars($appTitle ?? 'سیستم حسابداری', ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
        <div class="offcanvas-lg offcanvas-start ui-nav-drawer" tabindex="-1" id="ui-nav-drawer" aria-labelledby="ui-nav-drawer-title">
            <div class="offcanvas-header">
                <h6 class="offcanvas-title" id="ui-nav-drawer-title">منوی برنامه</h6>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#ui-nav-drawer" aria-label="بستن"></button>
            </div>
            <div class="offcanvas-body">
                <?php include __DIR__ . '/main_tabs.php'; ?>
            </div>
        </div>
    </div>

    <div class="ui-appbar-sub">
        <nav class="ui-crumb" aria-label="مسیر">
            <span class="ui-crumb-group" id="ui-crumb-group"></span>
            <i class="bi bi-chevron-left ui-crumb-sep" id="ui-crumb-sep"></i>
            <span class="ui-crumb-page" id="ui-crumb-page">داشبورد</span>
        </nav>

        <button type="button" class="ui-search-trigger" id="quick-nav-btn" title="جستجو در کل برنامه (Ctrl+K)">
            <i class="bi bi-search"></i>
            <span class="ui-search-text">جستجو در فاکتورها، اشخاص، کالاها و بخش‌ها…</span>
            <kbd class="d-none d-md-inline">Ctrl K</kbd>
        </button>

        <div class="ui-appbar-tools">
            <div class="dropdown">
                <button type="button" class="ui-tool ui-entity dropdown-toggle" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false" id="entity-switcher-btn" title="تغییر مجموعه">
                    <i class="bi bi-building"></i>
                    <span id="current-entity-name">...</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end" id="entity-switcher-list"></ul>
            </div>

            <div class="dropdown" id="notification-bell" style="display: none;">
                <a href="#" class="ui-tool ui-tool-icon" data-bs-toggle="dropdown" aria-expanded="false" id="notification-link" title="اعلان‌ها">
                    <i class="bi bi-bell"></i>
                    <span class="position-absolute badge rounded-pill bg-danger ui-tool-count" id="notification-count">0</span>
                </a>
                <ul class="dropdown-menu dropdown-menu-end ui-notif-menu" id="notification-list">
                    <li><h6 class="dropdown-header">اعلان‌ها و یادآوری‌ها</h6></li>
                    <li><hr class="dropdown-divider"></li>
                    <li id="notif-section-checks"></li>
                    <li id="notif-section-reminders"></li>
                    <li class="text-center py-2">
                        <button class="btn btn-sm btn-outline-primary" id="generate-reminders-header-btn"><i class="bi bi-arrow-clockwise me-1"></i>بررسی یادآوری‌ها</button>
                    </li>
                </ul>
            </div>

            <a href="analytics/index.php" class="ui-tool ui-tool-icon" data-permission="view_reports" title="آنالیز فروش"><i class="bi bi-bar-chart-line"></i></a>

            <div class="dropdown" id="ui-theme-picker">
                <button type="button" class="ui-tool ui-tool-icon" data-bs-toggle="dropdown" aria-expanded="false" id="ui-theme-btn" title="انتخاب قالب"><i class="bi bi-palette"></i></button>
                <ul class="dropdown-menu dropdown-menu-end ui-theme-menu" id="ui-theme-menu"></ul>
            </div>

            <div class="dropdown">
                <button type="button" class="ui-tool ui-user" data-bs-toggle="dropdown" aria-expanded="false" id="ui-user-btn" title="حساب کاربری">
                    <span class="ui-avatar"><i class="bi bi-person-fill"></i></span>
                    <span id="user-info" class="ui-user-name"></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><button type="button" id="show-change-password-modal-btn" class="dropdown-item d-none"><i class="bi bi-key me-2"></i>تغییر رمز عبور</button></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><button type="button" id="logout-btn" class="dropdown-item text-danger d-none"><i class="bi bi-box-arrow-right me-2"></i>خروج</button></li>
                </ul>
            </div>
        </div>
    </div>
</header>
