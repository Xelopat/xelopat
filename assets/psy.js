// Воркбук /psy: интерфейс целиком строится из данных, которые отдаёт psy.php (includes/psy_content.php).
// Здесь нет ни текстов тетради, ни личных ответов: всё приходит с сервера только админу.
(() => {
  'use strict';

  const D = JSON.parse(document.getElementById('psy-data').textContent);
  const B = JSON.parse(document.getElementById('psy-state').textContent);
  const S = B.v && typeof B.v === 'object' && !Array.isArray(B.v) ? B.v : {};

  const $nav = document.getElementById('psyNav');
  const $main = document.getElementById('psyMain');
  const $status = document.getElementById('psyStatus');

  /* ===== Мелочи ===== */

  function h(tag, attrs, ...kids) {
    const el = document.createElement(tag);
    if (attrs) {
      for (const [k, v] of Object.entries(attrs)) {
        if (v == null || v === false) continue;
        if (k === 'class') el.className = v;
        else if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
        else if (k === 'value' || k === 'checked') el[k] = v;
        else el.setAttribute(k, v === true ? '' : String(v));
      }
    }
    const add = (x) => {
      if (x == null || x === false) return;
      if (Array.isArray(x)) x.forEach(add);
      else el.append(x.nodeType ? x : document.createTextNode(String(x)));
    };
    kids.forEach(add);
    return el;
  }

  const pad = (n) => String(n).padStart(2, '0');
  const iso = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  const parseIso = (s) => { const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d); };
  const addDays = (d, n) => new Date(d.getFullYear(), d.getMonth(), d.getDate() + n);
  const dayDiff = (a, b) => Math.round((Date.UTC(a.getFullYear(), a.getMonth(), a.getDate()) - Date.UTC(b.getFullYear(), b.getMonth(), b.getDate())) / 864e5);
  const ISO_RE = /^\d{4}-\d{2}-\d{2}$/;
  const WD = ['вс', 'пн', 'вт', 'ср', 'чт', 'пт', 'сб'];
  const MONTHS = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
  const longDate = (d) => `${d.getDate()} ${MONTHS[d.getMonth()]}`;

  /* ===== Хранилище: память + отправка изменений на сервер ===== */

  const pending = Object.create(null);
  const watchers = new Set();
  let timer = 0;
  let inflight = false;
  let state = 'saved';

  const get = (k, d = '') => (k in S ? S[k] : d);

  function put(k, v) {
    if (v === '' || v === false || v == null || v === 0) {
      delete S[k];
      pending[k] = null;
    } else {
      S[k] = v;
      pending[k] = v;
    }
    watchers.forEach((f) => f());
    setStatus('pending');
    clearTimeout(timer);
    timer = setTimeout(flush, 600);
  }

  function setStatus(s) {
    state = s;
    $status.textContent = { saved: 'сохранено', pending: 'сохраняю…', error: 'не сохранено, повторю', auth: 'сессия истекла, обнови страницу' }[s];
    $status.dataset.state = s;
  }

  async function flush(keep) {
    clearTimeout(timer);
    const keys = Object.keys(pending);
    if (!keys.length || state === 'auth') return;
    if (inflight) { timer = setTimeout(flush, 400); return; }
    const batch = {};
    keys.forEach((k) => { batch[k] = pending[k]; });
    inflight = true;
    try {
      const r = await fetch('/psy', {
        method: 'POST',
        credentials: 'same-origin',
        keepalive: keep === true,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ csrf: B.csrf, set: batch }),
      });
      if (r.status === 401 || r.status === 403) { setStatus('auth'); return; }
      const j = r.ok ? await r.json().catch(() => null) : null;
      if (!j || !j.ok) throw new Error(String(r.status));
      keys.forEach((k) => { if (pending[k] === batch[k]) delete pending[k]; });
      setStatus(Object.keys(pending).length ? 'pending' : 'saved');
    } catch (e) {
      setStatus('error');
      timer = setTimeout(flush, 5000);
      return;
    } finally {
      inflight = false;
    }
    if (Object.keys(pending).length) timer = setTimeout(flush, 300);
  }

  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') flush(true); });
  window.addEventListener('pagehide', () => flush(true));

  /* ===== Дата, день курса, неделя ===== */

  function startDate() {
    const s = get('start');
    return ISO_RE.test(s) ? parseIso(s) : null;
  }
  function dayNo() {
    const st = startDate();
    return st ? dayDiff(new Date(), st) + 1 : 0;
  }
  const weekOf = (n) => Math.min(4, Math.max(1, Math.ceil(n / 7)));

  function reviewedDates() {
    const set = new Set();
    for (const k of Object.keys(S)) {
      const m = /^[dr]\.(\d{4}-\d{2}-\d{2})\.\d+$/.exec(k);
      if (m) set.add(m[1]);
    }
    return set;
  }

  /* ===== Мелкие компоненты ===== */

  function stepper(key, max) {
    const out = h('output', { class: 'psy-step__n' });
    const paint = () => { const n = Number(get(key, 0)); out.textContent = String(n); out.classList.toggle('on', n > 0); };
    const bump = (d) => { put(key, Math.max(0, Math.min(max || 99, Number(get(key, 0)) + d))); paint(); };
    paint();
    return h('span', { class: 'psy-step' },
      h('button', { type: 'button', class: 'psy-step__b', 'aria-label': 'Минус один', onclick: () => bump(-1) }, '−'),
      out,
      h('button', { type: 'button', class: 'psy-step__b', 'aria-label': 'Плюс один', onclick: () => bump(1) }, '+'));
  }

  function copyText(text, btn) {
    const done = () => { const old = btn.textContent; btn.textContent = 'Скопировано'; setTimeout(() => { btn.textContent = old; }, 1600); };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done, () => {});
      return;
    }
    const ta = h('textarea', { style: 'position:fixed;opacity:0' });
    ta.value = text;
    document.body.append(ta);
    ta.select();
    try { document.execCommand('copy'); done(); } catch (e) { /* ничего */ }
    ta.remove();
  }

  /* ===== Блоки ===== */

  const R = {};

  R.p = (b) => h('p', { class: 'psy-p' }, b.text);
  R.h = (b) => h('h3', { class: 'psy-h' }, b.text);

  R.callout = (b) => h('div', { class: `psy-callout psy-callout--${b.tone || 'accent'}` },
    b.title && h('div', { class: 'psy-callout__t' }, b.title),
    b.text && h('p', null, b.text),
    b.text2 && h('p', null, b.text2));

  R.card = (b, sub) => h('section', { class: 'psy-card' }, b.blocks.map((x) => R[x.t](x, sub)));

  R.list = (b) => h('ul', { class: 'psy-chips' }, b.items.map((t) => h('li', null, t)));

  R.check = (b) => {
    const box = h('div', { class: 'psy-checks' });
    b.items.forEach((t, i) => {
      const k = `${b.id}.${i}`;
      const inp = h('input', { type: 'checkbox', checked: !!get(k) });
      inp.addEventListener('change', () => put(k, inp.checked));
      box.append(h('label', { class: 'psy-check' }, inp, h('span', null, t)));
    });
    if (b.meter) {
      const m = h('div', { class: 'psy-meter' });
      const upd = () => {
        const n = b.items.filter((_, i) => get(`${b.id}.${i}`)).length;
        m.textContent = n ? b.meter.replace('{n}', n).replace('{m}', b.items.length) : '';
        m.hidden = !n;
      };
      watchers.add(upd);
      upd();
      box.append(m);
    }
    return box;
  };

  R.steps = (b) => h('ol', { class: 'psy-steps' }, b.items.map((it, i) => h('li', { class: 'psy-steps__i' },
    h('span', { class: 'psy-steps__n' }, i + 1),
    h('div', { class: 'psy-steps__b' },
      h('div', { class: 'psy-steps__k' }, it.k),
      h('div', { class: 'psy-steps__q' }, it.q)))));

  R.table = (b) => h('div', { class: 'psy-tablewrap' },
    h('table', { class: 'data-table psy-table' },
      h('thead', null, h('tr', null, b.head.map((c) => h('th', null, c)))),
      h('tbody', null, b.rows.map((r) => h('tr', null, r.map((c) => h('td', null, c)))))));

  R.say = (b) => h('div', { class: 'psy-say' }, b.items.map((t) => h('blockquote', { class: 'psy-say__i' },
    h('p', null, t),
    b.hint && h('small', null, b.hint))));

  R.copy = (b) => {
    const btn = h('button', { type: 'button', class: 'btn psy-copy__b', onclick: () => copyText(b.text, btn) }, 'Скопировать');
    return h('div', { class: 'psy-copy' }, h('p', null, b.text), btn);
  };

  // Да/нет. Тревожным считается ответ, указанный в bad: для вопросов вроде «приемлемо, если никто не узнает?» это «нет».
  R.quiz = (b) => {
    const verdict = h('div', { class: 'psy-callout' });
    const rows = b.items.map((it, i) => {
      const k = `${b.id}.${i}`;
      const seg = h('div', { class: 'psy-seg', role: 'group', 'aria-label': it.q });
      const btns = [['y', 'Да'], ['n', 'Нет']].map(([v, label]) => {
        const btn = h('button', { type: 'button', class: 'psy-seg__b', 'data-v': v, 'aria-pressed': 'false' }, label);
        btn.addEventListener('click', () => { put(k, get(k) === v ? '' : v); paint(); });
        return btn;
      });
      const paint = () => btns.forEach((btn) => {
        const on = get(k) === btn.dataset.v;
        btn.setAttribute('aria-pressed', String(on));
        btn.classList.toggle('is-bad', on && btn.dataset.v === it.bad);
        btn.classList.toggle('is-ok', on && btn.dataset.v !== it.bad);
      });
      seg.append(...btns);
      paint();
      return h('div', { class: 'psy-quiz__r' }, h('span', { class: 'psy-quiz__q' }, it.q), seg);
    });
    const upd = () => {
      let answered = 0;
      let bad = 0;
      b.items.forEach((it, i) => { const a = get(`${b.id}.${i}`); if (a) { answered++; if (a === it.bad) bad++; } });
      const flag = bad >= (b.min || 2);
      verdict.className = `psy-callout psy-callout--${flag ? 'danger' : 'ok'}`;
      verdict.hidden = !answered;
      verdict.replaceChildren(
        h('div', { class: 'psy-callout__t' }, flag ? 'Красный флаг' : 'Красный флаг не горит'),
        h('p', null, flag ? b.flag : `Тревожных ответов: ${bad} из ${b.items.length}${answered < b.items.length ? ', ответь на остальные' : ''}.`));
    };
    watchers.add(upd);
    upd();
    return h('div', { class: 'psy-quiz' }, rows, verdict);
  };

  /* ----- Сегодня ----- */

  R.hero = () => {
    const box = h('section', { class: 'psy-hero' });
    const paint = () => {
      const n = dayNo();
      const st = startDate();
      const done = reviewedDates();
      let streak = 0;
      for (let d = new Date(); streak < 400; d = addDays(d, -1)) {
        if (done.has(iso(d))) streak++;
        else if (streak || dayDiff(new Date(), d) > 0) break;
      }
      const ticks = h('div', { class: 'psy-ticks', 'aria-hidden': 'true' });
      for (let i = 0; i < 30; i++) {
        const dt = st ? iso(addDays(st, i)) : '';
        const isDone = dt && done.has(dt);
        ticks.append(h('i', { class: [isDone ? 'done' : '', st && i + 1 === n ? 'now' : '', st && i + 1 < n && !isDone ? 'past' : ''].join(' ').trim() }));
      }
      let head;
      if (!st) {
        head = h('div', { class: 'psy-hero__day' },
          h('span', { class: 'psy-hero__lbl' }, 'старт не задан'),
          h('button', { type: 'button', class: 'btn btn--primary', onclick: () => { put('start', iso(new Date())); } }, 'Начать сегодня'));
      } else if (n < 1) {
        head = h('div', { class: 'psy-hero__day' }, h('span', { class: 'psy-hero__lbl' }, `старт ${longDate(st)}, через ${1 - n} дн.`));
      } else if (n > 30) {
        head = h('div', { class: 'psy-hero__day' },
          h('span', { class: 'psy-hero__lbl' }, 'месяц пройден, идёт день'),
          h('strong', null, n));
      } else {
        head = h('div', { class: 'psy-hero__day' },
          h('span', { class: 'psy-hero__lbl' }, 'день'),
          h('strong', null, n),
          h('span', { class: 'psy-hero__of' }, 'из 30'));
      }
      box.replaceChildren(
        h('div', { class: 'psy-hero__top' },
          head,
          h('dl', { class: 'psy-hero__stats' },
            h('div', null, h('dt', null, 'разборов'), h('dd', null, done.size)),
            h('div', null, h('dt', null, 'серия'), h('dd', null, streak)))),
        ticks,
        h('p', { class: 'psy-hero__phrase' },
          h('span', null, 'Моя фраза на месяц'),
          `«${D.phrase}»`));
    };
    watchers.add(paint);
    paint();
    return box;
  };

  R.deck = () => {
    const n = dayNo();
    let i = n > 0 ? (n - 1) % D.deck.length : Math.floor((Date.now() / 864e5) % D.deck.length);
    const num = h('span', { class: 'psy-deck__num' });
    const txt = h('p', { class: 'psy-deck__txt' });
    const paint = () => {
      num.textContent = `${pad(i + 1)} / ${pad(D.deck.length)}`;
      txt.textContent = D.deck[i];
    };
    const go = (d) => { i = (i + d + D.deck.length) % D.deck.length; paint(); };
    const card = h('div', { class: 'psy-deck__card', tabindex: '0', 'aria-live': 'polite' }, num, txt);
    card.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowRight') { e.preventDefault(); go(1); }
      if (e.key === 'ArrowLeft') { e.preventDefault(); go(-1); }
    });
    paint();
    return h('section', { class: 'psy-deck' },
      h('div', { class: 'psy-sechead' }, h('h3', { class: 'psy-h' }, 'Карточка на сегодня'),
        h('button', { type: 'button', class: 'psy-ghost', onclick: () => { i = Math.floor(Math.random() * D.deck.length); paint(); } }, 'Случайная')),
      card,
      h('div', { class: 'psy-deck__nav' },
        h('button', { type: 'button', class: 'btn', onclick: () => go(-1) }, '← Назад'),
        h('button', { type: 'button', class: 'btn', onclick: () => go(1) }, 'Дальше →')));
  };

  let revDate = iso(new Date());

  R.review = (b) => {
    const box = h('section', { class: 'psy-review' });
    const draw = () => {
      const d = parseIso(revDate);
      const todayIso = iso(new Date());
      const done = reviewedDates();
      const strip = h('div', { class: 'psy-days', role: 'group', 'aria-label': 'Выбор дня' });
      for (let k = 13; k >= 0; k--) {
        const dd = addDays(new Date(), -k);
        const s = iso(dd);
        strip.append(h('button', { type: 'button', class: `psy-day${s === revDate ? ' is-sel' : ''}${done.has(s) ? ' is-done' : ''}`, onclick: () => { revDate = s; draw(); } },
          h('small', null, WD[dd.getDay()]), dd.getDate()));
      }
      const shift = (n) => { revDate = iso(addDays(d, n)); draw(); };
      box.replaceChildren(
        h('div', { class: 'psy-sechead' }, h('h3', { class: 'psy-h' }, b.title),
          revDate !== todayIso && h('button', { type: 'button', class: 'psy-ghost', onclick: () => { revDate = todayIso; draw(); } }, 'К сегодняшнему')),
        h('p', { class: 'psy-p' }, b.lead),
        h('div', { class: 'psy-datebar' },
          h('button', { type: 'button', class: 'btn psy-datebar__b', 'aria-label': 'Предыдущий день', onclick: () => shift(-1) }, '←'),
          h('div', { class: 'psy-datebar__d' }, `${longDate(d)} ${d.getFullYear()}`, h('small', null, revDate === todayIso ? 'сегодня' : WD[d.getDay()])),
          h('button', { type: 'button', class: 'btn psy-datebar__b', 'aria-label': 'Следующий день', onclick: () => shift(1), disabled: revDate >= todayIso }, '→')),
        strip,
        h('div', { class: 'psy-checks' }, b.steps.map((q, i) => {
          const k = `r.${revDate}.${i}`;
          const inp = h('input', { type: 'checkbox', checked: !!get(k) });
          inp.addEventListener('change', () => { put(k, inp.checked); draw(); });
          return h('label', { class: 'psy-check' }, inp, h('span', null, q));
        })));
    };
    draw();
    return box;
  };

  /* ----- Протоколы ----- */

  R.tools = (b, sub) => {
    let cur = b.tools.find((t) => t.id === sub) || b.tools[0];
    const grid = h('div', { class: 'psy-tools', role: 'tablist' });
    const detail = h('section', { class: 'psy-detail', 'aria-live': 'polite' });
    const tiles = new Map();

    const paintDetail = () => {
      watchers.clear();
      const t = cur;
      detail.replaceChildren(
        h('header', { class: 'psy-detail__h' },
          h('div', null,
            h('div', { class: 'psy-detail__ref' }, `раздел ${t.ref}`),
            h('h3', { class: 'psy-detail__t' }, t.title)),
          h('button', { type: 'button', class: 'psy-ghost', onclick: () => {
            if (!confirm('Очистить ответы и отметки в этом протоколе?')) return;
            const re = new RegExp(`(^|\\.)${t.id}_`);
            Object.keys(S).filter((k) => re.test(k)).forEach((k) => put(k, ''));
            paintDetail();
          } }, 'Сбросить')),
        ...t.blocks.map((blk) => R[blk.t](blk)));
    };

    b.tools.forEach((t) => {
      const tile = h('button', { type: 'button', class: 'psy-tile', role: 'tab', 'aria-selected': String(t === cur) },
        h('span', { class: 'psy-tile__ref' }, `§${t.ref}`),
        h('span', { class: 'psy-tile__t' }, t.title),
        h('span', { class: 'psy-tile__w' }, t.when));
      tile.addEventListener('click', () => {
        cur = t;
        tiles.forEach((el, tt) => el.setAttribute('aria-selected', String(tt === t)));
        history.replaceState(null, '', `#protocols/${t.id}`);
        paintDetail();
        if (window.innerWidth < 860) detail.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
      tiles.set(t, tile);
      grid.append(tile);
    });
    paintDetail();
    return h('div', { class: 'psy-proto' }, grid, detail);
  };

  /* ----- План, трекер, разбор недели ----- */

  let weekSel = 0;

  R.weeks = (b) => {
    const n = dayNo();
    if (!weekSel) weekSel = n > 0 ? weekOf(n) : 1;
    const box = h('div', { class: 'psy-weeks' });

    const weekProgress = (w) => {
      const wk = b.weeks[w - 1];
      return Math.round((wk.tasks.filter((_, i) => get(`plan.w${w}.${i}`)).length / wk.tasks.length) * 100);
    };
    const sumWeek = (w, c) => { let s = 0; for (let d = 1; d <= 7; d++) s += Number(get(`trk.w${w}.d${d}.c${c}`, 0)); return s; };
    const sumSoc = (w) => { let s = 0; for (let d = 1; d <= 7; d++) if (get(`trk.w${w}.d${d}.s`)) s++; return s; };

    const draw = () => {
      watchers.clear();
      const w = weekSel;
      const wk = b.weeks[w - 1];
      const tabs = h('div', { class: 'psy-wtabs', role: 'tablist' }, b.weeks.map((x, i) => {
        const bar = h('i', { style: `width:${weekProgress(i + 1)}%` });
        watchers.add(() => { bar.style.width = `${weekProgress(i + 1)}%`; });
        return h('button', { type: 'button', role: 'tab', class: 'psy-wtab', 'aria-selected': String(i + 1 === w), onclick: () => { weekSel = i + 1; draw(); } },
          h('small', null, `неделя ${i + 1}`), x.title, h('span', { class: 'psy-wtab__bar' }, bar));
      }));

      const tasks = h('div', { class: 'psy-checks' });
      wk.tasks.forEach((t, i) => {
        const k = `plan.w${w}.${i}`;
        const inp = h('input', { type: 'checkbox', checked: !!get(k) });
        inp.addEventListener('change', () => put(k, inp.checked));
        tasks.append(h('label', { class: 'psy-check' }, inp, h('span', null, t)));
      });

      // Трекер: пять счётчиков в день и отметка социальной попытки
      const rows = [];
      for (let d = 1; d <= 7; d++) {
        const dateCell = h('th', { scope: 'row' }, String(d));
        if (startDate()) {
          const dt = addDays(startDate(), (w - 1) * 7 + d - 1);
          dateCell.append(h('small', null, `${pad(dt.getDate())}.${pad(dt.getMonth() + 1)}`));
          if (iso(dt) === iso(new Date())) dateCell.classList.add('is-today');
        }
        const soc = h('input', { type: 'checkbox', checked: !!get(`trk.w${w}.d${d}.s`), 'aria-label': `День ${d}: социальная попытка` });
        soc.addEventListener('change', () => put(`trk.w${w}.d${d}.s`, soc.checked));
        rows.push(h('tr', null, dateCell,
          b.tracker.map((_, c) => h('td', null, stepper(`trk.w${w}.d${d}.c${c}`, 30))),
          h('td', { class: 'psy-trk__soc' }, h('label', { class: 'psy-check psy-check--solo' }, soc))));
      }
      const totals = h('tr', { class: 'psy-trk__tot' }, h('th', { scope: 'row' }, 'всего'),
        b.tracker.map((_, c) => { const td = h('td'); const upd = () => { td.textContent = String(sumWeek(w, c)); }; watchers.add(upd); upd(); return td; }),
        (() => { const td = h('td', { class: 'psy-trk__soc' }); const upd = () => { td.textContent = String(sumSoc(w)); }; watchers.add(upd); upd(); return td; })());

      box.replaceChildren(
        tabs,
        h('div', { class: 'psy-card' },
          h('div', { class: 'psy-callout__t' }, `Неделя ${w}: ${wk.title}`),
          h('p', { class: 'psy-p' }, wk.lead),
          tasks),
        h('section', { class: 'psy-card' },
          h('h3', { class: 'psy-h' }, 'Трекер на 7 дней'),
          h('p', { class: 'psy-p' }, b.trackerLead),
          h('div', { class: 'psy-tablewrap' },
            h('table', { class: 'data-table psy-trk' },
              h('thead', null, h('tr', null, h('th', null, 'день'), b.tracker.map((c) => h('th', null, c)), h('th', null, '1 социальная попытка'))),
              h('tbody', null, rows, totals)))),
        h('section', { class: 'psy-card' },
          h('h3', { class: 'psy-h' }, 'Итог недели'),
          h('p', { class: 'psy-p' }, 'Отметь, что было на этой неделе.'),
          h('div', { class: 'psy-checks' }, b.review.map((q, i) => {
            const k = `wk.w${w}.${i}`;
            const inp = h('input', { type: 'checkbox', checked: !!get(k) });
            inp.addEventListener('change', () => put(k, inp.checked));
            return h('label', { class: 'psy-check' }, inp, h('span', null, q));
          }))));
    };
    draw();
    return box;
  };

  /* ----- Знакомства ----- */

  R.ladder = (b) => {
    const rows = b.levels.map((t, i) => {
      const k = `lad.${i + 1}`;
      const row = h('li', { class: 'psy-lad__r' },
        h('span', { class: 'psy-lad__n' }, i + 1),
        h('span', { class: 'psy-lad__t' }, t),
        h('span', { class: 'psy-lad__c' }, h('small', null, 'раз'), stepper(k, 99)));
      const upd = () => row.classList.toggle('is-done', Number(get(k, 0)) > 0);
      watchers.add(upd);
      upd();
      return row;
    });
    const sum = h('div', { class: 'psy-meter' });
    const upd = () => {
      let top = 0;
      let total = 0;
      b.levels.forEach((_, i) => { const c = Number(get(`lad.${i + 1}`, 0)); total += c; if (c > 0) top = i + 1; });
      sum.textContent = total ? `Попыток всего: ${total}. Самый высокий пройденный уровень: ${top}.` : '';
      sum.hidden = !total;
    };
    watchers.add(upd);
    upd();
    return h('div', { class: 'psy-lad' }, h('ol', { class: 'psy-lad__l' }, rows), sum);
  };

  /* ----- Карта цикла ----- */

  R.cycle = (b) => h('div', { class: 'psy-cycle' }, b.rows.map((r, i) => {
    const k = `cyc.${i}`;
    const row = h('div', { class: `psy-cycle__r${get(k) ? ' is-me' : ''}` });
    const btn = h('button', { type: 'button', class: 'psy-cycle__me', 'aria-pressed': String(!!get(k)) }, 'Узнаю себя');
    btn.addEventListener('click', () => {
      const on = !get(k);
      put(k, on);
      btn.setAttribute('aria-pressed', String(on));
      row.classList.toggle('is-me', on);
    });
    row.append(
      h('div', { class: 'psy-cycle__c' }, h('small', null, 'триггер'), r[0]),
      h('div', { class: 'psy-cycle__c' }, h('small', null, 'автоматическая защита'), r[1]),
      h('div', { class: 'psy-cycle__c' }, h('small', null, 'цена защиты'), r[2]),
      btn);
    return row;
  }));

  /* ----- Основа ----- */

  R.setup = () => {
    const name = h('input', { type: 'text', id: 'psy-name', autocomplete: 'off', value: String(get('name')) });
    name.addEventListener('input', () => put('name', name.value));
    const date = h('input', { type: 'date', id: 'psy-start', value: ISO_RE.test(get('start')) ? get('start') : '' });
    date.addEventListener('change', () => put('start', ISO_RE.test(date.value) ? date.value : ''));
    return h('div', { class: 'psy-setup' },
      h('div', { class: 'field' }, h('label', { for: 'psy-name' }, 'Имя'), name),
      h('div', { class: 'field' }, h('label', { for: 'psy-start' }, 'Дата старта'), date),
      h('button', { type: 'button', class: 'btn', onclick: () => { date.value = iso(new Date()); put('start', date.value); } }, 'Старт сегодня'));
  };

  /* ===== Страницы и маршрутизация ===== */

  function renderNav(id) {
    $nav.replaceChildren(...D.views.map((v, i) => h('a', { href: `#${v.id}`, 'aria-current': v.id === id ? 'page' : null },
      h('span', { class: 'psy-nav__n' }, pad(i + 1)), v.label)));
  }

  function route() {
    const [id, sub] = decodeURIComponent(location.hash.slice(1)).split('/');
    const v = D.views.find((x) => x.id === id) || D.views[0];
    watchers.clear();
    renderNav(v.id);
    $main.replaceChildren(h('div', { class: 'psy-view' },
      h('header', { class: 'psy-vhead' },
        h('h2', null, v.title),
        v.lead && h('p', null, v.lead)),
      v.blocks.map((blk) => R[blk.t](blk, sub))));
    document.title = `${v.label} | ${D.title}`;
    if (!sub) window.scrollTo({ top: 0 });
  }

  window.addEventListener('hashchange', route);
  setStatus('saved');
  route();
})();
