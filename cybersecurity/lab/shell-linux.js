// Оболочка Linux для ПК и серверов: ip, ping, dhclient, nslookup, curl, systemctl и конфиги служб.
// Конфиги читаются через cat и меняются через echo "…" > файл (или >> чтобы дописать).
// Новая команда: добавить объект в CMDS { re: регулярка по всей строке, h: справка, run(ctx, m) }.
(function () {
  const ip = LAB.ip;
  const pad = (s, n) => String(s).padEnd(n, ' ');

  const SERVICES = {
    dns: { names: ['named', 'bind9', 'dns'], unit: 'named', title: 'BIND, DNS-сервер', port: 'udp/53' },
    dhcp: { names: ['isc-dhcp-server', 'dhcpd', 'dhcp'], unit: 'isc-dhcp-server', title: 'ISC DHCP-сервер', port: 'udp/67' },
    http: { names: ['nginx', 'apache2', 'httpd', 'http'], unit: 'nginx', title: 'nginx, веб-сервер', port: 'tcp/80' },
  };
  const svcByName = (n) => Object.keys(SERVICES).find((k) => SERVICES[k].names.includes(String(n).replace(/\.service$/, '').toLowerCase()));

  const FILES = {
    '/etc/hostname': { read: (d) => [d.name] },
    '/etc/resolv.conf': {
      read: (d) => (d.dns ? ['nameserver ' + d.dns] : ['# DNS-сервер не задан']),
      write(d, text, append) {
        const m = text.match(/nameserver\s+([\d.]+)/);
        if (!m && !append && !text.trim()) { d.dns = ''; return ''; }
        if (!m || !ip.valid(m[1])) return 'ожидалось: nameserver 10.0.0.10';
        d.dns = ip.norm(m[1]);
        return '';
      },
    },
    '/etc/bind/db.lab': {
      read: (d) => ['; зона DNS: имя  A  адрес'].concat(d.services.dns.records.map((r) => pad(r.name, 22) + 'IN  A  ' + r.ip)),
      write(d, text, append) {
        if (!append) d.services.dns.records = [];
        if (!text.trim()) return '';
        const m = text.trim().match(/^(\S+)\s+(?:IN\s+)?A\s+([\d.]+)$/i);
        if (!m || !ip.valid(m[2])) return 'ожидалось: site.lab A 10.0.0.20';
        const recs = d.services.dns.records.filter((r) => r.name.toLowerCase() !== m[1].toLowerCase());
        recs.push({ name: m[1], ip: ip.norm(m[2]) });
        d.services.dns.records = recs;
        return '';
      },
    },
    '/etc/dhcp/dhcpd.conf': {
      read(d) {
        const p = d.services.dhcp.pool;
        const out = ['# /etc/dhcp/dhcpd.conf'];
        if (ip.valid(p.start)) {
          out.push('subnet ' + ip.str(ip.net(ip.parse(p.start), p.prefix)) + ' netmask ' + ip.maskStr(p.prefix) + ' {');
          out.push('  range ' + p.start + ' ' + p.end + ';');
          if (p.gateway) out.push('  option routers ' + p.gateway + ';');
          if (p.dns) out.push('  option domain-name-servers ' + p.dns + ';');
          out.push('}');
        } else out.push('# пул не настроен');
        d.services.dhcp.reservations.forEach((r, i) => out.push('host res' + (i + 1) + ' { hardware ethernet ' + r.mac + '; fixed-address ' + r.ip + '; }'));
        return out;
      },
      write(d, text, append) {
        const cfg = d.services.dhcp;
        if (!append) { cfg.pool = { start: '', end: '', prefix: 24, gateway: '', dns: '' }; cfg.reservations = []; }
        if (!text.trim()) return '';
        let hit = false;
        let m;
        if ((m = text.match(/netmask\s+([\d.]+)/))) { const p = ip.parsePrefix(m[1]); if (p != null) { cfg.pool.prefix = p; hit = true; } }
        if ((m = text.match(/range\s+([\d.]+)\s+([\d.]+)/)) && ip.valid(m[1]) && ip.valid(m[2])) { cfg.pool.start = ip.norm(m[1]); cfg.pool.end = ip.norm(m[2]); hit = true; }
        if ((m = text.match(/option\s+routers\s+([\d.]+)/)) && ip.valid(m[1])) { cfg.pool.gateway = ip.norm(m[1]); hit = true; }
        if ((m = text.match(/domain-name-servers\s+([\d.]+)/)) && ip.valid(m[1])) { cfg.pool.dns = ip.norm(m[1]); hit = true; }
        if ((m = text.match(/hardware\s+ethernet\s+([0-9a-f:]{17}).*fixed-address\s+([\d.]+)/i)) && ip.valid(m[2])) {
          cfg.reservations = cfg.reservations.filter((r) => r.mac.toLowerCase() !== m[1].toLowerCase());
          cfg.reservations.push({ mac: m[1].toLowerCase(), ip: ip.norm(m[2]) });
          hit = true;
        }
        return hit ? '' : 'не понял строку. Примеры: "range 192.168.1.100 192.168.1.150", "option routers 192.168.1.1", "subnet 192.168.1.0 netmask 255.255.255.0"';
      },
    },
    '/var/www/html/index.html': {
      read: (d) => ['<h1>' + d.services.http.title + '</h1>', '<p>' + d.services.http.body + '</p>'],
      write(d, text, append) {
        const h = d.services.http;
        const t = text.match(/<h1>(.*?)<\/h1>/i);
        const body = text.replace(/<h1>.*?<\/h1>/i, '').replace(/<[^>]+>/g, '').trim();
        if (append) { if (t) h.title = t[1]; if (body) h.body = (h.body ? h.body + '\n' : '') + body; return ''; }
        h.title = t ? t[1] : h.title;
        h.body = body;
        return '';
      },
    },
  };
  FILES['/var/lib/dhcp/dhcpd.leases'] = {
    read(d, c) {
      const rows = Object.entries(c.sim.tables(d).leases);
      if (!rows.length) return ['# аренд пока нет: никто не получал адрес у этого сервера'];
      return rows.map(([mac, a]) => 'lease ' + a + ' { hardware ethernet ' + mac + '; binding state active; }');
    },
  };
  const DIRS = { '/etc': ['hostname', 'resolv.conf', 'bind/', 'dhcp/'], '/etc/bind': ['db.lab'], '/etc/dhcp': ['dhcpd.conf'], '/var/www/html': ['index.html'], '/var/lib/dhcp': ['dhcpd.leases'] };

  function pingOut(c, dst) {
    return c.run('ping', dst).then((r) => {
      const res = r.results[0];
      const dnsEv = r.results.find((x) => x.type === 'dns-fail');
      if (dnsEv) { c.err('ping: ' + dst + ': Temporary failure in name resolution'); c.why(dnsEv.reason); return; }
      if (res && res.type === 'local-err') { c.err('ping: connect: Network is unreachable'); c.why(res.text); return; }
      c.print('PING ' + dst + ' 56(84) bytes of data.');
      if (res && res.type === 'reply') c.print('64 bytes from ' + res.from + ': icmp_seq=1 ttl=' + res.ttl + ' time=' + (0.3 + Math.random() * 2).toFixed(1) + ' ms', 'ok');
      else if (res && res.type === 'unreach') c.err('From ' + res.from + ' icmp_seq=1 Destination Net Unreachable');
      else if (res && res.type === 'arp-fail') c.err('From ' + (c.dev.ifaces[0].ip || '?') + ' icmp_seq=1 Destination Host Unreachable');
      c.print('');
      c.print('--- ' + dst + ' ping statistics ---');
      const ok = res && res.type === 'reply';
      c.print('1 packets transmitted, ' + (ok ? 1 : 0) + ' received, ' + (ok ? 0 : 100) + '% packet loss', ok ? 'ok' : 'err');
      if (!ok && !res) c.why('ответ не вернулся: в журнале внизу видно, где пропал пакет');
      if (res && res.type === 'arp-fail') c.why('на ARP-запрос никто не ответил: узла нет в этой сети или он в другом VLAN');
    });
  }

  const CMDS = [
    { re: /^help$/, h: 'список команд', run(c) {
      c.print('Сеть:      ip a   ip addr add 192.168.1.10/24 dev eth0   ip route add default via 192.168.1.1');
      c.print('           ip neigh   ip link set eth0 down|up   dhclient   dhclient -r');
      c.print('Проверка:  ping <адрес|имя>   traceroute <адрес>   nslookup <имя>   curl http://<имя>/');
      c.print('Файлы:     cat /etc/resolv.conf   echo "nameserver 10.0.0.10" > /etc/resolv.conf   ls /etc');
      c.print('Службы:    systemctl status|start|stop named|isc-dhcp-server|nginx');
      c.print('           cat /etc/bind/db.lab   /etc/dhcp/dhcpd.conf   /var/www/html/index.html');
      c.print('           cat /var/lib/dhcp/dhcpd.leases (выданные адреса)');
      c.print('Прочее:    hostname   whoami   clear   history', 'dim');
    } },
    { re: /^clear$/, run(c) { c.clear(); } },
    { re: /^whoami$/, run(c) { c.print(c.user); } },
    { re: /^uname(\s+-a)?$/, run(c) { c.print('Linux ' + c.dev.name + ' 6.8.0-lab x86_64 GNU/Linux'); } },
    { re: /^history$/, run(c) { c.history.forEach((h, i) => c.print(String(i + 1).padStart(5) + '  ' + h)); } },
    { re: /^hostname(?:\s+(\S+))?$/, run(c, m) {
      if (!m[1]) { c.print(c.dev.name); return; }
      c.dev.name = m[1].slice(0, 24);
      c.changed();
    } },
    { re: /^(ipconfig|ifconfig)(\s.*)?$/, run(c, m) {
      if (m[1] === 'ipconfig') c.print('ipconfig — команда Windows. Здесь Linux, используй ip a', 'dim');
      else showAddr(c);
    } },
    { re: /^ip\s+(-br\s+)?a(ddr(ess)?)?(\s+show)?(\s+dev\s+\S+)?$/, run(c) { showAddr(c); } },
    { re: /^ip\s+a(?:ddr)?\s+add\s+(\S+)(?:\s+dev\s+(\S+))?$/, run(c, m) {
      const cidr = ip.parseCidr(m[1]);
      if (!cidr) return c.err('Error: формат 192.168.1.10/24');
      const bad = ip.hostError(cidr.ip, cidr.prefix);
      if (bad) return c.err('Error: ' + bad);
      const itf = c.dev.ifaces[0];
      itf.ip = cidr.ip;
      itf.prefix = cidr.prefix;
      c.dev.dhcp = false;
      c.changed();
    } },
    { re: /^ip\s+a(?:ddr)?\s+(?:del\s+\S+|flush)(?:\s+dev\s+\S+)?$/, run(c) { c.dev.ifaces[0].ip = ''; c.dev.dhcp = false; c.changed(); } },
    { re: /^ip\s+r(?:oute)?(\s+show)?$/, run(c) {
      const i = c.dev.ifaces[0];
      if (c.dev.gateway) c.print('default via ' + c.dev.gateway + ' dev ' + i.name + (c.dev.dhcp ? ' proto dhcp' : ''));
      if (i.ip) c.print(ip.netStr(i.ip, i.prefix) + ' dev ' + i.name + ' proto kernel scope link src ' + i.ip);
      if (!i.ip && !c.dev.gateway) c.print('(маршрутов нет: задай адрес)', 'dim');
    } },
    { re: /^ip\s+r(?:oute)?\s+(?:add|replace)\s+default\s+via\s+(\S+)(?:\s+dev\s+\S+)?$/, run(c, m) {
      if (!ip.valid(m[1])) return c.err('Error: неверный адрес шлюза');
      const i = c.dev.ifaces[0];
      if (i.ip && !ip.same(m[1], i.ip, i.prefix)) return c.err('Error: Nexthop has invalid gateway. (' + m[1] + ' не в сети ' + ip.netStr(i.ip, i.prefix) + ')');
      c.dev.gateway = ip.norm(m[1]);
      c.changed();
    } },
    { re: /^ip\s+r(?:oute)?\s+del(?:ete)?\s+default.*$/, run(c) { c.dev.gateway = ''; c.changed(); } },
    { re: /^(?:ip\s+n(?:eigh(?:bour)?)?(?:\s+show)?|arp(?:\s+-[na]+)?)$/, run(c) {
      const rows = Object.entries(c.sim.tables(c.dev).arp);
      if (!rows.length) { c.print('(ARP-таблица пуста)', 'dim'); return; }
      rows.forEach(([a, mac]) => c.print(a + ' dev ' + c.dev.ifaces[0].name + ' lladdr ' + mac + ' REACHABLE'));
    } },
    { re: /^ip\s+link\s+set\s+(?:dev\s+)?\S+\s+(up|down)$/, run(c, m) { c.dev.ifaces[0].down = m[1] === 'down'; c.changed(); } },
    { re: /^ping(?:\s+-c\s*\d+)?\s+(\S+)(?:\s+-c\s*\d+)?$/, run(c, m) { return pingOut(c, m[1]); } },
    { re: /^(?:traceroute|tracepath|tracert)\s+(\S+)$/, run(c, m) {
      c.print('traceroute to ' + m[1] + ', 12 hops max');
      return c.run('tracert', m[1]).then((r) => {
        r.hops.forEach((h) => c.print(String(h.ttl).padStart(2) + '  ' + (h.from ? h.from + '  ' + (0.5 + h.ttl * 0.7).toFixed(1) + ' ms' + (h.unreach ? ' !N' : '') : '* * *' + (h.error ? '   (' + h.error + ')' : '')), h.done ? 'ok' : h.from && !h.unreach ? '' : 'err'));
      });
    } },
    { re: /^dhclient(?:\s+-v)?(?:\s+(?!-r)\S+)?$/, run(c) {
      c.print('DHCPDISCOVER on ' + c.dev.ifaces[0].name + ' to 255.255.255.255 port 67');
      return c.run('dhcp').then((r) => {
        const res = r.results.find((x) => x.type === 'dhcp');
        if (!res) {
          const e = r.results.find((x) => x.type === 'local-err');
          c.err(e ? 'dhclient: ' + e.text : 'No DHCPOFFERS received.');
          if (!e) c.why('DHCP-сервер не ответил: его нет в этом широковещательном домене, служба выключена или на маршрутизаторе нет ip helper-address');
          return;
        }
        c.print('DHCPOFFER of ' + res.ip + ' from ' + res.server);
        c.print('DHCPREQUEST for ' + res.ip + ' on ' + c.dev.ifaces[0].name + ' to 255.255.255.255 port 67');
        c.print('DHCPACK of ' + res.ip + ' from ' + res.server);
        c.print('bound to ' + res.ip + '/' + res.prefix + (res.gateway ? ', шлюз ' + res.gateway : '') + (res.dns ? ', DNS ' + res.dns : ''), 'ok');
      });
    } },
    { re: /^dhclient\s+-r(?:\s+\S+)?$/, run(c) {
      c.dev.ifaces[0].ip = '';
      c.dev.gateway = '';
      c.dev.dns = '';
      c.dev.dhcp = true;
      c.changed();
      c.print('адрес освобождён (DHCPRELEASE)');
    } },
    { re: /^(?:nslookup|host|dig)\s+(\S+)$/, run(c, m) {
      if (ip.valid(m[1])) { c.print('обратные запросы (PTR) в лаборатории не поддерживаются', 'dim'); return; }
      return c.run('dns', m[1]).then((r) => {
        const ok = r.results.find((x) => x.type === 'dns');
        const fail = r.results.find((x) => x.type === 'dns-fail');
        if (fail && fail.reason === 'DNS-сервер не задан') { c.err(';; нет DNS-сервера: задай его в /etc/resolv.conf'); return; }
        c.print('Server:\t\t' + (c.dev.dns || '?'));
        c.print('Address:\t' + (c.dev.dns || '?') + '#53');
        c.print('');
        if (ok) { c.print('Name:\t' + m[1]); c.print('Address: ' + ok.ip, 'ok'); }
        else if (fail) c.err("** server can't find " + m[1] + ': NXDOMAIN');
        else { c.err(';; connection timed out; no servers could be reached'); c.why('DNS-сервер недоступен или служба named на нём не запущена'); }
      });
    } },
    { re: /^(?:curl|wget)(\s+-[a-zA-Z]+)*\s+(\S+)$/, run(c, m) {
      const url = m[2];
      return c.run('http', url).then((r) => {
        const res = r.results.find((x) => x.type === 'http');
        const host = url.replace(/^https?:\/\//, '').split('/')[0];
        if (res) {
          if (/-I/.test(m[0])) { c.print('HTTP/1.1 ' + res.status + (res.status === 200 ? ' OK' : ' Not Found')); c.print('Server: nginx'); c.print('Content-Type: text/html'); return; }
          c.print('<h1>' + res.title + '</h1>', res.status === 200 ? 'ok' : 'err');
          String(res.body).split('\n').forEach((l) => c.print('<p>' + l + '</p>'));
          return;
        }
        const fail = r.results.find((x) => x.type === 'dns-fail');
        if (fail) { c.err('curl: (6) Could not resolve host: ' + host); c.why(fail.reason); return; }
        if (r.results.find((x) => x.type === 'http-refused')) { c.err('curl: (7) Failed to connect to ' + host + ' port 80: Connection refused'); c.why('на сервере не запущен веб-сервер: systemctl start nginx'); return; }
        const e = r.results.find((x) => x.type === 'local-err');
        if (e) { c.err('curl: (7) ' + e.text); return; }
        c.err('curl: (28) Connection timed out');
      });
    } },
    { re: /^ls(?:\s+-\w+)?(?:\s+(\S+))?$/, run(c, m) {
      const dir = (m[1] || '~').replace(/\/$/, '') || '/';
      if (dir === '~' || dir === '/root' || dir === '/home/user') { c.print('(пусто)', 'dim'); return; }
      if (dir === '/') { c.print('etc  var  home  root', 'dir'); return; }
      const list = DIRS[dir];
      if (!list) { c.err("ls: cannot access '" + dir + "': No such file or directory"); return; }
      c.print(list.join('  '));
    } },
    { re: /^cat\s+(\S+)$/, run(c, m) {
      const f = FILES[m[1]];
      if (!f) { c.err('cat: ' + m[1] + ': No such file or directory'); return; }
      f.read(c.dev, c).forEach((l) => c.print(l));
    } },
    { re: /^echo\s+(["']?)(.*?)\1\s*(>>?)\s*(\S+)$/, run(c, m) {
      const f = FILES[m[4]];
      if (!f || !f.write) { c.err('bash: ' + m[4] + ': Permission denied или нет такого файла'); return; }
      const err = f.write(c.dev, m[2], m[3] === '>>');
      if (err) { c.err(m[4] + ': ' + err); return; }
      c.changed();
    } },
    { re: /^echo(?:\s+(.*))?$/, run(c, m) { c.print((m[1] || '').replace(/^["']|["']$/g, '')); } },
    { re: /^(?:sudo\s+)?systemctl\s+(status|start|stop|restart|enable|disable)\s+(\S+)$/, run(c, m) {
      const key = svcByName(m[2]);
      if (!key) { c.err('Unit ' + m[2] + '.service could not be found.'); c.why('есть: named (DNS), isc-dhcp-server (DHCP), nginx (веб)'); return; }
      const sv = c.dev.services[key];
      const meta = SERVICES[key];
      const act = m[1];
      if (act === 'status') {
        c.print([{ text: sv.on ? '● ' : '○ ', cls: sv.on ? 'ok' : 'dim' }, { text: meta.unit + '.service - ' + meta.title }]);
        c.print('     Active: ' + (sv.on ? 'active (running)' : 'inactive (dead)'), sv.on ? 'ok' : 'dim');
        c.print('     Listen: ' + meta.port);
        if (key === 'dhcp' && sv.on && !ip.valid(sv.pool.start)) c.print('     ! пул не настроен: cat /etc/dhcp/dhcpd.conf', 'err');
        if (key === 'dns' && sv.on && !sv.records.length) c.print('     ! в зоне нет записей: cat /etc/bind/db.lab', 'err');
        return;
      }
      if (act === 'start' || act === 'restart' || act === 'enable') sv.on = true;
      if (act === 'stop' || act === 'disable') sv.on = false;
      c.changed();
    } },
    { re: /^sudo\s+(.+)$/, run(c, m) { return c.exec(m[1]); } },
  ];

  function showAddr(c) {
    const i = c.dev.ifaces[0];
    c.print('1: lo: <LOOPBACK,UP> mtu 65536', 'dim');
    c.print('    inet 127.0.0.1/8 scope host lo', 'dim');
    const state = i.down ? 'DOWN' : i.link ? 'UP' : 'NO-CARRIER';
    c.print('2: ' + i.name + ': <BROADCAST,MULTICAST' + (i.down ? '' : ',UP') + '> mtu 1500 state ' + state, LAB.ifUp(i) ? '' : 'err');
    c.print('    link/ether ' + i.mac + ' brd ff:ff:ff:ff:ff:ff');
    if (i.ip) c.print('    inet ' + i.ip + '/' + i.prefix + ' brd ' + ip.str((ip.parse(i.ip) | ~ip.mask(i.prefix)) >>> 0) + ' scope global ' + (c.dev.dhcp ? 'dynamic ' : '') + i.name, 'ok');
    else c.print('    (IPv4-адреса нет: ip addr add … или dhclient)', 'dim');
  }

  LAB.registerShell('linux', (env) => {
    const history = [];
    const isServer = () => env.dev().type === 'server';
    const user = () => (isServer() ? 'root' : 'user');

    function ctxFor(io) {
      const dev = env.dev();
      const c = {
        dev, sim: env.sim(), user: user(), history,
        print: io.print,
        err: (t) => io.print(t, 'err'),
        why: (t) => io.print('# ' + t, 'dim'),
        clear: io.clear,
        changed: env.changed,
        run: (op, arg) => env.run(op, arg),
      };
      c.exec = (line) => exec(line, io);
      return c;
    }

    function exec(line, io) {
      const l = line.trim().replace(/\s+/g, ' ');
      if (!l) return;
      history.push(l);
      for (const cmd of CMDS) {
        const m = l.match(cmd.re);
        if (m) return cmd.run(ctxFor(io), m);
      }
      io.print('bash: ' + l.split(' ')[0] + ': command not found', 'err');
      io.print('# help покажет доступные команды', 'dim');
    }

    const WORDS = ['help', 'ip a', 'ip addr add', 'ip route', 'ip route add default via', 'ip neigh', 'ip link set eth0', 'ping', 'traceroute',
      'dhclient', 'nslookup', 'curl', 'cat', 'echo', 'ls', 'systemctl', 'hostname', 'clear', 'whoami', 'history'];
    const PATHS = Object.keys(FILES).concat(Object.keys(DIRS).map((d) => d + '/'));
    const UNITS = ['named', 'isc-dhcp-server', 'nginx'];

    return {
      title() { return user() + '@' + env.dev().name + ': ~'; },
      banner() {
        const d = env.dev();
        const lines = [{ text: (isServer() ? 'Ubuntu 24.04 LTS (сервер)' : 'Linux ' + d.name + ' 6.8.0-lab x86_64') + ', локальный терминал', cls: 'dim' }];
        if (isServer()) {
          const on = Object.keys(SERVICES).filter((k) => d.services[k].on).map((k) => SERVICES[k].unit);
          lines.push({ text: 'Службы: ' + (on.length ? on.join(', ') : 'ничего не запущено') + '. Управление: systemctl, настройка: cat и echo в конфиги.', cls: 'dim' });
        }
        lines.push({ text: 'Введи help, чтобы увидеть команды. Tab дописывает команды и пути.', cls: 'dim' });
        lines.push({ text: '' });
        return lines;
      },
      prompt() {
        const sym = isServer() ? '# ' : '$ ';
        return [{ text: user() + '@' + env.dev().name, cls: 'host' }, { text: ':' }, { text: '~', cls: 'path' }, { text: sym }];
      },
      exec,
      complete(line) {
        const words = line.split(' ');
        const last = words[words.length - 1];
        let pool;
        if (words.length === 1) pool = WORDS.map((w) => w.split(' ')[0]);
        else if (/^(cat|ls)$/.test(words[0]) || (words[0] === 'echo' && /^>/.test(words[words.length - 2] || ''))) pool = PATHS;
        else if (words[0] === 'systemctl' && words.length === 3) pool = UNITS;
        else if (words[0] === 'systemctl' && words.length === 2) pool = ['status', 'start', 'stop', 'restart'];
        else pool = WORDS.filter((w) => w.startsWith(words.slice(0, -1).join(' ') + ' ')).map((w) => w.split(' ')[words.length - 1]);
        const opts = [...new Set(pool.filter((p) => p && p.startsWith(last)))];
        if (!opts.length) return null;
        const head = words.slice(0, -1).concat('').join(' ');
        if (opts.length === 1) return { line: head + opts[0] + (opts[0].endsWith('/') ? '' : ' ') };
        let common = opts[0];
        opts.forEach((o) => { while (!o.startsWith(common)) common = common.slice(0, -1); });
        if (common.length > last.length) return { line: head + common };
        return { options: opts };
      },
    };
  });
})();
