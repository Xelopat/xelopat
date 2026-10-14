// Готовые схемы. Новая: LAB.registerPreset({ id, title, desc, task?, try?: [имя, цель, операция?], build(b) }).
// операция: ping (по умолчанию), tracert, dhcp, dns, http
// b.add(type, x, y, { name, ip: 'a.b.c.d/nn', gw, dns, dhcp: true, ifs: { порт: 'a.b.c.d/nn' }, helpers: { порт: ip },
//                     routes: [['сеть/nn', 'через']], vlans: { порт: n }, services: { dns, dhcp, http } })
// b.link(a, b, портA?, портB?) — без портов берутся первые свободные.
(function () {
  LAB.registerPreset({
    id: 'lan',
    title: 'Одна сеть на коммутаторе',
    desc: 'Три узла в сети 192.168.1.0/24. Пингани сервер с PC1: сначала ARP ищет MAC, коммутатор запоминает порты, затем идёт ICMP. Пингани ещё раз — ARP уже не нужен.',
    try: ['PC1', '192.168.1.100'],
    build(b) {
      const sw = b.add('switch', 0, 0);
      const pc1 = b.add('pc', -220, 160, { ip: '192.168.1.10/24' });
      const pc2 = b.add('pc', 0, 190, { ip: '192.168.1.11/24' });
      const srv = b.add('server', 220, 160, { ip: '192.168.1.100/24' });
      b.link(pc1, sw); b.link(pc2, sw); b.link(srv, sw);
    },
  });

  LAB.registerPreset({
    id: 'routed',
    title: 'Две сети и маршрутизатор',
    desc: 'Сети 192.168.1.0/24 и 192.168.2.0/24 соединены через R1. Узел видит, что адрес в чужой сети, и отправляет кадр на MAC шлюза, а не получателя. Маршрутизатор уменьшает TTL и упаковывает пакет в новый кадр.',
    try: ['PC1', '192.168.2.100'],
    build(b) {
      const r1 = b.add('router', 0, -40, { ifs: { 'G0/0': '192.168.1.1/24', 'G0/1': '192.168.2.1/24' } });
      const sw1 = b.add('switch', -260, 60);
      const sw2 = b.add('switch', 260, 60);
      const pc1 = b.add('pc', -380, 220, { ip: '192.168.1.10/24', gw: '192.168.1.1' });
      const pc2 = b.add('pc', -160, 230, { ip: '192.168.1.11/24', gw: '192.168.1.1' });
      const pc3 = b.add('pc', 160, 230, { ip: '192.168.2.10/24', gw: '192.168.2.1' });
      const srv = b.add('server', 380, 220, { ip: '192.168.2.100/24', gw: '192.168.2.1' });
      b.link(r1, sw1, 'G0/0'); b.link(r1, sw2, 'G0/1');
      b.link(pc1, sw1); b.link(pc2, sw1); b.link(pc3, sw2); b.link(srv, sw2);
    },
  });

  function chain(b, withReturnRoute) {
    const r1 = b.add('router', -260, 0, {
      ifs: { 'G0/0': '10.1.0.1/24', 'G0/1': '10.0.12.1/30' },
      routes: [['10.3.0.0/24', '10.0.12.2'], ['10.0.23.0/30', '10.0.12.2']],
    });
    const r2 = b.add('router', 0, -70, {
      ifs: { 'G0/0': '10.0.12.2/30', 'G0/1': '10.0.23.1/30' },
      routes: [['10.1.0.0/24', '10.0.12.1'], ['10.3.0.0/24', '10.0.23.2']],
    });
    const r3 = b.add('router', 260, 0, {
      ifs: { 'G0/0': '10.0.23.2/30', 'G0/1': '10.3.0.1/24' },
      routes: withReturnRoute ? [['10.1.0.0/24', '10.0.23.1'], ['10.0.12.0/30', '10.0.23.1']] : [['10.0.12.0/30', '10.0.23.1']],
    });
    const a = b.add('pc', -420, 170, { name: 'PC-A', ip: '10.1.0.10/24', gw: '10.1.0.1' });
    const c = b.add('server', 420, 170, { name: 'SRV-C', ip: '10.3.0.10/24', gw: '10.3.0.1' });
    b.link(r1, r2, 'G0/1', 'G0/0'); b.link(r2, r3, 'G0/1', 'G0/0');
    b.link(a, r1, null, 'G0/0'); b.link(c, r3, null, 'G0/1');
  }

  LAB.registerPreset({
    id: 'static',
    title: 'Три маршрутизатора, статика',
    desc: 'Между R1, R2 и R3 сети /30, маршруты прописаны вручную. Запусти tracert с PC-A до 10.3.0.10: каждый маршрутизатор на пути вернёт ICMP «TTL истёк», так и строится список хопов.',
    try: ['PC-A', '10.3.0.10'],
    build(b) { chain(b, true); },
  });

  LAB.registerPreset({
    id: 'task-route',
    title: 'Задача: ответ не возвращается',
    task: true,
    desc: 'Пинг с PC-A до SRV-C не проходит, хотя запрос до сервера доходит. Посмотри журнал: где теряется ответ? Исправь таблицу маршрутизации нужного маршрутизатора (в панели справа или командой ip route в его консоли).',
    try: ['PC-A', '10.3.0.10'],
    build(b) { chain(b, false); },
  });

  LAB.registerPreset({
    id: 'vlan',
    title: 'VLAN на одном коммутаторе',
    desc: 'Все четыре ПК в одной подсети, но порты Fa0/1–2 в VLAN 10, а Fa0/3–4 в VLAN 20. PC1 пингует PC2, а PC3 уже нет: ARP-запрос не выходит за пределы своего VLAN. Включи вид L2 и посмотри на домены.',
    try: ['PC1', '192.168.10.13'],
    build(b) {
      const sw = b.add('switch', 0, 0, { vlans: { 'Fa0/1': 10, 'Fa0/2': 10, 'Fa0/3': 20, 'Fa0/4': 20 } });
      const pcs = [['PC1', -330, '.11'], ['PC2', -110, '.12'], ['PC3', 110, '.13'], ['PC4', 330, '.14']]
        .map(([name, x, h]) => b.add('pc', x, 190, { name, ip: '192.168.10' + h + '/24' }));
      pcs.forEach((p, i) => b.link(p, sw, null, 'Fa0/' + (i + 1)));
    },
  });

  LAB.registerPreset({
    id: 'hub',
    title: 'Концентратор и коммутатор',
    desc: 'Слева хаб: любой кадр получают все, а лишние узлы его отбрасывают. Справа коммутатор: после того как он выучил MAC-адреса, кадр уходит только в нужный порт. Пингани дважды с каждой стороны и сравни.',
    try: ['PC1', '192.168.5.3'],
    build(b) {
      const hub = b.add('hub', -260, 0);
      const sw = b.add('switch', 260, 0);
      [['PC1', -420, 1], ['PC2', -260, 2], ['PC3', -100, 3]].forEach(([name, x, n]) => b.link(b.add('pc', x, 180, { name, ip: '192.168.5.' + n + '/24' }), hub));
      [['PC4', 100, 4], ['PC5', 260, 5], ['PC6', 420, 6]].forEach(([name, x, n]) => b.link(b.add('pc', x, 180, { name, ip: '192.168.6.' + n + '/24' }), sw));
    },
  });

  LAB.registerPreset({
    id: 'loop',
    title: 'Петля без STP',
    desc: 'Коммутаторы соединены двумя кабелями. Первый же широковещательный ARP-запрос начнёт ходить по кругу и размножаться: это широковещательный шторм. В реальных сетях от него спасает STP, который выключает лишний канал.',
    try: ['PC1', '192.168.7.2'],
    build(b) {
      const s1 = b.add('switch', -180, 0);
      const s2 = b.add('switch', 180, 0);
      b.link(s1, s2); b.link(s1, s2);
      b.link(b.add('pc', -300, 170, { name: 'PC1', ip: '192.168.7.1/24' }), s1);
      b.link(b.add('pc', 300, 170, { name: 'PC2', ip: '192.168.7.2/24' }), s2);
    },
  });

  LAB.registerPreset({
    id: 'services',
    title: 'DHCP, DNS и веб-сервер',
    desc: 'У ПК слева нет адресов: они получат их по DHCP. Сервер DHCP и DNS стоит в другой сети, поэтому на R1 включён ip helper-address (DHCP relay). Сделай dhclient на PC1, затем открой в его браузере http://site.lab: сначала DNS, потом TCP-рукопожатие и HTTP.',
    try: ['PC1', '', 'dhcp'],
    build(b) {
      const r1 = b.add('router', 0, 0, { ifs: { 'G0/0': '192.168.10.1/24', 'G0/1': '10.0.0.1/24' }, helpers: { 'G0/0': '10.0.0.10' } });
      const sw1 = b.add('switch', -260, 90);
      const sw2 = b.add('switch', 260, 90);
      const pc1 = b.add('pc', -380, 250, { dhcp: true });
      const pc2 = b.add('pc', -160, 260, { dhcp: true });
      const srv = b.add('server', 160, 260, {
        name: 'NS1', ip: '10.0.0.10/24', gw: '10.0.0.1', dns: '10.0.0.10',
        services: {
          dns: { on: true, records: [{ name: 'site.lab', ip: '10.0.0.20' }, { name: 'ns1.lab', ip: '10.0.0.10' }] },
          dhcp: { on: true, pool: { start: '192.168.10.100', end: '192.168.10.150', prefix: 24, gateway: '192.168.10.1', dns: '10.0.0.10' }, reservations: [] },
        },
      });
      const web = b.add('server', 380, 250, {
        name: 'WEB1', ip: '10.0.0.20/24', gw: '10.0.0.1', dns: '10.0.0.10',
        services: { http: { on: true, title: 'site.lab', body: 'Привет! Эту страницу отдал nginx на WEB1. Чтобы её получить, браузер спросил DNS, открыл TCP-соединение и отправил HTTP GET.' } },
      });
      b.link(r1, sw1, 'G0/0'); b.link(r1, sw2, 'G0/1');
      b.link(pc1, sw1); b.link(pc2, sw1); b.link(srv, sw2); b.link(web, sw2);
    },
  });

  // Построить сеть по пресету
  LAB.buildPreset = function (preset) {
    const net = new LAB.Net();
    const b = {
      add(type, x, y, o) {
        o = o || {};
        const d = net.add(type, x, y);
        if (o.name) d.name = o.name;
        if (o.ip) { const c = LAB.ip.parseCidr(o.ip); d.ifaces[0].ip = c.ip; d.ifaces[0].prefix = c.prefix; }
        if (o.gw) d.gateway = o.gw;
        if (o.dns) d.dns = o.dns;
        if (o.dhcp) d.dhcp = true;
        Object.entries(o.helpers || {}).forEach(([port, h]) => { const itf = d.ifaces.find((i) => i.name === port); if (itf) itf.helper = h; });
        Object.entries(o.services || {}).forEach(([k, v]) => Object.assign(d.services[k], v));
        Object.entries(o.ifs || {}).forEach(([port, cidr]) => {
          const itf = d.ifaces.find((i) => i.name === port);
          const c = LAB.ip.parseCidr(cidr);
          if (itf && c) { itf.ip = c.ip; itf.prefix = c.prefix; }
        });
        (o.routes || []).forEach(([cidr, via]) => {
          const c = LAB.ip.parseCidr(cidr);
          if (c) d.routes.push({ net: c.ip, prefix: c.prefix, via });
        });
        Object.entries(o.vlans || {}).forEach(([port, v]) => {
          const itf = d.ifaces.find((i) => i.name === port);
          if (itf) itf.vlan = v;
        });
        return d;
      },
      link(a, c, pa, pc) { net.connect(a.id, c.id, pa, pc); },
    };
    preset.build(b);
    return net;
  };
})();
