// Вкладка «Инкапсуляция»: HTTPS-запрос идёт вниз по стеку отправителя, по сети и вверх у получателя.
// На каждом шаге видно, какой заголовок добавился или снялся. Адрес: #encap/5 открывает пятый шаг.
(function () {
  let api, h, root;
  let els = {};
  let step = 0;
  let timer = null;
  let picked = null; // блок пакета, чьи поля показаны

  // Части пакета. layer задаёт цвет, fields показываются по клику на блок
  const BLOCKS = {
    data: { label: 'GET / HTTP/1.1', layer: 7, grow: 3, fields: [['Метод', 'GET'], ['Путь', '/'], ['Host', 'example.com'], ['User-Agent', 'Mozilla/5.0']] },
    enc: { label: 'TLS: 17 03 03 00 4a …', layer: 6, grow: 3, fields: [['Тип записи', 'Application Data (23)'], ['Версия в заголовке', '0x0303'], ['Содержимое', 'шифротекст AES-GCM, без ключа не прочитать']] },
    tcp: { label: 'TCP', layer: 4, grow: 1.2, fields: [['Порт отправителя', '51514 (временный)'], ['Порт получателя', '443 (HTTPS)'], ['Seq', '1001'], ['Ack', '5001'], ['Флаги', 'PSH, ACK'], ['Окно', '64240']] },
    ip: { label: 'IP', layer: 3, grow: 1.2, fields: [['Версия', '4'], ['TTL', '64'], ['Протокол', '6 (TCP)'], ['Отправитель', '192.168.1.10'], ['Получатель', '93.184.216.34']] },
    eth: { label: 'Ethernet', layer: 2, grow: 1.4, fields: [['MAC получателя', '00:1a:2b:3c:4d:5e (шлюз)'], ['MAC отправителя', '3c:22:fb:8a:10:4e'], ['EtherType', '0x0800 (IPv4)']] },
    fcs: { label: 'FCS', layer: 2, grow: 0.6, fields: [['CRC-32', '0x5d9f1e2a'], ['Назначение', 'проверка, что кадр не повреждён']] },
  };
  const FRAME = ['eth', 'ip', 'tcp', 'enc', 'fcs'];

  // side: send | wire | recv; add/strip — какие блоки добавились или снимаются на шаге
  const STEPS = [
    { side: 'send', layer: 7, blocks: ['data'], add: ['data'], title: 'Приложение формирует запрос', text: 'Браузер готовит HTTP-запрос к example.com. Пока это просто данные приложения.' },
    { side: 'send', layer: 6, blocks: ['enc'], add: ['enc'], title: 'Шифрование', text: 'TLS шифрует запрос. Дальше по сети пойдёт шифротекст: посредник увидит адреса и порты, но не содержимое.' },
    { side: 'send', layer: 5, blocks: ['enc'], title: 'Сеанс', text: 'Данные идут в рамках уже установленного сеанса (TLS-сессия поверх TCP-соединения). Отдельного заголовка этот уровень здесь не добавляет.' },
    { side: 'send', layer: 4, blocks: ['tcp', 'enc'], add: ['tcp'], title: 'TCP-заголовок: сегмент', text: 'Добавляются порты: 51514 у клиента, 443 у сервера, номера последовательности и флаги. Данные с TCP-заголовком называются сегментом.' },
    { side: 'send', layer: 3, blocks: ['ip', 'tcp', 'enc'], add: ['ip'], title: 'IP-заголовок: пакет', text: 'Добавляются IP-адреса отправителя и получателя и TTL. Теперь это пакет, его можно маршрутизировать между сетями.' },
    { side: 'send', layer: 2, blocks: FRAME, add: ['eth', 'fcs'], title: 'Ethernet: кадр', text: 'Заголовок с MAC-адресами и концевик FCS. MAC получателя здесь у шлюза, а не у сервера: кадр живёт только в пределах одного канала.' },
    { side: 'send', layer: 1, bits: true, title: 'Биты в кабель', text: 'Кадр превращается в последовательность битов и уходит в среду электрическими импульсами, светом или радиоволной.' },
    { side: 'wire', bits: true, title: 'Путь по сети', text: 'На каждом маршрутизаторе кадр снимается, IP-заголовок читается, TTL уменьшается на 1, и пакет упаковывается в новый кадр с новыми MAC. IP-адреса при этом не меняются (если нет NAT).' },
    { side: 'recv', layer: 1, bits: true, title: 'Приём сигнала', text: 'Сетевая карта сервера принимает сигнал и восстанавливает из него биты.' },
    { side: 'recv', layer: 2, blocks: FRAME, strip: ['eth', 'fcs'], title: 'Проверка кадра', text: 'FCS сходится, MAC получателя свой. Заголовок и концевик снимаются, EtherType 0x0800 говорит, что внутри IPv4.' },
    { side: 'recv', layer: 3, blocks: ['ip', 'tcp', 'enc'], strip: ['ip'], title: 'Проверка пакета', text: 'IP получателя совпадает с адресом сервера. Поле «Протокол» = 6, значит пакет отдаётся TCP.' },
    { side: 'recv', layer: 4, blocks: ['tcp', 'enc'], strip: ['tcp'], title: 'Доставка процессу', text: 'Порт 443 принадлежит веб-серверу. TCP подтверждает приём (ACK) и собирает сегменты по порядку.' },
    { side: 'recv', layer: 5, blocks: ['enc'], title: 'Сеанс', text: 'Данные относятся к существующему сеансу этого клиента.' },
    { side: 'recv', layer: 6, blocks: ['enc'], strip: ['enc'], title: 'Расшифровка', text: 'TLS расшифровывает данные ключом сеанса.' },
    { side: 'recv', layer: 7, blocks: ['data'], title: 'Приложение получает запрос', text: 'Веб-сервер читает GET / и готовит ответ. Ответ пройдёт тот же путь в обратную сторону.' },
  ];

  // Псевдобиты: детерминированные, чтобы картинка не прыгала между шагами
  const BITS = (() => {
    const src = 'E\u0000\u0000T@\u0000@\u0006À¨\u0001\nGET / HTTP';
    return [...src].map((c) => c.charCodeAt(0).toString(2).padStart(8, '0').slice(-8)).join(' ') + ' …';
  })();

  function stackEl(side, title) {
    const rows = {};
    const box = h('div', { class: 'enc-stack enc-stack--' + side },
      h('div', { class: 'enc-stack__title' }, title),
      api.layers().map((l) => (rows[l.n] = h('div', { class: 'enc-row', style: { '--lc': l.color } },
        h('span', { class: 'enc-row__num' }, 'L' + l.n),
        h('span', { class: 'enc-row__name' }, l.name)))));
    return { box, rows };
  }

  function render() {
    const s = STEPS[step];

    [['send', els.send], ['recv', els.recv]].forEach(([side, st]) => {
      Object.entries(st.rows).forEach(([n, row]) => {
        n = Number(n);
        const active = s.side === side && s.layer === n;
        // Пройденные уровни: у отправителя всё выше текущего, у получателя всё ниже
        const done = (side === 'send' && (s.side !== 'send' || n > s.layer)) ||
          (side === 'recv' && s.side === 'recv' && n < s.layer);
        row.classList.toggle('is-active', active);
        row.classList.toggle('is-done', done && !active);
      });
    });
    els.wire.classList.toggle('is-active', s.side === 'wire');
    els.wire.classList.toggle('is-past', s.side === 'recv');

    els.counter.textContent = 'Шаг ' + (step + 1) + ' из ' + STEPS.length;
    const where = s.side === 'wire' ? 'среда передачи' : (s.side === 'send' ? 'отправитель' : 'получатель') + ', L' + s.layer + ' ' + api.layer(s.layer).name;
    els.where.textContent = where;
    els.title.textContent = s.title;
    els.text.textContent = s.text;

    if (s.bits) {
      els.packet.replaceChildren(h('div', { class: 'enc-bits', style: { '--lc': api.layer(1).color } }, BITS));
      picked = null;
    } else {
      if (picked && !s.blocks.includes(picked)) picked = null;
      els.packet.replaceChildren(...s.blocks.map((key) => {
        const b = BLOCKS[key];
        const l = api.layer(b.layer);
        const cls = ['enc-block'];
        if (s.add && s.add.includes(key)) cls.push('is-added');
        if (s.strip && s.strip.includes(key)) cls.push('is-strip');
        if (picked === key) cls.push('is-picked');
        return h('button', {
          type: 'button',
          class: cls.join(' '),
          style: { '--lc': l.color, 'flex-grow': String(b.grow) },
          title: 'Показать поля',
          onClick: () => { picked = picked === key ? null : key; render(); },
        },
        h('span', { class: 'enc-block__layer' }, 'L' + b.layer),
        h('span', { class: 'enc-block__label' }, b.label),
        s.add && s.add.includes(key) ? h('span', { class: 'enc-block__tag' }, '+ добавлен') : null,
        s.strip && s.strip.includes(key) ? h('span', { class: 'enc-block__tag' }, '− снимается') : null);
      }));
    }

    if (picked) {
      const b = BLOCKS[picked];
      els.fields.hidden = false;
      els.fields.style.setProperty('--lc', api.layer(b.layer).color);
      els.fields.replaceChildren(
        h('div', { class: 'osi-card__title' }, 'Поля: ' + (picked === 'data' || picked === 'enc' ? 'данные' : b.label)),
        h('table', { class: 'enc-fields' }, h('tbody', null, b.fields.map(([k, v]) => h('tr', null, h('th', null, k), h('td', null, v))))));
    } else {
      els.fields.hidden = true;
    }

    els.prev.disabled = step === 0;
    els.next.disabled = step === STEPS.length - 1;
    els.play.textContent = timer ? '❚❚ пауза' : '▶ авто';
    els.progress.style.width = ((step + 1) / STEPS.length * 100) + '%';
    api.setParam(step + 1);
  }

  function go(i) {
    step = Math.max(0, Math.min(STEPS.length - 1, i));
    render();
  }

  function stop() {
    if (timer) { clearInterval(timer); timer = null; }
  }

  function togglePlay() {
    if (timer) { stop(); render(); return; }
    if (step === STEPS.length - 1) step = 0;
    timer = setInterval(() => {
      if (step >= STEPS.length - 1) { stop(); render(); return; }
      go(step + 1);
    }, 2600);
    render();
  }

  OSI.register({
    id: 'encap',
    title: 'Инкапсуляция',
    order: 20,

    mount(el, a) {
      api = a; h = a.h; root = el;
      els.send = stackEl('send', 'Отправитель: 192.168.1.10');
      els.recv = stackEl('recv', 'Сервер: 93.184.216.34');
      els.wire = h('div', { class: 'enc-wire', 'aria-hidden': 'true' }, h('span', { class: 'enc-wire__line' }), h('span', { class: 'enc-wire__dot' }), h('span', { class: 'enc-wire__label' }, 'сеть'));

      els.counter = h('span', { class: 'enc-counter' });
      els.where = h('span', { class: 'enc-where' });
      els.title = h('h3', { class: 'enc-title' });
      els.text = h('p', { class: 'enc-text' });
      els.packet = h('div', { class: 'enc-packet' });
      els.fields = h('div', { class: 'enc-fields-box', hidden: true });
      els.progress = h('span');

      els.prev = h('button', { type: 'button', class: 'btn', onClick: () => { stop(); go(step - 1); } }, '← назад');
      els.next = h('button', { type: 'button', class: 'btn btn--primary', onClick: () => { stop(); go(step + 1); } }, 'дальше ->');
      els.play = h('button', { type: 'button', class: 'btn', onClick: togglePlay });
      const reset = h('button', { type: 'button', class: 'btn', onClick: () => { stop(); picked = null; go(0); } }, 'сначала');

      root.append(h('div', { class: 'enc panel' },
        h('div', { class: 'enc-scene' }, els.send.box, els.wire, els.recv.box),
        h('div', { class: 'enc-progress' }, els.progress),
        h('div', { class: 'enc-info' },
          h('div', { class: 'enc-meta' }, els.counter, els.where),
          els.title,
          els.text),
        h('div', { class: 'enc-packet-wrap' },
          h('div', { class: 'osi-card__title' }, 'Что передаётся (нажми на блок, чтобы увидеть поля)'),
          els.packet),
        els.fields,
        h('div', { class: 'enc-controls' }, reset, els.prev, els.play, els.next)));

      document.addEventListener('keydown', (e) => {
        if (root.hidden || e.altKey || e.ctrlKey || e.metaKey) return;
        if (e.target instanceof Element && e.target.closest('input, textarea, select')) return;
        if (e.key === 'ArrowRight') { e.preventDefault(); stop(); go(step + 1); }
        if (e.key === 'ArrowLeft') { e.preventDefault(); stop(); go(step - 1); }
      });
    },

    show(param) {
      const n = Number(param);
      go(n >= 1 ? n - 1 : step);
    },

    hide() {
      stop();
    },
  });
})();
