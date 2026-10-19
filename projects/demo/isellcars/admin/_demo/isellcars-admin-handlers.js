/* Админка isellcars в демо: вход уже выполнен подставным токеном со сроком до 2100 года,
   после выхода войти можно с любым логином и паролем. */
(function () {
  'use strict';
  var cfg = window.__DEMO = window.__DEMO || {};
  function b64(o) { return btoa(JSON.stringify(o)).replace(/=+$/, '').replace(/\+/g, '-').replace(/\//g, '_'); }
  var TOKEN = b64({ alg: 'HS256', typ: 'JWT' }) + '.' + b64({ token_type: 'access', exp: 4102444800, user_id: 1 }) + '.demo';
  try {
    if (!localStorage.getItem('access_token')) {
      localStorage.setItem('access_token', TOKEN);
      localStorage.setItem('refresh_token', TOKEN);
    }
  } catch (e) { /* хранилище недоступно: покажется форма входа, она тоже работает */ }

  (cfg.handlers = cfg.handlers || []).push(function (ctx) {
    if (ctx.method === 'POST' && /\/api\/admin\/auth\/(login|refresh)\/?$/.test(ctx.path)) return ctx.json(200, { access: TOKEN, refresh: TOKEN });
    if (/\/api\/admin\/auth\/logout\/?$/.test(ctx.path)) return ctx.json(200, {});
    return null;
  });
})();
