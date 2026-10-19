/* Most в демо: сессия уже открыта (обновление токена отдаёт подставной токен до 2100 года),
   вход и выход работают без сервера, а веб-сокет заменён заглушкой, которая подключается и молчит. */
(function () {
  'use strict';
  var cfg = window.__DEMO = window.__DEMO || {};
  function b64(o) { return btoa(JSON.stringify(o)).replace(/=+$/, '').replace(/\+/g, '-').replace(/\//g, '_'); }
  var TOKEN = b64({ alg: 'HS256', typ: 'JWT' }) + '.' + b64({ token_type: 'access', exp: 4102444800, user_id: 'demo' }) + '.demo';

  (cfg.handlers = cfg.handlers || []).push(function (ctx) {
    if (ctx.method !== 'POST') return null;
    if (/\/auth\/token\/refresh\/?$/.test(ctx.path)) return ctx.json(200, { access: TOKEN });
    if (/\/auth\/token\/logout\/?$/.test(ctx.path)) return ctx.json(200, {});
    if (/\/auth\/login\/?$/.test(ctx.path)) return ctx.json(200, { user: { id: 'demo', type: 0 }, tokens: { access: TOKEN } });
    return null;
  });

  function DemoSocket(url) {
    var self = this;
    this.url = url; this.readyState = 0; this.protocol = ''; this.extensions = ''; this.bufferedAmount = 0; this.binaryType = 'blob';
    this._l = {};
    setTimeout(function () {
      self.readyState = 1;
      var ev = { type: 'open', target: self };
      if (self.onopen) self.onopen(ev);
      (self._l.open || []).forEach(function (f) { f(ev); });
    }, 50);
  }
  DemoSocket.prototype.send = function () {};
  DemoSocket.prototype.close = function () {
    this.readyState = 3;
    var ev = { type: 'close', code: 1000, wasClean: true, target: this };
    if (this.onclose) this.onclose(ev);
    (this._l.close || []).forEach(function (f) { f(ev); });
  };
  DemoSocket.prototype.addEventListener = function (t, f) { (this._l[t] = this._l[t] || []).push(f); };
  DemoSocket.prototype.removeEventListener = function (t, f) { this._l[t] = (this._l[t] || []).filter(function (x) { return x !== f; }); };
  DemoSocket.CONNECTING = 0; DemoSocket.OPEN = 1; DemoSocket.CLOSING = 2; DemoSocket.CLOSED = 3;
  window.WebSocket = DemoSocket;
})();
