// Дневник тренировок, таймеры и напоминания.
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
  const exercises = new Map(Object.values(plan.days).flatMap((day) => day.exercises.map((ex) => [ex.id, ex])));
  const groupOf = (ex) => ex.group || ex.id;

  /* ---------- данные ---------- */
  const dayState = (k) => state.days[k] || {};
  const doneMap = (k) => dayState(k).done || {};
  const dayPlan = (k) => plan.days[dayNum(parse(k))];
  const required = (k) => dayPlan(k).exercises.filter((e) => !e.optional);
  const doneCount = (k) => required(k).filter((e) => doneMap(k)[e.id]).length;
  const isFull = (k) => required(k).length > 0 && doneCount(k) === required(k).length;

  function lastWeight(ex, before) {
    for (const date of Object.keys(state.days).filter((k) => k < before).sort().reverse()) {
      for (const [id, value] of Object.entries(dayState(date).w || {})) {
        const previous = exercises.get(id);
        if (previous && groupOf(previous) === groupOf(ex)) return { value, date };
      }
    }
    return null;
  }

  let requests = Promise.resolve();
  function api(action, payload = {}) {
    requests = requests.then(() => send(action, payload));
    return requests;
  }

  async function send(action, payload) {
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

  const savedSets = (date, id) => dayState(date).sets?.[id] || [];
  const units = (ex) => ex.seconds ? 'сек' : 'повт.';
  const logSummary = (date, ex) => {
    const sets = savedSets(date, ex.id);
    const weight = dayState(date).w?.[ex.id];
    const details = [];
    if (sets.length) details.push(`${sets.length} подх. · ${sets.map((n) => n ?? '—').join(' / ')} ${units(ex)}`);
    if (weight != null) details.push(`${weight} кг`);
    return details.join(' · ') || 'Записать подходы';
  };

  function setRow(ex, i, value) {
    return `<div class="wo-set-row">
      <label class="wo-weight">Подход ${i + 1}
        <input type="number" inputmode="numeric" min="1" max="${ex.seconds ? 7200 : 999}"
          data-set-value value="${value ?? ''}" placeholder="${ex.seconds || ''}"> ${units(ex)}</label>
      ${ex.seconds ? '<button type="button" class="wo-rest" data-ex-timer>Запустить таймер</button>' : ''}
    </div>`;
  }

  function exerciseLog(ex, date) {
    const sets = savedSets(date, ex.id);
    const count = sets.length || Number(ex.sets.match(/^(\d+)×/)?.[1] || 1);
    const weight = dayState(date).w?.[ex.id];
    const last = ex.track ? lastWeight(ex, date) : null;
    return `<details class="wo-log">
      <summary>${logSummary(date, ex)}</summary>
      <form data-log="${ex.id}" data-log-date="${date}">
        <div class="wo-ex-tools">
          <label class="wo-weight">Подходов <input type="number" inputmode="numeric" min="1" max="30" name="count" value="${count}" required></label>
          ${ex.track ? `<label class="wo-weight">Вес <input type="number" inputmode="decimal" step="0.01" min="0.01" max="1000" name="weight"
            value="${weight ?? ''}" placeholder="${last?.value ?? ''}"> кг</label>` : ''}
          ${last ? `<span class="wo-muted">Прошлый вес · ${last.value} кг (${human(last.date)})</span>` : ''}
        </div>
        <div class="wo-sets">${Array.from({ length: count }, (_, i) => setRow(ex, i, sets[i])).join('')}</div>
        <div class="wo-ex-tools">
          <button type="submit" class="btn">Сохранить</button>
          <button type="button" class="wo-rest" data-clear-log>Очистить запись</button>
          <span class="wo-muted" role="status"></span>
        </div>
      </form>
    </details>`;
  }

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
    const due = k === todayKey && dayNum(parse(k)) === 7 && (!last || last < k);
    const alert = due
      ? '<div class="wo-alert">Сегодня контрольные замеры. <button type="button" data-goto="progress">Записать замеры</button></div>'
      : '';

    const head = day.kind === 'rest'
      ? `<div class="wo-daycard is-rest"><div class="wo-daycard-label">${esc(day.name)} · ${human(k)}${k === todayKey ? ' · сегодня' : ''}</div><h2>Полный отдых</h2><p>Мышцы растут, пока ты отдыхаешь. Сон, еда и вода — сегодня вся работа.</p></div>`
      : `<div class="wo-daycard"><div class="wo-daycard-label">${esc(day.name)} · ${human(k)}${k === todayKey ? ' · сегодня' : ''}</div><h2>${esc(day.title)}</h2>
         <div class="wo-progress">${bar(done, total)}<span>${done} из ${total}</span></div></div>`;

    const list = day.exercises.map((ex) => {
      const isDone = !!doneMap(k)[ex.id];
      const badges = [ex.added && '<span class="wo-badge wo-badge--added">добавлено</span>', ex.optional && '<span class="wo-badge">по желанию</span>'].filter(Boolean).join('');
      return `<li class="wo-ex${isDone ? ' is-done' : ''}">
        <label class="wo-check"><input type="checkbox" data-done="${ex.id}"${isDone ? ' checked' : ''} aria-label="Выполнено: ${esc(ex.name)}"><span></span></label>
        <div>
          <div class="wo-ex-top"><span class="wo-ex-name">${esc(ex.name)}</span><span class="wo-ex-sets">${esc(ex.sets)}</span></div>
          ${badges ? `<div class="wo-badges">${badges}</div>` : ''}
          ${ex.note ? `<p class="wo-ex-note">${esc(ex.note)}</p>` : ''}
          ${exerciseLog(ex, k)}
          ${ex.rest ? `<div class="wo-ex-tools"><button type="button" class="wo-rest" data-rest="${ex.rest}">⏱ отдых ${fmt(ex.rest)}</button></div>` : ''}
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
    const fields = Object.entries(plan.measures).map(([k, [label, unit]]) => {
      const prev = latest(k);
      return `<div class="field"><label for="m-${k}">${esc(label)}, ${unit}</label><input id="m-${k}" type="number" inputmode="decimal" step="0.1" min="0" name="${k}" placeholder="${prev ?? ''}"></div>`;
    }).join('');

    const cols = Object.keys(plan.measures);
    const rows = measureDates().reverse().map((d) => `<tr><td>${human(d)} ${parse(d).getFullYear()}</td>${cols.map((c) => `<td>${state.measures[d][c] ?? '—'}</td>`).join('')}<td><button type="button" class="wo-del" data-del="${d}" aria-label="Удалить замер">×</button></td></tr>`).join('');

    return `<section class="panel wo-measures"><h2 class="wo-panel-title">Записать замеры</h2>
        <form class="wo-form" id="woMeasureForm">
          <div class="field wide"><label for="m-date">Дата</label><input id="m-date" type="date" name="date" value="${todayKey}" max="${todayKey}" required></div>
          ${fields}
          <p class="wo-form-hint">Заполни, что измерил: пустые поля пропустятся. Фото спереди, сбоку и сзади храни у себя.</p>
          <button type="submit" class="btn btn--primary">Сохранить</button>
        </form></section>
      <section class="panel"><h2 class="wo-panel-title">История замеров</h2>${rows
        ? `<div class="wo-table-wrap"><table class="data-table"><thead><tr><th>Дата</th>${cols.map((c) => `<th>${esc(plan.measures[c][0])}</th>`).join('')}<th></th></tr></thead><tbody>${rows}</tbody></table></div>`
        : '<p class="wo-muted" style="margin:0;font-size:14px">Пока пусто. Первые замеры лучше сделать до начала плана, чтобы было с чем сравнивать.</p>'}</section>`;
  }

  function renderStats() {
    const history = new Map();
    for (const date of Object.keys(state.days).sort().reverse()) {
      const { w = {}, sets = {} } = dayState(date);
      for (const id of new Set([...Object.keys(w), ...Object.keys(sets)])) {
        const ex = exercises.get(id);
        if (!ex) continue;
        const group = groupOf(ex);
        if (!history.has(group)) history.set(group, []);
        history.get(group).push({ date, sets: sets[id] || [], weight: w[id] });
      }
    }
    const options = [...history.keys()].sort((a, b) => exercises.get(a).name.localeCompare(exercises.get(b).name, 'ru'));
    if (!history.has(view.exercise)) view.exercise = options[0];
    const ex = exercises.get(view.exercise);
    const rows = (history.get(view.exercise) || []).map(({ date, sets, weight }) => `<tr>
      <td>${human(date)} ${parse(date).getFullYear()}</td><td>${sets.length || '—'}</td>
      <td>${sets.map((n) => n ?? '—').join(' / ') || '—'}</td><td>${weight ?? '—'}</td></tr>`).join('');
    const goals = Object.entries(plan.targets).map(([k, t]) => {
      const cur = latest(k);
      const max = t.goals[t.goals.length - 1];
      const chips = t.goals.map((g, i) => `<span class="wo-chip${cur >= g ? ' is-hit' : ''}">${[1, 3, 6][i]} мес — ${g} ${t.unit}</span>`).join('');
      return `<div class="wo-goal"><div class="wo-goal-head"><span>${esc(t.label)}</span><b>${cur} ${t.unit}</b></div>${bar(Math.min(cur, max), max)}<div class="wo-chips">${chips}</div></div>`;
    }).join('');
    return `<section class="panel wo-stats"><h2 class="wo-panel-title">История упражнений</h2>
      ${ex ? `<div class="field"><label for="woStatsExercise">Упражнение</label>
        <select id="woStatsExercise">${options.map((id) => `<option value="${id}"${id === view.exercise ? ' selected' : ''}>${esc(exercises.get(id).name)}</option>`).join('')}</select></div>
        <div class="wo-table-wrap"><table class="data-table"><thead><tr><th>Дата</th><th>Подходы</th>
        <th>${ex.seconds ? 'Секунды' : 'Повторы'} по подходам</th><th>Вес, кг</th></tr></thead><tbody>${rows}</tbody></table></div>`
      : '<p class="wo-muted">Здесь появятся сохранённые подходы и рабочие веса.</p>'}</section>
      <section class="panel"><h2 class="wo-panel-title">Цели из плана</h2>${goals}
        <p class="wo-ex-note">Текущие значения берутся из последних замеров или стартовых данных плана.</p></section>`;
  }

  /* ---------- вкладка «План и питание» ---------- */
  function renderPlan() {
    return `<div class="wo-info">${plan.info.map((s) => `<details${s.open ? ' open' : ''}><summary>${esc(s.title)}${s.added ? ' <span class="wo-badge wo-badge--added">добавлено</span>' : ''}</summary><ul>${s.items.map((i) => `<li>${esc(i)}</li>`).join('')}</ul></details>`).join('')}</div>`;
  }

  /* ---------- общий рендер ---------- */
  const renderers = { today: renderToday, week: renderWeek, progress: renderProgress, stats: renderStats, plan: renderPlan };

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
    const t = e.target.closest('[data-date],[data-week],[data-open],[data-goto],[data-rest],[data-del],[data-ex-timer],[data-clear-log]');
    if (!t) return;
    if (t.dataset.date) { view.date = t.dataset.date; render(); }
    else if (t.dataset.week) { view.date = key(addDays(parse(view.date), Number(t.dataset.week))); render(); }
    else if (t.dataset.open) { view.date = t.dataset.open; setTab('today'); window.scrollTo(0, 0); }
    else if (t.dataset.goto) setTab(t.dataset.goto);
    else if (t.dataset.rest) startTimer(Number(t.dataset.rest));
    else if (t.hasAttribute('data-ex-timer')) {
      const input = $('[data-set-value]', t.closest('.wo-set-row'));
      if (!input.reportValidity()) return;
      const ex = exercises.get(t.form.dataset.log);
      const seconds = input.valueAsNumber || ex.seconds;
      startTimer(seconds, ex.name, () => {
        if (input.isConnected && !input.value) input.value = seconds;
      });
    } else if (t.hasAttribute('data-clear-log') && confirm('Очистить подходы и вес за этот день?')) {
      saveLog(t.form, [], null, true);
    }
    else if (t.dataset.del && confirm('Удалить замер за ' + human(t.dataset.del) + '?')) {
      api('measure_del', { date: t.dataset.del }).then(render);
    }
  });

  root.addEventListener('change', async (e) => {
    const cb = e.target.closest('[data-done]');
    if (cb) {
      const k = view.date;
      cb.disabled = true;
      const res = await api('done', { date: k, id: cb.dataset.done, value: cb.checked });
      if (cb.isConnected) {
        if (!res) cb.checked = !cb.checked;
        cb.disabled = false;
        cb.closest('.wo-ex').classList.toggle('is-done', cb.checked);
        const done = doneCount(k);
        const total = required(k).length;
        $('.wo-progress', root).innerHTML = `${bar(done, total)}<span>${done} из ${total}</span>`;
        $('.wo-day.is-selected', root).classList.toggle('is-full', isFull(k));
      } else if (view.tab === 'week') render();
      return;
    }
    if (e.target.id === 'woStatsExercise') {
      view.exercise = e.target.value;
      render();
      $('#woStatsExercise').focus();
    }
  });

  root.addEventListener('input', (e) => {
    const form = e.target.form;
    if (!form?.dataset.log) return;
    $('[role=status]', form).textContent = '';
    if (e.target.name === 'count' && e.target.validity.valid) {
      const ex = exercises.get(form.dataset.log);
      const container = $('.wo-sets', form);
      const count = e.target.valueAsNumber;
      while (container.children.length < count) {
        container.insertAdjacentHTML('beforeend', setRow(ex, container.children.length));
      }
      [...container.children].forEach((row, i) => {
        row.hidden = i >= count;
        $$('input, button', row).forEach((control) => { control.disabled = row.hidden; });
      });
    }
  });

  async function saveLog(form, sets, weight, clear = false) {
    const buttons = $$('button', form);
    buttons.forEach((button) => { button.disabled = true; });
    const { log: id, logDate: date } = form.dataset;
    const res = await api('exercise_log', { date, id, sets, weight });
    if (form.isConnected) {
      buttons.forEach((button) => { button.disabled = !!button.closest('[hidden]'); });
      if (!res) return;
      if (clear) {
        form.closest('details').outerHTML = exerciseLog(exercises.get(id), date);
        return;
      }
      $('summary', form.closest('details')).textContent = logSummary(date, exercises.get(id));
      $('[role=status]', form).textContent = 'Сохранено';
    } else if (res && view.tab === 'stats') render();
  }

  root.addEventListener('submit', async (e) => {
    const form = e.target.closest('#woMeasureForm, [data-log]');
    if (!form) return;
    e.preventDefault();
    if (form.dataset.log) {
      const sets = $$('[data-set-value]', form).slice(0, form.elements.count.valueAsNumber)
        .map((input) => input.valueAsNumber || null);
      saveLog(form, sets, form.elements.weight?.valueAsNumber || null);
      return;
    }
    const values = {};
    $$('input[type=number]', form).forEach((i) => { if (i.value !== '') values[i.name] = Number(i.value); });
    if (!Object.keys(values).length) return toast('Заполни хотя бы одно поле');
    const res = await api('measure_add', { date: form.elements.date.value, values });
    if (res) render();
  });

  /* ---------- таймеры ---------- */
  const timer = { id: 0, hide: 0, ctx: null, tones: [], wake: null };
  const fmt = (s) => Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
  const clock = $('#woTimer');

  function stopTones() {
    for (const tone of timer.tones) tone.stop();
    timer.tones = [];
  }

  function scheduleTones() {
    stopTones();
    const ctx = timer.ctx;
    if (!ctx) return;
    const now = Date.now();
    const cues = timer.finish
      ? [[timer.start - 3000, 520], [timer.start - 2000, 520], [timer.start - 1000, 520], [timer.start, 1040]]
      : [];
    cues.push([timer.end, 880], [timer.end + 250, 880], [timer.end + 500, 880]);
    for (const [at, frequency] of cues) {
      if (at < now - 100) continue;
      const start = ctx.currentTime + Math.max(0, at - now) / 1000;
      const tone = ctx.createOscillator();
      const gain = ctx.createGain();
      tone.frequency.value = frequency;
      gain.gain.setValueAtTime(0, start);
      gain.gain.linearRampToValueAtTime(0.15, start + 0.015);
      gain.gain.linearRampToValueAtTime(0, start + 0.16);
      tone.connect(gain).connect(ctx.destination);
      tone.start(start);
      tone.stop(start + 0.16);
      timer.tones.push(tone);
    }
  }

  function releaseWakeLock() {
    timer.wake?.release().catch(() => {});
    timer.wake = null;
  }

  function keepScreenOn() {
    if (timer.wake && !timer.wake.released) return;
    const id = timer.id;
    navigator.wakeLock?.request('screen').then((lock) => {
      if (timer.id === id && (!timer.wake || timer.wake.released)) timer.wake = lock;
      else lock.release().catch(() => {});
    }).catch(() => {});
  }

  function tick() {
    const now = Date.now();
    const prep = now < timer.start;
    const left = Math.max(0, Math.ceil(((prep ? timer.start : timer.end) - now) / 1000));
    $('#woTimerLabel').textContent = prep ? 'Приготовься' : (left ? timer.label : 'Готово');
    $('#woTimerTime').textContent = prep ? left : fmt(left);
    if (left === 0) {
      clearInterval(timer.id);
      timer.id = 0;
      releaseWakeLock();
      clock.classList.add('is-done');
      if (navigator.vibrate) navigator.vibrate([200, 100, 200]);
      timer.finish?.();
      timer.hide = setTimeout(() => { clock.hidden = true; }, 6000);
    }
  }

  function startTimer(seconds, name, finish) {
    try {
      timer.ctx ??= new (window.AudioContext || window.webkitAudioContext)();
      timer.ctx.resume().catch(() => {});
    } catch (e) { /* остаётся визуальный таймер */ }
    clearInterval(timer.id);
    clearTimeout(timer.hide);
    timer.label = name || 'Отдых';
    timer.finish = finish;
    timer.start = Date.now() + (finish ? 3000 : 0);
    timer.end = timer.start + seconds * 1000;
    clock.hidden = false;
    clock.classList.remove('is-done');
    $('#woTimerAdd').hidden = !!finish;
    scheduleTones();
    tick();
    timer.id = setInterval(tick, 100);
    keepScreenOn();
  }

  $('#woTimerAdd').addEventListener('click', () => {
    if (!timer.id) return startTimer(30);
    timer.end += 30000;
    scheduleTones();
    tick();
  });
  $('#woTimerStop').addEventListener('click', () => {
    clearInterval(timer.id);
    clearTimeout(timer.hide);
    timer.id = 0;
    releaseWakeLock();
    stopTones();
    clock.hidden = true;
  });
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden && timer.id) {
      tick();
      if (timer.id) keepScreenOn();
    }
  });

  /* ---------- напоминания ---------- */
  const rem = { sub: null, measures: false, error: '', msg: '', busy: false };
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

  function saveSubscription(sub) {
    const { keys } = sub.toJSON();
    if (!keys?.p256dh || !keys.auth) throw new Error('Браузер не предоставил ключи уведомлений.');
    return api('push_sub', {
      endpoint: sub.endpoint,
      tz: Intl.DateTimeFormat().resolvedOptions().timeZone,
      p256dh: keys.p256dh,
      auth: keys.auth,
    });
  }

  async function loadSub() {
    rem.sub = null;
    rem.measures = false;
    if (!pushSupported) return;
    try {
      const reg = await navigator.serviceWorker.getRegistration('/workout/');
      const sub = reg && await reg.pushManager.getSubscription();
      // Подписка считается включённой, только если о ней знает и сервер
      if (!sub) return;
      const hash = await sha1(sub.endpoint);
      if (!state.reminder.subs.includes(hash)) return;
      rem.sub = sub;
      rem.measures = state.reminder.measure_subs.includes(hash);
      if (!rem.measures) {
        await reg.update();
        await activated(reg);
        rem.measures = !!(await saveSubscription(sub));
      }
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
      else if (!on) hint = 'Включи, чтобы получать оба напоминания, даже если сайт закрыт.';
      else hint = rem.measures ? 'Напоминания включены на этом устройстве.' : 'Завтрак включён. Обнови страницу, чтобы подключить замеры.';
    }
    const disabled = !pushSupported || !W.vapid || rem.busy;
    el.innerHTML = `<div class="wo-avatar" aria-hidden="true"></div>
      <div>
        <h2 class="wo-rem-title">Напоминание позавтракать</h2>
        <div class="wo-rem-line"><span>каждый день в</span><input type="time" id="woRemTime" value="${esc(state.reminder.time)}" aria-label="Время напоминания"></div>
        <h2 class="wo-rem-title wo-measure-rem-title">Контрольные замеры</h2>
        <div class="wo-rem-line">по воскресеньям в ${esc(state.reminder.measure_time)}</div>
        <p class="wo-rem-status${err ? ' is-error' : ''}">${esc(hint)}</p>
      </div>
      <div class="wo-rem-actions">
        ${on ? '<button type="button" class="btn" data-test-push="breakfast">Проверить завтрак</button><button type="button" class="btn" data-test-push="measurements">Проверить замеры</button>' : ''}
        <label class="wo-switch"><input type="checkbox" id="woRemToggle" role="switch"${on ? ' checked' : ''}${disabled ? ' disabled' : ''} aria-label="Уведомления о завтраке и замерах"><span></span></label>
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
        const res = await saveSubscription(sub);
        if (!res) throw new Error('Сервер не сохранил подписку.');
        rem.sub = sub;
        rem.measures = true;
        rem.msg = 'Готово. Можно проверить, что уведомления доходят.';
      } else if (rem.sub) {
        const endpoint = rem.sub.endpoint;
        await rem.sub.unsubscribe();
        await api('push_unsub', { endpoint });
        rem.sub = null;
        rem.measures = false;
        rem.msg = 'Напоминания выключены на этом устройстве.';
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
    const button = e.target.closest('[data-test-push]');
    if (!button) return;
    button.disabled = true;
    await loadSub();
    const res = await api('push_test', { type: button.dataset.testPush });
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
  if (Object.hasOwn(renderers, startTab)) view.tab = startTab;
  navigator.serviceWorker?.addEventListener('message', (event) => {
    if (event.data?.tab === 'progress') {
      setTab('progress');
      window.scrollTo(0, 0);
    }
  });
  render();
  renderReminder();
  loadSub().then(renderReminder);
})();
