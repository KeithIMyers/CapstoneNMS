/* CapstoneNMS service worker — minimal offline shell.
 *
 * Strategy:
 *   - On install: precache the app icons + the offline fallback page.
 *   - On fetch:   network-first for HTML, cache-first for static assets,
 *                 and an offline-fallback page when both fail.
 *
 * Bumping CACHE_VERSION on a deploy invalidates the old shell. The
 * old caches are deleted in the activate handler so we don't keep
 * stale assets around forever.
 *
 * No notification handling here — that lives separately when the
 * Web Push phase lands.
 */
const CACHE_VERSION = 'capnms-v1';
const SHELL_URLS = [
    '/site/img/favicon.svg',
    '/offline',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_VERSION).then((cache) => cache.addAll(SHELL_URLS).catch(() => null))
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((k) => k !== CACHE_VERSION).map((k) => caches.delete(k)))
        )
    );
    self.clients.claim();
});

/* Web Push: show a notification when the server pushes a payload.
 * Payload shape (from WebPusher::broadcast):
 *   { title: '...', body: '...', url: '/news/foo' (optional), tag, icon }
 */
self.addEventListener('push', (event) => {
    let data = {};
    try { data = event.data ? event.data.json() : {}; } catch (e) {}
    const title = data.title || 'Breaking news';
    const opts = {
        body:  data.body || '',
        icon:  data.icon || '/site/img/favicon.svg',
        badge: '/site/img/favicon.svg',
        tag:   data.tag || 'usnt-breaking',
        data:  { url: data.url || '/' },
    };
    event.waitUntil(self.registration.showNotification(title, opts));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = (event.notification.data && event.notification.data.url) || '/';
    event.waitUntil((async () => {
        const all = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        for (const c of all) {
            if (c.url === url && 'focus' in c) return c.focus();
        }
        if (self.clients.openWindow) return self.clients.openWindow(url);
    })());
});

self.addEventListener('fetch', (event) => {
    const req = event.request;

    // We only handle GETs. POSTs (comment / favorite / track / etc.)
    // pass through untouched so the form actions still hit the server.
    if (req.method !== 'GET') return;

    // Don't intercept admin / livewire / ai routes — they need the
    // network to be the network.
    const url = new URL(req.url);
    if (url.origin !== self.location.origin) return;
    if (
        url.pathname.startsWith('/admin') ||
        url.pathname.startsWith('/livewire') ||
        url.pathname.startsWith('/admin-ai/') ||
        url.pathname.startsWith('/track/') ||
        url.pathname.startsWith('/ad/') ||
        url.pathname.startsWith('/stripe/')
    ) {
        return;
    }

    const accept = req.headers.get('accept') || '';
    const isHtml = req.mode === 'navigate' || accept.includes('text/html');

    if (isHtml) {
        event.respondWith(
            fetch(req)
                .then((res) => {
                    // Cache successful page loads so a repeat visit while
                    // offline still has something to show.
                    if (res.ok) {
                        const clone = res.clone();
                        caches.open(CACHE_VERSION).then((c) => c.put(req, clone));
                    }
                    return res;
                })
                .catch(() => caches.match(req).then((hit) => hit || caches.match('/offline')))
        );
        return;
    }

    // Static assets: cache-first, then fall through to network.
    event.respondWith(
        caches.match(req).then((hit) => {
            if (hit) return hit;
            return fetch(req)
                .then((res) => {
                    if (res.ok) {
                        const clone = res.clone();
                        caches.open(CACHE_VERSION).then((c) => c.put(req, clone));
                    }
                    return res;
                })
                .catch(() => caches.match('/offline'));
        })
    );
});
