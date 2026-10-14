// Вкладка «Атаки»: все атаки из data.js по уровням, с фильтром по тексту и уровням.
// Адрес: #attacks/2 оставляет только канальный уровень.
(function () {
  let api, h, list, search, counter;
  const chips = {};
  const enabled = new Set([7, 6, 5, 4, 3, 2, 1]);

  function render() {
    const q = search.value.trim().toLowerCase();
    let total = 0;
    list.replaceChildren();
    api.layers().forEach((l) => {
      if (!enabled.has(l.n)) return;
      const items = l.attacks.filter((a) => !q || (a.name + ' ' + a.note + ' ' + a.defense).toLowerCase().includes(q));
      if (!items.length) return;
      total += items.length;
      list.append(h('section', { class: 'atk-group', style: { '--lc': l.color } },
        h('button', { type: 'button', class: 'atk-group__head', onClick: () => api.go('layers', l.n), title: 'Открыть уровень' },
          h('span', { class: 'atk-group__num' }, 'L' + l.n), l.name, h('small', null, l.en)),
        h('div', { class: 'atk-cards' }, items.map((a) => h('article', { class: 'atk-card' },
          h('div', { class: 'osi-attack__name' }, a.name),
          h('div', { class: 'osi-attack__note' }, a.note),
          h('div', { class: 'osi-attack__def' }, h('span', null, 'защита: '), a.defense))))));
    });
    counter.textContent = total ? 'Найдено: ' + total : '';
    if (!total) list.append(h('div', { class: 'panel osi-results__empty' }, 'Ничего не нашлось'));
  }

  function setOnly(n) {
    enabled.clear();
    if (n) enabled.add(n);
    else [7, 6, 5, 4, 3, 2, 1].forEach((x) => enabled.add(x));
    Object.entries(chips).forEach(([k, c]) => c.classList.toggle('active', enabled.has(Number(k))));
  }

  OSI.register({
    id: 'attacks',
    title: 'Атаки',
    order: 40,

    mount(root, a) {
      api = a; h = a.h;
      search = h('input', { class: 'input', type: 'search', placeholder: 'Фильтр: спуфинг, DDoS, MitM, WPA3', 'aria-label': 'Фильтр атак', onInput: render });
      counter = h('span', { class: 'atk-counter' });
      const chipRow = h('div', { class: 'osi-chips' },
        h('button', { type: 'button', class: 'osi-chip osi-chip--btn', onClick: () => { setOnly(null); api.setParam(''); render(); } }, 'все'),
        api.layers().map((l) => (chips[l.n] = h('button', {
          type: 'button',
          class: 'osi-chip osi-chip--btn osi-chip--layer active',
          style: { '--lc': l.color },
          onClick: (e) => {
            // Обычный клик включает и выключает уровень, с Shift или Ctrl оставляет только его
            if (e.shiftKey || e.ctrlKey || e.metaKey) setOnly(l.n);
            else {
              if (enabled.has(l.n)) enabled.delete(l.n); else enabled.add(l.n);
              chips[l.n].classList.toggle('active', enabled.has(l.n));
            }
            api.setParam(enabled.size === 1 ? [...enabled][0] : '');
            render();
          },
        }, 'L' + l.n))));
      list = h('div', { class: 'atk-list' });
      root.append(
        h('div', { class: 'atk-toolbar panel' }, search, chipRow, counter),
        list);
    },

    show(param) {
      const n = Number(param);
      if (n >= 1 && n <= 7) setOnly(n);
      render();
    },
  });
})();
