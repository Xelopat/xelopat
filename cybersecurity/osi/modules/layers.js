// Вкладка «Уровни»: стек из 7 уровней, карточка выбранного уровня и поиск по всем данным.
// Адрес: #layers/3 открывает сетевой уровень.
(function () {
  let api, h, root, detail, search, results;
  const buttons = {};
  let current = 7;
  let hit = ''; // имя элемента, найденного поиском, подсвечивается в карточке

  const KINDS = [
    ['protocols', 'протокол'],
    ['devices', 'устройство'],
    ['attacks', 'атака'],
  ];

  function card(title, body, cls) {
    return h('div', { class: 'osi-card' + (cls ? ' ' + cls : '') }, h('div', { class: 'osi-card__title' }, title), body);
  }

  function isHit(name) { return hit !== '' && name === hit; }

  function renderDetail(l) {
    const tcp = api.data.tcpip.find((t) => t.key === l.tcpip);
    const prev = api.layer(l.n + 1);
    const next = api.layer(l.n - 1);

    detail.style.setProperty('--lc', l.color);
    detail.replaceChildren(
      h('div', { class: 'osi-detail__head' },
        h('div', { class: 'osi-detail__num' }, l.n),
        h('div', { class: 'osi-detail__info' },
          h('div', { class: 'osi-detail__en' }, 'Layer ' + l.n + ' / ' + l.en),
          h('h2', { class: 'osi-detail__title' }, l.name + ' уровень'),
          h('div', { class: 'osi-chips' },
            h('span', { class: 'osi-chip osi-chip--lc', title: l.pduEn }, 'PDU: ' + l.pdu),
            tcp && h('button', { type: 'button', class: 'osi-chip osi-chip--link', onClick: () => api.go('tcpip', l.n) }, 'TCP/IP: ' + tcp.name),
          ),
        ),
      ),
      h('p', { class: 'osi-detail__summary' }, l.summary),
      h('div', { class: 'osi-cards' },
        card('Функции', h('ul', { class: 'osi-list' }, l.functions.map((f) => h('li', null, f)))),
        card('Протоколы и стандарты', h('div', { class: 'osi-chips' },
          l.protocols.map((p) => h('span', { class: 'osi-chip' + (isHit(p.name) ? ' is-hit' : ''), title: p.note }, p.name)),
        ), 'osi-card--wide'),
        card('Устройства', h('ul', { class: 'osi-list osi-list--plain' },
          l.devices.map((d) => h('li', { class: isHit(d.name) ? 'is-hit' : null }, h('b', null, d.name), d.note ? h('span', null, ' — ' + d.note) : null)),
        )),
        card('Адресация', h('p', { class: 'osi-text' }, l.addressing)),
        card('Атаки и защита', h('ul', { class: 'osi-attacks' },
          l.attacks.map((a) => h('li', { class: isHit(a.name) ? 'is-hit' : null },
            h('div', { class: 'osi-attack__name' }, a.name),
            h('div', { class: 'osi-attack__note' }, a.note),
            h('div', { class: 'osi-attack__def' }, h('span', null, 'защита: '), a.defense),
          )),
        ), 'osi-card--wide'),
        card('Пример', h('p', { class: 'osi-text osi-text--mono' }, l.example), 'osi-card--wide'),
      ),
      h('div', { class: 'osi-detail__nav' },
        h('button', { type: 'button', class: 'btn', disabled: !prev, onClick: () => prev && select(prev.n, true) }, '↑ ', prev ? 'L' + prev.n + ' ' + prev.name : 'выше нет'),
        h('button', { type: 'button', class: 'btn', disabled: !next, onClick: () => next && select(next.n, true) }, '↓ ', next ? 'L' + next.n + ' ' + next.name : 'ниже нет'),
      ),
    );
  }

  function select(n, byUser) {
    const l = api.layer(n);
    if (!l) return;
    if (byUser) hit = '';
    current = l.n;
    Object.entries(buttons).forEach(([k, b]) => b.classList.toggle('active', Number(k) === l.n));
    renderDetail(l);
    api.setParam(l.n);
    if (byUser && window.matchMedia('(max-width: 900px)').matches) {
      detail.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  }

  function runSearch() {
    const q = search.value.trim().toLowerCase();
    results.replaceChildren();
    if (!q) {
      Object.values(buttons).forEach((b) => b.classList.remove('is-match', 'is-dim'));
      return;
    }
    const found = [];
    api.layers().forEach((l) => {
      KINDS.forEach(([field, kind]) => {
        (l[field] || []).forEach((it) => {
          const text = (it.name + ' ' + (it.note || '') + ' ' + (it.defense || '')).toLowerCase();
          if (text.includes(q)) found.push({ l, it, kind, exact: it.name.toLowerCase().startsWith(q) });
        });
      });
    });
    found.sort((a, b) => Number(b.exact) - Number(a.exact));

    const matched = new Set(found.map((f) => f.l.n));
    Object.entries(buttons).forEach(([k, b]) => {
      b.classList.toggle('is-match', matched.has(Number(k)));
      b.classList.toggle('is-dim', !matched.has(Number(k)));
    });

    if (!found.length) {
      results.append(h('div', { class: 'osi-results__empty' }, 'Ничего не нашлось'));
      return;
    }
    found.slice(0, 10).forEach(({ l, it, kind }) => {
      results.append(h('button', {
        type: 'button',
        class: 'osi-result',
        style: { '--lc': l.color },
        onClick: () => { hit = it.name; select(l.n); },
      },
      h('span', { class: 'osi-result__layer' }, 'L' + l.n),
      h('span', { class: 'osi-result__name' }, it.name),
      h('span', { class: 'osi-result__kind' }, kind)));
    });
    if (found.length > 10) results.append(h('div', { class: 'osi-results__empty' }, 'и ещё ' + (found.length - 10)));
  }

  OSI.register({
    id: 'layers',
    title: 'Уровни',
    order: 10,

    mount(el, a) {
      api = a; h = a.h; root = el;

      search = h('input', { class: 'input', type: 'search', placeholder: 'Поиск: протокол, устройство, атака', 'aria-label': 'Поиск по уровням', onInput: runSearch });
      results = h('div', { class: 'osi-results' });
      const stack = h('div', { class: 'osi-stack', role: 'list' });
      api.layers().forEach((l) => {
        buttons[l.n] = h('button', {
          type: 'button',
          class: 'osi-layer',
          role: 'listitem',
          style: { '--lc': l.color },
          onClick: () => select(l.n, true),
        },
        h('span', { class: 'osi-layer__num' }, l.n),
        h('span', { class: 'osi-layer__name' }, l.name, h('small', null, l.en)),
        h('span', { class: 'osi-layer__pdu' }, l.pdu));
        stack.append(buttons[l.n]);
      });

      const mnem = h('div', { class: 'osi-mnem' },
        h('div', { class: 'osi-card__title' }, 'Запомнить порядок'),
        api.data.mnemonics.map((m) => h('div', { class: 'osi-mnem__row' }, h('span', null, m.dir), ' ', m.text)));

      detail = h('div', { class: 'osi-detail panel', 'aria-live': 'polite' });
      root.append(h('div', { class: 'osi-explorer' },
        h('aside', { class: 'osi-side' }, search, results, stack, mnem),
        detail));

      // Стрелки вверх и вниз листают уровни, пока открыта эта вкладка
      document.addEventListener('keydown', (e) => {
        if (root.hidden || e.altKey || e.ctrlKey || e.metaKey) return;
        if (e.target instanceof Element && e.target.closest('input, textarea, select')) return;
        if (e.key === 'ArrowUp' && current < 7) { e.preventDefault(); select(current + 1, true); }
        if (e.key === 'ArrowDown' && current > 1) { e.preventDefault(); select(current - 1, true); }
      });
    },

    show(param) {
      select(Number(param) || current);
    },
  });
})();
