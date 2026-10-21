/* Демо Файломата: сайт без сервера. Выбранный файл никуда не отправляется,
   вместо него показывается настоящий результат, снятый на образце. */
(function () {
  'use strict';
  var P = '/projects/demo/filemat';
  var RESULTS = {
    'szhat-pdf': { s: 'sample-compressed.pdf', size: '4 КБ', fmt: 'PDF', file: 'szhat-pdf.pdf', suffix: '-compressed', ext: 'pdf' },
    'szhat-foto': { s: 'sample-compressed.webp', size: '88 КБ', fmt: 'WEBP', file: 'szhat-foto.webp', suffix: '-compressed', ext: 'webp' },
    'jpg-v-pdf': { s: 'sample.pdf', size: '230 КБ', fmt: 'PDF', file: 'jpg-v-pdf.pdf', suffix: '', ext: 'pdf' },
    'skleit-pdf': { s: 'sample-merged.pdf', size: '5 КБ', fmt: 'PDF', file: 'skleit-pdf.pdf', suffix: '-merged', ext: 'pdf' },
    'razdelit-pdf': { s: 'sample-split.pdf', size: '2 КБ', fmt: 'PDF', file: 'razdelit-pdf.pdf', suffix: '-split', ext: 'pdf' },
    'txt-v-pdf': { s: 'sample.pdf', size: '20 КБ', fmt: 'PDF', file: 'txt-v-pdf.pdf', suffix: '', ext: 'pdf' },
    'jpg-v-avif': { s: 'sample.avif', size: '217 КБ', fmt: 'AVIF', file: 'jpg-v-avif.avif', suffix: '', ext: 'avif' }
  };
  var KEY = 'filematDemo';

  function toast(text) {
    var t = document.createElement('div');
    t.className = 'demo-toast'; t.textContent = text;
    document.body.appendChild(t);
    setTimeout(function () { t.classList.add('on'); }, 20);
    setTimeout(function () { t.classList.remove('on'); setTimeout(function () { t.remove(); }, 300); }, 5200);
  }
  function kb(n) { return n >= 1048576 ? (n / 1048576).toFixed(1).replace('.', ',') + ' МБ' : Math.max(1, Math.round(n / 1024)) + ' КБ'; }

  // Загрузка: перехватываем отправку формы
  var form = document.querySelector('form.upload-form');
  if (form) {
    var slug = (/\/ru\/([a-z0-9-]+)\/convert\//.exec(form.getAttribute('action') || '') || [])[1];
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var input = form.querySelector('input[type=file]');
      var files = input && input.files ? [].slice.call(input.files) : [];
      if (!files.length) { toast('Сначала выберите файл.'); return; }
      var accept = (input.getAttribute('accept') || '').toLowerCase().split(',').filter(Boolean);
      var f = files[0], ext = '.' + (f.name.split('.').pop() || '').toLowerCase();
      if (accept.length && accept.indexOf(ext) < 0) { toast('Этот формат здесь не поддерживается: ' + ext + '. Подходят: ' + accept.join(', ')); return; }
      if (!RESULTS[slug]) { toast('В демо работают: сжатие PDF и фото, JPG в PDF, склейка и разделение PDF, TXT в PDF, JPG в AVIF.'); return; }
      try { sessionStorage.setItem(KEY, JSON.stringify({ slug: slug, name: f.name, size: f.size, count: files.length })); } catch (x) {}
      location.href = P + '/ru/jobs/demo/';
    });
  }

  // Страница задачи: подменяем ответ сервера о статусе
  var root = document.querySelector('[data-job-status]');
  if (root) {
    var job = {};
    try { job = JSON.parse(sessionStorage.getItem(KEY) || '{}'); } catch (x) {}
    var res = RESULTS[job.slug] || RESULTS['szhat-pdf'];
    var base = (job.name || 'document.pdf').replace(/\.[^.]+$/, '');
    var outName = base + res.suffix + '.' + res.ext;
    var note = document.createElement('div');
    note.className = 'demo-note';
    note.innerHTML = '<b>Демо.</b> Ваш файл остался на устройстве. Показан настоящий результат работы сервиса на образце того же типа.';
    var shell = root.querySelector('[data-status-shell]');
    shell.insertBefore(note, shell.firstChild);

    var label = root.querySelector('[data-status-label]'), text = root.querySelector('[data-status-text]');
    var list = root.querySelector('[data-result-list]'), bar = root.querySelector('[data-progress-bar]');
    var actions = root.querySelector('[data-terminal-actions]'), steps = document.querySelector('[data-flow-steps]');
    function row(name, size, fmt, loading, url) {
      var li = document.createElement('li');
      li.className = 'result-file-row status-' + (loading ? 'running' : 'done');
      li.innerHTML = '<span class="result-file-icon' + (loading ? ' is-loading' : '') + '" aria-hidden="true">' + (loading ? '<span class="spinner"></span>' : fmt) + '</span>' +
        '<span class="result-file-meta"><strong></strong><small></small></span><span class="result-file-state">' + (loading ? 'В работе' : 'Готово') + '</span>' +
        '<span class="result-file-action">' + (url ? '<a class="button button-secondary button-small" download>Скачать</a>' : '') + '</span>';
      li.querySelector('strong').textContent = name; li.querySelector('small').textContent = size;
      if (url) { var a = li.querySelector('a'); a.href = url; a.setAttribute('download', name); }
      return li;
    }
    function stage(done) {
      shell.className = 'tool-form-wrap result-workspace status-' + (done ? 'done' : 'running');
      label.textContent = done ? 'Готово' : 'Обрабатываем';
      text.textContent = done ? 'Готово. Можно скачать результат.' : 'Файл обрабатывается, страница обновится сама.';
      if (bar) bar.hidden = done;
      list.textContent = '';
      list.appendChild(done ? row(outName, res.size, res.fmt, false, P + '/results/' + res.file) : row(job.name || 'document', job.size ? kb(job.size) : '', res.fmt, true));
      actions.textContent = '';
      if (done) { var a = document.createElement('a'); a.className = 'button secondary'; a.href = P + '/ru/'; a.textContent = 'Конвертировать ещё'; actions.appendChild(a); }
      if (steps) {
        var s2 = steps.querySelector('[data-flow-step="2"]'), s3 = steps.querySelector('[data-flow-step="3"]');
        s2.classList.remove('is-current', 'is-complete'); s3.classList.remove('is-current', 'is-complete');
        if (done) { s2.classList.add('is-complete'); s3.classList.add('is-current', 'is-complete'); } else s2.classList.add('is-current');
      }
    }
    stage(false);
    setTimeout(function () { stage(true); }, 2600);
  }
})();
