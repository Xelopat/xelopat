// Оболочка в стиле Cisco IOS для маршрутизаторов и коммутаторов.
// Режимы: R1> (просмотр), R1# (enable), R1(config)#, R1(config-if)#.
// Команды можно сокращать (sh ip ro, conf t, int g0/0), «?» показывает варианты, Tab дописывает слово.
// Новая команда: добавить объект в CMDS { m: режимы, k: типы устройств, w: слова, a: аргументы, h: справка, run(ctx, args) }.
(function () {
  const ip = LAB.ip;
  const R = ['router'], SW = ['switch'], BOTH = ['router', 'switch'];
  const EXEC = ['exec', 'priv'], PRIV = ['priv'], CONF = ['config'], IF = ['if'];
  const pad = (s, n) => String(s).padEnd(n, ' ');
  const LONG = { g: 'gigabitethernet', fa: 'fastethernet', e: 'ethernet' };

  // g0/0, Gi0/0, GigabitEthernet 0/0 → индекс интерфейса
  function findIf(dev, args) {
    const m = args.join('').match(/^([a-z]+)(\d+\/\d+)$/i);
    if (!m) return -1;
    return dev.ifaces.findIndex((itf) => {
      const n = itf.name.match(/^([a-z]+)(\d+\/\d+)$/i);
      if (!n || n[2] !== m[2]) return false;
      const long = LONG[n[1].toLowerCase()] || n[1].toLowerCase();
      return long.startsWith(m[1].toLowerCase()) || n[1].toLowerCase() === m[1].toLowerCase();
    });
  }
  const longName = (name) => {
    const n = name.match(/^([a-z]+)(\d+\/\d+)$/i);
    if (!n) return name;
    const l = LONG[n[1].toLowerCase()];
    return l ? l.replace(/^./, (c) => c.toUpperCase()).replace('ethernet', 'Ethernet') + n[2] : name;
  };

  function routeArgs(ctx, args) {
    if (args.length !== 3) { ctx.err('% Формат: ip route 10.3.0.0 255.255.255.0 10.0.12.2'); return null; }
    const c = ip.parseCidr(args[0] + ' ' + args[1]);
    if (!c || !ip.valid(args[2])) { ctx.err('% Неверный адрес или маска'); return null; }
    return { net: ip.str(ip.net(ip.parse(c.ip), c.prefix)), prefix: c.prefix, via: ip.norm(args[2]) };
  }

  function pingOut(ctx, dst) {
    ctx.print('Type escape sequence to abort.');
    ctx.print('Sending 1, 100-byte ICMP Echos to ' + dst + ', timeout is 2 seconds:');
    return ctx.run('ping', dst).then((r) => {
      const res = r.results[0];
      const ok = res && res.type === 'reply';
      const mark = ok ? '!' : res && res.type === 'unreach' ? 'U' : '.';
      ctx.print(mark, ok ? 'ok' : 'err');
      ctx.print('Success rate is ' + (ok ? 100 : 0) + ' percent (' + (ok ? 1 : 0) + '/1)', ok ? 'ok' : 'err');
      if (!ok) {
        const why = !res ? 'ответ не пришёл: посмотри журнал внизу, где потерялся пакет'
          : res.type === 'unreach' ? res.from + ' сообщил, что сети назначения нет в таблице маршрутизации'
            : res.type === 'arp-fail' ? 'на ARP-запрос никто не ответил'
              : res.type === 'dns-fail' ? 'имя не удалось разрешить' : res.text;
        ctx.print('! ' + why, 'dim');
      }
    });
  }

  const CMDS = [
    { m: ['exec'], k: BOTH, w: ['enable'], h: 'перейти в привилегированный режим', run(c) { c.s.mode = 'priv'; } },
    { m: PRIV, k: BOTH, w: ['disable'], h: 'вернуться в обычный режим', run(c) { c.s.mode = 'exec'; } },
    { m: EXEC, k: BOTH, w: ['exit'], h: 'закрыть сеанс', run(c) {
      c.print('Connection to ' + c.dev.name + ' closed.', 'dim');
      c.s.mode = 'exec';
      c.print('');
      c.banner();
    } },
    { m: PRIV, k: BOTH, w: ['configure', 'terminal'], h: 'режим настройки', run(c) {
      c.print('Enter configuration commands, one per line.  End with CNTL/Z.');
      c.s.mode = 'config';
    } },
    { m: ['config', 'if'], k: BOTH, w: ['exit'], h: 'на уровень выше', run(c) { c.s.mode = c.s.mode === 'if' ? 'config' : 'priv'; } },
    { m: ['config', 'if'], k: BOTH, w: ['end'], h: 'выйти из настройки', run(c) { c.s.mode = 'priv'; } },

    { m: EXEC, k: R, w: ['ping'], a: '<IP или имя>', h: 'проверить доступность', run(c, a) {
      if (!a[0]) return c.err('% Укажи адрес: ping 10.0.0.1');
      return pingOut(c, a[0]);
    } },
    { m: EXEC, k: R, w: ['traceroute'], a: '<IP>', h: 'путь до узла', run(c, a) {
      if (!a[0]) return c.err('% Укажи адрес: traceroute 10.0.0.1');
      c.print('Type escape sequence to abort.');
      c.print('Tracing the route to ' + a[0]);
      return c.run('tracert', a[0]).then((r) => {
        r.hops.forEach((h) => c.print('  ' + h.ttl + ' ' + (h.from ? h.from + (h.unreach ? ' !N' : '') : '*  *  *' + (h.error ? '   ' + h.error : '')), h.done ? 'ok' : h.from && !h.unreach ? '' : 'err'));
      });
    } },

    { m: EXEC, k: R, w: ['show', 'ip', 'route'], h: 'таблица маршрутизации', run(c) {
      c.print('Codes: C - connected, S - static');
      c.print('');
      const rows = LAB.routeTable(c.dev);
      if (!rows.length) c.print('Таблица пуста: задай адреса интерфейсам и подними их (no shutdown).', 'dim');
      rows.forEach((r) => c.print(r.code + '    ' + pad(r.net, 19) + (r.code === 'C' ? 'is directly connected, ' + longName(r.iface) : '[1/0] via ' + r.via + (r.iface ? '' : '   (сосед недостижим)')), r.iface ? '' : 'err'));
    } },
    { m: EXEC, k: BOTH, w: ['show', 'ip', 'interface', 'brief'], h: 'интерфейсы кратко', run(c) {
      c.print(pad('Interface', 24) + pad('IP-Address', 16) + pad('Status', 23) + 'Protocol');
      c.dev.ifaces.forEach((i) => {
        const st = i.down ? 'administratively down' : i.link ? 'up' : 'down';
        c.print(pad(longName(i.name), 24) + pad(i.ip || 'unassigned', 16) + pad(st, 23) + (LAB.ifUp(i) ? 'up' : 'down'), LAB.ifUp(i) ? '' : 'dim');
      });
    } },
    { m: EXEC, k: R, w: ['show', 'arp'], h: 'ARP-таблица', run(c) {
      const rows = Object.entries(c.sim.tables(c.dev).arp);
      c.print(pad('Protocol', 10) + pad('Address', 17) + 'Hardware Addr');
      c.dev.ifaces.filter((i) => i.ip).forEach((i) => c.print(pad('Internet', 10) + pad(i.ip, 17) + i.mac + '   (свой)', 'dim'));
      rows.forEach(([a, m]) => c.print(pad('Internet', 10) + pad(a, 17) + m));
    } },
    { m: EXEC, k: SW, w: ['show', 'mac', 'address-table'], h: 'выученные MAC-адреса', run(c) {
      const rows = Object.entries(c.sim.tables(c.dev).mac);
      c.print('          Mac Address Table');
      c.print('-------------------------------------------');
      c.print(pad('Vlan', 8) + pad('Mac Address', 20) + 'Ports');
      if (!rows.length) c.print('(пусто: через коммутатор ещё не прошёл ни один кадр)', 'dim');
      rows.forEach(([k, port]) => { const [v, mac] = k.split('|'); c.print(pad(v, 8) + pad(mac, 20) + c.dev.ifaces[port].name); });
    } },
    { m: EXEC, k: SW, w: ['show', 'vlan', 'brief'], h: 'VLAN и их порты', run(c) {
      const by = {};
      c.dev.ifaces.forEach((i) => { (by[i.vlan || 1] = by[i.vlan || 1] || []).push(i.name); });
      c.print(pad('VLAN', 6) + pad('Name', 18) + 'Ports');
      Object.keys(by).sort((a, b) => a - b).forEach((v) => c.print(pad(v, 6) + pad(v === '1' ? 'default' : 'VLAN' + String(v).padStart(4, '0'), 18) + by[v].join(', ')));
    } },
    { m: PRIV, k: BOTH, w: ['show', 'running-config'], h: 'текущая конфигурация', run(c) {
      const d = c.dev;
      c.print('Building configuration...');
      c.print('!');
      c.print('hostname ' + d.name);
      c.print('!');
      d.ifaces.forEach((i) => {
        c.print('interface ' + longName(i.name));
        if (i.desc) c.print(' description ' + i.desc);
        if (c.kind === 'switch') {
          c.print(' switchport mode access');
          if ((i.vlan || 1) !== 1) c.print(' switchport access vlan ' + i.vlan);
        } else {
          c.print(i.ip ? ' ip address ' + i.ip + ' ' + ip.maskStr(i.prefix) : ' no ip address');
          if (i.helper) c.print(' ip helper-address ' + i.helper);
        }
        c.print(i.down ? ' shutdown' : ' no shutdown');
        c.print('!');
      });
      d.routes.forEach((r) => c.print('ip route ' + r.net + ' ' + ip.maskStr(r.prefix) + ' ' + r.via));
      if (d.routes.length) c.print('!');
      c.print('end');
    } },
    { m: EXEC, k: BOTH, w: ['show', 'version'], h: 'модель и время работы', run(c) {
      c.print('xelopat IOS-lite, учебная прошивка ' + (c.kind === 'router' ? 'маршрутизатора' : 'коммутатора'));
      c.print(c.dev.name + ' uptime is 41 days, 3 hours');
      c.print(c.dev.ifaces.length + ' ' + (c.kind === 'router' ? 'Gigabit' : 'FastEthernet') + ' interfaces');
    } },
    { m: PRIV, k: R, w: ['clear', 'arp-cache'], h: 'очистить ARP-таблицу', run(c) { c.sim.tables(c.dev).arp = {}; } },
    { m: PRIV, k: SW, w: ['clear', 'mac', 'address-table'], h: 'очистить таблицу MAC', run(c) { c.sim.tables(c.dev).mac = {}; } },
    { m: PRIV, k: BOTH, w: ['write', 'memory'], h: 'сохранить конфигурацию', run(c) {
      c.print('Building configuration...');
      c.print('[OK]', 'ok');
      c.print('! в лаборатории всё сохраняется сразу, команда для привычки', 'dim');
    } },
    { m: PRIV, k: BOTH, w: ['copy', 'running-config', 'startup-config'], h: 'сохранить конфигурацию', run(c) {
      c.print('Destination filename [startup-config]?');
      c.print('Building configuration...');
      c.print('[OK]', 'ok');
    } },

    { m: CONF, k: BOTH, w: ['hostname'], a: '<имя>', h: 'имя устройства', run(c, a) {
      if (!a[0]) return c.err('% Укажи имя');
      c.dev.name = a[0].slice(0, 24);
      c.changed();
    } },
    { m: CONF, k: BOTH, w: ['interface'], a: '<интерфейс>', h: 'настроить интерфейс', run(c, a) {
      const i = findIf(c.dev, a);
      if (i < 0) return c.err('% Нет такого интерфейса. Есть: ' + c.dev.ifaces.map((x) => x.name).join(', '));
      c.s.ifIdx = i;
      c.s.mode = 'if';
    } },
    { m: CONF, k: R, w: ['ip', 'route'], a: '<сеть> <маска> <шлюз>', h: 'статический маршрут', run(c, a) {
      const r = routeArgs(c, a);
      if (!r) return;
      if (c.dev.routes.some((x) => x.net === r.net && x.prefix === r.prefix && x.via === r.via)) return;
      c.dev.routes.push(r);
      c.changed();
    } },
    { m: CONF, k: R, w: ['no', 'ip', 'route'], a: '<сеть> <маска> <шлюз>', h: 'удалить маршрут', run(c, a) {
      const r = routeArgs(c, a);
      if (!r) return;
      const n = c.dev.routes.length;
      c.dev.routes = c.dev.routes.filter((x) => !(x.net === r.net && x.prefix === r.prefix && x.via === r.via));
      if (n === c.dev.routes.length) return c.err('% Такого маршрута нет');
      c.changed();
    } },

    { m: IF, k: R, w: ['ip', 'address'], a: '<IP> <маска>', h: 'адрес интерфейса', run(c, a) {
      const cidr = ip.parseCidr(a.join(' '));
      if (!cidr) return c.err('% Формат: ip address 192.168.1.1 255.255.255.0');
      const bad = ip.hostError(cidr.ip, cidr.prefix);
      if (bad) return c.err('% Плохой адрес: ' + bad);
      const clash = c.dev.ifaces.find((x, i) => i !== c.s.ifIdx && x.ip && (ip.same(x.ip, cidr.ip, cidr.prefix) || ip.same(x.ip, cidr.ip, x.prefix)));
      if (clash) return c.err('% ' + ip.netStr(cidr.ip, cidr.prefix) + ' overlaps with ' + clash.name + ' (две ноги маршрутизатора не могут быть в одной сети)');
      const itf = c.itf;
      itf.ip = cidr.ip;
      itf.prefix = cidr.prefix;
      c.changed();
    } },
    { m: IF, k: R, w: ['no', 'ip', 'address'], h: 'убрать адрес', run(c) { c.itf.ip = ''; c.changed(); } },
    { m: IF, k: BOTH, w: ['shutdown'], h: 'выключить интерфейс', run(c) {
      c.itf.down = true;
      c.changed();
      c.print('%LINK-5-CHANGED: Interface ' + longName(c.itf.name) + ', changed state to administratively down', 'dim');
    } },
    { m: IF, k: BOTH, w: ['no', 'shutdown'], h: 'включить интерфейс', run(c) {
      c.itf.down = false;
      c.changed();
      c.print('%LINK-3-UPDOWN: Interface ' + longName(c.itf.name) + ', changed state to ' + (c.itf.link ? 'up' : 'down (кабель не подключён)'), c.itf.link ? 'ok' : 'dim');
    } },
    { m: IF, k: R, w: ['ip', 'helper-address'], a: '<IP DHCP-сервера>', h: 'DHCP relay в другую сеть', run(c, a) {
      if (!ip.valid(a[0])) return c.err('% Укажи адрес DHCP-сервера');
      c.itf.helper = ip.norm(a[0]);
      c.changed();
    } },
    { m: IF, k: R, w: ['no', 'ip', 'helper-address'], h: 'выключить DHCP relay', run(c) { c.itf.helper = ''; c.changed(); } },
    { m: IF, k: SW, w: ['switchport', 'access', 'vlan'], a: '<1-4094>', h: 'VLAN порта', run(c, a) {
      const v = Number(a[0]);
      if (!(v >= 1 && v <= 4094)) return c.err('% VLAN от 1 до 4094');
      if (!c.dev.ifaces.some((x) => (x.vlan || 1) === v)) c.print('% Access VLAN does not exist. Creating vlan ' + v, 'dim');
      c.itf.vlan = v;
      c.changed();
    } },
    { m: IF, k: SW, w: ['switchport', 'mode', 'access'], h: 'порт доступа (уже так)', run() {} },
    { m: IF, k: BOTH, w: ['description'], a: '<текст>', h: 'описание порта', run(c, a) { c.itf.desc = a.join(' ').slice(0, 60); c.changed(); } },
  ];

  const lower = (s) => s.toLowerCase();

  LAB.registerShell('ios', (env) => {
    const s = { mode: 'exec', ifIdx: 0 };
    const kind = () => LAB.types[env.dev().type].kind;

    function avail(mode) { return CMDS.filter((c) => c.m.includes(mode) && c.k.includes(kind())); }

    function parse(line) {
      let tokens = line.trim().split(/\s+/).filter(Boolean);
      let mode = s.mode;
      if (tokens[0] && 'do'.startsWith(lower(tokens[0])) && tokens[0].length >= 2 && (mode === 'config' || mode === 'if')) { tokens = tokens.slice(1); mode = 'priv'; }
      return { tokens, mode };
    }

    function match(tokens, mode) {
      const list = avail(mode);
      const full = list.filter((c) => tokens.length >= c.w.length && c.w.every((w, i) => w.startsWith(lower(tokens[i]))));
      if (full.length > 1) {
        const exact = full.filter((c) => c.w.every((w, i) => w === lower(tokens[i])));
        if (exact.length) return { cmd: exact.sort((a, b) => b.w.length - a.w.length)[0] };
        const longest = Math.max(...full.map((c) => c.w.length));
        const top = full.filter((c) => c.w.length === longest);
        if (top.length === 1) return { cmd: top[0] };
        return { error: '% Ambiguous command: "' + tokens.join(' ') + '"' };
      }
      if (full.length === 1) return { cmd: full[0] };
      const partial = list.some((c) => tokens.length < c.w.length && tokens.every((t, i) => c.w[i].startsWith(lower(t))));
      if (partial) return { error: '% Incomplete command.' };
      return { error: '% Invalid input detected.' };
    }

    function ctxFor(io, mode) {
      const dev = env.dev();
      return {
        s, dev, sim: env.sim(), kind: kind(), mode,
        itf: dev.ifaces[s.ifIdx],
        print: io.print,
        err: (t) => io.print(t, 'err'),
        changed: env.changed,
        run: (op, arg) => env.run(op, arg),
        banner: () => banner().forEach((b) => io.print(b.text, b.cls)),
      };
    }

    function options(line) {
      const ends = /\s$/.test(line) || line === '';
      const { tokens, mode } = parse(line);
      const pos = ends ? tokens.length : tokens.length - 1;
      const part = ends ? '' : lower(tokens[pos] || '');
      const seen = new Map();
      avail(mode).forEach((c) => {
        if (!tokens.slice(0, pos).every((t, i) => c.w[i] && c.w[i].startsWith(lower(t)))) return;
        if (pos < c.w.length) {
          const w = c.w[pos];
          if (w.startsWith(part) && !seen.has(w)) seen.set(w, c.w.length === pos + 1 ? c.h + (c.a ? '  ' + c.a : '') : '');
        } else if (pos === c.w.length && c.a && !seen.has(c.a)) {
          seen.set(c.a, c.h);
        }
      });
      return { tokens, pos, part, opts: [...seen.entries()] };
    }

    function banner() {
      const dev = env.dev();
      const mgmt = dev.ifaces.find((i) => i.ip && LAB.ifUp(i));
      const lines = [];
      if (mgmt) {
        lines.push({ text: '$ ssh admin@' + mgmt.ip, cls: 'dim' });
        lines.push({ text: 'admin@' + mgmt.ip + "'s password: ********", cls: 'dim' });
      } else {
        lines.push({ text: 'Подключение через консольный кабель (COM1, 9600 бод): у устройства нет IP для SSH', cls: 'dim' });
      }
      lines.push({ text: '' });
      lines.push({ text: dev.name + ': учебная прошивка в стиле Cisco IOS. «?» показывает команды, Tab дописывает слово.' });
      lines.push({ text: 'Команды можно сокращать: en, conf t, int g0/0, sh ip ro.', cls: 'dim' });
      lines.push({ text: '' });
      return lines;
    }

    return {
      title() {
        const dev = env.dev();
        const mgmt = dev.ifaces.find((i) => i.ip && LAB.ifUp(i));
        return mgmt ? 'ssh admin@' + mgmt.ip : 'console: ' + dev.name;
      },
      banner,
      prompt() {
        const name = env.dev().name;
        const tail = { exec: '>', priv: '#', config: '(config)#', if: '(config-if)#' }[s.mode];
        return [{ text: name, cls: 'host' }, { text: tail + ' ', cls: s.mode === 'exec' ? 'sym' : 'priv' }];
      },
      exec(line, io) {
        if (!line.trim()) return;
        if (line.trim().endsWith('?')) {
          const { opts } = options(line.trim().slice(0, -1) + (/\s\?$/.test(line.trim()) || line.trim() === '?' ? ' ' : ''));
          if (!opts.length) { io.print('% Unrecognized command', 'err'); return; }
          opts.forEach(([w, h]) => io.print([{ text: '  ' + pad(w, 26), cls: 'cmd' }, { text: h, cls: 'dim' }]));
          return;
        }
        const { tokens, mode } = parse(line);
        if (!tokens.length) return;
        const m = match(tokens, mode);
        if (m.error) {
          io.print(m.error, 'err');
          if (m.error.includes('Invalid') && s.mode === 'exec' && avail('priv').some((c) => c.w[0].startsWith(lower(tokens[0])))) {
            io.print('! эта команда доступна после enable', 'dim');
          } else if (m.error.includes('Invalid') && (s.mode === 'priv' || s.mode === 'exec') && (avail('config').concat(avail('if'))).some((c) => c.w[0].startsWith(lower(tokens[0])))) {
            io.print('! это команда настройки: сначала configure terminal', 'dim');
          }
          return;
        }
        return m.cmd.run(ctxFor(io, mode), tokens.slice(m.cmd.w.length));
      },
      complete(line) {
        const { tokens, pos, part, opts } = options(line);
        const words = opts.map(([w]) => w).filter((w) => !w.startsWith('<'));
        if (!words.length) return null;
        if (words.length === 1) {
          const head = line.replace(/\S*$/, '');
          return { line: head + words[0] + ' ' };
        }
        let common = words[0];
        words.forEach((w) => { while (!w.startsWith(common)) common = common.slice(0, -1); });
        if (common.length > part.length) return { line: line.replace(/\S*$/, '') + common };
        return { options: words };
      },
    };
  });
})();
