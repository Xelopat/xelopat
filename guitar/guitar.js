// /guitar/guitar.js — схемы аккордов и тренажёр «играй вместе с метрономом»
(function () {
  // Струны: 6-я (низкая E) → 1-я; -1 не играть, 0 открытая; f — пальцы (1 указательный … 4 мизинец)
  var CHORDS = {
    Am: { fr: [-1, 0, 2, 2, 1, 0], f: [0, 0, 2, 3, 1, 0], tip: 'Как Em, но сдвинуто на струну вниз + указательный на 1-й лад. 5 струн.' },
    Em: { fr: [0, 2, 2, 0, 0, 0], f: [0, 2, 3, 0, 0, 0], tip: 'Два пальца на 5-й и 4-й струнах, 2-й лад. Все 6 струн.' },
    G:  { fr: [3, 2, 0, 0, 0, 3], f: [2, 1, 0, 0, 0, 3], tip: 'Все 6 струн. Тяжело? Убери безымянный с 1-й струны — получится лёгкий G.' },
    D:  { fr: [-1, -1, 0, 2, 3, 2], f: [0, 0, 0, 1, 3, 2], tip: 'Треугольник на трёх тонких струнах. 4 струны.' },
    Dm: { fr: [-1, -1, 0, 2, 3, 1], f: [0, 0, 0, 2, 3, 1], tip: 'Как D, но указательный на 1-й струне, 1-й лад. 4 струны.' },
    C:  { fr: [-1, 3, 2, 0, 1, 0], f: [0, 3, 2, 0, 1, 0], tip: 'Лесенка пальцев. 6-ю струну не трогаем, 5 струн.' }
  };
  var COL = { Am: '#ff8fa3', Em: '#f9c940', G: '#61d1ad', D: '#5ec4ff', Dm: '#9d8ff0', C: '#c3a6ff' };

  // Бой: слоты внутри такта; D — вниз, U — вверх, X — приглушённый удар.
  // Размер песни: song.meter = {beats, sub} — долей в такте и слотов на долю (по умолчанию 4×2, т.е. 4/4 восьмыми;
  // 4×3 — это 12/8: четыре «пульса» по три восьмых).
  var STRUMS = {
    easy:   { title: 'Простой ↓ на каждую долю',  s: ['D', '', 'D', '', 'D', '', 'D', ''] },
    six:    { title: '«Шестёрка»',                s: ['D', '', 'D', 'U', '', 'U', 'D', 'U'] },
    eight:  { title: '«Восьмёрка»',               s: ['D', 'U', 'D', 'U', 'D', 'U', 'D', 'U'] },
    muted:  { title: 'С приглушкой',              s: ['D', 'U', 'X', 'U', 'D', 'U', 'X', 'U'] },
    easy12: { title: 'Простой ↓ на каждый пульс', s: ['D', '', '', 'D', '', '', 'D', '', '', 'D', '', ''] },
    pat12:  { title: 'Бой из разбора',            s: ['D', '', 'D', '', 'U', 'U', 'D', '', 'D', 'U', 'D', 'U'] }
  };
  function slotLabels(beats, sub) {
    var tail = sub === 3 ? ['и', 'а'] : ['и'], out = [];
    for (var b = 1; b <= beats; b++) { out.push(String(b)); tail.forEach(function (t) { out.push(t); }); }
    return out;
  }

  function svg(name) {
    var c = CHORDS[name], W = 120, H = 140, x0 = 22, dx = 15.2, y0 = 24, dy = 22, i;
    var s = '<svg viewBox="0 0 ' + W + ' ' + H + '" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="' + name + '">';
    s += '<rect x="' + (x0 - 1) + '" y="' + (y0 - 3) + '" width="' + (dx * 5 + 2) + '" height="4" fill="currentColor"/>';
    for (i = 0; i <= 4; i++) s += '<line x1="' + x0 + '" x2="' + (x0 + dx * 5) + '" y1="' + (y0 + dy * i) + '" y2="' + (y0 + dy * i) + '" stroke="currentColor" stroke-opacity=".45"/>';
    for (i = 0; i < 6; i++) s += '<line x1="' + (x0 + dx * i) + '" x2="' + (x0 + dx * i) + '" y1="' + y0 + '" y2="' + (y0 + dy * 4) + '" stroke="currentColor" stroke-opacity=".7" stroke-width="' + (1.6 - i * 0.15) + '"/>';
    c.fr.forEach(function (f, k) {
      var x = x0 + dx * k;
      if (f < 0) s += '<text x="' + x + '" y="' + (y0 - 9) + '" font-size="12" text-anchor="middle" fill="currentColor" opacity=".7">✕</text>';
      else if (f === 0) s += '<circle cx="' + x + '" cy="' + (y0 - 12) + '" r="4" fill="none" stroke="currentColor" opacity=".8"/>';
      else {
        var y = y0 + dy * (f - 0.5);
        s += '<circle cx="' + x + '" cy="' + y + '" r="8" fill="' + COL[name] + '"/><text x="' + x + '" y="' + (y + 4.5) + '" font-size="12" font-weight="700" text-anchor="middle" fill="#151518">' + c.f[k] + '</text>';
      }
    });
    ['E', 'A', 'D', 'G', 'B', 'e'].forEach(function (l, k) {
      s += '<text x="' + (x0 + dx * k) + '" y="' + (H - 6) + '" font-size="10" text-anchor="middle" fill="currentColor" opacity=".5">' + l + '</text>';
    });
    return s + '</svg>';
  }

  var $ = function (id) { return document.getElementById(id); };
  var song = window.GUITAR_SONG;
  if (!song) return;

  // ---- аккорды песни
  var meter = song.meter || { beats: 4, sub: 2 };
  var beats = meter.beats, sub = meter.sub, slots = beats * sub;
  var SLOT_LBL = slotLabels(beats, sub);
  // такт в данных: строка — один аккорд на весь такт, массив — аккорд на каждую долю
  function barChords(b) { var a = []; for (var i = 0; i < beats; i++) a.push(typeof b === 'string' ? b : b[i]); return a; }
  var used = [];
  song.sections.forEach(function (sec) { sec.bars.forEach(function (b) { barChords(b).forEach(function (c) { if (used.indexOf(c) < 0) used.push(c); }); }); });
  $('g-chords').innerHTML = used.map(function (n) {
    return '<div class="g-ch"><div class="g-ch-n" style="color:' + COL[n] + '">' + n + '</div>' + svg(n) + '</div>';
  }).join('');

  // ---- такты и строки
  var hasLines = song.sections.some(function (sec) { return !!sec.lines; });
  var unit = hasLines ? 'строка' : 'такт';
  var bars = [], lineOf = [];
  var secHtml = '';
  function barHtml(b, i) {
    var cs = barChords(b), groups = [];
    cs.forEach(function (c, j) { if (j && groups[groups.length - 1].c === c) groups[groups.length - 1].n++; else groups.push({ c: c, n: 1, j: j }); });
    return '<span class="g-bar" data-i="' + i + '" style="border-color:' + COL[cs[0]] + '">' +
      groups.map(function (g) { return '<i data-j="' + g.j + '" data-n="' + g.n + '" style="flex:' + g.n + ';color:' + COL[g.c] + '">' + g.c + '</i>'; }).join('') + '</span>';
  }
  song.sections.forEach(function (sec, si) {
    var start = bars.length, body;
    sec.bars.forEach(function (b) { bars.push({ chords: barChords(b), sec: si }); });
    if (sec.lines) {
      var k = 0, rows = '';
      sec.lines.forEach(function (cnt, li) {
        var first = start + k, cells = '';
        for (var q = 0; q < cnt; q++, k++) { cells += barHtml(sec.bars[k], start + k); lineOf[start + k] = { first: first, last: first + cnt - 1, idx: li, total: sec.lines.length }; }
        rows += '<div class="g-lrow" data-first="' + first + '"><b class="g-ln">' + (li + 1) + '</b>' + cells + '</div>';
      });
      body = '<div class="g-lines">' + rows + '</div>';
    } else {
      sec.bars.forEach(function (b, k) { lineOf[start + k] = { first: start + k, last: start + k, idx: k, total: sec.bars.length }; });
      body = '<div class="g-bars">' + sec.bars.map(function (b, k) { return barHtml(b, start + k); }).join('') + '</div>';
    }
    secHtml += '<div class="g-sec"><div class="g-sec-h"><b>' + sec.name + '</b>' +
      '<button type="button" class="g-btn g-loop" data-sec="' + si + '">↻ петля</button></div>' + body + '</div>';
  });
  $('g-sections').innerHTML = secHtml;
  var barEls = [].slice.call(document.querySelectorAll('.g-bar'));
  var rowEls = [].slice.call(document.querySelectorAll('.g-lrow'));
  var mode = 'practice', api = {};

  // ---- бой
  var avail = Object.keys(STRUMS).filter(function (k) { return STRUMS[k].s.length === slots; });
  var strumKey = avail.indexOf(song.strum) >= 0 ? song.strum : avail[0];
  var strumList = $('g-strum-list');
  strumList.innerHTML = avail.map(function (k) {
    return '<div class="g-strmcard' + (k === strumKey ? ' on' : '') + '" data-s="' + k + '"><div class="g-strm-t">' + STRUMS[k].title + '</div><div class="g-strum">' +
      STRUMS[k].s.map(function (a, n) {
        var ch = a === 'D' ? '↓' : a === 'U' ? '↑' : a === 'X' ? '✕' : '·';
        return '<div class="g-sl' + (a ? ' hit' : '') + '"><div class="g-a">' + ch + '</div><div class="g-c">' + SLOT_LBL[n] + '</div></div>';
      }).join('') + '</div></div>';
  }).join('');
  function slotEls() { var c = strumList.querySelector('.g-strmcard.on'); return c ? [].slice.call(c.querySelectorAll('.g-sl')) : []; }
  strumList.onclick = function (e) {
    var c = e.target.closest('.g-strmcard'); if (!c) return;
    strumKey = c.dataset.s;
    [].forEach.call(strumList.children, function (x) { x.classList.toggle('on', x === c); });
  };

  // ---- метроном / проигрывание
  var bpmEl = $('g-bpm'), bpmOut = $('g-bpm-v');
  if (song.bpm_min) bpmEl.min = song.bpm_min;
  if (song.bpm_max) bpmEl.max = song.bpm_max;
  bpmEl.value = song.bpm; bpmOut.textContent = song.bpm;
  bpmEl.oninput = function () { bpmOut.textContent = bpmEl.value; };

  var actx = null, running = false, eighth = 0, timer = null, nextAt = 0, loopSec = -1, barIdx = 0, clickOn = true;
  $('g-click').onchange = function (e) { clickOn = e.target.checked; };

  function click(accent) {
    if (!clickOn) return;
    try {
      actx = actx || new (window.AudioContext || window.webkitAudioContext)();
      var o = actx.createOscillator(), g = actx.createGain();
      o.frequency.value = accent ? 1500 : 1000;
      g.gain.setValueAtTime(accent ? 0.35 : 0.2, actx.currentTime);
      g.gain.exponentialRampToValueAtTime(0.001, actx.currentTime + 0.06);
      o.connect(g); g.connect(actx.destination);
      o.start(); o.stop(actx.currentTime + 0.07);
    } catch (e) {}
  }

  function range() {
    if (loopSec < 0) return [0, bars.length - 1];
    var lo = -1, hi = -1;
    bars.forEach(function (b, i) { if (b.sec === loopSec) { if (lo < 0) lo = i; hi = i; } });
    return [lo, hi];
  }

  var curChord = '';
  function chordAt(i, beat) { return bars[i] ? bars[i].chords[beat] : null; }
  // следующий отличающийся от текущего аккорд
  function nextDifferent(i, beat) {
    var c = chordAt(i, beat), r = range();
    for (var n = 1; n < 1000; n++) {
      var b2 = beat + n, bi = i + Math.floor(b2 / beats), bb = b2 % beats;
      if (bi > r[1]) { if (loopSec < 0) return null; bi = r[0] + (bi - r[0]) % (r[1] - r[0] + 1); }
      var x = chordAt(bi, bb);
      if (x !== c) return x;
    }
    return null;
  }
  function show(i, beat) {
    barEls.forEach(function (el, k) {
      el.classList.toggle('cur', k === i);
      if (k === i) [].forEach.call(el.children, function (g) {
        var j = +g.dataset.j; g.classList.toggle('now', beat >= j && beat < j + +g.dataset.n);
      });
    });
    var c = chordAt(i, beat), name = $('g-now'), nn = $('g-next');
    name.textContent = c; name.style.color = COL[c];
    var nx = nextDifferent(i, beat);
    nn.textContent = nx || 'конец';
    var li = lineOf[i], sec = song.sections[bars[i].sec];
    rowEls.forEach(function (r) { r.classList.toggle('cur', +r.dataset.first === li.first); });
    $('g-sec-now').textContent = sec.name + (li.total > 1 ? ' · ' + unit + ' ' + (li.idx + 1) + ' из ' + li.total : '');
    var r = range(), ni = li.last + 1;
    if (ni > r[1]) ni = loopSec >= 0 ? r[0] : -1;
    var lineEl = $('g-line-next');
    if (ni < 0) lineEl.textContent = '';
    else {
      var nl = lineOf[ni], gs = [];
      for (var q = nl.first; q <= nl.last; q++) bars[q].chords.forEach(function (c) { if (gs[gs.length - 1] !== c) gs.push(c); });
      lineEl.innerHTML = 'Дальше ' + unit + (bars[ni].sec !== bars[i].sec ? ' (' + song.sections[bars[ni].sec].name + ')' : '') + ': ' +
        gs.map(function (c) { return '<b style="color:' + COL[c] + '">' + c + '</b>'; }).join(' · ');
    }
  }

  function step() {
    var slot = ((eighth % slots) + slots) % slots;
    var stEls = slotEls();
    for (var k = 0; k < stEls.length; k++) stEls[k].classList.toggle('cur', k === slot);
    var onBeat = slot % sub === 0;
    if (eighth < 0) {
      $('g-sec-now').textContent = 'Приготовься…';
    } else {
      if (slot === 0) {
        var r = range();
        if (eighth > 0) barIdx++;
        if (barIdx > r[1]) { if (loopSec >= 0) barIdx = r[0]; else { stop(); return; } }
      }
      if (onBeat) show(barIdx, slot / sub);
    }
    if (onBeat) click(slot === 0);
    eighth++;
    nextAt += 60000 / +bpmEl.value / sub;
    timer = setTimeout(step, Math.max(0, nextAt - performance.now()));
  }

  function start(from) {
    var r = range();
    barIdx = from == null ? r[0] : from; eighth = -slots; running = true;
    $('g-play').textContent = '⏸ Стоп';
    nextAt = performance.now();
    if (actx && actx.state === 'suspended') actx.resume();
    step();
  }
  function stop() {
    running = false; clearTimeout(timer);
    $('g-play').textContent = '▶ Играть';
    [].forEach.call(strumList.querySelectorAll('.g-sl.cur'), function (el) { el.classList.remove('cur'); });
    rowEls.forEach(function (r) { r.classList.remove('cur'); });
    barEls.forEach(function (el) { el.classList.remove('cur'); [].forEach.call(el.children, function (g) { g.classList.remove('now'); }); });
    $('g-now').textContent = '—'; $('g-now').style.color = ''; $('g-next').textContent = '—'; $('g-sec-now').textContent = ''; $('g-line-next').textContent = '';
  }
  $('g-play').onclick = function () { running ? stop() : start(); };
  // клик по такту — начать с него (промотка)
  $('g-sections').addEventListener('click', function (e) {
    var bar = e.target.closest('.g-bar'); if (!bar) return;
    if (mode === 'song' && api.setMode) api.setMode('practice');
    if (running) stop();
    start(+bar.dataset.i);
  });

  // петля по разделу / клик по такту — начать с него
  $('g-sections').onclick = function (e) {
    var lp = e.target.closest('.g-loop');
    if (lp) {
      var si = +lp.dataset.sec;
      loopSec = loopSec === si ? -1 : si;
      [].forEach.call(document.querySelectorAll('.g-loop'), function (b) { b.classList.toggle('on', +b.dataset.sec === loopSec); });
      if (running) stop();
      return;
    }
  };

  // ---- режим «с песней»: аудио, промотка и смены аккордов по записи
  var au = $('g-au'), modes = $('g-modes');
  if (au && song.audio && song.audio.chords) {
    var cs = song.audio.chords.map(function (c, i, arr) {
      return { t0: c[0], t1: i + 1 < arr.length ? arr[i + 1][0] : song.audio.duration, ch: c[1] };
    });
    var DUR = song.audio.duration;
    var tlEl = $('g-tl'), phEl = $('g-ph');
    cs.forEach(function (x) {
      var d = document.createElement('div');
      d.className = 'g-seg';
      d.style.left = x.t0 / DUR * 100 + '%'; d.style.width = (x.t1 - x.t0) / DUR * 100 + '%';
      d.style.background = COL[x.ch]; d.textContent = (x.t1 - x.t0) > 2.6 ? x.ch : '';
      tlEl.insertBefore(d, phEl);
    });
    var mmss = function (t) { return Math.floor(t / 60) + ':' + ('0' + Math.floor(t % 60)).slice(-2); };
    var segAt = function (t) { for (var i = cs.length - 1; i >= 0; i--) if (t >= cs[i].t0) return i; return 0; };
    tlEl.onclick = function (e) { var r = tlEl.getBoundingClientRect(); au.currentTime = (e.clientX - r.left) / r.width * DUR; };
    $('g-sp').onclick = function () { au.paused ? au.play() : au.pause(); };
    au.onplay = function () { $('g-sp').textContent = '⏸ Пауза'; };
    au.onpause = function () { $('g-sp').textContent = '▶ Играть'; };
    au.onended = au.onpause;
    $('g-prev').onclick = function () {
      var i = segAt(au.currentTime);
      au.currentTime = au.currentTime - cs[i].t0 > 1.5 || i === 0 ? cs[i].t0 : cs[i - 1].t0;
    };
    $('g-nextch').onclick = function () { var i = segAt(au.currentTime); if (i + 1 < cs.length) au.currentTime = cs[i + 1].t0; };
    [].forEach.call(document.querySelectorAll('.g-rate'), function (b) {
      b.onclick = function () {
        au.playbackRate = +b.dataset.r; au.preservesPitch = true;
        [].forEach.call(document.querySelectorAll('.g-rate'), function (x) { x.classList.toggle('on', x === b); });
      };
    });

    mode = 'song'; var lastI = -1;
    function songFrame() {
      if (mode === 'song') {
        var t = au.currentTime, i = segAt(t), c = cs[i];
        phEl.style.left = t / DUR * 100 + '%';
        $('g-time').textContent = mmss(t) + ' / ' + mmss(DUR);
        if (i !== lastI) {
          lastI = i;
          $('g-now').textContent = c.ch; $('g-now').style.color = COL[c.ch];
          $('g-next').textContent = cs[i + 1] ? cs[i + 1].ch : 'конец';
          $('g-sec-now').textContent = '';
        }
      }
      requestAnimationFrame(songFrame);
    }
    requestAnimationFrame(songFrame);

    function setMode(m) {
      mode = m;
      if (m === 'practice') au.pause(); else if (running) stop();
      lastI = -1;
      $('g-m-song').hidden = m !== 'song';
      $('g-m-practice').hidden = m !== 'practice';
      [].forEach.call(modes.children, function (b) { b.classList.toggle('on', b.dataset.m === m); });
      if (m === 'song') $('g-line-next').textContent = '';
      if (m === 'practice') { $('g-now').textContent = '—'; $('g-now').style.color = ''; $('g-next').textContent = '—'; }
    }
    api.setMode = setMode;
    modes.onclick = function (e) { var b = e.target.closest('button'); if (b) setMode(b.dataset.m); };
  }

  // ---- «Текст»: свой текст с аккордами, хранится только в этом браузере
  var lyrTa = $('g-lyr-ta'), lyrBox = $('g-lyr'), lyrEdit = $('g-lyr-edit');
  if (lyrTa && lyrBox && lyrEdit) {
    var KEY = 'guitar-text-' + (song.slug || song.title);
    var TOK = /^(?:[A-G][#b]?(?:m|maj|min|dim|aug|sus\d?|add\d|\d+)*(?:\/[A-G][#b]?)?|[x×]\d+|\|)$/;
    var esc = function (t) { return t.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); };
    var load = function () { try { return localStorage.getItem(KEY) || ''; } catch (e) { return ''; } };
    var save = function (v) { try { localStorage.setItem(KEY, v); } catch (e) {} };
    var render = function () {
      var t = load();
      if (!t.trim()) { lyrBox.innerHTML = '<span class="g-note">Пока пусто. Нажми «Вставить / править» и вставь свой текст с аккордами над словами.</span>'; return; }
      lyrBox.innerHTML = t.split('\n').map(function (ln) {
        var parts = ln.trim().split(/\s+/);
        var isChords = ln.trim() !== '' && parts.every(function (x) { return TOK.test(x); });
        return '<div class="' + (isChords ? 'g-lyr-ch' : 'g-lyr-tx') + '">' + (esc(ln) || '&nbsp;') + '</div>';
      }).join('');
    };
    var editing = false;
    lyrEdit.onclick = function () {
      editing = !editing;
      if (editing) { lyrTa.value = load(); lyrTa.hidden = false; lyrBox.hidden = true; lyrEdit.textContent = 'Готово'; lyrTa.focus(); }
      else { save(lyrTa.value); lyrTa.hidden = true; lyrBox.hidden = false; lyrEdit.textContent = 'Вставить / править'; render(); }
    };
    render();
  }
})();
