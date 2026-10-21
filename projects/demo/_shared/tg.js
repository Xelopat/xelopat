/* Мини-движок окна Telegram для демо: сообщения, кнопки под сообщением, набор текста. */
(function () {
  'use strict';
  window.TG = function (chat) {
    var api = { run: 0 };
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    api.tm = function () { var d = new Date(); return pad(d.getHours()) + ':' + pad(d.getMinutes()); };
    api.wait = function (ms) { return new Promise(function (r) { setTimeout(r, ms); }); };
    api.esc = function (s) { return String(s).replace(/[&<>]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]; }); };
    api.scroll = function () { chat.scrollTop = chat.scrollHeight; };
    api.reset = function () { api.run++; chat.innerHTML = ''; };
    api.me = function (t) {
      var m = document.createElement('div'); m.className = 'msg me';
      m.innerHTML = api.esc(t) + '<span class="tm">' + api.tm() + '</span>'; chat.appendChild(m); api.scroll();
    };
    api.sys = function (t) { var m = document.createElement('div'); m.className = 'sys'; m.textContent = t; chat.appendChild(m); api.scroll(); };
    api.typing = async function (ms) {
      var id = api.run, t = document.createElement('div'); t.className = 'typing'; t.innerHTML = '<i></i><i></i><i></i>';
      chat.appendChild(t); api.scroll(); await api.wait(ms || 550); t.remove(); return id === api.run;
    };
    // rows: [[ [label, handler], ... ], ...] кнопки под сообщением; после нажатия ряд блокируется
    api.buttons = function (rows) {
      var w = document.createElement('div'); w.className = 'inl';
      rows.forEach(function (row) {
        var r = document.createElement('div'); r.className = 'r';
        row.forEach(function (b) {
          var x = document.createElement('button'); x.type = 'button'; x.textContent = b[0];
          x.onclick = function () {
            if (x.disabled) return;
            if (b[2] !== 'keep') [].forEach.call(w.querySelectorAll('button'), function (y) { y.disabled = true; });
            b[1](b[0], w);
          };
          r.appendChild(x);
        });
        w.appendChild(r);
      });
      chat.appendChild(w); api.scroll(); return w;
    };
    api.bot = async function (html, rows, ms) {
      if (!(await api.typing(ms))) return false;
      var m = document.createElement('div'); m.className = 'msg bot';
      m.innerHTML = html + '<span class="tm">' + api.tm() + '</span>'; chat.appendChild(m);
      if (rows) api.buttons(rows);
      api.scroll(); return true;
    };
    return api;
  };
})();
