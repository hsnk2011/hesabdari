# CRM تیمی — design (فاز ۰ پایه‌ی تیم + push، فاز ۱ هسته‌ی CRM)

Date: 2026-10-17. Status: approved by the user (plan mode). Calls made by Claude, not the user: everyone sees all customers (only tasks are owned); leads in a separate table; Web Push in pure PHP; HR/support/dashboard/SMS campaigns are separate later projects.

## Context
تیم: ۳ شریک + ۱ کارمند، بیشتر با گوشی کار می‌کنند. چهار درد اعلام‌شده (همه را کاربر انتخاب کرد):
۱) معلوم نیست چه کسی با مشتری چه گفت، ۲) پیگیری بدهکارها و قول‌های پرداخت گم می‌شود،
۳) تقسیم و پیگیری کار بین خودمان سخت است، ۴) مشتری بالقوه رها می‌شود.
یادآوری باید **نوتیفیکیشن گوشی (Web Push)** باشد.
کاربر خواسته منابع انسانی هم باشد و پشتیبانی/تیکت هم؛ این‌ها پروژه‌های بعدی‌اند (پایین).

داشته‌های فعلی که رویشان سوار می‌شویم: `customers`/`partners`/`contacts`، `quotations`، `checks`، `installments`،
`sms_logs` + `SmsController` (کاوه‌نگار)، `notifications` + `NotificationController`، `Scheduler` (cron)،
`roles`/`permissions`، `PersonBalanceTrait::attachBalanceDataToPersons` (مانده‌ی اشخاص)،
`sw.js` (دستگیره‌ی `push` در خط ~380 و `notificationclick` در ~398 هست، ولی سرور هیچ push نمی‌فرستد).
کمبودها: `users` فقط username دارد؛ `notifications` کاربرمحور نیست؛ composer/کتابخانه‌ی push نداریم (PHP 8.3 با openssl/curl هست).

## Out of scope (پروژه‌های بعدی، هر کدام brainstorm جدا)
پشتیبانی/تیکت/گارانتی؛ منابع انسانی سبک (حقوق ماهانه‌ی یک کارمند با سند هزینه، مرخصی، حضور و غیاب ساده)؛
داشبورد عملکرد هر نفر؛ کمپین پیامکی گروهی. محدودسازی «هر کس فقط مشتری خودش» لازم نیست — همه همه‌چیز را می‌بینند، فقط کارها اسم دارند.

## فاز ۰ — پایه‌ی تیم و push

**داده** (در `api/core/schema.php`؛ با bump نسخه `SchemaMigrator` اعمال می‌کند):
- `team_members`: id, entity_id, user_id (nullable, unique با entity), partner_id (nullable → `partners`), full_name, mobile,
  kind enum(partner, employee), is_active, created_at. (کارمندِ بدون ورود به سیستم هم ممکن است — برای HR بعدی.)
- `notifications.user_id` int NULL (NULL = برای همه) + index. `NotificationController::getReminders` → `AND (user_id IS NULL OR user_id = ?)`.
- `push_subscriptions`: id, entity_id, user_id, endpoint (unique), p256dh, auth, user_agent, created_at, last_success_at, fail_count.
- کلیدهای VAPID یک بار تولید و در settings ذخیره می‌شوند.

**کد:**
- `api/core/WebPush.php` — PHP خالص بدون کتابخانه: JWT ES256 با `openssl_sign` (تبدیل DER→raw r||s)،
  رمزنگاری payload با aes128gcm (RFC 8291: `openssl_pkey_new` EC P-256، `openssl_pkey_derive`، `hash_hkdf`، `openssl_encrypt aes-128-gcm`)،
  ارسال با curl، حذف اشتراک روی 404/410.
- `api/models/TeamMember.php` + action‌ها در `CrmController` (list/save/deactivate)؛ UI در بخش کاربران (`templates/section_users.php`).
- اشتراک push: دکمه‌ی «فعال‌سازی نوتیفیکیشن روی این گوشی» (PushManager.subscribe با کلید عمومی VAPID)؛
  روی iOS راهنمای «افزودن به صفحه‌ی اصلی» نشان داده شود (شرط push در iOS).
- `sw.js`: `notificationclick` با `data.url` به همان کار/مشتری deep-link کند.

## فاز ۱ — هسته‌ی CRM

**داده:**
- `customers.owner_user_id` int NULL — مسئول مشتری.
- `crm_activities`: id, entity_id, person_type enum(customer, lead, supplier, contact), person_id, user_id (ثبت‌کننده),
  kind enum(call_out, call_in, meeting, visit, note), outcome varchar(50) (no_answer, talked, promised, interested, not_interested, …),
  body text, promise_amount decimal NULL, promise_date date NULL, promise_status enum(open, kept, broken) NULL, created_at.
- `crm_tasks`: id, entity_id, title, description, person_type NULL, person_id NULL, assignee_user_id, created_by,
  due_at datetime, kind enum(followup, collection, sales, other), status enum(open, done, cancelled), done_at, done_by,
  source enum(manual, promise_broken, check_due, check_bounced, installment_overdue, quotation_expiring), source_ref_id NULL,
  reminded_at NULL, created_at. یکتا روی (source, source_ref_id) برای منابع خودکار تا تکراری ساخته نشود.
- `crm_leads`: id, entity_id, name, phone, source, interest, stage enum(new, contacted, quoted, won, lost), lost_reason,
  owner_user_id, converted_customer_id NULL, notes, created_at, updated_at.
  (جدا از `customers` چون `customers.nationalId` اجباری است.)

**رفتار:**
- **پرونده‌ی مشتری (خط زمانی):** یک endpoint که فاکتور فروش/مرجوعی، پرداخت، چک، قسط، پیش‌فاکتور، `sms_logs`،
  `crm_activities` و `crm_tasks` همان مشتری را ادغام و بر اساس تاریخ نزولی صفحه‌بندی می‌کند؛ بالایش مانده (از `PersonBalanceTrait`)،
  مسئول، آخرین تماس، قول باز.
- **اقدام سریع (موبایل‌محور):** دکمه‌ی `tel:`؛ بعد از برگشت به برنامه (`visibilitychange`) برگه‌ی پایینی «نتیجه‌ی تماس؟» با چیپ‌های نتیجه،
  فیلد قول پرداخت (مبلغ/تاریخ شمسی)، و «پیگیری بعدی» (تاریخ + مسئول). ارسال پیامک با `SmsController` موجود.
- **بدهکارها:** مشتری‌های با مانده‌ی مثبت + چک سررسید/برگشتی + قسط معوق، با آخرین تماس (چه کسی/کی)، قول باز، مسئول؛ مرتب بر اساس فوریت.
- **کارها:** «کارهای من» (امروز/عقب‌افتاده/آینده) و «کارهای تیم»؛ ساخت/واگذاری/انجام/لغو.
- **سرنخ‌ها:** ستون‌های مرحله (روی موبایل به‌صورت تب/لیست)، تبدیل به مشتری = باز شدن فرم مشتری با پیش‌پر؛
  پس از ذخیره `converted_customer_id`، stage=won و فعالیت‌ها به مشتری منتقل می‌شوند. پیش‌فاکتور برای سرنخ پس از تبدیل.
- **اتوماسیون (Scheduler):**
  - `crm_automation` (هر ۱ ساعت): قول پرداختِ سررسیدشده بدون دریافتی ≥ مبلغ از زمان قول → broken + کار وصول برای مسئول؛
    چک دریافتی سررسید فردا/برگشتی و قسط معوق → کار وصول؛ پیش‌فاکتور در ۲ روز تا انقضا → کار فروش. مسئول = `owner_user_id` مشتری،
    وگرنه همه‌ی تیم (notification با user_id NULL).
  - `crm_push` (هر ۶۰ ثانیه): کارهای سررسیده با `reminded_at IS NULL` → notification کاربرمحور + Web Push به اشتراک‌های همان کاربر، سپس set reminded_at.
    به اضافه‌ی یک push خلاصه‌ی صبحگاهی (تعداد کارهای امروز/عقب‌افتاده).
- **دسترسی:** مجوزهای `use_crm` و `manage_crm_team` در نقشه‌ی `api/index.php`، برچسب در `js/access-control-manager.js`، نمایش منو در `js/app.js`.
  همه‌ی تغییرات در `activity_log`. قفل دوره روی CRM اعمال نمی‌شود (سند مالی نیست).
- همه‌چیز entity-scoped با `$_SESSION['current_entity_id']`.

**فایل‌های اصلی:**
- جدید: `api/controllers/CrmController.php`، `api/models/{TeamMember,CrmActivity,CrmTask,CrmLead,CrmTimeline}.php`،
  `api/core/WebPush.php`، `templates/section_crm.php`، `js/crm-manager.js`.
- تغییر: `api/core/schema.php`، `api/core/Scheduler.php`، `api/index.php`، `api/controllers/NotificationController.php`،
  `templates/main_tabs.php`، `templates/scripts.php`، `templates/section_customers.php` (دکمه‌ی پرونده/مسئول)،
  `templates/section_users.php`، `js/app.js`، `js/access-control-manager.js`، `sw.js`، ثابت نسخه (bump).
- الگو: کنترلر/مدل‌ها مثل `AssetController`/`Asset` (آخرین ماژول اضافه‌شده)، UI مثل `asset-manager.js`.

## Verification
- لوکال (laragon): bump نسخه → بارگذاری اول صفحه → بررسی ساخت جدول‌ها/ستون‌ها.
- تست PHP برای `WebPush` (رمزنگاری در برابر بردار تست RFC 8291) و برای منطق اتوماسیون (قول شکسته، dedupe منابع) در `tests/`.
- مرورگر داخلی با viewport موبایل: ثبت تماس از پرونده، ساخت پیگیری برای همکار، تبدیل سرنخ به مشتری، فهرست بدهکارها.
- push واقعی: اشتراک در Chrome لوکال، اجرای `php cron.php` و دیدن نوتیفیکیشن و deep-link؛ روی گوشی بعد از deploy (HTTPS لازم است).
- deploy روی سرورها فقط با تأیید کاربر و طبق روال پشتیبان‌گیری قبل از deploy.

