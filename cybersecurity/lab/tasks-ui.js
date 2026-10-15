// Задачи: список по уровням, карточка текущей задачи с проверкой и подсказками, прогресс.
// Прогресс хранится в браузере, а у вошедших на xelopat.ru ещё и в аккаунте (api.php?action=progress):
// при входе локальный и серверный прогресс объединяются.
(function () {
  const STORE = 'cs-lab:progress';
  let ui, h;
  let pop = null, card = null;
  const st = {
    done: {},          // id -> время прохождения (секунды)
    hints: {},         // id -> сколько подсказок открыто
    user: undefined,   // undefined: ещё не знаем, null: не вошёл, { name }
    results: null,     // результат последней проверки текущей задачи
    play: null,        // идёт показ проверки: { skip, title, task }
    revealed: 0,       // сколько условий уже показано
    stale: false,      // после проверки схему меняли
    collapsed: false,
    showKnow: false,
    syncing: false,
  };

  const root = () => document.getElementById('lab');
  const API = () => root().dataset.api || 'api.php';
  const LOGIN = () => root().dataset.login || 'https://xelopat.ru/auth/login';
  const now = () => Math.floor(Date.now() / 1000);

  function loadLocal() {
    try {
      const d = JSON.parse(localStorage.getItem(STORE) || '{}');
      st.done = d.done && typeof d.done === 'object' ? d.done : {};
      st.hints = d.hints && typeof d.hints === 'object' ? d.hints : {};
      st.collapsed = !!d.collapsed;
    } catch (e) { /* без хранилища начинаем с нуля */ }
  }
  function saveLocal() {
    try { localStorage.setItem(STORE, JSON.stringify({ done: st.done, hints: st.hints, collapsed: st.collapsed })); } catch (e) { /* не страшно */ }
  }

  async function api(params, body) {
    const url = API() + (params ? '?' + new URLSearchParams(params) : '');
    const res = await fetch(url, body ? { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) } : { credentials: 'include' });
    let data = null;
    try { data = await res.json(); } catch (e) { /* не JSON */ }
    if (!res.ok) throw new Error((data && data.error) || 'сервер ответил ' + res.status);
    return data;
  }

  // Забрать прогресс из аккаунта и отправить туда то, что решено в этом браузере
  async function sync() {
    if (st.syncing) return;
    st.syncing = true;
    try {
      const d = await api({ action: 'progress' });
      st.user = d.user;
      if (d.user && d.progress) {
        const server = d.progress.done || {};
        const merged = Object.assign({}, server);
        Object.entries(st.done).forEach(([id, t]) => { merged[id] = merged[id] ? Math.min(merged[id], t) : t; });
        const needPush = Object.keys(st.done).some((id) => !server[id]);
        st.done = merged;
        saveLocal();
        if (needPush) await push();
      }
    } catch (e) {
      if (st.user === undefined) st.user = null;
    }
    st.syncing = false;
    renderAll();
  }
  async function push() {
    if (!st.user) return;
    try {
      const d = await api(null, { action: 'progress', done: st.done });
      if (d.progress) { st.done = Object.assign({}, st.done, d.progress.done); saveLocal(); }
    } catch (e) { /* сохранится при следующей синхронизации */ }
  }

  const tasks = () => LAB.tasks;
  const task = (id) => LAB.tasks.find((t) => t.id === id) || null;
  const cur = () => (ui && ui.S.task ? task(ui.S.task) : null);
  const doneCount = () => tasks().filter((t) => st.done[t.id]).length;
  const nextTask = (t) => { const list = tasks(); const k = list.indexOf(t); return list.slice(k + 1).find((x) => !st.done[x.id]) || list[k + 1] || null; };

  function stopPlay() {
    if (!st.play) return;
    st.play.skip = true;
    ui.stopAnim();
  }

  function start(id) {
    const t = task(id);
    if (!t) return;
    stopPlay();
    const S = ui.S;
    const own = !S.task && !S.preset && S.net.devices.length;
    if (own && !confirm('Открыть задачу «' + t.title + '»? Схема на холсте будет заменена (сохрани её в «Мои карты», если она нужна).')) return;
    st.results = null;
    st.stale = false;
    st.collapsed = false;
    st.showKnow = !st.done[id];
    ui.setNet(LAB.buildTask(t), null, null, t.id);
    closePop();
    renderCard();
  }

  // Проверка: условия считаются сразу, затем по очереди проигрываются на холсте (ping, DHCP, HTTP…),
  // и отметка у каждого условия появляется, когда показ его операций закончился
  async function check() {
    const t = cur();
    if (!t || st.play) return;
    const res = LAB.checkTask(t, ui.S.net);
    st.results = res;
    st.stale = false;
    st.play = { skip: false, title: '', task: t.id };
    for (let i = 0; i < res.length; i++) {
      st.revealed = i;
      const list = (res.runs || []).filter((x) => x.check === i).slice(0, 4);
      for (const run of list) {
        if (st.play.skip) break;
        st.play.title = run.title;
        renderCard();
        await ui.play(run.r, run.title, 3);
      }
      if (st.play.skip) break;
    }
    const aborted = st.play.task !== ui.S.task;
    st.play = null;
    st.revealed = res.length;
    if (aborted) { renderCard(); return; }
    const ok = res.every((r) => r.ok);
    if (ok && !st.done[t.id]) {
      st.done[t.id] = now();
      saveLocal();
      push();
    }
    if (ok) ui.hint('Задача «' + t.title + '» решена', 'ok');
    renderCard();
    renderPop();
    updateBtn();
  }

  /* ===== Карточка задачи ===== */
  function renderCard() {
    if (!card) return;
    const t = cur();
    card.hidden = !t;
    if (!t) return;
    const solved = !!st.done[t.id];
    const allOk = !st.play && st.results && st.results.every((r) => r.ok);
    card.className = 'lab-task' + (st.collapsed ? ' is-collapsed' : '') + (allOk ? ' is-ok' : '');
    const head = h('div', { class: 'lab-task__head' },
      h('span', { class: 'lab-task__lvl' }, 'Уровень ' + t.level),
      h('b', { class: 'lab-task__title' }, t.title),
      solved ? h('span', { class: 'lab-task__done', title: 'Решена' }, '✓') : null,
      h('button', { type: 'button', class: 'lab-task__ic', title: st.collapsed ? 'Развернуть' : 'Свернуть', 'aria-expanded': st.collapsed ? 'false' : 'true', onClick: () => { st.collapsed = !st.collapsed; saveLocal(); renderCard(); } }, ui.icon('chevron', st.collapsed ? 'lab-ico--closed' : '')),
      h('button', { type: 'button', class: 'lab-task__ic', title: 'Выйти из задачи', onClick: () => { stopPlay(); ui.S.task = null; ui.save(); renderCard(); updateBtn(); } }, ui.icon('close')));
    if (st.collapsed) { card.replaceChildren(head); return; }

    const nHints = Math.min(st.hints[t.id] || 0, t.hints.length);
    const body = [
      h('p', { class: 'lab-task__goal' }, t.goal),
      h('details', { class: 'lab-task__know', open: st.showKnow, onToggle: (e) => { st.showKnow = e.target.open; } },
        ui.foldSummary('Что нужно знать'), h('p', null, t.know)),
    ];
    // Во время показа отметки открываются по одной; текущее условие помечено «проверяю»
    const items = (st.results || t.checks.map((c) => ({ text: c.text, ok: null }))).map((r, k) => {
      if (!st.play || !st.results) return r;
      if (k < st.revealed) return r;
      if (k === st.revealed) return { text: r.text, ok: null, run: true, why: st.play.title };
      return { text: r.text, ok: null };
    });
    body.push(h('ul', { class: 'lab-task__checks' + (st.stale ? ' is-stale' : '') }, items.map((r) => h('li', { class: r.run ? 'is-run' : r.ok == null ? '' : r.ok ? 'is-ok' : 'is-bad' },
      h('span', { class: 'lab-task__mark' }, r.run ? '•' : r.ok == null ? '○' : r.ok ? '✓' : '✕'),
      h('span', null, r.text, r.why ? h('small', null, r.why) : null)))));
    if (st.stale && st.results) body.push(h('p', { class: 'lab-task__note' }, 'Схема изменилась после проверки: проверь ещё раз.'));
    if (nHints) body.push(h('ol', { class: 'lab-task__hints' }, t.hints.slice(0, nHints).map((x) => h('li', null, x))));
    if (allOk) {
      const nx = nextTask(t);
      body.push(h('div', { class: 'lab-task__win' }, h('b', null, 'Готово!'), nx ? ' Следующая: «' + nx.title + '».' : ' Это была последняя задача: курс пройден.'));
    }
    const nx = allOk ? nextTask(t) : null;
    const actions = h('div', { class: 'lab-task__actions' },
      st.play ? h('button', { type: 'button', class: 'btn btn--primary', onClick: stopPlay }, 'Пропустить показ')
        : h('button', { type: 'button', class: 'btn btn--primary', onClick: check }, 'Проверить'),
      nx ? h('button', { type: 'button', class: 'lab-btn', onClick: () => start(nx.id) }, 'Дальше ->') : null,
      nHints < t.hints.length ? h('button', { type: 'button', class: 'lab-btn', onClick: () => { st.hints[t.id] = nHints + 1; saveLocal(); renderCard(); } }, 'Подсказка ' + (nHints + 1) + '/' + t.hints.length) : null,
      nHints >= t.hints.length && t.solution ? h('button', { type: 'button', class: 'lab-btn lab-btn--quiet', onClick: () => alert('Решение: ' + t.solution) }, 'Решение') : null,
      h('button', { type: 'button', class: 'lab-btn lab-btn--quiet', title: 'Вернуть задачу к началу', onClick: () => { if (confirm('Начать задачу заново? Твои изменения на холсте пропадут.')) { if (st.play) st.play.task = null; stopPlay(); ui.setNet(LAB.buildTask(t), null, null, t.id); st.results = null; renderCard(); } } }, 'Заново'));
    card.replaceChildren(head, h('div', { class: 'lab-task__body' }, body), actions);
  }

  /* ===== Список задач ===== */
  function renderPop() {
    if (!pop || pop.hidden) return;
    const total = tasks().length, done = doneCount();
    const acc = st.user === undefined ? h('span', null, 'проверяю вход…')
      : st.user ? h('span', null, 'Прогресс сохраняется в аккаунте ' + st.user.name + '.')
        : h('span', null, 'Прогресс хранится только в этом браузере. ', h('a', { href: LOGIN(), target: '_blank', rel: 'noopener' }, 'Войди'), ', чтобы он сохранялся в аккаунте, ', h('button', { type: 'button', class: 'lab-linkbtn', onClick: sync }, 'я вошёл'), '.');
    pop.replaceChildren(
      h('div', { class: 'lab-tasks__head' },
        h('b', null, 'Задачи'),
        h('span', null, done + ' из ' + total),
        h('button', { type: 'button', class: 'lab-task__ic', title: 'Закрыть', onClick: closePop }, ui.icon('close'))),
      h('div', { class: 'lab-tasks__bar' }, h('i', { style: { width: (total ? (done / total) * 100 : 0) + '%' } })),
      h('p', { class: 'lab-tasks__acc' }, acc),
      h('div', { class: 'lab-tasks__list' }, LAB.taskLevels.map((lv) => {
        const list = tasks().filter((t) => t.level === lv.n);
        if (!list.length) return null;
        const d = list.filter((t) => st.done[t.id]).length;
        return h('section', { class: 'lab-tasks__lvl' },
          h('div', { class: 'lab-tasks__lvlhead' }, h('b', null, lv.n + '. ' + lv.title), h('span', null, d + '/' + list.length)),
          h('p', null, lv.desc),
          list.map((t) => h('button', { type: 'button', class: 'lab-tasks__item' + (st.done[t.id] ? ' is-done' : '') + (ui.S.task === t.id ? ' is-cur' : ''), onClick: () => start(t.id) },
            h('span', { class: 'lab-tasks__mark' }, st.done[t.id] ? '✓' : '○'), t.title)));
      })),
      done === total && total ? h('p', { class: 'lab-tasks__final' }, 'Все задачи решены. Ты настраиваешь VLAN, транки, STP, маршрутизацию, OSPF, DHCP, DNS, NAT и ACL: это уверенный средний уровень.') : null,
      done ? h('p', { class: 'lab-tasks__reset' }, h('button', { type: 'button', class: 'lab-linkbtn', onClick: () => {
        if (!confirm('Сбросить отметки о решённых задачах в этом браузере?' + (st.user ? ' В аккаунте они останутся.' : ''))) return;
        st.done = {}; st.hints = {}; saveLocal(); renderAll();
      } }, 'сбросить прогресс в браузере')) : null);
  }

  function closePop() { if (pop) pop.hidden = true; }
  function updateBtn() { if (ui && ui.E.tasksBtn) ui.E.tasksBtn.textContent = 'Задачи ' + doneCount() + '/' + tasks().length; }
  function renderAll() { renderPop(); renderCard(); updateBtn(); }

  LAB.TasksUI = {
    init() {
      ui = LAB.ui;
      h = ui.h;
      loadLocal();
      card = h('div', { class: 'lab-task', hidden: true });
      pop = h('div', { class: 'lab-pop lab-tasks', hidden: true });
      ui.E.stage.append(card, pop);
      renderAll();
      sync();
    },
    toggle() {
      const show = pop.hidden;
      ui.closeMenus();
      if (!show) return;
      pop.hidden = false;
      renderPop();
      if (st.user === null) sync();
    },
    // Схема изменилась: результат последней проверки устарел
    stale() { if (st.results && !st.stale) { st.stale = true; renderCard(); } },
    render: renderAll,
  };
})();
