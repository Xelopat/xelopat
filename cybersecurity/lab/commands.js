// Команды консоли устройства. Новая: LAB.registerCommand({ name, kinds, usage, help, run(ctx, args) }).
// name может быть из нескольких слов («show ip route»), выбирается самое длинное совпадение.
// ctx: dev, net, sim, ip, print(text, cls), ping(dst) → Promise<результат>, tracert(dst) → Promise, changed()
(function () {
  const ip = LAB.ip;
  const reg = LAB.registerCommand;
  const pad = (s, n) => String(s).padEnd(n, ' ');

  reg({
    name: 'help', kinds: ['*'], usage: 'help', help: 'список команд',
    run(ctx) {
      const kind = LAB.types[ctx.dev.type].kind;
      LAB.commands
        .filter((c) => c.kinds.includes('*') || c.kinds.includes(kind))
        .forEach((c) => ctx.print(pad(c.usage, 34) + c.help));
    },
  });

  reg({ name: 'clear', kinds: ['*'], usage: 'clear', help: 'очистить консоль', run(ctx) { ctx.clear(); } });

  reg({
    name: 'ipconfig', kinds: ['host'], usage: 'ipconfig', help: 'адрес, маска, шлюз',
    run(ctx) {
      const i = ctx.dev.ifaces[0];
      ctx.print('Интерфейс ' + i.name + (i.link ? '' : ' (кабель не подключён)'));
      ctx.print('  MAC-адрес . . . . . : ' + i.mac);
      ctx.print('  IPv4-адрес  . . . . : ' + (i.ip || 'не задан'));
      ctx.print('  Маска подсети . . . : ' + (i.ip ? ip.maskStr(i.prefix) + ' (/' + i.prefix + ')' : '-'));
      ctx.print('  Основной шлюз . . . : ' + (ctx.dev.gateway || 'не задан'));
    },
  });

  function pingCmd(ctx, args) {
    const dst = args[0];
    if (!dst || ip.parse(dst) == null) { ctx.print('укажи IP-адрес: ping 192.168.1.1', 'err'); return; }
    ctx.print('Обмен пакетами с ' + dst + ':');
    return ctx.ping(dst).then((r) => {
      const res = r.results[0];
      if (!res) ctx.print('Превышен интервал ожидания для запроса.', 'err');
      else if (res.type === 'reply') ctx.print('Ответ от ' + res.from + ': TTL=' + res.ttl, 'ok');
      else if (res.type === 'unreach') ctx.print('Ответ от ' + res.from + ': заданная сеть недоступна.', 'err');
      else if (res.type === 'ttl') ctx.print('Ответ от ' + res.from + ': превышен срок жизни (TTL) при передаче.', 'err');
      else if (res.type === 'arp-fail') ctx.print('Заданный узел недоступен (нет ответа на ARP).', 'err');
      else ctx.print('Ошибка: ' + res.text, 'err');
      if (r.storm) ctx.print('Сеть парализована широковещательным штормом.', 'err');
    });
  }

  function tracertCmd(ctx, args) {
    const dst = args[0];
    if (!dst || ip.parse(dst) == null) { ctx.print('укажи IP-адрес: tracert 10.0.0.1', 'err'); return; }
    ctx.print('Трассировка маршрута к ' + dst + ', не более 12 прыжков:');
    return ctx.tracert(dst).then((r) => {
      r.hops.forEach((h) => {
        const text = h.from ? h.from + (h.unreach ? '  сеть недоступна' : '') : '*   ' + (h.error || 'превышен интервал ожидания');
        ctx.print(pad(h.ttl, 4) + text, h.done ? 'ok' : (h.from && !h.unreach ? '' : 'err'));
      });
      const last = r.hops[r.hops.length - 1];
      ctx.print(last && last.done ? 'Трассировка завершена.' : 'Узел не достигнут.', last && last.done ? 'ok' : 'err');
    });
  }

  reg({ name: 'ping', kinds: ['host', 'router'], usage: 'ping <IP>', help: 'проверить доступность узла', run: pingCmd });
  reg({ name: 'tracert', kinds: ['host'], usage: 'tracert <IP>', help: 'путь до узла по хопам', run: tracertCmd });
  reg({ name: 'traceroute', kinds: ['router'], usage: 'traceroute <IP>', help: 'путь до узла по хопам', run: tracertCmd });

  function arpTable(ctx) {
    const arp = ctx.sim.tables(ctx.dev).arp;
    const rows = Object.entries(arp);
    if (!rows.length) { ctx.print('ARP-таблица пуста'); return; }
    ctx.print(pad('IP-адрес', 18) + 'MAC-адрес');
    rows.forEach(([a, m]) => ctx.print(pad(a, 18) + m));
  }
  reg({ name: 'arp -a', kinds: ['host'], usage: 'arp -a', help: 'ARP-таблица', run: arpTable });
  reg({ name: 'show arp', kinds: ['router'], usage: 'show arp', help: 'ARP-таблица', run: arpTable });

  reg({
    name: 'show ip route', kinds: ['router'], usage: 'show ip route', help: 'таблица маршрутизации',
    run(ctx) {
      ctx.print('Коды: C - подключена, S - статический маршрут');
      const rows = LAB.routeTable(ctx.dev);
      if (!rows.length) ctx.print('таблица пуста: нет интерфейсов с адресом и кабелем');
      rows.forEach((r) => ctx.print(r.code + '    ' + pad(r.net, 20) + (r.code === 'C' ? 'подключена, ' + r.iface : 'через ' + r.via + (r.iface ? ', ' + r.iface : ' (шлюз недоступен)')), r.iface ? '' : 'err'));
    },
  });

  reg({
    name: 'show ip interface brief', kinds: ['router'], usage: 'show ip interface brief', help: 'интерфейсы и адреса',
    run(ctx) {
      ctx.print(pad('Интерфейс', 11) + pad('IP-адрес', 20) + 'Состояние');
      ctx.dev.ifaces.forEach((i) => ctx.print(pad(i.name, 11) + pad(i.ip ? i.ip + '/' + i.prefix : 'не задан', 20) + (i.link ? 'up' : 'down')));
    },
  });

  function parseRouteArgs(ctx, args) {
    // ip route 10.0.0.0 255.255.255.0 10.0.12.2  или  ip route 10.0.0.0/24 10.0.12.2
    let cidr, via;
    if (args.length === 3) { cidr = ip.parseCidr(args[0] + ' ' + args[1]); via = args[2]; }
    else if (args.length === 2) { cidr = ip.parseCidr(args[0]); via = args[1]; }
    if (!cidr || ip.parse(via) == null) { ctx.print('формат: ip route 10.0.0.0 255.255.255.0 10.0.12.2', 'err'); return null; }
    return { net: ip.str(ip.net(ip.parse(cidr.ip), cidr.prefix)), prefix: cidr.prefix, via: ip.str(ip.parse(via)) };
  }

  reg({
    name: 'ip route', kinds: ['router'], usage: 'ip route <сеть> <маска> <шлюз>', help: 'добавить статический маршрут',
    run(ctx, args) {
      const r = parseRouteArgs(ctx, args);
      if (!r) return;
      if (ctx.dev.routes.some((x) => x.net === r.net && x.prefix === r.prefix && x.via === r.via)) { ctx.print('такой маршрут уже есть'); return; }
      ctx.dev.routes.push(r);
      ctx.changed();
      ctx.print('добавлен: ' + r.net + '/' + r.prefix + ' через ' + r.via, 'ok');
    },
  });

  reg({
    name: 'no ip route', kinds: ['router'], usage: 'no ip route <сеть> <маска> <шлюз>', help: 'удалить маршрут',
    run(ctx, args) {
      const r = parseRouteArgs(ctx, args);
      if (!r) return;
      const before = ctx.dev.routes.length;
      ctx.dev.routes = ctx.dev.routes.filter((x) => !(x.net === r.net && x.prefix === r.prefix && x.via === r.via));
      if (ctx.dev.routes.length === before) { ctx.print('такого маршрута нет', 'err'); return; }
      ctx.changed();
      ctx.print('удалён', 'ok');
    },
  });

  reg({
    name: 'show mac address-table', kinds: ['switch'], usage: 'show mac address-table', help: 'выученные MAC-адреса',
    run(ctx) {
      const rows = Object.entries(ctx.sim.tables(ctx.dev).mac);
      if (!rows.length) { ctx.print('таблица пуста: коммутатор ещё не видел ни одного кадра'); return; }
      ctx.print(pad('VLAN', 6) + pad('MAC-адрес', 20) + 'Порт');
      rows.forEach(([k, port]) => { const [v, mac] = k.split('|'); ctx.print(pad(v, 6) + pad(mac, 20) + ctx.dev.ifaces[port].name); });
    },
  });

  reg({
    name: 'show vlan', kinds: ['switch'], usage: 'show vlan', help: 'порты по VLAN',
    run(ctx) {
      const by = {};
      ctx.dev.ifaces.forEach((i) => { (by[i.vlan || 1] = by[i.vlan || 1] || []).push(i.name); });
      Object.keys(by).sort((a, b) => a - b).forEach((v) => ctx.print(pad('VLAN ' + v, 10) + by[v].join(', ')));
    },
  });

  // Таблица маршрутизации для панели и show ip route: подключённые сети и статика
  LAB.routeTable = function (dev) {
    const rows = [];
    dev.ifaces.forEach((i) => {
      if (i.ip && i.link) rows.push({ code: 'C', net: ip.netStr(i.ip, i.prefix), prefix: i.prefix, iface: i.name });
    });
    dev.routes.forEach((r, idx) => {
      const out = dev.ifaces.find((i) => i.ip && i.link && ip.same(r.via, i.ip, i.prefix));
      rows.push({ code: 'S', net: r.net + '/' + r.prefix, prefix: r.prefix, via: r.via, iface: out ? out.name : '', idx });
    });
    return rows.sort((a, b) => b.prefix - a.prefix);
  };

  // Разбор строки консоли: самое длинное совпадение по имени команды
  LAB.findCommand = function (line, kind) {
    const low = line.trim().toLowerCase().replace(/\s+/g, ' ');
    let best = null;
    LAB.commands.forEach((c) => {
      if (!(c.kinds.includes('*') || c.kinds.includes(kind))) return;
      if (low === c.name || low.startsWith(c.name + ' ')) {
        if (!best || c.name.length > best.name.length) best = c;
      }
    });
    if (!best) return null;
    const args = line.trim().replace(/\s+/g, ' ').slice(best.name.length).trim();
    return { cmd: best, args: args ? args.split(' ') : [] };
  };
})();
