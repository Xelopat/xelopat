// Общий JS поддомена. Печать первого <h1>, как на основном сайте (assets/site.js);
// data-type="off" на заголовке отключает эффект.
(function () {
  let timer = null;

  function typeTitle(h1) {
    if (!h1) return;
    const text = h1.dataset.typeText || (h1.dataset.typeText = h1.textContent.trim());
    h1.setAttribute('aria-label', text);
    if (timer) { clearInterval(timer); timer = null; }

    const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    h1.style.minHeight = h1.offsetHeight + 'px';
    h1.textContent = '';
    const out = document.createElement('span');
    out.setAttribute('aria-hidden', 'true');
    const caret = document.createElement('span');
    caret.className = 'type-caret';
    caret.setAttribute('aria-hidden', 'true');
    h1.append(out, caret);

    const finish = () => {
      out.textContent = text;
      h1.style.minHeight = '';
      caret.classList.add('blink');
      caret.addEventListener('animationend', () => caret.remove(), { once: true });
      setTimeout(() => caret.remove(), 2800);
    };
    if (reduce || !text) { finish(); return; }

    const step = Math.max(28, Math.min(72, 1100 / text.length));
    let i = 0;
    timer = setInterval(() => {
      i += 1;
      out.textContent = text.slice(0, i);
      if (i >= text.length) { clearInterval(timer); timer = null; finish(); }
    }, step);
  }

  window.siteTypeTitle = typeTitle;

  function start() {
    const h1 = [...document.querySelectorAll('h1')].find((el) => !el.closest('.site-header') && el.dataset.type !== 'off');
    if (h1) typeTitle(h1);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();

// Выпадающие каталоги шапки: наведение на компьютере, нажатие на телефоне, Esc и щелчок мимо закрывают
(function () {
  function init() {
    const dds = [...document.querySelectorAll('[data-dd]')];
    const closeAll = (except) => dds.forEach((d) => {
      if (d === except) return;
      d.classList.remove('open');
      d.querySelector('.nav-dd').setAttribute('aria-expanded', 'false');
    });
    const set = (d, on) => {
      if (on) closeAll(d);
      d.classList.toggle('open', on);
      d.querySelector('.nav-dd').setAttribute('aria-expanded', on ? 'true' : 'false');
    };
    const hover = window.matchMedia('(hover: hover)');
    dds.forEach((d) => {
      let t = null;
      d.querySelector('.nav-dd').addEventListener('click', (e) => { e.stopPropagation(); set(d, !d.classList.contains('open')); });
      d.addEventListener('mouseenter', () => { if (!hover.matches) return; clearTimeout(t); set(d, true); });
      d.addEventListener('mouseleave', () => { if (!hover.matches) return; t = setTimeout(() => set(d, false), 300); });
    });
    document.addEventListener('click', (e) => { if (!e.target.closest('[data-dd]')) closeAll(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeAll(); });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
