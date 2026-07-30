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
