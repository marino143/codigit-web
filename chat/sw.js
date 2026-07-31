/* Naš chat — service worker: prikaz push notifikacija i otvaranje aplikacije. */
'use strict';

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));

self.addEventListener('push', event => {
    let data = {};
    try { data = event.data ? event.data.json() : {}; } catch (e) {}
    const title = data.title || 'Our Chat';
    event.waitUntil(self.registration.showNotification(title, {
        body: data.body || 'New message',
        tag: data.tag || 'chat',
        icon: 'assets/icon.png',
        badge: 'assets/icon.png',
        data: { conv: data.conv || 0 },
    }));
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
