/* Киоск в демо: админка в оригинале открывается сочетанием Пробел+Y, здесь — по адресу ?admin.
   Приложение ловит Y, пока зажат пробел, поэтому жмём их по очереди с паузой на перерисовку. */
(function () {
  'use strict';
  if (!/[?&]admin(=|&|$)/.test(location.search)) return;
  function key(type, code, k) { window.dispatchEvent(new KeyboardEvent(type, { code: code, key: k, bubbles: true })); }
  var tries = 0;
  (function open() {
    if (!document.querySelector('#root svg') && tries++ < 50) return setTimeout(open, 200);
    key('keydown', 'Space', ' ');
    setTimeout(function () {
      key('keydown', 'KeyY', 'y');
      setTimeout(function () { key('keyup', 'KeyY', 'y'); key('keyup', 'Space', ' '); }, 50);
    }, 120);
  })();
})();
