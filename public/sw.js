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

    event.waitUntil(
        self.registration.showNotification(data.title, {
            body: data.body,
            icon: '/images/pwa/icon-192.png',
            badge: '/images/pwa/badge-72.png',
            tag: data.tag || 'moto-gate-notification',
            renotify: true,
            data: {
                url: data.url || '/admin',
            },
        })
    );
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