/**
 * «فعال‌سازی نوتیفیکیشن» (میز کار): Pwa.enablePush() must reach the server.
 * js/api.js declares `const Api`, which — like any top-level const — is not a
 * property of window; checking `window.Api` made activation fail at once on
 * every browser. Loads js/pwa.js with Api declared the same way and fake
 * browser push objects.
 * Run: node tests/pwa_push_enable_test.js
 */
const fs = require('fs');
const path = require('path');
const vm = require('vm');

let passed = 0, failed = 0;
const expect = (c, m) => { if (c) { passed++; console.log('[PASS] ' + m); } else { failed++; console.log('[FAIL] ' + m); } };

function load(permission) {
    const calls = [];
    const subscription = {
        endpoint: 'https://push.example.test/abc',
        toJSON() { return { endpoint: this.endpoint, keys: { p256dh: 'p', auth: 'a' } }; },
        unsubscribe: async () => true,
    };
    const reg = {
        pushManager: {
            getSubscription: async () => null,
            subscribe: async (opts) => { calls.push(['subscribe', opts.applicationServerKey.length]); return subscription; },
        },
        addEventListener() {},
        update: async () => {},
    };
    const ctx = {
        console: { log() {}, warn() {}, info() {} },
        setTimeout, clearTimeout, setInterval: () => 0, Promise, Uint8Array, URL,
        atob: (s) => Buffer.from(s, 'base64').toString('binary'),
        isSecureContext: true,
        location: { hostname: 'localhost', href: 'https://localhost/hesabdari/' },
        navigator: { userAgent: 'test', serviceWorker: { ready: Promise.resolve(reg), register: async () => reg, addEventListener() {} } },
        PushManager: function () {},
        Notification: { permission: 'default', requestPermission: async () => permission },
        matchMedia: () => ({ matches: false }),
        localStorage: { getItem: () => null, setItem() {} },
        document: {
            readyState: 'loading',
            currentScript: { src: 'https://localhost/hesabdari/js/pwa.js?v=1' },
            getElementsByTagName: () => [],
            getElementById: () => null,
            addEventListener() {},
            createElement: () => ({ style: {}, setAttribute() {}, appendChild() {} }),
            head: { appendChild() {} },
            body: { appendChild() {} },
        },
        addEventListener() {},
        __APP_VERSION__: '1',
    };
    ctx.window = ctx;
    vm.createContext(ctx);
    // Same shape as js/api.js: a top-level const, not a window property.
    vm.runInContext(`const Api = { call: async (action, data) => {
        __calls.push([action, data]);
        if (action === 'push_public_key') return { success: true, public_key: 'BGv8_dkS6zDuWBUd_l5dhn0qw2AXidfTYlZP41nhjOlM_TuqdQKctSVGC3tsvqbeSRK6NkA0J4RKGZjh2oEGGzc' };
        if (action === 'push_subscribe') return { success: true };
        return {};
    } };`, Object.assign(ctx, { __calls: calls }));
    vm.runInContext(fs.readFileSync(path.join(__dirname, '../js/pwa.js'), 'utf8'), ctx);
    return { ctx, calls };
}

(async () => {
    const { ctx, calls } = load('granted');
    expect(typeof ctx.window.Api === 'undefined', 'like the real page, Api is not a property of window');
    const res = await ctx.Pwa.enablePush();
    expect(res && res.ok === true, 'activation succeeds when permission is granted');
    expect(calls.some(c => c[0] === 'push_public_key'), 'the server key is requested');
    expect(calls.some(c => c[0] === 'subscribe' && c[1] === 65), 'the browser subscription uses the 65-byte VAPID key');
    expect(calls.some(c => c[0] === 'push_subscribe' && c[1].endpoint === 'https://push.example.test/abc'), 'the device is registered on the server');

    const dismissed = await load('default').ctx.Pwa.enablePush();
    expect(dismissed && dismissed.ok === false && dismissed.reason === 'dismissed', 'a dismissed permission prompt is reported as such');
    const denied = await load('denied').ctx.Pwa.enablePush();
    expect(denied && denied.reason === 'denied', 'a blocked permission is reported as such');

    console.log(`\nPassed: ${passed}, Failed: ${failed}`);
    process.exit(failed ? 1 : 0);
})();
