// Схемы-примеры: готовая сеть, на которой можно посмотреть, как всё работает. Задачи живут в tasks.js.
// Новая схема: LAB.registerPreset({ id, title, desc, try?: [имя, цель, операция?], build(b) }).
// операция: ping (по умолчанию), tracert, dhcp, dns, http
//
// b.add(type, x, y, {
//   name, ip: 'a.b.c.d/nn', gw, dns, dhcp: true,
//   ifs: { порт: 'a.b.c.d/nn' }, helpers: { порт: ip }, down: [порт],
//   routes: [['сеть/nn', 'через']],
//   vlans: { порт: n }, trunks: { порт: '' | '10,20' }, natives: { порт: n }, stp: false | приоритет,
//   subs: { порт: [[vlan, 'a.b.c.d/nn', helper?]] },
//   nat: { порт или подынтерфейс: 'inside' | 'outside' }, natStatic: [['tcp', 80, 'внутр. ip', 80]],
//   ospf: true | [порты], ospfDef: true,
//   acl: { порт: { in: ['deny icmp 10.0.0.0/24 any', ...], out: [...] } },
//   services: { dns, dhcp: { on, pools: [...] }, http } })
// b.link(a, b, портA?, портB?) — без портов берутся первые свободные.
(function () {
  const ip = LAB.ip;

  // «deny icmp 192.168.20.0/24 any», «permit tcp any 10.0.0.20/32 eq 80»
  LAB.parseRule = function (str) {
    const t = String(str).trim().split(/\s+/);
    const r = { action: t[0], proto: t[1], src: t[2] || 'any', dst: t[3] || 'any', port: 0 };
    if (t[4] === 'eq') r.port = Number(t[5]) || 0;
    return LAB.normRule(r);
  };

  LAB.registerPreset({
    id: 'lan',
    title: 'Одна сеть на коммутаторе',
    desc: 'Три узла в сети 192.168.1.0/24. Пингани сервер с PC1: сначала ARP ищет MAC, коммутатор запоминает порты, затем идёт ICMP. Пингани ещё раз: ARP уже не нужен.',
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

  LAB.chain3 = function (b, opts) {
    opts = opts || {};
    const r1 = b.add('router', -260, 0, { ifs: { 'G0/0': '10.1.0.1/24', 'G0/1': '10.0.12.1/30' }, routes: opts.r1 || [] });
    const r2 = b.add('router', 0, -70, { ifs: { 'G0/0': '10.0.12.2/30', 'G0/1': '10.0.23.1/30' }, routes: opts.r2 || [] });
    const r3 = b.add('router', 260, 0, { ifs: { 'G0/0': '10.0.23.2/30', 'G0/1': '10.3.0.1/24' }, routes: opts.r3 || [] });
    const a = b.add('pc', -420, 170, { name: 'PC-A', ip: '10.1.0.10/24', gw: '10.1.0.1' });
    const c = b.add('server', 420, 170, { name: 'SRV-C', ip: '10.3.0.10/24', gw: '10.3.0.1' });
    b.link(r1, r2, 'G0/1', 'G0/0'); b.link(r2, r3, 'G0/1', 'G0/0');
    b.link(a, r1, null, 'G0/0'); b.link(c, r3, null, 'G0/1');
    return { r1, r2, r3, a, c };
  };

  LAB.registerPreset({
    id: 'static',
    title: 'Три маршрутизатора, статика',
    desc: 'Между R1, R2 и R3 сети /30, маршруты прописаны вручную. Запусти traceroute с PC-A до 10.3.0.10: каждый маршрутизатор на пути вернёт ICMP «TTL истёк», так и строится список хопов.',
    try: ['PC-A', '10.3.0.10', 'tracert'],
    build(b) {
      LAB.chain3(b, {
        r1: [['10.3.0.0/24', '10.0.12.2'], ['10.0.23.0/30', '10.0.12.2']],
        r2: [['10.1.0.0/24', '10.0.12.1'], ['10.3.0.0/24', '10.0.23.2']],
        r3: [['10.1.0.0/24', '10.0.23.1'], ['10.0.12.0/30', '10.0.23.1']],
      });
    },
  });

  LAB.registerPreset({
    id: 'vlan',
    title: 'VLAN на одном коммутаторе',
    desc: 'Все четыре ПК в одной подсети, но порты Fa0/1–2 в VLAN 10, а Fa0/3–4 в VLAN 20. PC1 пингует PC2, а PC3 уже нет: ARP-запрос не выходит за пределы своего VLAN. Включи вид L2 и посмотри на домены.',
    try: ['PC1', '192.168.10.13'],
    build(b) {
      const sw = b.add('switch', 0, 0, { vlans: { 'Fa0/1': 10, 'Fa0/2': 10, 'Fa0/3': 20, 'Fa0/4': 20 } });
      const pcs = [['PC1', -330, '.11'], ['PC2', -110, '.12'], ['PC3', 110, '.13'], ['PC4', 330, '.14']]
        .map(([name, x, hh]) => b.add('pc', x, 190, { name, ip: '192.168.10' + hh + '/24' }));
      pcs.forEach((p, i) => b.link(p, sw, null, 'Fa0/' + (i + 1)));
    },
  });

  LAB.registerPreset({
    id: 'trunk',
    title: 'Транк и маршрутизатор на палочке',
    desc: 'VLAN 10 и 20 живут на двух коммутаторах, между ними транк 802.1Q: кадры несут тег с номером VLAN. R1 подключён одним кабелем, на нём подынтерфейсы G0/0.10 и G0/0.20 — шлюзы обоих VLAN. Пингани с PC1 (VLAN 10) PC4 (VLAN 20) и посмотри теги в журнале.',
    try: ['PC1', '192.168.20.11'],
    build(b) {
      const r1 = b.add('router', 0, -150, { subs: { 'G0/0': [[10, '192.168.10.1/24'], [20, '192.168.20.1/24']] } });
      const s1 = b.add('switch', -200, 0, { trunks: { 'Fa0/1': '', 'Fa0/2': '' }, vlans: { 'Fa0/3': 10, 'Fa0/4': 20 } });
      const s2 = b.add('switch', 200, 0, { trunks: { 'Fa0/1': '' }, vlans: { 'Fa0/3': 10, 'Fa0/4': 20 } });
      b.link(r1, s1, 'G0/0', 'Fa0/1');
      b.link(s1, s2, 'Fa0/2', 'Fa0/1');
      b.link(b.add('pc', -320, 170, { name: 'PC1', ip: '192.168.10.10/24', gw: '192.168.10.1' }), s1, null, 'Fa0/3');
      b.link(b.add('pc', -100, 190, { name: 'PC2', ip: '192.168.20.10/24', gw: '192.168.20.1' }), s1, null, 'Fa0/4');
      b.link(b.add('pc', 100, 190, { name: 'PC3', ip: '192.168.10.11/24', gw: '192.168.10.1' }), s2, null, 'Fa0/3');
      b.link(b.add('pc', 320, 170, { name: 'PC4', ip: '192.168.20.11/24', gw: '192.168.20.1' }), s2, null, 'Fa0/4');
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
    desc: 'Коммутаторы соединены двумя кабелями, STP выключен. Первый же широковещательный ARP-запрос начнёт ходить по кругу и размножаться: это широковещательный шторм. Включи STP на обоих коммутаторах (вкладка STP) и повтори.',
    try: ['PC1', '192.168.7.2'],
    build(b) {
      const s1 = b.add('switch', -180, 0, { stp: false });
      const s2 = b.add('switch', 180, 0, { stp: false });
      b.link(s1, s2); b.link(s1, s2);
      b.link(b.add('pc', -300, 170, { name: 'PC1', ip: '192.168.7.1/24' }), s1);
      b.link(b.add('pc', 300, 170, { name: 'PC2', ip: '192.168.7.2/24' }), s2);
    },
  });

  LAB.registerPreset({
    id: 'stp',
    title: 'Кольцо коммутаторов и STP',
    desc: 'Три коммутатора в кольце. STP выбрал корень (SW1, у него приоритет меньше) и заблокировал один порт, чтобы не было петли: на виде L2 этот кабель пунктирный. Выключи кабель между SW1 и SW2: STP разблокирует запасной путь.',
    try: ['PC1', '192.168.8.2'],
    build(b) {
      const s1 = b.add('switch', 0, -120, { stp: 4096 });
      const s2 = b.add('switch', -220, 60);
      const s3 = b.add('switch', 220, 60);
      b.link(s1, s2); b.link(s1, s3); b.link(s2, s3);
      b.link(b.add('pc', -340, 220, { name: 'PC1', ip: '192.168.8.1/24' }), s2);
      b.link(b.add('pc', 340, 220, { name: 'PC2', ip: '192.168.8.2/24' }), s3);
    },
  });

  LAB.servicesNet = function (b, o) {
    o = o || {};
    const r1 = b.add('router', 0, 0, { ifs: { 'G0/0': '192.168.10.1/24', 'G0/1': '10.0.0.1/24' }, helpers: o.relay === false ? {} : { 'G0/0': '10.0.0.10' } });
    const sw1 = b.add('switch', -260, 90);
    const sw2 = b.add('switch', 260, 90);
    const pc1 = b.add('pc', -380, 250, { dhcp: true });
    const pc2 = b.add('pc', -160, 260, { dhcp: true });
    const srv = b.add('server', 160, 260, {
      name: 'NS1', ip: '10.0.0.10/24', gw: '10.0.0.1', dns: '10.0.0.10',
      services: o.services || {
        dns: { on: true, records: [{ name: 'site.lab', ip: '10.0.0.20' }, { name: 'ns1.lab', ip: '10.0.0.10' }] },
        dhcp: { on: true, pools: [{ start: '192.168.10.100', end: '192.168.10.150', prefix: 24, gateway: '192.168.10.1', dns: '10.0.0.10' }] },
      },
    });
    const web = b.add('server', 380, 250, {
      name: 'WEB1', ip: '10.0.0.20/24', gw: '10.0.0.1', dns: '10.0.0.10',
      services: { http: { on: true, title: 'site.lab', body: 'Привет! Эту страницу отдал nginx на WEB1. Чтобы её получить, браузер спросил DNS, открыл TCP-соединение и отправил HTTP GET.' } },
    });
    b.link(r1, sw1, 'G0/0'); b.link(r1, sw2, 'G0/1');
    b.link(pc1, sw1); b.link(pc2, sw1); b.link(srv, sw2); b.link(web, sw2);
    return { r1, pc1, pc2, srv, web };
  };

  LAB.registerPreset({
    id: 'services',
    title: 'DHCP, DNS и веб-сервер',
    desc: 'У ПК слева нет адресов: они получат их по DHCP. Сервер DHCP и DNS стоит в другой сети, поэтому на R1 включён ip helper-address (DHCP relay). Получи адрес на PC1, затем открой в его браузере http://site.lab: сначала DNS, потом TCP-рукопожатие и HTTP.',
    try: ['PC1', '', 'dhcp'],
    build(b) { LAB.servicesNet(b); },
  });

  // Офис за NAT и «интернет»: провайдер ISP и публичный сервер 203.0.113.0/24 не знают частных сетей
  LAB.natNet = function (b, o) {
    o = o || {};
    const r1 = b.add('router', -120, 0, Object.assign({ name: 'R1', ifs: { 'G0/0': '192.168.1.1/24', 'G0/1': '198.51.100.2/30' } }, o.r1 || {}));
    const isp = b.add('router', 180, 0, { name: 'ISP', ifs: { 'G0/0': '198.51.100.1/30', 'G0/1': '203.0.113.1/24' } });
    const sw = b.add('switch', -320, 100);
    const pc1 = b.add('pc', -460, 250, { name: 'PC1', ip: '192.168.1.10/24', gw: '192.168.1.1', dns: '203.0.113.53' });
    const pc2 = b.add('pc', -300, 270, { name: 'PC2', ip: '192.168.1.11/24', gw: '192.168.1.1', dns: '203.0.113.53' });
    const web = b.add('server', -140, 250, Object.assign({ name: 'OFFICE-WEB', ip: '192.168.1.80/24', gw: '192.168.1.1',
      services: { http: { on: true, title: 'Сайт офиса', body: 'Этот сервер стоит в частной сети офиса.' } } }, o.web || {}));
    const swi = b.add('switch', 380, 100, { name: 'SW-NET' });
    const pub = b.add('server', 300, 260, { name: 'INTERNET', ip: '203.0.113.53/24', gw: '203.0.113.1',
      services: { dns: { on: true, records: [{ name: 'example.lab', ip: '203.0.113.53' }] }, http: { on: true, title: 'example.lab', body: 'Страница из «интернета».' } } });
    const ext = b.add('pc', 470, 260, { name: 'GUEST', ip: '203.0.113.77/24', gw: '203.0.113.1' });
    b.link(r1, sw, 'G0/0'); b.link(r1, isp, 'G0/1', 'G0/0'); b.link(isp, swi, 'G0/1');
    b.link(pc1, sw); b.link(pc2, sw); b.link(web, sw); b.link(pub, swi); b.link(ext, swi);
    return { r1, isp, pc1, pc2, web, pub, ext };
  };

  LAB.registerPreset({
    id: 'nat',
    title: 'NAT: офис выходит в интернет',
    desc: 'У офиса частные адреса 192.168.1.0/24, провайдер ISP их не знает. На R1 включён PAT: G0/0 inside, G0/1 outside, пакеты уходят с адресом 198.51.100.2 и своим портом. Порт 80 проброшен на OFFICE-WEB. Пингани example.lab с PC1 и открой http://198.51.100.2 с GUEST.',
    try: ['PC1', 'example.lab'],
    build(b) {
      LAB.natNet(b, { r1: { nat: { 'G0/0': 'inside', 'G0/1': 'outside' }, routes: [['0.0.0.0/0', '198.51.100.1']], natStatic: [['tcp', 80, '192.168.1.80', 80]] } });
    },
  });

  LAB.ring4 = function (b, o) {
    o = o || {};
    const pos = [[-220, -120], [220, -120], [220, 120], [-220, 120]];
    const rs = pos.map(([x, y], k) => b.add('router', x, y, Object.assign({
      name: 'R' + (k + 1),
      ifs: { 'G0/0': '10.' + (k + 1) + '.0.1/24', 'G0/1': '10.0.' + (k + 1) + (k === 3 ? 1 : k + 2) + '.1/30', 'G0/2': '10.0.' + (k === 0 ? 4 : k) + (k + 1) + '.2/30' },
    }, (o.r || [])[k] || {})));
    rs.forEach((r, k) => b.link(r, rs[(k + 1) % 4], 'G0/1', 'G0/2'));
    const hosts = rs.map((r, k) => {
      const [x, y] = pos[k];
      const h = b.add(k % 2 ? 'server' : 'pc', x + (x < 0 ? -170 : 170), y + (y < 0 ? -60 : 60), { name: (k % 2 ? 'SRV' : 'PC') + (k + 1), ip: '10.' + (k + 1) + '.0.10/24', gw: '10.' + (k + 1) + '.0.1' });
      b.link(h, r, null, 'G0/0');
      return h;
    });
    return { rs, hosts };
  };

  LAB.registerPreset({
    id: 'ospf',
    title: 'OSPF в кольце',
    desc: 'Четыре маршрутизатора в кольце, статических маршрутов нет: их строит OSPF. Каждый объявляет свои сети, соседи обмениваются ими и считают кратчайший путь. Выключи один из кабелей кольца: OSPF сам найдёт обход.',
    try: ['PC1', '10.3.0.10', 'tracert'],
    build(b) { LAB.ring4(b, { r: [0, 1, 2, 3].map(() => ({ ospf: true })) }); },
  });

  LAB.registerPreset({
    id: 'acl',
    title: 'ACL: гостям нельзя к серверу',
    desc: 'Слева сеть сотрудников, справа гостевая, внизу сервер. На R1 на входе G0/1 (гости) стоит ACL: запрет к сети серверов, всё остальное разрешено. Пингани сервер с STAFF и с GUEST и посмотри, где пакет остановился.',
    try: ['GUEST', '10.0.0.10'],
    build(b) {
      const r1 = b.add('router', 0, -80, {
        ifs: { 'G0/0': '192.168.1.1/24', 'G0/1': '192.168.50.1/24', 'G0/2': '10.0.0.1/24' },
        acl: { 'G0/1': { in: ['deny ip 192.168.50.0/24 10.0.0.0/24', 'permit ip any any'] } },
      });
      b.link(b.add('pc', -260, 80, { name: 'STAFF', ip: '192.168.1.10/24', gw: '192.168.1.1' }), r1, null, 'G0/0');
      b.link(b.add('pc', 260, 80, { name: 'GUEST', ip: '192.168.50.10/24', gw: '192.168.50.1' }), r1, null, 'G0/1');
      b.link(b.add('server', 0, 160, { name: 'SRV', ip: '10.0.0.10/24', gw: '10.0.0.1', services: { http: { on: true, title: 'Внутренний портал', body: 'Только для сотрудников.' } } }), r1, null, 'G0/2');
    },
  });

  /* ===== Построение сети по описанию ===== */
  function lifByName(d, name) { return LAB.lifs(d).find((l) => l.name.toLowerCase() === String(name).toLowerCase()) || null; }

  LAB.builder = function (net) {
    return {
      net,
      add(type, x, y, o) {
        o = o || {};
        const d = net.add(type, x, y);
        if (o.name) d.name = o.name;
        if (o.ip) { const c = ip.parseCidr(o.ip); d.ifaces[0].ip = c.ip; d.ifaces[0].prefix = c.prefix; }
        if (o.gw) d.gateway = o.gw;
        if (o.dns) d.dns = o.dns;
        if (o.dhcp) d.dhcp = true;
        Object.entries(o.ifs || {}).forEach(([port, cidr]) => {
          const itf = d.ifaces.find((i) => i.name === port);
          const c = ip.parseCidr(cidr);
          if (itf && c) { itf.ip = c.ip; itf.prefix = c.prefix; }
        });
        Object.entries(o.subs || {}).forEach(([port, list]) => {
          const itf = d.ifaces.find((i) => i.name === port);
          if (!itf) return;
          list.forEach(([vlan, cidr, helper]) => {
            const c = ip.parseCidr(cidr);
            itf.subs.push(LAB.normSub({ vlan, ip: c ? c.ip : '', prefix: c ? c.prefix : 24, helper: helper || '' }));
          });
        });
        Object.entries(o.helpers || {}).forEach(([port, hh]) => { const l = lifByName(d, port); if (l) l.cfg.helper = hh; });
        Object.entries(o.nat || {}).forEach(([port, role]) => { const l = lifByName(d, port); if (l) l.cfg.nat = role; });
        if (o.ospf) LAB.lifs(d).forEach((l) => { if (o.ospf === true ? l.cfg.ip : o.ospf.includes(l.name)) l.cfg.ospf = true; });
        if (o.ospfDef) d.ospfDef = true;
        Object.entries(o.acl || {}).forEach(([port, a]) => {
          const l = lifByName(d, port);
          if (!l) return;
          l.cfg.aclIn = (a.in || []).map(LAB.parseRule);
          l.cfg.aclOut = (a.out || []).map(LAB.parseRule);
        });
        (o.natStatic || []).forEach(([proto, port, addr, lport]) => d.natStatic.push({ proto, port, ip: addr, lport: lport || port }));
        (o.down || []).forEach((port) => { const l = lifByName(d, port); if (l) l.cfg.down = true; });
        (o.routes || []).forEach(([cidr, via]) => {
          const c = ip.parseCidr(cidr);
          if (c) d.routes.push({ net: ip.str(ip.net(ip.parse(c.ip), c.prefix)), prefix: c.prefix, via });
        });
        Object.entries(o.vlans || {}).forEach(([port, v]) => { const itf = d.ifaces.find((i) => i.name === port); if (itf) itf.vlan = v; });
        Object.entries(o.trunks || {}).forEach(([port, allowed]) => { const itf = d.ifaces.find((i) => i.name === port); if (itf) { itf.mode = 'trunk'; itf.allowed = allowed || ''; } });
        Object.entries(o.natives || {}).forEach(([port, v]) => { const itf = d.ifaces.find((i) => i.name === port); if (itf) itf.native = v; });
        if (o.stp === false && d.stp) d.stp.on = false;
        if (typeof o.stp === 'number' && d.stp) d.stp.priority = o.stp;
        Object.entries(o.services || {}).forEach(([k, v]) => {
          if (k === 'dhcp') {
            const pools = v.pools || (v.pool ? [v.pool] : null);
            Object.assign(d.services.dhcp, v);
            delete d.services.dhcp.pool;
            if (pools) d.services.dhcp.pools = pools.map((p) => Object.assign(LAB.defaultPool(), p));
            if (!d.services.dhcp.reservations) d.services.dhcp.reservations = [];
          } else Object.assign(d.services[k], v);
        });
        return d;
      },
      link(a, c, pa, pc) { net.connect(a.id, c.id, pa, pc); },
    };
  };

  LAB.buildPreset = function (preset) {
    const net = new LAB.Net();
    preset.build(LAB.builder(net));
    return net;
  };
})();
