// Учебные задачи: от кабеля до филиала с VLAN, NAT, OSPF и ACL.
// theory: id темы из theory.js, она открывается по кнопке «Что нужно знать».
// Новая задача: LAB.registerTask({ id, level, title, goal, theory, hints: [], solution, build(b), checks: [{ text, test(c) }] }).
// test возвращает true, если условие выполнено, или строку с причиной, почему нет.
// Проверка идёт на копии сети: настройки на холсте она не трогает. Каждая операция (ping, DHCP, HTTP…)
// записывается в runs, чтобы после проверки проиграть её на холсте.
(function () {
  const ip = LAB.ip;

  LAB.taskLevels = [
    { n: 1, title: 'Кабели и адреса', desc: 'Как узлы в одной сети находят друг друга: Ethernet, ARP, IP-адрес и маска.' },
    { n: 2, title: 'Маршрутизация', desc: 'Шлюз по умолчанию, интерфейсы маршрутизатора, статические маршруты и маршрут по умолчанию.' },
    { n: 3, title: 'Подсети', desc: 'Делим адресное пространство: маски, /25, /26, /30 и VLSM.' },
    { n: 4, title: 'Службы', desc: 'DHCP, DHCP relay, резервирование, DNS и свой сайт.' },
    { n: 5, title: 'VLAN и коммутация', desc: 'VLAN, транки 802.1Q, маршрутизатор на палочке и STP.' },
    { n: 6, title: 'Интернет и безопасность', desc: 'NAT/PAT, проброс портов и списки доступа (ACL).' },
    { n: 7, title: 'Динамическая маршрутизация', desc: 'OSPF: соседи, обмен сетями, обход аварий, маршрут по умолчанию.' },
    { n: 8, title: 'Итоговая работа', desc: 'Всё вместе: филиал с нуля.' },
  ];

  /* ===== Проверки ===== */
  function makeCtx(net) {
    const c = LAB.Net.from(JSON.parse(JSON.stringify(net)));
    const sim = new LAB.Sim(c);
    const dev = (name) => {
      const d = c.byName(name);
      if (!d) throw new Error('нет устройства ' + name + ' (его переименовали или удалили?)');
      return d;
    };
    const why = (r) => {
      if (r.storm) return 'в сети широковещательный шторм';
      const x = r.results[0];
      if (!x) return 'ответ не вернулся';
      if (x.type === 'reply') return true;
      if (x.type === 'local-err') return x.text;
      if (x.type === 'arp-fail') return 'на ARP-запрос никто не ответил';
      if (x.type === 'unreach') return x.admin ? x.from + ' запретил это по ACL' : x.from + ' сообщил: сеть недоступна';
      if (x.type === 'dns-fail') return 'DNS: ' + x.reason;
      if (x.type === 'ttl') return 'TTL истёк в пути (петля маршрутизации?)';
      return 'нет ответа';
    };
    const runs = [];
    let note = '';
    const rec = (title, r) => { runs.push({ check: ctx.cur, title: title + note, r }); return r; };
    const ctx = {
      net: c, sim, dev, runs, cur: 0,
      ping(a, b) { const r = why(rec('ping ' + a + ' -> ' + b, sim.ping(dev(a).id, b))); return r === true ? true : a + ' -> ' + b + ': ' + r; },
      noPing(a, b) { return why(rec('ping ' + a + ' -> ' + b + ' (должен не пройти)', sim.ping(dev(a).id, b))) === true ? a + ' всё ещё пингует ' + b : true; },
      tracert(a, b) { return rec('traceroute ' + a + ' -> ' + b, sim.tracert(dev(a).id, b)); },
      // DHCP как подготовка к следующей проверке: не показываем
      prep(a) { sim.dhcp(dev(a).id); },
      http(a, url, title) {
        const r = rec('HTTP ' + a + ' -> ' + url, sim.http(dev(a).id, url));
        const x = r.results.find((y) => y.type === 'http');
        if (x) {
          if (x.status !== 200) return url + ': HTTP ' + x.status;
          if (title && !String(x.title).toLowerCase().includes(title.toLowerCase())) return url + ': открылась страница «' + x.title + '», а нужна «' + title + '»';
          return true;
        }
        if (r.results.some((y) => y.type === 'http-refused')) return url + ': соединение отклонено, веб-сервер не запущен';
        const w = why(r);
        return a + ' не открыл ' + url + ': ' + (w === true ? 'нет ответа' : w);
      },
      noHttp(a, url) { const r = rec('HTTP ' + a + ' -> ' + url + ' (должен не открыться)', sim.http(dev(a).id, url)); return r.results.some((y) => y.type === 'http') ? a + ' всё ещё открывает ' + url : true; },
      dhcp(a, cidr) {
        const r = rec('DHCP на ' + a, sim.dhcp(dev(a).id));
        const x = r.results.find((y) => y.type === 'dhcp');
        if (!x) { const e = r.results.find((y) => y.type === 'local-err'); return a + ' не получил адрес по DHCP' + (e ? ': ' + e.text : ''); }
        if (cidr && !ip.inCidr(x.ip, cidr)) return a + ' получил ' + x.ip + ', а нужен адрес из ' + cidr;
        ctx.last = x;
        return true;
      },
      dns(a, name, want) {
        const r = rec('DNS ' + a + ': ' + name, sim.dnsLookup(dev(a).id, name));
        const x = r.results.find((y) => y.type === 'dns');
        if (!x) { const w = why(r); return a + ' не узнал адрес ' + name + ': ' + (w === true ? 'нет ответа' : w); }
        if (want && x.ip !== want) return name + ' = ' + x.ip + ', а должно быть ' + want;
        return true;
      },
      linked(a, b) {
        const A = dev(a), B = dev(b);
        return c.links.some((l) => (l.a.dev === A.id && l.b.dev === B.id) || (l.a.dev === B.id && l.b.dev === A.id)) ? true : a + ' и ' + b + ' не соединены кабелем';
      },
      noStorm(a, b) { return rec('ping ' + a + ' -> ' + b + ' (нет ли шторма)', sim.ping(dev(a).id, b)).storm ? 'в сети широковещательный шторм' : true; },
      lif(a, name) {
        const l = LAB.lifs(dev(a)).find((x) => x.name.toLowerCase() === name.toLowerCase());
        if (!l) throw new Error('у ' + a + ' нет интерфейса ' + name);
        return l.cfg;
      },
      // Временно выключить порт и проверить: после проверки всё возвращается
      withDown(a, port, fn) {
        const itf = dev(a).ifaces.find((i) => i.name === port);
        const was = itf.down;
        itf.down = true;
        sim.reset();
        note = ', кабель ' + a + ' ' + port + ' выключен';
        try { return fn(); } finally { itf.down = was; note = ''; sim.reset(); }
      },
      all(list) { for (const r of list) if (r !== true) return r; return true; },
      ip: ip,
    };
    return ctx;
  }

  LAB.checkTask = function (task, net) {
    const c = makeCtx(net);
    const out = task.checks.map((ch, k) => {
      c.cur = k;
      try {
        const r = ch.test(c);
        return { text: ch.text, ok: r === true, why: r === true ? '' : typeof r === 'string' ? r : 'не выполнено' };
      } catch (e) {
        return { text: ch.text, ok: false, why: e.message };
      }
    });
    out.runs = c.runs;
    return out;
  };

  LAB.buildTask = function (task) {
    const net = new LAB.Net();
    task.build(LAB.builder(net));
    return net;
  };

  const T = LAB.registerTask;
  const inNet = (addr, cidr) => !!addr && ip.inCidr(addr, cidr);
  const netOf = (cfg) => (cfg.ip ? ip.str(ip.net(ip.parse(cfg.ip), cfg.prefix)) + '/' + cfg.prefix : '');

  /* ===== 1. Кабели и адреса ===== */
  T({
    id: 'cable', level: 1, title: 'Первый кабель',
    goal: 'Адреса у PC1 и PC2 уже настроены, но кабелей нет. Подключи оба компьютера к коммутатору SW1 и проверь связь ping-ом.',
    theory: 'ethernet',
    hints: ['Наведи на PC1, потяни точку над ним к SW1. Повтори для PC2.', 'Проверить связь: двойной щелчок по PC1, вкладка «Диагностика», ping 192.168.1.11.'],
    solution: 'Соединить PC1–SW1 и PC2–SW1.',
    build(b) {
      b.add('switch', 0, -40, { name: 'SW1' });
      b.add('pc', -220, 140, { name: 'PC1', ip: '192.168.1.10/24' });
      b.add('pc', 220, 140, { name: 'PC2', ip: '192.168.1.11/24' });
    },
    checks: [
      { text: 'PC1 подключён к SW1', test: (c) => c.linked('PC1', 'SW1') },
      { text: 'PC2 подключён к SW1', test: (c) => c.linked('PC2', 'SW1') },
      { text: 'PC1 пингует PC2', test: (c) => c.ping('PC1', '192.168.1.11') },
    ],
  });

  T({
    id: 'addr', level: 1, title: 'Адреса вручную',
    goal: 'Три компьютера подключены к коммутатору, но адресов у них нет. Дай им адреса из сети 192.168.1.0/24 так, чтобы все пинговали друг друга.',
    theory: 'ipv4',
    hints: ['Двойной щелчок по ПК, вкладка «Сеть», режим «Вручную».', 'Например: 192.168.1.10, .11, .12, маска /24. Шлюз пока не нужен: все в одной сети.'],
    solution: 'PC1 192.168.1.10/24, PC2 192.168.1.11/24, PC3 192.168.1.12/24.',
    build(b) {
      const sw = b.add('switch', 0, -40, { name: 'SW1' });
      ['PC1', 'PC2', 'PC3'].forEach((n, k) => b.link(b.add('pc', -260 + k * 260, 150, { name: n }), sw));
    },
    checks: [
      { text: 'У всех адрес из 192.168.1.0/24 с маской /24', test: (c) => c.all(['PC1', 'PC2', 'PC3'].map((n) => { const x = c.dev(n).ifaces[0]; return inNet(x.ip, '192.168.1.0/24') && x.prefix === 24 ? true : n + ': ' + (x.ip ? x.ip + '/' + x.prefix : 'нет адреса'); })) },
      { text: 'Адреса не повторяются', test: (c) => { const a = ['PC1', 'PC2', 'PC3'].map((n) => c.dev(n).ifaces[0].ip); return new Set(a).size === 3 ? true : 'два узла с одинаковым адресом'; } },
      { text: 'PC1 пингует PC3, PC3 пингует PC2', test: (c) => c.all([c.ping('PC1', c.dev('PC3').ifaces[0].ip), c.ping('PC3', c.dev('PC2').ifaces[0].ip)]) },
    ],
  });

  T({
    id: 'mask', level: 1, title: 'Чужая маска',
    goal: 'PC1 и PC2 в одной сети на одном коммутаторе, но связи нет. Найди ошибку в настройках и исправь её, не меняя сами адреса.',
    theory: 'mask',
    hints: ['Сравни маски PC1 и PC2.', 'С маской /25 адрес 192.168.1.200 в сети 192.168.1.128/25, а 192.168.1.10 туда не попадает.'],
    solution: 'Поставить PC2 маску /24.',
    build(b) {
      const sw = b.add('switch', 0, -40, { name: 'SW1' });
      b.link(b.add('pc', -220, 140, { name: 'PC1', ip: '192.168.1.10/24' }), sw);
      b.link(b.add('pc', 220, 140, { name: 'PC2', ip: '192.168.1.200/25' }), sw);
    },
    checks: [
      { text: 'Адреса не менялись', test: (c) => c.dev('PC1').ifaces[0].ip === '192.168.1.10' && c.dev('PC2').ifaces[0].ip === '192.168.1.200' ? true : 'адреса должны остаться прежними' },
      { text: 'PC1 пингует PC2', test: (c) => c.ping('PC1', '192.168.1.200') },
      { text: 'PC2 пингует PC1', test: (c) => c.ping('PC2', '192.168.1.10') },
    ],
  });

  /* ===== 2. Маршрутизация ===== */
  function twoNets(b, o) {
    o = o || {};
    const r1 = b.add('router', 0, -60, { name: 'R1', ifs: o.noRouterIp ? {} : { 'G0/0': '192.168.1.1/24', 'G0/1': '192.168.2.1/24' } });
    const sw1 = b.add('switch', -240, 60, { name: 'SW1' });
    const sw2 = b.add('switch', 240, 60, { name: 'SW2' });
    const gw = (g) => (o.noGw ? undefined : g);
    b.link(r1, sw1, 'G0/0'); b.link(r1, sw2, 'G0/1');
    b.link(b.add('pc', -360, 220, { name: 'PC1', ip: '192.168.1.10/24', gw: gw('192.168.1.1') }), sw1);
    b.link(b.add('pc', -140, 230, { name: 'PC2', ip: '192.168.1.11/24', gw: gw('192.168.1.1') }), sw1);
    b.link(b.add('server', 240, 230, { name: 'SRV', ip: '192.168.2.100/24', gw: gw('192.168.2.1') }), sw2);
  }

  T({
    id: 'gateway', level: 2, title: 'Шлюз по умолчанию',
    goal: 'Две сети соединены маршрутизатором R1, но компьютеры не достают до сервера в соседней сети. Настрой так, чтобы PC1 и PC2 пинговали SRV.',
    theory: 'gateway',
    hints: ['Адреса R1: 192.168.1.1 в левой сети и 192.168.2.1 в правой.', 'Шлюз задаётся во вкладке «Сеть» у каждого узла, включая сервер.'],
    solution: 'Шлюз 192.168.1.1 на PC1 и PC2, 192.168.2.1 на SRV.',
    build(b) { twoNets(b, { noGw: true }); },
    checks: [
      { text: 'PC1 пингует SRV', test: (c) => c.ping('PC1', '192.168.2.100') },
      { text: 'PC2 пингует SRV', test: (c) => c.ping('PC2', '192.168.2.100') },
      { text: 'SRV пингует PC1', test: (c) => c.ping('SRV', '192.168.1.10') },
    ],
  });

  T({
    id: 'router-ip', level: 2, title: 'Адреса маршрутизатора',
    goal: 'Новый маршрутизатор R1 ещё не настроен. Шлюзы на узлах уже указаны: 192.168.1.1 и 192.168.2.1. Настрой интерфейсы R1 так, чтобы обе сети связались.',
    theory: 'router',
    hints: ['Посмотри, какой порт R1 смотрит в какую сеть (подписи на кабелях).', 'G0/0: 192.168.1.1/24, G0/1: 192.168.2.1/24. Таблица маршрутов: веб-интерфейс R1, «Маршрутизация».'],
    solution: 'G0/0 192.168.1.1/24, G0/1 192.168.2.1/24.',
    build(b) { twoNets(b, { noRouterIp: true }); },
    checks: [
      { text: 'Шлюзы на узлах не менялись', test: (c) => c.dev('PC1').gateway === '192.168.1.1' && c.dev('SRV').gateway === '192.168.2.1' ? true : 'шлюзы узлов должны остаться прежними' },
      { text: 'PC1 пингует SRV', test: (c) => c.ping('PC1', '192.168.2.100') },
      { text: 'SRV пингует PC2', test: (c) => c.ping('SRV', '192.168.1.11') },
    ],
  });

  T({
    id: 'return', level: 2, title: 'Ответ не возвращается',
    goal: 'Пинг с PC-A до SRV-C не проходит, хотя запрос до сервера доходит. Найди в журнале, где теряется ответ, и исправь маршруты.',
    theory: 'twoway',
    hints: ['Запусти ping и открой журнал: на каком маршрутизаторе ответ отбрасывается «нет маршрута»?', 'На R3 нет маршрута в сеть 10.1.0.0/24. Следующий хоп для R3: 10.0.23.1.'],
    solution: 'На R3: 10.1.0.0/24 через 10.0.23.1.',
    build(b) {
      LAB.chain3(b, {
        r1: [['10.3.0.0/24', '10.0.12.2'], ['10.0.23.0/30', '10.0.12.2']],
        r2: [['10.1.0.0/24', '10.0.12.1'], ['10.3.0.0/24', '10.0.23.2']],
        r3: [['10.0.12.0/30', '10.0.23.1']],
      });
    },
    checks: [
      { text: 'PC-A пингует SRV-C', test: (c) => c.ping('PC-A', '10.3.0.10') },
      { text: 'SRV-C пингует PC-A', test: (c) => c.ping('SRV-C', '10.1.0.10') },
    ],
  });

  T({
    id: 'static3', level: 2, title: 'Три маршрутизатора',
    goal: 'Цепочка R1, R2, R3: адреса есть, маршрутов нет. Пропиши статические маршруты так, чтобы PC-A и SRV-C видели друг друга, а traceroute шёл через все три маршрутизатора.',
    theory: 'static',
    hints: ['R1 не знает про 10.3.0.0/24, R3 не знает про 10.1.0.0/24, а R2 не знает ни ту, ни другую.', 'R1: 10.3.0.0/24 через 10.0.12.2. R2: 10.1.0.0/24 через 10.0.12.1 и 10.3.0.0/24 через 10.0.23.2. R3: 10.1.0.0/24 через 10.0.23.1.'],
    solution: 'R1: 10.3.0.0/24 -> 10.0.12.2. R2: 10.1.0.0/24 -> 10.0.12.1, 10.3.0.0/24 -> 10.0.23.2. R3: 10.1.0.0/24 -> 10.0.23.1.',
    build(b) { LAB.chain3(b); },
    checks: [
      { text: 'PC-A пингует SRV-C', test: (c) => c.ping('PC-A', '10.3.0.10') },
      { text: 'SRV-C пингует PC-A', test: (c) => c.ping('SRV-C', '10.1.0.10') },
      { text: 'traceroute идёт через R1, R2, R3', test: (c) => { const t = c.tracert('PC-A', '10.3.0.10'); return t.hops.length === 4 && t.hops[3].done ? true : 'хопов ' + t.hops.length + ', а должно быть 4 (три маршрутизатора и сервер)'; } },
    ],
  });

  function hq(b, branchRoutes) {
    const r1 = b.add('router', -220, 0, { name: 'R1', ifs: { 'G0/0': '192.168.100.1/24', 'G0/1': '10.0.0.1/30' }, routes: branchRoutes || [] });
    const r2 = b.add('router', 120, 0, { name: 'HQ', ifs: { 'G0/0': '10.0.0.2/30', 'G0/1': '172.16.1.1/24', 'G0/2': '172.16.2.1/24', 'G0/3': '172.16.3.1/24' }, routes: [['192.168.100.0/24', '10.0.0.1']] });
    b.link(r1, r2, 'G0/1', 'G0/0');
    b.link(b.add('pc', -420, 120, { name: 'BRANCH-PC', ip: '192.168.100.10/24', gw: '192.168.100.1' }), r1, null, 'G0/0');
    [['MAIL', 360, -150, 1], ['FILES', 400, 0, 2], ['CRM', 360, 150, 3]].forEach(([n, x, y, k]) =>
      b.link(b.add('server', x, y, { name: n, ip: '172.16.' + k + '.10/24', gw: '172.16.' + k + '.1' }), r2, null, 'G0/' + k));
  }

  T({
    id: 'default', level: 2, title: 'Маршрут по умолчанию',
    goal: 'Филиал R1 подключён к центральному офису HQ единственным каналом. В центре три сети серверов, и их станет больше. Сделай так, чтобы BRANCH-PC доставал до всех серверов, а на R1 был ровно один маршрут.',
    theory: 'default-route',
    hints: ['Если путь наружу один, перечислять все сети незачем.', 'На R1: сеть 0.0.0.0, маска /0, следующий хоп 10.0.0.2.'],
    solution: 'На R1 один маршрут: 0.0.0.0/0 через 10.0.0.2.',
    build(b) { hq(b); },
    checks: [
      { text: 'BRANCH-PC пингует MAIL, FILES и CRM', test: (c) => c.all(['172.16.1.10', '172.16.2.10', '172.16.3.10'].map((a) => c.ping('BRANCH-PC', a))) },
      { text: 'На R1 ровно один маршрут, и это 0.0.0.0/0', test: (c) => { const r = c.dev('R1').routes; return r.length === 1 && r[0].prefix === 0 ? true : 'маршрутов на R1: ' + r.length + (r.length === 1 ? ', но это не 0.0.0.0/0' : ''); } },
    ],
  });

  /* ===== 3. Подсети ===== */
  T({
    id: 'split', level: 3, title: 'Раздели сеть пополам',
    goal: 'Офису выдали блок 172.16.0.0/24, а сетей две: слева и справа от R1. Разбей блок на две равные подсети и настрой адреса всем, включая R1, так чтобы левые узлы пинговали правые.',
    theory: 'subnetting',
    hints: ['Левая сеть 172.16.0.0/25, шлюз 172.16.0.1. Правая 172.16.0.128/25, шлюз 172.16.0.129.', 'Не забудь шлюзы на узлах и маски /25 везде.'],
    solution: 'R1 G0/0 172.16.0.1/25, G0/1 172.16.0.129/25; L1 .10/25 шлюз .1; R1-узел справа .130/25 шлюз .129 и т.д.',
    build(b) {
      const r1 = b.add('router', 0, -60, { name: 'R1' });
      const s1 = b.add('switch', -240, 60, { name: 'SW-L' });
      const s2 = b.add('switch', 240, 60, { name: 'SW-R' });
      b.link(r1, s1, 'G0/0'); b.link(r1, s2, 'G0/1');
      b.link(b.add('pc', -360, 220, { name: 'L1' }), s1); b.link(b.add('pc', -140, 230, { name: 'L2' }), s1);
      b.link(b.add('pc', 140, 230, { name: 'R-1' }), s2); b.link(b.add('pc', 360, 220, { name: 'R-2' }), s2);
    },
    checks: [
      { text: 'Все адреса внутри 172.16.0.0/24', test: (c) => c.all(['L1', 'L2', 'R-1', 'R-2'].map((n) => inNet(c.dev(n).ifaces[0].ip, '172.16.0.0/24') ? true : n + ': адрес вне блока')) },
      { text: 'Две подсети /25: слева и справа', test: (c) => {
        const l = c.lif('R1', 'G0/0'), r = c.lif('R1', 'G0/1');
        if (l.prefix !== 25 || r.prefix !== 25) return 'на R1 маски должны быть /25';
        if (!inNet(l.ip, '172.16.0.0/24') || !inNet(r.ip, '172.16.0.0/24')) return 'адреса R1 вне блока';
        return netOf(l) !== netOf(r) ? true : 'обе стороны в одной подсети';
      } },
      { text: 'L1 и L2 пингуют R-1 и R-2', test: (c) => c.all([c.ping('L1', c.dev('R-1').ifaces[0].ip), c.ping('L2', c.dev('R-2').ifaces[0].ip), c.ping('R-2', c.dev('L1').ifaces[0].ip)]) },
    ],
  });

  T({
    id: 'vlsm', level: 3, title: 'VLSM: подсети разного размера',
    goal: 'Блок 10.20.0.0/24 нужно поделить на четыре сети: A (до 100 узлов, за R1), B (до 50, за R1), C (до 20, за R2) и канал между R1 и R2. Подбери маски без лишнего расхода, настрой адреса, шлюзы и маршруты. Узел в каждой сети должен пинговать остальные.',
    theory: 'vlsm',
    hints: ['A 10.20.0.0/25, B 10.20.0.128/26, C 10.20.0.192/27, канал 10.20.0.224/30.', 'На R1 маршрут в сеть C через R2, на R2 маршрут по умолчанию через R1 (или два маршрута в A и B).'],
    solution: 'A 10.20.0.0/25 (R1 G0/0 .1), B 10.20.0.128/26 (R1 G0/2 .129), C 10.20.0.192/27 (R2 G0/1 .193), канал 10.20.0.224/30 (R1 .225, R2 .226). R1: 10.20.0.192/27 -> .226. R2: 0.0.0.0/0 -> .225.',
    build(b) {
      const r1 = b.add('router', -150, -40, { name: 'R1' });
      const r2 = b.add('router', 200, -40, { name: 'R2' });
      b.link(r1, r2, 'G0/1', 'G0/0');
      b.link(b.add('pc', -380, 120, { name: 'A' }), r1, null, 'G0/0');
      b.link(b.add('pc', -150, 170, { name: 'B' }), r1, null, 'G0/2');
      b.link(b.add('pc', 380, 120, { name: 'C' }), r2, null, 'G0/1');
    },
    checks: [
      { text: 'Сеть A вмещает 100 узлов, B 50, C 20', test: (c) => {
        const need = { A: 100, B: 50, C: 20 };
        return c.all(Object.keys(need).map((n) => { const x = c.dev(n).ifaces[0]; return x.ip && ip.hosts(x.prefix) >= need[n] ? true : n + ': в /' + x.prefix + ' помещается ' + ip.hosts(x.prefix) + ' узлов'; }));
      } },
      { text: 'Без лишнего расхода: A /25, B /26, C /27, канал /30', test: (c) => {
        const want = { A: 25, B: 26, C: 27 };
        const r = c.all(Object.keys(want).map((n) => c.dev(n).ifaces[0].prefix === want[n] ? true : n + ': маска /' + c.dev(n).ifaces[0].prefix + ', хватит /' + want[n]));
        if (r !== true) return r;
        return c.lif('R1', 'G0/1').prefix === 30 && c.lif('R2', 'G0/0').prefix === 30 ? true : 'канал R1–R2 должен быть /30';
      } },
      { text: 'Все сети внутри 10.20.0.0/24 и не пересекаются', test: (c) => {
        const cfgs = [c.lif('R1', 'G0/0'), c.lif('R1', 'G0/2'), c.lif('R2', 'G0/1'), c.lif('R1', 'G0/1')];
        if (cfgs.some((x) => !inNet(x.ip, '10.20.0.0/24'))) return 'адрес маршрутизатора вне блока';
        for (let i = 0; i < cfgs.length; i++) for (let j = i + 1; j < cfgs.length; j++) {
          const p = Math.min(cfgs[i].prefix, cfgs[j].prefix);
          if (ip.same(cfgs[i].ip, cfgs[j].ip, p)) return 'сети ' + netOf(cfgs[i]) + ' и ' + netOf(cfgs[j]) + ' пересекаются';
        }
        return true;
      } },
      { text: 'A, B и C пингуют друг друга', test: (c) => c.all([c.ping('A', c.dev('C').ifaces[0].ip), c.ping('B', c.dev('C').ifaces[0].ip), c.ping('C', c.dev('A').ifaces[0].ip)]) },
    ],
  });

  /* ===== 4. Службы ===== */
  T({
    id: 'dhcp-local', level: 4, title: 'DHCP в своей сети',
    goal: 'Компьютеры настроены на автоматическое получение адреса, но DHCP-сервер выключен. Включи на SRV службу DHCP и настрой пул 192.168.1.100–192.168.1.150, чтобы PC1 и PC2 получили адреса и пинговали сервер.',
    theory: 'dhcp',
    hints: ['Двойной щелчок по SRV, вкладка «Службы», карточка DHCP.', 'Пул: с 192.168.1.100 по 192.168.1.150, маска /24. Включи переключатель.'],
    solution: 'DHCP на SRV: пул 192.168.1.100–150 /24, служба включена.',
    build(b) {
      const sw = b.add('switch', 0, -40, { name: 'SW1' });
      b.link(b.add('server', 0, -220, { name: 'SRV', ip: '192.168.1.5/24' }), sw);
      b.link(b.add('pc', -220, 140, { name: 'PC1', dhcp: true }), sw);
      b.link(b.add('pc', 220, 140, { name: 'PC2', dhcp: true }), sw);
    },
    checks: [
      { text: 'PC1 получает адрес из 192.168.1.100–150', test: (c) => { const r = c.dhcp('PC1', '192.168.1.0/24'); if (r !== true) return r; const n = ip.parse(c.last.ip); return n >= ip.parse('192.168.1.100') && n <= ip.parse('192.168.1.150') ? true : 'адрес ' + c.last.ip + ' вне диапазона 100–150'; } },
      { text: 'PC2 получает адрес', test: (c) => c.dhcp('PC2', '192.168.1.0/24') },
      { text: 'После этого PC1 пингует SRV', test: (c) => { c.prep('PC1'); return c.ping('PC1', '192.168.1.5'); } },
    ],
  });

  T({
    id: 'relay', level: 4, title: 'DHCP через маршрутизатор',
    goal: 'DHCP-сервер NS1 стоит в серверной сети, а компьютеры в другой. Пул для них на сервере уже есть, но адреса они не получают. Настрой R1 так, чтобы PC1 получил адрес и открыл http://site.lab.',
    theory: 'dhcp-relay',
    hints: ['Relay настраивается на интерфейсе, который смотрит в сеть клиентов.', 'R1, «Интерфейсы», G0/0, поле DHCP relay: 10.0.0.10.'],
    solution: 'R1 G0/0: DHCP relay 10.0.0.10.',
    build(b) { LAB.servicesNet(b, { relay: false }); },
    checks: [
      { text: 'PC1 получает адрес из 192.168.10.0/24', test: (c) => c.dhcp('PC1', '192.168.10.0/24') },
      { text: 'PC1 открывает http://site.lab', test: (c) => { c.prep('PC1'); return c.http('PC1', 'http://site.lab'); } },
    ],
  });

  T({
    id: 'reserve', level: 4, title: 'Резервирование адреса',
    goal: 'Принтер PRN получает адрес по DHCP, и каждый раз может прийти другой. Сделай так, чтобы PRN всегда получал 192.168.10.50, а обычные компьютеры продолжали получать адреса из пула.',
    theory: 'dhcp-reserve',
    hints: ['MAC принтера видно на виде L2 под его значком и в окне «Сеть».', 'NS1, «Службы», DHCP, «Резервирование»: MAC принтера и 192.168.10.50.'],
    solution: 'На NS1 резервирование MAC PRN -> 192.168.10.50.',
    build(b) {
      LAB.servicesNet(b);
      const sw = b.net.devices.find((d) => d.type === 'switch');
      b.link(b.add('server', -500, 120, { name: 'PRN', dhcp: true }), sw);
    },
    checks: [
      { text: 'PRN получает 192.168.10.50', test: (c) => { const r = c.dhcp('PRN'); if (r !== true) return r; return c.last.ip === '192.168.10.50' ? true : 'PRN получил ' + c.last.ip; } },
      { text: 'PC1 получает адрес из пула, не .50', test: (c) => { const r = c.dhcp('PC1', '192.168.10.0/24'); if (r !== true) return r; return c.last.ip !== '192.168.10.50' ? true : 'PC1 получил адрес принтера'; } },
    ],
  });

  T({
    id: 'dns', level: 4, title: 'Имена вместо адресов',
    goal: 'У PC1 и PC2 адреса заданы вручную, а сайт открывается только по IP. Сделай так, чтобы с PC1 открывался http://portal.lab, а с PC2 работал ping portal.lab.',
    theory: 'dns',
    hints: ['Нужна запись portal.lab на NS1 (адрес веб-сервера 10.0.0.20).', 'И на PC1 и PC2 в поле DNS-сервер: 10.0.0.10.'],
    solution: 'NS1: запись portal.lab = 10.0.0.20. PC1, PC2: DNS 10.0.0.10.',
    build(b) {
      LAB.servicesNet(b, { services: { dns: { on: true, records: [{ name: 'ns1.lab', ip: '10.0.0.10' }] } } });
      ['PC1', 'PC2'].forEach((n, k) => { const d = b.net.byName(n); d.dhcp = false; d.ifaces[0].ip = '192.168.10.' + (10 + k); d.gateway = '192.168.10.1'; });
    },
    checks: [
      { text: 'PC1 узнаёт адрес portal.lab', test: (c) => c.dns('PC1', 'portal.lab', '10.0.0.20') },
      { text: 'PC1 открывает http://portal.lab', test: (c) => c.http('PC1', 'http://portal.lab') },
      { text: 'PC2 пингует portal.lab', test: (c) => c.ping('PC2', 'portal.lab') },
    ],
  });

  T({
    id: 'web', level: 4, title: 'Свой сайт',
    goal: 'Подними внутренний сайт отдела на сервере INTRA (192.168.10.80): страница с заголовком «Отдел продаж», открывается по имени http://intra.lab с PC1.',
    theory: 'http',
    hints: ['INTRA, «Службы», веб-сервер: заголовок «Отдел продаж», включить.', 'NS1: запись intra.lab = 192.168.10.80.'],
    solution: 'INTRA: веб-сервер с заголовком «Отдел продаж». NS1: intra.lab = 192.168.10.80.',
    build(b) {
      LAB.servicesNet(b);
      const sw = b.net.devices.find((d) => d.type === 'switch');
      b.link(b.add('server', -500, 120, { name: 'INTRA', ip: '192.168.10.80/24', gw: '192.168.10.1', dns: '10.0.0.10' }), sw);
    },
    checks: [
      { text: 'Веб-сервер на INTRA запущен', test: (c) => c.dev('INTRA').services.http.on ? true : 'служба выключена' },
      { text: 'intra.lab = 192.168.10.80', test: (c) => { c.prep('PC1'); return c.dns('PC1', 'intra.lab', '192.168.10.80'); } },
      { text: 'С PC1 открывается «Отдел продаж»', test: (c) => { c.prep('PC1'); return c.http('PC1', 'http://intra.lab', 'Отдел продаж'); } },
    ],
  });

  /* ===== 5. VLAN и коммутация ===== */
  T({
    id: 'vlans', level: 5, title: 'Отделы в разных VLAN',
    goal: 'Бухгалтерия (BUH1, BUH2) и склад (SKL1, SKL2) сидят на одном коммутаторе в одной подсети и видят друг друга. Раздели их: бухгалтерия в VLAN 10, склад в VLAN 20. Адреса не меняй.',
    theory: 'vlan',
    hints: ['SW1, «Порты и VLAN»: выбери порт и укажи VLAN.', 'Кто в каком порту, видно на подписях кабелей или по наведению.'],
    solution: 'Порты BUH1 и BUH2 в VLAN 10, порты SKL1 и SKL2 в VLAN 20.',
    build(b) {
      const sw = b.add('switch', 0, -40, { name: 'SW1' });
      [['BUH1', 11], ['BUH2', 12], ['SKL1', 13], ['SKL2', 14]].forEach(([n, a], k) => b.link(b.add('pc', -330 + k * 220, 150, { name: n, ip: '192.168.1.' + a + '/24' }), sw, null, 'Fa0/' + (k + 1)));
    },
    checks: [
      { text: 'BUH1 пингует BUH2, SKL1 пингует SKL2', test: (c) => c.all([c.ping('BUH1', '192.168.1.12'), c.ping('SKL1', '192.168.1.14')]) },
      { text: 'Бухгалтерия не видит склад', test: (c) => c.all([c.noPing('BUH1', '192.168.1.13'), c.noPing('BUH2', '192.168.1.14')]) },
      { text: 'Бухгалтерия в VLAN 10, склад в VLAN 20', test: (c) => {
        const sw = c.dev('SW1');
        const vl = (n) => { const d = c.dev(n); const l = c.net.links.find((x) => x.a.dev === d.id || x.b.dev === d.id); if (!l) return 0; const e = l.a.dev === sw.id ? l.a : l.b; return sw.ifaces[e.i].vlan; };
        return vl('BUH1') === 10 && vl('BUH2') === 10 && vl('SKL1') === 20 && vl('SKL2') === 20 ? true : 'VLAN портов: BUH1 ' + vl('BUH1') + ', BUH2 ' + vl('BUH2') + ', SKL1 ' + vl('SKL1') + ', SKL2 ' + vl('SKL2');
      } },
    ],
  });

  function floors(b, o) {
    o = o || {};
    const s1 = b.add('switch', -220, -40, { name: 'SW1', vlans: { 'Fa0/2': 10, 'Fa0/3': 20 }, trunks: o.trunk ? { 'Fa0/1': '' } : {} });
    const s2 = b.add('switch', 220, -40, { name: 'SW2', vlans: { 'Fa0/2': 10, 'Fa0/3': 20 }, trunks: o.trunk ? { 'Fa0/1': '' } : {} });
    b.link(s1, s2, 'Fa0/1', 'Fa0/1');
    const net = o.subnets ? (v) => '192.168.' + v : () => '192.168.1';
    const a = (v, n) => net(v) + '.' + n + '/24';
    const gw = (v) => (o.subnets ? '192.168.' + v + '.1' : undefined);
    b.link(b.add('pc', -340, 130, { name: 'PC1', ip: a(10, 11), gw: gw(10) }), s1, null, 'Fa0/2');
    b.link(b.add('pc', -120, 150, { name: 'PC2', ip: a(20, 12), gw: gw(20) }), s1, null, 'Fa0/3');
    b.link(b.add('pc', 120, 150, { name: 'PC3', ip: a(10, 13), gw: gw(10) }), s2, null, 'Fa0/2');
    b.link(b.add('pc', 340, 130, { name: 'PC4', ip: a(20, 14), gw: gw(20) }), s2, null, 'Fa0/3');
    return { s1, s2 };
  }

  T({
    id: 'trunk', level: 5, title: 'Транк между этажами',
    goal: 'На каждом этаже свой коммутатор, на обоих есть VLAN 10 и VLAN 20. Сейчас между коммутаторами обычный access-порт, и VLAN не проходят. Настрой канал так, чтобы PC1 видел PC3 (оба VLAN 10), PC2 видел PC4 (VLAN 20), а VLAN между собой не смешивались.',
    theory: 'trunk',
    hints: ['Порт Fa0/1 на SW1 и на SW2: режим trunk.', 'Разрешённые VLAN можно оставить «все» или указать 10,20.'],
    solution: 'Fa0/1 на SW1 и SW2: режим trunk.',
    build(b) { floors(b); },
    checks: [
      { text: 'PC1 пингует PC3 (VLAN 10)', test: (c) => c.ping('PC1', '192.168.1.13') },
      { text: 'PC2 пингует PC4 (VLAN 20)', test: (c) => c.ping('PC2', '192.168.1.14') },
      { text: 'VLAN 10 не видит VLAN 20', test: (c) => c.all([c.noPing('PC1', '192.168.1.14'), c.noPing('PC3', '192.168.1.12')]) },
    ],
  });

  T({
    id: 'roas', level: 5, title: 'Маршрутизатор на палочке',
    goal: 'VLAN 10 (192.168.10.0/24) и VLAN 20 (192.168.20.0/24) работают, транк между коммутаторами есть, шлюзы на ПК указаны (.1). Маршрутизатор R1 подключён к SW1 одним кабелем. Настрой R1 и порт SW1 так, чтобы VLAN 10 и VLAN 20 пинговали друг друга.',
    theory: 'roas',
    hints: ['SW1, порт к R1 (Fa0/4): режим trunk.', 'R1, «Интерфейсы», G0/0: добавь подынтерфейсы VLAN 10 с адресом 192.168.10.1/24 и VLAN 20 с 192.168.20.1/24.'],
    solution: 'SW1 Fa0/4 trunk. R1 G0/0.10 192.168.10.1/24, G0/0.20 192.168.20.1/24.',
    build(b) {
      const { s1 } = floors(b, { trunk: true, subnets: true });
      b.link(b.add('router', -220, -220, { name: 'R1' }), s1, 'G0/0', 'Fa0/4');
    },
    checks: [
      { text: 'PC1 (VLAN 10) пингует PC2 (VLAN 20)', test: (c) => c.ping('PC1', '192.168.20.12') },
      { text: 'PC4 (VLAN 20) пингует PC3 (VLAN 10)', test: (c) => c.ping('PC4', '192.168.10.13') },
      { text: 'На R1 подынтерфейсы, а не два кабеля', test: (c) => c.dev('R1').ifaces.filter((i) => i.link).length === 1 && c.dev('R1').ifaces[0].subs.length >= 2 ? true : 'нужен один кабель и подынтерфейсы на G0/0' },
    ],
  });

  T({
    id: 'dhcp-vlans', level: 5, title: 'DHCP для двух VLAN',
    goal: 'Маршрутизатор на палочке уже работает. Сервер SRV в VLAN 10 раздаёт адреса только своему VLAN. Сделай так, чтобы ПК в VLAN 20 тоже получали адреса по DHCP: из 192.168.20.0/24, со шлюзом 192.168.20.1.',
    theory: 'dhcp-pools',
    hints: ['R1, подынтерфейс G0/0.20: DHCP relay 192.168.10.5.', 'SRV, «Службы», DHCP: добавь второй пул 192.168.20.100–150, шлюз 192.168.20.1.'],
    solution: 'R1 G0/0.20 relay 192.168.10.5. На SRV второй пул 192.168.20.100–150 /24, шлюз 192.168.20.1.',
    build(b) {
      const s1 = b.add('switch', -160, 0, { name: 'SW1', trunks: { 'Fa0/1': '' }, vlans: { 'Fa0/2': 10, 'Fa0/3': 10, 'Fa0/4': 20, 'Fa0/5': 20 } });
      b.link(b.add('router', -160, -180, { name: 'R1', subs: { 'G0/0': [[10, '192.168.10.1/24'], [20, '192.168.20.1/24']] } }), s1, 'G0/0', 'Fa0/1');
      b.link(b.add('server', -420, 40, { name: 'SRV', ip: '192.168.10.5/24', gw: '192.168.10.1',
        services: { dhcp: { on: true, pools: [{ start: '192.168.10.100', end: '192.168.10.150', prefix: 24, gateway: '192.168.10.1' }] } } }), s1, null, 'Fa0/2');
      b.link(b.add('pc', -300, 190, { name: 'PC10', dhcp: true }), s1, null, 'Fa0/3');
      b.link(b.add('pc', -40, 190, { name: 'PC20', dhcp: true }), s1, null, 'Fa0/4');
      b.link(b.add('pc', 160, 160, { name: 'PC21', dhcp: true }), s1, null, 'Fa0/5');
    },
    checks: [
      { text: 'PC10 получает адрес из 192.168.10.0/24', test: (c) => c.dhcp('PC10', '192.168.10.0/24') },
      { text: 'PC20 получает адрес из 192.168.20.0/24, шлюз .1', test: (c) => { const r = c.dhcp('PC20', '192.168.20.0/24'); if (r !== true) return r; return c.last.gateway === '192.168.20.1' ? true : 'шлюз ' + (c.last.gateway || 'не выдан'); } },
      { text: 'PC21 получает адрес и пингует SRV', test: (c) => { const r = c.dhcp('PC21', '192.168.20.0/24'); return r !== true ? r : c.ping('PC21', '192.168.10.5'); } },
    ],
  });

  T({
    id: 'stp', level: 5, title: 'Петля и STP',
    goal: 'Три коммутатора соединены кольцом для надёжности, но STP на них выключен, и сеть утонула в широковещательном шторме. Включи STP и сделай корнем SW-CORE: через него идёт основной трафик.',
    theory: 'stp',
    hints: ['На каждом коммутаторе вкладка STP: включить.', 'У SW-CORE приоритет 4096 (меньше, чем 32768 у остальных).'],
    solution: 'STP включён на всех трёх. SW-CORE: приоритет 4096.',
    build(b) {
      const c0 = b.add('switch', 0, -140, { name: 'SW-CORE', stp: false });
      const a = b.add('switch', -230, 40, { name: 'SW-A', stp: false });
      const z = b.add('switch', 230, 40, { name: 'SW-B', stp: false });
      b.link(c0, a); b.link(c0, z); b.link(a, z);
      b.link(b.add('pc', -350, 200, { name: 'PC1', ip: '192.168.9.1/24' }), a);
      b.link(b.add('pc', 350, 200, { name: 'PC2', ip: '192.168.9.2/24' }), z);
    },
    checks: [
      { text: 'Шторма нет, PC1 пингует PC2', test: (c) => { const r = c.noStorm('PC1', '192.168.9.2'); return r !== true ? r : c.ping('PC1', '192.168.9.2'); } },
      { text: 'Корень STP: SW-CORE', test: (c) => { const st = c.net.stpInfo(); const b = st.bridge[c.dev('SW-CORE').id]; return b && b.isRoot && c.net.devices.filter((d) => d.stp).every((d) => d.stp.on) ? true : 'корнем выбран ' + (Object.values(st.bridge)[0] ? c.net.dev(Object.values(st.bridge)[0].root).name : 'никто'); } },
      { text: 'Кольцо сохранилось: обрыв SW-CORE–SW-A не рвёт связь', test: (c) => c.withDown('SW-CORE', 'Fa0/1', () => c.ping('PC1', '192.168.9.2')) },
    ],
  });

  /* ===== 6. Интернет и безопасность ===== */
  T({
    id: 'nat', level: 6, title: 'Выход в интернет через NAT',
    goal: 'Офис 192.168.1.0/24 подключён к провайдеру ISP. Провайдер не знает и не должен знать частные сети. Настрой R1 так, чтобы PC1 пинговал example.lab и открывал http://example.lab. На ISP ничего не меняй.',
    theory: 'nat',
    hints: ['R1, «NAT»: G0/0 inside, G0/1 outside.', 'R1, «Маршрутизация»: 0.0.0.0/0 через 198.51.100.1.'],
    solution: 'R1: G0/0 inside, G0/1 outside, маршрут 0.0.0.0/0 через 198.51.100.1.',
    build(b) { LAB.natNet(b); },
    checks: [
      { text: 'PC1 пингует example.lab', test: (c) => c.ping('PC1', 'example.lab') },
      { text: 'PC2 открывает http://example.lab', test: (c) => c.http('PC2', 'http://example.lab') },
      { text: 'ISP не знает частных сетей', test: (c) => c.dev('ISP').routes.length === 0 ? true : 'на ISP появились маршруты: так в реальности сделать нельзя' },
    ],
  });

  T({
    id: 'portfwd', level: 6, title: 'Опубликуй сайт',
    goal: 'Офис уже выходит в интернет через PAT. Теперь клиенты снаружи должны открывать сайт офиса (OFFICE-WEB, 192.168.1.80) по адресу http://198.51.100.2. Проверять будем с GUEST в интернете.',
    theory: 'port-forward',
    hints: ['R1, «NAT», «Проброс портов»: TCP, внешний порт 80, внутренний адрес 192.168.1.80, порт 80.'],
    solution: 'R1: статический NAT tcp 80 -> 192.168.1.80:80.',
    build(b) { LAB.natNet(b, { r1: { nat: { 'G0/0': 'inside', 'G0/1': 'outside' }, routes: [['0.0.0.0/0', '198.51.100.1']] } }); },
    checks: [
      { text: 'GUEST открывает http://198.51.100.2 и видит сайт офиса', test: (c) => c.http('GUEST', 'http://198.51.100.2', 'Сайт офиса') },
      { text: 'Офис по-прежнему ходит в интернет', test: (c) => c.ping('PC1', 'example.lab') },
    ],
  });

  function aclNet(b, acl) {
    const r1 = b.add('router', 0, -80, { name: 'R1', ifs: { 'G0/0': '192.168.1.1/24', 'G0/1': '192.168.50.1/24', 'G0/2': '10.0.0.1/24' }, acl: acl || {} });
    b.link(b.add('pc', -260, 80, { name: 'STAFF', ip: '192.168.1.10/24', gw: '192.168.1.1' }), r1, null, 'G0/0');
    b.link(b.add('pc', 260, 80, { name: 'GUEST', ip: '192.168.50.10/24', gw: '192.168.50.1' }), r1, null, 'G0/1');
    b.link(b.add('server', 0, 160, { name: 'SRV', ip: '10.0.0.10/24', gw: '10.0.0.1', services: { http: { on: true, title: 'Внутренний портал', body: 'Только для сотрудников.' } } }), r1, null, 'G0/2');
  }

  T({
    id: 'acl-guest', level: 6, title: 'Гостям нельзя к серверам',
    goal: 'Гостевая сеть 192.168.50.0/24 не должна доставать до сети серверов 10.0.0.0/24 ни ping-ом, ни по HTTP. Сотрудники ходят к серверу как раньше, а гости могут пинговать сотрудников (так сделано для примера).',
    theory: 'acl',
    hints: ['R1, «ACL», G0/1, на входе.', 'Правила: deny ip 192.168.50.0/24 10.0.0.0/24, затем permit ip any any (иначе неявный deny закроет всё).'],
    solution: 'R1 G0/1 in: deny ip 192.168.50.0/24 10.0.0.0/24; permit ip any any.',
    build(b) { aclNet(b); },
    checks: [
      { text: 'GUEST не пингует SRV и не открывает сайт', test: (c) => c.all([c.noPing('GUEST', '10.0.0.10'), c.noHttp('GUEST', 'http://10.0.0.10')]) },
      { text: 'STAFF пингует SRV и открывает сайт', test: (c) => c.all([c.ping('STAFF', '10.0.0.10'), c.http('STAFF', 'http://10.0.0.10')]) },
      { text: 'GUEST пингует STAFF', test: (c) => c.ping('GUEST', '192.168.1.10') },
    ],
  });

  T({
    id: 'acl-web', level: 6, title: 'К серверу только по HTTP',
    goal: 'К серверу SRV можно обращаться только по HTTP (TCP 80) из любой сети. Ping до сервера должен перестать работать, а всё остальное (например, ping между STAFF и GUEST) работать как раньше.',
    theory: 'acl-ports',
    hints: ['Удобно поставить ACL на выход G0/2 (в сторону сервера): тогда хватит одного списка на все сети.', 'permit tcp any 10.0.0.10/32 eq 80, затем deny ip any 10.0.0.0/24 (или ничего: неявный deny).'],
    solution: 'R1 G0/2 out: permit tcp any 10.0.0.10/32 eq 80.',
    build(b) { aclNet(b); },
    checks: [
      { text: 'STAFF и GUEST открывают сайт SRV', test: (c) => c.all([c.http('STAFF', 'http://10.0.0.10'), c.http('GUEST', 'http://10.0.0.10')]) },
      { text: 'Ping до SRV не проходит', test: (c) => c.all([c.noPing('STAFF', '10.0.0.10'), c.noPing('GUEST', '10.0.0.10')]) },
      { text: 'STAFF пингует GUEST', test: (c) => c.ping('STAFF', '192.168.50.10') },
    ],
  });

  /* ===== 7. Динамическая маршрутизация ===== */
  T({
    id: 'ospf', level: 7, title: 'OSPF вместо статики',
    goal: 'Четыре маршрутизатора в кольце, у каждого своя сеть с узлом. Статических маршрутов нет, и добавлять их нельзя. Включи OSPF так, чтобы все узлы видели друг друга, и чтобы обрыв любого одного кабеля кольца не рвал связь.',
    theory: 'ospf',
    hints: ['На каждом маршрутизаторе вкладка OSPF: включи OSPF на всех интерфейсах, включая G0/0 (сеть с узлом, её тоже надо объявить).'],
    solution: 'OSPF на всех интерфейсах R1–R4.',
    build(b) { LAB.ring4(b); },
    checks: [
      { text: 'Статических маршрутов нет', test: (c) => c.net.devices.every((d) => !d.routes.length) ? true : 'удали статические маршруты' },
      { text: 'PC1 пингует SRV2, PC3 и SRV4', test: (c) => c.all(['10.2.0.10', '10.3.0.10', '10.4.0.10'].map((a) => c.ping('PC1', a))) },
      { text: 'Обрыв R1–R2 не рвёт связь PC1 с SRV2', test: (c) => c.withDown('R1', 'G0/1', () => c.ping('PC1', '10.2.0.10')) },
      { text: 'Обрыв R3–R4 не рвёт связь SRV4 с PC3', test: (c) => c.withDown('R3', 'G0/1', () => c.ping('SRV4', '10.3.0.10')) },
    ],
  });

  T({
    id: 'ospf-default', level: 7, title: 'OSPF и выход в интернет',
    goal: 'Кольцо из четырёх маршрутизаторов уже на OSPF. R1 подключён к провайдеру через G0/3 и делает NAT, на нём есть маршрут по умолчанию. Сделай так, чтобы узлы за R2, R3 и R4 тоже выходили в интернет (пинговали 203.0.113.53), не добавляя статических маршрутов на R2–R4.',
    theory: 'ospf-default',
    hints: ['R1, вкладка OSPF: «Распространять маршрут по умолчанию».', 'Пакеты из сетей R2–R4 приходят на R1 через G0/1 и G0/2: эти интерфейсы тоже должны быть NAT inside.'],
    solution: 'R1: default-information originate; NAT inside на G0/0, G0/1, G0/2, outside на G0/3.',
    build(b) {
      const { rs } = LAB.ring4(b, { r: [{ ospf: ['G0/0', 'G0/1', 'G0/2'], nat: { 'G0/0': 'inside', 'G0/3': 'outside' }, routes: [['0.0.0.0/0', '198.51.100.1']] }, { ospf: true }, { ospf: true }, { ospf: true }] });
      const r1 = rs[0];
      r1.ifaces[3].ip = '198.51.100.2'; r1.ifaces[3].prefix = 30;
      const isp = b.add('router', -220, -330, { name: 'ISP', ifs: { 'G0/0': '198.51.100.1/30', 'G0/1': '203.0.113.1/24' } });
      b.link(r1, isp, 'G0/3', 'G0/0');
      b.link(b.add('server', 40, -330, { name: 'INTERNET', ip: '203.0.113.53/24', gw: '203.0.113.1' }), isp, null, 'G0/1');
    },
    checks: [
      { text: 'PC1 (за R1) пингует 203.0.113.53', test: (c) => c.ping('PC1', '203.0.113.53') },
      { text: 'SRV2, PC3 и SRV4 пингуют 203.0.113.53', test: (c) => c.all(['SRV2', 'PC3', 'SRV4'].map((n) => c.ping(n, '203.0.113.53'))) },
      { text: 'На R2, R3, R4 нет статических маршрутов, на ISP тоже', test: (c) => ['R2', 'R3', 'R4', 'ISP'].every((n) => !c.dev(n).routes.length) ? true : 'статические маршруты есть там, где их быть не должно' },
    ],
  });

  /* ===== 8. Итоговая ===== */
  T({
    id: 'final', level: 8, title: 'Филиал под ключ',
    goal: 'Новый филиал: кабели проложены, у провайдера всё готово. Сотрудники (STAFF1, STAFF2) должны быть в VLAN 10 (192.168.10.0/24), гости (GUEST1) в VLAN 20 (192.168.20.0/24). Все получают адреса по DHCP с SRV (192.168.10.5, он в VLAN 10), выходят в интернет и открывают http://example.lab. Гости не должны доставать до сети сотрудников. Петель нет: между SW1 и SW2 два кабеля.',
    theory: 'final',
    hints: [
      'SW1: Fa0/1 (к R1) и Fa0/2, Fa0/3 (к SW2) trunk. SW2: Fa0/1, Fa0/2 trunk. Порты сотрудников и SRV в VLAN 10, гостя в VLAN 20. STP включён (по умолчанию так).',
      'R1: G0/0.10 192.168.10.1/24, G0/0.20 192.168.20.1/24 с relay 192.168.10.5. G0/1 198.51.100.2/30 outside, подынтерфейсы inside, маршрут 0.0.0.0/0 через 198.51.100.1.',
      'SRV: адрес 192.168.10.5/24, шлюз .1. DHCP: пулы для 10 и 20, шлюзы .1, DNS 203.0.113.53.',
      'ACL на входе G0/0.20: deny ip 192.168.20.0/24 192.168.10.0/24, permit ip any any. DHCP-запросы гостей идут на 255.255.255.255 и под этот запрет не попадают.',
    ],
    solution: 'См. подсказки: все четыре шага вместе.',
    build(b) {
      const r1 = b.add('router', 0, -220, { name: 'R1' });
      const isp = b.add('router', 320, -220, { name: 'ISP', ifs: { 'G0/0': '198.51.100.1/30', 'G0/1': '203.0.113.1/24' } });
      b.link(r1, isp, 'G0/1', 'G0/0');
      b.link(b.add('server', 560, -220, { name: 'INTERNET', ip: '203.0.113.53/24', gw: '203.0.113.1',
        services: { dns: { on: true, records: [{ name: 'example.lab', ip: '203.0.113.53' }] }, http: { on: true, title: 'example.lab', body: 'Страница из «интернета».' } } }), isp, null, 'G0/1');
      const s1 = b.add('switch', -160, -40, { name: 'SW1', stp: false });
      const s2 = b.add('switch', 200, -40, { name: 'SW2', stp: false });
      b.link(r1, s1, 'G0/0', 'Fa0/1');
      b.link(s1, s2, 'Fa0/2', 'Fa0/1');
      b.link(s1, s2, 'Fa0/3', 'Fa0/2');
      b.link(b.add('server', -380, -40, { name: 'SRV' }), s1, null, 'Fa0/4');
      b.link(b.add('pc', -260, 140, { name: 'STAFF1', dhcp: true }), s1, null, 'Fa0/5');
      b.link(b.add('pc', 100, 150, { name: 'STAFF2', dhcp: true }), s2, null, 'Fa0/3');
      b.link(b.add('pc', 320, 140, { name: 'GUEST1', dhcp: true }), s2, null, 'Fa0/4');
    },
    checks: [
      { text: 'Петли нет: шторма не возникает', test: (c) => { c.prep('STAFF1'); return c.noStorm('STAFF1', '192.168.10.5'); } },
      { text: 'STAFF1 и STAFF2 получают адреса из 192.168.10.0/24', test: (c) => c.all([c.dhcp('STAFF1', '192.168.10.0/24'), c.dhcp('STAFF2', '192.168.10.0/24')]) },
      { text: 'GUEST1 получает адрес из 192.168.20.0/24', test: (c) => c.dhcp('GUEST1', '192.168.20.0/24') },
      { text: 'Все открывают http://example.lab', test: (c) => { ['STAFF1', 'STAFF2', 'GUEST1'].forEach((n) => c.prep(n)); return c.all(['STAFF1', 'STAFF2', 'GUEST1'].map((n) => c.http(n, 'http://example.lab'))); } },
      { text: 'Гость не достаёт до сотрудников и SRV', test: (c) => { ['STAFF1', 'GUEST1'].forEach((n) => c.prep(n)); return c.all([c.noPing('GUEST1', c.dev('STAFF1').ifaces[0].ip), c.noPing('GUEST1', '192.168.10.5')]); } },
      { text: 'Сотрудники пингуют друг друга и SRV', test: (c) => { ['STAFF1', 'STAFF2'].forEach((n) => c.prep(n)); return c.all([c.ping('STAFF1', c.dev('STAFF2').ifaces[0].ip), c.ping('STAFF2', '192.168.10.5')]); } },
    ],
  });
})();
