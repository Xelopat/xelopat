// Сетевая лаборатория: модель сети и движок симуляции. Здесь нет DOM, только логика.
//
// Расширение:
//   LAB.registerType({...})     новый тип устройства (types.js); своё поведение задаётся функцией receive
//   LAB.registerPreset({...})   готовая схема (presets.js)
//   LAB.registerCommand({...})  команда консоли устройства (commands.js)
(function () {
  const LAB = (window.LAB = window.LAB || {});
  LAB.types = {};
  LAB.presets = [];
  LAB.commands = [];

  // kind: host | router | switch | hub | своё (тогда нужен receive(sim, tick, dev, ifIdx, frame))
  // layers: [нижний, верхний] уровни OSI, на которых работает устройство
  LAB.registerType = (def) => {
    LAB.types[def.type] = Object.assign({ kind: 'host', layers: [1, 7], ports: ['eth0'], ttl: 64, prefix: def.type.toUpperCase() }, def);
  };
  LAB.registerPreset = (p) => LAB.presets.push(p);
  LAB.registerCommand = (c) => LAB.commands.push(c);

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
  LAB.BCAST = BCAST;

  function randMac() {
    const b = ['02'];
    for (let i = 0; i < 5; i++) b.push(Math.floor(Math.random() * 256).toString(16).padStart(2, '0'));
    return b.join(':');
  }
  const clone = (o) => JSON.parse(JSON.stringify(o));

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
        net.devices.push({
          id: String(d.id), type: d.type, name: String(d.name || d.id),
          x: Number(d.x) || 0, y: Number(d.y) || 0,
          gateway: String(d.gateway || ''),
          routes: Array.isArray(d.routes) ? d.routes.map((r) => ({ net: String(r.net), prefix: Number(r.prefix), via: String(r.via) })) : [],
          ifaces: (d.ifaces || []).map((i) => ({
            name: String(i.name), mac: String(i.mac || randMac()), ip: String(i.ip || ''),
            prefix: i.prefix == null ? 24 : Number(i.prefix), vlan: Number(i.vlan) || 1, link: i.link || null,
          })),
        });
      });
      json.links.forEach((l) => {
        if (net.dev(l.a.dev) && net.dev(l.b.dev)) net.links.push({ id: String(l.id), a: { dev: l.a.dev, i: Number(l.a.i) }, b: { dev: l.b.dev, i: Number(l.b.i) } });
      });
      net.devices.forEach((d) => d.ifaces.forEach((i) => { if (i.link && !net.link(i.link)) i.link = null; }));
      return net;
    }

    toJSON() {
      return { v: 1, seq: this.seq, devices: this.devices, links: this.links };
    }

    dev(id) { return this.devices.find((d) => d.id === id) || null; }
    link(id) { return this.links.find((l) => l.id === id) || null; }
    byName(name) { return this.devices.find((d) => d.name.toLowerCase() === String(name).toLowerCase()) || null; }
    type(dev) { return LAB.types[dev.type]; }

    uniqueName(prefix) {
      for (let n = 1; ; n++) if (!this.devices.some((d) => d.name === prefix + n)) return prefix + n;
    }

    add(type, x, y) {
      const t = LAB.types[type];
      if (!t) return null;
      const dev = {
        id: 'd' + this.seq++, type, name: this.uniqueName(t.prefix),
        x: Math.round(x), y: Math.round(y), gateway: '', routes: [],
        ifaces: t.ports.map((name) => ({ name, mac: randMac(), ip: '', prefix: 24, vlan: 1, link: null })),
      };
      this.devices.push(dev);
      return dev;
    }

    remove(id) {
      this.links.filter((l) => l.a.dev === id || l.b.dev === id).forEach((l) => this.disconnect(l.id));
      this.devices = this.devices.filter((d) => d.id !== id);
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
      return { link };
    }

    disconnect(linkId) {
      const l = this.link(linkId);
      if (!l) return;
      [l.a, l.b].forEach((end) => { const d = this.dev(end.dev); if (d && d.ifaces[end.i]) d.ifaces[end.i].link = null; });
      this.links = this.links.filter((x) => x.id !== linkId);
    }

    // Что на другом конце кабеля
    peer(devId, ifIdx) {
      const d = this.dev(devId);
      const l = d && d.ifaces[ifIdx] && this.link(d.ifaces[ifIdx].link);
      if (!l) return null;
      const end = l.a.dev === devId && l.a.i === ifIdx ? l.b : l.a;
      return { dev: this.dev(end.dev), ifIdx: end.i, link: l };
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
      this.links.forEach((l) => union(key(l.a.dev, l.a.i), key(l.b.dev, l.b.i)));

      const roots = [];
      const linkDomain = {};
      this.links.forEach((l) => {
        const r = find(key(l.a.dev, l.a.i));
        let n = roots.indexOf(r);
        if (n < 0) { roots.push(r); n = roots.length - 1; }
        linkDomain[l.id] = n;
      });

      // Какие подсети и VLAN живут в каждом домене
      const info = roots.map(() => ({ nets: new Set(), vlans: new Set(), hosts: 0 }));
      this.devices.forEach((d) => {
        const kind = this.type(d).kind;
        d.ifaces.forEach((itf, i) => {
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

  /* ===== Симуляция =====
     Работает событиями по тактам: кадр, отправленный на такте t, приходит на такте t+1.
     events: { type: 'hop', tick, link, from, to, frame, label, kind }
             { type: 'log', tick, dev, layer, text, level: info|ok|err|dim, frame? }
     ARP- и MAC-таблицы живут между запусками, как на настоящих устройствах (reset() их чистит). */
  const STORM_LIMIT = 1200;

  function describe(f) {
    if (f.type === 'arp') {
      return f.arp.op === 'request'
        ? { kind: 'arp', label: 'ARP-запрос: у кого ' + f.arp.tip + '?' }
        : { kind: 'arp', label: 'ARP-ответ: ' + f.arp.sip + ' это ' + f.arp.smac };
    }
    const p = f.ip;
    const t = p.icmp && p.icmp.type;
    if (t === 'echo-request') return { kind: 'req', label: 'ICMP эхо-запрос ' + p.src + ' → ' + p.dst + ', TTL ' + p.ttl };
    if (t === 'echo-reply') return { kind: 'rep', label: 'ICMP эхо-ответ ' + p.src + ' → ' + p.dst + ', TTL ' + p.ttl };
    if (t === 'time-exceeded') return { kind: 'err', label: 'ICMP: TTL истёк (' + p.src + ' → ' + p.dst + ')' };
    if (t === 'unreachable') return { kind: 'err', label: 'ICMP: сеть недоступна (' + p.src + ' → ' + p.dst + ')' };
    return { kind: 'req', label: 'IP ' + p.src + ' → ' + p.dst };
  }
  LAB.describe = describe;

  class Sim {
    constructor(net) {
      this.net = net;
      this.rt = {};
      this.pid = 0;
    }

    reset() { this.rt = {}; }

    tables(dev) { return this.rt[dev.id] || (this.rt[dev.id] = { arp: {}, mac: {} }); }

    begin() {
      this.events = [];
      this.queue = [];
      this.pending = {};
      this.results = [];
      this.count = 0;
      this.storm = false;
      this.lastTick = 0;
    }

    log(tick, dev, layer, text, level, frame) {
      this.events.push({ type: 'log', tick, dev: dev ? dev.id : null, layer, text, level: level || 'info', frame: frame || null });
    }

    send(tick, dev, ifIdx, frame) {
      const p = this.net.peer(dev.id, ifIdx);
      if (!p) {
        this.log(tick, dev, 1, dev.ifaces[ifIdx].name + ': кабель не подключён, кадр потерян', 'err');
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
        if (++this.count > STORM_LIMIT) {
          this.storm = true;
          this.queue = [];
          this.log(q.tick, null, 2, 'Широковещательный шторм: кадры бесконечно ходят по кольцу. В сети петля, а STP нет. Симуляция остановлена.', 'err');
          break;
        }
        this.receive(q.tick, q.dev, q.ifIdx, q.frame);
      }
      // Кому так и не ответили на ARP
      Object.values(this.pending).forEach((p) => {
        this.log(this.lastTick + 1, p.dev, 2, 'ARP: ' + p.nh + ' не ответил, пакеты отброшены', 'err');
        if (p.packets.some((pk) => pk.origin === p.dev.id)) this.results.push({ type: 'arp-fail', dev: p.dev.id, nh: p.nh });
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
      const out = dev.ifaces.map((p, i) => i).filter((i) => i !== ifIdx && dev.ifaces[i].link);
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
        const out = dev.ifaces.map((p, i) => i).filter((i) => i !== ifIdx && dev.ifaces[i].link && (dev.ifaces[i].vlan || 1) === vlan);
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
        if (!itf.ip || !itf.link) return;
        if (ip.same(dst, itf.ip, itf.prefix) && (!best || itf.prefix > best.prefix)) best = { prefix: itf.prefix, ifIdx: i, nh: dst, code: 'C' };
      });
      dev.routes.forEach((r) => {
        if (!ip.same(dst, r.net, r.prefix)) return;
        if (best && r.prefix <= best.prefix) return;
        const i = dev.ifaces.findIndex((itf) => itf.ip && itf.link && ip.same(r.via, itf.ip, itf.prefix));
        if (i >= 0) best = { prefix: r.prefix, ifIdx: i, nh: r.via, code: 'S', via: true };
      });
      return best;
    }

    ipSend(tick, dev, pkt) {
      const r = this.route(dev, pkt.dst);
      if (r.error) {
        this.log(tick, dev, 3, r.error + ', пакет отброшен', 'err');
        if (pkt.origin === dev.id) this.results.push({ type: 'local-err', text: r.error });
        // Чужой пакет: сообщить отправителю (но не отвечать ошибкой на ошибку)
        const isEcho = pkt.icmp && (pkt.icmp.type === 'echo-request' || pkt.icmp.type === 'echo-reply');
        if (pkt.fwd && isEcho && pkt.src && !this.isMine(dev, pkt.src)) {
          this.ipSend(tick, dev, { src: null, dst: pkt.src, ttl: this.net.type(dev).ttl, proto: 'icmp', icmp: { type: 'unreachable', id: pkt.icmp.id } });
        }
        return;
      }
      this.l2Out(tick, dev, r.ifIdx, r.nh, pkt);
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
      if (this.isMine(dev, p.dst)) return this.local(tick, dev, p, frame);

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

    local(tick, dev, p, frame) {
      const t = p.icmp && p.icmp.type;
      // Итог запуска считаем только у того, кто пинговал; остальные просто пишут в журнал
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

    /* --- Запуски --- */
    ping(srcId, dst, ttl) {
      this.begin();
      this.origin = srcId;
      const dev = this.net.dev(srcId);
      if (!dev) return this.finish();
      if (ip.parse(dst) == null) {
        this.results.push({ type: 'local-err', text: 'неверный адрес ' + dst });
        return this.finish();
      }
      dst = ip.str(ip.parse(dst));
      if (this.isMine(dev, dst)) {
        this.log(0, dev, 3, dst + ' это мой адрес, ответ сразу (loopback)', 'ok');
        this.results.push({ type: 'reply', from: dst, ttl: this.net.type(dev).ttl });
        return this.finish();
      }
      const pkt = { src: null, dst, ttl: ttl || this.net.type(dev).ttl, proto: 'icmp', icmp: { type: 'echo-request', id: ++this.pid, seq: 1 }, origin: dev.id };
      this.log(0, dev, 3, 'ping ' + dst + (ttl ? ' (TTL ' + ttl + ')' : '') + ': собираю ICMP эхо-запрос', 'info');
      this.ipSend(0, dev, pkt);
      this.drain();
      return this.finish();
    }

    tracert(srcId, dst, max) {
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
      return { events: all, hops, storm, ticks: offset };
    }

    finish() {
      const ticks = this.events.reduce((m, e) => Math.max(m, e.tick + (e.type === 'hop' ? 1 : 0)), 0);
      return { events: this.events, results: this.results, storm: this.storm, ticks };
    }
  }
  LAB.Sim = Sim;
})();
