/**
 * notifications.js
 * Bell icon dropdown, badge polling, and Web Push subscription management.
 */

(function() {
    'use strict';

    var POLL_INTERVAL = 60000; // 60 seconds
    var pollTimer = null;
    var cachedNotifications = [];

    // ─── BELL BADGE & DROPDOWN ──────────────────────────────────────────────

    function updateBadge(count) {
        var badge = document.getElementById('notifBadge');
        if (!badge) return;

        count = parseInt(count, 10) || 0;
        if (count > 0) {
            badge.textContent = count > 99 ? '99+' : count;
            badge.classList.remove('d-none');
        } else {
            badge.classList.add('d-none');
        }
    }

    function fetchNotifications(callback) {
        apiPost('getNotifications', { limit: 5 }, function(json) {
            if (json.results) {
                cachedNotifications = json.results.notifications || [];
                updateBadge(json.results.unread_count || 0);
            }
            if (callback) callback(json);
        });
    }

    function renderDropdown() {
        var list = document.getElementById('notifList');
        if (!list) return;

        if (cachedNotifications.length === 0) {
            list.innerHTML = '<div class="text-center py-3 text-muted small">No notifications</div>';
            return;
        }

        var html = '';
        cachedNotifications.forEach(function(n) {
            var readClass = n.is_read === '1' || n.is_read === 1 ? 'opacity-50' : '';
            var timeAgo = formatTimeAgo(n.created);
            var icon = n.category_icon || 'bi-bell';

            html += '<a href="#" class="dropdown-item px-3 py-2 border-bottom ' + readClass + '" ' +
                    'data-notification-id="' + n.notification_id + '" ' +
                    'data-action-url="' + (n.action_url || '') + '">' +
                    '<div class="d-flex align-items-start">' +
                    '<i class="bi ' + icon + ' me-2 mt-1 text-primary"></i>' +
                    '<div class="flex-grow-1 overflow-hidden">' +
                    '<div class="fw-semibold small text-truncate">' + escapeHtml(n.title) + '</div>' +
                    '<div class="text-muted small text-truncate">' + escapeHtml(n.body || '') + '</div>' +
                    '<div class="text-muted" style="font-size: 0.7rem;">' + timeAgo + '</div>' +
                    '</div></div></a>';
        });

        list.innerHTML = html;

        // Click handlers
        list.querySelectorAll('[data-notification-id]').forEach(function(el) {
            el.addEventListener('click', function(e) {
                e.preventDefault();
                var nid = this.getAttribute('data-notification-id');
                var url = this.getAttribute('data-action-url');

                apiPost('markNotificationRead', { notification_id: nid }, function() {
                    fetchNotifications();
                });

                if (url) {
                    window.location.href = url;
                }
            });
        });
    }

    function formatTimeAgo(dateStr) {
        var date = new Date(dateStr);
        var now = new Date();
        var diff = Math.floor((now - date) / 1000);

        if (diff < 60)   return 'Just now';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
        return date.toLocaleDateString();
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }

    // ─── MARK ALL READ ──────────────────────────────────────────────────────

    function initMarkAllRead() {
        var btn = document.getElementById('markAllReadBtn');
        if (!btn) return;

        btn.addEventListener('click', function(e) {
            e.preventDefault();
            apiPost('markAllNotificationsRead', {}, function() {
                fetchNotifications(function() {
                    renderDropdown();
                });
            });
        });
    }

    // ─── DROPDOWN OPEN ──────────────────────────────────────────────────────

    function initDropdown() {
        var bell = document.getElementById('notificationBell');
        if (!bell) return;

        var dropdown = bell.querySelector('[data-bs-toggle="dropdown"]');
        if (!dropdown) return;

        dropdown.addEventListener('show.bs.dropdown', function() {
            fetchNotifications(function() {
                renderDropdown();
            });
        });
    }

    // ─── PUSH SUBSCRIPTION ──────────────────────────────────────────────────

    function isPushSupported() {
        return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
    }

    function initPushSubscription() {
        if (!isPushSupported()) return;

        // Register service worker
        navigator.serviceWorker.register('../sw.js', { scope: '../' })
            .then(function(registration) {
                // Check existing subscription
                return registration.pushManager.getSubscription().then(function(subscription) {
                    if (subscription) {
                        // Already subscribed — send to server in case it's new session
                        sendSubscriptionToServer(subscription);
                    }
                    // Store registration for later use
                    window._swRegistration = registration;
                });
            })
            .catch(function(err) {
                console.log('SW registration failed:', err);
            });
    }

    /**
     * Subscribe to push notifications. Call from UI button.
     */
    window.subscribeToPush = function() {
        if (!isPushSupported()) {
            showAlert('warning', 'Push notifications are not supported in this browser.');
            return;
        }

        // First get VAPID public key from server
        apiPost('getVapidPublicKey', {}, function(json) {
            var publicKey = json.results ? json.results.vapid_public_key : '';
            if (!publicKey) {
                showAlert('warning', 'Push notifications are not configured on this server.');
                return;
            }

            var reg = window._swRegistration;
            if (!reg) {
                showAlert('danger', 'Service worker not registered. Please reload the page.');
                return;
            }

            // Convert VAPID key
            var applicationServerKey = urlBase64ToUint8Array(publicKey);

            reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: applicationServerKey
            })
            .then(function(subscription) {
                sendSubscriptionToServer(subscription);
                showAlert('success', 'Push notifications enabled!');
                // Update any UI elements
                updatePushUI('granted');
            })
            .catch(function(err) {
                if (Notification.permission === 'denied') {
                    showAlert('warning', 'Push notifications are blocked. Please enable them in your browser settings.');
                    updatePushUI('denied');
                } else {
                    showAlert('danger', 'Failed to subscribe: ' + err.message);
                }
            });
        });
    };

    /**
     * Unsubscribe from push notifications. Call from UI button.
     */
    window.unsubscribeFromPush = function() {
        if (!window._swRegistration) return;

        window._swRegistration.pushManager.getSubscription().then(function(subscription) {
            if (!subscription) return;

            var endpoint = subscription.endpoint;

            subscription.unsubscribe().then(function() {
                apiPost('unregisterPushSubscription', { endpoint: endpoint }, function() {
                    showAlert('success', 'Push notifications disabled.');
                    updatePushUI('default');
                });
            });
        });
    };

    function sendSubscriptionToServer(subscription) {
        var key   = subscription.getKey('p256dh');
        var auth  = subscription.getKey('auth');

        apiPost('registerPushSubscription', {
            endpoint: subscription.endpoint,
            p256dh:   key  ? btoa(String.fromCharCode.apply(null, new Uint8Array(key)))  : '',
            auth:     auth ? btoa(String.fromCharCode.apply(null, new Uint8Array(auth))) : ''
        });
    }

    function updatePushUI(permission) {
        var enableBtn  = document.getElementById('enablePushBtn');
        var disableBtn = document.getElementById('disablePushBtn');
        var statusText = document.getElementById('pushStatus');

        if (enableBtn)  enableBtn.classList.toggle('d-none', permission === 'granted' || permission === 'denied');
        if (disableBtn) disableBtn.classList.toggle('d-none', permission !== 'granted');
        if (statusText) {
            if (permission === 'granted')     statusText.textContent = 'Push notifications are enabled';
            else if (permission === 'denied') statusText.textContent = 'Push notifications are blocked in your browser';
            else                              statusText.textContent = 'Push notifications are not enabled';
        }
    }

    function urlBase64ToUint8Array(base64String) {
        var padding = '='.repeat((4 - base64String.length % 4) % 4);
        var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        var rawData = window.atob(base64);
        var outputArray = new Uint8Array(rawData.length);
        for (var i = 0; i < rawData.length; i++) {
            outputArray[i] = rawData.charCodeAt(i);
        }
        return outputArray;
    }

    // ─── POLLING ────────────────────────────────────────────────────────────

    function startPolling() {
        fetchNotifications();
        pollTimer = setInterval(function() {
            fetchNotifications();
        }, POLL_INTERVAL);
    }

    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    // Pause polling when tab is hidden
    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            stopPolling();
        } else {
            startPolling();
        }
    });

    // ─── INIT ───────────────────────────────────────────────────────────────

    document.addEventListener('DOMContentLoaded', function() {
        // Only init if bell icon exists (user is logged in)
        if (!document.getElementById('notificationBell')) return;

        initDropdown();
        initMarkAllRead();
        startPolling();
        initPushSubscription();

        // Set initial push UI state
        if (isPushSupported()) {
            updatePushUI(Notification.permission);
        }
    });

})();
