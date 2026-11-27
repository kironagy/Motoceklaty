{{-- Owner 2026-11-27: a push that reaches the browser also shows inside the site (a toast), on every
     admin page - his computer received them but never showed them. The service worker is kept
     registered (and updated) from any page once notifications are allowed. --}}
<script>
(function () {
    if (!('serviceWorker' in navigator)) return;
    if (window.Notification && Notification.permission === 'granted') {
        navigator.serviceWorker.register('/sw.js').catch(() => {});
    }
    if (window.__mgPushListener) return;
    window.__mgPushListener = true;
    navigator.serviceWorker.addEventListener('message', function (event) {
        var d = event.data || {};
        if (d.type !== 'mg-push') return;
        window.dispatchEvent(new CustomEvent('mg-push', { detail: d }));
        if (!window.FilamentNotification) return;
        var n = new FilamentNotification().title(d.title || 'Moto Gate').body(d.body || '').icon('heroicon-o-bell-alert').iconColor('info').duration(15000);
        if (d.url && window.FilamentNotificationAction) {
            n.actions([new FilamentNotificationAction('open').label('افتح').url(d.url).button()]);
        }
        n.send();
    });
})();
</script>
