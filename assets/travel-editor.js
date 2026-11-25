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
      if ((name === 'span' || name === 'button') && node.dataset.travelPhoto) {
        nodes.push({ tag: 'photo', src: node.dataset.travelPhoto, caption: node.dataset.caption || '' });
        continue;
      }
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

  function photoReference(src, caption) {
    // The formatting command can unwrap spans. A photo is an atomic editor item.
    const reference = document.createElement('button');
    reference.type = 'button';
    reference.tabIndex = -1;
    reference.className = 'co-editor-photo-reference';
    reference.contentEditable = 'false';
    reference.title = 'Изменить подпись к фото';
    reference.dataset.travelPhoto = src;
    reference.dataset.caption = caption;
    reference.textContent = caption ? 'Фото · ' + caption : 'Фото из галереи';
    return reference;
  }

  function toDom(node, makePhoto) {
    if (typeof node === 'string') return document.createTextNode(node);
    if (node?.tag === 'photo') return makePhoto(node.src, node.caption || '');
    if (!node || !tags.has(node.tag)) return null;
    const children = (node.children || []).map(child => toDom(child, makePhoto)).filter(Boolean);
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

  function photoBlock(node) {
    if (node.tag === 'photo') return [node];
    if (node.tag !== 'p' && node.tag !== 'div') return null;
    const contents = node.children.filter(child => typeof child === 'string' ? child.trim() : child.tag !== 'br');
    return contents.length && contents.every(child => child.tag === 'photo') ? contents : null;
  }

  function render(target, nodes, makePhoto = photoReference, chapters = false) {
    target.replaceChildren();
    let section = target;
    let photoRow = null;
    for (const node of nodes) {
      const photos = chapters && photoBlock(node);
      if (photos) {
        const buttons = photos.map(photo => toDom(photo, makePhoto)).filter(Boolean);
        if (buttons.length && !photoRow) {
          photoRow = document.createElement('div');
          photoRow.className = 'co-story-photos';
          section.append(photoRow);
        }
        photoRow?.append(...buttons);
        continue;
      }
      if (chapters && typeof node === 'string' && !node.trim()) continue;
      photoRow = null;
      const element = toDom(node, makePhoto);
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
    const photoPanel = container.querySelector('.co-editor-photo');
    const photoSelect = photoPanel.querySelector('select');
    const photoCaption = photoPanel.querySelector('input');
    const photoPreview = photoPanel.querySelector('img');
    const photoToggle = container.querySelector('[data-insert-photo]');
    const photoApply = photoPanel.querySelector('[data-apply-photo]');
    let media = [];
    let captionTarget = null;
    let range = null;
    let baseline = '';
    let sourceText = '';
    let sourceStory = null;

    function remember() {
      const selection = document.getSelection();
      if (!selection?.rangeCount || !editor.contains(selection.anchorNode) || !editor.contains(selection.focusNode)) return;
      range = selection.getRangeAt(0).cloneRange();
      container.querySelectorAll('[aria-pressed][data-edit]').forEach(button => {
        button.setAttribute('aria-pressed', String(document.queryCommandState(button.dataset.edit)));
      });
    }

    function replacesStory() {
      const selection = document.getSelection();
      if (!selection?.rangeCount || !editor.contains(selection.anchorNode) || !editor.contains(selection.focusNode)) return false;
      const selected = selection.getRangeAt(0);
      const before = document.createRange();
      const after = document.createRange();
      before.selectNodeContents(editor);
      after.selectNodeContents(editor);
      before.setEnd(selected.startContainer, selected.startOffset);
      after.setStart(selected.endContainer, selected.endOffset);
      return [before, after].every(part => {
        const contents = part.cloneContents();
        return !contents.textContent.trim() && !contents.querySelector('[data-travel-photo]');
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
      closePhoto();
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

    function updatePhotoPreview() {
      const file = media.find(entry => entry.src === photoSelect.value);
      photoPreview.hidden = !file || file.type !== 'image';
      if (photoPreview.hidden) photoPreview.removeAttribute('src');
      else photoPreview.src = file.preview || file.src;
    }
    function closePhoto() {
      photoPanel.hidden = true;
      photoToggle.setAttribute('aria-expanded', 'false');
      captionTarget = null;
      photoSelect.disabled = false;
      photoApply.textContent = 'Вставить';
    }
    function openPhoto() {
      closeLink();
      photoPanel.hidden = false;
      photoToggle.setAttribute('aria-expanded', 'true');
      updatePhotoPreview();
    }
    photoToggle.addEventListener('mousedown', e => e.preventDefault());
    photoToggle.addEventListener('click', () => {
      if (!photoPanel.hidden) { closePhoto(); return; }
      photoCaption.value = '';
      openPhoto();
      photoSelect.focus();
    });
    editor.addEventListener('click', e => {
      const reference = e.target.closest('button[data-travel-photo]');
      if (!reference) return;
      const source = reference.dataset.travelPhoto;
      const file = media.find(entry => entry.src === source || entry.preview === source);
      if (!file) return;
      captionTarget = reference;
      photoSelect.value = file.src;
      photoSelect.disabled = true;
      photoCaption.value = reference.dataset.caption;
      photoApply.textContent = 'Применить';
      openPhoto();
      photoCaption.focus();
    });
    photoSelect.addEventListener('change', updatePhotoPreview);
    function insertPhoto() {
      if (!photoSelect.value) return;
      const caption = photoCaption.value.trim();
      if (captionTarget && editor.contains(captionTarget)) {
        const replacement = photoReference(captionTarget.dataset.travelPhoto, caption);
        range = document.createRange();
        range.selectNode(captionTarget);
        closePhoto();
        apply('insertHTML', replacement.outerHTML);
        return;
      }
      const block = document.createElement('div');
      block.append(photoReference(photoSelect.value, caption));
      closePhoto();
      apply('insertHTML', block.outerHTML);
    }
    photoApply.addEventListener('click', insertPhoto);
    photoPanel.querySelector('[data-cancel-photo]').addEventListener('click', () => { closePhoto(); editor.focus(); });
    photoPanel.addEventListener('keydown', e => {
      if (e.key === 'Escape') { e.stopPropagation(); closePhoto(); editor.focus(); }
      if (e.key === 'Enter' && e.target === photoCaption) { e.preventDefault(); insertPhoto(); }
    });

    editor.addEventListener('paste', e => {
      if (!e.clipboardData || e.clipboardData.files.length) return;
      e.preventDefault();
      e.stopPropagation();
      const html = e.clipboardData.getData('text/html');
      if (html) {
        const template = document.createElement('template');
        template.innerHTML = html;
        const safe = document.createElement('div');
        const photoFromClipboard = (src, caption) => media.some(file => file.src === src || file.preview === src)
          ? photoReference(src, caption.slice(0, 180)) : null;
        render(safe, read(template.content), photoFromClipboard);
        if (safe.innerHTML) {
          if (replacesStory()) {
            // Chromium otherwise inherits the first block's styling when replacing a whole story.
            editor.replaceChildren(...safe.childNodes);
            document.getSelection().collapse(editor, editor.childNodes.length);
            remember();
          } else {
            document.execCommand('insertHTML', false, safe.innerHTML);
          }
          return;
        }
      }
      document.execCommand('insertText', false, e.clipboardData.getData('text/plain'));
    });
    editor.addEventListener('drop', e => e.preventDefault());
    editor.addEventListener('focus', () => document.execCommand('defaultParagraphSeparator', false, 'p'));

    function snapshot() { return JSON.stringify(editor.textContent.trim() ? read(editor) : []); }

    return {
      setMedia(files) {
        media = files;
        const selected = photoSelect.value;
        photoSelect.replaceChildren(...files.map((file, index) => new Option(
          `${file.type === 'video' ? 'Видео' : 'Фото'} ${index + 1}`, file.src)));
        if (files.some(file => file.src === selected)) photoSelect.value = selected;
        photoToggle.disabled = !files.length;
        photoToggle.title = files.length ? 'Вставить файл из галереи в рассказ' : 'Сначала добавь и сохрани фото в галерее';
        if (!files.length) closePhoto();
        updatePhotoPreview();
      },
      load(item, renderLegacy) {
        range = null;
        linkInput.setCustomValidity('');
        closeLink();
        closePhoto();
        sourceText = item?.details || '';
        sourceStory = Array.isArray(item?.details_rich) ? JSON.stringify(item.details_rich) : null;
        if (sourceStory !== null) render(editor, item.details_rich);
        else {
          renderLegacy(editor, sourceText);
          editor.querySelectorAll(':scope > section').forEach(section => section.replaceWith(...section.childNodes));
        }
        baseline = snapshot();
      },
      append(formData) {
        const current = snapshot();
        if (current === baseline && sourceStory === null) formData.set('details', sourceText);
        else formData.set('details_rich', current === baseline ? sourceStory : current);
      },
    };
  }

  window.travelStory = { connect, render };
})();
