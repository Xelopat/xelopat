// Справочник сценариев атак. Назначение защитное: механика, безопасная самопроверка стенда,
// обнаружение в логах, защита, проверка исправления. Карточки связаны с категориями OWASP.
//
// SCENARIO FIELDS: { id, num, cat, name, full, aliases[], section,
//   owaspWeb[], owaspApi[] (коды категорий -> ссылки), owaspWebNote?, owaspApiNote?,
//   cwe[], cweNote?, capec[], attack[], attackNote?, property,
//   essence, boundary, preconditions[], chain,
//   labCheck[], markers[], detection[], defense[], regression[] }
// Новый список: файл в attacks/scenarios/ с ATTACKS.add([...]).
(function () {
  const ATTACKS = (window.ATTACKS = window.ATTACKS || { items: [] });
  ATTACKS.add = (arr) => { arr.forEach((s) => ATTACKS.items.push(s)); };

  // Разделы: порядок и подписи вкладок-фильтров
  const CATS = {
    access: 'Контроль доступа', injection: 'Инъекции', session: 'Сессии и вход',
    identity: 'Токены и федерация', logic: 'Бизнес-логика', network: 'Сеть и SSRF',
    http: 'HTTP и прокси', mobile: 'Mobile', supply: 'Цепочка поставок',
  };
  // Куда ведут коды OWASP. Раздел owasp того же поддомена, вкладка «Списки».
  const OWASP_URL = (list, code) => '../owasp#lists/' + list + '/' + code;
  const OWASP_LISTS = { web: 'OWASP Web 2025', api: 'OWASP API 2023' };
  const MODES = [['scenarios', 'Сценарии'], ['owasp', 'Связь с OWASP'], ['quiz', 'Тренажёр']];

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

  const st = { mode: 'scenarios', cat: 'all', open: null, quiz: null, score: { ok: 0, all: 0 } };
  let root, lastMode = null;
  const byId = (id) => ATTACKS.items.find((s) => s.id === id);

  function readHash() {
    const [mode, a] = location.hash.replace(/^#/, '').split('/');
    if (MODES.some(([m]) => m === mode)) st.mode = mode;
    if (st.mode === 'scenarios' && a) { if (CATS[a]) st.cat = a; else if (byId(a)) st.open = a; }
  }
  function writeHash() {
    const parts = [st.mode];
    if (st.mode === 'scenarios') parts.push(st.open || st.cat);
    history.replaceState(null, '', '#' + parts.join('/'));
  }

  /* ===== Ссылки на OWASP и внешние каталоги ===== */
  function owaspLinks(s) {
    const out = [];
    (s.owaspWeb || []).forEach((c) => out.push(h('a', { class: 'at-ref at-ref--owasp', href: OWASP_URL('web', c), title: 'Открыть в разделе OWASP' }, 'OWASP Web ' + c)));
    if (s.owaspWebNote) out.push(h('span', { class: 'at-ref__note' }, s.owaspWebNote));
    (s.owaspApi || []).forEach((c) => out.push(h('a', { class: 'at-ref at-ref--owasp', href: OWASP_URL('api', c), title: 'Открыть в разделе OWASP' }, 'OWASP API ' + c)));
    if (s.owaspApiNote) out.push(h('span', { class: 'at-ref__note' }, s.owaspApiNote));
    return out;
  }
  const CWE_URL = (c) => 'https://cwe.mitre.org/data/definitions/' + c.replace(/\D/g, '') + '.html';
  const ATTACK_URL = (c) => 'https://attack.mitre.org/techniques/' + c + '/';

  function classRow(label, kids) {
    if (!kids || !kids.length) return null;
    return h('div', { class: 'at-class__row' }, h('span', { class: 'at-class__label' }, label), h('span', { class: 'at-class__val' }, kids));
  }

  /* ===== Карточка сценария ===== */
  function section(title, items) {
    if (!items || !items.length) return null;
    return h('div', { class: 'at-sec' }, h('h4', null, title), h('ul', null, items.map((x) => h('li', null, x))));
  }

  function card(s, opts) {
    const open = opts.open === s.id;
    const el = h('article', { class: 'at-card' + (open ? ' is-open' : ''), id: 'at-' + s.id },
      h('button', { type: 'button', class: 'at-card__head', 'aria-expanded': open ? 'true' : 'false', onClick: () => opts.toggle(s.id) },
        h('span', { class: 'at-card__num' }, String(s.num)),
        h('span', { class: 'at-card__titles' },
          h('b', null, s.name),
          h('small', null, s.full)),
        h('span', { class: 'at-card__sec' }, s.section),
        h('span', { class: 'at-card__fold', 'aria-hidden': 'true' })),
      h('p', { class: 'at-card__essence' }, s.essence));
    if (!open) return el;

    const cls = h('div', { class: 'at-class' },
      classRow('OWASP', owaspLinks(s)),
      classRow('CWE', [...(s.cwe || []).map((c) => h('a', { class: 'at-ref', href: CWE_URL(c), target: '_blank', rel: 'noopener' }, c)), s.cweNote ? h('span', { class: 'at-ref__note' }, s.cweNote) : null].filter(Boolean)),
      classRow('CAPEC', (s.capec || []).map((c) => h('span', { class: 'at-ref at-ref--plain' }, c))),
      classRow('ATT&CK', [...(s.attack || []).map((c) => h('a', { class: 'at-ref', href: ATTACK_URL(c), target: '_blank', rel: 'noopener' }, c)), s.attackNote ? h('span', { class: 'at-ref__note' }, s.attackNote) : null].filter(Boolean)),
      classRow('Свойство', [h('span', { class: 'at-ref__note' }, s.property)]));

    el.append(h('div', { class: 'at-card__body' },
      s.aliases && s.aliases.length ? h('p', { class: 'at-aliases' }, 'Другие названия: ' + s.aliases.join('; ')) : null,
      cls,
      h('div', { class: 'at-sec at-sec--boundary' }, h('h4', null, 'Граница доверия'), h('p', null, s.boundary)),
      section('Предпосылки', s.preconditions),
      h('div', { class: 'at-sec at-sec--chain' }, h('h4', null, 'Цепочка'), h('p', { class: 'at-chain' }, s.chain)),
      section('Безопасная самопроверка стенда', s.labCheck),
      section('Маркеры в логах', s.markers),
      section('Обнаружение', s.detection),
      h('div', { class: 'at-sec at-sec--defense' }, h('h4', null, 'Защита'), h('ul', null, s.defense.map((x) => h('li', null, x)))),
      section('Проверка исправления', s.regression)));
    return el;
  }

  /* ===== Режим: сценарии ===== */
  function renderScenarios(box) {
    const items = ATTACKS.items.filter((s) => st.cat === 'all' || s.cat === st.cat);
    box.append(
      h('p', { class: 'at-lead' }, 'Карточки устроены одинаково: суть, граница доверия, безопасная проверка своего стенда, маркеры в логах, обнаружение и защита. Материал описывает публичные классы сценариев для защиты и не содержит пошаговых инструкций атаки на чужие системы.'),
      h('div', { class: 'at-chips' },
        h('button', { type: 'button', class: 'at-chip' + (st.cat === 'all' ? ' active' : ''), onClick: () => { st.cat = 'all'; st.open = null; render(); } }, 'Все', h('span', { class: 'at-chip__n' }, String(ATTACKS.items.length))),
        Object.entries(CATS).map(([c, name]) => {
          const n = ATTACKS.items.filter((s) => s.cat === c).length;
          if (!n) return null;
          return h('button', { type: 'button', class: 'at-chip' + (st.cat === c ? ' active' : ''), onClick: () => { st.cat = c; st.open = null; render(); } }, name, h('span', { class: 'at-chip__n' }, String(n)));
        })),
      h('div', { class: 'at-grid' }, items.sort((a, b) => a.num - b.num).map((s) => card(s, { open: st.open, toggle: (id) => { st.open = st.open === id ? null : id; render(); scrollToOpen(); } }))));
  }

  /* ===== Режим: связь с OWASP ===== */
  function renderOwasp(box) {
    // Собрать карту: код OWASP -> сценарии
    const map = {};
    const push = (list, code, s) => { const k = list + ' ' + code; (map[k] = map[k] || []).push(s); };
    ATTACKS.items.forEach((s) => { (s.owaspWeb || []).forEach((c) => push('Web', c, s)); (s.owaspApi || []).forEach((c) => push('API', c, s)); });
    const keys = Object.keys(map).sort();
    box.append(
      h('p', { class: 'at-lead' }, 'Одна категория OWASP охватывает несколько конкретных сценариев, а один сценарий может относиться к нескольким категориям. Здесь видно, какие сценарии стоят за каждой категорией OWASP Web и API.'),
      h('div', { class: 'at-owasp' }, keys.map((k) => {
        const [list, code] = k.split(' ');
        const url = OWASP_URL(list.toLowerCase(), code);
        return h('section', { class: 'at-owasp__group' },
          h('div', { class: 'at-owasp__head' }, h('a', { class: 'at-ref at-ref--owasp', href: url }, 'OWASP ' + list + ' ' + code), h('span', null, map[k].length + ' сцен.')),
          h('div', { class: 'at-owasp__items' }, map[k].sort((a, b) => a.num - b.num).map((s) => h('button', { type: 'button', class: 'at-owasp__item', onClick: () => { st.mode = 'scenarios'; st.cat = 'all'; st.open = s.id; render(); scrollToOpen(); } },
            h('span', { class: 'at-card__num' }, String(s.num)), s.name))));
      })));
  }

  /* ===== Режим: тренажёр ===== */
  function newQuestion() {
    const pool = ATTACKS.items;
    const right = pool[Math.floor(Math.random() * pool.length)];
    const others = pool.filter((s) => s !== right);
    const opts = [right];
    while (opts.length < 4 && others.length) opts.push(others.splice(Math.floor(Math.random() * others.length), 1)[0]);
    opts.sort(() => Math.random() - 0.5);
    // Вопрос по маркеру в логах: по признаку назвать сценарий
    const marker = right.markers[Math.floor(Math.random() * right.markers.length)];
    st.quiz = { s: right, marker, opts, picked: null };
  }
  function renderQuiz(box) {
    if (!st.quiz) newQuestion();
    const q = st.quiz;
    box.append(
      h('div', { class: 'at-quiz__top' },
        h('p', { class: 'at-lead' }, 'По маркеру в логах назови сценарий. Так тренируется связь «что видно в телеметрии» и «что это за класс атаки».'),
        h('span', { class: 'at-score' }, 'Верно: ' + st.score.ok + ' из ' + st.score.all)),
      h('section', { class: 'at-quiz panel' },
        h('div', { class: 'at-quiz__label' }, 'Такой признак в логах. Какой это сценарий?'),
        h('p', { class: 'at-quiz__q' }, q.marker),
        h('div', { class: 'at-quiz__opts' }, q.opts.map((o) => {
          const state = q.picked == null ? '' : o === q.s ? ' is-right' : o === q.picked ? ' is-wrong' : ' is-dim';
          return h('button', { type: 'button', class: 'at-opt' + state, disabled: q.picked != null, onClick: () => { q.picked = o; st.score.all++; if (o === q.s) st.score.ok++; render(); } },
            h('b', null, o.name), h('span', null, o.full));
        })),
        q.picked != null ? h('div', { class: 'at-quiz__answer' + (q.picked === q.s ? ' is-ok' : ' is-err') },
          h('b', null, (q.picked === q.s ? 'Верно: ' : 'Правильный ответ: ') + q.s.name),
          h('p', null, q.s.essence),
          h('div', { class: 'at-quiz__next' },
            h('button', { type: 'button', class: 'btn btn--primary', onClick: () => { newQuestion(); render(); } }, 'Следующий'),
            h('button', { type: 'button', class: 'btn', onClick: () => { st.mode = 'scenarios'; st.cat = 'all'; st.open = q.s.id; render(); scrollToOpen(); } }, 'Открыть сценарий'))) : null));
  }

  function scrollToOpen() {
    requestAnimationFrame(() => {
      const el = root.querySelector('.at-card.is-open');
      if (el && el.getBoundingClientRect().top < 80) el.scrollIntoView({ block: 'start', behavior: 'smooth' });
    });
  }

  function render() {
    const tabs = h('div', { class: 'osi-tabs', role: 'tablist' }, MODES.map(([m, name]) => h('button', {
      type: 'button', role: 'tab', class: 'osi-tab' + (m === st.mode ? ' active' : ''), 'aria-selected': m === st.mode ? 'true' : 'false',
      onClick: () => { if (st.mode !== m) { st.mode = m; st.open = null; render(); } },
    }, name)));
    const box = h('div', { class: 'at-panel' + (st.mode !== lastMode ? ' is-enter' : '') });
    lastMode = st.mode;
    if (st.mode === 'scenarios') renderScenarios(box);
    else if (st.mode === 'owasp') renderOwasp(box);
    else renderQuiz(box);
    root.replaceChildren(tabs, box);
    writeHash();
  }

  function boot() {
    root = document.getElementById('attacks');
    if (!root) return;
    readHash();
    render();
  }
  if (document.readyState === 'complete') setTimeout(boot);
  else document.addEventListener('DOMContentLoaded', boot);
})();
