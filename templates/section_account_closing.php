<div class="row g-3">
    <div class="col-12">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h5 class="mb-1"><i class="bi bi-lock-fill me-2"></i>بستن حساب و مدیریت دوره مالی</h5>
                <p class="text-muted small mb-0">بستن کامل دوره مالی با ترازنامه، مانده مشتریان و تأمین‌کنندگان، چک‌ها، نقدینگی، انبار، شرکا و ذخیره پیش‌نویس سند افتتاحیه در اسنپ‌شات</p>
            </div>
            <button class="btn btn-primary" id="new-account-close-btn" data-permission-action="manage_settings">
                <i class="bi bi-plus-lg me-2"></i>بستن حساب جدید
            </button>
        </div>
    </div>

    <div class="col-lg-4 col-md-6">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">آخرین تاریخ بسته‌شده</div>
                <div class="h4 mb-0" id="closing-last-date">—</div>
            </div>
        </div>
    </div>
    <div class="col-lg-4 col-md-6">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">شروع پیشنهادی دوره بعد</div>
                <div class="h4 mb-0" id="closing-suggested-start">—</div>
            </div>
        </div>
    </div>
    <div class="col-lg-4 col-md-6">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">تعداد بستن‌های انجام‌شده</div>
                <div class="h4 mb-0" id="closing-count">0</div>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="alert alert-info mb-0" id="closing-lock-alert" style="display:none;">
            <i class="bi bi-shield-lock me-2"></i>
            <strong>دوره قفل است:</strong> تراکنش‌های تا تاریخ <span id="closing-lock-date"></span> قابل ویرایش نیستند.
            برای اصلاح، آخرین بستن حساب را بازگشایی کنید.
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>تاریخچه بستن حساب</span>
                <button class="btn btn-sm btn-outline-secondary" id="refresh-closings-btn"><i class="bi bi-arrow-repeat"></i></button>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>عنوان</th>
                            <th>بازه</th>
                            <th>نوع</th>
                            <th class="text-end">سود خالص</th>
                            <th>وضعیت</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="closings-list-tbody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card">
            <div class="card-header">تاریخچه سهم شرکا</div>
            <div class="table-responsive" style="max-height: 420px;">
                <table class="table table-sm mb-0">
                    <thead class="table-light sticky-top">
                        <tr>
                            <th>شریک</th>
                            <th>سهم</th>
                            <th>از</th>
                            <th>تا</th>
                        </tr>
                    </thead>
                    <tbody id="share-history-tbody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-12" id="closing-detail-panel" style="display:none;">
        <div class="card border-primary">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <span id="closing-detail-title">جزئیات بستن حساب</span>
                <button class="btn btn-sm btn-outline-light" id="close-detail-panel-btn"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="card-body" id="closing-detail-body"></div>
        </div>
    </div>
</div>

<!-- Modal: بستن حساب -->
<div class="modal fade" id="accountCloseModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-lock me-2"></i>بستن حساب</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-pills mb-3" id="close-wizard-steps" role="tablist">
                    <li class="nav-item"><button class="nav-link active" data-bs-toggle="pill" data-bs-target="#close-step-1" type="button">۱. تنظیمات دوره</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#close-step-2" type="button" disabled id="close-step-2-tab">۲. ترازنامه و جزئیات</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#close-step-3" type="button" disabled id="close-step-3-tab">۳. شرکا و سهم‌ها</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#close-step-4" type="button" disabled id="close-step-4-tab">۴. تأیید نهایی</button></li>
                </ul>

                <div class="tab-content">
                    <div class="tab-pane fade show active" id="close-step-1">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">نوع بستن</label>
                                <select class="form-select" id="close-type">
                                    <option value="annual">بستن سالانه</option>
                                    <option value="interim">بستن میان‌دوره‌ای</option>
                                    <option value="partner_restructure">بستن + تغییر ساختار شرکا</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">روش محاسبه سود</label>
                                <select class="form-select" id="close-calc-method">
                                    <option value="standard">استاندارد (فروش − خرید)</option>
                                    <option value="cogs">بر اساس بهای تمام‌شده (FIFO)</option>
                                    <option value="inventory">بر اساس موجودی انبار</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">تاریخ شروع دوره</label>
                                <input type="text" class="form-control persian-datepicker" id="close-period-start" autocomplete="off">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">تاریخ پایان دوره (تاریخ بستن)</label>
                                <input type="text" class="form-control persian-datepicker" id="close-period-end" autocomplete="off">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">عنوان (اختیاری)</label>
                                <input type="text" class="form-control" id="close-title" placeholder="مثلاً بستن سال ۱۴۰۴">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-bold">یادداشت</label>
                                <textarea class="form-control" id="close-notes" rows="2"></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="close-step-2">
                        <div id="close-preview-financial"></div>
                    </div>

                    <div class="tab-pane fade" id="close-step-3">
                        <div class="alert alert-secondary small">
                            سهم‌های زیر برای <strong>دوره بعد</strong> (از روز بعد از تاریخ بستن) اعمال می‌شوند.
                            در بستن «تغییر ساختار شرکا» می‌توانید سهم‌ها را تغییر دهید، شریک جدید اضافه یا شریک را حذف کنید.
                        </div>
                        <div class="table-responsive mb-3">
                            <table class="table table-sm">
                                <thead class="table-light">
                                    <tr>
                                        <th>شریک</th>
                                        <th style="width:120px">سهم فعلی</th>
                                        <th style="width:140px">سهم دوره بعد (%)</th>
                                        <th style="width:100px">عملیات</th>
                                    </tr>
                                </thead>
                                <tbody id="close-partners-tbody"></tbody>
                            </table>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-success" id="close-add-partner-row-btn">
                            <i class="bi bi-person-plus me-1"></i>افزودن شریک جدید
                        </button>
                        <hr>
                        <h6>تسهیم سود این دوره (بر اساس سهم فعلی)</h6>
                        <div id="close-period-allocation"></div>
                    </div>

                    <div class="tab-pane fade" id="close-step-4">
                        <div class="card border-warning mb-3">
                            <div class="card-header bg-warning bg-opacity-25">
                                <i class="bi bi-database-fill-check me-2"></i>پشتیبان کامل (الزامی قبل از بستن)
                            </div>
                            <div class="card-body">
                                <p class="small text-muted mb-2">قبل از بستن حساب، یک نسخه پشتیبان <strong>کامل</strong> از تمام داده‌های برنامه ایجاد کنید. تا پشتیبان ساخته نشود، دکمه بستن فعال نمی‌شود.</p>
                                <button type="button" class="btn btn-warning" id="close-create-backup-btn">
                                    <i class="bi bi-database-down me-2"></i>ایجاد پشتیبان کامل
                                </button>
                                <div id="close-backup-status" class="mt-2 small text-muted">هنوز پشتیبان برای این بستن ایجاد نشده است.</div>
                            </div>
                        </div>
                        <div id="close-final-summary"></div>
                        <div class="form-check form-switch mt-3">
                            <input class="form-check-input" type="checkbox" id="close-post-profit">
                            <label class="form-check-label" for="close-post-profit">
                                ثبت سهم سود در حساب شخصی شرکا (تراکنش نقدی در روز بعد از تاریخ بستن)
                            </label>
                        </div>
                        <div class="alert alert-warning mt-3 small mb-0">
                            <strong>توجه:</strong> پس از بستن، ویرایش تراکنش‌های این دوره مسدود می‌شود.
                            فقط آخرین بستن قابل بازگشایی است.
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer d-flex justify-content-between">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-primary" id="close-wizard-prev-btn" style="display:none;">مرحله قبل</button>
                    <button type="button" class="btn btn-primary" id="close-wizard-next-btn">پیش‌نمایش و ادامه</button>
                    <button type="button" class="btn btn-success" id="close-execute-btn" style="display:none;">
                        <i class="bi bi-lock-fill me-1"></i>اجرای بستن حساب
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
