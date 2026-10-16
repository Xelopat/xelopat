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

// Бургер на узких экранах: меню выезжает панелью, как на основном сайте
(function () {
  function init() {
    const nav = document.getElementById('navRoot');
    const btn = document.getElementById('burgerBtn');
    if (!nav || !btn) return;
    const set = (on) => {
      nav.classList.toggle('open', on);
      btn.setAttribute('aria-expanded', on ? 'true' : 'false');
      document.body.classList.toggle('nav-open', on);
    };
    btn.addEventListener('click', (e) => { e.stopPropagation(); set(!nav.classList.contains('open')); });
    nav.addEventListener('click', (e) => { if (e.target.closest('a[href]')) set(false); });
    document.addEventListener('click', (e) => { if (!e.target.closest('.site-header')) set(false); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') set(false); });
    window.addEventListener('resize', () => { if (window.innerWidth > 900) set(false); });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();

// Фон всех страниц поддомена (копия фона из assets/site.js основного сайта): еле заметная сеть, узлы медленно дрейфуют, по связям изредка пробегает пакет.
// Холст фиксирован под контентом; в фоновой вкладке анимация стоит, при «уменьшить движение» кадр статичный.
(function () {
  if (window.__siteNet) return;
  window.__siteNet = true;
  // В лаборатории своё полотно на весь экран, фон там только мешает
  if (location.pathname.indexOf('/lab') === 0) return;
  const cv = document.createElement('canvas');
  if (!cv.getContext) return;
  cv.className = 'site-net';
  cv.setAttribute('aria-hidden', 'true');
  document.body.prepend(cv);
  const ctx = cv.getContext('2d');
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  let w = 0, h = 0, link = 150, nodes = [], packets = [], mouse = null, raf = 0, lastSpawn = 0;

  function fit() {
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    const nw = window.innerWidth, nh = window.innerHeight;
    const reseed = nw !== w || !nodes.length;
    w = nw; h = nh;
    cv.width = Math.round(w * dpr);
    cv.height = Math.round(h * dpr);
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    link = w < 640 ? 110 : 150;
    // Высота на телефоне меняется при прокрутке (панель адреса), узлы при этом не пересоздаём
    if (reseed) {
      const count = Math.max(18, Math.min(70, Math.round(w * h / 18000)));
      nodes = Array.from({ length: count }, function () {
        return { x: Math.random() * w, y: Math.random() * h, vx: (Math.random() - .5) * .25, vy: (Math.random() - .5) * .25 };
      });
      packets = [];
    }
    if (reduce) draw(0);
  }

  function spawn() {
    const a = nodes[Math.floor(Math.random() * nodes.length)];
    const near = nodes.filter(function (b) { return b !== a && Math.hypot(a.x - b.x, a.y - b.y) < link; });
    if (!near.length) return;
    packets.push({ a: a, b: near[Math.floor(Math.random() * near.length)], t: 0, v: .006 + Math.random() * .008 });
  }

  function draw(now) {
    ctx.clearRect(0, 0, w, h);
    if (!reduce) {
      for (const n of nodes) {
        n.x += n.vx; n.y += n.vy;
        if (n.x < 0 || n.x > w) n.vx *= -1;
        if (n.y < 0 || n.y > h) n.vy *= -1;
      }
    }
    ctx.lineWidth = 1;
    for (let i = 0; i < nodes.length; i += 1) {
      const a = nodes[i];
      for (let j = i + 1; j < nodes.length; j += 1) {
        const b = nodes[j];
        const d = Math.hypot(a.x - b.x, a.y - b.y);
        if (d < link) {
          ctx.strokeStyle = 'rgba(148,150,168,' + ((1 - d / link) * .06).toFixed(3) + ')';
          ctx.beginPath(); ctx.moveTo(a.x, a.y); ctx.lineTo(b.x, b.y); ctx.stroke();
        }
      }
      if (mouse) {
        const d = Math.hypot(a.x - mouse.x, a.y - mouse.y);
        if (d < link * 1.3) {
          ctx.strokeStyle = 'rgba(249,201,64,' + ((1 - d / (link * 1.3)) * .1).toFixed(3) + ')';
          ctx.beginPath(); ctx.moveTo(a.x, a.y); ctx.lineTo(mouse.x, mouse.y); ctx.stroke();
        }
      }
    }
    ctx.fillStyle = 'rgba(148,150,168,.15)';
    for (const n of nodes) { ctx.beginPath(); ctx.arc(n.x, n.y, 1.5, 0, Math.PI * 2); ctx.fill(); }
    if (reduce) return;

    if (now - lastSpawn > 1200 && packets.length < 4) { spawn(); lastSpawn = now; }
    packets = packets.filter(function (p) {
      p.t += p.v;
      if (p.t >= 1 || Math.hypot(p.a.x - p.b.x, p.a.y - p.b.y) > link) return false;
      const x = p.a.x + (p.b.x - p.a.x) * p.t, y = p.a.y + (p.b.y - p.a.y) * p.t;
      ctx.shadowColor = 'rgba(249,201,64,.25)';
      ctx.shadowBlur = 5;
      ctx.fillStyle = 'rgba(249,201,64,.3)';
      ctx.beginPath(); ctx.arc(x, y, 1.6, 0, Math.PI * 2); ctx.fill();
      ctx.shadowBlur = 0;
      return true;
    });
  }

  function loop(now) {
    raf = 0;
    if (document.hidden) return;
    draw(now);
    raf = requestAnimationFrame(loop);
  }
  function play() { if (!reduce && !raf) raf = requestAnimationFrame(loop); }

  window.addEventListener('pointermove', function (e) {
    mouse = e.pointerType === 'mouse' ? { x: e.clientX, y: e.clientY } : null;
  }, { passive: true });
  document.documentElement.addEventListener('pointerleave', function () { mouse = null; });
  document.addEventListener('visibilitychange', play);
  let resizeTimer = 0;
  window.addEventListener('resize', function () {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(fit, 120);
  });
  fit();
  play();
})();
