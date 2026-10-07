// Общий JS сайта. Подключается из header.php.

// Печать заголовка: буквы появляются по одной, потом курсор мигает 3 раза и исчезает.
// Срабатывает на первом <h1> страницы; data-type="off" на заголовке отключает эффект.
(function () {
  let timer = null;

  function typeTitle(h1) {
    if (!h1) return;
    const text = h1.dataset.typeText || (h1.dataset.typeText = h1.textContent.trim());
    h1.setAttribute('aria-label', text);
    if (timer) { clearInterval(timer); timer = null; }

    const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    // Держим высоту, чтобы страница не прыгала, пока заголовок пустой
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
      // Если анимация не отыграла (вкладка в фоне), всё равно убираем курсор
      setTimeout(() => caret.remove(), 3000);
    };
    if (reduce || !text) { finish(); return; }

    // Длинные заголовки печатаются быстрее, чтобы вся анимация укладывалась примерно в секунду
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
    const h1 = [...document.querySelectorAll('h1')].find((el) => !el.closest('header, .site-header') && el.dataset.type !== 'off');
    if (h1) typeTitle(h1);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
