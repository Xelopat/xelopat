// Service worker страницы тренировок: показывает утреннее напоминание о завтраке.
// Пуш приходит пустым, текст собирается здесь по дню недели на устройстве.
// Названия дней дублируют workout/plan.php.
const TODAY = {
  1: 'Пн — верх / сила',
  2: 'Вт — ноги',
  3: 'Ср — кардио',
  4: 'Чт — верх / масса',
  5: 'Пт — ноги / объём + кардио',
  6: 'Сб — прогулка',
  7: 'Вс — отдых',
};

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));

self.addEventListener('push', (e) => {
  const d = new Date().getDay() || 7;
  e.waitUntil(self.registration.showNotification('Позавтракай 🍳', {
    body: 'Белок и углеводы с утра. Сегодня: ' + TODAY[d],
    icon: '/img/xelopat.png',
    badge: '/img/xelopat.png',
    tag: 'breakfast',
    data: { url: '/workout' },
  }));
});

self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  const url = (e.notification.data && e.notification.data.url) || '/workout';
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
    for (const c of list) {
      if (c.url.includes('/workout') && 'focus' in c) return c.focus();
    }
    return self.clients.openWindow(url);
  }));
});
