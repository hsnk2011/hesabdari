/* =================================================================
 * Service Worker — hesabdari accounting PWA
 * Strategies:
 *   - Cache-first  : static assets (css, js, fonts, images, icons)
 *   - Network-first: navigations + dynamic GET content
 *   - Network-only : API (POST) with optional background-sync queue
 * Includes: versioned caches w/ auto-cleanup, offline fallback,
 *           background sync outbox, push notification handlers.
 * ================================================================= */

/* Version comes from the ?v= query that pwa.js appends to the registration
 * URL (sourced from PHP APP_VERSION). The literal is only a fallback for
 * direct/unversioned registration. */
const SW_QUERY_VERSION = (() => {
  try { return new URL(self.location.href).searchParams.get('v') || ''; } catch (e) { return ''; }
})();
const CACHE_VERSION = 'v' + (SW_QUERY_VERSION || '2.0.119');
const STATIC_CACHE = `hesab-static-${CACHE_VERSION}`;
const DYNAMIC_CACHE = `hesab-dynamic-${CACHE_VERSION}`;
const OFFLINE_URL = 'offline.html';

function emptyResponse(status, text) {
  return new Response(text || '', { status: status || 503, statusText: 'Offline' });
}

async function matchResponse(request, opts) {
  const hit = await caches.match(request, opts);
  return hit || null;
}

/* App shell — paths are relative to the SW scope (the app root), so the
 * same SW works whether the app is served from the domain root or a
 * sub-directory. Missing files are skipped instead of failing install. */
/* NOTE: index.php / './' are intentionally NOT precached — the HTML embeds a
 * per-session CSRF token, so a cached copy would carry a stale token and every
 * API POST would fail with 403. Navigations stay network-first with the
 * offline.html fallback instead. */
const PRECACHE_URLS = [
  'offline.html',
  'manifest.php',
  'style.css',
  'assets/css/bootstrap.rtl.min.css',
  'assets/css/bootstrap-icons.css',
  'assets/css/dark-mode.css',
  'assets/css/vazirmatn-font-face.css',
  'assets/css/toastr.min.css',
  'assets/css/select2.min.css',
  'assets/css/dataTables.bootstrap5.css',
  'assets/css/print-label.css',
  'assets/css/bd-datepicker.css',
  'assets/js/jquery.min.js',
  'assets/js/barcode-detector-polyfill.min.js',
  'assets/js/bootstrap.bundle.min.js',
  'assets/js/chart.js',
  'assets/js/toastr.min.js',
  'assets/js/xlsx.full.min.js',
  'js/config.js',
  'js/api.js',
  'js/ui.js',
  'js/sayad-scanner.js',
  'js/app.js',
  'js/pwa.js',
  'assets/icons/icon-192.png',
  'assets/icons/icon-512.png',
  'assets/icons/apple-touch-icon.png'
];

/* ---------------------------------------------------------------- *
 *  INSTALL — precache the app shell (resilient: ignore failures)
 * ---------------------------------------------------------------- */
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(STATIC_CACHE).then((cache) => {
      return Promise.all(
        PRECACHE_URLS.map((url) =>
          cache.add(new Request(url, { cache: 'reload' })).catch((err) => {
            console.warn('[SW] precache skipped:', url, err && err.message);
          })
        )
      );
    }).then(() => self.skipWaiting())
  );
});

/* ---------------------------------------------------------------- *
 *  ACTIVATE — clean up old caches, enable navigation preload
 * ---------------------------------------------------------------- */
self.addEventListener('activate', (event) => {
  event.waitUntil(
    (async () => {
      const keys = await caches.keys();
      await Promise.all(
        keys.map((key) => {
          if (key !== STATIC_CACHE && key !== DYNAMIC_CACHE) {
            return caches.delete(key);
          }
          return null;
        })
      );
      if (self.registration.navigationPreload) {
        try { await self.registration.navigationPreload.enable(); } catch (e) {}
      }
      await self.clients.claim();
      // Fire-and-forget: flush any queued offline submissions in the
      // background. Deliberately NOT awaited (and never awaited in install)
      // so activation/page load is not delayed, and fetch handling never
      // waits on the outbox.
      replayOutbox().catch(() => {});
    })()
  );
});

/* ---------------------------------------------------------------- *
 *  Helpers
 * ---------------------------------------------------------------- */
function isStaticAsset(request, url) {
  const dest = request.destination;
  if (['style', 'script', 'font', 'image'].includes(dest)) return true;
  return /\.(css|js|mjs|png|jpe?g|gif|svg|webp|ico|woff2?|ttf|eot)(\?|$)/i.test(url.pathname);
}

function isApiRequest(url) {
  return /(^|\/)api(\.php)?(\/|$|\?)/i.test(url.pathname) || /\/api\//i.test(url.pathname);
}

/* Cache-first: serve from cache, fall back to network, then revalidate. */
async function cacheFirst(request) {
  const cached = await matchResponse(request);
  if (cached) {
    // Revalidate in background (best-effort) for next time.
    fetchAndPut(request, STATIC_CACHE).catch(() => {});
    return cached;
  }
  return fetchAndPut(request, STATIC_CACHE);
}

async function fetchAndPut(request, cacheName) {
  const response = await fetch(request);
  if (response && response.ok && (request.method === 'GET')) {
    const copy = response.clone();
    const cache = await caches.open(cacheName);
    cache.put(request, copy).catch(() => {});
  }
  return response;
}

/* Network-first: try network (store copy), fall back to cache. */
async function networkFirst(request, event) {
  try {
    const preload = event && event.preloadResponse ? await event.preloadResponse : null;
    const response = preload || await fetch(request);
    if (response && response.ok && request.method === 'GET') {
      const copy = response.clone();
      caches.open(DYNAMIC_CACHE).then((c) => c.put(request, copy)).catch(() => {});
    }
    return response;
  } catch (err) {
    const cached = await matchResponse(request);
    if (cached) return cached;
    throw err;
  }
}

/* ---------------------------------------------------------------- *
 *  FETCH — route by request type
 * ---------------------------------------------------------------- */
self.addEventListener('fetch', (event) => {
  const request = event.request;
  const url = new URL(request.url);

  // Only manage HTTP(S) requests from our own origin (let CDNs/others pass,
  // but opportunistically cache same-domain assets only).
  if (!url.protocol.startsWith('http')) return;

  // ---- Non-GET (API mutations / form posts) ----
  if (request.method !== 'GET') {
    if (isApiRequest(url) && request.headers.get('X-Background-Sync') === '1') {
      event.respondWith(handleBackgroundSyncPost(request));
    }
    // Otherwise let the request go to the network untouched (no caching).
    return;
  }

  // ---- Same-origin only beyond this point for caching logic ----
  const sameOrigin = url.origin === self.location.origin;

  // ---- Navigations: network-first w/ offline fallback ----
  if (request.mode === 'navigate') {
    event.respondWith(
      networkFirst(request, event).catch(async () => {
        const cached = await matchResponse(request, { ignoreSearch: true });
        if (cached) return cached;
        const offline = await matchResponse(OFFLINE_URL);
        return offline || emptyResponse(503, 'Offline');
      })
    );
    return;
  }

  // ---- API GET (rare) : network-first ----
  if (sameOrigin && isApiRequest(url)) {
    event.respondWith(
      networkFirst(request, event).catch(
        () => new Response(
          JSON.stringify({ error: 'offline', offline: true }),
          { status: 503, headers: { 'Content-Type': 'application/json' } }
        )
      )
    );
    return;
  }

  // ---- Static assets (same origin OR cacheable CDN) : cache-first ----
  if (isStaticAsset(request, url)) {
    event.respondWith(
      cacheFirst(request).catch(async () => {
        const cached = await matchResponse(request);
        if (cached) return cached;
        // Bypass HTTP cache once more — avoids stuck "504 Offline" loops
        // when the SW previously failed during DNS/SSL cutover.
        try {
          return await fetch(request, { cache: 'reload' });
        } catch (e2) {
          if (request.destination === 'image') {
            return (await matchResponse('assets/icons/icon-192.png')) || emptyResponse(404);
          }
          return emptyResponse(503);
        }
      })
    );
    return;
  }

  // ---- Everything else same-origin : network-first ----
  if (sameOrigin) {
    event.respondWith(
      networkFirst(request, event).catch(async () => {
        return (await matchResponse(request)) || emptyResponse(503);
      })
    );
  }
});

/* ================================================================= *
 *  BACKGROUND SYNC — outbox for offline form submissions (opt-in)
 *  A request only enters the queue when it carries the header
 *  `X-Background-Sync: 1`. This prevents accidental duplicate
 *  submission of financial documents. See PWA_REPORT.md.
 * ================================================================= */
const DB_NAME = 'hesab-pwa';
const STORE = 'outbox';

function openDB() {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open(DB_NAME, 1);
    req.onupgradeneeded = () => {
      const db = req.result;
      if (!db.objectStoreNames.contains(STORE)) {
        db.createObjectStore(STORE, { keyPath: 'id', autoIncrement: true });
      }
    };
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}

function dbAdd(record) {
  return openDB().then((db) => new Promise((resolve, reject) => {
    const tx = db.transaction(STORE, 'readwrite');
    tx.objectStore(STORE).add(record);
    tx.oncomplete = () => resolve();
    tx.onerror = () => reject(tx.error);
  }));
}

function dbAll() {
  return openDB().then((db) => new Promise((resolve, reject) => {
    const tx = db.transaction(STORE, 'readonly');
    const req = tx.objectStore(STORE).getAll();
    req.onsuccess = () => resolve(req.result || []);
    req.onerror = () => reject(req.error);
  }));
}

function dbDelete(id) {
  return openDB().then((db) => new Promise((resolve, reject) => {
    const tx = db.transaction(STORE, 'readwrite');
    tx.objectStore(STORE).delete(id);
    tx.oncomplete = () => resolve();
    tx.onerror = () => reject(tx.error);
  }));
}

async function handleBackgroundSyncPost(request) {
  try {
    return await fetch(request.clone());
  } catch (err) {
    // Offline — store for later and ask the browser to sync.
    try {
      const body = await request.clone().text();
      const headers = {};
      request.headers.forEach((v, k) => { headers[k] = v; });
      await dbAdd({
        url: request.url,
        method: request.method,
        headers,
        body,
        ts: Date.now()
      });
      if ('sync' in self.registration) {
        try { await self.registration.sync.register('sync-outbox'); } catch (e) {}
      }
      await notifyClients({ type: 'BG_SYNC_QUEUED' });
    } catch (e) {
      console.warn('[SW] failed to queue request', e);
    }
    return new Response(
      JSON.stringify({ queued: true, offline: true,
        message: 'درخواست ذخیره شد و هنگام اتصال به اینترنت ارسال می‌شود.' }),
      { status: 202, headers: { 'Content-Type': 'application/json' } }
    );
  }
}

async function replayOutbox() {
  const items = await dbAll();
  for (const item of items) {
    let res = null;
    try {
      res = await fetch(item.url, {
        method: item.method,
        headers: item.headers,
        body: item.body,
        credentials: 'include'
      });
    } catch (e) {
      // Network failure — still offline. Keep every remaining item, ask for
      // another sync round and stop quietly.
      if ('sync' in self.registration) {
        try { await self.registration.sync.register('sync-outbox'); } catch (e2) {}
      }
      return;
    }
    if (res && res.ok) {
      await dbDelete(item.id);
    } else if (res && res.status >= 400 && res.status < 500) {
      // Permanent client error (validation, auth, bad URL…). Retrying it
      // forever would poison the queue, so drop it.
      console.warn('[SW] outbox item dropped (client error ' + res.status + '):', item.url);
      await dbDelete(item.id);
    }
    // 5xx / opaque responses: keep the item and try the next one.
  }
  await notifyClients({ type: 'BG_SYNC_FLUSHED' });
}

self.addEventListener('sync', (event) => {
  if (event.tag === 'sync-outbox') {
    event.waitUntil(replayOutbox().catch((e) => {
      console.warn('[SW] outbox replay failed', e);
    }));
  }
});

async function notifyClients(msg) {
  const all = await self.clients.matchAll({ includeUncontrolled: true });
  all.forEach((c) => c.postMessage(msg));
}

/* Allow the page to trigger skipWaiting (update flow) and manual flush. */
self.addEventListener('message', (event) => {
  if (!event.data) return;
  if (event.data.type === 'SKIP_WAITING') self.skipWaiting();
  if (event.data.type === 'FLUSH_OUTBOX') replayOutbox().catch(() => {});
});

/* ================================================================= *
 *  PUSH NOTIFICATIONS (registration / receive support)
 * ================================================================= */
self.addEventListener('push', (event) => {
  let data = { title: 'سیستم حسابداری', body: 'یک اعلان جدید دارید.' };
  if (event.data) {
    try { data = Object.assign(data, event.data.json()); }
    catch (e) { data.body = event.data.text(); }
  }
  const options = {
    body: data.body,
    icon: 'assets/icons/icon-192.png',
    badge: 'assets/icons/icon-96.png',
    dir: 'rtl',
    lang: 'fa',
    data: { url: data.url || './index.php' },
    vibrate: [80, 40, 80]
  };
  // Chat pushes share a tag per conversation: a new message replaces the previous one.
  if (data.tag) { options.tag = data.tag; options.renotify = true; }
  event.waitUntil(self.registration.showNotification(data.title, options));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = (event.notification.data && event.notification.data.url) || './index.php';
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
      // Reuse an open window and take it to the task/customer the push is about.
      for (const client of list) {
        if ('focus' in client) {
          const absolute = new URL(target, self.registration.scope).href;
          if (client.url !== absolute && 'navigate' in client) {
            return client.navigate(absolute).then((c) => (c || client).focus()).catch(() => client.focus());
          }
          return client.focus();
        }
      }
      if (self.clients.openWindow) return self.clients.openWindow(target);
      return null;
    })
  );
});
