# گزارش تبدیل به PWA — سیستم حسابداری بازرگانی فرش

> Progressive Web App conversion report for the Hesabdari (carpet trading accounting) application.

---

## 1. خلاصه پروژه (Audit)

| مورد | جزئیات |
|------|--------|
| Backend | PHP 8.3 (procedural + OOP models/controllers), MySQLi, session auth, CSRF, rate limiting |
| API | Action-based POST router: `api.php` → `api/index.php` |
| Frontend | Vanilla JS (IIFE modules) + jQuery، Bootstrap 5 **RTL**، Chart.js، DataTables، Select2، Persian datepicker، فونت Vazirmatn |
| زبان/جهت | فارسی، `lang="fa"`، `dir="rtl"` |
| صفحات HTML | `index.php` (برنامه اصلی)، `analytics/index.php` (زیرپوشه)، `scan.php` (دو بلوک `<head>`)، `setup.php` (نصاب) |
| وضعیت قبل از PWA | بدون manifest، بدون service worker، **بدون هیچ آیکون/favicon**، بدون صفحه آفلاین |

### تصمیمات مهندسی کلیدی
- **مسیرهای نسبی** در manifest و ثبت SW، تا برنامه چه در ریشه‌ی دامنه و چه در زیرپوشه کار کند.
- **محدوده‌ی SW پویا**: `pwa.js` ریشه‌ی برنامه را از آدرس خودش (`…/js/pwa.js`) محاسبه می‌کند، بنابراین صفحه‌ی `analytics/` هم درست SW را با scope ریشه ثبت می‌کند.
- **API با POST و CSRF** هرگز کش نمی‌شود؛ Cache API فقط GET را پشتیبانی می‌کند و دستکاری POSTها می‌تواند باعث ثبت تکراری اسناد مالی شود.
- آیکون‌ها چون PHP فاقد GD/Imagick بود، با **Python + Pillow** از یک تصویر مادر ساخته شدند.

---

## 2. چک‌لیست امکانات پیاده‌سازی‌شده

### 2.1 Web App Manifest (`manifest.json`)
- [x] `name`, `short_name`, `description` (فارسی)
- [x] `start_url`, `scope` (نسبی)، `display: "standalone"` + `display_override`
- [x] `theme_color: #4f46e5`، `background_color: #f8f9fa`
- [x] آیکون‌ها در اندازه‌های 72, 96, 128, 144, 152, 192, 384, 512
- [x] آیکون‌های **maskable** اختصاصی (192, 512)
- [x] `screenshots` با `form_factor` (narrow + wide) برای واجد شرایط بودن نصب/استور
- [x] `orientation: "portrait"`، `lang: "fa"`، `dir: "rtl"`
- [x] `shortcuts` (داشبورد، فروش، آنالیز)، `categories`

### 2.2 Service Worker (`sw.js`)
- [x] **Cache-first** برای فایل‌های ثابت (CSS/JS/فونت/تصویر)
- [x] **Network-first** برای navigation و محتوای پویا (با navigation preload)
- [x] صفحه‌ی fallback آفلاین (`offline.html`)
- [x] **Background sync** برای ارسال فرم‌ها هنگام آفلاین بودن (صف IndexedDB، opt-in)
- [x] پشتیبانی **Push notification** (هندلرهای `push` و `notificationclick`)
- [x] نسخه‌بندی کش با **پاک‌سازی خودکار** نسخه‌های قدیمی در `activate`
- [x] هندلرهای `install` و `activate` (+ `skipWaiting` / `clients.claim`)

### 2.3 متاتگ‌های HTML (همه‌ی صفحات)
- [x] `index.php`, `analytics/index.php`, `scan.php` (هر دو بلوک)، `setup.php`
- [x] `<link rel="manifest">`، `theme-color`
- [x] `apple-mobile-web-app-capable` / `-status-bar-style` / `-title`
- [x] Apple touch icons، favicon (ico + 16/32)
- [x] متاتگ‌های کاشی مایکروسافت + `browserconfig.xml`
- [x] `viewport` با `viewport-fit=cover`

### 2.4 صفحه‌ی آفلاین (`offline.html`)
- [x] هماهنگ با تم برنامه (گرادیان ایندیگو، فونت Vazirmatn، RTL)
- [x] پیام راهنما به فارسی
- [x] دکمه‌ی «تلاش مجدد» که با یک درخواست واقعی، اتصال را بررسی می‌کند
- [x] نمایش خلاصه‌ی داده‌های کش‌شده در دستگاه + زمان آخرین اتصال موفق

### 2.5 اسکریپت ثبت PWA (`js/pwa.js`)
- [x] ثبت SW با **تشخیص به‌روزرسانی** و بنر اعلان نسخه‌ی جدید
- [x] دکمه‌ی «نصب اپلیکیشن» هنگام رخداد `beforeinstallprompt`
- [x] راهنمای نصب iOS (چون iOS از `beforeinstallprompt` پشتیبانی نمی‌کند)
- [x] مدیریت صحیح چرخه‌ی حیات SW (`controllerchange` → reload یک‌باره)
- [x] واکنش به پیام‌های background sync (Toast فارسی)

### 2.6 بهبود تجربه‌ی موبایل
- [x] هدف‌های لمسی حداقل ۴۴×۴۴ پیکسل (روی صفحات کوچک)
- [x] `touch-action: manipulation` روی عناصر تعاملی
- [x] رفع اسکرول افقی ناخواسته (`overflow-x: hidden`)
- [x] فونت حداقل ۱۶px روی ورودی‌ها (جلوگیری از زوم خودکار iOS)
- [x] `env(safe-area-inset-*)` برای دستگاه‌های دارای ناچ

---

## 3. فایل‌های ایجاد/ویرایش‌شده

### ایجادشده
```
manifest.json
sw.js
offline.html
browserconfig.xml
favicon.ico
js/pwa.js
tools/generate-pwa-icons.py
assets/icon-source.png              (تصویر مادر)
assets/icons/icon-{72,96,128,144,152,192,384,512}.png
assets/icons/maskable-{192,512}.png
assets/icons/apple-touch-icon.png
assets/icons/mstile-150.png
assets/icons/favicon-{16,32}.png
assets/screenshots/screenshot-{narrow,wide}.png
PWA_REPORT.md
```

### ویرایش‌شده
```
index.php                 (متاتگ‌های PWA + viewport-fit)
templates/scripts.php     (بارگذاری js/pwa.js)
analytics/index.php       (متاتگ‌ها + بارگذاری ../js/pwa.js)
scan.php                  (متاتگ‌ها + js/pwa.js در هر دو بلوک)
setup.php                 (theme-color + آیکون‌ها؛ بدون ثبت SW)
style.css                 (بخش بهبود موبایل/PWA)
js/config.js              (AppConfig.VERSION برای cache-busting)
```

---

## 4. اعتبارسنجی (Lighthouse PWA simulation)

| بررسی | نتیجه |
|-------|-------|
| `manifest.json` معتبر بودن JSON | ✅ PASS (python `json.load`) |
| `sw.js` صحت نحوی | ✅ PASS (`node --check`) |
| `js/pwa.js` صحت نحوی | ✅ PASS (`node --check`) |
| لینت PHP صفحات ویرایش‌شده | ✅ بدون خطای جدید (`php -l`) |
| `browserconfig.xml` معتبر بودن XML | ✅ PASS |
| آیکون 192 و 512 | ✅ موجود |
| آیکون maskable | ✅ موجود |
| لینک manifest در همه‌ی صفحات | ✅ |
| `theme-color` و `viewport` | ✅ |
| start_url / scope / display=standalone | ✅ |
| صفحه‌ی آفلاین و ثبت SW | ✅ |
| HTTPS | ⚠️ وابسته به محیط (در محصول لازم است؛ localhost استثناست) |

> این یک شبیه‌سازی دستی است. برای امتیاز رسمی، Lighthouse را در Chrome DevTools (تب Lighthouse → Progressive Web App) روی محیط HTTPS اجرا کنید.

---

## 5. آزمایش محلی (Local testing)

1. پروژه روی Laragon در `http://localhost/hesabdari/` سرو می‌شود. Service Worker روی `localhost` حتی با HTTP کار می‌کند (محیط امن محسوب می‌شود).
2. باز کنید: `http://localhost/hesabdari/index.php`
3. در Chrome → DevTools → **Application**:
   - **Manifest**: باید name، آیکون‌ها و screenshots را نشان دهد.
   - **Service Workers**: باید `sw.js` با وضعیت *activated and running* باشد.
   - **Cache Storage**: باید `hesab-static-v2.0.11` و `hesab-dynamic-v2.0.11` ساخته شوند.
4. **تست آفلاین**: در تب Network گزینه **Offline** را بزنید و صفحه را رفرش کنید → باید نسخه‌ی کش‌شده یا `offline.html` نمایش داده شود.
5. **تست نصب**: در نوار آدرس آیکون نصب ظاهر می‌شود؛ یا بنر «نصب اپلیکیشن» در پایین صفحه. روی iPhone با Safari، راهنمای «افزودن به صفحه اصلی» بعد از چند ثانیه نمایش داده می‌شود.
6. **تست به‌روزرسانی**: مقدار `CACHE_VERSION` در `sw.js` (یا `AppConfig.VERSION`) را تغییر دهید و رفرش کنید → بنر «نسخه‌ی جدید در دسترس است» در بالای صفحه ظاهر می‌شود.

---

## 6. استقرار (Deployment)

1. همه‌ی فایل‌های جدید (به‌ویژه `sw.js`، `manifest.json`، `offline.html`، `favicon.ico`، پوشه‌های `assets/icons` و `assets/screenshots`) را آپلود کنید.
2. **HTTPS را در محصول فعال کنید** — بدون آن service worker اصلاً ثبت نمی‌شود (به‌جز localhost).
3. مطمئن شوید سرور این هدرها را می‌دهد:
   - `sw.js` و `manifest.json` با `Content-Type` صحیح (`application/javascript`، `application/manifest+json`).
   - برای `sw.js` کش طولانی‌مدت ندهید (در حد چند دقیقه یا `no-cache`) تا به‌روزرسانی‌ها سریع دیده شوند. آدرس ثبت SW با `?v=` نسخه‌دار است که به‌روزرسانی را تضمین می‌کند.
4. **Cache-busting در هر استقرار**: مقدار نسخه را در **یک جا** بالا ببرید:
   - `js/config.js` → `AppConfig.VERSION`
   - `sw.js` → `CACHE_VERSION`
   - `templates/scripts.php` → `$v`
   بالا بردن این مقادیر باعث می‌شود کش‌های قدیمی در `activate` پاک شوند و فایل‌های نسخه‌دار دوباره دریافت شوند.
5. اگر artwork آیکون را عوض کردید: `assets/icon-source.png` را جایگزین و سپس `python tools/generate-pwa-icons.py` را اجرا کنید.

---

## 7. محدودیت‌های شناخته‌شده (Known limitations)

- **Background sync محافظه‌کارانه است**: برای جلوگیری از ثبت تکراری فاکتور/سند مالی، یک درخواست فقط زمانی در صف آفلاین قرار می‌گیرد که هدر `X-Background-Sync: 1` داشته باشد. درخواست‌های عادی API دست‌نخورده به شبکه می‌روند. برای فعال‌سازی روی یک فرم خاص، آن هدر را در فراخوانی `fetch` اضافه کنید. توجه: هنگام پخش مجدد، اعتبار توکن CSRF و session باید معتبر باشد.
- **Push notification فقط در حد دریافت/ثبت** است؛ زیرساخت سمت سرور (VAPID keys، ذخیره‌ی subscription و ارسال) پیاده نشده و باید جداگانه اضافه شود.
- **API هرگز آفلاین کش نمی‌شود** (به‌عمد، به‌دلیل ماهیت POST و CSRF). در حالت آفلاین، داده‌های زنده در دسترس نیستند؛ فقط پوسته‌ی برنامه و صفحات بازدیدشده.
- **iOS PWA محدودیت‌های ذاتی دارد**: بدون `beforeinstallprompt`، عمر کش محدودتر، و بدون Background Sync API. راهنمای نصب دستی فراهم شده است.
- **screenshots** نمونه‌ی برندشده (آیکون روی گرادیان) هستند؛ برای استورها بهتر است با اسکرین‌شات واقعی برنامه جایگزین شوند.
- صفحه‌ی `setup.php` عمداً SW را ثبت نمی‌کند تا نصاب کش نشود.
- یک هشدار لینت از پیش‌موجود در `scan.php` (خط ۴۳، nullable در منطق ورود اصلی) مربوط به کد قبلی است و به PWA ربطی ندارد.

---

## 8. نحوه‌ی بازتولید آیکون‌ها

```bash
# نیازمندی: Pillow
python tools/generate-pwa-icons.py
```
این اسکریپت از `assets/icon-source.png` تمام آیکون‌ها، نسخه‌های maskable، Apple touch icon، favicon‌ها، کاشی مایکروسافت و screenshots را می‌سازد.
