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
  const isLogged = (ex) => !ex.section;
  const pendingLogs = new Map();
  const logKey = (date, id) => date + '/' + id;

  /* ---------- данные ---------- */
  const dayState = (k) => state.days[k] || {};
  const doneMap = (k) => dayState(k).done || {};
  const dayPlan = (k) => plan.days[dayNum(parse(k))];
  const required = (k) => dayPlan(k).exercises.filter((e) => !e.optional);
  const doneCount = (k) => required(k).filter((e) => doneMap(k)[e.id]).length;
  const isFull = (k) => required(k).length > 0 && doneCount(k) === required(k).length;

  let requests = Promise.resolve();
  function api(action, payload = {}) {
    requests = requests.then(() => send(action, payload));
    return requests;
  }

  async function send(action, payload) {
    try {
      const res = await fetch('/workout/api', {
        method: 'POST',
        keepalive: action === 'exercise_log',
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

  function savedLog(date, id) {
    const day = dayState(date);
    const sets = day.sets?.[id] || [];
    const legacy = day.w?.[id];
    const weights = day.sw?.[id] || (legacy == null ? [] : (sets.length ? sets.map(() => legacy) : [legacy]));
    return { sets, weights };
  }

  function suggestedWeights(ex, before) {
    if (!ex.track) return [];
    for (const date of Object.keys(state.days).filter((date) => date < before).sort().reverse()) {
      const { w = {}, sw = {} } = dayState(date);
      const ids = new Set([ex.id, ...Object.keys(sw), ...Object.keys(w)]);
      for (const id of ids) {
        const previous = exercises.get(id);
        if (!previous || groupOf(previous) !== groupOf(ex)) continue;
        const { weights } = savedLog(date, id);
        if (weights.some((weight) => weight != null)) return weights;
      }
    }
    return [ex.defaultWeight ?? 5];
  }

  const units = (ex) => ex.seconds ? 'сек' : 'повт.';
  const number = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 });
  const displayNumber = (value) => value == null ? '—' : number.format(value);
  const plannedCount = (ex) => Number(ex.sets.match(/^(\d+)×/)?.[1] || 1);

  function setRow(ex, i, value, weight, defaults) {
    const index = i + 1;
    const planned = ex.seconds ? null : ex.sets.match(/^\d+×(\d+)/)?.[1];
    const suggested = value == null && planned != null;
    const defaultWeight = defaults[i] ?? defaults.find((entry) => entry != null);
    const suggestedWeight = weight == null && defaultWeight > 0;
    return `<div class="wo-set-row">
      <span class="wo-set-index">${index}</span>
      <div class="wo-stepper">
        <input type="number" inputmode="numeric" min="1" max="${ex.seconds ? 7200 : 999}" data-set-value
          aria-label="${ex.seconds ? 'Секунды' : 'Повторы'}, подход ${index}" value="${value ?? planned ?? ''}"
          placeholder="${ex.seconds || '—'}"${suggested ? ' data-planned title="По плану. Нажми Enter, чтобы записать."' : ''}>
        <button type="button" class="wo-rest" data-step="-1" aria-label="Уменьшить, подход ${index}">−</button>
        <button type="button" class="wo-rest" data-step="1" aria-label="Увеличить, подход ${index}">+</button>
      </div>
      ${ex.track ? `<div class="wo-stepper wo-weight-stepper">
        <input type="number" inputmode="decimal" step="0.01" min="0.01" max="1000"
          data-set-weight aria-label="Вес, подход ${index}" value="${weight ?? (suggestedWeight ? defaultWeight : '')}"
          placeholder="—"${suggestedWeight ? ' data-planned title="Предложенный вес. Нажми Enter, чтобы записать."' : ''}>
        <button type="button" class="wo-rest" data-step="-5" aria-label="Уменьшить вес на 5 кг, подход ${index}">−5</button>
        <button type="button" class="wo-rest" data-step="5" aria-label="Увеличить вес на 5 кг, подход ${index}">+5</button>
      </div>` : ''}
      ${ex.seconds ? '<button type="button" class="wo-rest" data-ex-timer>Старт</button>' : ''}
    </div>`;
  }

  function exerciseLog(ex, date) {
    const draft = pendingLogs.get(logKey(date, ex.id));
    const { sets, weights } = draft?.payload || savedLog(date, ex.id);
    const count = sets.length || plannedCount(ex);
    const defaults = suggestedWeights(ex, date);
    return `<details class="wo-log">
      <summary>Подходы</summary>
      <form data-log="${ex.id}" data-log-date="${date}">
        <div class="wo-ex-tools">
          <label class="wo-weight">Подходов <input type="number" inputmode="numeric" min="1" max="30" name="count" value="${count}" required></label>
          <span class="wo-save-status" role="status">${draft?.message || ''}</span>
        </div>
        <div class="wo-sets${ex.track ? ' wo-sets--weighted' : ''}">
          <div class="wo-set-head"><span>№</span><span>${ex.seconds ? 'Секунды' : 'Повторы'}</span>${ex.track ? '<span>Вес, кг</span>' : ''}</div>
          <div class="wo-set-values">${Array.from({ length: count }, (_, i) => setRow(ex, i, sets[i], weights[i], defaults)).join('')}</div>
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
          ${isLogged(ex) ? exerciseLog(ex, k) : ''}
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

  const statsExercises = [...exercises.values()].filter((ex) => isLogged(ex) && groupOf(ex) === ex.id)
    .sort((a, b) => a.name.localeCompare(b.name, 'ru'));
  const sum = (values) => values.reduce((total, value) => total + value, 0);

  function exerciseHistory(ex) {
    const ids = [...exercises.values()].filter((item) => groupOf(item) === ex.id).map((item) => item.id);
    const history = [];
    for (const date of Object.keys(state.days).sort().reverse()) {
      for (const id of ids) {
        const { sets, weights } = savedLog(date, id);
        const values = sets.filter((value) => value != null);
        const recordedWeights = weights.filter((weight) => weight != null);
        if (!values.length && !recordedWeights.length && !doneMap(date)[id]) continue;
        let weightedReps = 0;
        let volume = 0;
        if (!ex.seconds) {
          sets.forEach((reps, i) => {
            if (reps == null || weights[i] == null) return;
            weightedReps += reps;
            volume += reps * weights[i];
          });
        }
        const mean = weightedReps ? volume / weightedReps
          : recordedWeights.length ? sum(recordedWeights) / recordedWeights.length : null;
        history.push({ date, sets, weights, mean, volume: weightedReps ? volume : null, total: values.length ? sum(values) : null });
      }
    }
    return history;
  }

  function statsChart(ex, history) {
    const metrics = {
      ...(ex.track && !ex.seconds ? { volume: ['Объём нагрузки', 'кг'] } : {}),
      ...(ex.track ? { mean: ['Средний вес, кг', 'кг'] } : {}),
      total: [ex.seconds ? 'Время, сек' : 'Повторы', units(ex)],
    };
    const options = Object.keys(metrics);
    const metric = view.metric || options.find((key) => history.some((row) => row[key] != null)) || options[0];
    const [label, unit] = metrics[metric];
    const entries = history.filter((row) => row[metric] != null).slice(0, 12).reverse();
    const max = Math.max(...entries.map((row) => row[metric]));
    return `<div class="wo-chart">
      ${options.length > 1 ? `<div class="field"><label for="woStatsMetric">Показатель графика</label><select id="woStatsMetric">
        ${options.map((key) => `<option value="${key}"${key === metric ? ' selected' : ''}>${metrics[key][0]}</option>`).join('')}</select></div>` : ''}
      ${entries.length ? `<ul class="wo-chart-bars" aria-label="${label} по тренировкам">${entries.map((row) => `<li>
        <time datetime="${row.date}">${human(row.date)}</time>
        <span class="wo-bar" aria-hidden="true"><i style="width:${100 * row[metric] / max}%"></i></span>
        <b>${displayNumber(row[metric])} ${unit}</b>
      </li>`).join('')}</ul>` : '<p class="wo-muted">Для этого графика пока нет записанных значений.</p>'}
    </div>`;
  }

  function renderStats() {
    if (!view.exercise) {
      view.exercise = statsExercises.find((ex) => exerciseHistory(ex).some((row) => row.total != null || row.mean != null))?.id
        || statsExercises[0].id;
    }
    const ex = exercises.get(view.exercise);
    const history = exerciseHistory(ex);
    const rows = history.map(({ date, sets, weights, mean, volume }) => {
      const values = Array.from({ length: Math.max(sets.length, weights.length) }, (_, i) => {
        if (sets[i] == null && weights[i] == null) return '';
        return `<span>${sets[i] == null ? '' : `${sets[i]} ${units(ex)}`}${sets[i] != null && weights[i] != null ? ' × ' : ''}${weights[i] == null ? '' : `${displayNumber(weights[i])} кг`}</span>`;
      }).filter(Boolean);
      return `<tr><td>${human(date)} ${parse(date).getFullYear()}</td>
        <td class="wo-stats-sets">${values.join('') || 'Отмечено выполнение'}</td>
        ${ex.track ? `<td>${displayNumber(mean)}</td>` : ''}
        ${ex.track && !ex.seconds ? `<td>${displayNumber(volume)}</td>` : ''}</tr>`;
    }).join('');
    const goals = Object.entries(plan.targets).map(([k, t]) => {
      const cur = latest(k);
      const max = t.goals[t.goals.length - 1];
      const chips = t.goals.map((g, i) => `<span class="wo-chip${cur >= g ? ' is-hit' : ''}">${[1, 3, 6][i]} мес — ${g} ${t.unit}</span>`).join('');
      return `<div class="wo-goal"><div class="wo-goal-head"><span>${esc(t.label)}</span><b>${cur} ${t.unit}</b></div>${bar(Math.min(cur, max), max)}<div class="wo-chips">${chips}</div></div>`;
    }).join('');
    return `<section class="panel wo-stats"><h2 class="wo-panel-title">История упражнений</h2>
      <div class="field"><label for="woStatsExercise">Упражнение</label>
        <select id="woStatsExercise">${statsExercises.map((item) => `<option value="${item.id}"${item.id === ex.id ? ' selected' : ''}>${esc(item.name)}</option>`).join('')}</select></div>
      ${statsChart(ex, history)}
      ${rows ? `<div class="wo-table-wrap"><table class="data-table"><thead><tr><th>Дата</th><th>Подходы</th>
        ${ex.track ? '<th>Средний вес, кг</th>' : ''}${ex.track && !ex.seconds ? '<th>Объём нагрузки</th>' : ''}</tr></thead><tbody>${rows}</tbody></table></div>`
        : '<p class="wo-muted">Тренировок с этим упражнением пока нет.</p>'}
      ${ex.track && !ex.seconds ? '<p class="wo-ex-note">Объём нагрузки в килограммах — сумма «вес × повторы» за тренировку.</p>' : ''}
      </section>
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
    const t = e.target.closest('[data-date],[data-week],[data-open],[data-goto],[data-rest],[data-del],[data-ex-timer],[data-step]');
    if (!t) return;
    if (t.dataset.date) { view.date = t.dataset.date; render(); }
    else if (t.dataset.week) { view.date = key(addDays(parse(view.date), Number(t.dataset.week))); render(); }
    else if (t.dataset.open) { view.date = t.dataset.open; setTab('today'); window.scrollTo(0, 0); }
    else if (t.dataset.goto) setTab(t.dataset.goto);
    else if (t.dataset.rest) startTimer(Number(t.dataset.rest));
    else if (t.dataset.step) {
      const input = $('input', t.closest('.wo-stepper'));
      const current = input.valueAsNumber || Number(input.placeholder) || 0;
      const next = Math.min(Number(input.max), current + Number(t.dataset.step));
      input.value = next > 0 ? Number(next.toFixed(2)) : '';
      input.dispatchEvent(new Event('input', { bubbles: true }));
    } else if (t.hasAttribute('data-ex-timer')) {
      const row = t.closest('.wo-set-row');
      const input = $('[data-set-value]', row);
      if (!input.reportValidity()) return;
      const { log: id, logDate: date } = t.form.dataset;
      const ex = exercises.get(id);
      const index = [...row.parentElement.children].indexOf(row);
      const seconds = input.valueAsNumber || ex.seconds;
      startTimer(seconds, ex.name, () => recordTimer(date, id, index, seconds));
    } else if (t.dataset.del && confirm('Удалить замер за ' + human(t.dataset.del) + '?')) {
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
      view.metric = null;
      render();
      $('#woStatsExercise').focus();
    }
    if (e.target.id === 'woStatsMetric') {
      view.metric = e.target.value;
      render();
      $('#woStatsMetric').focus();
    }
  });

  root.addEventListener('input', (e) => {
    const form = e.target.form;
    if (!form?.dataset.log) return;
    const { log: id, logDate: date } = form.dataset;
    const edited = [e.target];
    if (e.target.matches('[data-set-weight]') && e.target.value && e.target.validity.valid) {
      edited.push($('[data-set-value]', e.target.closest('.wo-set-row')));
    }
    for (const input of edited) {
      if (!input.hasAttribute('data-planned')) continue;
      input.removeAttribute('data-planned');
      input.removeAttribute('title');
    }
    if (e.target.name === 'count' && e.target.validity.valid) {
      const ex = exercises.get(id);
      const container = $('.wo-set-values', form);
      const count = e.target.valueAsNumber;
      const defaults = suggestedWeights(ex, date);
      while (container.children.length < count) {
        container.insertAdjacentHTML('beforeend', setRow(ex, container.children.length, null, null, defaults));
      }
      [...container.children].forEach((row, i) => {
        row.hidden = i >= count;
        $$('input, button', row).forEach((control) => { control.disabled = row.hidden; });
      });
    }
    if ($$('input:enabled', form).some((input) => !input.validity.valid)) {
      $('.wo-save-status', form).textContent = 'Проверь значения';
      return;
    }
    const read = (selector) => $$(selector + ':enabled', form)
      .map((input) => input.hasAttribute('data-planned') ? null : input.valueAsNumber || null);
    scheduleLog({ date, id, sets: read('[data-set-value]'), weights: read('[data-set-weight]') });
  });

  root.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter' || !e.target.matches('[data-planned]')) return;
    e.preventDefault();
    e.target.dispatchEvent(new Event('input', { bubbles: true }));
  });

  const logForm = (date, id) => root.querySelector(`[data-log-date="${date}"][data-log="${id}"]`);

  function setLogStatus(draft, message) {
    draft.message = message;
    const status = logForm(draft.payload.date, draft.payload.id)?.querySelector('.wo-save-status');
    if (status) status.textContent = message;
  }

  function scheduleLog(payload) {
    const k = logKey(payload.date, payload.id);
    clearTimeout(pendingLogs.get(k)?.timeout);
    const draft = { payload };
    pendingLogs.set(k, draft);
    draft.timeout = setTimeout(() => saveLog(k, draft), 1500);
    setLogStatus(draft, 'Ожидает сохранения');
  }

  async function saveLog(k, draft) {
    clearTimeout(draft.timeout);
    if (draft.saving) return;
    draft.saving = true;
    setLogStatus(draft, 'Сохраняю…');
    const res = await api('exercise_log', draft.payload);
    draft.saving = false;
    if (pendingLogs.get(k) !== draft) return;
    if (res) pendingLogs.delete(k);
    setLogStatus(draft, res ? 'Сохранено' : 'Не сохранено');
    if (res && view.tab === 'stats') {
      const focused = document.activeElement.id;
      render();
      if (focused) document.getElementById(focused)?.focus();
    }
  }

  function recordTimer(date, id, index, seconds) {
    const form = logForm(date, id);
    if (form) {
      const input = $$('[data-set-value]', form)[index];
      if (input && !input.disabled && !input.value) {
        input.value = seconds;
        input.dispatchEvent(new Event('input', { bubbles: true }));
      }
      return;
    }
    const ex = exercises.get(id);
    const log = pendingLogs.get(logKey(date, id))?.payload || savedLog(date, id);
    const count = log.sets.length || plannedCount(ex);
    if (index >= count || log.sets[index] != null) return;
    const sets = Array.from({ length: count }, (_, i) => i === index ? seconds : (log.sets[i] ?? null));
    const weights = ex.track ? Array.from({ length: count }, (_, i) => log.weights[i] ?? null) : [];
    scheduleLog({ date, id, sets, weights });
  }

  function flushLogs() {
    for (const [k, draft] of pendingLogs) saveLog(k, draft);
  }

  window.addEventListener('online', flushLogs);
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) flushLogs();
  });

  root.addEventListener('submit', async (e) => {
    const form = e.target.closest('#woMeasureForm, [data-log]');
    if (!form) return;
    e.preventDefault();
    if (form.dataset.log) return;
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

  if (window.XelopatNative) {
    window.XelopatNative.onmessage = (event) => alert(event.data);
  }

  function stopTones() {
    for (const tone of timer.tones) tone.stop();
    timer.tones = [];
  }

  function scheduleTones() {
    stopTones();
    if (window.XelopatNative) {
      window.XelopatNative.postMessage(JSON.stringify({ action: 'start', end: timer.end, label: timer.label }));
      return;
    }
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
      if (!window.XelopatNative && navigator.vibrate) navigator.vibrate([200, 100, 200]);
      try { localStorage.removeItem('workoutRestTimer'); } catch (e) {}
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
    try {
      localStorage.setItem('workoutRestTimer', JSON.stringify({ start: timer.start, end: timer.end, label: timer.label, exercise: !!timer.finish }));
    } catch (e) {}
    scheduleTones();
    tick();
    timer.id = setInterval(tick, 100);
    keepScreenOn();
  }

  $('#woTimerAdd').addEventListener('click', () => {
    if (!timer.id) return startTimer(30);
    timer.end += 30000;
    try {
      localStorage.setItem('workoutRestTimer', JSON.stringify({ start: timer.start, end: timer.end, label: timer.label, exercise: !!timer.finish }));
    } catch (e) {}
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
    window.XelopatNative?.postMessage(JSON.stringify({ action: 'cancel' }));
    try { localStorage.removeItem('workoutRestTimer'); } catch (e) {}
  });
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden && timer.id) {
      tick();
      if (timer.id) keepScreenOn();
    }
  });

  try {
    const saved = JSON.parse(localStorage.getItem('workoutRestTimer'));
    if (saved && !saved.exercise && Number.isFinite(saved.end) && saved.end > Date.now()) {
      Object.assign(timer, { start: saved.start, end: saved.end, label: saved.label });
      clock.hidden = false;
      tick();
      timer.id = setInterval(tick, 100);
    }
  } catch (e) {}

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
