/* Private drafts and serialized autosaves for the existing perfume form. */
'use strict';
window.initPerfumeEditor = ({ openModal, closeModal, formModal, viewModal }) => {
  const $ = id => document.getElementById(id);
  const config = window.PERFUMES_EDITOR_CONFIG;
  const form = $('perfForm');
  const fields = ['name', 'brand', 'rating', 'price', 'tags', 'description', 'notes', 'notes_top', 'notes_middle', 'notes_base'];
  const records = new Map([...Object.values(window.PERFUMES_DATA), ...config.drafts].map(it => [String(it.id), it]));
  const prefix = 'xelopat:perfumes:v1:' + config.user + ':';
  let tab;
  try { tab = sessionStorage.getItem(prefix + 'tab') || crypto.randomUUID(); sessionStorage.setItem(prefix + 'tab', tab); } catch (_) { tab = crypto.randomUUID(); }
  const keyFor = id => prefix + tab + ':' + id;
  let edit = null, key = '', dirty = false, serial = 0, timer, saving = null, conflict = false, localSafe = true, photoBusy = false, closing = false, busy = false, changedPublic = false, previousFocus = null;
  const text = (tag, value, cls) => { const el = document.createElement(tag); el.textContent = value; if (cls) el.className = cls; return el; };
  const button = (label, fn, cls = 'btn') => { const b = text('button', label, cls); b.type = 'button'; b.onclick = fn; return b; };
  const status = (message, error = false) => { $('perfSaveStatus').textContent = message; $('perfSaveStatus').dataset.error = String(error); };
  function cached() {
    const out = [];
    try {
      for (let i = 0; i < localStorage.length; i++) {
        const k = localStorage.key(i); if (!k.startsWith(prefix)) continue;
        try { const row = JSON.parse(localStorage.getItem(k)); if (row?.item?.id) out.push({ key: k, ...row }); } catch (_) {}
      }
    } catch (_) {}
    return out.sort((a, b) => b.time - a.time);
  }
  function persist() {
    try { localStorage.setItem(key, JSON.stringify({ item: edit, time: Date.now() })); localSafe = true; } catch (_) { localSafe = false; }
    return localSafe;
  }
  function removeCache(k) { try { localStorage.removeItem(k); } catch (_) {} }
  function renderDrafts() {
    const local = cached(), drafts = [...records.values()].filter(it => it.draft);
    const localIds = new Set(local.map(r => String(r.item.id)));
    $('perfDraftCount').textContent = new Set([...drafts.map(it => String(it.id)), ...local.filter(r => r.item.draft).map(r => String(r.item.id))]).size;
    const notice = $('perfRecovery'); notice.replaceChildren(); notice.hidden = !local.length;
    if (local.length) notice.append(text('span', 'В этом браузере есть несохранённые изменения: ' + local.length + '.'), button('Продолжить', showDrafts));
    const list = $('perfDraftList'); list.replaceChildren();
    const rowButton = (item, label, recovery) => {
      const b = button('', () => { $('perfDraftDialog').close(); open(item, recovery); }, 'btn perf-draft-row');
      b.append(text('strong', item.name || 'Без названия'), text('span', label, 'muted')); list.append(b);
    };
    local.forEach(r => rowButton(r.item, 'Копия в этом браузере · ' + new Date(r.time).toLocaleString('ru-RU'), r));
    drafts.sort((a, b) => (b.updated_at || '').localeCompare(a.updated_at || '')).forEach(it => { if (!localIds.has(String(it.id))) rowButton(it, it.brand || 'Бренд пока не указан'); });
    if (!list.children.length) list.append(text('p', 'Начни добавлять аромат — незаконченная запись сохранится здесь.', 'muted'));
  }
  function showDrafts() { renderDrafts(); $('perfDraftDialog').showModal(); }
  function preview() {
    const src = edit.photoData !== undefined ? edit.photoData : edit.photo_url;
    $('photoPreview').style.display = src ? 'block' : 'none';
    if (src) $('photoPreview').src = src; else $('photoPreview').removeAttribute('src');
    $('fRemoveLine').style.display = src || edit.photoData === '' ? 'flex' : 'none';
    $('fRemovePhoto').checked = edit.photoData === '';
  }
  function labels() {
    $('fTitle').textContent = edit.draft ? 'Черновик аромата' : 'Редактирование';
    $('perfDraftHint').hidden = !edit.draft;
    $('perfPublish').hidden = !edit.draft;
    $('fIdPill').style.display = edit.draft ? 'none' : 'inline-flex'; $('fIdPill').textContent = edit.id;
    $('deleteForm').style.display = 'block'; $('deleteForm').querySelector('button').textContent = edit.draft ? 'Удалить черновик' : 'Удалить аромат';
  }
  function open(item = null, recovery = null) {
    previousFocus = document.activeElement;
    edit = structuredClone(item || { id: crypto.randomUUID().replaceAll('-', ''), revision: 0, draft: true, photo_url: '' });
    edit.id = String(edit.id);
    if (Array.isArray(edit.tags)) edit.tags = edit.tags.join(', ');
    for (const field of fields) edit[field] = edit[field] == null ? '' : String(edit[field]);
    key = recovery?.key || keyFor(edit.id); dirty = !!recovery; serial = 0; conflict = false; closing = false; localSafe = true;
    form.reset(); for (const field of fields) form.elements[field].value = edit[field];
    $('fIdHidden').value = edit.id; $('dId').value = edit.id;
    $('perfRetry').hidden = !recovery; $('perfCopy').hidden = true;
    preview(); labels();
    status(recovery ? 'Восстановлена копия из браузера. Проверь её и продолжай редактировать.' : item ? 'Сохранено на сервере' : 'Черновик сохранится автоматически, когда начнёшь вводить.');
    openModal(formModal); $('fName').focus();
  }
  function edited() {
    if (!edit) return;
    for (const field of fields) edit[field] = form.elements[field].value;
    dirty = true; serial++; const ok = persist();
    $('perfRetry').hidden = true; $('perfCopy').hidden = !conflict;
    status(conflict ? 'Запись изменилась в другой вкладке. Можно сохранить свои правки отдельным черновиком.' : ok ? 'Копия сохранена в браузере. Сохраняю на сервер…' : 'Не закрывай страницу до сохранения: копия в браузере недоступна.', conflict || !ok);
    clearTimeout(timer); if (!conflict) timer = setTimeout(() => save(), 1500);
  }
  async function api(data) {
    let response;
    try { response = await fetch('/perfumes/api', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': config.csrf }, body: JSON.stringify(data), signal: AbortSignal.timeout(20000) }); }
    catch (_) { throw new Error('Нет связи с сервером. Попробуй сохранить ещё раз.'); }
    const result = await response.json().catch(() => ({}));
    if (!response.ok) { const error = new Error(result.error || 'Не удалось сохранить запись.'); error.code = response.status; throw error; }
    return result;
  }
  async function save(publish = false) {
    clearTimeout(timer);
    while (saving) { await saving; if (conflict) return false; }
    if (conflict || !edit || photoBusy) return false;
    if (!dirty && !publish) return true;
    if (!form.checkValidity()) { status('Проверь оценку и цену. Копия остаётся в браузере.', true); $('perfRetry').hidden = false; return false; }
    const sent = structuredClone(edit), tick = serial, active = edit, sentKey = key;
    status('Сохраняю…'); $('perfRetry').hidden = true;
    saving = (async () => {
      try {
        const { item } = await api({ ...sent, action: 'save', publish });
        records.set(String(item.id), item);
        if (!item.draft) { changedPublic = true; window.PERFUMES_DATA[item.id] = item; }
        active.revision = item.revision; active.draft = item.draft; active.photo_url = item.photo_url;
        if (active.photoData === sent.photoData) delete active.photoData;
        dirty = tick !== serial;
        if (dirty) persist(); else removeCache(sentKey);
        status(dirty ? 'Сохраняю последние изменения…' : item.draft ? 'Черновик сохранён на сервере' : 'Сохранено на сервере');
        labels(); renderDrafts(); return true;
      } catch (e) {
        conflict = e.code === 409; $('perfCopy').hidden = !conflict; $('perfRetry').hidden = conflict;
        const ok = persist(); status(e.message + (ok ? ' Копия есть в этом браузере.' : ' Не закрывай страницу: копию в браузере сохранить не удалось.'), true);
        renderDrafts(); return false;
      }
    })();
    const ok = await saving; saving = null;
    if (ok && dirty && !busy) return save();
    return ok;
  }
  async function close() {
    if (closing || busy) return;
    if (photoBusy) { status('Подожди, фото ещё готовится.'); return; }
    closing = true;
    const ok = await save();
    if (!ok && !localSafe) { closing = false; return; }
    clearTimeout(timer); closeModal(formModal, true); edit = null; renderDrafts(); previousFocus?.focus();
    if (changedPublic) location.reload();
  }
  form.addEventListener('input', e => { if (fields.includes(e.target.name)) edited(); });
  $('openAddBtn').onclick = () => open(); $('openDraftsBtn').onclick = showDrafts;
  $('perfCloseDrafts').onclick = () => $('perfDraftDialog').close();
  document.querySelectorAll('.js-edit').forEach(b => b.onclick = e => { e.preventDefault(); e.stopPropagation(); open(records.get(String(b.dataset.id))); });
  $('vEditBtn').onclick = () => { const id = $('vIdPill').textContent; closeModal(viewModal, true); open(records.get(id)); };
  $('perfRetry').onclick = () => save();
  $('perfCopy').onclick = async () => {
    while (saving) await saving;
    const oldKey = key; edit.copyFrom = edit.id; edit.id = crypto.randomUUID().replaceAll('-', ''); edit.revision = 0; edit.draft = true;
    key = keyFor(edit.id); conflict = false; dirty = true; serial++; persist(); labels();
    if (await save()) { removeCache(oldKey); $('perfCopy').hidden = true; renderDrafts(); }
  };
  function disable(value) { formModal.querySelectorAll('button,input,textarea').forEach(el => el.disabled = value); }
  form.addEventListener('submit', async e => {
    e.preventDefault();
    if (busy || photoBusy) return;
    if (edit.draft && !edit.name.trim()) { status('Укажи название аромата. Остальные поля можно заполнить позже.', true); $('fName').focus(); return; }
    // Freeze the form while flushing, then publish the latest acknowledged revision.
    busy = true; disable(true);
    if (!(await save())) { disable(false); busy = false; return; }
    const ok = await save(edit.draft); disable(false); busy = false;
    if (ok) { closeModal(formModal, true); edit = null; location.reload(); }
  });
  $('deleteForm').addEventListener('submit', async e => {
    e.preventDefault();
    if (busy || photoBusy || !confirm(edit.draft ? 'Удалить этот черновик?' : 'Удалить аромат из коллекции?')) return;
    busy = true; disable(true); clearTimeout(timer); while (saving) await saving;
    try {
      if (edit.revision || records.has(edit.id)) await api({ action: 'delete', id: edit.id, revision: edit.revision });
      const published = !edit.draft;
      records.delete(edit.id); removeCache(key); dirty = false; closeModal(formModal, true); edit = null; renderDrafts();
      if (published || changedPublic) location.reload();
    } catch (error) { status(error.message, true); }
    finally { disable(false); busy = false; }
  });
  async function photo(file) {
    if (!edit || !file || photoBusy || busy) return;
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 25 * 1024 * 1024) { status('Выбери JPG, PNG или WebP до 25 МБ.', true); return; }
    photoBusy = true; status('Готовлю фото…');
    try {
      const img = await createImageBitmap(file, { imageOrientation: 'from-image' });
      const scale = Math.min(1, 1400 / Math.max(img.width, img.height));
      const c = document.createElement('canvas'); c.width = Math.round(img.width * scale); c.height = Math.round(img.height * scale);
      const ctx = c.getContext('2d'); ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height); ctx.drawImage(img, 0, 0, c.width, c.height); img.close();
      edit.photoData = c.toDataURL('image/jpeg', .85); preview(); edited();
    } catch (_) { status('Не удалось открыть фото. Попробуй другой файл.', true); }
    finally { photoBusy = false; }
  }
  $('fPhoto').onchange = () => { photo($('fPhoto').files[0]); $('fPhoto').value = ''; };
  $('fRemovePhoto').onchange = () => { if ($('fRemovePhoto').checked) edit.photoData = ''; else delete edit.photoData; preview(); edited(); };
  $('pasteBox').onclick = () => $('fPhoto').click();
  $('pasteBox').onkeydown = e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); $('fPhoto').click(); } };
  formModal.addEventListener('paste', e => { const file = [...e.clipboardData.files].find(f => f.type.startsWith('image/')); if (file) { e.preventDefault(); photo(file); } });
  formModal.addEventListener('dragover', e => { if (e.dataTransfer.types.includes('Files')) e.preventDefault(); });
  formModal.addEventListener('drop', e => { e.preventDefault(); photo(e.dataTransfer.files[0]); });
  formModal.addEventListener('keydown', e => {
    if (e.key !== 'Tab') return;
    const controls = [...formModal.querySelectorAll('button,input,textarea,[tabindex="0"]')].filter(el => !el.disabled && el.getClientRects().length);
    const first = controls[0], last = controls.at(-1);
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  });
  window.addEventListener('beforeunload', e => { if (photoBusy || (dirty && !localSafe)) { e.preventDefault(); e.returnValue = ''; } });
  document.addEventListener('visibilitychange', () => { if (document.hidden && edit && dirty) { persist(); if (!conflict && !busy) save(); } });
  window.addEventListener('online', () => { if (edit && dirty && !conflict && !busy) save(); });
  renderDrafts();
  if (new URLSearchParams(location.search).has('add')) { const u = new URL(location.href); u.searchParams.delete('add'); history.replaceState(null, '', u); open(); }
  return { close };
};
