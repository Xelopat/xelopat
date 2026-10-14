// Вкладка «OSI и TCP/IP»: как семь уровней OSI ложатся на четыре уровня TCP/IP.
// Адрес: #tcpip/4 подсвечивает транспортный уровень.
(function () {
  let api, h;
  const osiCells = {};
  const tcpCells = {};

  function highlight(n) {
    const l = n ? api.layer(n) : null;
    Object.entries(osiCells).forEach(([k, c]) => c.classList.toggle('is-lit', !!l && Number(k) === l.n));
    Object.entries(tcpCells).forEach(([k, c]) => c.classList.toggle('is-lit', !!l && k === l.tcpip));
  }

  function highlightTcp(key) {
    const t = api.data.tcpip.find((x) => x.key === key);
    Object.entries(osiCells).forEach(([k, c]) => c.classList.toggle('is-lit', !!t && t.layers.includes(Number(k))));
    Object.entries(tcpCells).forEach(([k, c]) => c.classList.toggle('is-lit', k === key));
  }

  OSI.register({
    id: 'tcpip',
    title: 'OSI и TCP/IP',
    order: 30,

    mount(root, a) {
      api = a; h = a.h;
      const grid = h('div', { class: 'tcp-grid', onMouseleave: () => highlight(null) },
        h('div', { class: 'tcp-head' }, 'OSI, 7 уровней'),
        h('div', { class: 'tcp-head' }, 'TCP/IP, 4 уровня'),
        h('div', { class: 'tcp-head tcp-head--ex' }, 'Примеры'));

      api.layers().forEach((l) => {
        const row = 8 - l.n + 1; // +1 из-за строки заголовков
        osiCells[l.n] = h('button', {
          type: 'button',
          class: 'tcp-osi',
          style: { '--lc': l.color, 'grid-row': String(row) },
          onMouseenter: () => highlight(l.n),
          onFocus: () => highlight(l.n),
          onClick: () => api.go('layers', l.n),
          title: 'Открыть уровень',
        },
        h('span', { class: 'tcp-osi__num' }, 'L' + l.n),
        h('span', null, l.name),
        h('small', null, l.en));
        grid.append(osiCells[l.n]);
      });

      api.data.tcpip.forEach((t) => {
        const top = 8 - Math.max(...t.layers) + 1;
        const span = 'span ' + t.layers.length;
        tcpCells[t.key] = h('div', {
          class: 'tcp-model',
          style: { 'grid-row': top + ' / ' + span },
          tabindex: '0',
          onMouseenter: () => highlightTcp(t.key),
          onFocus: () => highlightTcp(t.key),
        },
        h('b', null, t.name),
        h('small', null, t.en),
        h('span', { class: 'tcp-model__layers' }, 'OSI: ' + t.layers.map((n) => 'L' + n).join(', ')));
        grid.append(tcpCells[t.key]);
        grid.append(h('div', { class: 'tcp-ex', style: { 'grid-row': top + ' / ' + span } }, t.examples));
      });

      root.append(h('div', { class: 'panel' },
        grid,
        h('ul', { class: 'osi-list tcp-notes' },
          h('li', null, 'OSI — эталонная модель для обучения и описания, TCP/IP — то, по чему реально работает интернет.'),
          h('li', null, 'В TCP/IP сеансовый уровень и уровень представления растворены в приложении: шифрование (TLS) и сеансы реализуют сами программы и библиотеки.'),
          h('li', null, 'Иногда TCP/IP рисуют из 5 уровней: канальный и физический показывают отдельно.'),
        )));
    },

    show(param) {
      highlight(Number(param) || null);
    },
  });
})();
