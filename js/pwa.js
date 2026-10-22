/* =================================================================
 * pwa.js — PWA bootstrap (registration, install & update UX)
 * Self-contained: injects its own UI, works on every page, supports
 * deployment at the domain root or in a sub-directory.
 * ================================================================= */
(function () {
  'use strict';

  // Resolve the app root from this script's own URL (…/js/pwa.js) so the
  // service worker scope is correct whether we are on index.php (root) or
  // analytics/index.php (sub-folder).
  var thisScript = document.currentScript ||
    (function () { var s = document.getElementsByTagName('script'); return s[s.length - 1]; })();
  var ROOT = (function () {
    try {
      return new URL('.', thisScript.src.replace(/js\/pwa\.js.*$/, '')).href;
    } catch (e) { return './'; }
  })();
  // One version for every page (main app, analytics, scan): the service worker
  // URL embeds it, and two different URLs make the SW reinstall on each page
  // switch (endless «به‌روزرسانی» banner). Prefer the server-provided value,
  // then the ?v= of this script tag.
  var APP_VERSION = window.__APP_VERSION__ || (window.AppConfig && window.AppConfig.VERSION) || (function () {
    var m = /[?&]v=([^&]+)/.exec(thisScript && thisScript.src || '');
    return m ? decodeURIComponent(m[1]) : '';
  })();

  // Service workers require a secure context. localhost over http is allowed.
  var host = location.hostname;
  var secureOk = window.isSecureContext ||
    host === 'localhost' || host === '127.0.0.1' || host === '::1';

  /* ------------------------------------------------------------- *
   *  Small style + helpers (no framework dependency)
   * ------------------------------------------------------------- */
  function injectStyles() {
    if (document.getElementById('pwa-styles')) return;
    var css = '' +
      '.pwa-banner{position:fixed;left:12px;right:12px;z-index:20000;' +
      'background:#fff;border:1px solid #e2e8f0;border-radius:14px;' +
      'box-shadow:0 12px 30px rgba(0,0,0,.18);padding:12px 14px;' +
      'display:flex;align-items:center;gap:12px;font-family:inherit;' +
      'direction:rtl;animation:pwaUp .25s ease}' +
      '.pwa-banner.bottom{bottom:calc(12px + env(safe-area-inset-bottom))}' +
      '.pwa-banner.top{top:calc(12px + env(safe-area-inset-top))}' +
      '.pwa-banner .pwa-ico{width:42px;height:42px;border-radius:10px;flex:0 0 auto;' +
      'background:#eef2ff;display:flex;align-items:center;justify-content:center}' +
      '.pwa-banner .pwa-ico img{width:30px;height:30px}' +
      '.pwa-banner .pwa-txt{flex:1;min-width:0}' +
      '.pwa-banner .pwa-txt b{display:block;font-size:.95rem;color:#1e293b}' +
      '.pwa-banner .pwa-txt span{font-size:.8rem;color:#64748b}' +
      '.pwa-btn{border:0;cursor:pointer;font-family:inherit;font-weight:600;' +
      'border-radius:10px;padding:9px 14px;font-size:.9rem;min-height:40px;' +
      'min-width:44px;touch-action:manipulation;white-space:nowrap}' +
      '.pwa-btn-primary{background:linear-gradient(135deg,#6366f1,#4f46e5);color:#fff}' +
      '.pwa-btn-ghost{background:transparent;color:#94a3b8;padding:9px 8px}' +
      '@keyframes pwaUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}' +
      '.pwa-modal{position:fixed;inset:0;z-index:20001;background:rgba(15,23,42,.55);' +
      'display:flex;align-items:flex-end;justify-content:center;direction:rtl}' +
      '.pwa-sheet{background:#fff;width:100%;max-width:480px;border-radius:20px 20px 0 0;' +
      'padding:22px 22px calc(22px + env(safe-area-inset-bottom));font-family:inherit;' +
      'animation:pwaUp .25s ease}' +
      '.pwa-sheet h3{margin:0 0 6px;font-size:1.1rem;color:#1e293b}' +
      '.pwa-sheet p{margin:0 0 14px;color:#64748b;font-size:.9rem}' +
      '.pwa-step{display:flex;gap:10px;align-items:center;padding:10px 0;' +
      'border-bottom:1px dashed #eef2f7;color:#334155;font-size:.92rem}' +
      '.pwa-step:last-of-type{border-bottom:0}' +
      '.pwa-step .n{flex:0 0 auto;width:26px;height:26px;border-radius:50%;' +
      'background:#eef2ff;color:#4338ca;display:flex;align-items:center;' +
      'justify-content:center;font-weight:700;font-size:.85rem}';
    var s = document.createElement('style');
    s.id = 'pwa-styles';
    s.textContent = css;
    document.head.appendChild(s);
  }

  function toast(msg, type) {
    if (window.toastr) {
      toastr[type === 'error' ? 'error' : (type === 'warning' ? 'warning' : 'success')](msg);
      return;
    }
    // minimal fallback
    var t = document.createElement('div');
    t.className = 'pwa-banner bottom';
    t.innerHTML = '<div class="pwa-txt"><b>' + msg + '</b></div>';
    document.body.appendChild(t);
    setTimeout(function () { t.remove(); }, 4000);
  }

  function iconImg() {
    return '<div class="pwa-ico"><img src="' + ROOT + 'assets/icons/icon-192.png" alt=""></div>';
  }

  /* ------------------------------------------------------------- *
   *  INSTALL prompt (Android / desktop Chromium)
   * ------------------------------------------------------------- */
  var deferredPrompt = null;

  function isStandalone() {
    return window.matchMedia('(display-mode: standalone)').matches ||
      window.navigator.standalone === true;
  }

  function canShowInstallUi() {
    if (isStandalone()) return false;
    if (sessionStorage.getItem('pwa_install_dismissed') === '1') return false;
    if (document.getElementById('pwa-install-banner')) return false;
    return true;
  }

  window.addEventListener('beforeinstallprompt', function (e) {
    // Only defer the native banner when we will show our own install UI.
    // Otherwise leave the event alone (avoids Chrome console noise).
    if (!canShowInstallUi()) {
      return;
    }
    e.preventDefault();
    deferredPrompt = e;
    showInstallBanner();
  });

  function showInstallBanner() {
    if (!canShowInstallUi() && !deferredPrompt) return;
    if (document.getElementById('pwa-install-banner')) return;
    if (isStandalone()) return;
    injectStyles();
    var b = document.createElement('div');
    b.id = 'pwa-install-banner';
    b.className = 'pwa-banner bottom';
    b.innerHTML = iconImg() +
      '<div class="pwa-txt"><b>نصب اپلیکیشن حسابداری</b>' +
      '<span>برای دسترسی سریع‌تر، برنامه را روی دستگاه نصب کنید</span></div>' +
      '<button class="pwa-btn pwa-btn-primary" id="pwa-install-yes">نصب</button>' +
      '<button class="pwa-btn pwa-btn-ghost" id="pwa-install-no" aria-label="بستن">&times;</button>';
    document.body.appendChild(b);

    document.getElementById('pwa-install-yes').addEventListener('click', function () {
      if (!deferredPrompt) { b.remove(); return; }
      deferredPrompt.prompt();
      deferredPrompt.userChoice.then(function (choice) {
        if (choice && choice.outcome === 'accepted') {
          toast('برنامه با موفقیت نصب شد.');
        }
        deferredPrompt = null;
        b.remove();
      }).catch(function () {
        deferredPrompt = null;
        b.remove();
      });
    });
    document.getElementById('pwa-install-no').addEventListener('click', function () {
      sessionStorage.setItem('pwa_install_dismissed', '1');
      deferredPrompt = null;
      b.remove();
    });
  }

  window.addEventListener('appinstalled', function () {
    deferredPrompt = null;
    var b = document.getElementById('pwa-install-banner');
    if (b) b.remove();
    toast('برنامه روی دستگاه شما نصب شد.');
  });

  /* ------------------------------------------------------------- *
   *  iOS install instructions (no beforeinstallprompt on iOS Safari)
   * ------------------------------------------------------------- */
  var STORAGE_KEYS = (window.AppConfig && window.AppConfig.STORAGE_KEYS) || {
    PWA_IOS_HINT_SEEN: 'pwa_ios_hint_seen',
    LAST_ONLINE: 'hesab_last_online'
  };
  var SW_UPDATE_INTERVAL = (window.AppConfig && window.AppConfig.UI_TIMINGS && window.AppConfig.UI_TIMINGS.SW_UPDATE_CHECK_INTERVAL) || 30 * 60 * 1000;

  function isIOS() {
    return /iphone|ipad|ipod/i.test(navigator.userAgent) ||
      (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  }

  function maybeShowIOSHint() {
    if (!isIOS() || isStandalone()) return;
    if (localStorage.getItem(STORAGE_KEYS.PWA_IOS_HINT_SEEN) === '1') return;
    // Show after a short delay so it doesn't fight with page load.
    setTimeout(showIOSSheet, 2500);
  }

  function showIOSSheet() {
    if (document.getElementById('pwa-ios-sheet')) return;
    injectStyles();
    var m = document.createElement('div');
    m.className = 'pwa-modal';
    m.id = 'pwa-ios-sheet';
    m.innerHTML =
      '<div class="pwa-sheet">' +
        '<h3>نصب روی iPhone / iPad</h3>' +
        '<p>برای افزودن این برنامه به صفحه اصلی دستگاه:</p>' +
        '<div class="pwa-step"><span class="n">۱</span>' +
          '<span>روی دکمه «اشتراک‌گذاری» <b>&#x2191;</b> در نوار پایین سافاری بزنید.</span></div>' +
        '<div class="pwa-step"><span class="n">۲</span>' +
          '<span>گزینه «افزودن به صفحه اصلی» (Add to Home Screen) را انتخاب کنید.</span></div>' +
        '<div class="pwa-step"><span class="n">۳</span>' +
          '<span>روی «افزودن» بزنید تا آیکون برنامه ساخته شود.</span></div>' +
        '<button class="pwa-btn pwa-btn-primary" id="pwa-ios-ok" ' +
          'style="width:100%;margin-top:16px">متوجه شدم</button>' +
      '</div>';
    document.body.appendChild(m);
    function close() {
      localStorage.setItem(STORAGE_KEYS.PWA_IOS_HINT_SEEN, '1');
      m.remove();
    }
    document.getElementById('pwa-ios-ok').addEventListener('click', close);
    m.addEventListener('click', function (e) { if (e.target === m) close(); });
  }

  // Expose a manual trigger so a menu button can re-open instructions.
  window.PWAInstall = {
    prompt: function () {
      if (deferredPrompt) {
        showInstallBanner();
        document.getElementById('pwa-install-yes') &&
          document.getElementById('pwa-install-yes').click();
      } else if (isIOS()) {
        showIOSSheet();
      } else {
        toast('برنامه قابل نصب نیست یا قبلاً نصب شده است.', 'warning');
      }
    }
  };

  /* ------------------------------------------------------------- *
   *  UPDATE banner (new service worker available)
   * ------------------------------------------------------------- */
  function showUpdateBanner(worker) {
    if (document.getElementById('pwa-update-banner')) return;
    injectStyles();
    var b = document.createElement('div');
    b.id = 'pwa-update-banner';
    b.className = 'pwa-banner top';
    b.innerHTML = iconImg() +
      '<div class="pwa-txt"><b>نسخه جدید در دسترس است</b>' +
      '<span>برای دریافت آخرین تغییرات به‌روزرسانی کنید</span></div>' +
      '<button class="pwa-btn pwa-btn-primary" id="pwa-update-yes">به‌روزرسانی</button>' +
      '<button class="pwa-btn pwa-btn-ghost" id="pwa-update-no" aria-label="بستن">&times;</button>';
    document.body.appendChild(b);
    document.getElementById('pwa-update-yes').addEventListener('click', function () {
      if (worker) worker.postMessage({ type: 'SKIP_WAITING' });
      b.remove();
    });
    document.getElementById('pwa-update-no').addEventListener('click', function () { b.remove(); });
  }

  /* ------------------------------------------------------------- *
   *  Register the service worker + lifecycle wiring
   * ------------------------------------------------------------- */
  function register() {
    var swUrl = ROOT + 'sw.js?v=' + encodeURIComponent(APP_VERSION);
    navigator.serviceWorker.register(swUrl, { scope: ROOT }).then(function (reg) {
      // Detect a waiting worker that's already there.
      if (reg.waiting && navigator.serviceWorker.controller) {
        showUpdateBanner(reg.waiting);
      }
      reg.addEventListener('updatefound', function () {
        var nw = reg.installing;
        if (!nw) return;
        nw.addEventListener('statechange', function () {
          if (nw.state === 'installed' && navigator.serviceWorker.controller) {
            showUpdateBanner(nw);
          }
        });
      });
      // Periodically check for updates (e.g. long-lived tabs).
      setInterval(function () { reg.update().catch(function () {}); }, SW_UPDATE_INTERVAL);
    }).catch(function (err) {
      console.warn('[PWA] SW registration failed:', err);
    });

    // Reload once the new SW takes control.
    var refreshing = false;
    navigator.serviceWorker.addEventListener('controllerchange', function () {
      if (refreshing) return;
      refreshing = true;
      window.location.reload();
    });

    // Messages from SW (background sync feedback).
    navigator.serviceWorker.addEventListener('message', function (e) {
      if (!e.data) return;
      if (e.data.type === 'BG_SYNC_QUEUED') {
        toast('اتصال برقرار نیست؛ درخواست ذخیره شد و هنگام وصل شدن ارسال می‌شود.', 'warning');
      } else if (e.data.type === 'BG_SYNC_FLUSHED') {
        toast('درخواست‌های ذخیره‌شده با موفقیت ارسال شدند.');
      }
    });
  }

  /* ------------------------------------------------------------- *
   *  Connectivity bookkeeping (used by offline.html)
   * ------------------------------------------------------------- */
  function stampOnline() {
    try { if (navigator.onLine) localStorage.setItem(STORAGE_KEYS.LAST_ONLINE, Date.now().toString()); } catch (e) {}
  }
  window.addEventListener('online', stampOnline);

  /* ------------------------------------------------------------- *
   *  Web Push (CRM reminders)
   * ------------------------------------------------------------- */
  function b64urlToBytes(s) {
    var pad = '='.repeat((4 - s.length % 4) % 4);
    var raw = atob((s + pad).replace(/-/g, '+').replace(/_/g, '/'));
    var out = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
    return out;
  }
  function isIOS() { return /iphone|ipad|ipod/i.test(navigator.userAgent); }
  function isStandalone() {
    return window.navigator.standalone === true || (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches);
  }
  function pushSupported() {
    return secureOk && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
  }
  // 'unsupported' | 'ios-install' | 'denied' | 'on' | 'off'
  function pushState() {
    if (isIOS() && !isStandalone()) return Promise.resolve('ios-install');
    if (!pushSupported()) return Promise.resolve('unsupported');
    if (Notification.permission === 'denied') return Promise.resolve('denied');
    return navigator.serviceWorker.ready
      .then(function (reg) { return reg.pushManager.getSubscription(); })
      .then(function (sub) { return sub ? 'on' : 'off'; })
      .catch(function () { return 'off'; });
  }
  // Subscribes this device and registers it on the server. Resolves true on success.
  function enablePush() {
    if (!pushSupported() || !window.Api) return Promise.resolve(false);
    return Notification.requestPermission().then(function (perm) {
      if (perm !== 'granted') return false;
      return Promise.all([navigator.serviceWorker.ready, Api.call('push_public_key')]).then(function (r) {
        var reg = r[0], key = r[1] && r[1].public_key;
        if (!key) return false;
        return reg.pushManager.getSubscription().then(function (old) {
          return old || reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64urlToBytes(key) });
        }).then(function (sub) {
          var j = sub.toJSON();
          return Api.call('push_subscribe', { endpoint: j.endpoint, keys: j.keys }).then(function (res) { return !!(res && res.success); });
        });
      });
    }).catch(function (e) { console.warn('[PWA] push subscribe failed:', e); return false; });
  }
  function disablePush() {
    if (!pushSupported()) return Promise.resolve(true);
    return navigator.serviceWorker.ready.then(function (reg) { return reg.pushManager.getSubscription(); }).then(function (sub) {
      if (!sub) return true;
      var endpoint = sub.endpoint;
      return sub.unsubscribe().then(function () { return window.Api ? Api.call('push_unsubscribe', { endpoint: endpoint }) : null; }).then(function () { return true; });
    }).catch(function () { return false; });
  }
  window.Pwa = { pushState: pushState, enablePush: enablePush, disablePush: disablePush };

  /* ------------------------------------------------------------- *
   *  Boot
   * ------------------------------------------------------------- */
  function boot() {
    stampOnline();
    maybeShowIOSHint();
    if (!APP_VERSION) {
      // Without a version we would register a different SW URL than other pages.
      console.warn('[PWA] APP_VERSION missing; service worker registration skipped on this page.');
      return;
    }
    if ('serviceWorker' in navigator && secureOk) {
      register();
    } else if (!secureOk) {
      console.info('[PWA] Service worker disabled: insecure context (needs HTTPS or localhost).');
    }
  }

  if (document.readyState === 'complete' || document.readyState === 'interactive') {
    boot();
  } else {
    window.addEventListener('DOMContentLoaded', boot);
  }
})();
