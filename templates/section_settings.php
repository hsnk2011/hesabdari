<div class="tab-pane fade" id="settings" role="tabpanel" aria-labelledby="settings-tab">
    <h4>تنظیمات و مدیریت</h4>

    <ul class="nav nav-pills mb-3" id="settings-sub-tab" role="tablist">
        <li class="nav-item" role="presentation" data-permission="manage_settings">
            <button class="nav-link active" id="general-settings-tab" data-bs-toggle="tab" data-bs-target="#general-settings-pane" type="button" role="tab">
                <i class="bi bi-gear-fill me-2"></i>تنظیمات کلی
            </button>
        </li>
        <li class="nav-item" role="presentation" data-permission="manage_settings">
            <button class="nav-link" id="print-settings-tab" data-bs-toggle="tab" data-bs-target="#print-settings-pane" type="button" role="tab">
                <i class="bi bi-printer-fill me-2"></i>تنظیمات چاپ فاکتور
            </button>
        </li>
        <li class="nav-item" role="presentation" data-permission="manage_settings">
            <button class="nav-link" id="sms-settings-tab" data-bs-toggle="tab" data-bs-target="#sms-settings-pane" type="button" role="tab">
                <i class="bi bi-chat-dots-fill me-2"></i>تنظیمات پیامک
            </button>
        </li>
        <li class="nav-item" role="presentation" data-permission="manage_settings">
            <button class="nav-link" id="payment-gateway-settings-tab" data-bs-toggle="tab" data-bs-target="#payment-gateway-settings-pane" type="button" role="tab">
                <i class="bi bi-credit-card-2-front me-2"></i>درگاه پرداخت
            </button>
        </li>
        <li class="nav-item" role="presentation" data-permission="manage_settings">
            <button class="nav-link" id="integration-settings-tab" data-bs-toggle="tab" data-bs-target="#integration-settings-pane" type="button" role="tab">
                <i class="bi bi-link-45deg me-2"></i>اتصال فروشگاه آنلاین
            </button>
        </li>
        <li class="nav-item" role="presentation" data-permission="manage_collections">
            <button class="nav-link" id="collections-settings-tab" data-bs-toggle="tab" data-bs-target="#collections-pane" type="button" role="tab">
                <i class="bi bi-columns-gap me-2"></i>مدیریت کلکسیون‌ها
            </button>
        </li>
        <li class="nav-item" role="presentation" data-permission="manage_settings">
             <button class="nav-link" id="partners-settings-tab" data-bs-toggle="tab" data-bs-target="#partners-pane" type="button" role="tab">
                <i class="bi bi-people-fill me-2"></i>مدیریت شرکا
            </button>
        </li>
        <li class="nav-item" role="presentation" data-permission="manage_settings">
            <button class="nav-link" id="users-settings-tab" data-bs-toggle="tab" data-bs-target="#users-pane" type="button" role="tab">
                <i class="bi bi-person-gear me-2"></i>مدیریت کاربران
            </button>
        </li>
         <li class="nav-item" role="presentation" data-permission="manage_settings">
            <button class="nav-link" id="access-control-tab" data-bs-toggle="tab" data-bs-target="#access-control-pane" type="button" role="tab">
                <i class="bi bi-shield-check me-2"></i>کنترل دسترسی
            </button>
        </li>
        <li class="nav-item" role="presentation" data-permission="manage_settings">
            <button class="nav-link" id="account-closing-tab" data-bs-toggle="tab" data-bs-target="#account-closing-pane" type="button" role="tab">
                <i class="bi bi-lock-fill me-2"></i>بستن حساب
            </button>
        </li>
        <li class="nav-item" role="presentation" data-permission="manage_settings">
            <button class="nav-link" id="backup-restore-tab" data-bs-toggle="tab" data-bs-target="#backup-restore-pane" type="button" role="tab">
                <i class="bi bi-database-fill-gear me-2"></i>پشتیبان‌گیری و بازیابی
            </button>
        </li>
         <li class="nav-item" role="presentation" data-permission="manage_settings">
            <button class="nav-link" id="activity-log-settings-tab" data-bs-toggle="tab" data-bs-target="#activity-log-pane" type="button" role="tab">
                <i class="bi bi-clipboard2-data-fill me-2"></i>گزارش فعالیت کاربران
            </button>
        </li>
        <li class="nav-item" role="presentation" data-permission="manage_settings">
            <button class="nav-link" id="error-log-settings-tab" data-bs-toggle="tab" data-bs-target="#error-log-pane" type="button" role="tab">
                <i class="bi bi-bug-fill me-2"></i>لاگ فنی / خطاها
            </button>
        </li>
    </ul>

    <div class="tab-content" id="settings-sub-tab-content">
        <div class="tab-pane fade show active" id="general-settings-pane" role="tabpanel">
             <div class="row">
                <div class="col-md-10 mx-auto">
                    <form id="settings-form">
                        <div class="card mb-3">
                            <div class="card-header"><h5 class="mb-0">تنظیمات عمومی برنامه</h5></div>
                            <div class="card-body">
                                <div class="alert alert-danger form-error" style="display:none;"></div>
                                <div class="mb-3">
                                    <label for="setting-app-title" class="form-label">عنوان برنامه</label>
                                    <input type="text" class="form-control" id="setting-app-title" data-setting-key="app_title">
                                    <div class="form-text">این عنوان در هدر صفحه نمایش داده می‌شود.</div>
                                </div>
                                <hr>
                                <h6 class="mb-3">مدیریت مجموعه‌های تجاری</h6>
                                <div id="business-entities-container"></div>
                                <div class="input-group mt-3">
                                    <input type="text" class="form-control" id="new-entity-name" maxlength="255" placeholder="نام مجموعه جدید">
                                    <button type="button" class="btn btn-outline-primary" id="add-entity-btn">
                                        <i class="bi bi-plus-lg"></i> افزودن مجموعه
                                    </button>
                                </div>
                                <div class="form-text">مجموعهٔ جدید به‌صورت خودکار برای شما قابل دسترسی می‌شود؛ دسترسی سایر کاربران را از بخش مدیریت کاربران تنظیم کنید. مجموعه‌ای که اطلاعات ثبت‌شده دارد قابل حذف نیست.</div>
                                <hr>
                                <h6 class="mb-3">تنظیمات عمومی</h6>
                                <div class="mb-3">
                                     <label for="setting-standard-dimensions" class="form-label">لیست ابعاد استاندارد</label>
                                     <textarea class="form-control" id="setting-standard-dimensions" rows="3"></textarea>
                                     <div class="form-text">ابعاد مختلف را با کاما (,) از هم جدا کنید. این لیست در بخش کلکسیون‌ها و لیست قیمت استفاده می‌شود.</div>
                                </div>
                                <div class="mb-3">
                                     <label for="setting-expense-categories" class="form-label">لیست دسته‌بندی هزینه‌ها</label>
                                     <textarea class="form-control" id="setting-expense-categories" rows="3"></textarea>
                                     <label for="setting-income-categories" class="form-label mt-3">لیست دسته‌بندی درآمدها</label>
                                     <textarea class="form-control" id="setting-income-categories" rows="3"></textarea>
                                     <div class="form-text">دسته‌بندی‌های مختلف برای ثبت هزینه را با کاما (,) از هم جدا کنید.</div>
                                </div>
                                <hr>
                                <h6 class="mb-3">تنظیمات مالیات</h6>
                                <div class="mb-3">
                                    <label for="setting-default-tax-rate" class="form-label">نرخ مالیات پیش‌فرض (%)</label>
                                    <input type="number" class="form-control" id="setting-default-tax-rate" value="0" min="0" max="100" step="0.1" style="max-width: 200px;">
                                    <div class="form-text">این نرخ هنگام فعال‌سازی مالیات در فاکتور به صورت خودکار اعمال می‌شود (۰ = غیرفعال).</div>
                                </div>
                                <hr>
                                <h6 class="mb-3">واحد ارز</h6>
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label for="setting-currency-label" class="form-label">عنوان واحد ارز</label>
                                        <input type="text" class="form-control" id="setting-currency-label" placeholder="تومان">
                                        <div class="form-text">در تمام نمایش‌های مبلغ (فاکتور، گزارش، داشبورد) استفاده می‌شود.</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="setting-currency-rial-divisor" class="form-label">تقسیم‌کننده ریال</label>
                                        <input type="number" class="form-control" id="setting-currency-rial-divisor" min="1" step="1" placeholder="10">
                                        <div class="form-text">برای تبدیل مبالغ ریالی (صورتحساب بانکی، درگاه پرداخت) به واحد ارز سیستم. مثال: تومان = ۱۰، ریال = ۱.</div>
                                    </div>
                                </div>
                                <hr>
                                <h6 class="mb-3">قواعد محاسبه فرش</h6>
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label for="setting-dimension-cm-threshold" class="form-label">آستانه سانتی‌متر ابعاد</label>
                                        <input type="number" class="form-control" id="setting-dimension-cm-threshold" min="1" step="1" placeholder="15">
                                        <div class="form-text">ابعادی که کوچک‌تر از این مقدار باشند متر تلقی می‌شوند؛ بزرگ‌تر، سانتی‌متر.</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="setting-dimension-area-map" class="form-label">نگاشت ابعاد به مساحت (JSON)</label>
                                        <input type="text" class="form-control ltr" id="setting-dimension-area-map" placeholder='{"پادری":0.4}'>
                                        <div class="form-text">برای ابعاد اسمی که عدد نیستند. مثال: <code class="ltr">{"پادری":0.4}</code></div>
                                    </div>
                                </div>
                                <hr>
                                <h6 class="mb-3">گزارش‌ها و چک</h6>
                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <label for="setting-ar-aging-buckets" class="form-label">بازه‌های گزارش سررسید (روز)</label>
                                        <input type="text" class="form-control ltr" id="setting-ar-aging-buckets" placeholder="30,60,90">
                                        <div class="form-text">مرزهای سنی مطالبات/بدهی‌ها با کاما.</div>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="setting-check-discount-days" class="form-label">روزهای هر ماه در تنزیل چک</label>
                                        <input type="number" class="form-control" id="setting-check-discount-days" min="1" step="1" placeholder="30">
                                    </div>
                                    <div class="col-md-4">
                                        <label for="setting-share-link-ttl" class="form-label">اعتبار لینک اشتراک (ساعت)</label>
                                        <input type="number" class="form-control" id="setting-share-link-ttl" min="1" step="1" placeholder="168">
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <label for="setting-default-cash-account" class="form-label">صندوق پیش‌فرض</label>
                                        <select class="form-select" id="setting-default-cash-account">
                                            <option value="">— انتخاب نشده (اولین صندوق) —</option>
                                        </select>
                                        <div class="form-text">برای تنزیل چک و عملیات نقدی پیش‌فرض استفاده می‌شود.</div>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="setting-support-url" class="form-label">لینک پشتیبانی</label>
                                        <input type="text" class="form-control ltr" id="setting-support-url" placeholder="https://...">
                                        <div class="form-text">در صفحه ورود و صفحه بارکد محصول نمایش داده می‌شود.</div>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="setting-pwa-theme-color" class="form-label">رنگ تم اپلیکیشن (PWA)</label>
                                        <input type="color" class="form-control form-control-color" id="setting-pwa-theme-color" value="#4f46e5">
                                        <div class="form-text">در مانیفست اپلیکیشن (نصب روی گوشی) استفاده می‌شود.</div>
                                    </div>
                                </div>
                                <hr>
                                <h6 class="mb-3">سابقه قیمت در فاکتور</h6>
                                <p class="text-muted small mb-3">پیشنهاد قیمت متری از فاکتورهای قبلی هنگام ثبت ردیف خرید/فروش.</p>
                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" role="switch" id="setting-invoice-price-history-enabled" checked>
                                            <label class="form-check-label" for="setting-invoice-price-history-enabled">فعال‌سازی نمایش سابقه قیمت</label>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" role="switch" id="setting-invoice-price-history-similar" checked>
                                            <label class="form-check-label" for="setting-invoice-price-history-similar">شامل محصولات مشابه (نام/کالکشن)</label>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" role="switch" id="setting-invoice-price-history-person" checked>
                                            <label class="form-check-label" for="setting-invoice-price-history-person">اولویت با همان مشتری/تأمین‌کننده</label>
                                        </div>
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <label for="setting-invoice-price-history-limit" class="form-label">تعداد پیشنهاد قیمت</label>
                                        <input type="number" class="form-control" id="setting-invoice-price-history-limit" min="1" max="5" step="1" value="3">
                                        <div class="form-text">بین ۱ تا ۵ پیشنهاد متری.</div>
                                    </div>
                                </div>
                                <hr>
                                <h6 class="mb-3"><i class="bi bi-sliders me-1"></i>قوانین و رفتار سیستم</h6>
                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <label for="setting-negative-stock-policy" class="form-label">فروش بیش از موجودی</label>
                                        <select class="form-select" id="setting-negative-stock-policy">
                                            <option value="allow">مجاز (بدون پیام)</option>
                                            <option value="warn">مجاز با هشدار</option>
                                            <option value="block">ممنوع</option>
                                        </select>
                                        <div class="form-text">وقتی موجودی کافی نیست، کالای «مجازی» ثبت می‌شود تا بعداً به خرید وصل شود.</div>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="setting-credit-limit-policy" class="form-label">عبور از سقف اعتبار مشتری</label>
                                        <select class="form-select" id="setting-credit-limit-policy">
                                            <option value="off">بررسی نشود</option>
                                            <option value="warn">هشدار</option>
                                            <option value="block">ممنوع</option>
                                        </select>
                                        <div class="form-text">سقف اعتبار در فرم هر مشتری تعیین می‌شود.</div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label d-block">شماره‌گذاری فاکتور</label>
                                        <div class="form-text mt-0">شماره هر فاکتور همان شماره‌ای است که در فهرست فاکتورها می‌بینید؛ اگر در فرم فاکتور شماره دیگری بنویسید، همان شماره در همه‌جا نمایش داده می‌شود.</div>
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label for="setting-cron-mode" class="form-label">اجرای کارهای زمان‌بندی‌شده</label>
                                        <select class="form-select" id="setting-cron-mode">
                                            <option value="request">همراه با درخواست کاربران (بدون کران)</option>
                                            <option value="external">کران خارجی (توصیه‌شده)</option>
                                        </select>
                                        <div class="form-text">برای کران خارجی، دستور زیر را هر دقیقه اجرا کنید:<br>
                                            <code class="ltr d-inline-block">php <?php echo htmlspecialchars(str_replace('\\', '/', dirname(__DIR__)), ENT_QUOTES, 'UTF-8'); ?>/cron.php</code><br>
                                            یا آدرس <code class="ltr">cron.php?secret=کلید پشتیبان خودکار</code> را با سرویس کران فراخوانی کنید.</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label d-block">یادآور پیامکی به مشتری</label>
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" role="switch" id="setting-reminder-sms-enabled">
                                            <label class="form-check-label" for="setting-reminder-sms-enabled">ارسال یادآور اقساط و فاکتورهای سررسیدگذشته</label>
                                        </div>
                                        <div class="row g-2 mt-1">
                                            <div class="col-6">
                                                <label class="form-label small mb-0" for="setting-reminder-installment-days">روز قبل از سررسید قسط</label>
                                                <input type="number" class="form-control form-control-sm" id="setting-reminder-installment-days" min="0" max="30" value="2">
                                            </div>
                                            <div class="col-6">
                                                <label class="form-label small mb-0" for="setting-reminder-overdue-every">تکرار یادآور بدهی (روز)</label>
                                                <input type="number" class="form-control form-control-sm" id="setting-reminder-overdue-every" min="0" max="90" value="7">
                                            </div>
                                        </div>
                                        <input type="text" class="form-control form-control-sm ltr mt-2" id="setting-sms-verify-template-reminder" placeholder="نام قالب Verify کاوه‌نگار (فقط در حالت verify)">
                                        <div class="form-text">فقط وقتی پیامک در تنظیمات پیامک فعال باشد ارسال می‌شود (ساعت ۹ تا ۲۰). ۰ = یادآور بدهی ارسال نشود. در حالت Verify توکن‌ها: token=نام مشتری، token2=مبلغ، token3=تاریخ/شماره فاکتور.</div>
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label d-block">میز کار</label>
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" role="switch" id="setting-workspace-enabled" checked>
                                            <label class="form-check-label" for="setting-workspace-enabled">تب «میز کار» فعال باشد</label>
                                        </div>
                                        <div class="form-text">با خاموش کردن، تب میز کار، دکمه‌های مربوط (پرونده مشتری، ارسال در گفتگو، پروژه در فرم هزینه)، یادآوری‌ها و نوتیفیکیشن‌های آن برای همه کاربران خاموش می‌شود. اطلاعات و دسترسی نقش‌ها پاک نمی‌شوند و با روشن کردن دوباره برمی‌گردند.</div>
                                    </div>
                                </div>
                                <hr>
                                <h6 class="mb-3">ورود صورتحساب بانکی</h6>
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label for="setting-bank-import-common-fees" class="form-label">مبالغ رایج کارمزد بانکی (ریال)</label>
                                        <input type="text" class="form-control ltr" id="setting-bank-import-common-fees" placeholder="7000,12000,15000,18000">
                                        <div class="form-text">برای تشخیص خودکار کارمزدها هنگام تطبیق صورتحساب، با کاما.</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="setting-bank-import-fee-cap" class="form-label">سقف نسبت کارمزد به تراکنش</label>
                                        <input type="number" class="form-control ltr" id="setting-bank-import-fee-cap" min="0" max="1" step="0.001" placeholder="0.02">
                                        <div class="form-text">کارمزدهای بزرگ‌تر از این نسبتِ مبلغ تراکنش، مشکوک گزارش می‌شوند (۰٫۰۲ = ۲٪).</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-success w-100 mt-3"><i class="bi bi-check-lg me-2"></i>ذخیره تنظیمات کلی</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="tab-pane fade" id="print-settings-pane" role="tabpanel">
            <div class="row">
                <div class="col-md-10 mx-auto">
                    <div class="alert alert-info mb-3">
                        <i class="bi bi-info-circle-fill me-2"></i>
                        <strong>تنظیمات مجموعه:</strong> این تنظیمات برای مجموعه تجاری فعلی ذخیره می‌شود و روی تنظیمات پیش‌فرض (عمومی) اولویت دارد.
                    </div>
                    <form id="print-settings-form">
                        <div class="card mb-3">
                            <div class="card-header"><h5 class="mb-0"><i class="bi bi-printer me-2"></i>تنظیمات چاپ فاکتور</h5></div>
                            <div class="card-body">
                                <div class="alert alert-danger form-error" style="display:none;"></div>
                                
                                <h6 class="mb-3">اطلاعات شرکت/فروشگاه</h6>
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label for="print-company-name" class="form-label">نام شرکت/فروشگاه</label>
                                        <input type="text" class="form-control" id="print-company-name" placeholder="مثال: فروشگاه فرش شما">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="print-company-phone" class="form-label">شماره تماس</label>
                                        <input type="text" class="form-control" id="print-company-phone" placeholder="021-12345678">
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label for="print-company-address" class="form-label">آدرس</label>
                                    <textarea class="form-control" id="print-company-address" rows="2" placeholder="آدرس کامل فروشگاه"></textarea>
                                </div>
                                
                                <hr>
                                <h6 class="mb-3">لوگو</h6>
                                <div class="row mb-3">
                                    <div class="col-md-8">
                                        <label for="print-logo-url" class="form-label">آدرس لوگو (URL)</label>
                                        <input type="text" class="form-control" id="print-logo-url" placeholder="https://example.com/logo.png یا assets/logo.png">
                                        <div class="form-text">می‌توانید آدرس تصویر آنلاین یا مسیر فایل محلی را وارد کنید.</div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">پیش‌نمایش</label>
                                        <div id="logo-preview" class="border rounded p-2 text-center" style="min-height: 60px; background: #f8f9fa;">
                                            <img src="" id="logo-preview-img" style="max-height: 50px; max-width: 100%; display: none;">
                                            <span id="logo-preview-text" class="text-muted small">لوگویی انتخاب نشده</span>
                                        </div>
                                    </div>
                                </div>
                                
                                <hr>
                                <h6 class="mb-3">اطلاعات پرداخت</h6>
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label for="print-bank-name" class="form-label">نام بانک</label>
                                        <input type="text" class="form-control" id="print-bank-name" placeholder="مثال: بانک ملت">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="print-account-number" class="form-label">شماره حساب</label>
                                        <input type="text" class="form-control" id="print-account-number" placeholder="1234567890">
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label for="print-card-number" class="form-label">شماره کارت</label>
                                        <input type="text" class="form-control" id="print-card-number" placeholder="6037-xxxx-xxxx-xxxx">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="print-sheba-number" class="form-label">شماره شبا</label>
                                        <input type="text" class="form-control" id="print-sheba-number" placeholder="IR12 0120 0000 0000 1234 5678 90">
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label for="print-account-holder" class="form-label">نام صاحب حساب</label>
                                    <input type="text" class="form-control" id="print-account-holder" placeholder="نام و نام خانوادگی">
                                </div>
                                
                                <hr>
                                <h6 class="mb-3">قالب چاپ</h6>
                                <div class="mb-3">
                                    <label for="print-template" class="form-label">نوع قالب فاکتور</label>
                                    <select class="form-select" id="print-template">
                                        <option value="classic">کلاسیک (پیش‌فرض — فاصله بیشتر)</option>
                                        <option value="compact">فشرده (مناسب فاکتورهای شلوغ‌تر)</option>
                                        <option value="images">با تصویر (تصویر اصلی هر محصول)</option>
                                    </select>
                                    <div class="form-text">قالب فشرده پرداخت‌ها و جمع را کنار هم می‌چیند. قالب با تصویر ستون تصویر محصول را هم نشان می‌دهد.</div>
                                </div>
                                
                                <hr>
                                <h6 class="mb-3">تنظیمات نمایش</h6>
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="print-show-logo" checked>
                                            <label class="form-check-label" for="print-show-logo">نمایش لوگو در فاکتور</label>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="print-show-payment-info" checked>
                                            <label class="form-check-label" for="print-show-payment-info">نمایش اطلاعات پرداخت</label>
                                        </div>
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="print-show-company-info" checked>
                                            <label class="form-check-label" for="print-show-company-info">نمایش اطلاعات فروشگاه</label>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="print-show-signature-line">
                                            <label class="form-check-label" for="print-show-signature-line">نمایش خط امضا و مهر</label>
                                        </div>
                                    </div>
                                </div>
                                
                                <hr>
                                <h6 class="mb-3">متن‌های سفارشی</h6>
                                <div class="mb-3">
                                    <label for="print-header-text" class="form-label">متن سربرگ (اختیاری)</label>
                                    <input type="text" class="form-control" id="print-header-text" placeholder="مثال: با تشکر از خرید شما">
                                </div>
                                <div class="mb-3">
                                    <label for="print-footer-text" class="form-label">متن پاورقی</label>
                                    <textarea class="form-control" id="print-footer-text" rows="2" placeholder="مثال: کالای فروخته شده پس گرفته نمی‌شود."></textarea>
                                </div>
                                <div class="mb-3">
                                    <label for="print-terms" class="form-label">شرایط و قوانین</label>
                                    <textarea class="form-control" id="print-terms" rows="3" placeholder="شرایط فروش، گارانتی و ..."></textarea>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-success flex-grow-1"><i class="bi bi-check-lg me-2"></i>ذخیره تنظیمات چاپ مجموعه</button>
                            <button type="button" class="btn btn-outline-primary" id="preview-print-settings-btn"><i class="bi bi-eye me-2"></i>پیش‌نمایش</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="sms-settings-pane" role="tabpanel">
            <div class="row">
                <div class="col-lg-11 mx-auto">
                    <div class="alert alert-info mb-3">
                        <i class="bi bi-info-circle-fill me-2"></i>
                        ارسال پیامک از طریق پنل <strong>کاوه‌نگار</strong> انجام می‌شود. کلید API فقط روی سرور ذخیره می‌شود و در مرورگر نمایش کامل داده نمی‌شود.
                    </div>
                    <form id="sms-settings-form">
                        <div class="card mb-3">
                            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <h5 class="mb-0"><i class="bi bi-chat-dots me-2"></i>اتصال کاوه‌نگار</h5>
                                <div class="d-flex gap-2 align-items-center flex-wrap">
                                    <span id="sms-credit-badge" class="badge bg-secondary">اعتبار: —</span>
                                    <span id="sms-expire-badge" class="badge bg-secondary">انقضا: —</span>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-danger form-error" style="display:none;"></div>
                                <div class="alert alert-warning py-2">
                                    <i class="bi bi-shield-check me-1"></i>
                                    هیچ پیامکی به‌صورت خودکار ارسال نمی‌شود؛ قبل از هر ارسال، تأیید شما الزامی است. پیامک فقط برای مشتری (فاکتور فروش) فعال است.
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <div class="form-check form-switch mt-2">
                                            <input class="form-check-input" type="checkbox" id="sms-enabled">
                                            <label class="form-check-label" for="sms-enabled">فعال‌سازی ارسال پیامک</label>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="sms-mode" class="form-label">روش ارسال</label>
                                        <select class="form-select" id="sms-mode">
                                            <option value="verify">اعتبارسنجی کاوه‌نگار (بدون خط اختصاصی)</option>
                                            <option value="simple">ارسال ساده با خط/Sender</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4" id="sms-sender-wrap">
                                        <label for="sms-sender" class="form-label">خط ارسال‌کننده (Sender)</label>
                                        <input type="text" class="form-control" id="sms-sender" placeholder="9981803830" dir="ltr">
                                        <div class="form-text">شماره خط اختصاصی کاوه‌نگار، دقیقاً مثل پنل (مثال: <code dir="ltr">9981803830</code>)</div>
                                    </div>
                                </div>
                                <div class="mb-0">
                                    <label for="sms-api-key" class="form-label">کلید API کاوه‌نگار</label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" id="sms-api-key" placeholder="برای تغییر کلید جدید وارد کنید" autocomplete="off">
                                        <button type="button" class="btn btn-outline-secondary" id="sms-toggle-api-key" title="نمایش/مخفی"><i class="bi bi-eye"></i></button>
                                    </div>
                                    <div class="form-text" id="sms-api-key-hint">وضعیت کلید بارگذاری می‌شود…</div>
                                </div>
                            </div>
                        </div>

                        <div class="card mb-3" id="sms-verify-guide-card">
                            <div class="card-header"><h5 class="mb-0"><i class="bi bi-patch-check me-2"></i>الگوهای اعتبارسنجی در پنل کاوه‌نگار</h5></div>
                            <div class="card-body">
                                <p class="mb-2">در کنسول کاوه‌نگار → <strong>اعتبارسنجی</strong> → ایجاد الگو، این دو الگو را بسازید (پس از تأیید پنل قابل استفاده‌اند):</p>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">نام الگوی فاکتور فروش</label>
                                        <input type="text" class="form-control" id="sms-verify-template-sales" value="invoicesales" dir="ltr">
                                        <pre class="bg-light border rounded p-2 small mt-2 mb-0" dir="rtl" style="white-space:pre-wrap;">[نام فروشگاه]

مشتری گرامی %token

فاکتور شماره %token2 مورخ %token3 به مبلغ

%token10 [واحد ارز] صادر شد.

لینک فاکتور: %token20</pre>
                                        <div class="form-text">token=نام | token2=شماره فاکتور | token3=تاریخ | token10=مبلغ | token20=لینک — نام فروشگاه و واحد ارز از تنظیمات خوانده می‌شوند.</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">نام الگوی آماده تحویل</label>
                                        <input type="text" class="form-control" id="sms-verify-template-ready" value="invoiceready" dir="ltr">
                                        <pre class="bg-light border rounded p-2 small mt-2 mb-0" dir="rtl" style="white-space:pre-wrap;">[نام فروشگاه]

مشتری گرامی %token

سفارش با فاکتور %token2 آماده تحویل است.

مانده: %token10 [واحد ارز]

لینک فاکتور: %token20</pre>
                                        <div class="form-text">token=نام | token2=شماره فاکتور | token10=مانده | token20=لینک — نام فروشگاه و واحد ارز از تنظیمات خوانده می‌شوند.</div>
                                    </div>
                                </div>
                                <div class="alert alert-secondary mt-3 mb-0 small">
                                    لینک وب‌سایت در فرم الگو: دامنه‌ی فروشگاه شما (مثلاً <code>www.example.com</code>) — نام سرویس (نام فروشگاه) حتماً در متن باشد — متن فارسی — بدون محتوای تبلیغاتی.
                                </div>
                            </div>
                        </div>

                        <div class="card mb-3" id="sms-simple-templates-card">
                            <div class="card-header"><h5 class="mb-0"><i class="bi bi-file-text me-2"></i>متن‌های پیش‌نمایش / حالت ارسال ساده</h5></div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label" for="sms-template-sales">قالب فاکتور فروش (مشتری)</label>
                                    <textarea class="form-control font-monospace" id="sms-template-sales" rows="4" dir="rtl"></textarea>
                                </div>
                                <div class="mb-0">
                                    <label class="form-label" for="sms-template-ready">قالب آماده تحویل (مشتری)</label>
                                    <textarea class="form-control font-monospace" id="sms-template-ready" rows="4" dir="rtl"></textarea>
                                </div>
                                <div class="form-text mt-2">
                                    متغیرها:
                                    <code>{customer_name}</code>
                                    <code>{invoice_number}</code>
                                    <code>{date}</code>
                                    <code>{grand_total}</code>
                                    <code>{remaining}</code>
                                    <code>{invoice_link}</code>
                                    <code>{company_name}</code>
                                    <code>{company_phone}</code>
                                </div>
                            </div>
                        </div>

                        <div class="card mb-3" id="sms-check-alert-card">
                            <div class="card-header"><h5 class="mb-0"><i class="bi bi-bank2 me-2"></i>اعلان چک‌های نزدیک به سررسید (شرکا)</h5></div>
                            <div class="card-body">
                                <div class="alert alert-secondary small">
                                    روزانه برای شرکای انتخاب‌شده که شماره تماس دارند، خلاصه چک‌های نزدیک به سررسید ارسال می‌شود.
                                    شماره تماس را در «مدیریت شرکا» ثبت کنید.
                                </div>
                                <div class="row g-3 mb-3">
                                    <div class="col-md-4">
                                        <div class="form-check form-switch mt-2">
                                            <input class="form-check-input" type="checkbox" id="sms-check-alert-enabled">
                                            <label class="form-check-label" for="sms-check-alert-enabled">فعال‌سازی اعلان خودکار چک</label>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="sms-check-alert-days" class="form-label">چند روز قبل از سررسید؟</label>
                                        <input type="number" class="form-control" id="sms-check-alert-days" min="0" max="60" value="3">
                                    </div>
                                    <div class="col-md-4">
                                        <label for="sms-check-alert-verify-template" class="form-label">نام الگوی اعتبارسنجی (اختیاری)</label>
                                        <input type="text" class="form-control" id="sms-check-alert-verify-template" value="checkdue" dir="ltr">
                                    </div>
                                </div>
                                <div class="row g-3 mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label">نوع چک</label>
                                        <div class="d-flex flex-wrap gap-3">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="sms-check-type-received" value="received" checked>
                                                <label class="form-check-label" for="sms-check-type-received">دریافتی</label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="sms-check-type-payable" value="payable" checked>
                                                <label class="form-check-label" for="sms-check-type-payable">پرداختی</label>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">وضعیت‌های مشمول</label>
                                        <div class="d-flex flex-wrap gap-3">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="sms-check-status-in-hand" value="in_hand" checked>
                                                <label class="form-check-label" for="sms-check-status-in-hand">نزد ما</label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="sms-check-status-payable" value="payable" checked>
                                                <label class="form-check-label" for="sms-check-status-payable">پرداختنی</label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="sms-check-status-endorsed" value="endorsed">
                                                <label class="form-check-label" for="sms-check-status-endorsed">واگذار شده</label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label for="sms-check-alert-partners" class="form-label">شرکای دریافت‌کننده</label>
                                    <select class="form-select" id="sms-check-alert-partners" multiple></select>
                                    <div class="form-text">فقط شرکایی که تلفن دارند پیامک می‌گیرند.</div>
                                </div>
                                <div class="mb-3">
                                    <label for="sms-check-alert-template" class="form-label">قالب پیام (حالت ارسال ساده)</label>
                                    <textarea class="form-control font-monospace" id="sms-check-alert-template" rows="5" dir="rtl"></textarea>
                                    <div class="form-text mt-1">
                                        متغیرها:
                                        <code>{partner_name}</code>
                                        <code>{days}</code>
                                        <span class="text-muted">(روز مانده تا نزدیک‌ترین چک)</span>
                                        <code>{alert_days}</code>
                                        <span class="text-muted">(بازه تنظیم‌شده)</span>
                                        <code>{check_count}</code>
                                        <code>{total_amount}</code>
                                        <code>{checks_summary}</code>
                                        <span class="text-muted">(برای هر چک روز مانده هم نوشته می‌شود)</span>
                                        <code>{company_name}</code>
                                        <code>{company_phone}</code>
                                        <code>{date}</code>
                                    </div>
                                </div>
                                <div class="d-flex flex-wrap gap-2">
                                    <button type="button" class="btn btn-outline-primary btn-sm" id="sms-check-alert-run-btn">
                                        <i class="bi bi-send me-1"></i>ارسال دستی اعلان‌ها الان
                                    </button>
                                    <small class="text-muted align-self-center">ارسال طبق تنظیمات فعلی (بدون انتظار برای کرون روزانه)</small>
                                </div>
                            </div>
                        </div>

                        <div class="card mb-3">
                            <div class="card-header"><h5 class="mb-0"><i class="bi bi-send-check me-2"></i>ارسال آزمایشی</h5></div>
                            <div class="card-body">
                                <div class="row g-2 align-items-end">
                                    <div class="col-md-4">
                                        <label for="sms-test-receptor" class="form-label">شماره موبایل</label>
                                        <input type="text" class="form-control" id="sms-test-receptor" placeholder="09123456789" dir="ltr">
                                    </div>
                                    <div class="col-md-5" id="sms-test-message-wrap">
                                        <label for="sms-test-message" class="form-label">متن پیام (فقط حالت ساده)</label>
                                        <input type="text" class="form-control" id="sms-test-message" value="پیامک آزمایشی از سیستم حسابداری">
                                    </div>
                                    <div class="col-md-3">
                                        <button type="button" class="btn btn-outline-primary w-100" id="sms-test-send-btn">
                                            <i class="bi bi-send me-1"></i>ارسال تست
                                        </button>
                                    </div>
                                </div>
                                <div class="form-text mt-2">شماره باید موبایل ایران باشد؛ مثال: <code dir="ltr">09123456789</code></div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-success w-100 mb-4">
                            <i class="bi bi-check-lg me-2"></i>ذخیره تنظیمات پیامک
                        </button>
                    </form>

                    <div class="card mb-3">
                        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <h5 class="mb-0"><i class="bi bi-bar-chart me-2"></i>آمار ارسال (۷ روز اخیر)</h5>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="sms-stats-refresh-btn">
                                <i class="bi bi-arrow-clockwise me-1"></i>بروزرسانی آمار
                            </button>
                        </div>
                        <div class="card-body">
                            <div class="row g-3 text-center" id="sms-stats-row">
                                <div class="col-md-4">
                                    <div class="border rounded p-3 h-100">
                                        <div class="text-muted small">کل ارسال‌ها</div>
                                        <div class="fs-4 fw-semibold" id="sms-stat-total">—</div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="border rounded p-3 h-100 border-success">
                                        <div class="text-muted small">تحویل شده</div>
                                        <div class="fs-4 fw-semibold text-success" id="sms-stat-delivered">—</div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="border rounded p-3 h-100 border-danger">
                                        <div class="text-muted small">تحویل نشده</div>
                                        <div class="fs-4 fw-semibold text-danger" id="sms-stat-undelivered">—</div>
                                    </div>
                                </div>
                            </div>
                            <div class="form-text mt-2 mb-0" id="sms-stats-hint">در صورت محدودیت پنل کاوه‌نگار، آمار از تاریخچه همین سیستم محاسبه می‌شود.</div>
                        </div>
                    </div>

                    <div class="card mb-3">
                        <div class="card-header">
                            <ul class="nav nav-tabs card-header-tabs" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="sms-local-logs-tab" data-bs-toggle="tab" data-bs-target="#sms-local-logs-pane" type="button" role="tab">
                                        <i class="bi bi-clock-history me-1"></i>تاریخچه سیستم
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="sms-outbox-tab" data-bs-toggle="tab" data-bs-target="#sms-outbox-pane" type="button" role="tab">
                                        <i class="bi bi-mailbox me-1"></i>آخرین پیامک‌های کاوه‌نگار
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="sms-inbox-tab" data-bs-toggle="tab" data-bs-target="#sms-inbox-pane" type="button" role="tab">
                                        <i class="bi bi-inbox me-1"></i>پیامک‌های ورودی
                                    </button>
                                </li>
                            </ul>
                        </div>
                        <div class="card-body tab-content">
                            <div class="tab-pane fade show active" id="sms-local-logs-pane" role="tabpanel">
                                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                                    <div class="text-muted small" id="sms-local-logs-count">—</div>
                                    <div class="d-flex gap-2">
                                        <button type="button" class="btn btn-sm btn-outline-primary" id="sms-refresh-status-btn" title="بررسی وضعیت تحویل از کاوه‌نگار">
                                            <i class="bi bi-truck me-1"></i>بررسی تحویل
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="sms-local-logs-refresh-btn">
                                            <i class="bi bi-arrow-clockwise me-1"></i>بازخوانی
                                        </button>
                                    </div>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover align-middle mb-0" id="sms-local-logs-table">
                                        <thead class="table-light">
                                            <tr>
                                                <th>زمان</th>
                                                <th>گیرنده</th>
                                                <th>متن / قالب</th>
                                                <th>منبع</th>
                                                <th>وضعیت تحویل</th>
                                                <th>شناسه</th>
                                            </tr>
                                        </thead>
                                        <tbody id="sms-local-logs-body">
                                            <tr><td colspan="6" class="text-center text-muted py-4">در حال بارگذاری…</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div class="tab-pane fade" id="sms-outbox-pane" role="tabpanel">
                                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                                    <div class="text-muted small">آخرین پیامک‌های ارسال‌شده از پنل کاوه‌نگار (latestoutbox)</div>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="sms-outbox-refresh-btn">
                                        <i class="bi bi-arrow-clockwise me-1"></i>بازخوانی
                                    </button>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>زمان</th>
                                                <th>گیرنده</th>
                                                <th>فرستنده</th>
                                                <th>متن</th>
                                                <th>وضعیت تحویل</th>
                                                <th>هزینه</th>
                                            </tr>
                                        </thead>
                                        <tbody id="sms-outbox-body">
                                            <tr><td colspan="6" class="text-center text-muted py-4">برای مشاهده، این زبانه را باز کنید.</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div class="tab-pane fade" id="sms-inbox-pane" role="tabpanel">
                                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                                    <div class="text-muted small">پیامک‌های دریافتی روی خط اختصاصی (نیاز به Sender)</div>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="sms-inbox-refresh-btn">
                                        <i class="bi bi-arrow-clockwise me-1"></i>بازخوانی
                                    </button>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>زمان</th>
                                                <th>فرستنده</th>
                                                <th>گیرنده (خط)</th>
                                                <th>متن</th>
                                            </tr>
                                        </thead>
                                        <tbody id="sms-inbox-body">
                                            <tr><td colspan="4" class="text-center text-muted py-4">برای مشاهده، این زبانه را باز کنید.</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card mb-3">
                        <div class="card-header"><h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>راهنمای وضعیت تحویل</h5></div>
                        <div class="card-body small">
                            <div class="row g-2">
                                <div class="col-md-3"><span class="badge bg-secondary">۱ / ۲</span> در صف / زمان‌بندی</div>
                                <div class="col-md-3"><span class="badge bg-info text-dark">۴ / ۵</span> ارسال به مخابرات</div>
                                <div class="col-md-3"><span class="badge bg-success">۱۰</span> تحویل شده به گیرنده</div>
                                <div class="col-md-3"><span class="badge bg-danger">۱۱</span> تحویل نشده</div>
                                <div class="col-md-3"><span class="badge bg-warning text-dark">۶</span> خطا در مخابرات</div>
                                <div class="col-md-3"><span class="badge bg-dark">۱۳</span> لغو شده</div>
                                <div class="col-md-3"><span class="badge bg-dark">۱۴</span> بلاک شده</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="payment-gateway-settings-pane" role="tabpanel">
            <div class="row">
                <div class="col-md-8 mx-auto">
                    <div class="alert alert-info mb-3">
                        <i class="bi bi-info-circle-fill me-2"></i>
                        با فعال‌سازی زرین‌پال، مشتری از لینک پیامک فاکتور می‌تواند مانده یا مبلغ دلخواه (تا سقف مانده) را آنلاین پرداخت کند.
                        جزئیات کارت‌به‌کارت فقط وقتی در تب «تنظیمات چاپ فاکتور» گزینه <strong>نمایش اطلاعات پرداخت</strong> فعال باشد روی لینک مشتری دیده می‌شود.
                    </div>
                    <form id="zarinpal-settings-form">
                        <div class="card mb-3">
                            <div class="card-header"><h5 class="mb-0"><i class="bi bi-credit-card me-2"></i>زرین‌پال</h5></div>
                            <div class="card-body">
                                <div class="alert alert-danger form-error" style="display:none;"></div>
                                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3 p-3 border rounded bg-light">
                                    <div>
                                        <div class="fw-semibold">پرداخت آنلاین در لینک فاکتور مشتری</div>
                                        <div class="form-text mb-0" id="zarinpal-enabled-hint">وضعیت ذخیره می‌شود…</div>
                                    </div>
                                    <div class="form-check form-switch m-0">
                                        <input class="form-check-input" type="checkbox" role="switch" id="zarinpal-enabled" style="width:2.75em;height:1.4em;cursor:pointer;">
                                        <label class="form-check-label" for="zarinpal-enabled" id="zarinpal-enabled-label">غیرفعال</label>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label for="zarinpal-merchant-id" class="form-label">مرچنت‌آیدی (Merchant ID)</label>
                                    <input type="password" class="form-control" id="zarinpal-merchant-id" dir="ltr" autocomplete="new-password" placeholder="برای تغییر، مرچنت‌آیدی جدید وارد کنید">
                                    <div class="form-text" id="zarinpal-merchant-hint">وضعیت مرچنت‌آیدی بارگذاری می‌شود…</div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label for="zarinpal-currency-unit" class="form-label">واحد مبالغ سیستم</label>
                                        <select class="form-select" id="zarinpal-currency-unit">
                                            <option value="toman">تومان (ارسال ×۱۰ به زرین‌پال)</option>
                                            <option value="rial">ریال (همان مبلغ به زرین‌پال)</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="zarinpal-deposit-account" class="form-label">حساب واریز پرداخت‌های آنلاین</label>
                                        <select class="form-select" id="zarinpal-deposit-account">
                                            <option value="">— انتخاب حساب —</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" id="zarinpal-sandbox">
                                    <label class="form-check-label" for="zarinpal-sandbox">حالت آزمایشی (Sandbox)</label>
                                </div>
                                <div class="form-text mb-3">برای تست از مرچنت‌آیدی سندباکس زرین‌پال استفاده کنید.</div>
                                <button type="submit" class="btn btn-success w-100">
                                    <i class="bi bi-check-lg me-2"></i>ذخیره تنظیمات درگاه
                                </button>
                                <div class="form-text mt-2">حساب واریز برای هر مجموعه جداگانه ذخیره می‌شود (مجموعه فعال فعلی).</div>
                            </div>
                        </div>
                    </form>

                    <div class="card mb-3" id="online-payments-card">
                        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <h5 class="mb-0"><i class="bi bi-arrow-repeat me-2"></i>پرداخت‌های آنلاین و تطبیق</h5>
                            <div class="d-flex gap-2">
                                <select class="form-select form-select-sm" id="online-payments-status" style="width:auto">
                                    <option value="">همه</option>
                                    <option value="review">نیازمند بررسی</option>
                                    <option value="pending">در انتظار</option>
                                    <option value="paid">ثبت‌شده</option>
                                    <option value="failed">ناموفق/لغو</option>
                                </select>
                                <button type="button" class="btn btn-sm btn-outline-primary" id="online-payments-refresh"><i class="bi bi-arrow-clockwise"></i></button>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="small text-muted px-3 pt-2">پرداخت‌هایی که مشتری انجام داده ولی بازگشت به سایت انجام نشده، هر ۱۰ دقیقه به‌صورت خودکار بررسی و ثبت می‌شوند. موارد «نیازمند بررسی» را با دکمه بررسی مجدد ثبت کنید.</div>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover mb-0">
                                    <thead><tr><th>#</th><th>تاریخ</th><th>فاکتور</th><th>مشتری</th><th>مبلغ</th><th>وضعیت</th><th>کد پیگیری</th><th></th></tr></thead>
                                    <tbody id="online-payments-tbody"><tr><td colspan="8" class="text-center text-muted">—</td></tr></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="integration-settings-pane" role="tabpanel">
            <div class="row g-3">
                <div class="col-lg-7">
                    <div class="card shadow-sm">
                        <div class="card-header bg-white">
                            <h5 class="mb-0"><i class="bi bi-shop me-2"></i>اتصال به فروشگاه زیما هوم (Medusa)</h5>
                        </div>
                        <div class="card-body">
                            <p class="text-muted small">
                                آدرس وب‌هوک فروشگاه و رمز مشترک برای همگام‌سازی موجودی و سفارش‌ها.
                                رمز باید با <code>INTEGRATION_WEBHOOK_SECRET</code> در مدیریت سایت یکی باشد.
                            </p>
                            <form id="integration-settings-form">
                                <div class="mb-3">
                                    <label class="form-label" for="integration-medusa-webhook-url">آدرس وب‌هوک Medusa</label>
                                    <input type="url" class="form-control" id="integration-medusa-webhook-url"
                                           placeholder="https://api.example.com/hooks/hesabdari" dir="ltr">
                                    <div class="form-text">معمولاً: <code>https://api.DOMAIN/hooks/hesabdari</code></div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label" for="integration-webhook-secret">رمز مشترک وب‌هوک (HMAC)</label>
                                    <input type="password" class="form-control" id="integration-webhook-secret" dir="ltr" autocomplete="new-password"
                                           placeholder="خالی بگذارید اگر نمی‌خواهید عوض شود">
                                    <div class="form-text" id="integration-secret-hint">وضعیت رمز: —</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label" for="integration-default-entity">شناسه مجموعه پیش‌فرض</label>
                                    <input type="number" class="form-control" id="integration-default-entity" min="1" dir="ltr">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label" for="integration-sso-users">شناسه کاربران مجاز برای ورود از فروشگاه (SSO)</label>
                                    <input type="text" class="form-control" id="integration-sso-users" dir="ltr" placeholder="مثلاً 3,7 — خالی = غیرفعال">
                                    <div class="form-text">فقط این کاربران از طریق لینک ورود یک‌مرحله‌ای فروشگاه وارد حسابداری می‌شوند. شناسه کاربر در «کاربران و دسترسی‌ها» دیده می‌شود.</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label" for="integration-online-account">حساب پیش‌فرض پرداخت آنلاین</label>
                                    <select class="form-select" id="integration-online-account">
                                        <option value="">— انتخاب حساب —</option>
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-success">
                                    <i class="bi bi-check-lg me-1"></i>ذخیره تنظیمات اتصال
                                </button>
                                <button type="button" class="btn btn-outline-primary" id="integration-ping-btn">
                                    <i class="bi bi-broadcast me-1"></i>تست ping داخلی
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="card shadow-sm">
                        <div class="card-header bg-white d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="bi bi-key me-2"></i>توکن API برای مدیریت سایت</h5>
                            <button type="button" class="btn btn-sm btn-primary" id="integration-create-token-btn">
                                <i class="bi bi-plus-lg"></i> ساخت توکن
                            </button>
                        </div>
                        <div class="card-body">
                            <p class="text-muted small">
                                این توکن را در تنظیمات مدیریت سایت در فیلد «API Token» قرار دهید.
                                مقدار کامل فقط یک‌بار بعد از ساخت نمایش داده می‌شود.
                            </p>
                            <div id="integration-new-token-box" class="alert alert-warning d-none">
                                <div class="fw-semibold mb-1">توکن جدید (همین الان کپی کنید):</div>
                                <code id="integration-new-token-value" class="user-break" dir="ltr"></code>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>نام</th>
                                            <th>پیشوند</th>
                                            <th>مجموعه</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody id="integration-tokens-tbody">
                                        <tr><td colspan="4" class="text-muted text-center">در حال بارگذاری...</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="card shadow-sm mt-3">
                        <div class="card-body small text-muted">
                            <div class="fw-semibold text-dark mb-1">راهنمای سریع</div>
                            <ol class="mb-0 pe-3">
                                <li>اینجا یک توکن بسازید و کپی کنید.</li>
                                <li>در پنل مدیریت سایت → تنظیمات حسابداری، آدرس حسابداری و توکن را ذخیره کنید.</li>
                                <li>رمز وب‌هوک را در هر دو طرف یکسان بگذارید.</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="collections-pane" role="tabpanel">
            <?php include __DIR__ . '/section_collections.php'; ?>
        </div>
        <div class="tab-pane fade" id="partners-pane" role="tabpanel">
            <?php include __DIR__ . '/section_partners.php'; ?>
        </div>
        <div class="tab-pane fade" id="users-pane" role="tabpanel">
            <?php include __DIR__ . '/section_users.php'; ?>
        </div>
        <div class="tab-pane fade" id="access-control-pane" role="tabpanel">
            <?php include __DIR__ . '/section_access_control.php'; ?>
        </div>
        <div class="tab-pane fade" id="account-closing-pane" role="tabpanel">
            <?php include __DIR__ . '/section_account_closing.php'; ?>
        </div>
        <div class="tab-pane fade" id="backup-restore-pane" role="tabpanel">
            <div class="row">
                <div class="col-lg-7">
                    <div class="card mb-3">
                        <div class="card-header"><h5 class="mb-0"><i class="bi bi-upload me-2"></i>ایجاد پشتیبان دستی</h5></div>
                        <div class="card-body">
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" role="switch" id="backup-include-images">
                                <label class="form-check-label" for="backup-include-images">شامل تصاویر محصولات در پشتیبان (فایل ZIP)</label>
                            </div>
                            <p class="text-muted small mb-3">پس از ایجاد، فایل دیتابیس (بدون تصاویر) به‌صورت خودکار به ایمیل تنظیم‌شده ارسال می‌شود. اگر بکاپ با تصاویر باشد، ZIP کامل روی سرور می‌ماند.</p>
                            <p class="text-muted small">یک نسخه کامل از تمام داده‌های برنامه (تمام مجموعه‌ها، کاربران و تنظیمات) ایجاد کنید.</p>
                            <button class="btn btn-primary w-100" type="button" id="create-full-backup-btn"><i class="bi bi-database-fill-down me-2"></i>ایجاد پشتیبان کامل</button>
                            <hr>
                            <p class="text-muted small mt-3">از یک مجموعه کاری خاص پشتیبان تهیه کنید. (مناسب برای کپی کردن داده بین مجموعه‌ها)</p>
                             <div class="input-group">
                                <select class="form-select" id="backup-entity-select"></select>
                                <button class="btn btn-outline-secondary" type="button" id="create-entity-backup-btn" disabled>ایجاد پشتیبان از این مجموعه</button>
                            </div>
                            <hr>
                            <p class="text-muted small mt-3">از بخش‌های خاصی از برنامه (مانند فقط مشتریان) پشتیبان تهیه کنید.</p>
                             <div class="mb-2">
                                <label class="form-label">انتخاب بخش‌ها:</label>
                                <select class="form-select" id="backup-partial-select" multiple>
                                    <option value="customers">مشتریان</option>
                                    <option value="suppliers">تامین‌کنندگان</option>
                                    <option value="products">محصولات و انبار</option>
                                    <option value="sales_invoices">فاکتورهای فروش</option>
                                    <option value="purchase_invoices">فاکتورهای خرید</option>
                                    <option value="accounts">حساب‌ها و شرکا</option>
                                    <option value="checks">چک‌ها و پرداخت‌ها</option>
                                    <option value="expenses">هزینه‌ها</option>
                                    <option value="incomes">درآمدها</option>
                                </select>
                            </div>
                            <button class="btn btn-outline-secondary w-100" type="button" id="create-partial-backup-btn">ایجاد پشتیبان جزئی</button>
                        </div>
                    </div>
                     <div class="card mb-3">
                        <div class="card-header"><h5 class="mb-0 text-danger"><i class="bi bi-hdd-stack-fill me-2"></i>بازیابی از پشتیبان</h5></div>
                        <div class="card-body">
                            <div class="alert alert-danger"><strong>هشدار!</strong> عملیات بازیابی تمام اطلاعات فعلی شما را **حذف** کرده و اطلاعات فایل پشتیبان را جایگزین می‌کند. این عمل غیرقابل بازگشت است.</div>
                            <div class="mb-3">
                                <label for="restore-file-input" class="form-label">انتخاب فایل پشتیبان (`.sql.gz`، `.zip` با تصاویر یا `.enc` رمزنگاری‌شده)</label>
                                <input class="form-control" type="file" id="restore-file-input" name="backupFile" accept=".gz,.sql,.zip,.enc">
                            </div>
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" role="switch" id="restore-include-images" checked>
                                <label class="form-check-label" for="restore-include-images">در صورت وجود تصاویر در فایل، تصاویر محصولات هم بازیابی شوند</label>
                            </div>
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" role="switch" id="restore-confirm-checkbox">
                                <label class="form-check-label" for="restore-confirm-checkbox">از حذف داده‌های فعلی و جایگزینی آن‌ها آگاهم و مسئولیت آن را می‌پذیرم.</label>
                            </div>
                            <button type="button" class="btn btn-danger w-100" id="restore-btn" disabled><i class="bi bi-exclamation-triangle-fill me-2"></i>شروع عملیات بازیابی با آپلود فایل</button>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="card">
                        <div class="card-header"><h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>پشتیبان‌های موجود</h5></div>
                        <div class="card-body">
                             <div class="table-responsive" style="max-height: 450px;">
                                <table class="table table-sm table-hover">
                                    <thead><tr><th>فایل</th><th>تاریخ</th><th>حجم</th><th>عملیات</th></tr></thead>
                                    <tbody id="existing-backups-tbody"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                        <div class="card mt-3">
                            <div class="card-header"><h5 class="mb-0"><i class="bi bi-robot me-2"></i>پشتیبان‌گیری خودکار</h5></div>
                            <div class="card-body">
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" role="switch" id="auto-backup-enabled">
                                    <label class="form-check-label" for="auto-backup-enabled">فعال‌سازی پشتیبان‌گیری خودکار روزانه</label>
                                </div>
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" role="switch" id="auto-backup-include-images">
                                    <label class="form-check-label" for="auto-backup-include-images">پشتیبان خودکار همراه با تصاویر محصولات</label>
                                </div>
                                <p class="text-muted small mb-3">پشتیبان خودکار پس از ایجاد، فایل دیتابیس (بدون تصاویر) به ایمیل ارسال می‌شود.</p>
                                <div class="mb-3">
                                    <label for="auto-backup-retention-days" class="form-label">نگهداری پشتیبان‌های خودکار برای چند روز؟</label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="auto-backup-retention-days" min="1" value="7">
                                        <span class="input-group-text">روز</span>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label for="auto-backup-secret" class="form-label">کلید مخفی اجرای خودکار (Cron Secret)</label>
                                    <input type="text" class="form-control" id="auto-backup-secret" placeholder="مثلاً X9r2K...">
                                    <div class="form-text">برای اجرای امن آدرس <code>api.php?action=run_automatic_backup&secret=...</code> لازم است.</div>
                                </div>
                                <div class="form-text mt-2 text-primary"><i class="bi bi-info-circle-fill me-1"></i>تغییرات این بخش به صورت خودکار ذخیره می‌شود.</div>
                            </div>
                        </div>
                        <div class="card mt-3">
                            <div class="card-header"><h5 class="mb-0"><i class="bi bi-envelope-at-fill me-2"></i>تنظیمات ایمیل (SMTP)</h5></div>
                            <div class="card-body">
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" role="switch" id="smtp-enabled">
                                    <label class="form-check-label" for="smtp-enabled">فعال‌سازی ارسال ایمیل</label>
                                </div>
                                <div class="row g-2">
                                    <div class="col-md-8">
                                        <label for="smtp-host" class="form-label">سرور SMTP</label>
                                        <input type="text" class="form-control" id="smtp-host" placeholder="smtp.example.com">
                                    </div>
                                    <div class="col-md-4">
                                        <label for="smtp-port" class="form-label">پورت</label>
                                        <input type="number" class="form-control" id="smtp-port" min="1" max="65535" value="587">
                                    </div>
                                    <div class="col-md-4">
                                        <label for="smtp-encryption" class="form-label">رمزنگاری</label>
                                        <select class="form-select" id="smtp-encryption">
                                            <option value="tls">TLS (STARTTLS — پورت 587)</option>
                                            <option value="ssl">SSL (پورت 465)</option>
                                            <option value="none">بدون رمزنگاری</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="smtp-username" class="form-label">نام کاربری</label>
                                        <input type="text" class="form-control" id="smtp-username" autocomplete="off">
                                    </div>
                                    <div class="col-md-4">
                                        <label for="smtp-password" class="form-label">رمز عبور</label>
                                        <input type="password" class="form-control" id="smtp-password" autocomplete="new-password">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="mail-from-address" class="form-label">ایمیل فرستنده</label>
                                        <input type="email" class="form-control" id="mail-from-address" placeholder="noreply@example.com">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="mail-from-name" class="form-label">نام فرستنده</label>
                                        <input type="text" class="form-control" id="mail-from-name" placeholder="سیستم حسابداری">
                                    </div>
                                    <div class="col-12">
                                        <label for="backup-email-to" class="form-label">ایمیل دریافت بکاپ</label>
                                        <input type="text" class="form-control" id="backup-email-to" placeholder="admin@example.com یا چند آدرس با ; جدا کنید">
                                        <div class="form-text">فایل دیتابیس (sql.gz) همیشه پیوست می‌شود؛ در بکاپ با تصاویر، ZIP کامل فقط روی سرور ذخیره می‌شود.</div>
                                    </div>
                                </div>
                                <div class="mt-3">
                                    <label for="test-email-to" class="form-label">ارسال ایمیل آزمایشی به</label>
                                    <div class="input-group">
                                        <input type="email" class="form-control" id="test-email-to" placeholder="خالی = همان ایمیل دریافت بکاپ">
                                        <button type="button" class="btn btn-outline-primary" id="test-email-settings-btn">تست SMTP</button>
                                    </div>
                                </div>
                                <div class="form-text mt-2 text-primary"><i class="bi bi-info-circle-fill me-1"></i>تغییرات این بخش به صورت خودکار ذخیره می‌شود.</div>
                            </div>
                        </div>
                        <div class="card mt-3">
                            <div class="card-header"><h5 class="mb-0"><i class="bi bi-cloud-arrow-up-fill me-2"></i>ارسال بکاپ به سرور دیگر (بدون SSH)</h5></div>
                            <div class="card-body">
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" role="switch" id="remote-backup-enabled">
                                    <label class="form-check-label" for="remote-backup-enabled">فعال‌سازی ارسال بکاپ به سرور دیگر هنگام پشتیبان‌گیری</label>
                                </div>

                                <div class="mb-3">
                                    <label for="remote-backup-url" class="form-label">آدرس دریافت بکاپ در سرور مقصد</label>
                                    <input type="text" class="form-control" id="remote-backup-url" placeholder="مثلاً https://example.com/api/index.php?action=receive_remote_backup">
                                    <div class="form-text">این آدرس باید روی سرور مقصد تنظیم شود.</div>
                                </div>

                                <div class="mb-3">
                                    <label for="remote-backup-secret" class="form-label">کلید امنیتی ارسال (Secret)</label>
                                    <input type="text" class="form-control" id="remote-backup-secret" placeholder="یک کلید امن انتخاب کنید">
                                    <div class="form-text">باید با «کلید دریافت» در سرور مقصد یکسان باشد.</div>
                                </div>

                                <div class="mb-3">
                                    <label for="remote-backup-receive-secret" class="form-label">کلید دریافت در همین سرور (برای مقصد بودن)</label>
                                    <input type="text" class="form-control" id="remote-backup-receive-secret" placeholder="اگر این سرور مقصد است، این کلید را تنظیم کنید">
                                    <div class="form-text">اگر نمی‌خواهید این سرور بکاپ دریافت کند، خالی بگذارید.</div>
                                </div>

                                <div class="mb-3">
                                    <label for="backup-encryption-password" class="form-label">رمز رمزنگاری بکاپ‌های ارسالی (اختیاری)</label>
                                    <input type="text" class="form-control ltr" id="backup-encryption-password" autocomplete="off" placeholder="خالی = بدون رمزنگاری">
                                    <div class="form-text">اگر تنظیم شود، نسخه‌ای که به سرور دیگر یا ایمیل فرستاده می‌شود با AES-256 رمزنگاری می‌شود (پسوند .enc). این رمز را جای امنی نگه دارید؛ بدون آن بازیابی ممکن نیست.</div>
                                </div>

                                <div class="mb-3">
                                    <label for="remote-backup-retention-days" class="form-label">نگهداری بکاپ‌های دریافتی برای چند روز؟</label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="remote-backup-retention-days" min="1" value="30">
                                        <span class="input-group-text">روز</span>
                                    </div>
                                </div>

                                <button type="button" class="btn btn-outline-primary w-100" id="test-remote-backup-btn">
                                    <i class="bi bi-plug-fill me-2"></i>تست اتصال به سرور مقصد
                                </button>
                                <div class="form-text mt-2 text-primary"><i class="bi bi-info-circle-fill me-1"></i>تغییرات این بخش به صورت خودکار ذخیره می‌شود.</div>
                            </div>
                        </div>
                </div>
            </div>
        </div>
        <div class="tab-pane fade" id="activity-log-pane" role="tabpanel">
            <?php include __DIR__ . '/section_activity_log.php'; ?>
        </div>

        <div class="tab-pane fade" id="error-log-pane" role="tabpanel">
            <?php include __DIR__ . '/section_error_log.php'; ?>
        </div>

    </div>
</div>
