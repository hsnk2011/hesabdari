<header class="d-flex justify-content-between align-items-center p-3 mb-3 bg-white rounded shadow-sm">
    <div>
        <h3 class="mb-0 d-inline-block" id="app-title-header">سیستم حسابداری بازرگانی فرش</h3>
        <span id="user-info" class="ms-3"></span>
    </div>
    <div class="d-flex align-items-center">
        <div class="dropdown me-3">
            <a href="#" class="btn btn-outline-secondary dropdown-toggle btn-sm" data-bs-toggle="dropdown" aria-expanded="false" id="entity-switcher-btn">
                <i class="bi bi-building me-1"></i>
                <span id="current-entity-name">...</span>
            </a>
            <ul class="dropdown-menu" id="entity-switcher-list">
                </ul>
        </div>

        <div class="dropdown me-3" id="notification-bell" style="display: none;">
            <a href="#" class="nav-link text-secondary" data-bs-toggle="dropdown" aria-expanded="false" id="notification-link">
                <i class="bi bi-bell-fill fs-4 position-relative">
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" id="notification-count" style="font-size: 0.6em;">
                        0
                    </span>
                </i>
            </a>
            <ul class="dropdown-menu dropdown-menu-end" id="notification-list">
                <li><h6 class="dropdown-header">اعلان‌ها</h6></li>
                <li><hr class="dropdown-divider"></li>
                </ul>
        </div>
        <button id="show-change-password-modal-btn" class="btn btn-sm btn-outline-secondary me-2 d-none"><i class="bi bi-key-fill me-2"></i>تغییر رمز</button>
        <button id="logout-btn" class="btn btn-sm btn-outline-danger d-none"><i class="bi bi-box-arrow-right me-2"></i>خروج</button>
        <i class="bi bi-cash-coin fs-2 text-success d-inline-block align-middle"></i>
    </div>
</header>