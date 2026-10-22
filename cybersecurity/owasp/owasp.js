// Страница OWASP Top 10: списки рисков, сквозной поиск по темам и тренажёр.
// Новый список: файл в owasp/data/ с вызовом OWASP.addList({ id, order, title, full, year, url, intro, items }).
// Элемент списка: { id, en, ru, tags, short, what, example, defense: [], prev? }. Темы описаны в TAGS.
(function () {
  const OWASP = (window.OWASP = window.OWASP || { lists: [] });
  OWASP.addList = (l) => { OWASP.lists.push(l); };

  const TAGS = {
    access: 'Контроль доступа', auth: 'Аутентификация', inject: 'Инъекции и ввод', config: 'Конфигурация',
    supply: 'Цепочка поставок', crypto: 'Криптография и секреты', logging: 'Логи и мониторинг', design: 'Архитектура и логика',
    resource: 'Ресурсы и DoS', privacy: 'Приватность', comm: 'Передача данных', update: 'Обновления', ai: 'Специфика ИИ',
  };
  const MODES = [['lists', 'Списки'], ['topics', 'По темам'], ['quiz', 'Тренажёр']];

  function h(tag, attrs, ...kids) {
    const el = document.createElement(tag);
    Object.entries(attrs || {}).forEach(([k, v]) => {
      if (v == null || v === false) return;
      if (k === 'class') el.className = v;
      else if (k.startsWith('on')) el.addEventListener(k.slice(2).toLowerCase(), v);
      else el.setAttribute(k, v === true ? '' : v);
    });
    kids.flat(Infinity).forEach((c) => { if (c != null && c !== false) el.append(c.nodeType ? c : String(c)); });
    return el;
  }

  const st = { mode: 'lists', list: 'web', open: null, tag: 'access', quiz: null, score: { ok: 0, all: 0 }, quizList: 'all' };
  let root;

  // Адрес страницы хранит выбор: #lists/web/A01, #topics/access, #quiz
  function readHash() {
    const [mode, a, b] = location.hash.replace(/^#/, '').split('/');
    if (MODES.some(([m]) => m === mode)) st.mode = mode;
    if (st.mode === 'lists' && a && OWASP.lists.some((l) => l.id === a)) { st.list = a; st.open = b || null; }
    if (st.mode === 'topics' && a && TAGS[a]) st.tag = a;
  }
  function writeHash() {
    const parts = [st.mode];
    if (st.mode === 'lists') { parts.push(st.list); if (st.open) parts.push(st.open); }
    if (st.mode === 'topics') parts.push(st.tag);
    history.replaceState(null, '', '#' + parts.join('/'));
  }

  const listById = (id) => OWASP.lists.find((l) => l.id === id);

  /* ===== Карточка риска ===== */
  function card(l, it, opts) {
    const key = l.id + '/' + it.id;
    const open = opts.open === key;
    const el = h('article', { class: 'ow-card' + (open ? ' is-open' : ''), id: 'ow-' + l.id + '-' + it.id },
      h('button', { type: 'button', class: 'ow-card__head', 'aria-expanded': open ? 'true' : 'false', onClick: () => opts.toggle(key) },
        h('span', { class: 'ow-card__id' }, it.id),
        h('span', { class: 'ow-card__titles' },
          h('b', null, it.ru),
          h('small', null, it.en + (opts.showList ? ', ' + l.title + ' ' + l.year : ''))),
        h('span', { class: 'ow-card__fold', 'aria-hidden': 'true' })),
      h('p', { class: 'ow-card__short' }, it.short));
    if (open) {
      el.append(h('div', { class: 'ow-card__body' },
        h('div', { class: 'ow-sec' }, h('h4', null, 'Суть'), h('p', null, it.what)),
        h('div', { class: 'ow-sec ow-sec--example' }, h('h4', null, 'Пример атаки'), h('p', null, it.example)),
        h('div', { class: 'ow-sec' }, h('h4', null, 'Как защититься'), h('ul', null, it.defense.map((d) => h('li', null, d)))),
        h('div', { class: 'ow-card__foot' },
          (it.tags || []).map((t) => h('button', { type: 'button', class: 'ow-tag', title: 'Все риски этой темы во всех списках', onClick: () => { st.mode = 'topics'; st.tag = t; st.open = null; render(); } }, TAGS[t] || t)),
          it.prev ? h('span', { class: 'ow-prev' }, it.prev) : null)));
    }
    return el;
  }

  /* ===== Режимы ===== */
  function renderLists(box) {
    const l = listById(st.list) || OWASP.lists[0];
    box.append(
      h('div', { class: 'ow-chips', role: 'tablist', 'aria-label': 'Списки OWASP' }, OWASP.lists.map((x) => h('button', {
        type: 'button', role: 'tab', class: 'ow-chip' + (x.id === l.id ? ' active' : ''), 'aria-selected': x.id === l.id ? 'true' : 'false',
        onClick: () => { st.list = x.id; st.open = null; render(); },
      }, x.title, h('span', { class: 'ow-chip__year' }, String(x.year))))),
      h('section', { class: 'ow-intro panel' },
        h('div', { class: 'ow-intro__head' }, h('h2', null, l.full + ' (' + l.year + ')'), h('a', { href: l.url, target: '_blank', rel: 'noopener' }, 'официальная страница')),
        h('p', null, l.intro),
        l.prev ? h('p', { class: 'ow-intro__prev' }, l.prev) : null),
      h('div', { class: 'ow-grid' }, l.items.map((it) => card(l, it, { open: st.open ? l.id + '/' + st.open : null, toggle: (key) => { const id = key.split('/')[1]; st.open = st.open === id ? null : id; render(); scrollToOpen(); } }))));
  }

  function renderTopics(box) {
    const found = [];
    OWASP.lists.forEach((l) => l.items.forEach((it) => { if ((it.tags || []).includes(st.tag)) found.push({ l, it }); }));
    box.append(
      h('p', { class: 'ow-lead' }, 'Одни и те же классы проблем встречаются в разных списках: в вебе, API, мобильных приложениях и ИИ. Выбери тему и сравни, как она выглядит в каждом мире.'),
      h('div', { class: 'ow-chips' }, Object.entries(TAGS).map(([t, name]) => h('button', { type: 'button', class: 'ow-chip' + (t === st.tag ? ' active' : ''), onClick: () => { st.tag = t; st.open = null; render(); } }, name))),
      h('p', { class: 'ow-count' }, 'Рисков с темой «' + TAGS[st.tag] + '»: ' + found.length + ' в ' + new Set(found.map((x) => x.l.id)).size + ' списках'),
      h('div', { class: 'ow-grid' }, found.map(({ l, it }) => card(l, it, { showList: true, open: st.open, toggle: (key) => { st.open = st.open === key ? null : key; render(); scrollToOpen(); } }))));
  }

  // Тренажёр: по примеру атаки угадать категорию из четырёх вариантов
  function newQuestion() {
    const pool = [];
    OWASP.lists.forEach((l) => { if (st.quizList === 'all' || st.quizList === l.id) l.items.forEach((it) => pool.push({ l, it })); });
    const right = pool[Math.floor(Math.random() * pool.length)];
    // Неправильные варианты берём из того же списка: так вопрос честнее
    const same = right.l.items.filter((x) => x !== right.it);
    const opts = [right.it];
    while (opts.length < 4 && same.length) opts.push(same.splice(Math.floor(Math.random() * same.length), 1)[0]);
    opts.sort(() => Math.random() - 0.5);
    st.quiz = { l: right.l, it: right.it, opts, picked: null };
  }

  function renderQuiz(box) {
    if (!st.quiz) newQuestion();
    const q = st.quiz;
    box.append(
      h('div', { class: 'ow-quiz__top' },
        h('div', { class: 'ow-chips' },
          h('button', { type: 'button', class: 'ow-chip' + (st.quizList === 'all' ? ' active' : ''), onClick: () => { st.quizList = 'all'; newQuestion(); render(); } }, 'Все списки'),
          OWASP.lists.map((l) => h('button', { type: 'button', class: 'ow-chip' + (st.quizList === l.id ? ' active' : ''), onClick: () => { st.quizList = l.id; newQuestion(); render(); } }, l.title))),
        h('span', { class: 'ow-score' }, 'Верно: ' + st.score.ok + ' из ' + st.score.all)),
      h('section', { class: 'ow-quiz panel' },
        h('div', { class: 'ow-quiz__label' }, q.l.full + ' (' + q.l.year + '): какая это категория?'),
        h('p', { class: 'ow-quiz__q' }, q.it.example),
        h('div', { class: 'ow-quiz__opts' }, q.opts.map((o) => {
          const state = q.picked == null ? '' : o === q.it ? ' is-right' : o === q.picked ? ' is-wrong' : ' is-dim';
          return h('button', { type: 'button', class: 'ow-opt' + state, disabled: q.picked != null, onClick: () => {
            q.picked = o;
            st.score.all++;
            if (o === q.it) st.score.ok++;
            render();
          } }, h('span', { class: 'ow-opt__id' }, o.id), h('span', null, o.ru));
        })),
        q.picked != null ? h('div', { class: 'ow-quiz__answer' + (q.picked === q.it ? ' is-ok' : ' is-err') },
          h('b', null, (q.picked === q.it ? 'Верно: ' : 'Правильный ответ: ') + q.it.id + ' ' + q.it.ru),
          h('p', null, q.it.what),
          h('div', { class: 'ow-quiz__next' },
            h('button', { type: 'button', class: 'btn btn--primary', onClick: () => { newQuestion(); render(); } }, 'Следующий вопрос'),
            h('button', { type: 'button', class: 'btn', onClick: () => { st.mode = 'lists'; st.list = q.l.id; st.open = q.it.id; render(); scrollToOpen(); } }, 'Подробнее о категории'))) : null));
  }

  function scrollToOpen() {
    requestAnimationFrame(() => {
      const el = root.querySelector('.ow-card.is-open');
      if (el && el.getBoundingClientRect().top < 80) el.scrollIntoView({ block: 'start', behavior: 'smooth' });
    });
  }

  function render() {
    const tabs = h('div', { class: 'osi-tabs', role: 'tablist' }, MODES.map(([m, name]) => h('button', {
      type: 'button', role: 'tab', class: 'osi-tab' + (m === st.mode ? ' active' : ''), 'aria-selected': m === st.mode ? 'true' : 'false',
      onClick: () => { if (st.mode !== m) { st.mode = m; st.open = null; render(); } },
    }, name)));
    const box = h('div', { class: 'ow-panel' });
    if (st.mode === 'lists') renderLists(box);
    else if (st.mode === 'topics') renderTopics(box);
    else renderQuiz(box);
    root.replaceChildren(tabs, box);
    writeHash();
  }

  function boot() {
    root = document.getElementById('owasp');
    if (!root) return;
    OWASP.lists.sort((a, b) => a.order - b.order);
    readHash();
    render();
  }
  // Файлы списков подключены после этого скрипта (defer): ждём, пока выполнятся все
  if (document.readyState === 'complete') setTimeout(boot);
  else document.addEventListener('DOMContentLoaded', boot);
})();
