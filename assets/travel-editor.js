(() => {
  const tags = new Set(['p', 'div', 'h3', 'h4', 'strong', 'em', 'u', 'ul', 'ol', 'li', 'blockquote', 'br', 'a', 'span']);
  const blocks = new Set(['p', 'div', 'h3', 'h4', 'ul', 'ol', 'li', 'blockquote']);
  const aliases = { b: 'strong', i: 'em', font: 'span' };
  const hex = /^#[0-9a-f]{6}$/i;

  function textColor(value) {
    if (hex.test(value)) return value.toLowerCase();
    const rgb = /^rgb\(\s*(\d{1,3}),\s*(\d{1,3}),\s*(\d{1,3})\s*\)$/i.exec(value);
    if (!rgb || rgb.slice(1).some(channel => Number(channel) > 255)) return '';
    return '#' + rgb.slice(1).map(channel => Number(channel).toString(16).padStart(2, '0')).join('');
  }

  function read(parent) {
    const nodes = [];
    for (const node of parent.childNodes) {
      if (node.nodeType === Node.TEXT_NODE) { nodes.push(node.data); continue; }
      if (node.nodeType !== Node.ELEMENT_NODE) continue;
      const name = node.localName;
      if (['script', 'style', 'iframe', 'svg', 'math'].includes(name)) continue;
      let tag = aliases[name] || name;
      if (tag === 'br') { nodes.push({ tag }); continue; }
      let children = read(node);
      const color = textColor(node.style?.color || node.getAttribute('color') || '');
      if (tag === 'p' && children.some(child => blocks.has(child.tag))) tag = 'div';
      if (color && tag !== 'span') children = [{ tag: 'span', color, children }];
      if (!tags.has(tag)) { nodes.push(...children); continue; }
      const item = { tag, children };
      if (tag === 'a') item.href = node.getAttribute('href') || '';
      if (tag === 'span') {
        if (color) item.color = color;
        if (node.classList.contains('co-chapter-date')) item.date = true;
      }
      nodes.push(item);
    }
    return nodes;
  }

  function toDom(node) {
    if (typeof node === 'string') return document.createTextNode(node);
    if (!node || !tags.has(node.tag)) return null;
    const children = (node.children || []).map(toDom).filter(Boolean);
    if (node.tag === 'a' && !/^https?:\/\//i.test(node.href || '')) {
      const fragment = document.createDocumentFragment();
      fragment.append(...children);
      return fragment;
    }
    const element = document.createElement(node.tag);
    if (node.tag === 'a') {
      element.href = node.href;
      element.target = '_blank';
      element.rel = 'noopener noreferrer';
    }
    if (node.tag === 'span') {
      if (typeof node.color === 'string' && hex.test(node.color)) element.style.color = node.color;
      if (node.date === true) element.className = 'co-chapter-date';
    }
    element.append(...children);
    return element;
  }

  function render(target, nodes, chapters = true) {
    target.replaceChildren();
    let section = target;
    for (const node of nodes) {
      const element = toDom(node);
      if (!element) continue;
      if (chapters && node.tag === 'h3') {
        section = document.createElement('section');
        section.className = 'co-travel-section';
        target.append(section);
      }
      section.append(element);
    }
  }

  function connect(container) {
    const editor = container.querySelector('[contenteditable]');
    const linkPanel = container.querySelector('.co-editor-link');
    const linkInput = linkPanel.querySelector('input');
    let range = null;
    let baseline = '';
    let sourceText = '';

    function remember() {
      const selection = document.getSelection();
      if (!selection?.rangeCount || !editor.contains(selection.anchorNode) || !editor.contains(selection.focusNode)) return;
      range = selection.getRangeAt(0).cloneRange();
      container.querySelectorAll('[aria-pressed][data-edit]').forEach(button => {
        button.setAttribute('aria-pressed', String(document.queryCommandState(button.dataset.edit)));
      });
    }

    function apply(command, value = null) {
      editor.focus({ preventScroll: true });
      if (range && editor.contains(range.commonAncestorContainer)) {
        const selection = document.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
      }
      // Native editing commands keep these changes in the browser's undo history.
      document.execCommand(command, false, value);
      remember();
    }

    document.addEventListener('selectionchange', remember);
    container.querySelectorAll('button[data-edit]').forEach(button => {
      button.addEventListener('mousedown', e => e.preventDefault());
      button.addEventListener('click', () => apply(button.dataset.edit, button.dataset.value || null));
    });
    container.querySelectorAll('select[data-edit], input[data-edit]').forEach(control => {
      control.addEventListener('change', () => {
        apply(control.dataset.edit, control.value);
        if (control.localName === 'select') control.selectedIndex = 0;
      });
    });

    const showLink = container.querySelector('[data-insert-link]');
    showLink.addEventListener('mousedown', e => e.preventDefault());
    showLink.addEventListener('click', () => {
      linkPanel.hidden = false;
      linkInput.disabled = false;
      linkInput.value = '';
      linkInput.setCustomValidity('');
      linkInput.focus();
    });
    function closeLink() { linkPanel.hidden = true; linkInput.disabled = true; }
    function applyLink() {
      const href = linkInput.value.trim();
      if (!/^https?:\/\//i.test(href) || !linkInput.checkValidity()) {
        linkInput.setCustomValidity('Введи ссылку, которая начинается с https:// или http://.');
        linkInput.reportValidity();
        return;
      }
      closeLink();
      if (range && !range.collapsed) apply('createLink', href);
      else {
        const link = document.createElement('a');
        link.href = href;
        link.textContent = href;
        apply('insertHTML', link.outerHTML);
      }
    }
    linkInput.addEventListener('input', () => linkInput.setCustomValidity(''));
    linkInput.addEventListener('keydown', e => {
      if (e.key === 'Enter') { e.preventDefault(); applyLink(); }
      if (e.key === 'Escape') { e.stopPropagation(); closeLink(); editor.focus(); }
    });
    linkPanel.querySelector('[data-apply-link]').addEventListener('click', applyLink);
    linkPanel.querySelector('[data-cancel-link]').addEventListener('click', () => { closeLink(); editor.focus(); });

    editor.addEventListener('paste', e => {
      if (!e.clipboardData || e.clipboardData.files.length) return;
      e.preventDefault();
      e.stopPropagation();
      document.execCommand('insertText', false, e.clipboardData.getData('text/plain'));
    });
    editor.addEventListener('drop', e => e.preventDefault());
    editor.addEventListener('focus', () => document.execCommand('defaultParagraphSeparator', false, 'p'));

    function snapshot() { return JSON.stringify(editor.textContent.trim() ? read(editor) : []); }

    return {
      load(item, renderLegacy) {
        range = null;
        linkInput.setCustomValidity('');
        closeLink();
        sourceText = item?.details || '';
        if (Array.isArray(item?.details_rich)) render(editor, item.details_rich, false);
        else {
          renderLegacy(editor, sourceText);
          editor.querySelectorAll(':scope > section').forEach(section => section.replaceWith(...section.childNodes));
        }
        baseline = snapshot();
      },
      append(formData) {
        const current = snapshot();
        if (current === baseline) formData.set('details', sourceText);
        else formData.set('details_rich', current);
      },
    };
  }

  window.travelStory = { connect, render };
})();
