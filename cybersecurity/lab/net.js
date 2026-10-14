// Сетевая лаборатория: модель сети и движок симуляции. Здесь нет DOM, только логика.
//
// Расширение:
//   LAB.registerType({...})     новый тип устройства (types.js); своё поведение задаётся функцией receive
//   LAB.registerPreset({...})   готовая схема (presets.js)
//   LAB.registerShell(id, fn)   командная оболочка устройства (shell-ios.js, shell-linux.js)
(function () {
  const LAB = (window.LAB = window.LAB || {});
  LAB.types = {};
  LAB.presets = [];
  LAB.shells = {};

  // kind: host | router | switch | hub | своё (тогда нужен receive(sim, tick, dev, ifIdx, frame))
  // layers: [нижний, верхний] уровни OSI, на которых работает устройство
  // shell: какая оболочка открывается в терминале (ios, linux)
  LAB.registerType = (def) => {
    LAB.types[def.type] = Object.assign({ kind: 'host', layers: [1, 7], ports: ['eth0'], ttl: 64, prefix: def.type.toUpperCase() }, def);
  };
  LAB.registerPreset = (p) => LAB.presets.push(p);
  LAB.registerShell = (id, factory) => { LAB.shells[id] = factory; };

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

  function defaultServices() {
    return {
      dns: { on: false, records: [] },
      dhcp: { on: false, pool: { start: '', end: '', prefix: 24, gateway: '', dns: '' }, reservations: [] },
      http: { on: false, title: 'Добро пожаловать', body: 'Эту страницу отдал учебный веб-сервер.' },
    };
  }
  LAB.defaultServices = defaultServices;

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
        if (!LAB.types[d.type]) throw new Error('неизвестный тип устройства: ' + d.type);
        const sv = defaultServices();
        const js = d.services || {};
        net.devices.push({
          id: String(d.id), type: d.type, name: String(d.name || d.id),
          x: Number(d.x) || 0, y: Number(d.y) || 0,
          gateway: String(d.gateway || ''),
          dns: String(d.dns || ''),
          dhcp: !!d.dhcp,
          routes: Array.isArray(d.routes) ? d.routes.map((r) => ({ net: String(r.net), prefix: Number(r.prefix), via: String(r.via) })) : [],
          services: {
            dns: Object.assign(sv.dns, js.dns, { records: ((js.dns && js.dns.records) || []).map((r) => ({ name: String(r.name), ip: String(r.ip) })) }),
            dhcp: Object.assign(sv.dhcp, js.dhcp, {
              pool: Object.assign(sv.dhcp.pool, js.dhcp && js.dhcp.pool),
              reservations: ((js.dhcp && js.dhcp.reservations) || []).map((r) => ({ mac: String(r.mac), ip: String(r.ip) })),
            }),
            http: Object.assign(sv.http, js.http),
          },
          ifaces: (d.ifaces || []).map((i) => ({
            name: String(i.name), mac: String(i.mac || randMac()), ip: String(i.ip || ''),
            prefix: i.prefix == null ? 24 : Number(i.prefix), vlan: Number(i.vlan) || 1,
            down: !!i.down, helper: String(i.helper || ''), link: i.link || null,
          })),
        });
      });
      json.links.forEach((l) => {
        net.reindex();
        if (net.dev(l.a.dev) && net.dev(l.b.dev)) net.links.push({ id: String(l.id), a: { dev: l.a.dev, i: Number(l.a.i) }, b: { dev: l.b.dev, i: Number(l.b.i) } });
      });
      net.reindex();
      net.devices.forEach((d) => d.ifaces.forEach((i) => { if (i.link && !net.link(i.link)) i.link = null; }));
      return net;
    }

    toJSON() {
      return { v: 2, seq: this.seq, devices: this.devices, links: this.links };
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
    byIp(addr) { return this.devices.find((d) => d.ifaces.some((i) => i.ip === addr)) || null; }

    uniqueName(prefix) {
      for (let n = 1; ; n++) if (!this.devices.some((d) => d.name === prefix + n)) return prefix + n;
    }

    add(type, x, y) {
      const t = LAB.types[type];
      if (!t) return null;
      const dev = {
        id: 'd' + this.seq++, type, name: this.uniqueName(t.prefix),
        x: Math.round(x), y: Math.round(y), gateway: '', dns: '', dhcp: false, routes: [],
        services: defaultServices(),
        ifaces: t.ports.map((name) => ({ name, mac: randMac(), ip: '', prefix: 24, vlan: 1, down: false, helper: '', link: null })),
      };
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

    // Широковещательные домены: хабы и коммутаторы (в пределах VLAN) соединяют порты,
    // интерфейсы узлов и маршрутизаторов домены разделяют. Возвращает домен для каждого кабеля.
    domains() {
      const parent = {};
      const find = (k) => { while (parent[k] !== k) { parent[k] = parent[parent[k]]; k = parent[k]; } return k; };
      const add = (k) => { if (!(k in parent)) parent[k] = k; return k; };
      const union = (a, b) => { parent[find(add(a))] = find(add(b)); };
      const key = (devId, i) => {
        const d = this.dev(devId);
        const kind = this.type(d).kind;
        if (kind === 'hub') return d.id;
        if (kind === 'switch') return d.id + ':v' + (d.ifaces[i].vlan || 1);
        return d.id + ':' + i;
      };
      this.links.forEach((l) => {
        if (this.linkDown(l)) { add(key(l.a.dev, l.a.i)); add(key(l.b.dev, l.b.i)); return; }
        union(key(l.a.dev, l.a.i), key(l.b.dev, l.b.i));
      });

      const roots = [];
      const linkDomain = {};
      this.links.forEach((l) => {
        const r = find(key(l.a.dev, l.a.i));
        let n = roots.indexOf(r);
        if (n < 0) { roots.push(r); n = roots.length - 1; }
        linkDomain[l.id] = n;
      });

      const info = roots.map(() => ({ nets: new Set(), vlans: new Set(), hosts: 0 }));
      this.devices.forEach((d) => {
        const kind = this.type(d).kind;
        d.ifaces.forEach((itf) => {
          if (!itf.link) return;
          const dom = linkDomain[itf.link];
          if (kind === 'switch') info[dom].vlans.add(itf.vlan || 1);
          if ((kind === 'host' || kind === 'router') && itf.ip) {
            info[dom].nets.add(ip.netStr(itf.ip, itf.prefix));
            info[dom].hosts++;
          }
        });
      });
      return { linkDomain, info };
    }
  }
  LAB.Net = Net;

  /* ===== Подписи пакетов ===== */
  const DHCP_OP = { discover: 'DISCOVER', offer: 'OFFER', request: 'REQUEST', ack: 'ACK', nak: 'NAK' };
  LAB.DHCP_OP = DHCP_OP;

  function describe(f) {
    if (f.type === 'arp') {
      return f.arp.op === 'request'
        ? { kind: 'arp', label: 'ARP-запрос: у кого ' + f.arp.tip + '?' }
        : { kind: 'arp', label: 'ARP-ответ: ' + f.arp.sip + ' это ' + f.arp.smac };
    }
    const p = f.ip;
    const app = p.app;
    if (app && app.type === 'dhcp') return { kind: 'dhcp', label: 'DHCP ' + DHCP_OP[app.op] + (app.yiaddr ? ': ' + app.yiaddr : '') };
    if (app && app.type === 'dns') {
      return app.op === 'query'
        ? { kind: 'dns', label: 'DNS-запрос: ' + app.name + '?' }
        : { kind: 'dns', label: 'DNS-ответ: ' + app.name + ' = ' + (app.answer || 'не найдено') };
    }
    if (app && app.type === 'http') {
      return app.op === 'request'
        ? { kind: 'http', label: 'HTTP GET ' + app.path }
        : { kind: 'http', label: 'HTTP ' + app.status + ' ' + (app.status === 200 ? 'OK' : 'Not Found') };
    }
    if (p.proto === 'tcp') return { kind: 'tcp', label: 'TCP [' + p.tcp.flags + '] ' + p.src + ':' + p.tcp.sport + ' → ' + p.dst + ':' + p.tcp.dport };
    const t = p.icmp && p.icmp.type;
    if (t === 'echo-request') return { kind: 'req', label: 'ICMP эхо-запрос ' + p.src + ' → ' + p.dst + ', TTL ' + p.ttl };
    if (t === 'echo-reply') return { kind: 'rep', label: 'ICMP эхо-ответ ' + p.src + ' → ' + p.dst + ', TTL ' + p.ttl };
    if (t === 'time-exceeded') return { kind: 'err', label: 'ICMP: TTL истёк (' + p.src + ' → ' + p.dst + ')' };
    if (t === 'unreachable') return { kind: 'err', label: 'ICMP: недоступно (' + p.src + ' → ' + p.dst + ')' };
    return { kind: 'req', label: 'IP ' + p.src + ' → ' + p.dst };
  }
  LAB.describe = describe;

  /* ===== Симуляция =====
     Работает событиями по тактам: кадр, отправленный на такте t, приходит на такте t+1.
     events: { type: 'hop', tick, link, from, to, frame, label, kind }
             { type: 'log', tick, dev, layer, text, level: info|ok|err|dim, frame? }
     ARP- и MAC-таблицы и аренды DHCP живут между запусками (reset() их чистит). */
  const STORM_LIMIT = 1200;

  class Sim {
    constructor(net) {
      this.net = net;
      this.rt = {};
      this.pid = 0;
    }

    reset() { this.rt = {}; }

    tables(dev) { return this.rt[dev.id] || (this.rt[dev.id] = { arp: {}, mac: {}, leases: {}, conns: {} }); }

    begin(originId) {
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

    drain() {
      while (this.queue.length) {
        const q = this.queue.shift();
        this.lastTick = q.tick;
        if (q.fn) { q.fn(q.tick); continue; }
        if (++this.count > STORM_LIMIT) {
          this.storm = true;
          this.queue = [];
          this.log(q.tick, null, 2, 'Широковещательный шторм: кадры бесконечно ходят по кольцу. В сети петля, а STP нет. Симуляция остановлена.', 'err');
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
      const vlan = inIf.vlan || 1;
      const srcKey = vlan + '|' + frame.src;
      if (tb.mac[srcKey] !== ifIdx) {
        tb.mac[srcKey] = ifIdx;
        this.log(tick, dev, 2, 'запомнил: ' + frame.src + ' за портом ' + inIf.name + ' (VLAN ' + vlan + ')', 'dim', frame);
      }
      const known = tb.mac[vlan + '|' + frame.dst];
      if (frame.dst === BCAST || known == null) {
        const out = dev.ifaces.map((p, i) => i).filter((i) => i !== ifIdx && up(dev.ifaces[i]) && (dev.ifaces[i].vlan || 1) === vlan);
        this.log(tick, dev, 2, (frame.dst === BCAST ? 'широковещательный кадр' : 'MAC ' + frame.dst + ' пока не знаю') +
          ': рассылаю во все порты VLAN ' + vlan + ' (' + out.length + ')', 'info', frame);
        out.forEach((i) => this.send(tick, dev, i, frame));
        return;
      }
      if (known === ifIdx) {
        this.log(tick, dev, 2, 'получатель за тем же портом, кадр дальше не идёт', 'dim', frame);
        return;
      }
      this.log(tick, dev, 2, frame.dst + ' за портом ' + dev.ifaces[known].name + ': пересылаю только туда', 'info', frame);
      this.send(tick, dev, known, frame);
    }

    /* --- L3: узлы и маршрутизаторы --- */
    isMine(dev, addr) { return dev.ifaces.some((i) => i.ip && i.ip === addr); }

    route(dev, dst) {
      const kind = this.net.type(dev).kind;
      if (kind !== 'router') {
        const idx = dev.ifaces.findIndex((i) => i.ip);
        if (idx < 0) return { error: 'на интерфейсе не задан IP-адрес' };
        const itf = dev.ifaces[idx];
        if (ip.same(dst, itf.ip, itf.prefix)) return { ifIdx: idx, nh: dst };
        if (!dev.gateway) return { error: dst + ' в другой сети, а шлюз по умолчанию не задан' };
        if (!ip.same(dev.gateway, itf.ip, itf.prefix)) return { error: 'шлюз ' + dev.gateway + ' не в сети интерфейса ' + ip.netStr(itf.ip, itf.prefix) };
        return { ifIdx: idx, nh: dev.gateway, via: true };
      }
      const r = this.routerLookup(dev, dst);
      return r || { error: 'нет маршрута до ' + dst };
    }

    // Самый длинный префикс; при равенстве побеждает подключённая сеть
    routerLookup(dev, dst) {
      let best = null;
      dev.ifaces.forEach((itf, i) => {
        if (!itf.ip || !up(itf)) return;
        if (ip.same(dst, itf.ip, itf.prefix) && (!best || itf.prefix > best.prefix)) best = { prefix: itf.prefix, ifIdx: i, nh: dst, code: 'C' };
      });
      dev.routes.forEach((r) => {
        if (!ip.same(dst, r.net, r.prefix)) return;
        if (best && r.prefix <= best.prefix) return;
        const i = dev.ifaces.findIndex((itf) => itf.ip && up(itf) && ip.same(r.via, itf.ip, itf.prefix));
        if (i >= 0) best = { prefix: r.prefix, ifIdx: i, nh: r.via, code: 'S', via: true };
      });
      return best;
    }

    ipSend(tick, dev, pkt) {
      const r = this.route(dev, pkt.dst);
      if (r.error) {
        this.log(tick, dev, 3, r.error + ', пакет отброшен', 'err');
        if (pkt.origin === dev.id && dev.id === this.origin) this.results.push({ type: 'local-err', text: r.error });
        const isEcho = pkt.icmp && (pkt.icmp.type === 'echo-request' || pkt.icmp.type === 'echo-reply');
        if (pkt.fwd && (isEcho || pkt.proto !== 'icmp') && pkt.src && !this.isMine(dev, pkt.src)) {
          this.ipSend(tick, dev, { src: null, dst: pkt.src, ttl: this.net.type(dev).ttl, proto: 'icmp', icmp: { type: 'unreachable' } });
        }
        return;
      }
      this.l2Out(tick, dev, r.ifIdx, r.nh, pkt);
    }

    // Отправить без маршрутизации и ARP: на известный MAC или широковещательно (DHCP)
    rawSend(tick, dev, ifIdx, dstMac, pkt) {
      const itf = dev.ifaces[ifIdx];
      this.send(tick, dev, ifIdx, { src: itf.mac, dst: dstMac, type: 'ipv4', ip: pkt });
    }

    l2Out(tick, dev, ifIdx, nh, pkt) {
      const itf = dev.ifaces[ifIdx];
      if (!pkt.src) pkt.src = itf.ip;
      const mac = this.tables(dev).arp[nh];
      if (mac) {
        this.send(tick, dev, ifIdx, { src: itf.mac, dst: mac, type: 'ipv4', ip: pkt });
        return;
      }
      const key = dev.id + '|' + nh;
      if (!this.pending[key]) {
        this.pending[key] = { dev, nh, ifIdx, packets: [] };
        this.log(tick, dev, 2, 'не знаю MAC для ' + nh + ': спрашиваю всех через ARP', 'info');
        this.send(tick, dev, ifIdx, { src: itf.mac, dst: BCAST, type: 'arp', arp: { op: 'request', sip: itf.ip, smac: itf.mac, tip: nh, tmac: '00:00:00:00:00:00' } });
      }
      this.pending[key].packets.push(pkt);
    }

    l3Receive(tick, dev, ifIdx, frame) {
      const itf = dev.ifaces[ifIdx];
      const kind = this.net.type(dev).kind;
      if (frame.dst !== itf.mac && frame.dst !== BCAST) {
        this.log(tick, dev, 2, 'кадр для чужого MAC ' + frame.dst + ', отбрасываю', 'dim', frame);
        return;
      }

      if (frame.type === 'arp') {
        const a = frame.arp;
        if (!itf.ip || a.tip !== itf.ip) {
          this.log(tick, dev, 2, 'ARP спрашивает про ' + a.tip + ', это не я', 'dim', frame);
          return;
        }
        this.tables(dev).arp[a.sip] = a.smac;
        if (a.op === 'request') {
          this.log(tick, dev, 2, a.tip + ' это я: отвечаю своим MAC', 'info', frame);
          this.send(tick, dev, ifIdx, { src: itf.mac, dst: a.smac, type: 'arp', arp: { op: 'reply', sip: itf.ip, smac: itf.mac, tip: a.sip, tmac: a.smac } });
          return;
        }
        this.log(tick, dev, 2, 'ARP: ' + a.sip + ' = ' + a.smac + ', записал в таблицу', 'info', frame);
        const key = dev.id + '|' + a.sip;
        const p = this.pending[key];
        if (p) {
          delete this.pending[key];
          p.packets.forEach((pk) => this.send(tick, dev, p.ifIdx, { src: dev.ifaces[p.ifIdx].mac, dst: a.smac, type: 'ipv4', ip: pk }));
        }
        return;
      }

      const p = frame.ip;
      if (p.dst === BCAST_IP) {
        if (kind === 'router') return this.routerBroadcast(tick, dev, ifIdx, p, frame);
        return this.local(tick, dev, p, frame, ifIdx);
      }
      if (this.isMine(dev, p.dst)) return this.local(tick, dev, p, frame, ifIdx);

      if (kind !== 'router') {
        this.log(tick, dev, 3, 'IP-пакет для ' + p.dst + ', это не я: отбрасываю (узел не маршрутизирует)', 'dim', frame);
        return;
      }
      const q = clone(p);
      q.ttl -= 1;
      q.fwd = true;
      if (q.ttl <= 0) {
        this.log(tick, dev, 3, 'TTL дошёл до 0: пакет уничтожен, отправителю уходит ICMP «время истекло»', 'err', frame);
        if (p.icmp && p.icmp.type === 'echo-request') {
          this.ipSend(tick, dev, { src: itf.ip, dst: p.src, ttl: this.net.type(dev).ttl, proto: 'icmp', icmp: { type: 'time-exceeded', id: p.icmp.id } });
        }
        return;
      }
      const r = this.route(dev, q.dst);
      if (!r.error) {
        this.log(tick, dev, 3, 'маршрут до ' + q.dst + ': через ' + dev.ifaces[r.ifIdx].name + (r.via ? ' на ' + r.nh : ' (сеть подключена напрямую)') + ', TTL ' + p.ttl + ' → ' + q.ttl, 'info', frame);
      }
      this.ipSend(tick, dev, q);
    }

    // Широковещательный IP маршрутизатор не пересылает. Исключение: DHCP при настроенном relay
    routerBroadcast(tick, dev, ifIdx, p, frame) {
      const itf = dev.ifaces[ifIdx];
      const app = p.app;
      if (app && app.type === 'dhcp' && (app.op === 'discover' || app.op === 'request') && itf.helper && itf.ip) {
        this.log(tick, dev, 7, 'DHCP relay: пересылаю ' + DHCP_OP[app.op] + ' серверу ' + itf.helper + ' (ip helper-address)', 'info', frame);
        const a = clone(app);
        a.giaddr = itf.ip;
        this.ipSend(tick, dev, { src: itf.ip, dst: itf.helper, ttl: this.net.type(dev).ttl, proto: 'udp', udp: { sport: 67, dport: 67 }, app: a });
        return;
      }
      this.log(tick, dev, 3, 'широковещательный пакет: маршрутизатор его дальше не пускает' +
        (app && app.type === 'dhcp' ? ' (для DHCP из другой сети нужен ip helper-address)' : ''), 'dim', frame);
    }

    local(tick, dev, p, frame, ifIdx) {
      if (p.proto === 'udp') return this.udpLocal(tick, dev, p, frame, ifIdx);
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
        this.log(tick, dev, 3, p.src + ' сообщил: сеть назначения недоступна', 'err', frame);
        if (mine) this.results.push({ type: 'unreach', from: p.src });
      }
    }

    /* --- L4/L7: UDP (DHCP, DNS) --- */
    udpLocal(tick, dev, p, frame, ifIdx) {
      const app = p.app || {};
      const kind = this.net.type(dev).kind;
      const sv = dev.services || {};

      if (app.type === 'dhcp') {
        if (app.op === 'discover' || app.op === 'request') {
          if (!(sv.dhcp && sv.dhcp.on)) {
            this.log(tick, dev, 4, 'DHCP ' + DHCP_OP[app.op] + ': порт 67 закрыт, я не DHCP-сервер', 'dim', frame);
            return;
          }
          return this.dhcpServer(tick, dev, p, frame, ifIdx);
        }
        // OFFER и ACK: маршрутизатор передаёт их клиенту, клиент принимает
        if (kind === 'router') {
          const out = dev.ifaces.findIndex((i) => i.ip === app.giaddr);
          if (out < 0) return;
          this.log(tick, dev, 7, 'DHCP relay: возвращаю ' + DHCP_OP[app.op] + ' клиенту в сеть ' + ip.netStr(app.giaddr, dev.ifaces[out].prefix), 'info', frame);
          this.rawSend(tick, dev, out, app.chaddr, { src: dev.ifaces[out].ip, dst: BCAST_IP, ttl: 64, proto: 'udp', udp: { sport: 67, dport: 68 }, app: clone(app) });
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

    dhcpServer(tick, dev, p, frame, ifIdx) {
      const app = p.app;
      const cfg = dev.services.dhcp;
      const pool = cfg.pool;
      const myIf = dev.ifaces[ifIdx];
      const tb = this.tables(dev);
      const target = app.giaddr || myIf.ip;
      if (!ip.valid(pool.start) || !ip.valid(pool.end) || !ip.same(pool.start, target, pool.prefix)) {
        this.log(tick, dev, 7, 'DHCP: нет пула для сети ' + (target ? ip.netStr(target, pool.prefix) : '?') + ', запрос игнорирую', 'err', frame);
        return;
      }

      const reply = (op, yiaddr) => {
        const a = { type: 'dhcp', op, xid: app.xid, chaddr: app.chaddr, yiaddr, prefix: pool.prefix,
          router: pool.gateway, dns: pool.dns, server: myIf.ip, giaddr: app.giaddr || '' };
        if (app.giaddr) this.ipSend(tick, dev, { src: myIf.ip, dst: app.giaddr, ttl: 64, proto: 'udp', udp: { sport: 67, dport: 67 }, app: a });
        else this.rawSend(tick, dev, ifIdx, app.chaddr, { src: myIf.ip, dst: BCAST_IP, ttl: 64, proto: 'udp', udp: { sport: 67, dport: 68 }, app: a });
      };

      if (app.op === 'discover') {
        const addr = this.dhcpPick(dev, app.chaddr);
        if (!addr) {
          this.log(tick, dev, 7, 'DHCP: свободных адресов в пуле нет', 'err', frame);
          return;
        }
        this.log(tick, dev, 7, 'DHCP DISCOVER от ' + app.chaddr + ': предлагаю ' + addr.ip + (addr.reserved ? ' (резервирование)' : ''), 'info', frame);
        reply('offer', addr.ip);
        return;
      }
      if (app.server && app.server !== myIf.ip) {
        this.log(tick, dev, 7, 'DHCP REQUEST адресован другому серверу', 'dim', frame);
        return;
      }
      tb.leases[app.chaddr] = app.yiaddr;
      this.log(tick, dev, 7, 'DHCP: выдаю ' + app.yiaddr + ' клиенту ' + app.chaddr + ', аренда записана', 'ok', frame);
      reply('ack', app.yiaddr);
    }

    dhcpPick(dev, mac) {
      const cfg = dev.services.dhcp;
      const pool = cfg.pool;
      const res = cfg.reservations.find((r) => r.mac.toLowerCase() === mac.toLowerCase());
      if (res && ip.valid(res.ip)) return { ip: res.ip, reserved: true };
      const leases = this.tables(dev).leases;
      if (leases[mac]) return { ip: leases[mac] };
      const taken = new Set(Object.values(leases));
      cfg.reservations.forEach((r) => taken.add(r.ip));
      this.net.devices.forEach((d) => d.ifaces.forEach((i) => { if (i.ip && !(d.dhcp && i.mac === mac)) taken.add(i.ip); }));
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
        const pkt = { src: null, dst: addr, ttl: ttl || this.net.type(dev).ttl, proto: 'icmp', icmp: { type: 'echo-request', id: ++this.pid, seq: 1 }, origin: dev.id };
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
        hops.push({ ttl, from: res.from, done: res.type === 'reply', unreach: res.type === 'unreach' });
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
      const itf = dev.ifaces[0];
      const xid = (Math.random() * 0xffffffff) >>> 0;
      const bcast = (tick, app) => this.rawSend(tick, dev, 0, BCAST, { src: '0.0.0.0', dst: BCAST_IP, ttl: 64, proto: 'udp', udp: { sport: 68, dport: 67 }, app });
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
  }
  LAB.Sim = Sim;

  // Таблица маршрутизации для веб-интерфейса и show ip route
  LAB.routeTable = function (dev) {
    const rows = [];
    dev.ifaces.forEach((i) => {
      if (i.ip && up(i)) rows.push({ code: 'C', net: ip.netStr(i.ip, i.prefix), prefix: i.prefix, iface: i.name });
    });
    dev.routes.forEach((r, idx) => {
      const out = dev.ifaces.find((i) => i.ip && up(i) && ip.same(r.via, i.ip, i.prefix));
      rows.push({ code: 'S', net: r.net + '/' + r.prefix, prefix: r.prefix, via: r.via, iface: out ? out.name : '', idx });
    });
    return rows.sort((a, b) => b.prefix - a.prefix);
  };
})();
