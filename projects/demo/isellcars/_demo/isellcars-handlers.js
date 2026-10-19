/* isellcars: фильтры каталога в демо. Для каждого значения фильтра записан свой список машин,
   здесь они складываются как на сервере: значения одного фильтра через «или», разные фильтры через «и». */
(function () {
  'use strict';
  var cfg = window.__DEMO = window.__DEMO || {};
  var FILTERS = ['brand', 'year', 'drive', 'transmission', 'fuel', 'color', 'bodyType', 'condition', 'steering', 'availability', 'category', 'categorySystem', 'feature'];
  var ALL = 200;

  function list(ctx, locale, extra) {
    var sp = new URLSearchParams(Object.assign({ locale: locale, page_size: String(ALL) }, extra || {}));
    var key = 'GET /api/catalog/list?' + Array.from(sp.entries()).sort(function (a, b) { return a[0] < b[0] ? -1 : 1; })
      .map(function (p) { return encodeURIComponent(p[0]) + '=' + encodeURIComponent(p[1]); }).join('&');
    var r = ctx.get(key);
    if (!r) return null;
    try { return JSON.parse(r.body).results || []; } catch (e) { return null; }
  }

  (cfg.handlers = cfg.handlers || []).push(function (ctx) {
    if (ctx.method !== 'GET' || ctx.path !== '/api/catalog/list') return null;
    var p = ctx.params;
    var locale = p.get('locale') === 'en' ? 'en' : 'ru';
    var used = FILTERS.filter(function (k) { return p.getAll(k).length; });
    var search = (p.get('search') || '').trim().toLowerCase();
    // Без фильтров, поиска и пагинации отдаём записанный ответ как есть
    if (!used.length && !search && !p.get('page_size') && !p.get('page')) return null;
    var items = list(ctx, locale);
    if (!items) return null;
    used.forEach(function (k) {
      var ids = {};
      p.getAll(k).forEach(function (v) {
        var o = {}; o[k] = v;
        (list(ctx, locale, o) || []).forEach(function (c) { ids[c.id] = true; });
      });
      items = items.filter(function (c) { return ids[c.id]; });
    });
    if (search) {
      items = items.filter(function (c) {
        return [c.brand_name, c.model_name, c.title, c.slug].join(' ').toLowerCase().indexOf(search) >= 0;
      });
    }
    var size = Math.max(1, parseInt(p.get('page_size') || '12', 10));
    var page = Math.max(1, parseInt(p.get('page') || '1', 10));
    var total = Math.max(1, Math.ceil(items.length / size));
    return ctx.json(200, { results: items.slice((page - 1) * size, page * size), count: items.length, page: page, page_size: size, total_pages: total });
  });
})();
