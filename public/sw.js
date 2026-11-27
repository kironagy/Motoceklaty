// Owner 2026-11-27: a new version takes over at once (the old one kept running on open tabs)
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
    let data = {
        title: 'Moto Gate',
        body: 'لديك إشعار جديد',
        url: '/admin',
    };

    try {
        if (event.data) {
            data = { ...data, ...event.data.json() };
        }
    } catch (error) {
        console.error('Push payload error:', error);
    }

    event.waitUntil(Promise.all([
        self.registration.showNotification(data.title, {
            body: data.body,
            // the old /images/pwa/ icons were missing (404)
            icon: '/images/app/icon-192.png',
            tag: data.tag || 'moto-gate-notification',
            renotify: true,
            data: {
                url: data.url || '/admin',
            },
        }),
        // Owner 2026-11-27: an open tab shows it too, inside the site - even when the computer
        // or the phone hides the browser's notifications
        self.clients.matchAll({ type: 'window', includeUncontrolled: true })
            .then((list) => list.forEach((client) => client.postMessage({ type: 'mg-push', ...data }))),
    ]));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const targetUrl = event.notification.data?.url || '/admin';

    event.waitUntil(
        clients.matchAll({
            type: 'window',
            includeUncontrolled: true,
        }).then((clientList) => {
            for (const client of clientList) {
                if (client.url.includes(targetUrl) && 'focus' in client) {
                    return client.focus();
                }
            }

            return clients.openWindow(targetUrl);
        })
    );
});
