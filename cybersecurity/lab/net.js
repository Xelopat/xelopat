// Сетевая лаборатория: модель сети и движок симуляции. Здесь нет DOM, только логика.
//
// Что умеет: Ethernet и ARP, коммутаторы с VLAN (access и trunk 802.1Q) и STP, маршрутизаторы
// с подынтерфейсами, статикой, OSPF, NAT/PAT и ACL, узлы с DHCP-клиентом, DNS, HTTP.
//
// Расширение:
//   LAB.registerType({...})     новый тип устройства (types.js); своё поведение задаётся функцией receive
//   LAB.registerPreset({...})   готовая схема (presets.js)
//   LAB.registerTask({...})     учебная задача с проверкой (tasks.js)
(function () {
  const LAB = (window.LAB = window.LAB || {});
  LAB.types = {};
  LAB.presets = [];
  LAB.tasks = [];

  // kind: host | router | switch | hub | своё (тогда нужен receive(sim, tick, dev, ifIdx, frame))
  // layers: [нижний, верхний] уровни OSI, на которых работает устройство
  LAB.registerType = (def) => {
    LAB.types[def.type] = Object.assign({ kind: 'host', layers: [1, 7], ports: ['eth0'], ttl: 64, prefix: def.type.toUpperCase() }, def);
  };
  LAB.registerPreset = (p) => LAB.presets.push(p);
  LAB.registerTask = (t) => LAB.tasks.push(t);

  /* ===== IPv4 ===== */
  const ip = {
    parse(s) {
      const m = String(s == null ? '' : s).trim().match(/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/);
      if (!m) return null;
      const p = m.slice(1).map(Number);
      if (p.some((x) => x > 255)) return null;
      return ((p[0] << 24) >>> 0) + (p[1] << 16) + (p[2] << 8) + p[3];
    },
    str(n) { return [n >>> 24, (n >>> 16) & 255, (n >>> 8) & 255, n & 255].join('.'); },
    valid(s) { return ip.parse(s) != null; },
    norm(s) { const n = ip.parse(s); return n == null ? '' : ip.str(n); },
    mask(prefix) { return prefix === 0 ? 0 : (0xFFFFFFFF << (32 - prefix)) >>> 0; },
    maskStr(prefix) { return ip.str(ip.mask(prefix)); },
    // «24», «/24» или «255.255.255.0» → 24
    parsePrefix(s) {
      s = String(s == null ? '' : s).trim();
      if (/^\/?\d{1,2}$/.test(s)) { const p = Number(s.replace('/', '')); return p <= 32 ? p : null; }
      const n = ip.parse(s);
      if (n == null) return null;
      for (let p = 0; p <= 32; p++) if (ip.mask(p) === n) return p;
      return null;
    },
    net(n, prefix) { return (n & ip.mask(prefix)) >>> 0; },
    same(a, b, prefix) {
      const x = typeof a === 'number' ? a : ip.parse(a);
      const y = typeof b === 'number' ? b : ip.parse(b);
      if (x == null || y == null) return false;
      return ip.net(x, prefix) === ip.net(y, prefix);
    },
    netStr(a, prefix) { return ip.str(ip.net(ip.parse(a), prefix)) + '/' + prefix; },
    // «192.168.1.10/24» или «192.168.1.10 255.255.255.0» → { ip, prefix }
    parseCidr(s) {
      const parts = String(s == null ? '' : s).trim().split(/\s*\/\s*|\s+/);
      if (parts.length !== 2) return null;
      const n = ip.parse(parts[0]);
      const p = ip.parsePrefix(parts[1]);
      if (n == null || p == null) return null;
      return { ip: ip.str(n), prefix: p };
    },
    // Адрес попадает в сеть «a.b.c.d/nn» (или any)
    inCidr(addr, cidr) {
      if (!cidr || cidr === 'any') return true;
      const c = ip.parseCidr(cidr);
      if (!c) return ip.norm(cidr) === addr;
      return ip.same(addr, c.ip, c.prefix);
    },
    // Сколько узлов помещается в сеть с таким префиксом
    hosts(prefix) { return prefix >= 31 ? (prefix === 31 ? 2 : 1) : Math.pow(2, 32 - prefix) - 2; },
    // Можно ли выдать адрес узлу: не адрес сети и не широковещательный
    hostError(addr, prefix) {
      const n = ip.parse(addr);
      if (n == null) return 'неверный IP-адрес';
      if (prefix >= 31) return '';
      const m = ip.mask(prefix);
      if ((n & ~m) >>> 0 === 0) return 'это адрес сети, узлу его выдать нельзя';
      if ((n | ~m) >>> 0 === n) return 'это широковещательный адрес сети';
      return '';
    },
  };
  LAB.ip = ip;

  const BCAST = 'ff:ff:ff:ff:ff:ff';
  const BCAST_IP = '255.255.255.255';
  LAB.BCAST = BCAST;

  function randMac() {
    const b = ['02'];
    for (let i = 0; i < 5; i++) b.push(Math.floor(Math.random() * 256).toString(16).padStart(2, '0'));
    return b.join(':');
  }
  const clone = (o) => JSON.parse(JSON.stringify(o));
  const up = (itf) => !!(itf && itf.link && !itf.down);
  LAB.ifUp = up;

  // Список VLAN «10,20,30-35»; пустая строка или «all» значит все
  function parseVlanList(s) {
    s = String(s == null ? '' : s).trim();
    if (!s || /^(all|все)$/i.test(s)) return null;
    const set = new Set();
    s.split(/[,;\s]+/).forEach((part) => {
      const m = part.match(/^(\d+)(?:-(\d+))?$/);
      if (!m) return;
      const a = Number(m[1]), b = m[2] ? Number(m[2]) : a;
      for (let v = Math.max(1, a); v <= Math.min(4094, b); v++) set.add(v);
    });
    return set;
  }
  LAB.parseVlanList = parseVlanList;
  const trunkAllows = (itf, v) => { const set = parseVlanList(itf.allowed); return !set || set.has(v); };
  // Несёт ли порт коммутатора этот VLAN
  const carries = (itf, v) => (itf.mode === 'trunk' ? trunkAllows(itf, v) : (itf.vlan || 1) === v);
  LAB.carries = carries;

  /* ===== Логические интерфейсы L3 =====
     У маршрутизатора это физический порт и его подынтерфейсы 802.1Q (G0/0.10), у узла один порт.
     { i: номер порта, vlan: 0 или тег, cfg: где лежат ip, prefix, helper, nat, ospf, acl, itf: физический порт, name } */
  function lifs(dev) {
    const kind = LAB.types[dev.type].kind;
    const out = [];
    dev.ifaces.forEach((itf, i) => {
      out.push({ i, vlan: 0, cfg: itf, itf, name: itf.name, key: dev.id + ':' + i });
      if (kind === 'router') (itf.subs || []).forEach((sb) => out.push({ i, vlan: sb.vlan, cfg: sb, itf, name: itf.name + '.' + sb.vlan, key: dev.id + ':' + i + '.' + sb.vlan }));
    });
    return out;
  }
  const lifUp = (l) => up(l.itf) && !l.cfg.down;
  LAB.lifs = lifs;
  LAB.lifUp = lifUp;

  /* ===== ACL ===== */
  const ACL_PROTOS = ['ip', 'icmp', 'tcp', 'udp'];
  LAB.ACL_PROTOS = ACL_PROTOS;
  const l4dst = (p) => (p.proto === 'tcp' ? p.tcp.dport : p.proto === 'udp' ? p.udp.dport : p.icmp ? p.icmp.id : null);
  const l4src = (p) => (p.proto === 'tcp' ? p.tcp.sport : p.proto === 'udp' ? p.udp.sport : p.icmp ? p.icmp.id : null);
  function setDst(p, v) { if (p.proto === 'tcp') p.tcp.dport = v; else if (p.proto === 'udp') p.udp.dport = v; else if (p.icmp) p.icmp.id = v; }
  function setSrc(p, v) { if (p.proto === 'tcp') p.tcp.sport = v; else if (p.proto === 'udp') p.udp.sport = v; else if (p.icmp) p.icmp.id = v; }

  function aclMatch(r, p) {
    if (r.proto !== 'ip' && r.proto !== p.proto) return false;
    if ((r.proto === 'tcp' || r.proto === 'udp') && r.port && l4dst(p) !== r.port) return false;
    return ip.inCidr(p.src, r.src) && ip.inCidr(p.dst, r.dst);
  }
  LAB.aclText = (r) => r.action + ' ' + r.proto + ' ' + (r.src || 'any') + ' ' + (r.dst || 'any') + ((r.proto === 'tcp' || r.proto === 'udp') && r.port ? ' eq ' + r.port : '');
  function normRule(r) {
    return { action: r.action === 'deny' ? 'deny' : 'permit', proto: ACL_PROTOS.includes(r.proto) ? r.proto : 'ip',
      src: String(r.src || 'any'), dst: String(r.dst || 'any'), port: Number(r.port) || 0 };
  }
  LAB.normRule = normRule;

  /* ===== Службы сервера ===== */
  function defaultPool() { return { start: '', end: '', prefix: 24, gateway: '', dns: '' }; }
  function defaultServices() {
    return {
      dns: { on: false, records: [] },
      dhcp: { on: false, pools: [defaultPool()], reservations: [] },
      http: { on: false, title: 'Добро пожаловать', body: 'Эту страницу отдал учебный веб-сервер.' },
    };
  }
  LAB.defaultServices = defaultServices;
  LAB.defaultPool = defaultPool;

  function normServices(js) {
    const sv = defaultServices();
    js = js || {};
    if (js.dns) sv.dns = { on: !!js.dns.on, records: (js.dns.records || []).map((r) => ({ name: String(r.name), ip: String(r.ip) })) };
    if (js.dhcp) {
      // Раньше пул был один (pool), теперь их список (pools): для каждой сети свой
      const pools = Array.isArray(js.dhcp.pools) ? js.dhcp.pools : js.dhcp.pool ? [js.dhcp.pool] : [];
      sv.dhcp = {
        on: !!js.dhcp.on,
        pools: (pools.length ? pools : [defaultPool()]).map((p) => Object.assign(defaultPool(), p, { prefix: p.prefix == null ? 24 : Number(p.prefix) })),
        reservations: (js.dhcp.reservations || []).map((r) => ({ mac: String(r.mac), ip: String(r.ip) })),
      };
    }
    if (js.http) Object.assign(sv.http, js.http, { on: !!js.http.on });
    return sv;
  }

  function normL3(o, src) {
    o.nat = src.nat === 'inside' || src.nat === 'outside' ? src.nat : '';
    o.ospf = !!src.ospf;
    o.aclIn = (src.aclIn || []).map(normRule);
    o.aclOut = (src.aclOut || []).map(normRule);
    return o;
  }
  function normIface(i, kind) {
    const o = {
      name: String(i.name), mac: String(i.mac || randMac()), ip: String(i.ip || ''),
      prefix: i.prefix == null ? 24 : Number(i.prefix), vlan: Number(i.vlan) || 1,
      down: !!i.down, helper: String(i.helper || ''), link: i.link || null,
    };
    if (i.desc) o.desc = String(i.desc);
    if (kind === 'switch') {
      o.mode = i.mode === 'trunk' ? 'trunk' : 'access';
      o.allowed = String(i.allowed || '');
      o.native = Number(i.native) || 1;
    }
    if (kind === 'router') {
      normL3(o, i);
      o.subs = (i.subs || []).map((sb) => normL3({ vlan: Number(sb.vlan) || 1, ip: String(sb.ip || ''), prefix: sb.prefix == null ? 24 : Number(sb.prefix), helper: String(sb.helper || ''), down: !!sb.down }, sb));
    }
    return o;
  }
  LAB.normSub = (sb) => normL3({ vlan: Number(sb.vlan) || 1, ip: String(sb.ip || ''), prefix: sb.prefix == null ? 24 : Number(sb.prefix), helper: String(sb.helper || ''), down: !!sb.down }, sb);

  function normDevice(d, t) {
    const dev = {
      id: String(d.id), type: d.type, name: String(d.name || d.id),
      x: Number(d.x) || 0, y: Number(d.y) || 0,
      gateway: String(d.gateway || ''),
      dns: String(d.dns || ''),
      dhcp: !!d.dhcp,
      routes: Array.isArray(d.routes) ? d.routes.map((r) => ({ net: String(r.net), prefix: Number(r.prefix), via: String(r.via) })) : [],
      services: normServices(d.services),
      ifaces: (d.ifaces || []).map((i) => normIface(i, t.kind)),
    };
    if (t.kind === 'switch') dev.stp = { on: d.stp ? d.stp.on !== false : true, priority: d.stp && d.stp.priority != null ? Number(d.stp.priority) : 32768 };
    if (t.kind === 'router') {
      dev.ospfDef = !!d.ospfDef;
      dev.natStatic = (d.natStatic || []).map((s) => ({ proto: s.proto === 'udp' ? 'udp' : 'tcp', port: Number(s.port) || 0, ip: String(s.ip || ''), lport: Number(s.lport) || Number(s.port) || 0 }));
    }
    return dev;
  }

  /* ===== Модель сети ===== */
  class Net {
    constructor() {
      this.devices = [];
      this.links = [];
      this.seq = 1;
    }

    static from(json) {
      const net = new Net();
      if (!json || !Array.isArray(json.devices) || !Array.isArray(json.links)) throw new Error('нет devices или links');
      net.seq = Number(json.seq) || 1;
      json.devices.forEach((d) => {
        const t = LAB.types[d.type];
        if (!t) throw new Error('неизвестный тип устройства: ' + d.type);
        net.devices.push(normDevice(d, t));
      });
      net.reindex();
      json.links.forEach((l) => {
        if (net.dev(l.a.dev) && net.dev(l.b.dev)) net.links.push({ id: String(l.id), a: { dev: l.a.dev, i: Number(l.a.i) }, b: { dev: l.b.dev, i: Number(l.b.i) } });
      });
      net.reindex();
      net.devices.forEach((d) => d.ifaces.forEach((i) => { if (i.link && !net.link(i.link)) i.link = null; }));
      return net;
    }

    toJSON() {
      return { v: 3, seq: this.seq, devices: this.devices, links: this.links };
    }

    // Индексы по id: на больших схемах (тысячи устройств) поиск перебором тормозит
    reindex() { this._dm = null; this._lm = null; }
    dev(id) {
      if (!this._dm) this._dm = new Map(this.devices.map((d) => [d.id, d]));
      return this._dm.get(id) || null;
    }
    link(id) {
      if (!this._lm) this._lm = new Map(this.links.map((l) => [l.id, l]));
      return this._lm.get(id) || null;
    }
    byName(name) { return this.devices.find((d) => d.name.toLowerCase() === String(name).toLowerCase()) || null; }
    type(dev) { return LAB.types[dev.type]; }
    byIp(addr) { return this.devices.find((d) => lifs(d).some((l) => l.cfg.ip === addr)) || null; }

    uniqueName(prefix) {
      for (let n = 1; ; n++) if (!this.devices.some((d) => d.name === prefix + n)) return prefix + n;
    }

    add(type, x, y) {
      const t = LAB.types[type];
      if (!t) return null;
      const dev = normDevice({
        id: 'd' + this.seq++, type, name: this.uniqueName(t.prefix), x: Math.round(x), y: Math.round(y),
        ifaces: t.ports.map((name) => ({ name })),
      }, t);
      this.devices.push(dev);
      this.reindex();
      return dev;
    }

    remove(id) {
      this.links.filter((l) => l.a.dev === id || l.b.dev === id).forEach((l) => this.disconnect(l.id));
      this.devices = this.devices.filter((d) => d.id !== id);
      this.reindex();
    }

    // Соединить два устройства: порт можно указать по имени, иначе берётся первый свободный
    connect(aId, bId, aPort, bPort) {
      if (aId === bId) return { error: 'Нельзя соединить устройство само с собой' };
      const a = this.dev(aId), b = this.dev(bId);
      if (!a || !b) return { error: 'Устройство не найдено' };
      const pick = (d, port) => d.ifaces.findIndex((i) => !i.link && (port == null || i.name.toLowerCase() === String(port).toLowerCase()));
      const ai = pick(a, aPort), bi = pick(b, bPort);
      if (ai < 0) return { error: a.name + ': нет свободного порта' };
      if (bi < 0) return { error: b.name + ': нет свободного порта' };
      const link = { id: 'l' + this.seq++, a: { dev: a.id, i: ai }, b: { dev: b.id, i: bi } };
      a.ifaces[ai].link = link.id;
      b.ifaces[bi].link = link.id;
      this.links.push(link);
      this.reindex();
      return { link };
    }

    disconnect(linkId) {
      const l = this.link(linkId);
      if (!l) return;
      [l.a, l.b].forEach((end) => { const d = this.dev(end.dev); if (d && d.ifaces[end.i]) d.ifaces[end.i].link = null; });
      this.links = this.links.filter((x) => x.id !== linkId);
      this.reindex();
    }

    // Что на другом конце кабеля (any: вернуть даже если интерфейс выключен)
    peer(devId, ifIdx, any) {
      const d = this.dev(devId);
      const l = d && d.ifaces[ifIdx] && this.link(d.ifaces[ifIdx].link);
      if (!l) return null;
      const end = l.a.dev === devId && l.a.i === ifIdx ? l.b : l.a;
      const pd = this.dev(end.dev);
      if (!any && (d.ifaces[ifIdx].down || pd.ifaces[end.i].down)) return null;
      return { dev: pd, ifIdx: end.i, link: l };
    }

    linkDown(l) {
      const a = this.dev(l.a.dev), b = this.dev(l.b.dev);
      return !!(a.ifaces[l.a.i].down || b.ifaces[l.b.i].down);
    }

    /* --- STP (802.1D, одно дерево на все VLAN) ---
       Корень: наименьший приоритет, при равенстве наименьший MAC. У каждого другого коммутатора
       корневой порт ведёт к корню кратчайшим путём; на каждом кабеле между коммутаторами один конец
       назначенный, а если ни один конец не корневой, худший конец блокируется. */
    stpInfo() {
      const isStp = (d) => d && this.type(d).kind === 'switch' && d.stp && d.stp.on;
      const sws = this.devices.filter(isStp);
      const role = {};
      const blocked = new Set();
      const bridge = {};
      if (!sws.length) return { blocked, role, bridge };
      const bid = (d) => [d.stp.priority, d.ifaces[0] ? d.ifaces[0].mac : d.id];
      const better = (a, b) => a[0] !== b[0] ? a[0] < b[0] : a[1] < b[1];
      const edges = this.links.filter((l) => !this.linkDown(l) && isStp(this.dev(l.a.dev)) && isStp(this.dev(l.b.dev)));
      const adj = {};
      edges.forEach((l) => {
        (adj[l.a.dev] = adj[l.a.dev] || []).push({ me: l.a.i, nb: l.b.dev, nbi: l.b.i, l });
        (adj[l.b.dev] = adj[l.b.dev] || []).push({ me: l.b.i, nb: l.a.dev, nbi: l.a.i, l });
      });
      // Компоненты связности: в каждой свой корень
      const comp = {};
      sws.forEach((s) => {
        if (comp[s.id]) return;
        const list = [s.id];
        comp[s.id] = list;
        for (let k = 0; k < list.length; k++) (adj[list[k]] || []).forEach((e) => { if (!comp[e.nb]) { comp[e.nb] = list; list.push(e.nb); } });
      });
      const dist = {}, rootPort = {}, rootOf = {};
      new Set(Object.values(comp)).forEach((list) => {
        const root = list.map((id) => this.dev(id)).reduce((a, b) => (better(bid(b), bid(a)) ? b : a));
        list.forEach((id) => { dist[id] = Infinity; rootOf[id] = root.id; });
        dist[root.id] = 0;
        // Стоимость каждого канала 19 (FastEthernet), как в классическом STP
        const todo = new Set(list);
        while (todo.size) {
          let cur = null;
          todo.forEach((id) => { if (cur == null || dist[id] < dist[cur]) cur = id; });
          todo.delete(cur);
          if (dist[cur] === Infinity) break;
          (adj[cur] || []).forEach((e) => { if (dist[cur] + 19 < dist[e.nb]) dist[e.nb] = dist[cur] + 19; });
        }
        list.forEach((id) => {
          if (id === root.id) return;
          let best = null;
          (adj[id] || []).forEach((e) => {
            if (e.nb === id) return;
            const nb = this.dev(e.nb);
            const cand = { e, key: [dist[e.nb] + 19, bid(nb)[0], bid(nb)[1], e.nbi, e.me] };
            if (!best) { best = cand; return; }
            const a = cand.key, b = best.key;
            for (let k = 0; k < a.length; k++) { if (a[k] !== b[k]) { if (a[k] < b[k]) best = cand; break; } }
          });
          if (best) rootPort[id] = best.e.me;
        });
      });
      sws.forEach((s) => {
        bridge[s.id] = { root: rootOf[s.id], cost: dist[s.id], isRoot: rootOf[s.id] === s.id, rootPort: rootPort[s.id] };
        s.ifaces.forEach((itf, i) => { if (up(itf)) role[s.id + ':' + i] = 'desig'; });
      });
      edges.forEach((l) => {
        const A = this.dev(l.a.dev), B = this.dev(l.b.dev);
        const ka = A.id + ':' + l.a.i, kb = B.id + ':' + l.b.i;
        if (A.id === B.id) {
          const worse = l.a.i > l.b.i ? ka : kb;
          role[worse] = 'blocked';
          blocked.add(worse);
          return;
        }
        if (rootPort[A.id] === l.a.i) { role[ka] = 'root'; return; }
        if (rootPort[B.id] === l.b.i) { role[kb] = 'root'; return; }
        const va = [dist[A.id], bid(A)[0], bid(A)[1], l.a.i], vb = [dist[B.id], bid(B)[0], bid(B)[1], l.b.i];
        let aWins = true;
        for (let k = 0; k < va.length; k++) { if (va[k] !== vb[k]) { aWins = va[k] < vb[k]; break; } }
        const loser = aWins ? kb : ka;
        role[loser] = 'blocked';
        blocked.add(loser);
      });
      return { blocked, role, bridge };
    }

    // Широковещательные домены с учётом VLAN, транков, подынтерфейсов и STP.
    // Возвращает домен для каждого кабеля (по нетегированному трафику), теги на транках и сводку по доменам.
    domains(stp) {
      stp = stp || this.stpInfo();
      const parent = {};
      const find = (k) => { while (parent[k] !== k) { parent[k] = parent[parent[k]]; k = parent[k]; } return k; };
      const add = (k) => { if (!(k in parent)) parent[k] = k; return k; };
      const union = (a, b) => { parent[find(add(a))] = find(add(b)); };

      // Какие VLAN вообще встречаются: нужны, чтобы раскрыть транки «все VLAN»
      const all = new Set([1]);
      this.devices.forEach((d) => d.ifaces.forEach((i) => {
        if (i.vlan) all.add(i.vlan);
        if (i.native) all.add(i.native);
        const set = parseVlanList(i.allowed);
        if (set && set.size < 200) set.forEach((v) => all.add(v));
        (i.subs || []).forEach((sb) => all.add(sb.vlan));
      }));
      // Что видно на конце кабеля: тег (0 = без тега) → ключ сегмента
      const att = (devId, i) => {
        const d = this.dev(devId);
        const kind = this.type(d).kind;
        const itf = d.ifaces[i];
        if (kind === 'hub') return { hub: d.id };
        if (kind === 'switch') {
          if (itf.mode === 'trunk') {
            const nat = itf.native || 1;
            const m = { 0: d.id + ':v' + nat };
            all.forEach((v) => { if (v !== nat && trunkAllows(itf, v)) m[v] = d.id + ':v' + v; });
            return m;
          }
          return { 0: d.id + ':v' + (itf.vlan || 1) };
        }
        const m = { 0: d.id + ':' + i };
        if (kind === 'router') (itf.subs || []).forEach((sb) => { m[sb.vlan] = d.id + ':' + i + '.' + sb.vlan; });
        return m;
      };
      const linkPairs = {};
      this.links.forEach((l) => {
        const A = att(l.a.dev, l.a.i), B = att(l.b.dev, l.b.i);
        const pairs = [];
        if (A.hub && B.hub) [0, ...all].forEach((t) => pairs.push([t, A.hub + ':' + t, B.hub + ':' + t]));
        else if (A.hub) Object.entries(B).forEach(([t, k]) => pairs.push([Number(t), A.hub + ':' + t, k]));
        else if (B.hub) Object.entries(A).forEach(([t, k]) => pairs.push([Number(t), k, B.hub + ':' + t]));
        else Object.entries(A).forEach(([t, k]) => { if (B[t]) pairs.push([Number(t), k, B[t]]); });
        linkPairs[l.id] = pairs;
        const cut = this.linkDown(l) || stp.blocked.has(l.a.dev + ':' + l.a.i) || stp.blocked.has(l.b.dev + ':' + l.b.i);
        pairs.forEach(([, a, b]) => { if (cut) { add(a); add(b); } else union(a, b); });
        if (!pairs.length) { add(l.id + ':a'); }
      });

      const roots = [];
      const idx = (key) => {
        if (!(key in parent)) return -1;
        const r = find(key);
        let n = roots.indexOf(r);
        if (n < 0) { roots.push(r); n = roots.length - 1; }
        return n;
      };
      const linkDomain = {};
      const linkTags = {};
      this.links.forEach((l) => {
        const pairs = linkPairs[l.id];
        const p0 = pairs.find((p) => p[0] === 0) || pairs[0];
        linkDomain[l.id] = p0 ? idx(p0[1]) : idx(l.id + ':a');
        linkTags[l.id] = pairs.filter((p) => p[0] !== 0).map((p) => p[0]);
      });
      Object.keys(parent).forEach(idx);

      const info = roots.map(() => ({ nets: new Set(), vlans: new Set(), hosts: 0 }));
      Object.keys(parent).forEach((k) => {
        const m = k.match(/:v(\d+)$/);
        if (m) info[idx(k)].vlans.add(Number(m[1]));
      });
      this.devices.forEach((d) => {
        const kind = this.type(d).kind;
        if (kind !== 'host' && kind !== 'router') return;
        lifs(d).forEach((l) => {
          if (!l.cfg.ip) return;
          const n = idx(l.key);
          if (n < 0) return;
          info[n].nets.add(ip.netStr(l.cfg.ip, l.cfg.prefix));
          info[n].hosts++;
        });
      });
      return { linkDomain, linkTags, info, keyDom: idx, stp };
    }

    /* --- OSPF (одна область 0) ---
       Соседи: интерфейсы с включённым OSPF в одной подсети и одном широковещательном домене.
       Каждый маршрутизатор объявляет сети своих OSPF-интерфейсов; маршрут строится по кратчайшему
       пути (метрика: число переходов, стоимость каждого канала 1). */
    ospf(dom) {
      dom = dom || this.domains();
      const routers = this.devices.filter((d) => this.type(d).kind === 'router');
      const lifsOf = {};
      routers.forEach((r) => { lifsOf[r.id] = lifs(r).filter((l) => l.cfg.ospf && l.cfg.ip && lifUp(l)); });
      const nbrs = {};
      routers.forEach((r) => { nbrs[r.id] = []; });
      routers.forEach((r) => lifsOf[r.id].forEach((a) => {
        const da = dom.keyDom(a.key);
        if (da < 0) return;
        routers.forEach((o) => {
          if (o === r) return;
          lifsOf[o.id].forEach((b) => {
            if (b.cfg.prefix === a.cfg.prefix && ip.same(a.cfg.ip, b.cfg.ip, a.cfg.prefix) && dom.keyDom(b.key) === da) {
              nbrs[r.id].push({ dev: o, ip: b.cfg.ip, lif: a, their: b });
            }
          });
        });
      }));
      const adv = {};
      routers.forEach((r) => {
        adv[r.id] = lifsOf[r.id].map((l) => ({ net: ip.str(ip.net(ip.parse(l.cfg.ip), l.cfg.prefix)), prefix: l.cfg.prefix }));
        if (r.ospfDef && r.routes.some((x) => x.prefix === 0)) adv[r.id].push({ net: '0.0.0.0', prefix: 0, ext: true });
      });
      const routes = {};
      routers.forEach((s) => {
        routes[s.id] = [];
        if (!lifsOf[s.id].length) return;
        const dist = { [s.id]: 0 }, first = {};
        const queue = [s.id];
        for (let k = 0; k < queue.length; k++) {
          const cur = queue[k];
          nbrs[cur].forEach((n) => {
            if (dist[n.dev.id] != null) return;
            dist[n.dev.id] = dist[cur] + 1;
            first[n.dev.id] = cur === s.id ? n : first[cur];
            queue.push(n.dev.id);
          });
        }
        const best = {};
        const own = lifs(s).filter((l) => l.cfg.ip && lifUp(l));
        queue.slice(1).forEach((tid) => adv[tid].forEach((a) => {
          if (a.prefix > 0 && own.some((l) => l.cfg.prefix === a.prefix && ip.same(l.cfg.ip, a.net, a.prefix))) return;
          const key = a.net + '/' + a.prefix;
          const cost = dist[tid] + (a.ext ? 0 : 1);
          if (best[key] && best[key].cost <= cost) return;
          const f = first[tid];
          best[key] = { net: a.net, prefix: a.prefix, via: f.ip, lif: f.lif, cost, from: tid, ext: !!a.ext };
        }));
        routes[s.id] = Object.values(best);
      });
      return { routes, nbrs };
    }
  }
  LAB.Net = Net;

  /* ===== Подписи пакетов ===== */
  const DHCP_OP = { discover: 'DISCOVER', offer: 'OFFER', request: 'REQUEST', ack: 'ACK', nak: 'NAK' };
  LAB.DHCP_OP = DHCP_OP;

  function describe(f) {
    const tag = f.vlan ? ' [VLAN ' + f.vlan + ']' : '';
    if (f.type === 'arp') {
      return f.arp.op === 'request'
        ? { kind: 'arp', label: 'ARP-запрос: у кого ' + f.arp.tip + '?' + tag }
        : { kind: 'arp', label: 'ARP-ответ: ' + f.arp.sip + ' это ' + f.arp.smac + tag };
    }
    const p = f.ip;
    const app = p.app;
    let d;
    if (app && app.type === 'dhcp') d = { kind: 'dhcp', label: 'DHCP ' + DHCP_OP[app.op] + (app.yiaddr ? ': ' + app.yiaddr : '') };
    else if (app && app.type === 'dns') {
      d = app.op === 'query'
        ? { kind: 'dns', label: 'DNS-запрос: ' + app.name + '?' }
        : { kind: 'dns', label: 'DNS-ответ: ' + app.name + ' = ' + (app.answer || 'не найдено') };
    } else if (app && app.type === 'http') {
      d = app.op === 'request'
        ? { kind: 'http', label: 'HTTP GET ' + app.path }
        : { kind: 'http', label: 'HTTP ' + app.status + ' ' + (app.status === 200 ? 'OK' : 'Not Found') };
    } else if (p.proto === 'tcp') d = { kind: 'tcp', label: 'TCP [' + p.tcp.flags + '] ' + p.src + ':' + p.tcp.sport + ' → ' + p.dst + ':' + p.tcp.dport };
    else {
      const t = p.icmp && p.icmp.type;
      if (t === 'echo-request') d = { kind: 'req', label: 'ICMP эхо-запрос ' + p.src + ' → ' + p.dst + ', TTL ' + p.ttl };
      else if (t === 'echo-reply') d = { kind: 'rep', label: 'ICMP эхо-ответ ' + p.src + ' → ' + p.dst + ', TTL ' + p.ttl };
      else if (t === 'time-exceeded') d = { kind: 'err', label: 'ICMP: TTL истёк (' + p.src + ' → ' + p.dst + ')' };
      else if (t === 'unreachable') d = { kind: 'err', label: 'ICMP: ' + (p.icmp.code === 'admin' ? 'запрещено ACL' : 'недоступно') + ' (' + p.src + ' → ' + p.dst + ')' };
      else d = { kind: 'req', label: 'IP ' + p.src + ' → ' + p.dst };
    }
    d.label += tag;
    return d;
  }
  LAB.describe = describe;

  /* ===== Симуляция =====
     Работает событиями по тактам: кадр, отправленный на такте t, приходит на такте t+1.
     events: { type: 'hop', tick, link, from, to, frame, label, kind }
             { type: 'log', tick, dev, layer, text, level: info|ok|err|dim, frame? }
     ARP- и MAC-таблицы, аренды DHCP и трансляции NAT живут между запусками (reset() их чистит). */
  const STORM_LIMIT = 1200;

  class Sim {
    constructor(net) {
      this.net = net;
      this.rt = {};
      this.pid = 0;
    }

    reset() { this.rt = {}; this.cache = null; }

    tables(dev) { return this.rt[dev.id] || (this.rt[dev.id] = { arp: {}, mac: {}, leases: {}, conns: {}, nat: {} }); }

    // STP, домены и OSPF зависят только от схемы: считаем один раз на запуск
    topo() {
      if (!this.cache) {
        const stp = this.net.stpInfo();
        const dom = this.net.domains(stp);
        this.cache = { stp, dom, ospf: this.net.ospf(dom) };
      }
      return this.cache;
    }

    refreshTopo() { this.cache = null; return this.topo(); }

    begin(originId) {
      this.cache = null;
      this.events = [];
      this.queue = [];
      this.pending = {};
      this.results = [];
      this.flows = {};
      this.count = 0;
      this.storm = false;
      this.lastTick = 0;
      this.origin = originId;
    }

    log(tick, dev, layer, text, level, frame) {
      this.events.push({ type: 'log', tick, dev: dev ? dev.id : null, layer, text, level: level || 'info', frame: frame || null });
    }

    // Отложить действие на следующий такт (например, ACK и сразу за ним HTTP-запрос)
    later(tick, fn) { this.queue.push({ tick: tick + 1, fn }); }

    send(tick, dev, ifIdx, frame) {
      const p = this.net.peer(dev.id, ifIdx);
      if (!p) {
        const itf = dev.ifaces[ifIdx];
        this.log(tick, dev, 1, itf.name + (itf.down ? ': интерфейс выключен (shutdown)' : itf.link ? ': порт на другом конце выключен' : ': кабель не подключён') + ', кадр потерян', 'err');
        return;
      }
      const f = clone(frame);
      const d = describe(f);
      this.events.push({ type: 'hop', tick, link: p.link.id, from: dev.id, to: p.dev.id, frame: f, label: d.label, kind: d.kind });
      this.queue.push({ tick: tick + 1, dev: p.dev, ifIdx: p.ifIdx, frame: f });
    }

    // Отправить кадр через логический интерфейс: у подынтерфейса кадр получает тег 802.1Q
    lifSend(tick, dev, lif, frame) {
      if (lif.vlan) frame.vlan = lif.vlan;
      else delete frame.vlan;
      this.send(tick, dev, lif.i, frame);
    }

    drain() {
      while (this.queue.length) {
        const q = this.queue.shift();
        this.lastTick = q.tick;
        if (q.fn) { q.fn(q.tick); continue; }
        if (++this.count > STORM_LIMIT) {
          this.storm = true;
          this.queue = [];
          this.log(q.tick, null, 2, 'Широковещательный шторм: кадры бесконечно ходят по кольцу. В сети петля, а STP выключен. Симуляция остановлена.', 'err');
          break;
        }
        this.receive(q.tick, q.dev, q.ifIdx, q.frame);
      }
      Object.values(this.pending).forEach((p) => {
        this.log(this.lastTick + 1, p.dev, 2, 'ARP: ' + p.nh + ' не ответил, пакеты отброшены', 'err');
        if (p.dev.id === this.origin) this.results.push({ type: 'arp-fail', dev: p.dev.id, nh: p.nh });
      });
      this.pending = {};
    }

    receive(tick, dev, ifIdx, frame) {
      const t = this.net.type(dev);
      if (typeof t.receive === 'function') return t.receive(this, tick, dev, ifIdx, frame);
      if (t.kind === 'hub') return this.hubReceive(tick, dev, ifIdx, frame);
      if (t.kind === 'switch') return this.switchReceive(tick, dev, ifIdx, frame);
      return this.l3Receive(tick, dev, ifIdx, frame);
    }

    /* --- L1: концентратор --- */
    hubReceive(tick, dev, ifIdx, frame) {
      const out = dev.ifaces.map((p, i) => i).filter((i) => i !== ifIdx && up(dev.ifaces[i]));
      this.log(tick, dev, 1, 'повторяю сигнал во все порты (' + out.length + '), адресов не смотрю', 'info', frame);
      out.forEach((i) => this.send(tick, dev, i, frame));
    }

    /* --- L2: коммутатор --- */
    switchReceive(tick, dev, ifIdx, frame) {
      const tb = this.tables(dev);
      const inIf = dev.ifaces[ifIdx];
      const stp = this.topo().stp;
      if (stp.blocked.has(dev.id + ':' + ifIdx)) {
        this.log(tick, dev, 2, inIf.name + ' заблокирован STP: кадр отброшен, петли нет', 'dim', frame);
        return;
      }
      let vlan;
      if (inIf.mode === 'trunk') {
        const nat = inIf.native || 1;
        vlan = frame.vlan || nat;
        if (!trunkAllows(inIf, vlan)) {
          this.log(tick, dev, 2, 'VLAN ' + vlan + ' не разрешён на транке ' + inIf.name + ': кадр отброшен', 'err', frame);
          return;
        }
      } else {
        if (frame.vlan) {
          this.log(tick, dev, 2, 'кадр с тегом VLAN ' + frame.vlan + ' пришёл в access-порт ' + inIf.name + ': отбрасываю (нужен trunk)', 'err', frame);
          return;
        }
        vlan = inIf.vlan || 1;
      }
      const srcKey = vlan + '|' + frame.src;
      if (tb.mac[srcKey] !== ifIdx) {
        tb.mac[srcKey] = ifIdx;
        this.log(tick, dev, 2, 'запомнил: ' + frame.src + ' за портом ' + inIf.name + ' (VLAN ' + vlan + ')', 'dim', frame);
      }
      const out = (i) => {
        const itf = dev.ifaces[i];
        const f = Object.assign({}, frame);
        if (itf.mode === 'trunk' && vlan !== (itf.native || 1)) f.vlan = vlan;
        else delete f.vlan;
        this.send(tick, dev, i, f);
      };
      const known = tb.mac[vlan + '|' + frame.dst];
      if (frame.dst === BCAST || known == null) {
        const ports = dev.ifaces.map((p, i) => i).filter((i) => i !== ifIdx && up(dev.ifaces[i]) && !stp.blocked.has(dev.id + ':' + i) && carries(dev.ifaces[i], vlan));
        this.log(tick, dev, 2, (frame.dst === BCAST ? 'широковещательный кадр' : 'MAC ' + frame.dst + ' пока не знаю') +
          ': рассылаю во все порты VLAN ' + vlan + ' (' + ports.length + ')', 'info', frame);
        ports.forEach(out);
        return;
      }
      if (known === ifIdx) {
        this.log(tick, dev, 2, 'получатель за тем же портом, кадр дальше не идёт', 'dim', frame);
        return;
      }
      const tagNote = dev.ifaces[known].mode === 'trunk' && vlan !== (dev.ifaces[known].native || 1) ? ' с тегом VLAN ' + vlan : '';
      this.log(tick, dev, 2, frame.dst + ' за портом ' + dev.ifaces[known].name + ': пересылаю только туда' + tagNote, 'info', frame);
      out(known);
    }

    /* --- L3: узлы и маршрутизаторы --- */
    lifs(dev) { return lifs(dev); }
    isMine(dev, addr) { return lifs(dev).some((l) => l.cfg.ip && l.cfg.ip === addr); }
    lifByIp(dev, addr) { return lifs(dev).find((l) => l.cfg.ip === addr) || null; }

    route(dev, dst) {
      const kind = this.net.type(dev).kind;
      if (kind !== 'router') {
        const lif = lifs(dev).find((l) => l.cfg.ip);
        if (!lif) return { error: 'на интерфейсе не задан IP-адрес' };
        const c = lif.cfg;
        if (ip.same(dst, c.ip, c.prefix)) return { lif, nh: dst };
        if (!dev.gateway) return { error: dst + ' в другой сети, а шлюз по умолчанию не задан' };
        if (!ip.same(dev.gateway, c.ip, c.prefix)) return { error: 'шлюз ' + dev.gateway + ' не в сети интерфейса ' + ip.netStr(c.ip, c.prefix) };
        return { lif, nh: dev.gateway, via: true };
      }
      const r = this.routerLookup(dev, dst);
      return r || { error: 'нет маршрута до ' + dst };
    }

    // Все маршруты маршрутизатора: подключённые (C), статические (S), OSPF (O)
    routes(dev) {
      const all = lifs(dev).filter((l) => l.cfg.ip && lifUp(l));
      const list = [];
      all.forEach((l) => list.push({ code: 'C', ad: 0, net: ip.str(ip.net(ip.parse(l.cfg.ip), l.cfg.prefix)), prefix: l.cfg.prefix, lif: l, nh: null }));
      dev.routes.forEach((r, idx) => {
        const l = all.find((x) => ip.same(r.via, x.cfg.ip, x.cfg.prefix));
        list.push({ code: 'S', ad: 1, net: r.net, prefix: r.prefix, lif: l || null, nh: r.via, idx });
      });
      const o = this.topo().ospf.routes[dev.id] || [];
      o.forEach((r) => list.push({ code: 'O', ad: 110, net: r.net, prefix: r.prefix, lif: r.lif, nh: r.via, cost: r.cost, ext: r.ext }));
      return list;
    }

    // Самый длинный префикс; при равенстве побеждает меньшая административная дистанция (C, затем S, затем O)
    routerLookup(dev, dst) {
      let best = null;
      this.routes(dev).forEach((r) => {
        if (!r.lif || !ip.same(dst, r.net, r.prefix)) return;
        if (best && (r.prefix < best.prefix || (r.prefix === best.prefix && r.ad >= best.ad))) return;
        best = r;
      });
      if (!best) return null;
      return { prefix: best.prefix, lif: best.lif, nh: best.code === 'C' ? dst : best.nh, code: best.code, via: best.code !== 'C' };
    }

    // ACL на интерфейсе: null если пакет пропускается, иначе правило, которое его остановило
    aclDeny(lif, dir, pkt) {
      const list = dir === 'in' ? lif.cfg.aclIn : lif.cfg.aclOut;
      if (!list || !list.length || !pkt.src) return null;
      for (const r of list) if (aclMatch(r, pkt)) return r.action === 'deny' ? r : null;
      return { implicit: true };
    }

    aclDrop(tick, dev, lif, dir, rule, pkt, frame) {
      const txt = rule.implicit ? 'ни одно правило не подошло, в конце списка неявный deny any' : 'правило «' + LAB.aclText(rule) + '»';
      this.log(tick, dev, 3, 'ACL ' + (dir === 'in' ? 'на входе' : 'на выходе') + ' ' + lif.name + ': пакет ' + pkt.src + ' → ' + pkt.dst + ' запрещён, ' + txt, 'err', frame);
      const isErr = pkt.icmp && (pkt.icmp.type === 'unreachable' || pkt.icmp.type === 'time-exceeded');
      if (!isErr && pkt.src !== '0.0.0.0') {
        this.ipSend(tick, dev, { src: null, dst: pkt.src, ttl: this.net.type(dev).ttl, proto: 'icmp', icmp: { type: 'unreachable', code: 'admin', id: pkt.icmp ? pkt.icmp.id : undefined } });
      }
    }

    ipSend(tick, dev, pkt) {
      const r = this.route(dev, pkt.dst);
      if (r.error) {
        this.log(tick, dev, 3, r.error + ', пакет отброшен', 'err');
        if (pkt.origin === dev.id && dev.id === this.origin) this.results.push({ type: 'local-err', text: r.error });
        const isEcho = pkt.icmp && (pkt.icmp.type === 'echo-request' || pkt.icmp.type === 'echo-reply');
        if (pkt.fwd && (isEcho || pkt.proto !== 'icmp') && pkt.src && !this.isMine(dev, pkt.src)) {
          this.ipSend(tick, dev, { src: null, dst: pkt.src, ttl: this.net.type(dev).ttl, proto: 'icmp', icmp: { type: 'unreachable', id: pkt.icmp ? pkt.icmp.id : undefined } });
        }
        return;
      }
      if (pkt.fwd && this.net.type(dev).kind === 'router') {
        const deny = this.aclDeny(r.lif, 'out', pkt);
        if (deny) { this.aclDrop(tick, dev, r.lif, 'out', deny, pkt); return; }
        if (pkt.natIn && r.lif.cfg.nat === 'outside') this.natOut(tick, dev, r.lif, pkt);
      }
      delete pkt.natIn;
      delete pkt.fwd;
      this.l2Out(tick, dev, r.lif, r.nh, pkt);
    }

    // Отправить без маршрутизации и ARP: на известный MAC или широковещательно (DHCP)
    rawSend(tick, dev, lif, dstMac, pkt) {
      this.lifSend(tick, dev, lif, { src: lif.itf.mac, dst: dstMac, type: 'ipv4', ip: pkt });
    }

    l2Out(tick, dev, lif, nh, pkt) {
      if (!pkt.src) pkt.src = lif.cfg.ip;
      const mac = this.tables(dev).arp[nh];
      if (mac) {
        this.lifSend(tick, dev, lif, { src: lif.itf.mac, dst: mac, type: 'ipv4', ip: pkt });
        return;
      }
      const key = dev.id + '|' + nh;
      if (!this.pending[key]) {
        this.pending[key] = { dev, nh, lif, packets: [] };
        this.log(tick, dev, 2, 'не знаю MAC для ' + nh + ': спрашиваю всех через ARP' + (lif.vlan ? ' (в VLAN ' + lif.vlan + ', через ' + lif.name + ')' : ''), 'info');
        this.lifSend(tick, dev, lif, { src: lif.itf.mac, dst: BCAST, type: 'arp', arp: { op: 'request', sip: lif.cfg.ip, smac: lif.itf.mac, tip: nh, tmac: '00:00:00:00:00:00' } });
      }
      this.pending[key].packets.push(pkt);
    }

    /* --- NAT ---
       PAT: пакет из inside-сети уходит в outside с адресом интерфейса и своим портом (для ICMP номером id),
       ответ по этому порту возвращается внутреннему узлу. Статический NAT (проброс порта) работает сразу в обе стороны. */
    natOut(tick, dev, lif, pkt) {
      const tb = this.tables(dev);
      const pub = lif.cfg.ip;
      const sport = l4src(pkt);
      const st = (dev.natStatic || []).find((s) => s.proto === pkt.proto && s.ip === pkt.src && (s.lport || s.port) === sport);
      if (st) {
        this.log(tick, dev, 3, 'статический NAT: ' + pkt.src + ':' + sport + ' → ' + pub + ':' + st.port, 'info');
        pkt.src = pub;
        setSrc(pkt, st.port);
        return;
      }
      let ext = null;
      Object.keys(tb.nat).forEach((k) => { const m = tb.nat[k]; if (m.proto === pkt.proto && m.ip === pkt.src && m.port === sport) ext = m.ext; });
      if (ext == null) {
        ext = sport;
        while (tb.nat[pkt.proto + '|' + ext] || (dev.natStatic || []).some((s) => s.proto === pkt.proto && s.port === ext)) ext = ext >= 65000 ? 1024 : ext + 1;
        tb.nat[pkt.proto + '|' + ext] = { proto: pkt.proto, ip: pkt.src, port: sport, ext, pub };
        this.log(tick, dev, 3, 'NAT (PAT): ' + pkt.src + ':' + sport + ' → ' + pub + ':' + ext + ', запись в таблице трансляций', 'info');
      } else {
        this.log(tick, dev, 3, 'NAT: ' + pkt.src + ':' + sport + ' → ' + pub + ':' + ext + ' (трансляция уже есть)', 'dim');
      }
      pkt.src = pub;
      setSrc(pkt, ext);
    }

    // Пакет пришёл на outside-адрес: найти трансляцию и вернуть внутренний адрес, иначе null
    natIn(tick, dev, p, frame) {
      const port = l4dst(p);
      const st = (p.proto === 'tcp' || p.proto === 'udp') && (dev.natStatic || []).find((s) => s.proto === p.proto && s.port === port);
      const q = clone(p);
      if (st) {
        q.dst = st.ip;
        setDst(q, st.lport || st.port);
        this.log(tick, dev, 3, 'статический NAT: ' + p.dst + ':' + port + ' → ' + q.dst + ':' + (st.lport || st.port), 'info', frame);
        return q;
      }
      const m = port != null && this.tables(dev).nat[p.proto + '|' + port];
      if (!m) return null;
      q.dst = m.ip;
      setDst(q, m.port);
      this.log(tick, dev, 3, 'NAT: ответ на ' + p.dst + ':' + port + ' возвращаю внутреннему узлу ' + m.ip + ':' + m.port, 'info', frame);
      return q;
    }

    l3Receive(tick, dev, ifIdx, frame) {
      const kind = this.net.type(dev).kind;
      const lif = lifs(dev).find((l) => l.i === ifIdx && l.vlan === (frame.vlan || 0));
      if (!lif) {
        this.log(tick, dev, 2, 'кадр с тегом VLAN ' + frame.vlan + ': ' + (kind === 'router' ? 'подынтерфейса ' + dev.ifaces[ifIdx].name + '.' + frame.vlan + ' нет' : 'узел не понимает теги 802.1Q') + ', отбрасываю', 'err', frame);
        return;
      }
      if (lif.cfg.down) {
        this.log(tick, dev, 2, lif.name + ' выключен, кадр отброшен', 'dim', frame);
        return;
      }
      const c = lif.cfg;
      if (frame.dst !== lif.itf.mac && frame.dst !== BCAST) {
        this.log(tick, dev, 2, 'кадр для чужого MAC ' + frame.dst + ', отбрасываю', 'dim', frame);
        return;
      }

      if (frame.type === 'arp') {
        const a = frame.arp;
        if (!c.ip || a.tip !== c.ip) {
          this.log(tick, dev, 2, 'ARP спрашивает про ' + a.tip + ', это не я', 'dim', frame);
          return;
        }
        this.tables(dev).arp[a.sip] = a.smac;
        if (a.op === 'request') {
          this.log(tick, dev, 2, a.tip + ' это я: отвечаю своим MAC', 'info', frame);
          this.lifSend(tick, dev, lif, { src: lif.itf.mac, dst: a.smac, type: 'arp', arp: { op: 'reply', sip: c.ip, smac: lif.itf.mac, tip: a.sip, tmac: a.smac } });
          return;
        }
        this.log(tick, dev, 2, 'ARP: ' + a.sip + ' = ' + a.smac + ', записал в таблицу', 'info', frame);
        const key = dev.id + '|' + a.sip;
        const p = this.pending[key];
        if (p) {
          delete this.pending[key];
          p.packets.forEach((pk) => this.lifSend(tick, dev, p.lif, { src: p.lif.itf.mac, dst: a.smac, type: 'ipv4', ip: pk }));
        }
        return;
      }

      let p = frame.ip;
      if (kind === 'router') {
        const deny = this.aclDeny(lif, 'in', p);
        if (deny) { this.aclDrop(tick, dev, lif, 'in', deny, p, frame); return; }
      }
      if (p.dst === BCAST_IP) {
        if (kind === 'router') return this.routerBroadcast(tick, dev, lif, p, frame);
        return this.local(tick, dev, p, frame, lif);
      }
      let natted = false;
      if (kind === 'router' && c.nat === 'outside' && p.dst === c.ip) {
        const q = this.natIn(tick, dev, p, frame);
        if (q) { p = q; natted = true; }
      }
      if (!natted && this.isMine(dev, p.dst)) return this.local(tick, dev, p, frame, lif);

      if (kind !== 'router') {
        this.log(tick, dev, 3, 'IP-пакет для ' + p.dst + ', это не я: отбрасываю (узел не маршрутизирует)', 'dim', frame);
        return;
      }
      const q = clone(p);
      q.ttl -= 1;
      q.fwd = true;
      q.natIn = c.nat === 'inside';
      if (q.ttl <= 0) {
        this.log(tick, dev, 3, 'TTL дошёл до 0: пакет уничтожен, отправителю уходит ICMP «время истекло»', 'err', frame);
        if (p.icmp && p.icmp.type === 'echo-request') {
          this.ipSend(tick, dev, { src: c.ip, dst: p.src, ttl: this.net.type(dev).ttl, proto: 'icmp', icmp: { type: 'time-exceeded', id: p.icmp.id } });
        }
        return;
      }
      const r = this.route(dev, q.dst);
      if (!r.error) {
        const how = r.code === 'O' ? ' (маршрут OSPF)' : r.code === 'S' ? ' (статический маршрут)' : ' (сеть подключена напрямую)';
        this.log(tick, dev, 3, 'маршрут до ' + q.dst + ': через ' + r.lif.name + (r.via ? ' на ' + r.nh : '') + how + ', TTL ' + p.ttl + ' → ' + q.ttl, 'info', frame);
      }
      this.ipSend(tick, dev, q);
    }

    // Широковещательный IP маршрутизатор не пересылает. Исключение: DHCP при настроенном relay
    routerBroadcast(tick, dev, lif, p, frame) {
      const c = lif.cfg;
      const app = p.app;
      if (app && app.type === 'dhcp' && (app.op === 'discover' || app.op === 'request') && c.helper && c.ip) {
        this.log(tick, dev, 7, 'DHCP relay на ' + lif.name + ': пересылаю ' + DHCP_OP[app.op] + ' серверу ' + c.helper + ' (ip helper-address)', 'info', frame);
        const a = clone(app);
        a.giaddr = c.ip;
        this.ipSend(tick, dev, { src: c.ip, dst: c.helper, ttl: this.net.type(dev).ttl, proto: 'udp', udp: { sport: 67, dport: 67 }, app: a });
        return;
      }
      this.log(tick, dev, 3, 'широковещательный пакет: маршрутизатор его дальше не пускает' +
        (app && app.type === 'dhcp' ? ' (для DHCP из другой сети нужен ip helper-address на ' + lif.name + ')' : ''), 'dim', frame);
    }

    local(tick, dev, p, frame, lif) {
      if (p.proto === 'udp') return this.udpLocal(tick, dev, p, frame, lif);
      if (p.proto === 'tcp') return this.tcpLocal(tick, dev, p, frame);
      const t = p.icmp && p.icmp.type;
      const mine = dev.id === this.origin;
      if (t === 'echo-request') {
        this.log(tick, dev, 3, 'эхо-запрос от ' + p.src + ': отвечаю', 'ok', frame);
        this.ipSend(tick, dev, { src: p.dst, dst: p.src, ttl: this.net.type(dev).ttl, proto: 'icmp', icmp: { type: 'echo-reply', id: p.icmp.id, seq: p.icmp.seq } });
        return;
      }
      if (t === 'echo-reply') {
        this.log(tick, dev, 3, 'ответ от ' + p.src + ' получен, TTL ' + p.ttl, 'ok', frame);
        if (mine) this.results.push({ type: 'reply', from: p.src, ttl: p.ttl });
        return;
      }
      if (t === 'time-exceeded') {
        this.log(tick, dev, 3, p.src + ' сообщил: TTL истёк в пути', 'info', frame);
        if (mine) this.results.push({ type: 'ttl', from: p.src });
        return;
      }
      if (t === 'unreachable') {
        const admin = p.icmp.code === 'admin';
        this.log(tick, dev, 3, p.src + ' сообщил: ' + (admin ? 'доступ запрещён ACL' : 'сеть назначения недоступна'), 'err', frame);
        if (mine) this.results.push({ type: 'unreach', from: p.src, admin });
      }
    }

    /* --- L4/L7: UDP (DHCP, DNS) --- */
    udpLocal(tick, dev, p, frame, lif) {
      const app = p.app || {};
      const kind = this.net.type(dev).kind;
      const sv = dev.services || {};

      if (app.type === 'dhcp') {
        if (app.op === 'discover' || app.op === 'request') {
          if (!(sv.dhcp && sv.dhcp.on)) {
            this.log(tick, dev, 4, 'DHCP ' + DHCP_OP[app.op] + ': порт 67 закрыт, я не DHCP-сервер', 'dim', frame);
            return;
          }
          return this.dhcpServer(tick, dev, p, frame, lif);
        }
        // OFFER и ACK: маршрутизатор передаёт их клиенту, клиент принимает
        if (kind === 'router') {
          const out = this.lifByIp(dev, app.giaddr);
          if (!out) return;
          this.log(tick, dev, 7, 'DHCP relay: возвращаю ' + DHCP_OP[app.op] + ' клиенту в сеть ' + ip.netStr(app.giaddr, out.cfg.prefix) + ' через ' + out.name, 'info', frame);
          this.rawSend(tick, dev, out, app.chaddr, { src: out.cfg.ip, dst: BCAST_IP, ttl: 64, proto: 'udp', udp: { sport: 67, dport: 68 }, app: clone(app) });
          return;
        }
        if (app.chaddr !== dev.ifaces[0].mac) {
          this.log(tick, dev, 4, 'DHCP ' + DHCP_OP[app.op] + ' не мне', 'dim', frame);
          return;
        }
        const flow = this.flows['dhcp:' + app.xid];
        if (flow) flow(tick, p);
        return;
      }

      if (app.type === 'dns') {
        if (app.op === 'query') {
          if (!(sv.dns && sv.dns.on)) {
            this.log(tick, dev, 4, 'DNS-запрос на порт 53, но DNS-сервер здесь не запущен', 'err', frame);
            return;
          }
          const name = String(app.name).toLowerCase().replace(/\.$/, '');
          const rec = sv.dns.records.find((r) => r.name.toLowerCase().replace(/\.$/, '') === name);
          this.log(tick, dev, 7, 'DNS: ' + app.name + (rec ? ' = ' + rec.ip : ' нет в зоне (NXDOMAIN)'), rec ? 'ok' : 'err', frame);
          this.ipSend(tick, dev, { src: p.dst, dst: p.src, ttl: this.net.type(dev).ttl, proto: 'udp', udp: { sport: 53, dport: p.udp.sport },
            app: { type: 'dns', op: 'response', id: app.id, name: app.name, answer: rec ? rec.ip : null } });
          return;
        }
        const flow = this.flows['dns:' + app.id];
        if (flow) flow(tick, p);
      }
    }

    // Пул для сети клиента: при relay сеть берётся из giaddr, иначе из сети интерфейса сервера
    dhcpPool(dev, target) {
      return (dev.services.dhcp.pools || []).find((pl) => ip.valid(pl.start) && ip.valid(pl.end) && ip.same(pl.start, target, pl.prefix)) || null;
    }

    dhcpServer(tick, dev, p, frame, lif) {
      const app = p.app;
      const tb = this.tables(dev);
      const myIp = lif.cfg.ip;
      const target = app.giaddr || myIp;
      const pool = target ? this.dhcpPool(dev, target) : null;
      if (!pool) {
        this.log(tick, dev, 7, 'DHCP: нет пула, в который попадает ' + (target || '?') + (app.giaddr ? ' (адрес relay)' : ' (мой адрес)') + ', запрос игнорирую', 'err', frame);
        return;
      }

      const reply = (op, yiaddr) => {
        const a = { type: 'dhcp', op, xid: app.xid, chaddr: app.chaddr, yiaddr, prefix: pool.prefix,
          router: pool.gateway, dns: pool.dns, server: myIp, giaddr: app.giaddr || '' };
        if (app.giaddr) this.ipSend(tick, dev, { src: myIp, dst: app.giaddr, ttl: 64, proto: 'udp', udp: { sport: 67, dport: 67 }, app: a });
        else this.rawSend(tick, dev, lif, app.chaddr, { src: myIp, dst: BCAST_IP, ttl: 64, proto: 'udp', udp: { sport: 67, dport: 68 }, app: a });
      };

      if (app.op === 'discover') {
        const addr = this.dhcpPick(dev, app.chaddr, pool);
        if (!addr) {
          this.log(tick, dev, 7, 'DHCP: свободных адресов в пуле ' + pool.start + '–' + pool.end + ' нет', 'err', frame);
          return;
        }
        this.log(tick, dev, 7, 'DHCP DISCOVER от ' + app.chaddr + ': предлагаю ' + addr.ip + (addr.reserved ? ' (резервирование)' : '') + ' из пула ' + pool.start + '–' + pool.end, 'info', frame);
        reply('offer', addr.ip);
        return;
      }
      if (app.server && app.server !== myIp) {
        this.log(tick, dev, 7, 'DHCP REQUEST адресован другому серверу', 'dim', frame);
        return;
      }
      tb.leases[app.chaddr] = app.yiaddr;
      this.log(tick, dev, 7, 'DHCP: выдаю ' + app.yiaddr + ' клиенту ' + app.chaddr + ', аренда записана', 'ok', frame);
      reply('ack', app.yiaddr);
    }

    dhcpPick(dev, mac, pool) {
      const cfg = dev.services.dhcp;
      const res = cfg.reservations.find((r) => r.mac.toLowerCase() === mac.toLowerCase());
      if (res && ip.valid(res.ip) && ip.same(res.ip, pool.start, pool.prefix)) return { ip: res.ip, reserved: true };
      const leases = this.tables(dev).leases;
      if (leases[mac] && ip.same(leases[mac], pool.start, pool.prefix)) return { ip: leases[mac] };
      const taken = new Set(Object.entries(leases).filter(([m]) => m !== mac).map(([, a]) => a));
      cfg.reservations.forEach((r) => taken.add(r.ip));
      this.net.devices.forEach((d) => lifs(d).forEach((l) => { if (l.cfg.ip && !(d.dhcp && l.itf.mac === mac)) taken.add(l.cfg.ip); }));
      [pool.gateway, pool.dns].forEach((x) => x && taken.add(x));
      const a = ip.parse(pool.start), b = ip.parse(pool.end);
      for (let n = a; n <= b && n - a < 4096; n++) {
        const s = ip.str(n);
        if (!taken.has(s) && !ip.hostError(s, pool.prefix)) return { ip: s };
      }
      return null;
    }

    /* --- L4/L7: TCP и HTTP --- */
    tcpLocal(tick, dev, p, frame) {
      const t = p.tcp;
      const key = p.src + ':' + t.sport;
      const tb = this.tables(dev);
      const ttl = this.net.type(dev).ttl;
      const answer = (flags, extra) => this.ipSend(tick, dev, Object.assign({ src: p.dst, dst: p.src, ttl, proto: 'tcp',
        tcp: { sport: t.dport, dport: t.sport, flags, seq: t.ack || 0, ack: (t.seq || 0) + 1 } }, extra));

      // Ответ клиенту: ищем открытый клиентом поток
      const flow = this.flows['tcp:' + dev.id + ':' + t.dport];
      if (flow && t.sport === 80) { flow(tick, p); return; }

      if (t.dport !== 80) {
        this.log(tick, dev, 4, 'TCP на порт ' + t.dport + ': порт закрыт', 'dim', frame);
        return;
      }
      const http = dev.services && dev.services.http;
      if (!(http && http.on)) {
        if (t.flags === 'SYN') {
          this.log(tick, dev, 4, 'TCP SYN на порт 80, но веб-сервер не запущен: отвечаю RST', 'err', frame);
          answer('RST, ACK');
        }
        return;
      }
      if (t.flags === 'SYN') {
        tb.conns[key] = 'syn';
        this.log(tick, dev, 4, 'TCP SYN на порт 80: соглашаюсь, отвечаю SYN, ACK (шаг 2 из 3)', 'info', frame);
        answer('SYN, ACK');
        return;
      }
      if (p.app && p.app.type === 'http') {
        const ok = p.app.path === '/' || p.app.path === '/index.html';
        this.log(tick, dev, 7, 'HTTP GET ' + p.app.path + ': отдаю ' + (ok ? '200 OK' : '404 Not Found'), ok ? 'ok' : 'err', frame);
        answer('PSH, ACK', { app: { type: 'http', op: 'response', status: ok ? 200 : 404, title: ok ? http.title : 'Not Found', body: ok ? http.body : 'Страница ' + p.app.path + ' не найдена' } });
        return;
      }
      if (t.flags === 'FIN, ACK') {
        tb.conns[key] = 'fin';
        this.log(tick, dev, 4, 'клиент закрывает соединение: отвечаю FIN, ACK', 'info', frame);
        answer('FIN, ACK');
        return;
      }
      if (t.flags === 'ACK') {
        if (tb.conns[key] === 'syn') { tb.conns[key] = 'est'; this.log(tick, dev, 4, 'TCP-соединение установлено (шаг 3 из 3)', 'ok', frame); }
        else if (tb.conns[key] === 'fin') { delete tb.conns[key]; this.log(tick, dev, 4, 'соединение закрыто', 'dim', frame); }
      }
    }

    /* --- Клиентские операции --- */
    // DNS: найти имя и вызвать done(ip | null)
    resolve(tick, dev, name, done) {
      if (ip.valid(name)) { done(tick, ip.norm(name)); return; }
      if (!dev.dns) {
        this.log(tick, dev, 7, 'не задан DNS-сервер: не у кого спросить адрес ' + name, 'err');
        this.results.push({ type: 'dns-fail', name, reason: 'DNS-сервер не задан' });
        return;
      }
      const id = ++this.pid;
      this.flows['dns:' + id] = (t, p) => {
        delete this.flows['dns:' + id];
        this.log(t, dev, 7, p.app.answer ? name + ' = ' + p.app.answer : 'DNS: имя ' + name + ' не найдено', p.app.answer ? 'ok' : 'err');
        if (!p.app.answer) { this.results.push({ type: 'dns-fail', name, reason: 'имя не найдено (NXDOMAIN)' }); return; }
        done(t, p.app.answer);
      };
      this.log(tick, dev, 7, 'спрашиваю DNS-сервер ' + dev.dns + ': какой адрес у ' + name + '?', 'info');
      this.ipSend(tick, dev, { src: null, dst: dev.dns, ttl: this.net.type(dev).ttl, proto: 'udp', udp: { sport: 50000 + (id % 10000), dport: 53 },
        app: { type: 'dns', op: 'query', id, name }, origin: dev.id });
    }

    ping(srcId, dst, ttl) {
      this.begin(srcId);
      const dev = this.net.dev(srcId);
      if (!dev) return this.finish();
      const go = (tick, addr) => {
        if (this.isMine(dev, addr)) {
          this.log(tick, dev, 3, addr + ' это мой адрес, ответ сразу (loopback)', 'ok');
          this.results.push({ type: 'reply', from: addr, ttl: this.net.type(dev).ttl });
          return;
        }
        const pkt = { src: null, dst: addr, ttl: ttl || this.net.type(dev).ttl, proto: 'icmp', icmp: { type: 'echo-request', id: 1000 + (++this.pid % 50000), seq: 1 }, origin: dev.id };
        this.log(tick, dev, 3, 'ping ' + addr + (ttl ? ' (TTL ' + ttl + ')' : '') + ': собираю ICMP эхо-запрос', 'info');
        this.ipSend(tick, dev, pkt);
      };
      if (ip.valid(dst)) go(0, ip.norm(dst));
      else this.resolve(0, dev, dst, go);
      this.drain();
      return this.finish();
    }

    tracert(srcId, dst, max) {
      // Имя сначала превращаем в адрес отдельным запуском
      if (!ip.valid(dst)) {
        const r = this.dnsLookup(srcId, dst);
        const res = r.results.find((x) => x.type === 'dns');
        if (!res || !res.ip) return Object.assign(r, { hops: [{ ttl: 1, from: null, error: 'не удалось узнать адрес ' + dst }] });
        const t = this.tracert(srcId, res.ip, max);
        t.events = r.events.concat(t.events.map((e) => Object.assign({}, e, { tick: e.tick + r.ticks + 1 })));
        t.ticks += r.ticks + 1;
        return t;
      }
      const all = [];
      const hops = [];
      let offset = 0;
      let storm = false;
      for (let ttl = 1; ttl <= (max || 12); ttl++) {
        const r = this.ping(srcId, dst, ttl);
        r.events.forEach((e) => all.push(Object.assign({}, e, { tick: e.tick + offset })));
        offset += r.ticks + 2;
        storm = storm || r.storm;
        const res = r.results[0];
        if (!res) { hops.push({ ttl, from: null }); if (storm) break; continue; }
        if (res.type === 'local-err' || res.type === 'arp-fail') { hops.push({ ttl, from: null, error: res.text || 'нет ответа на ARP' }); break; }
        hops.push({ ttl, from: res.from, done: res.type === 'reply', unreach: res.type === 'unreach', admin: res.admin });
        if (res.type === 'reply' || res.type === 'unreach') break;
      }
      return { events: all, hops, storm, ticks: offset, results: [] };
    }

    dnsLookup(srcId, name) {
      this.begin(srcId);
      const dev = this.net.dev(srcId);
      if (!dev) return this.finish();
      this.resolve(0, dev, name, (t, addr) => this.results.push({ type: 'dns', name, ip: addr, server: dev.dns }));
      this.drain();
      return this.finish();
    }

    // DHCP-клиент: DISCOVER → OFFER → REQUEST → ACK. При успехе адрес сразу записывается в настройки узла
    dhcp(srcId) {
      this.begin(srcId);
      const dev = this.net.dev(srcId);
      if (!dev) return this.finish();
      const lif = lifs(dev)[0];
      const itf = lif.itf;
      const xid = (Math.random() * 0xffffffff) >>> 0;
      const bcast = (tick, app) => this.rawSend(tick, dev, lif, BCAST, { src: '0.0.0.0', dst: BCAST_IP, ttl: 64, proto: 'udp', udp: { sport: 68, dport: 67 }, app });
      this.flows['dhcp:' + xid] = (tick, p) => {
        const a = p.app;
        if (a.op === 'offer') {
          if (this.flows['dhcp:' + xid + ':got']) return;
          this.flows['dhcp:' + xid + ':got'] = true;
          this.log(tick, dev, 7, 'получил предложение ' + a.yiaddr + ' от ' + a.server + ': прошу его (REQUEST)', 'info', null);
          bcast(tick, { type: 'dhcp', op: 'request', xid, chaddr: itf.mac, yiaddr: a.yiaddr, server: a.server });
          return;
        }
        if (a.op === 'ack') {
          itf.ip = a.yiaddr;
          itf.prefix = a.prefix;
          dev.gateway = a.router || '';
          dev.dns = a.dns || '';
          dev.dhcp = true;
          this.tables(dev).arp = {};
          this.log(tick, dev, 7, 'DHCP ACK: мой адрес ' + a.yiaddr + '/' + a.prefix + (a.router ? ', шлюз ' + a.router : '') + (a.dns ? ', DNS ' + a.dns : ''), 'ok', null);
          this.results.push({ type: 'dhcp', ip: a.yiaddr, prefix: a.prefix, gateway: a.router, dns: a.dns, server: a.server });
        }
      };
      if (!up(itf)) {
        this.log(0, dev, 1, 'кабель не подключён: DHCP-запрос отправить некуда', 'err');
        this.results.push({ type: 'local-err', text: 'кабель не подключён' });
        return this.finish();
      }
      this.log(0, dev, 7, 'адреса пока нет: шлю DHCP DISCOVER всем (0.0.0.0 → 255.255.255.255)', 'info');
      bcast(0, { type: 'dhcp', op: 'discover', xid, chaddr: itf.mac });
      this.drain();
      return this.finish();
    }

    // HTTP: DNS (если имя) → TCP-рукопожатие → GET → ответ → закрытие соединения
    http(srcId, url) {
      this.begin(srcId);
      const dev = this.net.dev(srcId);
      if (!dev) return this.finish();
      const m = String(url).trim().match(/^(?:https?:\/\/)?([^/:\s]+)(?::\d+)?(\/\S*)?$/i);
      if (!m) { this.results.push({ type: 'local-err', text: 'неверный адрес ' + url }); return this.finish(); }
      const host = m[1];
      const path = m[2] || '/';
      this.resolve(0, dev, host, (tick, addr) => {
        const sport = 49152 + (++this.pid % 16000);
        let seq = 1000;
        const send = (t, flags, extra) => this.ipSend(t, dev, Object.assign({ src: null, dst: addr, ttl: this.net.type(dev).ttl, proto: 'tcp',
          tcp: { sport, dport: 80, flags, seq: seq++, ack: 0 }, origin: dev.id }, extra));
        this.flows['tcp:' + dev.id + ':' + sport] = (t, p) => {
          const f = p.tcp.flags;
          if (f.startsWith('RST')) {
            this.log(t, dev, 4, addr + ':80 отклонил соединение (RST): веб-сервер не запущен', 'err', null);
            this.results.push({ type: 'http-refused', host, ip: addr });
            return;
          }
          if (f === 'SYN, ACK') {
            this.log(t, dev, 4, 'сервер согласен: отвечаю ACK, соединение установлено', 'ok', null);
            send(t, 'ACK');
            this.later(t, (t2) => {
              this.log(t2, dev, 7, 'отправляю HTTP GET ' + path, 'info');
              send(t2, 'PSH, ACK', { app: { type: 'http', op: 'request', path, host } });
            });
            return;
          }
          if (p.app && p.app.type === 'http') {
            this.log(t, dev, 7, 'получил ответ HTTP ' + p.app.status + ': «' + p.app.title + '»', p.app.status === 200 ? 'ok' : 'err', null);
            this.results.push({ type: 'http', host, ip: addr, path, status: p.app.status, title: p.app.title, body: p.app.body });
            this.later(t, (t2) => { this.log(t2, dev, 4, 'страница получена, закрываю соединение (FIN)', 'dim'); send(t2, 'FIN, ACK'); });
            return;
          }
          if (f === 'FIN, ACK') {
            this.log(t, dev, 4, 'сервер тоже закрыл соединение: последний ACK', 'dim', null);
            send(t, 'ACK');
            delete this.flows['tcp:' + dev.id + ':' + sport];
          }
        };
        this.log(tick, dev, 4, 'открываю TCP-соединение с ' + addr + ':80, шаг 1 из 3: SYN', 'info');
        send(tick, 'SYN');
      });
      this.drain();
      return this.finish();
    }

    finish() {
      const ticks = this.events.reduce((m, e) => Math.max(m, e.tick + (e.type === 'hop' ? 1 : 0)), 0);
      return { events: this.events, results: this.results, storm: this.storm, ticks };
    }

    // Таблица маршрутизации для веб-интерфейса
    routeTable(dev) {
      this.cache = null;
      return this.routes(dev).map((r) => ({
        code: r.code, net: r.net + '/' + r.prefix, prefix: r.prefix, via: r.nh, iface: r.lif ? r.lif.name : '', idx: r.idx, cost: r.cost, ext: r.ext,
      })).sort((a, b) => b.prefix - a.prefix || ({ C: 0, S: 1, O: 2 }[a.code] - { C: 0, S: 1, O: 2 }[b.code]));
    }
  }
  LAB.Sim = Sim;
})();
