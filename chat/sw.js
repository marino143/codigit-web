/* Naš chat — service worker: notifikacije, brzo otvaranje i rad bez mreže. */
'use strict';

const SHELL_CACHE = 'chat-shell-v1';   // stilovi, skripta, ikone (imaju ?v= pa se ne mijenjaju)
const DATA_CACHE  = 'chat-data-v1';    // zadnji viđeni razgovori i poruke

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', event => {
    event.waitUntil((async () => {
        // ukloni cacheve starijih verzija
        const keep = [SHELL_CACHE, DATA_CACHE];
        for (const name of await caches.keys()) {
            if (!keep.includes(name)) await caches.delete(name);
        }
        await self.clients.claim();
    })());
});

/**
 * Statika (assets/…) se smije servirati iz cachea jer nosi ?v= u adresi.
 * Podaci idu prvo na mrežu, a kad je nema — iz zadnjeg spremljenog odgovora,
 * da se stari razgovori mogu čitati i bez signala.
 */
self.addEventListener('fetch', event => {
    const req = event.request;
    if (req.method !== 'GET') return;

    const url = new URL(req.url);
    if (url.origin !== self.location.origin) return;

    const isAsset = url.pathname.includes('/assets/') || url.pathname.endsWith('/manifest.json');
    const isPoll = url.pathname.endsWith('/api.php') && url.searchParams.get('action') === 'poll';
    const isPage = url.pathname.endsWith('/index.php') || url.pathname === '/' || url.pathname === '';

    if (isAsset) {
        event.respondWith((async () => {
            const cached = await caches.match(req);
            if (cached) return cached;
            const res = await fetch(req);
            if (res.ok) (await caches.open(SHELL_CACHE)).put(req, res.clone());
            return res;
        })());
        return;
    }

    if (isPoll || isPage) {
        event.respondWith((async () => {
            try {
                const res = await fetch(req);
                if (res.ok) {
                    const cache = await caches.open(DATA_CACHE);
                    // pamti se samo poll bez `since` (puni popis) i sama stranica
                    if (isPage || !url.searchParams.get('since')) cache.put(req, res.clone());
                }
                return res;
            } catch (e) {
                const cached = await caches.match(req, { ignoreSearch: isPage });
                if (cached) return cached;
                if (isPoll) {
                    return new Response(JSON.stringify({ offline: true, convs: [], users: [] }),
                        { headers: { 'Content-Type': 'application/json' } });
                }
                throw e;
            }
        })());
    }
});

self.addEventListener('push', event => {
    let data = {};
    try { data = event.data ? event.data.json() : {}; } catch (e) {}
    const title = data.title || 'Our Chat';
    event.waitUntil(Promise.all([
        self.registration.showNotification(title, {
            body: data.body || 'New message',
            // Poruke iz istog razgovora dijele oznaku pa se ne gomilaju u niz
            // obavijesti, ali renotify javi zvukom/bannerom svaku novu.
            tag: data.tag || 'chat',
            renotify: true,
            icon: 'assets/icon.png',
            badge: 'assets/icon.png',
            data: { conv: data.conv || 0 },
        }),
        // broj na ikoni aplikacije (macOS dock / iOS home screen)
        (async () => {
            if (!('setAppBadge' in self.navigator)) return;
            const n = typeof data.badge === 'number' ? data.badge : 0;
            try { n > 0 ? await self.navigator.setAppBadge(n) : await self.navigator.clearAppBadge(); } catch (e) {}
        })(),
    ]));
});

/**
 * Preglednik povremeno sam poništi i zamijeni pretplatu (istek ključeva,
 * čišćenje). Bez ovoga korisnik tiho prestane primati notifikacije.
 */
self.addEventListener('pushsubscriptionchange', event => {
    event.waitUntil((async () => {
        try {
            const old = event.oldSubscription || await self.registration.pushManager.getSubscription();
            const key = (event.newSubscription && event.newSubscription.options.applicationServerKey)
                || (old && old.options && old.options.applicationServerKey);
            const fresh = event.newSubscription
                || await self.registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });
            const j = fresh.toJSON();
            const body = new URLSearchParams({
                endpoint: fresh.endpoint,
                p256dh: j.keys.p256dh,
                auth: j.keys.auth,
                old_endpoint: old ? old.endpoint : '',
            });
            await fetch('api.php?action=push_resubscribe', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
                credentials: 'include',
            });
        } catch (e) { /* pokušat ćemo opet kad se aplikacija otvori */ }
    })());
});

self.addEventListener('notificationclick', event => {
    event.notification.close();
    event.waitUntil((async () => {
        const all = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        for (const c of all) {
            if ('focus' in c) { await c.focus(); return; }
        }
        await self.clients.openWindow('index.php');
    })());
});
