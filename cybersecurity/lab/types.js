// Типы устройств лаборатории. Новый тип: LAB.registerType({ type, title, kind, prefix, ports, layers, color, icon, hint, web }).
// web: какие вкладки есть в окне устройства (см. TABS и PAGES в windows.js).
// kind берёт готовое поведение (host, router, switch, hub); для своего поведения добавь receive(sim, tick, dev, ifIdx, frame).
// icon: pc | server | router | switch | hub, или функция icon(svg) => SVG-элемент (см. ui.js).
(function () {
  const range = (prefix, from, to) => Array.from({ length: to - from + 1 }, (_, i) => prefix + (from + i));

  LAB.registerType({
    type: 'pc', title: 'ПК', kind: 'host', prefix: 'PC', icon: 'pc',
    ports: ['eth0'], layers: [1, 7], color: '#ff8f8f', ttl: 64, web: ['net', 'diag', 'browser'],
    hint: 'Конечный узел: работает на всех 7 уровнях. Нужны IP, маска и шлюз.',
  });
  LAB.registerType({
    type: 'server', title: 'Сервер', kind: 'host', prefix: 'SRV', icon: 'server',
    ports: ['eth0'], layers: [1, 7], color: '#f5a964', ttl: 128, web: ['net', 'services', 'diag', 'browser'],
    hint: 'Узел, на котором можно запустить службы: DNS, DHCP и веб-сервер со статической страницей.',
  });
  LAB.registerType({
    type: 'switch', title: 'Коммутатор', kind: 'switch', prefix: 'SW', icon: 'switch',
    ports: range('Fa0/', 1, 8), layers: [1, 2], color: '#9d8ff0', web: ['switch'],
    hint: 'L2: запоминает MAC-адреса за портами и пересылает кадр только туда, где получатель. VLAN делит его на отдельные сети.',
  });
  LAB.registerType({
    type: 'router', title: 'Маршрутизатор', kind: 'router', prefix: 'R', icon: 'router',
    ports: range('G0/', 0, 3), layers: [1, 3], color: '#5aa7ff', ttl: 255, web: ['router', 'diag'],
    hint: 'L3: соединяет разные сети. У каждого интерфейса свой IP, путь выбирается по таблице маршрутизации.',
  });
  LAB.registerType({
    type: 'hub', title: 'Концентратор', kind: 'hub', prefix: 'HUB', icon: 'hub',
    ports: range('P', 1, 6), layers: [1, 1], color: '#d68fe0',
    hint: 'L1: просто повторяет сигнал во все порты. Адресов не понимает и настроек не имеет.', web: [],
  });
})();
