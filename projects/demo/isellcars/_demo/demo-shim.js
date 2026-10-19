/* Демо-режим: запросы к API не уходят в сеть, а получают записанные ответы.
   Изменения (POST, PUT, PATCH, DELETE) живут в памяти вкладки: правки видны, пока страница открыта.
   Настройка приходит в window.__DEMO = { match: [префиксы], strip: [что срезать с начала пути], api: {"GET /path?q": {status, type, body}}, handlers: [fn] } */
(function () {
  'use strict';
  var cfg = window.__DEMO || {};
  var api = cfg.api || {};
  var handlers = cfg.handlers || [];
  var store = {};

  function parse(url) {
    var u = new URL(url, location.href);
    var full = u.origin + u.pathname;
    var path = null;
    (cfg.match || []).forEach(function (m) {
      if (path !== null) return;
      if (m.indexOf('://') > 0 ? full.indexOf(m) === 0 : (u.origin === location.origin && u.pathname.indexOf(m) === 0)) path = u.pathname;
    });
    if (path === null) return null;
    (cfg.strip || []).forEach(function (s) { if (path.indexOf(s) === 0 && path.length > s.length) path = path.slice(s.length); });
    var params = Array.from(u.searchParams.entries()).sort(function (a, b) { return a[0] < b[0] ? -1 : a[0] > b[0] ? 1 : (a[1] < b[1] ? -1 : 1); });
    var q = params.length ? '?' + params.map(function (p) { return encodeURIComponent(p[0]) + '=' + encodeURIComponent(p[1]); }).join('&') : '';
    return { path: path, query: q, params: u.searchParams };
  }

  function sortedKey(key) {
    var i = key.indexOf('?');
    if (i < 0) return key;
    var head = key.slice(0, i), sp = new URLSearchParams(key.slice(i + 1));
    var p = Array.from(sp.entries()).sort(function (a, b) { return a[0] < b[0] ? -1 : a[0] > b[0] ? 1 : (a[1] < b[1] ? -1 : 1); });
    return head + '?' + p.map(function (x) { return encodeURIComponent(x[0]) + '=' + encodeURIComponent(x[1]); }).join('&');
  }
  var index = {};
  Object.keys(api).forEach(function (k) { index[sortedKey(k)] = api[k]; });

  function get(key) { return Object.prototype.hasOwnProperty.call(store, key) ? store[key] : index[key]; }
  function json(status, value) { return { status: status, type: 'application/json', body: JSON.stringify(value) }; }

  function bodyToObject(body) {
    if (body == null) return {};
    if (typeof body === 'string') { try { return JSON.parse(body); } catch (e) { return Object.fromEntries(new URLSearchParams(body)); } }
    if (typeof FormData !== 'undefined' && body instanceof FormData) {
      var o = {};
      body.forEach(function (v, k) { if (typeof v === 'string') o[k] = v; });
      return o;
    }
    return {};
  }

  // Списки с объектом id: массив или {results: [...]}
  function eachList(prefix, fn) {
    Object.keys(index).concat(Object.keys(store)).forEach(function (k) {
      if (k.indexOf('GET ' + prefix) !== 0) return;
      var r = get(k);
      if (!r) return;
      var v;
      try { v = JSON.parse(r.body); } catch (e) { return; }
      var arr = Array.isArray(v) ? v : (v && Array.isArray(v.results) ? v.results : null);
      if (!arr) return;
      if (fn(arr, v) !== false) store[k] = { status: r.status, type: r.type, body: JSON.stringify(v) };
    });
  }

  function mutate(method, p, body) {
    var path = p.path.replace(/\/+$/, '');
    var m = /^(.*)\/([^/]+)$/.exec(path);
    var obj = bodyToObject(body);
    if (method === 'POST') {
      var created = null;
      eachList(p.path, function (arr, whole) {
        if (created) return false;
        var max = arr.reduce(function (a, x) { return typeof x.id === 'number' && x.id > a ? x.id : a; }, 0);
        created = Object.assign({}, arr[0] || {}, obj, { id: max + 1 });
        arr.unshift(created);
        if (whole && typeof whole.count === 'number') whole.count += 1;
      });
      return json(201, created || Object.assign({ id: Date.now() }, obj, { ok: true, success: true }));
    }
    if (!m) return json(200, Object.assign({ ok: true }, obj));
    var listPath = m[1] + '/', id = m[2];
    var detailKey = 'GET ' + p.path;
    var detail = get(detailKey);
    var updated = null;
    if (detail && method !== 'DELETE') {
      try {
        updated = Object.assign(JSON.parse(detail.body), obj);
        store[detailKey] = { status: 200, type: detail.type, body: JSON.stringify(updated) };
      } catch (e) { /* не JSON */ }
    }
    eachList(listPath, function (arr, whole) {
      var i = arr.findIndex(function (x) { return x && String(x.id) === id; });
      if (i < 0) return false;
      if (method === 'DELETE') { arr.splice(i, 1); if (whole && typeof whole.count === 'number') whole.count -= 1; }
      else arr[i] = Object.assign(arr[i], obj);
    });
    if (method === 'DELETE') return { status: 204, type: 'application/json', body: '' };
    return json(200, updated || Object.assign({ id: isNaN(+id) ? id : +id }, obj));
  }

  function respond(method, url, body) {
    var p = parse(url);
    if (!p) return null;
    method = (method || 'GET').toUpperCase();
    var key = method + ' ' + p.path + p.query;
    for (var i = 0; i < handlers.length; i++) {
      var h = handlers[i]({ method: method, path: p.path, params: p.params, body: body, get: get, index: index, json: json });
      if (h) return h;
    }
    var hit = get(key) || get(method + ' ' + p.path) || (method === 'GET' ? get(method + ' ' + p.path.replace(/\/?$/, '/') + p.query) : null);
    if (!hit && method === 'GET') {
      // тот же путь с другими параметрами: берём первый записанный
      var prefix = 'GET ' + p.path + '?';
      var k = Object.keys(index).find(function (x) { return x.indexOf(prefix) === 0; });
      if (k) hit = get(k);
    }
    if (hit) return hit;
    if (method === 'GET') return json(404, { detail: 'Нет в демо' });
    return mutate(method, p, body);
  }
  window.__demoRespond = respond;

  var realFetch = window.fetch ? window.fetch.bind(window) : null;
  window.fetch = function (input, init) {
    var url = typeof input === 'string' ? input : (input && input.url) || String(input);
    var method = (init && init.method) || (input && input.method) || 'GET';
    var r = respond(method, url, init && init.body);
    if (!r) return realFetch(input, init);
    var status = r.status === 204 ? 204 : r.status;
    return new Promise(function (resolve) {
      setTimeout(function () {
        resolve(new Response(status === 204 ? null : r.body, { status: status, headers: { 'Content-Type': r.type || 'application/json' } }));
      }, 120);
    });
  };

  var RealXHR = window.XMLHttpRequest;
  function DemoXHR() {
    var real = new RealXHR(), self = this, method = 'GET', url = '', headers = {};
    this.readyState = 0; this.status = 0; this.responseText = ''; this.response = ''; this.responseType = '';
    this.upload = real.upload; this.withCredentials = false; this.timeout = 0;
    this.open = function (m, u) { method = m; url = u; self._demo = parse(u) !== null; if (!self._demo) real.open.apply(real, arguments); self.readyState = 1; };
    this.setRequestHeader = function (k, v) { headers[k] = v; if (!self._demo) real.setRequestHeader(k, v); };
    this.getAllResponseHeaders = function () { return self._demo ? 'content-type: ' + (self._type || 'application/json') + '\r\n' : real.getAllResponseHeaders(); };
    this.getResponseHeader = function (k) { return self._demo ? (/content-type/i.test(k) ? self._type || 'application/json' : null) : real.getResponseHeader(k); };
    this.abort = function () { if (!self._demo) real.abort(); };
    this.overrideMimeType = function () {};
    this.send = function (body) {
      if (!self._demo) {
        real.onreadystatechange = function () {
          ['readyState', 'status', 'statusText', 'responseText', 'response', 'responseURL'].forEach(function (k) { try { self[k] = real[k]; } catch (e) { /* только чтение */ } });
          if (self.onreadystatechange) self.onreadystatechange();
        };
        ['onload', 'onerror', 'onabort', 'ontimeout', 'onloadend'].forEach(function (ev) { real[ev] = function (e) { if (self[ev]) self[ev](e); }; });
        real.responseType = self.responseType;
        real.send(body);
        return;
      }
      var r = respond(method, url, body);
      setTimeout(function () {
        self._type = r.type;
        self.readyState = 4; self.status = r.status; self.statusText = String(r.status);
        self.responseURL = new URL(url, location.href).href;
        self.responseText = r.body || '';
        self.response = self.responseType === 'json' ? (r.body ? JSON.parse(r.body) : null) : self.responseText;
        if (self.onreadystatechange) self.onreadystatechange();
        if (self.onload) self.onload({ type: 'load' });
        if (self.onloadend) self.onloadend({ type: 'loadend' });
        (self._listeners.load || []).forEach(function (f) { f({ type: 'load' }); });
        (self._listeners.loadend || []).forEach(function (f) { f({ type: 'loadend' }); });
      }, 120);
    };
    this._listeners = {};
    this.addEventListener = function (t, f) { (self._listeners[t] = self._listeners[t] || []).push(f); if (!self._demo) real.addEventListener(t, f); };
    this.removeEventListener = function () {};
  }
  DemoXHR.UNSENT = 0; DemoXHR.OPENED = 1; DemoXHR.HEADERS_RECEIVED = 2; DemoXHR.LOADING = 3; DemoXHR.DONE = 4;
  window.XMLHttpRequest = DemoXHR;
})();
