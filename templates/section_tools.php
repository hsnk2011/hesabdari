<div class="tab-pane fade" id="tools" role="tabpanel" aria-labelledby="tools-tab">
    <h4>ابزارها</h4>

    <ul class="nav nav-pills mb-3" id="tools-sub-tab" role="tablist">
        <li class="nav-item" role="presentation" data-permission="import_export_data">
            <button class="nav-link" id="import-export-tab" data-bs-toggle="tab" data-bs-target="#import-export-pane" type="button" role="tab">
                <i class="bi bi-arrow-left-right me-2"></i>ورود/خروج داده
            </button>
        </li>
        <li class="nav-item" role="presentation" data-permission="manage_stocktaking">
            <button class="nav-link" id="stocktaking-tab" data-bs-toggle="tab" data-bs-target="#stocktaking-pane" type="button" role="tab">
                <i class="bi bi-clipboard-data me-2"></i>انبارگردانی
            </button>
        </li>
        <li class="nav-item" role="presentation" data-permission="manage_installments">
            <button class="nav-link" id="installments-tab" data-bs-toggle="tab" data-bs-target="#installments-pane" type="button" role="tab">
                <i class="bi bi-calendar2-week me-2"></i>اقساط
            </button>
        </li>
        <li class="nav-item" role="presentation" data-permission-any="manage_customers,view_reports">
            <button class="nav-link" id="master-data-tab" data-bs-toggle="tab" data-bs-target="#master-data-pane" type="button" role="tab">
                <i class="bi bi-person-badge me-2"></i>فروشندگان و گروه مشتری
            </button>
        </li>
        <li class="nav-item" role="presentation" data-permission="manage_transactions">
            <button class="nav-link" id="recurring-budget-tab" data-bs-toggle="tab" data-bs-target="#recurring-budget-pane" type="button" role="tab">
                <i class="bi bi-arrow-repeat me-2"></i>هزینه تکراری و بودجه
            </button>
        </li>
        <li class="nav-item" role="presentation" data-permission="manage_settings">
            <button class="nav-link" id="trash-tab" data-bs-toggle="tab" data-bs-target="#trash-pane" type="button" role="tab">
                <i class="bi bi-trash3 me-2"></i>سطل بازیافت
            </button>
        </li>
        <li class="nav-item" role="presentation" data-permission="manage_bank_imports">
            <button class="nav-link" id="bank-import-settings-tab" data-bs-toggle="tab" data-bs-target="#bank-import-pane" type="button" role="tab">
                <i class="bi bi-file-earmark-spreadsheet me-2"></i>ورود صورتحساب بانکی
            </button>
        </li>
    </ul>

    <div class="tab-content" id="tools-sub-tab-content">
        <!-- Import/Export Pane -->
        <div class="tab-pane fade" id="import-export-pane" role="tabpanel">
            <div class="row">
                <div class="col-md-11 mx-auto">

                    <!-- خروج داده -->
                    <div class="card mb-3">
                        <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="bi bi-download me-2"></i>خروج داده</h5>
                            <div class="d-flex gap-2">
                                <button class="btn btn-sm btn-outline-light" id="export-all-json-btn" title="خروجی کامل JSON">
                                    <i class="bi bi-file-earmark-code me-1"></i>خروجی کامل JSON
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row mb-3">
                                <div class="col-md-4">
                                    <label for="export-data-type" class="form-label fw-bold">نوع داده</label>
                                    <select class="form-select" id="export-data-type">
                                        <optgroup label="اشخاص">
                                            <option value="customers">مشتریان</option>
                                            <option value="suppliers">تأمین‌کنندگان</option>
                                            <option value="partners">شرکا</option>
                                        </optgroup>
                                        <optgroup label="محصولات و انبار">
                                            <option value="products">محصولات</option>
                                            <option value="product_categories">دسته‌بندی محصولات</option>
                                            <option value="product_collections">کلکسیون محصولات</option>
                                            <option value="warehouses">انبارها</option>
                                            <option value="inventory_items">موجودی انبار</option>
                                        </optgroup>
                                        <optgroup label="مالی">
                                            <option value="accounts">حساب‌های بانکی</option>
                                            <option value="payments">پرداخت‌ها</option>
                                            <option value="checks">چک‌ها</option>
                                            <option value="expenses">هزینه‌ها</option>
                                            <option value="incomes">درآمدها</option>
                                            <option value="installments">اقساط</option>
                                        </optgroup>
                                        <optgroup label="فاکتورها">
                                            <option value="sales_invoices">فاکتورهای فروش</option>
                                            <option value="sales_invoice_items">آیتم‌های فاکتور فروش</option>
                                            <option value="purchase_invoices">فاکتورهای خرید</option>
                                            <option value="purchase_invoice_items">آیتم‌های فاکتور خرید</option>
                                            <option value="return_invoices">فاکتورهای برگشت</option>
                                            <option value="return_invoice_items">آیتم‌های فاکتور برگشت</option>
                                        </optgroup>
                                        <optgroup label="سایر">
                                            <option value="quotations">پیش‌فاکتورها</option>
                                            <option value="quotation_items">آیتم‌های پیش‌فاکتور</option>
                                        </optgroup>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="export-format" class="form-label fw-bold">فرمت خروجی</label>
                                    <select class="form-select" id="export-format">
                                        <option value="csv">CSV</option>
                                        <option value="json">JSON</option>
                                    </select>
                                </div>
                                <div class="col-md-5 d-flex align-items-end gap-2">
                                    <button class="btn btn-outline-primary" id="export-load-items-btn">
                                        <i class="bi bi-search me-1"></i>بارگذاری آیتم‌ها
                                    </button>
                                    <button class="btn btn-success" id="export-csv-btn" disabled>
                                        <i class="bi bi-download me-1"></i>دانلود انتخاب‌شده‌ها
                                    </button>
                                    <button class="btn btn-outline-success" id="export-all-btn" disabled>
                                        <i class="bi bi-download me-1"></i>دانلود همه
                                    </button>
                                </div>
                            </div>

                            <!-- جستجو و آمار -->
                            <div class="row mb-2" id="export-toolbar" style="display: none;">
                                <div class="col-md-4">
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                                        <input type="text" class="form-control" id="export-search" placeholder="جستجو...">
                                    </div>
                                </div>
                                <div class="col-md-8 d-flex align-items-center gap-3">
                                    <span class="badge bg-primary" id="export-total-badge">کل: 0</span>
                                    <span class="badge bg-success" id="export-selected-badge">انتخاب شده: 0</span>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="checkbox" id="export-select-all">
                                        <label class="form-check-label" for="export-select-all">انتخاب همه</label>
                                    </div>
                                </div>
                            </div>

                            <!-- جدول آیتم‌ها -->
                            <div id="export-items-container" style="display: none;">
                                <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                                    <table class="table table-sm table-hover table-striped" id="export-items-table">
                                        <thead class="table-light sticky-top">
                                            <tr id="export-items-thead"></tr>
                                        </thead>
                                        <tbody id="export-items-tbody"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ورود داده -->
                    <div class="card mb-3">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0"><i class="bi bi-upload me-2"></i>ورود داده (CSV)</h5>
                        </div>
                        <div class="card-body">
                            <div class="row mb-3">
                                <div class="col-md-4">
                                    <label for="import-csv-type" class="form-label fw-bold">نوع داده</label>
                                    <select class="form-select" id="import-csv-type">
                                        <optgroup label="اشخاص">
                                            <option value="customers">مشتریان</option>
                                            <option value="suppliers">تأمین‌کنندگان</option>
                                            <option value="partners">شرکا</option>
                                        </optgroup>
                                        <optgroup label="محصولات و انبار">
                                            <option value="products">محصولات</option>
                                            <option value="product_categories">دسته‌بندی محصولات</option>
                                            <option value="warehouses">انبارها</option>
                                        </optgroup>
                                        <optgroup label="مالی">
                                            <option value="accounts">حساب‌های بانکی</option>
                                            <option value="expenses">هزینه‌ها</option>
                                            <option value="incomes">درآمدها</option>
                                        </optgroup>
                                    </select>
                                </div>
                                <div class="col-md-5">
                                    <label for="import-csv-file" class="form-label fw-bold">فایل CSV</label>
                                    <input type="file" class="form-control" id="import-csv-file" accept=".csv,.txt">
                                </div>
                                <div class="col-md-3 d-flex align-items-end">
                                    <button class="btn btn-primary w-100" id="import-csv-btn">
                                        <i class="bi bi-upload me-1"></i>ورود داده
                                    </button>
                                </div>
                            </div>
                            <div id="import-help-box" class="alert alert-info mb-0">
                                <i class="bi bi-info-circle-fill me-2"></i>
                                <strong>راهنما:</strong> فایل CSV باید دارای خط اول هدر باشد. هدرها می‌توانند فارسی یا انگلیسی باشند.
                                <div id="import-columns-hint" class="mt-2 small"></div>
                            </div>
                            <!-- نتیجه ورود -->
                            <div id="import-result" class="mt-3" style="display: none;"></div>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- Stocktaking Pane -->
        <div class="tab-pane fade" id="stocktaking-pane" role="tabpanel">
            <div class="row g-3">
                <div class="col-12">
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>مراحل انبارگردانی:</strong>
                        ۱) چاپ برگه شمارش (با موجودی سیستم) یا فرم خام برای اقلام خارج از سیستم و شمارش روی انبار —
                        ۲) ثبت شمارش واقعی در سیستم و مقایسه با موجودی سیستم —
                        ۳) ذخیره و اعمال اختلاف‌ها. محصولات جدید را بعداً با «افزودن ردیف» وارد کنید.
                    </div>
                </div>

                <div class="col-lg-4 col-md-6">
                    <div class="card h-100 border-0 shadow-sm">
                        <div class="card-body">
                            <div class="text-muted small">کل سشن‌ها</div>
                            <div class="display-6 fw-bold" id="stocktaking-total-sessions">0</div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4 col-md-6">
                    <div class="card h-100 border-0 shadow-sm">
                        <div class="card-body">
                            <div class="text-muted small">پیش‌نویس / در جریان</div>
                            <div class="display-6 fw-bold text-warning" id="stocktaking-open-sessions">0</div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4 col-md-6">
                    <div class="card h-100 border-0 shadow-sm">
                        <div class="card-body">
                            <div class="text-muted small">اختلاف خالص ثبت‌شده</div>
                            <div class="display-6 fw-bold text-primary" id="stocktaking-net-diff">0</div>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                        <h5 class="mb-0"><i class="bi bi-clipboard-data me-2"></i>مدیریت انبارگردانی</h5>
                        <div class="d-flex gap-2">
                            <button class="btn btn-outline-secondary" id="refresh-stocktaking-btn"><i class="bi bi-arrow-repeat me-2"></i>به‌روزرسانی</button>
                            <button class="btn btn-outline-dark" id="stocktaking-pane-print-btn"><i class="bi bi-printer me-2"></i>چاپ برگه شمارش</button>
                            <input type="number" class="form-control stocktaking-blank-rows-input" min="1" max="500" value="20" dir="ltr" inputmode="numeric" title="تعداد ردیف فرم خام" placeholder="ردیف" style="width: 90px;" aria-label="تعداد ردیف فرم خام">
                            <button class="btn btn-outline-secondary" id="stocktaking-pane-blank-print-btn"><i class="bi bi-file-earmark me-2"></i>چاپ فرم خام</button>
                            <button class="btn btn-outline-primary" id="warehouse-inout-print-btn"><i class="bi bi-box-arrow-in-down me-2"></i>چاپ فرم ورود/خروج</button>
                            <button class="btn btn-primary" id="new-stocktaking-btn"><i class="bi bi-plus me-2"></i>انبارگردانی جدید</button>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-striped align-middle">
                            <thead>
                                <tr>
                                    <th>شناسه</th>
                                    <th>تاریخ</th>
                                    <th>انبار</th>
                                    <th>عنوان</th>
                                    <th>وضعیت</th>
                                    <th>ردیف‌ها</th>
                                    <th>اختلاف خالص</th>
                                    <th>مازاد / کسری</th>
                                    <th>عملیات</th>
                                </tr>
                            </thead>
                            <tbody id="stocktaking-list-tbody"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Stocktaking Modal -->
        <div class="modal fade" id="stocktaking-modal" tabindex="-1">
            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title mb-1"><i class="bi bi-clipboard-check me-2"></i><span id="stocktaking-modal-title">انبارگردانی جدید</span></h5>
                            <div class="text-muted small">موجودی سیستم را بارگذاری کنید، شمارش واقعی را وارد کنید و اختلاف‌ها را ذخیره کنید.</div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <form id="stocktaking-form">
                            <input type="hidden" id="stocktaking-id">
                            <div class="row g-3 mb-3">
                                <div class="col-lg-4">
                                    <label class="form-label fw-bold">عنوان</label>
                                    <input type="text" class="form-control" id="stocktaking-title" placeholder="مثلاً انبارگردانی پایان ماه">
                                </div>
                                <div class="col-lg-2 col-md-4">
                                    <label class="form-label fw-bold">تاریخ</label>
                                    <input type="text" class="form-control persian-datepicker" id="stocktaking-date" autocomplete="off">
                                </div>
                                <div class="col-lg-3 col-md-4">
                                    <label class="form-label fw-bold">انبار</label>
                                    <select class="form-select" id="stocktaking-warehouse"></select>
                                </div>
                                <div class="col-lg-3 col-md-4">
                                    <label class="form-label fw-bold">وضعیت</label>
                                    <select class="form-select" id="stocktaking-status">
                                        <option value="draft">پیش‌نویس</option>
                                        <option value="in_progress">در جریان</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-bold">توضیحات</label>
                                    <textarea class="form-control" id="stocktaking-notes" rows="2" placeholder="توضیحات تکمیلی، علت شمارش، محدوده بررسی و ..."></textarea>
                                </div>
                            </div>

                            <div class="card border-0 bg-light mb-3">
                                <div class="card-body">
                                    <div class="row g-2 align-items-end">
                                        <div class="col-lg-5">
                                            <label class="form-label fw-bold">جستجوی سریع در snapshot</label>
                                            <input type="text" class="form-control" id="stocktaking-snapshot-search" placeholder="نام محصول، کد طرح، رنگ یا ابعاد">
                                        </div>
                                        <div class="col-lg-3">
                                            <button type="button" class="btn btn-outline-primary w-100" id="load-stocktaking-snapshot-btn">
                                                <i class="bi bi-boxes me-2"></i>بارگذاری موجودی فعلی
                                            </button>
                                        </div>
                                        <div class="col-lg-2">
                                            <button type="button" class="btn btn-outline-success w-100" id="add-stocktaking-row-btn">
                                                <i class="bi bi-plus me-2"></i>افزودن ردیف
                                            </button>
                                        </div>
                                        <div class="col-lg-2">
                                            <button type="button" class="btn btn-outline-secondary w-100" id="clear-stocktaking-rows-btn">
                                                <i class="bi bi-trash3 me-2"></i>پاک‌سازی
                                            </button>
                                        </div>
                                        <div class="col-lg-2">
                                            <button type="button" class="btn btn-outline-dark w-100" id="print-stocktaking-sheet-btn">
                                                <i class="bi bi-printer me-2"></i>چاپ برگه شمارش
                                            </button>
                                        </div>
                                        <div class="col-lg-1">
                                            <label class="form-label fw-bold">ردیف خام</label>
                                            <input type="number" class="form-control stocktaking-blank-rows-input" min="1" max="500" value="20" dir="ltr" inputmode="numeric" title="تعداد ردیف فرم خام" aria-label="تعداد ردیف فرم خام">
                                        </div>
                                        <div class="col-lg-2">
                                            <button type="button" class="btn btn-outline-secondary w-100" id="print-stocktaking-blank-sheet-btn">
                                                <i class="bi bi-file-earmark me-2"></i>چاپ فرم خام
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="table-responsive">
                                <table class="table table-sm align-middle">
                                    <thead>
                                        <tr>
                                            <th style="min-width: 220px;">محصول</th>
                                            <th class="stocktaking-warehouse-col" style="min-width: 140px;">انبار</th>
                                            <th style="min-width: 120px;">ابعاد</th>
                                            <th class="text-end" style="min-width: 110px;">سیستم</th>
                                            <th class="text-end" style="min-width: 110px;">واقعی</th>
                                            <th class="text-end" style="min-width: 110px;">اختلاف</th>
                                            <th style="min-width: 220px;">یادداشت ردیف</th>
                                            <th style="width: 70px;"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="stocktaking-items-tbody"></tbody>
                                </table>
                            </div>

                            <div class="row g-3 mt-2">
                                <div class="col-lg-3 col-md-6">
                                    <div class="border rounded p-3 bg-white h-100">
                                        <div class="text-muted small">تعداد ردیف‌ها</div>
                                        <div class="h4 mb-0" id="stocktaking-summary-rows">0</div>
                                    </div>
                                </div>
                                <div class="col-lg-3 col-md-6">
                                    <div class="border rounded p-3 bg-white h-100">
                                        <div class="text-muted small">جمع سیستم</div>
                                        <div class="h4 mb-0" id="stocktaking-summary-system">0</div>
                                    </div>
                                </div>
                                <div class="col-lg-3 col-md-6">
                                    <div class="border rounded p-3 bg-white h-100">
                                        <div class="text-muted small">جمع شمارش واقعی</div>
                                        <div class="h4 mb-0" id="stocktaking-summary-counted">0</div>
                                    </div>
                                </div>
                                <div class="col-lg-3 col-md-6">
                                    <div class="border rounded p-3 bg-white h-100">
                                        <div class="text-muted small">اختلاف خالص</div>
                                        <div class="h4 mb-0" id="stocktaking-summary-diff">0</div>
                                    </div>
                                </div>
                            </div>

                            <div class="alert alert-warning mt-3 mb-0">
                                <strong>نکته:</strong> مقدار «سیستم» از موجودی ثبت‌شده فعلی می‌آید. در حالت «همه انبارها»، انبار هر ردیف جداگانه ثبت و اعمال می‌شود. اگر کسری قابل تسویه در سیستم نباشد، اعمال نهایی انجام نمی‌شود.
                            </div>
                        </form>
                    </div>
                    <div class="modal-footer d-flex justify-content-between flex-wrap gap-2">
                        <div class="text-muted small">ثبت نهایی، اختلاف‌های مازاد و کسری را روی موجودی اعمال می‌کند.</div>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-outline-dark" id="print-stocktaking-modal-footer-btn"><i class="bi bi-printer me-1"></i>چاپ برگه</button>
                            <input type="number" class="form-control stocktaking-blank-rows-input" min="1" max="500" value="20" dir="ltr" inputmode="numeric" title="تعداد ردیف فرم خام" aria-label="تعداد ردیف فرم خام" style="width: 90px;">
                            <button type="button" class="btn btn-outline-secondary" id="print-stocktaking-blank-footer-btn"><i class="bi bi-file-earmark me-1"></i>فرم خام</button>
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">بستن</button>
                            <button type="button" class="btn btn-outline-primary" id="save-stocktaking-draft-btn">ذخیره پیش‌نویس</button>
                            <button type="button" class="btn btn-primary" id="save-and-apply-stocktaking-btn">ذخیره و اعمال</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Stocktaking Print Sheet Modal -->
        <div class="modal fade" id="stocktaking-print-modal" tabindex="-1">
            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title mb-1"><i class="bi bi-printer me-2"></i>چاپ برگه شمارش فیزیکی</h5>
                            <div class="text-muted small">مرحله ۱: موجودی سیستم را بارگذاری و چاپ کنید، یا فرم خام برای اقلام خارج از سیستم بگیرید و روی انبار شمارش دستی انجام دهید.</div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3 mb-3">
                            <div class="col-lg-4">
                                <label class="form-label fw-bold">عنوان</label>
                                <input type="text" class="form-control" id="stocktaking-print-title" placeholder="مثلاً انبارگردانی پایان ماه">
                            </div>
                            <div class="col-lg-3">
                                <label class="form-label fw-bold">تاریخ</label>
                                <input type="text" class="form-control persian-datepicker" id="stocktaking-print-date" autocomplete="off">
                            </div>
                            <div class="col-lg-3">
                                <label class="form-label fw-bold">انبار</label>
                                <select class="form-select" id="stocktaking-print-warehouse"></select>
                            </div>
                            <div class="col-lg-2 d-flex align-items-end">
                                <button type="button" class="btn btn-outline-primary w-100" id="load-stocktaking-print-preview-btn">
                                    <i class="bi bi-boxes me-1"></i>بارگذاری
                                </button>
                            </div>
                            <div class="col-lg-8">
                                <label class="form-label fw-bold">جستجو در موجودی</label>
                                <input type="text" class="form-control" id="stocktaking-print-search" placeholder="نام محصول، کد طرح، رنگ یا ابعاد">
                            </div>
                            <div class="col-lg-4 d-flex align-items-end">
                                <button type="button" class="btn btn-dark w-100" id="print-stocktaking-print-modal-btn" disabled>
                                    <i class="bi bi-printer me-2"></i>چاپ برگه شمارش
                                </button>
                            </div>
                            <div class="col-lg-3">
                                <label class="form-label fw-bold">تعداد ردیف فرم خام</label>
                                <input type="number" class="form-control stocktaking-blank-rows-input" id="stocktaking-print-blank-rows" min="1" max="500" value="20" dir="ltr" inputmode="numeric" placeholder="مثلاً 18">
                            </div>
                            <div class="col-lg-3 d-flex align-items-end">
                                <button type="button" class="btn btn-outline-dark w-100" id="print-stocktaking-blank-modal-btn">
                                    <i class="bi bi-file-earmark me-2"></i>چاپ فرم خام
                                </button>
                            </div>
                        </div>

                        <div class="alert alert-light border mb-3">
                            پس از چاپ برگه شمارش، ستون‌های «شمارش فیزیکی» و «یادداشت» را روی کاغذ پر کنید. سپس از «انبارگردانی جدید» همان لیست را بارگذاری کرده و اعداد واقعی را در سیستم ثبت کنید.
                            برای محصولاتی که در سیستم نیستند، «چاپ فرم خام» را بزنید و مشخصات را با دست بنویسید؛ بعداً با «افزودن ردیف» وارد کنید.
                        </div>

                        <div class="table-responsive">
                            <table class="table table-sm table-bordered align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>محصول</th>
                                        <th>کد طرح</th>
                                        <th>رنگ</th>
                                        <th>ابعاد</th>
                                        <th title="شناسه انبار = serial_id مثل INV-001266">شناسه انبار</th>
                                        <th class="stocktaking-print-warehouse-col">انبار</th>
                                        <th class="text-end">موجودی سیستم</th>
                                    </tr>
                                </thead>
                                <tbody id="stocktaking-print-preview-tbody">
                                    <tr><td colspan="8" class="text-center text-muted">برای پیش‌نمایش، «بارگذاری» را بزنید.</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">بستن</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Warehouse In/Out blank print modal -->
        <div class="modal fade" id="warehouse-inout-print-modal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title mb-1"><i class="bi bi-box-arrow-in-down me-2"></i>چاپ فرم ورود / خروج انبار</h5>
                            <div class="text-muted small">فرم خالی را پرینت بگیرید؛ عنوان، انبار و اقلام را روی کاغذ بنویسید.</div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">تعداد ردیف خالی</label>
                                <input type="number" class="form-control" id="warehouse-inout-print-blank-rows" min="1" max="500" value="15" dir="ltr" inputmode="numeric">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">بستن</button>
                        <button type="button" class="btn btn-primary" id="print-warehouse-inout-modal-btn">
                            <i class="bi bi-printer me-2"></i>چاپ فرم
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Installments Pane -->
        <div class="tab-pane fade" id="installments-pane" role="tabpanel">
            <div class="row">
                <div class="col-md-11 mx-auto">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5><i class="bi bi-calendar2-week me-2"></i>مدیریت اقساط</h5>
                        <button class="btn btn-primary" id="new-installment-btn"><i class="bi bi-plus me-2"></i>طرح اقساط جدید</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-striped table-sm">
                            <thead>
                                <tr>
                                    <th>شماره قسط</th>
                                    <th>توضیحات</th>
                                    <th>مبلغ</th>
                                    <th>پرداخت شده</th>
                                    <th>مانده</th>
                                    <th>سررسید</th>
                                    <th>تاریخ پرداخت</th>
                                    <th>وضعیت</th>
                                    <th>عملیات</th>
                                </tr>
                            </thead>
                            <tbody id="installments-list-tbody"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Installment Modal -->
        <div class="modal fade" id="installment-modal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-calendar2-week me-2"></i>ایجاد طرح اقساط</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <form id="installment-form">
                            <!-- انتخاب فاکتور با جستجو -->
                            <div class="row mb-3">
                                <div class="col-md-12">
                                    <label class="form-label fw-bold">فاکتور</label>
                                    <div class="input-group mb-2">
                                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                                        <input type="text" class="form-control" id="installment-invoice-search" placeholder="جستجوی فاکتور (شماره، نام مشتری/تأمین‌کننده)..." autocomplete="off">
                                    </div>
                                    <select class="form-select" id="installment-invoice-id" size="5" style="max-height: 200px; overflow-y: auto;"></select>
                                    <input type="hidden" id="installment-invoice-type">
                                    <div class="form-text" id="installment-invoice-info"></div>
                                </div>
                            </div>
                            <hr>
                            <div class="row mb-3">
                                <div class="col-md-3">
                                    <label class="form-label">مبلغ کل</label>
                                    <input type="text" class="form-control" id="installment-total-amount" placeholder="مبلغ کل">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">تعداد اقساط</label>
                                    <input type="number" class="form-control" id="installment-count" min="1" value="3">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">تاریخ شروع</label>
                                    <input type="text" class="form-control persian-datepicker" id="installment-start-date" placeholder="1404/01/01" autocomplete="off">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">مدت کل (ماه)</label>
                                    <input type="number" class="form-control" id="installment-duration-months" min="1" value="6" placeholder="ماه">
                                </div>
                                <div class="col-md-2 d-flex align-items-end">
                                    <button type="button" class="btn btn-outline-primary w-100" id="auto-calc-installments">
                                        <i class="bi bi-calculator me-1"></i>محاسبه
                                    </button>
                                </div>
                            </div>
                            <div class="alert alert-info py-2 small" id="installment-calc-info">
                                <i class="bi bi-info-circle me-1"></i>
                                <strong>راهنما:</strong> مدت کل و تعداد اقساط را وارد کنید. اقساط به طور مساوی در طول مدت تعیین‌شده توزیع می‌شوند. مثال: ۶ قسط در ۶ ماه = هر ماه یک قسط.
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="form-label fw-bold mb-0">ردیف‌های اقساط</label>
                                <button type="button" class="btn btn-sm btn-outline-success" id="add-installment-row">
                                    <i class="bi bi-plus me-1"></i>افزودن ردیف
                                </button>
                            </div>
                            <div id="installment-rows"></div>
                        </form>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
                        <button type="button" class="btn btn-primary" id="save-installment-plan">
                            <i class="bi bi-check-lg me-1"></i>ذخیره طرح تقسیط
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Salespeople & customer groups -->
        <div class="tab-pane fade" id="master-data-pane" role="tabpanel">
            <div class="row g-3">
                <div class="col-lg-6" data-permission="manage_customers">
                    <div class="card h-100">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="bi bi-person-badge me-2"></i>فروشندگان</h5>
                            <button class="btn btn-sm btn-success" id="add-salesperson-btn"><i class="bi bi-plus-lg me-1"></i>فروشنده جدید</button>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive"><table class="table table-sm table-hover mb-0">
                                <thead><tr><th>نام</th><th>تلفن</th><th>پورسانت</th><th>وضعیت</th><th></th></tr></thead>
                                <tbody id="salespeople-tbody"></tbody>
                            </table></div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6" data-permission="manage_customers">
                    <div class="card h-100">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="bi bi-people me-2"></i>گروه‌های مشتری</h5>
                            <button class="btn btn-sm btn-success" id="add-customer-group-btn"><i class="bi bi-plus-lg me-1"></i>گروه جدید</button>
                        </div>
                        <div class="card-body p-0">
                            <div class="small text-muted px-3 pt-2">تخفیف گروه هنگام صدور فاکتور برای مشتریان آن گروه پیشنهاد می‌شود.</div>
                            <div class="table-responsive"><table class="table table-sm table-hover mb-0">
                                <thead><tr><th>نام گروه</th><th>تخفیف</th><th>تعداد مشتری</th><th></th></tr></thead>
                                <tbody id="customer-groups-tbody"></tbody>
                            </table></div>
                        </div>
                    </div>
                </div>
                <div class="col-12" data-permission="view_reports">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <h5 class="mb-0"><i class="bi bi-graph-up me-2"></i>گزارش فروش و پورسانت فروشندگان</h5>
                            <div class="d-flex gap-2 align-items-center">
                                <input type="text" class="form-control form-control-sm persian-datepicker" id="sp-report-from" style="width:8rem" placeholder="از تاریخ">
                                <input type="text" class="form-control form-control-sm persian-datepicker" id="sp-report-to" style="width:8rem" placeholder="تا تاریخ">
                                <button class="btn btn-sm btn-primary" id="sp-report-btn">نمایش</button>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive"><table class="table table-sm mb-0">
                                <thead><tr><th>فروشنده</th><th>تعداد فاکتور</th><th>فروش (پس از تخفیف)</th><th>مرجوعی</th><th>فروش خالص</th><th>وصول‌شده</th><th>درصد</th><th>پورسانت</th></tr></thead>
                                <tbody id="sp-report-tbody"><tr><td colspan="8" class="text-center text-muted">بازه را انتخاب و «نمایش» را بزنید.</td></tr></tbody>
                            </table></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recurring expenses & budgets -->
        <div class="tab-pane fade" id="recurring-budget-pane" role="tabpanel">
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-arrow-repeat me-2"></i>هزینه‌های تکراری (اجاره، قبوض، حقوق ...)</h5>
                    <button class="btn btn-sm btn-success" id="add-recurring-expense-btn"><i class="bi bi-plus-lg me-1"></i>هزینه تکراری جدید</button>
                </div>
                <div class="card-body p-0">
                    <div class="small text-muted px-3 pt-2">حالت «خودکار»: در سررسید، هزینه خودکار ثبت می‌شود. حالت «یادآوری»: فقط اعلان داده می‌شود و با دکمه «ثبت» آن را ثبت می‌کنید. ماه‌ها بر اساس تقویم شمسی محاسبه می‌شوند.</div>
                    <div class="table-responsive"><table class="table table-sm table-hover mb-0">
                        <thead><tr><th>عنوان</th><th>دسته</th><th>مبلغ</th><th>حساب</th><th>تکرار</th><th>نوبت بعد</th><th>حالت</th><th>وضعیت</th><th></th></tr></thead>
                        <tbody id="recurring-expenses-tbody"></tbody>
                    </table></div>
                </div>
            </div>
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h5 class="mb-0"><i class="bi bi-pie-chart me-2"></i>بودجه هزینه‌ها و عملکرد</h5>
                    <div class="d-flex gap-2 align-items-center">
                        <input type="text" class="form-control form-control-sm persian-datepicker" id="budget-report-from" style="width:8rem" placeholder="از تاریخ">
                        <input type="text" class="form-control form-control-sm persian-datepicker" id="budget-report-to" style="width:8rem" placeholder="تا تاریخ">
                        <button class="btn btn-sm btn-primary" id="budget-report-btn">نمایش</button>
                        <button class="btn btn-sm btn-success" id="add-budget-btn"><i class="bi bi-plus-lg me-1"></i>بودجه جدید</button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive"><table class="table table-sm mb-0">
                        <thead><tr><th>دسته</th><th>دوره</th><th>بودجه</th><th>هزینه واقعی</th><th>باقی‌مانده</th><th>مصرف</th><th></th></tr></thead>
                        <tbody id="budget-report-tbody"></tbody>
                    </table></div>
                    <div id="budget-unbudgeted" class="p-2 small"></div>
                </div>
            </div>
        </div>

        <!-- Recycle bin -->
        <div class="tab-pane fade" id="trash-pane" role="tabpanel">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h5 class="mb-0"><i class="bi bi-trash3 me-2"></i>سطل بازیافت (اسناد حذف‌شده)</h5>
                    <div class="d-flex gap-2">
                        <select class="form-select form-select-sm" id="trash-type-filter" style="width:auto">
                            <option value="">همه</option>
                            <option value="sales_invoice">فاکتور فروش</option>
                            <option value="purchase_invoice">فاکتور خرید</option>
                            <option value="sales_return">مرجوعی فروش</option>
                            <option value="purchase_return">مرجوعی خرید</option>
                            <option value="quotation">پیش فاکتور</option>
                            <option value="payment">تراکنش</option>
                            <option value="check">چک</option>
                            <option value="expense">هزینه</option>
                            <option value="income">درآمد</option>
                            <option value="customer">مشتری</option>
                            <option value="supplier">تأمین‌کننده</option>
                            <option value="contact">سایر اشخاص</option>
                            <option value="product">کالا</option>
                        </select>
                        <button class="btn btn-sm btn-outline-primary" id="trash-refresh-btn"><i class="bi bi-arrow-clockwise"></i></button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="small text-muted px-3 pt-2">مشتری، تأمین‌کننده، سایر اشخاص، هزینه و درآمد قابل بازیابی هستند. فاکتور و پیش‌فاکتور به‌صورت سند جدیدِ پیش‌پرشده باز می‌شوند تا پس از بررسی ذخیره شوند.</div>
                    <div class="table-responsive"><table class="table table-sm table-hover mb-0">
                        <thead><tr><th>عنوان</th><th>تاریخ سند</th><th>مبلغ</th><th>حذف توسط</th><th>زمان حذف</th><th></th></tr></thead>
                        <tbody id="trash-tbody"></tbody>
                    </table></div>
                </div>
            </div>
        </div>

        <!-- Bank Import Pane -->
        <div class="tab-pane fade" id="bank-import-pane" role="tabpanel">
            <div class="row">
                <div class="col-lg-11 mx-auto">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0"><i class="bi bi-file-earmark-spreadsheet me-2"></i>تطبیق صورتحساب بانکی</h5>
                        <small class="text-muted" id="bank-import-supported-formats">پشتیبانی: ملت (CSV)، دی، ملی، بلو/سامان (Excel)</small>
                    </div>
                    <div class="alert alert-info py-2">
                        مبالغ صورتحساب بانکی به <strong>ریال</strong> هستند و هنگام ورود به <strong><span data-currency-label>تومان</span></strong> تبدیل می‌شوند (تقسیم بر <span id="bank-import-rial-divisor">۱۰</span>).
                    </div>
                    <div class="card mb-3">
                        <div class="card-body">
                            <div class="row g-3 align-items-end">
                                <div class="col-md-3">
                                    <label for="bank-import-account" class="form-label">حساب بانکی</label>
                                    <select class="form-select" id="bank-import-account"></select>
                                </div>
                                <div class="col-md-4">
                                    <label for="bank-import-file" class="form-label">فایل صورتحساب (.csv / .xls / .xlsx)</label>
                                    <input type="file" class="form-control" id="bank-import-file" accept=".csv,.xls,.xlsx,text/csv,application/vnd.ms-excel">
                                </div>
                                <div class="col-md-2">
                                    <label for="bank-import-date-window" class="form-label">بازه تطبیق (روز)</label>
                                    <input type="number" class="form-control" id="bank-import-date-window" value="2" min="0" max="30">
                                </div>
                                <div class="col-md-3">
                                    <button class="btn btn-outline-secondary w-100 mb-1" type="button" id="bank-import-preview-btn">
                                        <i class="bi bi-eye me-1"></i>پیش‌نمایش
                                    </button>
                                    <button class="btn btn-primary w-100" type="button" id="bank-import-btn">
                                        <i class="bi bi-upload me-1"></i>ورود و تطبیق خودکار
                                    </button>
                                </div>
                            </div>
                            <div id="bank-import-preview" class="mt-3 d-none"></div>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-striped table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>تاریخ ورود</th>
                                    <th>حساب</th>
                                    <th>قالب</th>
                                    <th>بازه</th>
                                    <th>تعداد</th>
                                    <th>تطبیق</th>
                                    <th>وضعیت</th>
                                    <th>عملیات</th>
                                </tr>
                            </thead>
                            <tbody id="bank-imports-list-tbody"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
