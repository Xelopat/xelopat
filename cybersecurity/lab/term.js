// Терминал: вывод, строка ввода с приглашением, история, Tab, Ctrl+L, Ctrl+C.
// Работает с любой оболочкой из LAB.shells:
//   shell.banner() → строки приветствия
//   shell.prompt() → [{ text, cls }] части приглашения
//   shell.exec(line, io) → ничего или Promise (пока он идёт, ввод скрыт)
//   shell.complete(line) → { line?, options? } для Tab
// io.print(text | [{ text, cls }], cls), io.clear()
(function () {
  const MAX_LINES = 600;

  function el(tag, cls, text) {
    const e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
  }

  function lineEl(line) {
    const row = el('div', 'term__line' + (line.cls ? ' is-' + line.cls : ''));
    line.parts.forEach((p) => row.append(el('span', p.cls ? 'is-' + p.cls : '', p.text)));
    return row;
  }

  // store: { lines, history, booted } живёт дольше окна, поэтому вывод сохраняется между открытиями
  LAB.Terminal = function (container, shell, store) {
    const root = el('div', 'term');
    const out = el('div', 'term__out');
    const row = el('div', 'term__line term__prompt');
    const promptBox = el('span', 'term__ps');
    const input = el('input', 'term__in');
    input.spellcheck = false;
    input.autocomplete = 'off';
    input.setAttribute('autocapitalize', 'off');
    input.setAttribute('aria-label', 'Командная строка');
    row.append(promptBox, input);
    root.append(out, row);
    container.append(root);

    let hi = store.history.length;
    let busy = false;

    const scroll = () => { root.scrollTop = root.scrollHeight; };

    const io = {
      print(text, cls) {
        const parts = Array.isArray(text) ? text : [{ text: String(text) }];
        const line = { parts, cls };
        store.lines.push(line);
        if (store.lines.length > MAX_LINES) {
          store.lines.splice(0, store.lines.length - MAX_LINES);
          if (out.firstChild) out.firstChild.remove();
        }
        out.append(lineEl(line));
        scroll();
      },
      clear() {
        store.lines.length = 0;
        out.replaceChildren();
      },
    };

    function drawPrompt() {
      promptBox.replaceChildren(...shell.prompt().map((p) => el('span', p.cls ? 'is-' + p.cls : '', p.text)));
    }

    function setBusy(v) {
      busy = v;
      row.hidden = v;
      if (!v) { drawPrompt(); input.focus({ preventScroll: true }); scroll(); }
    }

    function run(line) {
      io.print(shell.prompt().concat([{ text: line, cls: 'cmd' }]));
      if (line.trim()) {
        store.history.push(line);
        if (store.history.length > 100) store.history.shift();
      }
      hi = store.history.length;
      let res;
      try {
        res = shell.exec(line, io);
      } catch (err) {
        io.print('внутренняя ошибка: ' + err.message, 'err');
      }
      if (res && typeof res.then === 'function') {
        setBusy(true);
        res.catch((err) => io.print('ошибка: ' + err.message, 'err')).finally(() => setBusy(false));
      } else {
        drawPrompt();
        scroll();
      }
    }

    input.addEventListener('keydown', (e) => {
      if (busy) { e.preventDefault(); return; }
      if (e.key === 'Enter') {
        e.preventDefault();
        const line = input.value;
        input.value = '';
        run(line);
        return;
      }
      if (e.key === 'ArrowUp') {
        e.preventDefault();
        if (hi > 0) input.value = store.history[--hi];
        return;
      }
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        hi = Math.min(store.history.length, hi + 1);
        input.value = store.history[hi] || '';
        return;
      }
      if (e.key === 'Tab') {
        e.preventDefault();
        const r = shell.complete ? shell.complete(input.value) : null;
        if (!r) return;
        if (r.line != null) input.value = r.line;
        if (r.options && r.options.length) {
          io.print(shell.prompt().concat([{ text: input.value, cls: 'cmd' }]));
          io.print(r.options.join('    '), 'dim');
        }
        return;
      }
      if (e.ctrlKey && (e.key === 'l' || e.key === 'L')) {
        e.preventDefault();
        io.clear();
        return;
      }
      if (e.ctrlKey && (e.key === 'c' || e.key === 'C') && !window.getSelection().toString()) {
        e.preventDefault();
        io.print(shell.prompt().concat([{ text: input.value + '^C', cls: 'cmd' }]));
        input.value = '';
      }
    });

    // Щелчок по терминалу ставит курсор в строку ввода (если не выделяли текст)
    root.addEventListener('mouseup', () => {
      if (!window.getSelection().toString()) input.focus({ preventScroll: true });
    });

    store.lines.forEach((l) => out.append(lineEl(l)));
    if (!store.booted) {
      store.booted = true;
      (shell.banner() || []).forEach((b) => io.print(b.text != null ? [b] : b, b.cls));
    }
    drawPrompt();
    requestAnimationFrame(scroll);

    return {
      focus() { input.focus({ preventScroll: true }); },
      refreshPrompt: drawPrompt,
      run,
    };
  };
})();
