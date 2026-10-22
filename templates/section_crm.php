<div class="tab-pane fade" id="crm" role="tabpanel">
    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
        <h4 class="mb-0"><i class="bi bi-kanban me-2"></i>میز کار</h4>
        <div class="d-flex flex-wrap gap-2">
            <div class="dropdown">
                <button class="btn btn-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-plus-circle me-1"></i>جدید</button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li data-permission="manage_projects"><button class="dropdown-item" type="button" id="work-new-project-btn"><i class="bi bi-folder-plus me-2"></i>پروژه</button></li>
                    <li data-permission="use_projects"><button class="dropdown-item" type="button" id="work-new-meeting-btn"><i class="bi bi-calendar-plus me-2"></i>جلسه</button></li>
                    <li data-permission="use_cartable"><button class="dropdown-item" type="button" id="office-new-payment-btn"><i class="bi bi-cash-coin me-2"></i>درخواست پرداخت</button></li>
                    <li data-permission="use_cartable"><button class="dropdown-item" type="button" id="office-new-letter-btn"><i class="bi bi-envelope-paper me-2"></i>نامه / ارجاع</button></li>
                    <li data-permission="use_ideas"><button class="dropdown-item" type="button" id="office-new-idea-btn"><i class="bi bi-lightbulb me-2"></i>ایده</button></li>
                    <li data-permission="use_messenger"><button class="dropdown-item" type="button" id="chat-new-group-btn"><i class="bi bi-people me-2"></i>گروه گفتگو</button></li>
                    <li data-permission="use_crm"><button class="dropdown-item" type="button" id="crm-new-task-btn"><i class="bi bi-alarm me-2"></i>پیگیری مشتری</button></li>
                    <li data-permission="use_crm"><button class="dropdown-item" type="button" id="crm-new-lead-btn"><i class="bi bi-person-plus me-2"></i>سرنخ (مشتری بالقوه)</button></li>
                </ul>
            </div>
            <button class="btn btn-outline-success d-none" id="crm-push-btn"><i class="bi bi-bell me-1"></i>فعال‌سازی نوتیفیکیشن روی این گوشی</button>
        </div>
    </div>
    <div class="alert alert-info py-2 small d-none" id="crm-push-hint"></div>
    <div class="alert alert-warning py-2 small d-none" id="crm-no-team-hint">
        حساب کاربری شما هنوز به یک عضو تیم وصل نیست؛ تا وصل نشود کار و یادآوری نمی‌گیرید. مدیر از زبانه «تیم» اعضا را تعریف می‌کند.
    </div>

    <ul class="nav nav-pills crm-subnav flex-nowrap mb-3" id="crm-subnav" role="tablist">
        <li class="nav-item"><button class="nav-link active" data-crm-pane="my" type="button">کارهای من <span class="badge text-bg-danger rounded-pill ms-1 d-none" id="crm-my-badge"></span></button></li>
        <li class="nav-item" data-permission="use_messenger"><button class="nav-link" data-crm-pane="chat" type="button">پیام‌ها <span class="badge text-bg-danger rounded-pill ms-1 d-none" id="chat-unread-badge"></span></button></li>
        <li class="nav-item" data-permission="use_projects"><button class="nav-link" data-crm-pane="projects" type="button">پروژه‌ها</button></li>
        <li class="nav-item" data-permission="use_projects"><button class="nav-link" data-crm-pane="meetings" type="button">جلسات</button></li>
        <li class="nav-item" data-permission="use_cartable"><button class="nav-link" data-crm-pane="cartable" type="button">کارتابل</button></li>
        <li class="nav-item" data-permission="use_ideas"><button class="nav-link" data-crm-pane="ideas" type="button">ایده‌ها</button></li>
        <li class="nav-item" data-permission="manage_hr"><button class="nav-link" data-crm-pane="hr" type="button">پرسنل</button></li>
        <li class="nav-item" data-permission="use_crm"><button class="nav-link" data-crm-pane="team" type="button">پیگیری مشتری‌ها</button></li>
        <li class="nav-item" data-permission="use_crm"><button class="nav-link" data-crm-pane="debtors" type="button">بدهکارها</button></li>
        <li class="nav-item" data-permission="use_crm"><button class="nav-link" data-crm-pane="leads" type="button">سرنخ‌ها</button></li>
        <li class="nav-item" data-permission="manage_crm_team"><button class="nav-link" data-crm-pane="members" type="button">تیم</button></li>
    </ul>
    <div class="crm-pane" id="crm-pane-my"></div>

    <div class="crm-pane d-none" id="crm-pane-projects">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <ul class="nav nav-pills nav-sm crm-stage-pills flex-nowrap" id="work-project-filters">
                <li class="nav-item"><button class="nav-link active py-1 px-3" type="button" data-filter="active">جاری</button></li>
                <li class="nav-item"><button class="nav-link py-1 px-3" type="button" data-filter="mine">پروژه‌های من</button></li>
                <li class="nav-item"><button class="nav-link py-1 px-3" type="button" data-filter="done">پایان‌یافته</button></li>
                <li class="nav-item" data-permission="manage_projects"><button class="nav-link py-1 px-3" type="button" data-filter="all">همه</button></li>
            </ul>
        </div>
        <div class="row g-3" id="work-projects-list"></div>
    </div>

    <div class="crm-pane d-none" id="crm-pane-meetings">
        <ul class="nav nav-pills crm-stage-pills flex-nowrap mb-3" id="work-meeting-filters">
            <li class="nav-item"><button class="nav-link active py-1 px-3" type="button" data-filter="upcoming">پیش‌رو</button></li>
            <li class="nav-item"><button class="nav-link py-1 px-3" type="button" data-filter="past">گذشته</button></li>
            <li class="nav-item"><button class="nav-link py-1 px-3" type="button" data-filter="actions">مصوبه‌های من</button></li>
        </ul>
        <div id="work-meetings-list"></div>
    </div>

    <div class="crm-pane d-none" id="crm-pane-chat">
        <div class="row g-0 border rounded chat-shell">
            <div class="col-md-4 border-end chat-list-col">
                <div class="p-2 border-bottom d-flex gap-2">
                    <input type="search" class="form-control form-control-sm" id="chat-list-search" placeholder="جستجوی گفتگو…">
                    <button class="btn btn-sm btn-primary text-nowrap" id="chat-new-direct-btn" title="گفتگوی جدید"><i class="bi bi-pencil-square"></i></button>
                </div>
                <div id="chat-list" class="chat-list"></div>
            </div>
            <div class="col-md-8 d-none d-md-flex flex-column chat-view-col" id="chat-view-desktop">
                <div class="m-auto text-muted text-center p-4"><i class="bi bi-chat-dots fs-1 d-block mb-2"></i>یک گفتگو را انتخاب کنید.</div>
            </div>
        </div>
    </div>

    <div class="crm-pane d-none" id="crm-pane-cartable">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
            <ul class="nav nav-pills crm-stage-pills flex-nowrap" id="office-req-tabs">
                <li class="nav-item"><button class="nav-link active py-1 px-3" type="button" data-tab="mine_action">منتظر من</button></li>
                <li class="nav-item"><button class="nav-link py-1 px-3" type="button" data-tab="mine">ثبت‌شده‌های من</button></li>
                <li class="nav-item"><button class="nav-link py-1 px-3" type="button" data-tab="all">همه</button></li>
            </ul>
            <select class="form-select form-select-sm w-auto ms-auto" id="office-req-kind"><option value="">همه انواع</option><option value="payment">درخواست پرداخت</option><option value="letter">نامه / ارجاع</option></select>
        </div>
        <div id="office-req-list"></div>
    </div>

    <div class="crm-pane d-none" id="crm-pane-ideas">
        <div class="d-flex justify-content-between align-items-center mb-3 gap-2">
            <p class="text-muted small mb-0">ایده‌ات را بنویس؛ همکاران رأی و نظر می‌دهند و مدیر آن را به پروژه تبدیل می‌کند.</p>
            <button class="btn btn-sm btn-primary text-nowrap" id="office-new-idea-btn-2"><i class="bi bi-lightbulb me-1"></i>ایده جدید</button>
        </div>
        <div id="office-ideas-list"></div>
    </div>

    <div class="crm-pane d-none" id="crm-pane-hr">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
            <input type="search" class="form-control form-control-sm" style="max-width:260px" id="office-hr-search" placeholder="جستجوی نام، سمت یا تلفن…">
            <label class="form-check-label small"><input type="checkbox" class="form-check-input me-1" id="office-hr-inactive">غیرفعال‌ها هم</label>
            <button class="btn btn-sm btn-outline-secondary ms-auto" id="office-hr-import"><i class="bi bi-people me-1"></i>افزودن اعضای تیم</button>
            <button class="btn btn-sm btn-primary" id="office-hr-new"><i class="bi bi-person-plus me-1"></i>پرونده جدید</button>
        </div>
        <div id="office-hr-list"></div>
    </div>

    <div class="crm-pane d-none" id="crm-pane-team">
        <div class="row g-2 mb-3">
            <div class="col-7 col-md-4"><select class="form-select" id="crm-team-filter-member"><option value="">همه اعضا</option></select></div>
            <div class="col-5 col-md-3">
                <select class="form-select" id="crm-team-filter-status">
                    <option value="open">باز</option>
                    <option value="done">انجام‌شده</option>
                    <option value="cancelled">لغوشده</option>
                </select>
            </div>
        </div>
        <div id="crm-team-list"></div>
    </div>

    <div class="crm-pane d-none" id="crm-pane-debtors">
        <div class="row g-2 mb-3">
            <div class="col-12"><select class="form-select" id="crm-customer-search" aria-label="جستجوی مشتری"></select></div>
            <div class="col-md-5"><input type="search" class="form-control" id="crm-debtors-search" placeholder="جستجوی نام یا شماره در بدهکارها..."></div>
            <div class="col-md-7 small text-muted d-flex align-items-center" id="crm-debtors-summary"></div>
        </div>
        <div id="crm-debtors-list"></div>
    </div>

    <div class="crm-pane d-none" id="crm-pane-leads">
        <div class="row g-2 mb-3">
            <div class="col-md-5"><input type="search" class="form-control" id="crm-leads-search" placeholder="جستجوی نام، شماره یا علاقه‌مندی..."></div>
        </div>
        <ul class="nav nav-pills nav-fill crm-stage-pills d-md-none mb-2 flex-nowrap" id="crm-stage-pills"></ul>
        <div class="row g-3" id="crm-leads-board"></div>
    </div>

    <div class="crm-pane d-none" id="crm-pane-members">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <p class="text-muted small mb-0">شرکا و کارکنان. هر عضوی که «کاربر سیستم» داشته باشد می‌تواند کار بگیرد و یادآوری دریافت کند.</p>
            <button class="btn btn-sm btn-primary" id="crm-add-member-btn"><i class="bi bi-plus-circle me-1"></i>افزودن عضو</button>
        </div>
        <div id="crm-members-list"></div>
    </div>
</div>

<!-- Customer / lead file -->
<div class="offcanvas offcanvas-bottom crm-sheet" tabindex="-1" id="crm-file" aria-labelledby="crm-file-title">
    <div class="offcanvas-header border-bottom">
        <h5 class="offcanvas-title text-truncate" id="crm-file-title">پرونده</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="بستن"></button>
    </div>
    <div class="offcanvas-body">
        <div id="crm-file-head"></div>
        <div class="crm-actions d-flex flex-wrap gap-2 my-3" id="crm-file-actions"></div>
        <div id="crm-file-tasks"></div>
        <h6 class="text-muted small fw-bold mt-3">سابقه</h6>
        <div id="crm-file-timeline"></div>
        <div class="text-center my-3"><button class="btn btn-sm btn-outline-secondary d-none" id="crm-file-more">بیشتر</button></div>
    </div>
</div>

<!-- Call / note result -->
<div class="offcanvas offcanvas-bottom crm-sheet crm-sheet-short" tabindex="-1" id="crm-log-sheet" aria-labelledby="crm-log-title">
    <div class="offcanvas-header border-bottom">
        <h5 class="offcanvas-title text-truncate" id="crm-log-title">ثبت نتیجه</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="بستن"></button>
    </div>
    <div class="offcanvas-body">
        <div class="mb-2 d-flex flex-wrap gap-2" id="crm-log-kinds"></div>
        <label class="form-label small text-muted mb-1">نتیجه</label>
        <div class="mb-3 d-flex flex-wrap gap-2" id="crm-log-outcomes"></div>
        <div class="row g-2 mb-3 d-none" id="crm-log-promise">
            <div class="col-7"><label class="form-label small mb-1">مبلغ قول</label><input type="text" inputmode="numeric" class="form-control numeric-input" id="crm-log-promise-amount"></div>
            <div class="col-5"><label class="form-label small mb-1">تا تاریخ</label><input type="text" class="form-control persian-datepicker" id="crm-log-promise-date" autocomplete="off"></div>
        </div>
        <textarea class="form-control mb-3" id="crm-log-body" rows="2" placeholder="چه گفت؟ (اختیاری)"></textarea>
        <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" id="crm-log-next-on">
            <label class="form-check-label" for="crm-log-next-on">پیگیری بعدی</label>
        </div>
        <div class="row g-2 mb-3 d-none" id="crm-log-next">
            <div class="col-12"><input type="text" class="form-control" id="crm-log-next-title" placeholder="عنوان پیگیری"></div>
            <div class="col-5"><input type="text" class="form-control persian-datepicker" id="crm-log-next-date" autocomplete="off"></div>
            <div class="col-3"><input type="time" class="form-control" id="crm-log-next-time" value="10:00"></div>
            <div class="col-4"><select class="form-select" id="crm-log-next-assignee"></select></div>
        </div>
        <div class="alert alert-danger py-2 small d-none" id="crm-log-error"></div>
        <button class="btn btn-success w-100" id="crm-log-save">ثبت</button>
    </div>
</div>

<!-- Project (میز کار) -->
<div class="offcanvas offcanvas-bottom crm-sheet work-sheet" tabindex="-1" id="work-project-sheet" aria-labelledby="work-project-title">
    <div class="offcanvas-header border-bottom flex-wrap gap-2">
        <div class="min-w-0 flex-grow-1">
            <h5 class="offcanvas-title text-truncate" id="work-project-title">پروژه</h5>
            <div class="d-flex align-items-center gap-2 small mt-1" id="work-project-sub"></div>
        </div>
        <div class="dropdown d-none" id="work-project-menu">
            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="dropdown" aria-label="تنظیمات پروژه"><i class="bi bi-three-dots-vertical"></i></button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><button class="dropdown-item" type="button" data-act="edit"><i class="bi bi-pencil me-2"></i>ویرایش پروژه</button></li>
                <li><hr class="dropdown-divider"></li>
                <li><button class="dropdown-item text-danger" type="button" data-act="delete"><i class="bi bi-trash me-2"></i>حذف پروژه</button></li>
            </ul>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="بستن"></button>
    </div>
    <ul class="nav nav-tabs crm-subnav flex-nowrap px-2 pt-2" id="work-project-tabs">
        <li class="nav-item"><button class="nav-link active" type="button" data-tab="overview">نمای کلی</button></li>
        <li class="nav-item"><button class="nav-link" type="button" data-tab="tasks">کارها <span class="badge text-bg-light" data-count="tasks"></span></button></li>
        <li class="nav-item"><button class="nav-link" type="button" data-tab="board">تابلو</button></li>
        <li class="nav-item"><button class="nav-link" type="button" data-tab="done">انجام‌شده <span class="badge text-bg-light" data-count="done"></span></button></li>
        <li class="nav-item"><button class="nav-link" type="button" data-tab="documents">مستندات <span class="badge text-bg-light" data-count="documents"></span></button></li>
        <li class="nav-item"><button class="nav-link" type="button" data-tab="reports">گزارش‌ها <span class="badge text-bg-light" data-count="reports"></span></button></li>
    </ul>
    <div class="offcanvas-body" id="work-project-body"></div>
</div>

<!-- Meeting (میز کار) -->
<div class="offcanvas offcanvas-bottom crm-sheet work-sheet" tabindex="-1" id="work-meeting-sheet" aria-labelledby="work-meeting-title">
    <div class="offcanvas-header border-bottom">
        <h5 class="offcanvas-title text-truncate" id="work-meeting-title">جلسه</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="بستن"></button>
    </div>
    <div class="offcanvas-body" id="work-meeting-body"></div>
</div>

<!-- Chat conversation on phones (میز کار) -->
<div class="offcanvas offcanvas-bottom crm-sheet chat-sheet" tabindex="-1" id="chat-sheet" aria-label="گفتگو">
    <div class="d-flex flex-column h-100" id="chat-view-mobile"></div>
</div>

<!-- Request / idea / staff file (میز کار) -->
<div class="offcanvas offcanvas-bottom crm-sheet" tabindex="-1" id="office-sheet" aria-labelledby="office-sheet-title">
    <div class="offcanvas-header border-bottom">
        <h5 class="offcanvas-title text-truncate" id="office-sheet-title"></h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="بستن"></button>
    </div>
    <div class="offcanvas-body" id="office-sheet-body"></div>
</div>