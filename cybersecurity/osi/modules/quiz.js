// Вкладка «Тренажёр»: угадать уровень OSI для протокола, устройства или атаки.
// Вопросы собираются из data.js сами; элементы с quiz: false пропускаются.
(function () {
  let api, h, els = {};
  let pool = [];
  let bag = [];
  let q = null;
  let answered = false;
  const score = { right: 0, total: 0, streak: 0, best: 0 };
  const kinds = { protocols: true, devices: true, attacks: true };

  const KIND_LABEL = { protocols: 'Протокол или стандарт', devices: 'Устройство', attacks: 'Атака' };
  const KIND_Q = {
    protocols: 'На каком уровне OSI работает',
    devices: 'На каком уровне OSI работает',
    attacks: 'На каком уровне OSI происходит атака',
  };

  function buildPool() {
    pool = [];
    api.layers().forEach((l) => {
      Object.keys(kinds).forEach((kind) => {
        if (!kinds[kind]) return;
        (l[kind] || []).forEach((it) => {
          if (it.quiz === false) return;
          pool.push({ kind, item: it, layer: l.n });
        });
      });
    });
    bag = [];
  }

  // Мешок без повторов: пока не пройдены все вопросы, один и тот же не выпадет
  function nextQuestion() {
    if (!pool.length) { q = null; render(); return; }
    if (!bag.length) bag = api.shuffle(pool);
    q = bag.pop();
    answered = false;
    render();
  }

  function answer(n) {
    if (!q || answered) return;
    answered = true;
    score.total++;
    if (n === q.layer) {
      score.right++;
      score.streak++;
      score.best = Math.max(score.best, score.streak);
    } else {
      score.streak = 0;
    }
    render(n);
  }

  function render(chosen) {
    els.score.replaceChildren(
      h('span', null, 'Верно ', h('b', null, score.right), ' из ', h('b', null, score.total)),
      h('span', null, 'Серия ', h('b', null, score.streak)),
      h('span', null, 'Рекорд ', h('b', null, score.best)));

    if (!q) {
      els.card.replaceChildren(h('div', { class: 'quiz-empty' }, 'Выбери хотя бы одну категорию вопросов.'));
      els.answers.replaceChildren();
      els.feedback.replaceChildren();
      els.next.hidden = true;
      return;
    }

    els.card.replaceChildren(
      h('div', { class: 'quiz-kind' }, KIND_LABEL[q.kind]),
      h('div', { class: 'quiz-q' }, KIND_Q[q.kind] + ':'),
      h('div', { class: 'quiz-name' }, q.item.name));

    els.answers.replaceChildren(...api.layers().map((l) => {
      const cls = ['quiz-btn'];
      if (answered && l.n === q.layer) cls.push('is-right');
      if (answered && l.n === chosen && chosen !== q.layer) cls.push('is-wrong');
      return h('button', {
        type: 'button',
        class: cls.join(' '),
        style: { '--lc': l.color },
        disabled: answered,
        onClick: () => answer(l.n),
      }, h('span', { class: 'quiz-btn__num' }, l.n), h('span', null, l.name), h('kbd', null, l.n));
    }));

    if (answered) {
      const l = api.layer(q.layer);
      const ok = chosen === q.layer;
      els.feedback.replaceChildren(h('div', { class: 'quiz-fb ' + (ok ? 'is-ok' : 'is-bad'), style: { '--lc': l.color } },
        h('b', null, ok ? 'Верно. ' : 'Нет. '),
        q.item.name + ': L' + l.n + ', ' + l.name.toLowerCase() + ' уровень.',
        q.item.note ? h('div', { class: 'quiz-fb__note' }, q.item.note) : null,
        q.item.defense ? h('div', { class: 'quiz-fb__note' }, 'Защита: ' + q.item.defense) : null,
        h('button', { type: 'button', class: 'quiz-fb__link', onClick: () => api.go('layers', l.n) }, 'открыть уровень →')));
      els.next.hidden = false;
      els.next.focus({ preventScroll: true });
    } else {
      els.feedback.replaceChildren();
      els.next.hidden = true;
    }
  }

  OSI.register({
    id: 'quiz',
    title: 'Тренажёр',
    order: 50,

    mount(root, a) {
      api = a; h = a.h;

      const toggles = h('div', { class: 'osi-chips' }, Object.keys(kinds).map((kind) => {
        const b = h('button', {
          type: 'button',
          class: 'osi-chip osi-chip--btn active',
          onClick: () => {
            kinds[kind] = !kinds[kind];
            b.classList.toggle('active', kinds[kind]);
            buildPool();
            nextQuestion();
          },
        }, KIND_LABEL[kind]);
        return b;
      }));

      els.score = h('div', { class: 'quiz-score' });
      els.card = h('div', { class: 'quiz-card' });
      els.answers = h('div', { class: 'quiz-answers' });
      els.feedback = h('div', { class: 'quiz-feedback', 'aria-live': 'polite' });
      els.next = h('button', { type: 'button', class: 'btn btn--primary', hidden: true, onClick: nextQuestion }, 'Следующий вопрос →');
      const reset = h('button', { type: 'button', class: 'btn', onClick: () => {
        Object.assign(score, { right: 0, total: 0, streak: 0, best: score.best });
        bag = [];
        nextQuestion();
      } }, 'Сбросить счёт');

      root.append(h('div', { class: 'quiz panel' },
        h('div', { class: 'quiz-top' }, toggles, els.score),
        els.card,
        els.answers,
        els.feedback,
        h('div', { class: 'quiz-controls' }, reset, els.next),
        h('div', { class: 'quiz-hint' }, 'Клавиши 1–7 выбирают уровень, Enter переходит к следующему вопросу.')));

      document.addEventListener('keydown', (e) => {
        if (root.hidden || e.altKey || e.ctrlKey || e.metaKey) return;
        if (e.target instanceof Element && e.target.closest('input, textarea, select')) return;
        if (/^[1-7]$/.test(e.key) && !answered) { e.preventDefault(); answer(Number(e.key)); }
        else if (e.key === 'Enter' && answered && e.target !== els.next) { e.preventDefault(); nextQuestion(); }
      });

      buildPool();
      nextQuestion();
    },
  });
})();
