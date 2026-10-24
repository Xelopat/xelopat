/* ИИ CRM, демо: то, что нельзя взять из записанных ответов, считаем на месте.
   Поиск и фильтры по номенклатуре, поставщикам и позициям, вход по любым данным, новая заявка. */
(function () {
  'use strict';
  var cfg = window.__DEMO = window.__DEMO || {};
  cfg.handlers = cfg.handlers || [];
  var created = {};          // заявки, созданные в этой вкладке
  var nextId = 100;

  function rows(c, key) {
    var r = c.get(key);
    if (!r) return [];
    try { var v = JSON.parse(r.body); return Array.isArray(v) ? v : (v.results || []); } catch (e) { return []; }
  }
  function has(t, q) {
    var hay = t.join(' ').toLowerCase();
    return q.toLowerCase().split(/\s+/).filter(Boolean).every(function (w) { return hay.indexOf(w) >= 0; });
  }
  function page(c, list, asObject) {
    var lim = parseInt(c.params.get('limit') || '0', 10), off = parseInt(c.params.get('offset') || '0', 10);
    var out = lim ? list.slice(off, off + lim) : list;
    return asObject ? c.json(200, { count: list.length, next: null, previous: null, results: out }) : c.json(200, out);
  }

  cfg.handlers.push(function (c) {
    var p = c.path.replace(/\/*$/, '/'), m;

    if (c.method === 'POST' && p === '/api/auth/login/') {
      var me = JSON.parse(c.get('GET /api/auth/me/').body);
      localStorage.setItem('auth_token', 'demo-token');
      return c.json(200, { token: 'demo-token', user: me });
    }

    if (c.method === 'GET' && p === '/api/nomenclature/') {
      var all = rows(c, 'GET /api/nomenclature/?limit=1000');
      if (c.params.has('parent')) { var pv = c.params.get('parent'); all = all.filter(function (x) { return pv === '' ? x.parent === null : String(x.parent) === pv; }); }
      if (c.params.has('is_product')) { var ip = /^(1|true)$/i.test(c.params.get('is_product')); all = all.filter(function (x) { return !!x.is_product === ip; }); }
      var q = (c.params.get('q') || '').trim();
      if (q) all = all.filter(function (x) { return has([x.name, x.article, x.code_1c], q); });
      return page(c, all, true);
    }

    if (c.method === 'GET' && p === '/api/suppliers/') {
      var sup = rows(c, 'GET /api/suppliers/?limit=10000');
      if (/^true$/i.test(c.params.get('is_active') || '')) sup = sup.filter(function (x) { return x.is_active; });
      var sq = (c.params.get('q') || c.params.get('search') || '').trim();
      if (sq) sup = sup.filter(function (x) { return has([x.name, x.inn, x.city, x.brands, x.code_1c], sq); });
      return page(c, sup, c.params.has('offset'));
    }

    if (c.method === 'GET' && p === '/api/request-items/') {
      var it = rows(c, 'GET /api/request-items/?limit=10000');
      if (c.params.get('request')) it = it.filter(function (x) { return String(x.request) === c.params.get('request'); });
      if (c.params.get('product')) it = it.filter(function (x) { return String(x.product) === c.params.get('product'); });
      var iq = (c.params.get('q') || '').trim();
      if (iq) it = it.filter(function (x) { return has([x.raw_name, x.clean_name, x.code_1c], iq); });
      return page(c, it, false);
    }

    if (c.method === 'GET' && p === '/api/price-history/') {
      var pid = c.params.get('product_id');
      var hit = pid && c.get('GET /api/price-history/?limit=' + (c.params.get('limit') || '10') + '&product_id=' + pid);
      return hit || c.json(200, { count: 0, next: null, previous: null, results: [] });
    }
    if (c.method === 'GET' && p === '/api/price-history/recommendations/') {
      var rid = c.params.get('product_id');
      var rh = rid && c.get('GET /api/price-history/recommendations/?product_id=' + rid);
      return rh || c.json(200, []);
    }

    // Заявки, созданные в демо: список, карточка и матрица видят их
    if (c.method === 'POST' && p === '/api/requests/') {
      var tpl = JSON.parse(c.get('GET /api/requests/5/').body), b = {};
      try { b = typeof c.body === 'string' ? JSON.parse(c.body) : {}; } catch (e) { /* не JSON */ }
      var id = nextId++;
      var now = new Date().toISOString();
      var req = Object.assign({}, tpl, b, { id: id, number: String(id - 91), parent: null, status: 'new', client_deadline: b.client_deadline || null, object_name: b.object_name || '', comment: b.comment || '', items_count: 0, quotation_requests_count: 0, quotation_received_count: 0, created_at: now, updated_at: now, subrequests_count: 0 });
      created[id] = req;
      return c.json(201, req);
    }
    if (c.method === 'GET' && p === '/api/requests/') {
      var sp = Array.from(c.params.entries()).sort(function (a, b) { return a[0] < b[0] ? -1 : a[0] > b[0] ? 1 : (a[1] < b[1] ? -1 : 1); });
      var skey = 'GET ' + c.path + (sp.length ? '?' + sp.map(function (x) { return encodeURIComponent(x[0]) + '=' + encodeURIComponent(x[1]); }).join('&') : '');
      var base = c.get(skey) || c.get('GET /api/requests/?limit=500');
      var list = base ? JSON.parse(base.body) : [];
      var fresh = Object.keys(created).map(function (k) { return created[k]; }).reverse();
      if (!fresh.length) return null;
      return Array.isArray(list) ? c.json(200, fresh.concat(list)) : c.json(200, Object.assign({}, list, { count: (list.count || 0) + fresh.length, results: fresh.concat(list.results || []) }));
    }
    if ((m = /^\/api\/requests\/(\d+)\/(.*)$/.exec(c.path)) && created[m[1]]) {
      var r = created[m[1]], what = m[2].replace(/\/$/, '');
      if (c.method === 'GET' && what === '') return c.json(200, r);
      if (c.method === 'GET' && what === 'matrix') return c.json(200, { request: r, suppliers: [], items: [] });
      if (c.method === 'GET' && what === 'subrequests') return c.json(200, { parent_id: r.id, parent_number: r.number, subrequests: [] });
      if (c.method === 'GET') return c.json(200, []);
      if (what === '' && c.method !== 'DELETE') { try { Object.assign(r, JSON.parse(c.body)); } catch (e) { /* не JSON */ } return c.json(200, r); }
    }
    return null;
  });
})();
