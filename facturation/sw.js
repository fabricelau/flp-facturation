/* Facturation Fabrice Lau Production : notifications sur le téléphone */
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));
self.addEventListener('push', e => {
  let d = {};
  try { d = e.data ? e.data.json() : {}; } catch (_) { d = { body: e.data ? e.data.text() : '' }; }
  if (typeof d.badge === 'number' && self.navigator && 'setAppBadge' in self.navigator) { try { (d.badge ? self.navigator.setAppBadge(d.badge) : self.navigator.clearAppBadge()).catch(() => {}); } catch (_) {} }
  e.waitUntil(self.registration.showNotification(d.title || 'Facturation', {
    body: d.body || '', icon: 'assets/icone-192.png', badge: 'assets/icone-192.png',
    tag: d.tag || undefined, renotify: !!d.tag, data: { url: d.url || './' }
  }));
});
self.addEventListener('notificationclick', e => {
  e.notification.close();
  const url = (e.notification.data && e.notification.data.url) || './';
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(list => {
    for (const c of list) if ('focus' in c) { c.focus(); return; }
    return self.clients.openWindow(url);
  }));
});
