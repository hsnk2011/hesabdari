<div class="modal fade" id="changePasswordModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">تغییر رمز عبور</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="change-password-form">
                    <div class="alert alert-danger form-error" style="display:none;"></div>
                    <div class="mb-3">
                        <label for="current-password" class="form-label">رمز عبور فعلی</label>
                        <input type="password" class="form-control" id="current-password" required>
                    </div>
                    <div class="mb-3">
                        <label for="new-password" class="form-label">رمز عبور جدید</label>
                        <input type="password" class="form-control" id="new-password" required minlength="8">
                    </div>
                    <div class="mb-3">
                        <label for="new-password-confirm" class="form-label">تکرار رمز عبور جدید</label>
                        <input type="password" class="form-control" id="new-password-confirm" required minlength="8">
                    </div>
                    <button type="submit" class="btn btn-success w-100">ذخیره تغییرات</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="cashCheckModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">وصول چک</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="cash-check-form">
                    <div class="alert alert-danger form-error" style="display:none;"></div>
                    <input type="hidden" id="cash-check-id">
                    <p id="cash-check-help-text">چک به کدام حساب واریز شود؟</p>
                    <div class="mb-3">
                        <label for="cash-check-account-id" class="form-label">انتخاب حساب</label>
                        <select class="form-select" id="cash-check-account-id" required></select>
                    </div>
                    <div class="mb-3">
                        <label for="cash-check-date" class="form-label" id="cash-check-date-label">تاریخ وصول</label>
                        <input type="text" class="form-control persian-datepicker" id="cash-check-date" required>
                        <small class="form-text text-muted">می‌تواند با تاریخ سررسید متفاوت باشد</small>
                    </div>
                    <button type="submit" class="btn btn-success w-100">ثبت</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ادغام دو شخص (مشتری/تأمین‌کننده) -->
<div class="modal fade" id="mergePersonsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-intersect me-2"></i>ادغام اشخاص</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <div class="form-label">شخص انتخاب‌شده</div>
                    <div class="fw-bold" id="merge-persons-source"></div>
                </div>
                <div class="mb-3">
                    <label for="merge-persons-target" class="form-label">ادغام با</label>
                    <select class="form-select" id="merge-persons-target" data-placeholder="جستجو و انتخاب شخص..."></select>
                    <div class="form-text">مشتری + تأمین‌کننده: هر دو یک شخص می‌شوند (هم مشتری و هم تأمین‌کننده). دو شخص هم‌نوع (تکراری): اسناد این شخص به شخص انتخاب‌شده منتقل و این شخص حذف می‌شود.</div>
                </div>
                <div id="merge-persons-plan"></div>
                <div class="form-check mt-2" id="merge-persons-ack-wrap" style="display:none;">
                    <input class="form-check-input" type="checkbox" id="merge-persons-ack">
                    <label class="form-check-label text-danger" for="merge-persons-ack">متوجه هستم که این ادغام برگشت‌ناپذیر است.</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
                <button type="button" class="btn btn-primary" id="merge-persons-submit" disabled>ادغام</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="confirmationModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="confirmationModalTitle">تأیید عملیات</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p id="confirmationModalBody">آیا از انجام این عملیات مطمئن هستید؟</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
                <button type="button" class="btn btn-danger" id="confirmActionBtn">تأیید</button>
            </div>
        </div>
    </div>
</div>

<!-- مودال تبدیل جزئی فاکتور امانی -->
<div class="modal fade" id="partialConsignmentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-arrow-return-left me-2"></i>بازگردانی از امانی</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    اقلامی که می‌خواهید از فاکتور امانی بازگردانده شود را انتخاب کنید. این اقلام به فاکتور خرید یا فروش جدید منتقل می‌شوند.
                </div>
                
                <!-- اطلاعات فاکتور امانی -->
                <div class="card mb-3">
                    <div class="card-header">
                        <strong>اطلاعات فاکتور امانی</strong>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <strong>شماره فاکتور:</strong> <span id="pc-invoice-id"></span>
                            </div>
                            <div class="col-md-4">
                                <strong>تاریخ:</strong> <span id="pc-invoice-date"></span>
                            </div>
                            <div class="col-md-4">
                                <strong id="pc-person-label">مشتری/تامین‌کننده:</strong> <span id="pc-invoice-person"></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- انتخاب اقلام -->
                <div class="card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <strong>انتخاب اقلام</strong>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="pc-select-all">
                            <label class="form-check-label" for="pc-select-all">انتخاب همه</label>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 50px;">انتخاب</th>
                                        <th>محصول</th>
                                        <th>ابعاد</th>
                                        <th>تعداد</th>
                                        <th>قیمت واحد</th>
                                        <th>جمع</th>
                                    </tr>
                                </thead>
                                <tbody id="pc-items-body"></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- خلاصه انتخاب -->
                <div class="card bg-light">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <strong>تعداد اقلام انتخابی:</strong> <span id="pc-selected-count">0</span>
                            </div>
                            <div class="col-md-4">
                                <strong>جمع کل انتخابی:</strong> <span id="pc-selected-total">0</span>
                            </div>
                            <div class="col-md-4">
                                <strong>تخفیف متناسب:</strong> <span id="pc-selected-discount">0</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <input type="hidden" id="pc-invoice-type">
                <input type="hidden" id="pc-invoice-id-input">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
                <button type="button" class="btn btn-success" id="pc-confirm-btn" disabled>
                    <i class="bi bi-check-lg me-2"></i>تبدیل به فاکتور اصلی
                </button>
            </div>
        </div>
    </div>
</div>

<!-- تاریخچه فاکتور و اقلام مرتبط -->
<div class="modal fade" id="invoiceHistoryModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title"><i class="bi bi-clock-history me-2"></i><span id="invoice-history-title">تاریخچه فاکتور</span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="invoice-history-nav" class="d-flex flex-wrap align-items-center gap-2 mb-2 d-none">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="invoice-history-back">
                        <i class="bi bi-arrow-right me-1"></i>بازگشت
                    </button>
                    <nav class="small" id="invoice-history-breadcrumb"></nav>
                </div>
                <ul class="nav nav-tabs mb-3" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#invoice-history-tab-changes" type="button" role="tab">
                            <i class="bi bi-pencil-square me-1"></i>تاریخچه تغییرات <span class="badge bg-secondary" id="invoice-history-count">0</span>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#invoice-history-tab-links" type="button" role="tab">
                            <i class="bi bi-diagram-3 me-1"></i><span id="invoice-history-links-label">اقلام مرتبط</span> <span class="badge bg-secondary" id="invoice-history-links-count">0</span>
                        </button>
                    </li>
                </ul>
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="invoice-history-tab-changes" role="tabpanel">
                        <div id="invoice-history-timeline"></div>
                    </div>
                    <div class="tab-pane fade" id="invoice-history-tab-links" role="tabpanel">
                        <div id="invoice-history-links"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">بستن</button>
            </div>
        </div>
    </div>
</div>

<!-- اقلام فروخته‌شده روی فاکتور امانی خرید -->
<div class="modal fade" id="soldConsignmentReviewModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-arrow-left-right me-2"></i>اقلام امانی فروخته‌شده</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info mb-3">
                    اقلامی که از فاکتور امانی خرید فروخته شده‌اند باید به فاکتور خرید تبدیل شوند. بقیه اقلام روی فاکتور امانی می‌مانند.
                </div>
                <div id="sold-consignment-empty" class="text-center text-muted py-4" style="display:none;">
                    موردی یافت نشد.
                </div>
                <div id="sold-consignment-table-wrap" class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>فاکتور امانی</th>
                                <th>تأمین‌کننده</th>
                                <th>محصول</th>
                                <th>شناسه</th>
                                <th>فاکتور فروش</th>
                                <th>مشتری</th>
                                <th>تاریخ فروش</th>
                            </tr>
                        </thead>
                        <tbody id="sold-consignment-body"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <span class="me-auto text-muted" id="sold-consignment-count"></span>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">بستن</button>
                <button type="button" class="btn btn-success" id="sold-consignment-convert-btn">
                    <i class="bi bi-check-lg me-1"></i>تبدیل به فاکتور خرید
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Global search (Ctrl+K) -->
<div class="modal fade" id="quickNavModal" tabindex="-1" aria-labelledby="quickNavModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title" id="quickNavModalLabel"><i class="bi bi-search me-2"></i>جستجو در کل برنامه</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-2">
                <input type="search" class="form-control mb-2" id="quick-nav-search" placeholder="نام، شماره فاکتور، شناسه انبار، تلفن، چک، حساب..." autocomplete="off" enterkeyhint="search">
                <div class="list-group list-group-flush gs-results" id="quick-nav-list"></div>
                <div class="text-muted small mt-2 d-flex justify-content-between px-1">
                    <span>↑↓ انتخاب · Enter باز کردن</span>
                    <span>میانبر: <kbd>Ctrl</kbd> + <kbd>K</kbd></span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Bank reconciliation workspace -->
<div class="modal fade" id="bankReconcileModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-0"><i class="bi bi-arrow-left-right me-2"></i>میز کار تطبیق بانکی</h5>
                    <small class="text-muted" id="bank-reconcile-subtitle"></small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="bank-reconcile-import-id">
                <input type="hidden" id="bank-reconcile-account-id">
                <div class="row g-3 mb-3" id="bank-reconcile-summary"></div>
                <div class="row g-2 mb-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label">فیلتر وضعیت</label>
                        <select class="form-select" id="bank-reconcile-status-filter">
                            <option value="">همه</option>
                            <option value="unmatched" selected>تطبیق‌نشده</option>
                            <option value="matched">تطبیق‌شده</option>
                            <option value="ignored">نادیده</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">جستجو در شرح / طرف / پیگیری</label>
                        <input type="text" class="form-control" id="bank-reconcile-search" placeholder="متن جستجو...">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">بازه تاریخ (±روز)</label>
                        <input type="number" class="form-control" id="bank-reconcile-date-window" value="2" min="0" max="30">
                    </div>
                    <div class="col-md-3 d-flex gap-2">
                        <button type="button" class="btn btn-success flex-fill" id="bank-reconcile-smart-btn">
                            <i class="bi bi-magic me-1"></i>پیشنهاد هوشمند
                        </button>
                        <button type="button" class="btn btn-outline-primary" id="bank-reconcile-refresh-btn" title="بروزرسانی">
                            <i class="bi bi-arrow-repeat"></i>
                        </button>
                    </div>
                </div>
                <div class="table-responsive" style="max-height: calc(100vh - 280px);">
                    <table class="table table-sm table-hover align-middle">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th>تاریخ</th>
                                <th>شرح</th>
                                <th>طرف</th>
                                <th class="text-end">مبلغ</th>
                                <th class="text-end">مانده</th>
                                <th>پیگیری</th>
                                <th>وضعیت</th>
                                <th style="min-width:220px">عملیات</th>
                            </tr>
                        </thead>
                        <tbody id="bank-reconcile-items-tbody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Create transaction from bank item -->
<div class="modal fade" id="bankCreateFromItemModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">ثبت در سیستم از ردیف بانک</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="bank-create-item-id">
                <input type="hidden" id="bank-create-bank-account-id">
                <input type="hidden" id="bank-create-bank-amount">
                <p class="small text-muted" id="bank-create-item-info"></p>
                <div class="mb-3">
                    <label class="form-label">نوع ثبت</label>
                    <select class="form-select" id="bank-create-type">
                        <option value="expense">هزینه (برداشت)</option>
                        <option value="income">درآمد شرکت (واریز)</option>
                        <option value="payment">پرداخت به شخص</option>
                        <option value="receipt">دریافت از شخص</option>
                        <option value="transfer">انتقال وجه بین حساب‌ها</option>
                        <option value="split">تقسیم بین چند شخص</option>
                    </select>
                </div>
                <div class="mb-3" id="bank-create-category-wrap">
                    <label class="form-label" id="bank-create-category-label">دسته‌بندی هزینه</label>
                    <select class="form-select" id="bank-create-category"></select>
                    <div class="form-text">از لیست دسته‌بندی‌های تعریف‌شده انتخاب کنید یا عنوان جدید را تایپ کنید.</div>
                </div>
                <div class="mb-3 d-none" id="bank-create-person-wrap">
                    <label class="form-label" id="bank-create-person-label">شخص</label>
                    <select class="form-select" id="bank-create-person-type">
                        <option value="customer">مشتری</option>
                        <option value="supplier">تامین‌کننده</option>
                        <option value="partner">شریک</option>
                        <option value="contact">سایر اشخاص</option>
                    </select>
                    <select class="form-select mt-2" id="bank-create-person-id"></select>
                </div>
                <div class="mb-3 d-none" id="bank-create-transfer-wrap">
                    <label class="form-label" id="bank-create-transfer-label">حساب طرف انتقال</label>
                    <select class="form-select" id="bank-create-other-account"></select>
                    <div class="form-text" id="bank-create-transfer-hint"></div>
                </div>
                <div class="mb-3 d-none" id="bank-create-split-wrap">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <label class="form-label mb-0">سهم‌ها</label>
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-outline-secondary" id="bank-create-split-equal-btn">تقسیم مساوی</button>
                            <button type="button" class="btn btn-outline-primary" id="bank-create-split-add-btn"><i class="bi bi-plus"></i> سهم</button>
                        </div>
                    </div>
                    <div class="small text-muted mb-2" id="bank-create-split-txn-hint"></div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-2">
                            <thead>
                                <tr>
                                    <th style="width:28%">نوع شخص</th>
                                    <th>شخص</th>
                                    <th style="width:24%" class="text-end">مبلغ</th>
                                    <th style="width:40px"></th>
                                </tr>
                            </thead>
                            <tbody id="bank-create-split-rows"></tbody>
                        </table>
                    </div>
                    <div class="small" id="bank-create-split-total-line"></div>
                </div>
                <div class="mb-3">
                    <label class="form-label">شرح</label>
                    <textarea class="form-control" id="bank-create-description" rows="4"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
                <button type="button" class="btn btn-success" id="bank-create-submit-btn">ثبت و تطبیق</button>
            </div>
        </div>
    </div>
</div>

<!-- Smart bank match proposals confirmation -->
<div class="modal fade" id="bankProposalsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-0"><i class="bi bi-magic me-2"></i>پیشنهادهای هوشمند تطبیق</h5>
                    <small class="text-muted">موارد انتخاب‌شده فقط پس از تأیید شما ثبت می‌شوند. جزئیات بانک به شرح اضافه می‌شود؛ مبلغ اصلی ثابت می‌ماند و کارمزد در صورت اختلاف به‌صورت هزینه جدا (فقط روی حساب مبدأ انتقال) ثبت می‌شود.</small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="bank-proposals-select-all">انتخاب همه</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="bank-proposals-select-none">حذف انتخاب</button>
                    <span class="align-self-center small text-muted" id="bank-proposals-count"></span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th style="width:36px"></th>
                                <th>امتیاز</th>
                                <th>بانک</th>
                                <th>دفتر</th>
                                <th>دلایل</th>
                            </tr>
                        </thead>
                        <tbody id="bank-proposals-tbody"></tbody>
                    </table>
                </div>
                <div id="bank-proposals-empty" class="text-muted text-center py-4 d-none">پیشنهاد مطمئنی یافت نشد.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
                <button type="button" class="btn btn-success" id="bank-proposals-confirm-btn">
                    <i class="bi bi-check2-all me-1"></i>تأیید و ثبت انتخاب‌شده‌ها
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Manual match picker -->
<div class="modal fade" id="bankManualMatchModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">تطبیق دستی با دفتر حساب</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="bank-manual-item-id">
                <div class="row g-2 mb-3">
                    <div class="col-md-8">
                        <input type="text" class="form-control" id="bank-manual-search" placeholder="جستجو در دفتر...">
                    </div>
                    <div class="col-md-4">
                        <button type="button" class="btn btn-outline-primary w-100" id="bank-manual-search-btn">جستجو</button>
                    </div>
                </div>
                <div class="list-group" id="bank-manual-results" style="max-height: 400px; overflow-y: auto;"></div>
            </div>
        </div>
    </div>
</div>

<!-- SMS send confirmation (required before any SMS) -->
<div class="modal fade" id="smsConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-chat-dots me-2"></i>تأیید ارسال پیامک</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning py-2 mb-3">
                    پیامک فقط پس از تأیید شما ارسال می‌شود.
                </div>
                <input type="hidden" id="sms-confirm-invoice-id">
                <input type="hidden" id="sms-confirm-invoice-type">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="sms-confirm-template">قالب</label>
                        <select class="form-select" id="sms-confirm-template">
                            <option value="sales">فاکتور فروش</option>
                            <option value="ready">آماده تحویل</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="sms-confirm-phone">شماره گیرنده</label>
                        <select class="form-select" id="sms-confirm-phone"></select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="sms-confirm-phone-manual">شماره دستی</label>
                        <input type="text" class="form-control" id="sms-confirm-phone-manual" placeholder="0912xxxxxxx" dir="ltr">
                    </div>
                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="sms-confirm-include-link" checked>
                            <label class="form-check-label" for="sms-confirm-include-link">درج لینک مشاهده فاکتور در پیامک</label>
                        </div>
                        <div class="form-text" id="sms-confirm-link-preview" dir="ltr"></div>
                        <button type="button" class="btn btn-link btn-sm text-danger p-0" id="sms-confirm-revoke-links"><i class="bi bi-link-45deg"></i> باطل کردن لینک‌های قبلی این فاکتور</button>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="sms-confirm-message">متن پیامک</label>
                        <textarea class="form-control" id="sms-confirm-message" rows="6" dir="rtl"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">انصراف</button>
                <button type="button" class="btn btn-outline-primary" id="sms-confirm-reload-btn">بازخوانی قالب</button>
                <button type="button" class="btn btn-success" id="sms-confirm-send-btn">
                    <i class="bi bi-send-check me-1"></i>تأیید و ارسال
                </button>
            </div>
        </div>
    </div>
</div>