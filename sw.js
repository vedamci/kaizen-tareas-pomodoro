self.addEventListener('install', event => self.skipWaiting());
self.addEventListener('activate', event => event.waitUntil(self.clients.claim()));
self.addEventListener('push', event => {
  let data = {};
  try { data = event.data ? event.data.json() : {}; } catch (e) { data = { body: event.data ? event.data.text() : '' }; }
  event.waitUntil(self.registration.showNotification(data.title || 'Kaizen', {
    body: data.body || 'Tienes un aviso nuevo.',
    icon: './kaizen-icon-180.png',
    badge: './kaizen-icon-180.png',
    tag: data.tag || ('kaizen-pomodoro-' + Date.now()),
    renotify: true,
    requireInteraction: true,
    silent: false,
    data: { url: data.url || './' }
  }));
});
self.addEventListener('notificationclick', event => {
  event.notification.close();
  event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(clients => {
    for (const client of clients) if ('focus' in client) return client.focus();
    if (self.clients.openWindow) return self.clients.openWindow(event.notification.data?.url || './');
  }));
});
