// «Мои карты»: схемы в аккаунте xelopat.ru (до 30 карт по 5000 устройств), сервер в api.php.
// Адрес API и страницы входа приходят из data-api и data-login у #lab (их задаёт lab/index.php).
// Карта уходит на сервер сжатой (deflate-raw), если браузер это умеет.
(function () {
  let ui, h, pop, state = { user: undefined, maps: [], limits: { maps: 30, devices: 5000 }, busy: false, error: '' };

  const root = () => document.getElementById('lab');
  const API = () => root().dataset.api || 'api.php';
  const LOGIN = () => root().dataset.login || 'https://xelopat.ru/auth/login';

  async function api(params, body) {
    const url = API() + (params ? '?' + new URLSearchParams(params) : '');
    const res = await fetch(url, body ? {
      method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
    } : { credentials: 'include' });
    let data = null;
    try { data = await res.json(); } catch (e) { /* ответ не JSON */ }
    if (!res.ok) throw new Error((data && data.error) || 'сервер ответил ' + res.status);
    return data;
  }

  function toB64(buf) {
    const bytes = new Uint8Array(buf);
    let out = '';
    for (let i = 0; i < bytes.length; i += 0x8000) out += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
    return btoa(out);
  }
  async function pack(str) {
    if (!window.CompressionStream) return null;
    const stream = new Blob([str]).stream().pipeThrough(new CompressionStream('deflate-raw'));
    return toB64(await new Response(stream).arrayBuffer());
  }
  async function unpack(b64) {
    const bin = Uint8Array.from(atob(b64), (c) => c.charCodeAt(0));
    const stream = new Blob([bin]).stream().pipeThrough(new DecompressionStream('deflate-raw'));
    return new Response(stream).text();
  }

  async function refresh() {
    state.error = '';
    try {
      const d = await api({ action: 'list' });
      state.user = d.user;
      state.maps = d.maps || [];
      state.limits = d.limits || state.limits;
    } catch (e) {
      state.user = undefined;
      state.error = 'Не удалось связаться с сервером: ' + e.message;
    }
    render();
  }

  async function busy(fn, okText) {
    if (state.busy) return;
    state.busy = true;
    state.error = '';
    render();
    try {
      await fn();
      if (okText) ui.hint(okText, 'ok');
    } catch (e) {
      state.error = e.message;
    }
    state.busy = false;
    await refresh();
  }

  async function saveMap(id, name) {
    const S = ui.S;
    if (S.net.devices.length > state.limits.devices) throw new Error('в карте больше ' + state.limits.devices + ' устройств');
    const json = JSON.stringify(S.net);
    const gz = await pack(json);
    const body = { action: 'save', name, id: id || undefined };
    if (gz) body.gz = gz; else body.data = JSON.parse(json);
    const r = await api(null, body);
    S.map = { id: r.id, name: r.name };
    ui.save();
  }

  async function openMap(m) {
    const plain = !window.DecompressionStream;
    const d = await api({ action: 'get', id: m.id, plain: plain ? 1 : '' });
    const json = plain ? d.map.json : await unpack(d.map.gz);
    const net = LAB.Net.from(JSON.parse(json));
    ui.setNet(net, null, { id: d.map.id, name: d.map.name });
    close();
  }

  function fmtDate(sec) {
    const d = new Date(sec * 1000);
    const p = (n) => String(n).padStart(2, '0');
    return p(d.getDate()) + '.' + p(d.getMonth() + 1) + '.' + d.getFullYear() + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
  }

  function render() {
    if (!pop || pop.hidden) return;
    const S = ui.S;
    const head = h('div', { class: 'lab-maps__head' }, h('b', null, 'Мои карты'),
      state.user ? h('span', null, state.user.name + ', ' + state.maps.length + ' из ' + state.limits.maps) : null);
    pop.replaceChildren(head);

    if (state.user === undefined && !state.error) { pop.append(h('p', { class: 'lab-empty' }, 'Загрузка…')); return; }
    if (state.error) pop.append(h('p', { class: 'lab-maps__err' }, state.error));
    if (!state.user) {
      if (state.user === null) {
        pop.append(
          h('p', { class: 'lab-empty' }, 'Карты хранятся в аккаунте xelopat.ru. Войди, и здесь появится сохранение: до ' + state.limits.maps + ' карт по ' + state.limits.devices + ' устройств.'),
          h('div', { class: 'lab-maps__row' },
            h('a', { class: 'btn btn--primary', href: LOGIN(), target: '_blank', rel: 'noopener' }, 'Войти'),
            h('button', { type: 'button', class: 'lab-btn', onClick: refresh }, 'Я вошёл, обновить')));
      }
      return;
    }

    const full = state.maps.length >= state.limits.maps;
    if (S.map && state.maps.some((m) => m.id === S.map.id)) {
      pop.append(h('div', { class: 'lab-maps__cur' },
        h('span', null, 'Открыта: ', h('b', null, S.map.name)),
        h('button', { type: 'button', class: 'btn btn--primary', disabled: state.busy, onClick: () => busy(() => saveMap(S.map.id, S.map.name), 'Карта «' + S.map.name + '» сохранена') }, 'Сохранить')));
    }
    const name = h('input', { class: 'input', placeholder: 'Название новой карты', maxlength: 60, value: S.map ? '' : (S.preset ? 'Моя схема' : '') });
    const saveNew = () => {
      const v = name.value.trim();
      if (!v) { name.focus(); return; }
      busy(() => saveMap('', v), 'Карта «' + v + '» сохранена');
    };
    name.addEventListener('keydown', (e) => { if (e.key === 'Enter') saveNew(); });
    pop.append(h('div', { class: 'lab-maps__row' }, name,
      h('button', { type: 'button', class: 'lab-btn', disabled: state.busy || full, title: full ? 'Уже ' + state.limits.maps + ' карт: удали лишнюю или перезапиши' : '', onClick: saveNew }, 'Сохранить как новую')));
    if (full) pop.append(h('p', { class: 'lab-maps__err' }, 'Достигнут предел в ' + state.limits.maps + ' карт: удали ненужную или перезапиши существующую.'));

    if (!state.maps.length) { pop.append(h('p', { class: 'lab-empty' }, 'Сохранённых карт пока нет.')); return; }
    pop.append(h('div', { class: 'lab-maps__list' }, state.maps.map((m) => h('div', { class: 'lab-maps__item' + (S.map && S.map.id === m.id ? ' is-cur' : '') },
      h('button', { type: 'button', class: 'lab-maps__open', disabled: state.busy, title: 'Открыть', onClick: () => {
        if (S.net.devices.length && !(S.map && S.map.id === m.id) && !confirm('Открыть «' + m.name + '»? Несохранённые изменения на холсте пропадут.')) return;
        busy(() => openMap(m));
      } },
      h('b', null, m.name), h('small', null, m.devices + ' устр., ' + fmtDate(m.updated))),
      h('button', { type: 'button', class: 'lab-maps__act', disabled: state.busy, title: 'Перезаписать текущей схемой', onClick: () => {
        if (confirm('Перезаписать «' + m.name + '» тем, что сейчас на холсте?')) busy(() => saveMap(m.id, m.name), 'Карта «' + m.name + '» перезаписана');
      } }, '⤓'),
      h('button', { type: 'button', class: 'lab-maps__act', disabled: state.busy, title: 'Переименовать', onClick: () => {
        const v = prompt('Новое название', m.name);
        if (v && v.trim()) busy(async () => {
          await api(null, { action: 'rename', id: m.id, name: v.trim() });
          if (S.map && S.map.id === m.id) { S.map.name = v.trim().slice(0, 60); ui.save(); }
        });
      } }, '✎'),
      h('button', { type: 'button', class: 'lab-maps__act is-danger', disabled: state.busy, title: 'Удалить', onClick: () => {
        if (confirm('Удалить карту «' + m.name + '»? Это нельзя отменить.')) busy(async () => {
          await api(null, { action: 'delete', id: m.id });
          if (S.map && S.map.id === m.id) { S.map = null; ui.save(); }
        }, 'Карта удалена');
      } }, '✕')))));
  }

  function close() { if (pop) pop.hidden = true; }

  LAB.Maps = {
    toggle() {
      ui = LAB.ui;
      h = ui.h;
      if (!pop) {
        pop = h('div', { class: 'lab-pop lab-maps', hidden: true });
        ui.E.stage.append(pop);
      }
      const show = pop.hidden;
      ui.closeMenus();
      if (!show) return;
      pop.hidden = false;
      render();
      refresh();
    },
  };
})();
