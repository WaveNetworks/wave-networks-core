/**
 * Service Worker — Web Push notifications for Wave Networks Core
 * Scope: /admin/
 */

self.addEventListener('push', function(event) {
    var data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        data = { title: 'New Notification', body: event.data ? event.data.text() : '' };
    }

    var title = data.title || 'New Notification';
    var options = {
        body:  data.body || '',
        icon:  data.icon || '/admin/assets/img/icon-192.png',
        badge: data.badge || '/admin/assets/img/icon-192.png',
        tag:   data.tag || 'wn-notification-' + Date.now(),
        data: {
            action_url: data.action_url || '/admin/app/index.php?page=notifications'
        },
        requireInteraction: false
    };

    event.waitUntil(
        self.registration.showNotification(title, options)
    );
});

self.addEventListener('notificationclick', function(event) {
    event.notification.close();

    var url = event.notification.data && event.notification.data.action_url
        ? event.notification.data.action_url
        : '/admin/app/index.php?page=notifications';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function(clientList) {
            // Focus existing tab if one is open
            for (var i = 0; i < clientList.length; i++) {
                var client = clientList[i];
                if (client.url.indexOf('/admin/') !== -1 && 'focus' in client) {
                    client.focus();
                    client.navigate(url);
                    return;
                }
            }
            // Otherwise open a new window
            if (clients.openWindow) {
                return clients.openWindow(url);
            }
        })
    );
});

self.addEventListener('pushsubscriptionchange', function(event) {
    // Re-subscribe with new credentials if subscription changes
    event.waitUntil(
        self.registration.pushManager.subscribe(event.oldSubscription.options)
            .then(function(subscription) {
                // Notify server of new subscription
                return fetch('/admin/api/index.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=registerPushSubscription' +
                          '&endpoint='  + encodeURIComponent(subscription.endpoint) +
                          '&p256dh='    + encodeURIComponent(btoa(String.fromCharCode.apply(null, new Uint8Array(subscription.getKey('p256dh'))))) +
                          '&auth='      + encodeURIComponent(btoa(String.fromCharCode.apply(null, new Uint8Array(subscription.getKey('auth')))))
                });
            })
    );
});
