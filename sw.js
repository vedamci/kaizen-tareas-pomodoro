self.addEventListener('install', event => self.skipWaiting());
self.addEventListener('activate', event => event.waitUntil(self.clients.claim()));
self.addEventListener('push', event => {
  let data = {};
  try { data = event.data ? event.data.json() : {}; } catch (e) { data = { body: event.data ? event.data.text() : '' }; }
  const tag=data.tag||('kaizen-pomodoro-'+Date.now());
  event.waitUntil((async()=>{
    // A foreground timer can already have displayed the same job.
    if(self.registration.getNotifications&&(await self.registration.getNotifications({tag})).length)return;
    await self.registration.showNotification(data.title||'Kaizen',{
      body:data.body||'Tienes un aviso nuevo.',tag,renotify:false,requireInteraction:true,silent:false,
      timestamp:Number(data.timestamp)||Date.now(),data:{url:'./'}
    });
  })());
});
self.addEventListener('notificationclick', event => {
  event.notification.close();
  const scope=new URL(self.registration.scope);
  const target=new URL(event.notification.data?.url||'./',scope);
  const url=target.origin===scope.origin&&target.pathname.startsWith(scope.pathname)?target.href:scope.href;
  event.waitUntil(self.clients.matchAll({type:'window',includeUncontrolled:true}).then(clients=>{
    for(const client of clients)if(client.url?.startsWith(scope.href)&&'focus' in client)return client.focus();
    if(self.clients.openWindow)return self.clients.openWindow(url);
  }));
});
