<?php
/**
 * Public live stock list for colleagues and resellers (no login required).
 * URL: stock.php?c=TOKEN — created and revoked from «لیست قیمت» (StockShareController).
 * The link never expires; it shows the stock of the moment until it is revoked.
 */
require_once __DIR__ . '/api/config.php';
require_once __DIR__ . '/api/core/helpers.php';
require_once __DIR__ . '/api/models/StockShareLink.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

$error = null;
$list = null;
$company = ['name' => '', 'phone' => '', 'logo_url' => ''];
$currency = 'تومان';
$nowFa = '';

$db = @new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
if ($db->connect_error) {
    $error = 'خطا در اتصال به سرور.';
} else {
    $db->set_charset('utf8mb4');
    db_sync_timezone($db); // last_viewed_at uses NOW(), in the app's time zone
    $model = new StockShareLink($db);
    $link = $model->findActiveByToken((string)($_GET['c'] ?? ''));
    if (!$link) {
        $error = 'لینک یافت نشد یا باطل شده است.';
    } else {
        $list = $model->buildPublicList($link);
        $company = $model->publicCompany((int)$link['entity_id']);
        $currency = (string)get_app_setting($db, 'currency_label', 'تومان');
        $model->recordView((int)$link['id']);
        $nowFa = function_exists('gregorian_to_jalali') ? gregorian_to_jalali(date('Y-m-d')) . ' ' . date('H:i') : date('Y-m-d H:i');
    }
    $db->close();
}

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$priceLabel = ['sale' => 'قیمت فروش', 'partner' => 'قیمت همکاری', 'none' => ''][$list['price_mode'] ?? 'none'] ?? '';
$pageTitle = trim(($company['name'] ? $company['name'] . ' — ' : '') . 'لیست موجودی');
$payload = $list ? [
    'list' => $list,
    'currency' => $currency,
] : null;
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#6b2a2a">
    <title><?php echo h($pageTitle); ?></title>
    <link rel="stylesheet" href="assets/css/vazirmatn-font-face.css">
    <style>
        :root {
            --brand: #6b2a2a; --brand-ink: #fff; --bg: #f7f3ee; --card: #fff; --ink: #1f1a17;
            --muted: #7a6f66; --line: #e7dfd5; --chip: #f1e8dc; --ok: #2f6b3a; --img-bg: #efe7dc;
        }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Vazirmatn, Tahoma, sans-serif; background: var(--bg); color: var(--ink); font-size: 14px; }
        .wrap { max-width: 1180px; margin: 0 auto; padding: 0 16px 40px; }
        header.top { background: var(--brand); color: var(--brand-ink); padding: 14px 0 12px; margin-bottom: 12px; }
        header.top .wrap { display: flex; gap: 12px; align-items: center; padding-bottom: 0; }
        header.top img { height: 44px; width: auto; max-width: 96px; object-fit: contain; background: #fff; border-radius: 8px; padding: 3px; }
        header.top h1 { margin: 0; font-size: 18px; font-weight: 700; }
        header.top .sub { font-size: 12px; opacity: .85; margin-top: 2px; }
        header.top a { color: inherit; }
        .bar { position: sticky; top: 0; z-index: 5; background: var(--bg); padding: 8px 0; display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .bar select, .bar input { font: inherit; border: 1px solid var(--line); border-radius: 8px; padding: 8px 10px; background: #fff; color: var(--ink); min-width: 0; }
        .bar input { flex: 1 1 180px; }
        .bar select { flex: 0 1 160px; }
        .seg { display: inline-flex; border: 1px solid var(--brand); border-radius: 8px; overflow: hidden; }
        .seg button { font: inherit; border: 0; background: #fff; color: var(--brand); padding: 7px 12px; cursor: pointer; }
        .seg button.on { background: var(--brand); color: var(--brand-ink); }
        .btn { font: inherit; border: 1px solid var(--line); background: #fff; color: var(--ink); border-radius: 8px; padding: 7px 12px; cursor: pointer; }
        .count-line { color: var(--muted); font-size: 12px; margin: 4px 0 10px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 12px; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 12px; overflow: hidden; display: flex; flex-direction: column; }
        .card .ph { aspect-ratio: 4 / 5; background: var(--img-bg); display: flex; align-items: center; justify-content: center; cursor: zoom-in; position: relative; }
        .card .ph img { width: 100%; height: 100%; object-fit: contain; }
        .card .ph .n { position: absolute; bottom: 6px; left: 6px; background: rgba(0,0,0,.55); color: #fff; font-size: 11px; border-radius: 6px; padding: 1px 6px; }
        .noimg { color: var(--muted); font-size: 12px; }
        .card .ph.none { aspect-ratio: auto; height: 56px; cursor: default; }
        .card .b { padding: 10px 12px 8px; }
        .nm { font-weight: 700; font-size: 15px; }
        .meta { color: var(--muted); font-size: 12px; margin-top: 2px; }
        .sz { border-top: 1px dashed var(--line); padding: 6px 0; }
        .sz .r { display: flex; gap: 8px; align-items: center; justify-content: space-between; }
        .dim { font-weight: 600; direction: ltr; unicode-bidi: isolate; }
        .qty { color: var(--ok); font-size: 12px; background: none; border: 0; font: inherit; padding: 0; }
        button.qty { cursor: pointer; text-decoration: underline dotted; }
        .pr { font-weight: 700; white-space: nowrap; }
        .pr small { font-weight: 400; color: var(--muted); font-size: 11px; }
        .call { color: var(--muted); font-size: 12px; white-space: nowrap; }
        .ids { display: none; margin-top: 4px; }
        .ids.open { display: block; }
        .tag { display: inline-block; background: var(--chip); border-radius: 5px; padding: 1px 6px; margin: 2px; font-size: 12px; direction: ltr; font-family: ui-monospace, Consolas, monospace; }
        .rows { display: flex; flex-direction: column; gap: 6px; }
        .row { background: var(--card); border: 1px solid var(--line); border-radius: 10px; padding: 6px; display: flex; gap: 10px; align-items: flex-start; }
        .row .th { width: 64px; height: 80px; flex: none; border-radius: 6px; background: var(--img-bg); overflow: hidden; cursor: zoom-in; display: flex; align-items: center; justify-content: center; }
        .row .th img { width: 100%; height: 100%; object-fit: cover; }
        .row .t { flex: 1; min-width: 0; }
        .row .sz { border: 0; padding: 2px 0; }
        .empty, .error { text-align: center; padding: 48px 16px; color: var(--muted); }
        .error { color: #9b1c1c; font-size: 16px; }
        .lb { position: fixed; inset: 0; background: rgba(0,0,0,.88); z-index: 50; display: none; align-items: center; justify-content: center; }
        .lb.open { display: flex; }
        .lb img { max-width: 94vw; max-height: 84vh; object-fit: contain; }
        .lb .x, .lb .nv { position: absolute; background: rgba(255,255,255,.15); color: #fff; border: 0; font-size: 22px; width: 44px; height: 44px; border-radius: 50%; cursor: pointer; }
        .lb .x { top: 12px; left: 12px; }
        .lb .prev { right: 12px; top: 50%; } .lb .next { left: 12px; top: 50%; }
        .lb .cap { position: absolute; bottom: 14px; left: 0; right: 0; text-align: center; color: #fff; font-size: 13px; }
        footer { text-align: center; color: var(--muted); font-size: 12px; margin-top: 24px; }
        @media (max-width: 560px) {
            .grid { grid-template-columns: 1fr; }
            .bar select { flex: 1 1 40%; }
            .bar { position: static; }
        }
        @media print {
            @page { size: A4; margin: 10mm; }
            body { background: #fff; font-size: 11px; }
            header.top { background: none; color: #000; border-bottom: 2px solid #000; padding: 0 0 6px; }
            .bar, .lb, .no-print { display: none !important; }
            .grid { grid-template-columns: repeat(3, 1fr); gap: 8px; }
            .card, .row { break-inside: avoid; }
            .card .ph { aspect-ratio: 1 / 1; }
            .ids { display: block !important; }
            button.qty { text-decoration: none; }
        }
    </style>
</head>
<body>
<?php if ($error): ?>
    <div class="wrap"><div class="error"><?php echo h($error); ?></div></div>
<?php else: ?>
    <header class="top">
        <div class="wrap">
            <?php if ($company['logo_url']): ?><img src="<?php echo h($company['logo_url']); ?>" alt=""><?php endif; ?>
            <div>
                <h1><?php echo h($pageTitle); ?></h1>
                <div class="sub">
                    <?php if ($priceLabel): ?><?php echo h($priceLabel); ?> · <?php endif; ?>
                    به‌روز: <?php echo h($nowFa); ?>
                    <?php if ($company['phone']): ?> · <a href="tel:<?php echo h(preg_replace('/[^\d+]/', '', $company['phone'])); ?>"><?php echo h($company['phone']); ?></a><?php endif; ?>
                </div>
            </div>
        </div>
    </header>
    <div class="wrap">
        <div class="bar">
            <select id="f-size" aria-label="سایز"><option value="">همه سایزها</option></select>
            <select id="f-col" aria-label="کلکسیون"><option value="">همه کلکسیون‌ها</option></select>
            <input id="f-q" type="search" placeholder="جستجوی نام، کد طرح یا شناسه" aria-label="جستجو">
            <div class="seg" role="group" aria-label="نما">
                <button type="button" data-view="cards">▦ کارتی</button>
                <button type="button" data-view="list">☰ فهرستی</button>
            </div>
            <button type="button" class="btn" id="btn-print">چاپ / PDF</button>
        </div>
        <div class="count-line" id="count-line"></div>
        <div id="out"></div>
        <footer>این لیست زنده است؛ هر بار باز شود موجودی همان لحظه را نشان می‌دهد.</footer>
    </div>
    <div class="lb" id="lb" aria-hidden="true">
        <button type="button" class="x" aria-label="بستن">✕</button>
        <button type="button" class="nv prev" aria-label="قبلی">›</button>
        <img alt="">
        <button type="button" class="nv next" aria-label="بعدی">‹</button>
        <div class="cap"></div>
    </div>
    <script>
    (function () {
        var DATA = <?php echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        // Same literal as AppConfig.STORAGE_KEYS.STOCK_SHARE_VIEW (js/config.js is not loaded here).
        var VIEW_KEY = 'hesabdari_stock_share_view';
        var list = DATA.list, currency = DATA.currency;
        var showPrice = list.price_mode !== 'none';
        var $ = function (id) { return document.getElementById(id); };
        var esc = function (s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        };
        var fa = function (n) { return Number(n).toLocaleString('fa-IR'); };
        var dimText = function (d) { return String(d).replace(/x/gi, '×'); };
        var view = 'cards';
        try { view = localStorage.getItem(VIEW_KEY) === 'list' ? 'list' : 'cards'; } catch (e) {}

        list.sizes.forEach(function (s) { $('f-size').insertAdjacentHTML('beforeend', '<option value="' + esc(s) + '">' + esc(dimText(s)) + '</option>'); });
        list.collections.forEach(function (c) { $('f-col').insertAdjacentHTML('beforeend', '<option value="' + esc(c) + '">' + esc(c) + '</option>'); });
        if (list.collections.length < 2) $('f-col').style.display = 'none';
        if (list.sizes.length < 2) $('f-size').style.display = 'none';

        function priceHtml(s) {
            if (!showPrice) return '';
            if (s.price == null) return '<span class="call">تماس بگیرید</span>';
            return '<span class="pr">' + fa(s.price) + ' <small>' + esc(currency) + '</small></span>';
        }
        function qtyHtml(s, key) {
            var serials = s.serials || [];
            var label = s.count != null ? fa(s.count) + ' عدد' : (serials.length ? 'شناسه‌ها' : '');
            if (serials.length === 1 && s.count != null) {
                return '<span class="qty">' + label + '</span> <span class="tag">' + esc(serials[0]) + '</span>';
            }
            if (serials.length) return '<button type="button" class="qty" data-ids="' + key + '">' + label + ' ▾</button>';
            return label ? '<span class="qty">' + label + '</span>' : '';
        }
        function sizesHtml(p, pi) {
            return p.sizes.map(function (s, si) {
                var key = pi + '-' + si, serials = s.serials || [];
                return '<div class="sz"><div class="r"><span class="dim">' + esc(dimText(s.dimension)) + '</span>'
                    + '<span>' + qtyHtml(s, key) + '</span>' + priceHtml(s) + '</div>'
                    + (serials.length > 1 || (serials.length && s.count == null)
                        ? '<div class="ids" id="ids-' + key + '">' + serials.map(function (x) { return '<span class="tag">' + esc(x) + '</span>'; }).join('') + '</div>'
                        : '')
                    + '</div>';
            }).join('');
        }
        function metaText(p) {
            return [p.collection, p.design_code, p.colorway].filter(Boolean).join(' · ');
        }
        function imgHtml(p, pi, cls) {
            if (!p.images.length) return '<div class="' + cls + ' none"><span class="noimg">بدون تصویر</span></div>';
            return '<div class="' + cls + '" data-gal="' + pi + '"><img loading="lazy" src="' + esc(p.images[0].thumb) + '" alt="' + esc(p.name) + '">'
                + (cls === 'ph' && p.images.length > 1 ? '<span class="n">' + fa(p.images.length) + ' عکس</span>' : '') + '</div>';
        }

        function filtered() {
            var size = $('f-size').value, col = $('f-col').value, q = $('f-q').value.trim().toLowerCase();
            var out = [];
            list.products.forEach(function (p, pi) {
                if (col && p.collection !== col) return;
                var sizes = size ? p.sizes.filter(function (s) { return s.dimension === size; }) : p.sizes;
                if (!sizes.length) return;
                if (q) {
                    var hay = [p.name, p.collection, p.design_code, p.colorway].join(' ').toLowerCase();
                    if (hay.indexOf(q) === -1) {
                        sizes = sizes.filter(function (s) {
                            return (s.serials || []).some(function (x) { return String(x).toLowerCase().indexOf(q) !== -1; });
                        });
                        if (!sizes.length) return;
                    }
                }
                out.push({ p: Object.assign({}, p, { sizes: sizes }), pi: pi });
            });
            return out;
        }

        function render() {
            var items = filtered();
            document.querySelectorAll('.seg button').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-view') === view); });
            var pieces = 0, counted = true;
            items.forEach(function (it) { it.p.sizes.forEach(function (s) { if (s.count == null) counted = false; else pieces += s.count; }); });
            $('count-line').textContent = fa(items.length) + ' طرح' + (counted && items.length ? ' · ' + fa(pieces) + ' تخته' : '');
            if (!items.length) {
                $('out').innerHTML = '<div class="empty">موردی با این فیلترها موجود نیست.</div>';
                return;
            }
            if (view === 'list') {
                $('out').innerHTML = '<div class="rows">' + items.map(function (it) {
                    var p = it.p;
                    return '<div class="row">' + imgHtml(p, it.pi, 'th') + '<div class="t"><div class="nm">' + esc(p.name) + '</div>'
                        + '<div class="meta">' + esc(metaText(p)) + '</div>' + sizesHtml(p, it.pi) + '</div></div>';
                }).join('') + '</div>';
            } else {
                $('out').innerHTML = '<div class="grid">' + items.map(function (it) {
                    var p = it.p;
                    return '<div class="card">' + imgHtml(p, it.pi, 'ph') + '<div class="b"><div class="nm">' + esc(p.name) + '</div>'
                        + '<div class="meta">' + esc(metaText(p)) + '</div>' + sizesHtml(p, it.pi) + '</div></div>';
                }).join('') + '</div>';
            }
        }

        // Lightbox: every image of the tapped product.
        var lb = $('lb'), lbImg = lb.querySelector('img'), gal = [], gi = 0, galName = '';
        function showImg() {
            lbImg.src = gal[gi].full;
            lb.querySelector('.cap').textContent = galName + (gal.length > 1 ? ' — ' + fa(gi + 1) + ' از ' + fa(gal.length) : '');
            lb.querySelectorAll('.nv').forEach(function (b) { b.style.display = gal.length > 1 ? '' : 'none'; });
        }
        function openGal(pi) {
            var p = list.products[pi];
            gal = p.images; gi = 0; galName = p.name;
            if (!gal.length) return;
            showImg();
            lb.classList.add('open');
            lb.setAttribute('aria-hidden', 'false');
        }
        function closeGal() { lb.classList.remove('open'); lb.setAttribute('aria-hidden', 'true'); lbImg.removeAttribute('src'); }
        function step(d) { if (gal.length > 1) { gi = (gi + d + gal.length) % gal.length; showImg(); } }
        lb.addEventListener('click', function (e) {
            if (e.target.classList.contains('prev')) step(-1);
            else if (e.target.classList.contains('next')) step(1);
            else if (e.target !== lbImg) closeGal();
        });
        document.addEventListener('keydown', function (e) {
            if (!lb.classList.contains('open')) return;
            if (e.key === 'Escape') closeGal();
            if (e.key === 'ArrowRight') step(-1);
            if (e.key === 'ArrowLeft') step(1);
        });
        var touchX = null;
        lb.addEventListener('touchstart', function (e) { touchX = e.touches[0].clientX; }, { passive: true });
        lb.addEventListener('touchend', function (e) {
            if (touchX === null) return;
            var dx = e.changedTouches[0].clientX - touchX;
            touchX = null;
            if (Math.abs(dx) > 40) step(dx > 0 ? 1 : -1);
        });

        $('out').addEventListener('click', function (e) {
            var g = e.target.closest('[data-gal]');
            if (g) { openGal(+g.getAttribute('data-gal')); return; }
            var q = e.target.closest('[data-ids]');
            if (q) {
                var box = $('ids-' + q.getAttribute('data-ids'));
                if (box) box.classList.toggle('open');
            }
        });
        document.querySelectorAll('.seg button').forEach(function (b) {
            b.addEventListener('click', function () {
                view = b.getAttribute('data-view');
                try { localStorage.setItem(VIEW_KEY, view); } catch (e) {}
                render();
            });
        });
        ['f-size', 'f-col'].forEach(function (id) { $(id).addEventListener('change', render); });
        $('f-q').addEventListener('input', render);
        $('btn-print').addEventListener('click', function () { window.print(); });
        render();
    })();
    </script>
<?php endif; ?>
</body>
</html>
