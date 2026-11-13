(() => {
  'use strict';
  const config = JSON.parse(document.getElementById('bkData').textContent);
  const $ = id => document.getElementById(id);
  const node = (tag, text, cls) => { const n = document.createElement(tag); if (text !== undefined) n.textContent = text; if (cls) n.className = cls; return n; };
  const button = (text, fn, cls) => { const b = node('button', text, cls); b.type = 'button'; b.onclick = fn; return b; };
  const lower = s => s.trim().toLocaleLowerCase('ru');
  const unique = values => [...new Map(values.map(v => [lower(v), v])).values()];
  const cmp = (a, b) => a.localeCompare(b, 'ru', { sensitivity: 'base', numeric: true });
  const dateText = d => d ? new Date(d + 'T12:00:00').toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' }) : '';
  let items = config.items, viewing = null;
  let filter = { q: '', status: '', genres: [], tags: [], sort: 'read', limit: 24 };
  function readFilters() {
    const p = new URLSearchParams(location.search);
    filter = { q: p.get('q') || '', status: config.statuses[p.get('status')] ? p.get('status') : '', genres: p.getAll('genre'), tags: p.getAll('tag'), sort: ['read', 'added', 'title', 'author'].includes(p.get('sort')) ? p.get('sort') : 'read', limit: Math.max(24, Math.min(2400, Number(p.get('limit')) || 24)) };
    $('bkSearch').value = filter.q;
  }
  function urlState(item = null, push = false) {
    const p = new URLSearchParams();
    if (filter.q) p.set('q', filter.q);
    if (filter.status) p.set('status', filter.status);
    for (const g of filter.genres) p.append('genre', g);
    for (const t of filter.tags) p.append('tag', t);
    if (filter.sort !== 'read') p.set('sort', filter.sort);
    if (filter.limit > 24) p.set('limit', filter.limit);
    if (item) p.set('item', item);
    history[push ? 'pushState' : 'replaceState'](null, '', '/books' + (p.size ? '?' + p : ''));
  }
  function cover(book) {
    const c = node('div', undefined, 'bk-cover');
    const placeholder = () => { c.replaceChildren(node('div', book.title || 'Книга', 'bk-cover-placeholder')); };
    if (book.coverUrl) { const img = node('img'); img.src = book.coverUrl; img.alt = ''; img.loading = 'lazy'; img.onerror = placeholder; c.append(img); }
    else placeholder();
    return c;
  }
  function badge(book) { const n = node('span', config.statuses[book.status], 'bk-badge'); n.dataset.status = book.status; return n; }
  function matches(b, except = '') {
    return (!filter.q || lower(b.title + ' ' + b.description).includes(lower(filter.q))) &&
      (except === 'status' || !filter.status || b.status === filter.status) &&
      ['genres', 'tags'].every(key => except === key || !filter[key].length || filter[key].some(t => b[key].some(v => lower(t) === lower(v))));
  }
  function sorted(list) {
    const ranks = { read: 0, reading: 1, planned: 2, stopped: 3 };
    return list.sort((a, b) => {
      let result = 0;
      if (filter.sort === 'title') result = cmp(a.title, b.title);
      else if (filter.sort === 'author') result = cmp(a.description, b.description) || cmp(a.title, b.title);
      else if (filter.sort === 'read') result = ranks[a.status] - ranks[b.status] || (a.status === 'read' ? cmp(b.finished, a.finished) : 0);
      return result || cmp(b.created, a.created) || cmp(a.id, b.id);
    });
  }
  function changedFilters() { filter.limit = 24; urlState(); render(); }
  function render() {
    const published = items.filter(b => !b.draft);
    $('bkSort').querySelectorAll('[data-sort]').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.sort === filter.sort)));
    const statusBox = $('bkStatuses'); statusBox.replaceChildren();
    for (const [key, label] of [['', 'Все'], ['read', 'Прочитано'], ['reading', 'Читаю'], ['planned', 'В планах'], ['stopped', 'Не дочитал']]) {
      const b = button(label, () => { filter.status = key; changedFilters(); });
      b.append(node('span', String(published.filter(it => matches(it, 'status') && (!key || it.status === key)).length)));
      b.setAttribute('aria-pressed', String(filter.status === key)); statusBox.append(b);
    }
    for (const key of ['genres', 'tags']) {
      const box = $(key === 'genres' ? 'bkGenres' : 'bkTags'); box.replaceChildren();
      const values = unique([...published.flatMap(b => b[key]), ...filter[key]]).sort(cmp);
      for (const value of values) {
        const active = filter[key].some(t => lower(t) === lower(value));
        const b = button(value, () => { filter[key] = active ? filter[key].filter(t => lower(t) !== lower(value)) : [...filter[key], value]; changedFilters(); });
        const count = published.filter(it => matches(it, key) && it[key].some(t => lower(t) === lower(value))).length;
        b.append(node('span', String(count))); b.setAttribute('aria-pressed', String(active)); box.append(b);
      }
      if (!values.length) box.append(node('span', key === 'genres' ? 'Жанры появятся здесь после добавления книг.' : 'Метки можно добавить при редактировании книги.', 'bk-filter-hint'));
    }
    const applied = $('bkApplied'); applied.replaceChildren();
    if (filter.q) applied.append(button('Поиск: ' + filter.q + ' ×', () => { filter.q = ''; $('bkSearch').value = ''; changedFilters(); }));
    if (filter.status) applied.append(button(config.statuses[filter.status] + ' ×', () => { filter.status = ''; changedFilters(); }));
    for (const key of ['genres', 'tags']) for (const value of filter[key]) applied.append(button(value + ' ×', () => { filter[key] = filter[key].filter(t => t !== value); changedFilters(); }));
    $('bkReset').hidden = !filter.q && !filter.status && !filter.genres.length && !filter.tags.length;
    const found = sorted(published.filter(b => matches(b)));
    $('bkCount').replaceChildren(node('span', 'Найдено: ' + found.length, 'bk-total'), node('span', 'В библиотеке: ' + published.length, 'bk-total'));
    const grid = $('bkGrid'); grid.replaceChildren();
    for (const book of found.slice(0, filter.limit)) {
      const card = button('', () => openView(book.id, true), 'bk-card'); card.dataset.book = book.id;
      card.append(cover(book));
      const body = node('div', undefined, 'bk-card-body'); body.append(node('h3', book.title), node('div', book.description, 'bk-author'));
      const meta = node('div', undefined, 'bk-chips bk-card-meta'); meta.append(badge(book));
      for (const genre of book.genres) meta.append(node('span', genre, 'bk-badge'));
      for (const tag of book.tags) meta.append(node('span', tag, 'bk-badge bk-personal-tag'));
      body.append(meta);
      if (book.details) body.append(node('p', book.details, 'bk-note'));
      if (book.status === 'read' && book.finished) body.append(node('div', dateText(book.finished), 'bk-card-date'));
      card.append(body); grid.append(card);
    }
    $('bkMore').hidden = found.length <= filter.limit;
    $('bkEmpty').hidden = found.length > 0;
    $('bkEmpty').replaceChildren(node('strong', published.length ? 'Ничего не нашлось' : 'Здесь будет твоя библиотека'), node('span', published.length ? 'Попробуй другой запрос или сбрось фильтры.' : config.editor ? 'Добавь первую книгу. Незаконченную запись можно оставить в черновиках.' : 'Книги пока не добавлены.'));
    if (config.editor) renderDrafts();
  }
  function openView(id, push = false) {
    const book = items.find(b => b.id === id && !b.draft); if (!book) return;
    viewing = book;
    const content = $('bkViewContent'); content.replaceChildren();
    const top = node('div', undefined, 'bk-view-top'), info = node('div');
    info.append(node('h2', book.title), node('div', book.description, 'bk-author'), badge(book));
    if (book.status === 'read') info.append(node('div', book.finished ? 'Прочитано ' + dateText(book.finished) : 'Дата прочтения не указана', 'bk-hint'));
    top.append(cover(book), info); content.append(top);
    const tags = node('div', undefined, 'bk-chips');
    for (const key of ['genres', 'tags']) for (const value of book[key]) tags.append(button(value, () => { closeView(); filter[key] = [value]; changedFilters(); }));
    content.append(tags);
    if (book.details) content.append(node('p', book.details, 'bk-view-note'));
    if (!$('bkView').open) $('bkView').showModal();
    if (push) urlState(id, true);
  }
  function closeView() { viewing = null; urlState(); $('bkView').close(); }
  document.querySelectorAll('[data-close]').forEach(b => b.onclick = () => b.dataset.close === 'bkView' ? closeView() : $(b.dataset.close).close());
  $('bkView').addEventListener('cancel', e => { e.preventDefault(); closeView(); });
  window.addEventListener('popstate', () => {
    const id = new URLSearchParams(location.search).get('item');
    readFilters(); render();
    if (id) openView(id); else if ($('bkView').open) closeView();
  });
  $('bkSearch').oninput = () => { filter.q = $('bkSearch').value; changedFilters(); };
  $('bkSort').onclick = e => { const choice = e.target.closest('[data-sort]'); if (!choice) return; filter.sort = choice.dataset.sort; changedFilters(); };
  $('bkReset').onclick = () => { filter.q = ''; filter.status = ''; filter.genres = []; filter.tags = []; $('bkSearch').value = ''; changedFilters(); };
  $('bkMore').onclick = () => { filter.limit += 24; urlState(); render(); };

  // A local copy is written immediately; only completed requests clear it.
  const prefix = 'xelopat:books:v1:' + config.user + ':';
  let tabId;
  try { tabId = sessionStorage.getItem(prefix + 'tab') || crypto.randomUUID(); sessionStorage.setItem(prefix + 'tab', tabId); } catch (_) { tabId = String(Date.now()); }
  let edit = null, editKey = '', dirty = false, serial = 0, timer = null, inFlight = null, conflict = false, localSafe = true, coverBusy = false, deleting = false, closing = false, publishing = false;
  const newId = () => crypto.randomUUID().replaceAll('-', '');
  const backupKey = id => prefix + tabId + ':' + id;
  function backups() {
    const rows = [];
    try {
      for (let i = 0; i < localStorage.length; i++) {
        const key = localStorage.key(i); if (!key.startsWith(prefix)) continue;
        try { const value = JSON.parse(localStorage.getItem(key)); if (value?.book?.id) rows.push({ key, ...value }); } catch (_) {}
      }
    } catch (_) {}
    return rows.sort((a, b) => b.time - a.time);
  }
  function persist() {
    if (!edit) return true;
    try { localStorage.setItem(editKey, JSON.stringify({ book: edit, pending: pendingTags(), time: Date.now() })); localSafe = true; }
    catch (_) { localSafe = false; }
    return localSafe;
  }
  function removeBackup(key) { try { localStorage.removeItem(key); } catch (_) {} }
  function renderDrafts() {
    const local = backups(), server = items.filter(b => b.draft);
    const localDraftIds = new Set(local.filter(r => r.book.draft).map(r => r.book.id));
    $('bkDraftCount').textContent = new Set([...server.map(b => b.id), ...localDraftIds]).size;
    const recovery = $('bkRecovery'); recovery.replaceChildren(); recovery.hidden = !local.length;
    if (local.length) {
      recovery.append(node('span', 'В этом браузере есть несохранённые изменения: ' + local.length + '.'));
      recovery.append(button('Продолжить', () => showDrafts()));
    }
    const list = $('bkDraftList'); list.replaceChildren();
    for (const row of local) {
      const b = button('', () => { $('bkDraftDialog').close(); openEditor(row.book, row); }, 'bk-draft-row');
      const title = node('span', row.book.title || 'Без названия'); title.append(node('small', 'Копия в этом браузере · ' + new Date(row.time).toLocaleString('ru-RU')));
      b.append(title, node('span', 'Продолжить →')); list.append(b);
    }
    for (const book of server.sort((a, b) => cmp(b.updated, a.updated))) {
      if (localDraftIds.has(book.id)) continue;
      const b = button('', () => { $('bkDraftDialog').close(); openEditor(book); }, 'bk-draft-row');
      const title = node('span', book.title || 'Без названия'); title.append(node('small', book.description || 'Автор пока не указан'));
      b.append(title, node('span', 'Продолжить →')); list.append(b);
    }
    if (!list.children.length) list.append(node('p', 'Черновиков пока нет. Начни добавлять книгу — она сохранится здесь.', 'bk-hint'));
  }
  function showDrafts() { renderDrafts(); $('bkDraftDialog').showModal(); }

  readFilters(); render();
  const start = new URLSearchParams(location.search);
  if (start.get('item')) openView(start.get('item'));
  if (!config.editor) return;
  const form = $('bkForm'), dialog = $('bkEditor');
  const fields = ['title', 'description', 'status', 'finished', 'details'];
  function pendingTags() {
    return Object.fromEntries([...form.querySelectorAll('.bk-tag-field')].map(box => [box.dataset.field, box.querySelector('input').value]));
  }
  function status(text, error = false) { $('bkSaveStatus').textContent = text; $('bkSaveStatus').dataset.error = String(error); }
  function edited() {
    if (!edit) return;
    for (const name of fields) edit[name] = form.elements[name].value;
    dirty = true; serial++; $('bkCopy').hidden = !conflict; $('bkRetry').hidden = true;
    const ok = persist(); status(ok ? 'Изменения сохранены в браузере. Сохраняю на сервер…' : 'Копия в браузере недоступна. Не закрывай страницу до сохранения.', !ok);
    if (conflict) status('Книга изменилась в другой вкладке. Твои правки можно сохранить отдельным черновиком.', true);
    clearTimeout(timer); if (!conflict) timer = setTimeout(() => save(), 1500);
  }
  function tagValues(key) {
    const defaults = key === 'genres' ? ['Фантастика', 'Фэнтези', 'Детектив', 'Роман', 'История', 'Биография', 'Научпоп', 'Психология', 'Программирование'] : [];
    return unique([...items.flatMap(b => b[key]), ...defaults]).sort(cmp);
  }
  function renderTagField(box) {
    const key = box.dataset.field, chips = box.querySelector('.bk-edit-chips'), input = box.querySelector('input'), suggestions = box.querySelector('.bk-suggestions');
    chips.replaceChildren();
    for (const value of edit[key]) chips.append(button(value + ' ×', () => { edit[key] = edit[key].filter(v => v !== value); renderTagField(box); edited(); }));
    suggestions.replaceChildren();
    const values = tagValues(key).filter(v => !edit[key].some(t => lower(t) === lower(v)) && (!input.value || lower(v).includes(lower(input.value)))).slice(0, 8);
    for (const value of values) {
      const b = button(value, () => { input.value = value; commitTag(box); input.focus(); }); b.setAttribute('role', 'option'); suggestions.append(b);
    }
    suggestions.hidden = document.activeElement !== input || !input.value.trim() || !values.length;
    input.setAttribute('aria-expanded', String(!suggestions.hidden));
  }
  function commitTag(box) {
    const input = box.querySelector('input'), key = box.dataset.field;
    const values = input.value.split(/[,;\n]+/).map(s => s.trim()).filter(Boolean);
    if (!values.length) return;
    const known = tagValues(key);
    edit[key] = unique([...edit[key], ...values.map(v => known.find(t => lower(t) === lower(v)) || v.slice(0, 40))]).slice(0, 20);
    input.value = ''; renderTagField(box); edited();
  }
  form.querySelectorAll('.bk-tag-field').forEach(box => {
    const input = box.querySelector('input');
    box.querySelector('.bk-tag-add').onclick = () => commitTag(box);
    input.addEventListener('input', () => { renderTagField(box); edited(); });
    input.addEventListener('focus', () => renderTagField(box));
    input.addEventListener('blur', e => { if (!box.contains(e.relatedTarget)) { box.querySelector('.bk-suggestions').hidden = true; input.setAttribute('aria-expanded', 'false'); } });
    input.addEventListener('keydown', e => {
      if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); commitTag(box); }
      if (e.key === 'ArrowDown') { e.preventDefault(); box.querySelector('.bk-suggestions button')?.focus(); }
      if (e.key === 'Escape' && !box.querySelector('.bk-suggestions').hidden) { e.stopPropagation(); e.preventDefault(); box.querySelector('.bk-suggestions').hidden = true; input.setAttribute('aria-expanded', 'false'); }
    });
  });
  function previewCover() {
    const src = edit.coverData !== undefined ? edit.coverData : edit.coverUrl;
    $('bkCoverPreview').hidden = !src; $('bkCoverLabel').hidden = !!src; $('bkRemoveCover').hidden = !src;
    if (src) $('bkCoverPreview').src = src; else $('bkCoverPreview').removeAttribute('src');
  }
  function editorLabels() {
    $('bkEditorTitle').textContent = edit.draft ? 'Черновик книги' : 'Изменить книгу';
    $('bkDraftHint').hidden = !edit.draft; $('bkPublish').hidden = !edit.draft;
    $('bkDelete').textContent = edit.draft ? 'Удалить черновик' : 'Удалить книгу';
    $('bkDateLabel').hidden = !['read', 'stopped'].includes(edit.status);
  }
  function openEditor(book = null, recovery = null) {
    edit = structuredClone(book || { id: newId(), revision: 0, draft: true, title: '', description: '', status: 'planned', finished: '', details: '', genres: [], tags: [], coverUrl: '' });
    editKey = recovery?.key || backupKey(edit.id); dirty = !!recovery; serial = 0; conflict = false; closing = false; localSafe = true;
    form.reset(); for (const key of fields) form.elements[key].value = edit[key];
    form.querySelectorAll('.bk-tag-field').forEach(box => { box.querySelector('input').value = recovery?.pending?.[box.dataset.field] || ''; renderTagField(box); });
    $('bkAuthors').replaceChildren(...unique(items.map(b => b.description).filter(Boolean)).sort(cmp).map(a => { const o = node('option'); o.value = a; return o; }));
    $('bkExtra').open = !!edit.details || !!edit.tags.length;
    $('bkRetry').hidden = !recovery; $('bkCopy').hidden = true; editorLabels(); previewCover();
    status(recovery ? 'Восстановлена копия из браузера. Проверь её и продолжай редактировать.' : book ? 'Сохранено на сервере' : 'Черновик сохранится автоматически, как только начнёшь вводить.');
    dialog.showModal(); form.elements.title.focus();
  }
  form.addEventListener('input', e => {
    if (!fields.includes(e.target.name)) return;
    if (e.target.name === 'status') {
      if (e.target.value === 'read' && edit.status !== 'read' && !form.elements.finished.value) {
        const now = new Date(); form.elements.finished.value = [now.getFullYear(), String(now.getMonth() + 1).padStart(2, '0'), String(now.getDate()).padStart(2, '0')].join('-');
      }
    }
    edited(); editorLabels();
  });
  async function api(action, data) {
    let response;
    try { response = await fetch('/books?api=' + action, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': config.csrf }, body: JSON.stringify(data), signal: AbortSignal.timeout(20000) }); }
    catch (_) { throw new Error('Нет связи с сервером. Попробуй сохранить ещё раз.'); }
    const result = await response.json().catch(() => ({}));
    if (!response.ok) { const error = new Error(result.error || 'Не получилось сохранить.'); error.code = response.status; throw error; }
    return result;
  }
  async function save(publish = false) {
    clearTimeout(timer);
    while (inFlight) { await inFlight; if (conflict) return false; }
    if (conflict || deleting) return false;
    if (!edit || coverBusy || (!dirty && !publish)) return !dirty;
    const active = edit, tick = serial, sent = structuredClone(edit), key = editKey;
    const pending = pendingTags();
    for (const k of ['genres', 'tags']) sent[k] = unique([...sent[k], ...pending[k].split(/[,;\n]+/).map(s => s.trim()).filter(Boolean)]);
    sent.publish = publish;
    status('Сохраняю…'); $('bkRetry').hidden = true;
    inFlight = (async () => {
      try {
        const result = await api('save', sent), saved = result.item;
        items = items.filter(b => b.id !== saved.id); items.push(saved);
        active.revision = saved.revision; active.draft = saved.draft; active.coverUrl = saved.coverUrl; active.created = saved.created; active.updated = saved.updated;
        if (active.coverData === sent.coverData) delete active.coverData;
        dirty = tick !== serial;
        if (!dirty) { removeBackup(key); status(saved.draft ? 'Черновик сохранён на сервере' : 'Сохранено на сервере'); }
        else { persist(); status('Сохраняю последние изменения…'); }
        conflict = false; editorLabels(); render(); return true;
      } catch (error) {
        conflict = error.code === 409; $('bkCopy').hidden = !conflict; $('bkRetry').hidden = conflict;
        const cached = persist(); status(error.message + (cached ? ' Копия есть в этом браузере.' : ' Не закрывай страницу: копию в браузере сохранить не удалось.'), true);
        renderDrafts(); return false;
      }
    })();
    const ok = await inFlight; inFlight = null;
    if (ok && dirty) return save();
    return ok;
  }
  async function closeEditor() {
    if (closing || deleting || publishing) return;
    if (coverBusy) { status('Подожди, обложка ещё готовится.'); return; }
    closing = true;
    form.querySelectorAll('.bk-tag-field').forEach(commitTag);
    const ok = await save();
    if (!ok && !localSafe) { closing = false; return; }
    clearTimeout(timer); dialog.close(); edit = null; render();
  }
  $('bkAdd').onclick = () => openEditor(); $('bkDrafts').onclick = showDrafts;
  $('bkEdit').onclick = () => { const book = viewing; closeView(); openEditor(book); };
  $('bkCloseEditor').onclick = closeEditor; $('bkDone').onclick = closeEditor;
  dialog.addEventListener('cancel', e => { e.preventDefault(); closeEditor(); });
  $('bkRetry').onclick = () => save();
  $('bkCopy').onclick = async () => {
    if (inFlight) await inFlight;
    const oldKey = editKey;
    edit.copyFrom = edit.id; edit.id = newId(); edit.revision = 0; edit.draft = true; editKey = backupKey(edit.id);
    conflict = false; dirty = true; serial++; persist(); editorLabels();
    if (await save()) { removeBackup(oldKey); $('bkCopy').hidden = true; renderDrafts(); }
  };
  form.onsubmit = async e => {
    e.preventDefault();
    form.querySelectorAll('.bk-tag-field').forEach(commitTag);
    if (!edit.title.trim() || !edit.description.trim()) { status('Укажи название и автора. Остальное можно заполнить позже.', true); (!edit.title.trim() ? form.elements.title : form.elements.description).focus(); return; }
    if (coverBusy) { status('Подожди, обложка ещё готовится.'); return; }
    const duplicate = items.find(b => !b.draft && b.id !== edit.id && lower(b.title) === lower(edit.title) && lower(b.description) === lower(edit.description));
    if (duplicate && !confirm('Такая книга уже есть в библиотеке. Добавить ещё одну запись?')) return;
    publishing = true;
    const controls = [...form.querySelectorAll('button,input,select,textarea')]; controls.forEach(c => c.disabled = true);
    const ok = await save(true); controls.forEach(c => c.disabled = false); publishing = false;
    if (ok) { const id = edit.id; dialog.close(); edit = null; render(); openView(id, true); }
  };
  $('bkDelete').onclick = async () => {
    if (coverBusy) { status('Подожди, обложка ещё готовится.'); return; }
    if (!confirm(edit.draft ? 'Удалить этот черновик?' : 'Удалить книгу из библиотеки?')) return;
    deleting = true;
    const controls = [...form.querySelectorAll('button,input,select,textarea')]; controls.forEach(c => c.disabled = true);
    clearTimeout(timer); while (inFlight) await inFlight;
    try {
      if (edit.revision) await api('delete', { id: edit.id, revision: edit.revision });
      items = items.filter(b => b.id !== edit.id); removeBackup(editKey); dirty = false; dialog.close(); edit = null; render();
    } catch (error) { status(error.message, true); }
    finally { deleting = false; controls.forEach(c => c.disabled = false); }
  };
  async function loadCover(file) {
    if (!file || !edit || coverBusy) return;
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 25 * 1024 * 1024) { status('Выбери изображение JPG, PNG или WebP до 25 МБ.', true); return; }
    coverBusy = true; status('Готовлю обложку…');
    try {
      const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
      const scale = Math.min(1, 1400 / Math.max(bitmap.width, bitmap.height));
      const canvas = document.createElement('canvas'); canvas.width = Math.round(bitmap.width * scale); canvas.height = Math.round(bitmap.height * scale);
      const ctx = canvas.getContext('2d'); ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, canvas.width, canvas.height); ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height); bitmap.close();
      edit.coverData = canvas.toDataURL('image/jpeg', .85); previewCover(); edited();
    } catch (_) { status('Не удалось открыть изображение. Попробуй другую обложку.', true); }
    finally { coverBusy = false; }
  }
  $('bkPickCover').onclick = () => $('bkCoverFile').click();
  $('bkCoverFile').onchange = () => { loadCover($('bkCoverFile').files[0]); $('bkCoverFile').value = ''; };
  $('bkRemoveCover').onclick = () => { edit.coverData = ''; previewCover(); edited(); };
  dialog.addEventListener('dragover', e => { if (e.dataTransfer.types.includes('Files')) e.preventDefault(); });
  dialog.addEventListener('drop', e => { e.preventDefault(); loadCover(e.dataTransfer.files[0]); });
  dialog.addEventListener('paste', e => { const f = [...e.clipboardData.files].find(f => f.type.startsWith('image/')); if (f) { e.preventDefault(); loadCover(f); } });
  window.addEventListener('beforeunload', e => { if ((dirty && !localSafe) || coverBusy) { e.preventDefault(); e.returnValue = ''; } });
  document.addEventListener('visibilitychange', () => { if (document.hidden && edit && dirty) { persist(); if (!conflict) save(); } });
  window.addEventListener('online', () => { if (edit && dirty && !conflict) save(); });
  if (start.has('add')) { urlState(); openEditor(); }
})();
