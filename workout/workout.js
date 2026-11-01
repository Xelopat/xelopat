// Страница тренировок: четыре вкладки, таймер отдыха и напоминание о завтраке (Web Push).
(() => {
  'use strict';

  const W = window.WORKOUT;
  const plan = W.plan;
  let state = W.state;

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  /* ---------- даты ---------- */
  const key = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  const parse = (k) => { const [y, m, d] = k.split('-').map(Number); return new Date(y, m - 1, d); };
  const addDays = (d, n) => { const r = new Date(d); r.setDate(r.getDate() + n); return r; };
  const dayNum = (d) => d.getDay() || 7; // 1 = понедельник … 7 = воскресенье
  const monday = (d) => addDays(d, 1 - dayNum(d));
  const human = (k) => parse(k).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' });
  const todayKey = key(new Date());

  const view = { tab: 'today', date: todayKey };

  /* ---------- данные ---------- */
  const dayState = (k) => state.days[k] || {};
  const doneMap = (k) => dayState(k).done || {};
  const dayPlan = (k) => plan.days[dayNum(parse(k))];
  const required = (k) => dayPlan(k).exercises.filter((e) => !e.optional);
  const doneCount = (k) => required(k).filter((e) => doneMap(k)[e.id]).length;
  const isFull = (k) => required(k).length > 0 && doneCount(k) === required(k).length;

  // Последний записанный вес упражнения до выбранной даты
  function lastWeight(id, before) {
    const dates = Object.keys(state.days).filter((k) => k < before && state.days[k].w && state.days[k].w[id]).sort();
    const k = dates[dates.length - 1];
    return k ? { value: state.days[k].w[id], date: k } : null;
  }

  async function api(action, payload = {}) {
    try {
      const res = await fetch('/workout/api', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action, csrf: W.csrf, ...payload }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || res.status);
      if (data.state) state = data.state;
      return data;
    } catch (e) {
      toast('Не сохранилось: ' + e.message);
      return null;
    }
  }

  function toast(text) {
    const el = document.createElement('div');
    el.className = 'wo-toast';
    el.textContent = text;
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 3500);
  }

  const bar = (done, total) => {
    const pct = total ? Math.round((done / total) * 100) : 0;
    return `<div class="wo-bar${total && done === total ? ' is-full' : ''}"><i style="width:${pct}%"></i></div>`;
  };

  /* ---------- вкладка «Сегодня» ---------- */
  function renderToday() {
    const k = view.date;
    const day = dayPlan(k);
    const total = required(k).length;
    const done = doneCount(k);
    const mon = monday(parse(k));

    const pills = [1, 2, 3, 4, 5, 6, 7].map((n) => {
      const dk = key(addDays(mon, n - 1));
      const cls = ['wo-day', dk === todayKey && 'is-today', dk === k && 'is-selected', isFull(dk) && 'is-full'].filter(Boolean).join(' ');
      return `<button type="button" class="${cls}" data-date="${dk}">${plan.days[n].short}<small>${parse(dk).getDate()}</small></button>`;
    }).join('');

    const last = lastMeasureDate();
    const due = !last || (parse(todayKey) - parse(last)) / 864e5 >= 28;
    const alert = due
      ? `<div class="wo-alert">${last ? `Последние замеры были ${human(last)}.` : 'Замеров пока нет.'} По плану их делают раз в 4 недели: <button type="button" data-goto="progress">записать замеры</button></div>`
      : '';

    const head = day.kind === 'rest'
      ? `<div class="wo-daycard is-rest"><div class="wo-daycard-label">${esc(day.name)} · ${human(k)}${k === todayKey ? ' · сегодня' : ''}</div><h2>Полный отдых</h2><p>Мышцы растут, пока ты отдыхаешь. Сон, еда и вода — сегодня вся работа.</p></div>`
      : `<div class="wo-daycard"><div class="wo-daycard-label">${esc(day.name)} · ${human(k)}${k === todayKey ? ' · сегодня' : ''}</div><h2>${esc(day.title)}</h2>
         <div class="wo-progress">${bar(done, total)}<span>${done} из ${total}</span></div></div>`;

    const list = day.exercises.map((ex) => {
      const isDone = !!doneMap(k)[ex.id];
      const w = (dayState(k).w || {})[ex.id];
      const lw = ex.track ? lastWeight(ex.id, k) : null;
      const badges = [ex.added && '<span class="wo-badge wo-badge--added">добавлено</span>', ex.optional && '<span class="wo-badge">по желанию</span>'].filter(Boolean).join('');
      const tools = [];
      if (ex.track) {
        tools.push(`<label class="wo-weight">Вес <input type="number" inputmode="decimal" step="0.5" min="0" max="1000" data-weight="${ex.id}" value="${w ?? ''}" placeholder="${lw ? lw.value : 'кг'}"> кг${lw ? `<span>· в прошлый раз ${lw.value} (${human(lw.date)})</span>` : ''}</label>`);
      }
      if (ex.rest) tools.push(`<button type="button" class="wo-rest" data-rest="${ex.rest}">⏱ отдых ${fmt(ex.rest)}</button>`);
      return `<li class="wo-ex${isDone ? ' is-done' : ''}">
        <label class="wo-check"><input type="checkbox" data-done="${ex.id}"${isDone ? ' checked' : ''} aria-label="Выполнено: ${esc(ex.name)}"><span></span></label>
        <div>
          <div class="wo-ex-top"><span class="wo-ex-name">${esc(ex.name)}</span><span class="wo-ex-sets">${esc(ex.sets)}</span></div>
          ${badges ? `<div class="wo-badges">${badges}</div>` : ''}
          ${ex.note ? `<p class="wo-ex-note">${esc(ex.note)}</p>` : ''}
          ${tools.length ? `<div class="wo-ex-tools">${tools.join('')}</div>` : ''}
        </div></li>`;
    }).join('');

    return `<div class="wo-days"><button type="button" class="wo-nav" data-week="-7" aria-label="Прошлая неделя">‹</button><div class="wo-days-pills">${pills}</div><button type="button" class="wo-nav" data-week="7" aria-label="Следующая неделя">›</button></div>
      ${alert}${head}<ul class="wo-list">${list}</ul>`;
  }

  /* ---------- вкладка «Неделя» ---------- */
  function renderWeek() {
    const mon = monday(parse(view.date));
    let full = 0;
    const cards = [1, 2, 3, 4, 5, 6, 7].map((n) => {
      const dk = key(addDays(mon, n - 1));
      const d = plan.days[n];
      const total = required(dk).length;
      const done = doneCount(dk);
      if (isFull(dk)) full++;
      return `<button type="button" class="wo-weekcard${dk === todayKey ? ' is-today' : ''}" data-open="${dk}">
        <div class="wo-weekcard-day">${esc(d.name)} · ${parse(dk).getDate()}</div>
        <h3>${esc(d.title)}</h3>
        <p>${total ? `${done} из ${total} выполнено` : 'без тренировки'}</p>${total ? bar(done, total) : ''}</button>`;
    }).join('');
    const days = [1, 2, 3, 4, 5, 6, 7].filter((n) => required(key(addDays(mon, n - 1))).length).length;
    return `<div class="wo-days" style="justify-content:space-between"><button type="button" class="wo-nav" data-week="-7" aria-label="Прошлая неделя">‹</button>
      <div class="wo-muted" style="text-align:center;font-size:14px">${human(key(mon))} — ${human(key(addDays(mon, 6)))} · выполнено дней: <b style="color:var(--text)">${full} из ${days}</b></div>
      <button type="button" class="wo-nav" data-week="7" aria-label="Следующая неделя">›</button></div>
      <div class="wo-weekgrid">${cards}</div>`;
  }

  /* ---------- вкладка «Прогресс» ---------- */
  const measureDates = () => Object.keys(state.measures).sort();
  const lastMeasureDate = () => measureDates().pop() || null;

  function latest(k) {
    const dates = measureDates().reverse();
    for (const d of dates) if (state.measures[d][k] != null) return state.measures[d][k];
    return plan.baseline[k] ?? null;
  }

  function renderProgress() {
    const goals = Object.entries(plan.targets).map(([k, t]) => {
      const cur = latest(k);
      const max = t.goals[t.goals.length - 1];
      const chips = t.goals.map((g, i) => `<span class="wo-chip${cur >= g ? ' is-hit' : ''}">${[1, 3, 6][i]} мес — ${g} ${t.unit}</span>`).join('');
      return `<div class="wo-goal"><div class="wo-goal-head"><span>${esc(t.label)}</span><b>${cur} ${t.unit}</b></div>${bar(Math.min(cur, max), max)}<div class="wo-chips">${chips}</div></div>`;
    }).join('');

    const fields = Object.entries(plan.measures).map(([k, [label, unit]]) => {
      const prev = latest(k);
      return `<div class="field"><label for="m-${k}">${esc(label)}, ${unit}</label><input id="m-${k}" type="number" inputmode="decimal" step="0.1" min="0" name="${k}" placeholder="${prev ?? ''}"></div>`;
    }).join('');

    const cols = Object.keys(plan.measures);
    const rows = measureDates().reverse().map((d) => `<tr><td>${human(d)} ${parse(d).getFullYear()}</td>${cols.map((c) => `<td>${state.measures[d][c] ?? '—'}</td>`).join('')}<td><button type="button" class="wo-del" data-del="${d}" aria-label="Удалить замер">×</button></td></tr>`).join('');

    return `<div class="wo-grid2">
      <section class="panel"><h2 class="wo-panel-title">Цели из плана</h2>${goals}
        <p class="wo-ex-note">Сейчас: берутся последние замеры, а пока их нет — стартовые значения из плана (подтягивания 9, вис 45 сек).</p></section>
      <section class="panel"><h2 class="wo-panel-title">Записать замеры</h2>
        <form class="wo-form" id="woMeasureForm">
          <div class="field wide"><label for="m-date">Дата</label><input id="m-date" type="date" name="date" value="${todayKey}" max="${todayKey}" required></div>
          ${fields}
          <p class="wo-form-hint">Заполни, что измерил: пустые поля пропустятся. Фото спереди, сбоку и сзади храни у себя.</p>
          <button type="submit" class="btn btn--primary">Сохранить</button>
        </form></section></div>
      <section class="panel"><h2 class="wo-panel-title">История замеров</h2>${rows
        ? `<div class="wo-table-wrap"><table class="data-table"><thead><tr><th>Дата</th>${cols.map((c) => `<th>${esc(plan.measures[c][0])}</th>`).join('')}<th></th></tr></thead><tbody>${rows}</tbody></table></div>`
        : '<p class="wo-muted" style="margin:0;font-size:14px">Пока пусто. Первые замеры лучше сделать до начала плана, чтобы было с чем сравнивать.</p>'}</section>`;
  }

  /* ---------- вкладка «План и питание» ---------- */
  function renderPlan() {
    return `<div class="wo-info">${plan.info.map((s) => `<details${s.open ? ' open' : ''}><summary>${esc(s.title)}${s.added ? ' <span class="wo-badge wo-badge--added">добавлено</span>' : ''}</summary><ul>${s.items.map((i) => `<li>${esc(i)}</li>`).join('')}</ul></details>`).join('')}</div>`;
  }

  /* ---------- общий рендер ---------- */
  const renderers = { today: renderToday, week: renderWeek, progress: renderProgress, plan: renderPlan };

  function render() {
    $$('.wo-tab').forEach((b) => {
      const on = b.dataset.tab === view.tab;
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-selected', on);
    });
    const root = $('#woView');
    // Фокус и прокрутка не должны прыгать при отметке упражнения
    const y = window.scrollY;
    root.innerHTML = renderers[view.tab]();
    window.scrollTo(0, y);
  }

  function setTab(tab) {
    view.tab = tab;
    try { history.replaceState(null, '', '#' + tab); } catch (e) { /* без истории тоже работает */ }
    render();
  }

  $('#woTabs').addEventListener('click', (e) => {
    const b = e.target.closest('[data-tab]');
    if (b) setTab(b.dataset.tab);
  });

  const root = $('#woView');

  root.addEventListener('click', (e) => {
    const t = e.target.closest('[data-date],[data-week],[data-open],[data-goto],[data-rest],[data-del]');
    if (!t) return;
    if (t.dataset.date) { view.date = t.dataset.date; render(); }
    else if (t.dataset.week) { view.date = key(addDays(parse(view.date), Number(t.dataset.week))); render(); }
    else if (t.dataset.open) { view.date = t.dataset.open; setTab('today'); window.scrollTo(0, 0); }
    else if (t.dataset.goto) setTab(t.dataset.goto);
    else if (t.dataset.rest) startTimer(Number(t.dataset.rest));
    else if (t.dataset.del && confirm('Удалить замер за ' + human(t.dataset.del) + '?')) {
      api('measure_del', { date: t.dataset.del }).then(render);
    }
  });

  root.addEventListener('change', async (e) => {
    const cb = e.target.closest('[data-done]');
    if (cb) {
      const k = view.date;
      const d = (state.days[k] = state.days[k] || {});
      d.done = Array.isArray(d.done) || !d.done ? {} : d.done;
      if (cb.checked) d.done[cb.dataset.done] = 1; else delete d.done[cb.dataset.done];
      render();
      await api('done', { date: k, id: cb.dataset.done, value: cb.checked });
      return;
    }
    const w = e.target.closest('[data-weight]');
    if (w) {
      const k = view.date;
      const res = await api('weight', { date: k, id: w.dataset.weight, value: w.value === '' ? null : Number(w.value) });
      if (res) render();
    }
  });

  root.addEventListener('submit', async (e) => {
    const form = e.target.closest('#woMeasureForm');
    if (!form) return;
    e.preventDefault();
    const values = {};
    $$('input[type=number]', form).forEach((i) => { if (i.value !== '') values[i.name] = Number(i.value); });
    if (!Object.keys(values).length) return toast('Заполни хотя бы одно поле');
    const res = await api('measure_add', { date: form.elements.date.value, values });
    if (res) render();
  });

  /* ---------- таймер отдыха ---------- */
  const timer = { end: 0, id: 0, ctx: null };
  const fmt = (s) => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;

  function beep() {
    try {
      timer.ctx = timer.ctx || new (window.AudioContext || window.webkitAudioContext)();
      [0, 0.25, 0.5].forEach((t) => {
        const o = timer.ctx.createOscillator();
        const g = timer.ctx.createGain();
        o.frequency.value = 880;
        g.gain.value = 0.15;
        o.connect(g).connect(timer.ctx.destination);
        o.start(timer.ctx.currentTime + t);
        o.stop(timer.ctx.currentTime + t + 0.15);
      });
    } catch (e) { /* звука нет — остаётся вибрация */ }
    if (navigator.vibrate) navigator.vibrate([200, 100, 200]);
  }

  function tick() {
    const left = Math.max(0, Math.ceil((timer.end - Date.now()) / 1000));
    $('#woTimerTime').textContent = fmt(left);
    if (left === 0) {
      clearInterval(timer.id);
      timer.id = 0;
      $('#woTimer').classList.add('is-done');
      beep();
      setTimeout(() => { if (!timer.id) $('#woTimer').hidden = true; }, 6000);
    }
  }

  function startTimer(sec) {
    // Контекст звука нужно создать по клику, иначе браузер его заблокирует
    try { timer.ctx = timer.ctx || new (window.AudioContext || window.webkitAudioContext)(); timer.ctx.resume(); } catch (e) { /* ничего */ }
    clearInterval(timer.id);
    timer.end = Date.now() + sec * 1000;
    $('#woTimer').hidden = false;
    $('#woTimer').classList.remove('is-done');
    tick();
    timer.id = setInterval(tick, 250);
  }

  $('#woTimerAdd').addEventListener('click', () => {
    if (!timer.id) return startTimer(30);
    timer.end += 30000;
    tick();
  });
  $('#woTimerStop').addEventListener('click', () => {
    clearInterval(timer.id);
    timer.id = 0;
    $('#woTimer').hidden = true;
  });

  /* ---------- напоминание о завтраке ---------- */
  const rem = { sub: null, error: '', msg: '', busy: false };
  const pushSupported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
  const isIos = /iPhone|iPad|iPod/.test(navigator.userAgent);

  const b64ToBytes = (s) => Uint8Array.from(atob(s.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(s.length / 4) * 4, '=')), (c) => c.charCodeAt(0));

  async function sha1(text) {
    const buf = await crypto.subtle.digest('SHA-1', new TextEncoder().encode(text));
    return [...new Uint8Array(buf)].map((b) => b.toString(16).padStart(2, '0')).join('');
  }

  // Страница /workout лежит вне области воркера (/workout/), поэтому serviceWorker.ready не сработает: ждём сам воркер
  function activated(reg) {
    const sw = reg.installing || reg.waiting || reg.active;
    if (!sw || sw.state === 'activated') return Promise.resolve();
    return new Promise((resolve, reject) => {
      const timeout = setTimeout(() => reject(new Error('Service worker не запустился. Обнови страницу и попробуй ещё раз.')), 15000);
      sw.addEventListener('statechange', () => {
        if (sw.state === 'activated') { clearTimeout(timeout); resolve(); }
        else if (sw.state === 'redundant') { clearTimeout(timeout); reject(new Error('Service worker не установился.')); }
      });
    });
  }

  async function loadSub() {
    rem.sub = null;
    if (!pushSupported) return;
    try {
      const reg = await navigator.serviceWorker.getRegistration('/workout/');
      const sub = reg && await reg.pushManager.getSubscription();
      // Подписка считается включённой, только если о ней знает и сервер
      if (sub && state.reminder.subs.includes(await sha1(sub.endpoint))) rem.sub = sub;
    } catch (e) { /* подписки нет */ }
  }

  function renderReminder() {
    const el = $('#woReminder');
    const on = !!rem.sub;
    let hint = rem.msg;
    let err = !!rem.error;
    if (rem.error) hint = rem.error;
    else if (!hint) {
      if (!W.vapid) hint = 'Не удалось создать ключи: на сервере нет расширения openssl.';
      else if (!pushSupported) hint = isIos
        ? 'Айфон показывает пуши только сайтам, добавленным на экран «Домой»: Поделиться → На экран «Домой», потом открой страницу оттуда.'
        : 'Этот браузер не умеет получать уведомления с сайтов.';
      else if (Notification.permission === 'denied') { hint = 'Уведомления для сайта запрещены в настройках браузера. Разреши их и включи снова.'; err = true; }
      else hint = on ? 'Включено на этом устройстве. Уведомление придёт, даже если сайт закрыт.' : 'Уведомление с сайта придёт утром, даже если страница закрыта.';
    }
    const disabled = !pushSupported || !W.vapid || rem.busy;
    el.innerHTML = `<div class="wo-avatar" aria-hidden="true"></div>
      <div>
        <h2 class="wo-rem-title">Напоминание позавтракать</h2>
        <div class="wo-rem-line"><span>каждый день в</span><input type="time" id="woRemTime" value="${esc(state.reminder.time)}" aria-label="Время напоминания"></div>
        <p class="wo-rem-status${err ? ' is-error' : ''}">${esc(hint)}</p>
      </div>
      <div class="wo-rem-actions">
        ${on ? '<button type="button" class="btn" id="woRemTest">Проверить</button>' : ''}
        <label class="wo-switch"><input type="checkbox" id="woRemToggle" role="switch"${on ? ' checked' : ''}${disabled ? ' disabled' : ''} aria-label="Напоминание о завтраке"><span></span></label>
      </div>`;
  }

  async function setReminder(enable) {
    rem.error = rem.msg = '';
    rem.busy = true;
    renderReminder();
    try {
      if (enable) {
        if ((await Notification.requestPermission()) !== 'granted') throw new Error('Без разрешения на уведомления напоминание не включить.');
        const reg = await navigator.serviceWorker.register('/workout/sw.js');
        await activated(reg);
        let sub = await reg.pushManager.getSubscription();
        const opts = { userVisibleOnly: true, applicationServerKey: b64ToBytes(W.vapid) };
        try {
          sub = sub || await reg.pushManager.subscribe(opts);
        } catch (e) {
          // Подписка от старых ключей сервера: сбрасываем и подписываемся заново
          if (sub) await sub.unsubscribe();
          sub = await reg.pushManager.subscribe(opts);
        }
        const res = await api('push_sub', { endpoint: sub.endpoint, tz: Intl.DateTimeFormat().resolvedOptions().timeZone });
        if (!res) throw new Error('Сервер не сохранил подписку.');
        rem.sub = sub;
        rem.msg = `Готово. Напоминание придёт в ${state.reminder.time}. Нажми «Проверить», чтобы убедиться, что уведомления доходят.`;
      } else if (rem.sub) {
        const endpoint = rem.sub.endpoint;
        await rem.sub.unsubscribe();
        await api('push_unsub', { endpoint });
        rem.sub = null;
        rem.msg = 'Напоминание выключено на этом устройстве.';
      }
    } catch (e) {
      rem.error = e.message || 'Не получилось.';
      await loadSub();
    }
    rem.busy = false;
    renderReminder();
  }

  $('#woReminder').addEventListener('change', async (e) => {
    if (e.target.id === 'woRemToggle') setReminder(e.target.checked);
    if (e.target.id === 'woRemTime' && e.target.value) {
      const res = await api('reminder_time', { time: e.target.value });
      if (res) { rem.msg = `Время сохранено: ${e.target.value}.`; renderReminder(); }
    }
  });

  $('#woReminder').addEventListener('click', async (e) => {
    if (e.target.id !== 'woRemTest') return;
    e.target.disabled = true;
    const res = await api('push_test');
    rem.error = '';
    rem.msg = res && res.sent > 0
      ? 'Отправлено: уведомление должно прийти через несколько секунд.'
      : 'Push-сервис не принял запрос. Выключи напоминание и включи снова.';
    if (res && res.sent === 0) rem.error = rem.msg;
    await loadSub();
    renderReminder();
  });

  /* ---------- старт ---------- */
  const startTab = location.hash.slice(1);
  if (renderers[startTab]) view.tab = startTab;
  render();
  renderReminder();
  loadSub().then(renderReminder);
})();
