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
    return '<div class="g-ch"><div class="g-ch-n" style="color:' + COL[n] + '">' + n + '</div>' + svg(n) + '<small>' + CHORDS[n].tip + '</small></div>';
  }).join('');

  // ---- такты
  var bars = [];
  var secHtml = '';
  song.sections.forEach(function (sec, si) {
    var start = bars.length;
    sec.bars.forEach(function (b) { bars.push({ chords: barChords(b), sec: si }); });
    secHtml += '<div class="g-sec"><div class="g-sec-h"><b>' + sec.name + '</b>' +
      '<button type="button" class="g-btn g-loop" data-sec="' + si + '">↻ петля</button></div><div class="g-bars">' +
      sec.bars.map(function (b, k) {
        var cs = barChords(b), groups = [];
        cs.forEach(function (c, j) { if (j && groups[groups.length - 1].c === c) groups[groups.length - 1].n++; else groups.push({ c: c, n: 1, j: j }); });
        return '<span class="g-bar" data-i="' + (start + k) + '" style="border-color:' + COL[cs[0]] + '">' +
          groups.map(function (g) { return '<i data-j="' + g.j + '" data-n="' + g.n + '" style="color:' + COL[g.c] + '">' + g.c + '</i>'; }).join('') + '</span>';
      }).join('') + '</div></div>';
  });
  $('g-sections').innerHTML = secHtml;
  var barEls = [].slice.call(document.querySelectorAll('.g-bar'));

  // ---- бой
  var avail = Object.keys(STRUMS).filter(function (k) { return STRUMS[k].s.length === slots; });
  var strumKey = avail.indexOf(song.strum) >= 0 ? song.strum : avail[0];
  var strumEl = $('g-strum');
  function drawStrum() {
    strumEl.innerHTML = STRUMS[strumKey].s.map(function (a, i) {
      var ch = a === 'D' ? '↓' : a === 'U' ? '↑' : a === 'X' ? '✕' : '·';
      return '<div class="g-sl' + (a ? ' hit' : '') + '"><div class="g-a">' + ch + '</div><div class="g-c">' + SLOT_LBL[i] + '</div></div>';
    }).join('');
  }
  $('g-strums').innerHTML = avail.map(function (k) {
    return '<button type="button" class="g-btn g-strm' + (k === strumKey ? ' on' : '') + '" data-s="' + k + '">' + STRUMS[k].title + '</button>';
  }).join('');
  drawStrum();
  $('g-strums').onclick = function (e) {
    var b = e.target.closest('.g-strm'); if (!b) return;
    strumKey = b.dataset.s;
    [].forEach.call(document.querySelectorAll('.g-strm'), function (x) { x.classList.toggle('on', x === b); });
    drawStrum();
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
    $('g-sec-now').textContent = song.sections[bars[i].sec].name;
  }

  function step() {
    var slot = ((eighth % slots) + slots) % slots;
    var stEls = strumEl.children;
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

  function start() {
    var r = range();
    barIdx = r[0]; eighth = -slots; running = true;
    $('g-play').textContent = '⏸ Стоп';
    nextAt = performance.now();
    if (actx && actx.state === 'suspended') actx.resume();
    step();
  }
  function stop() {
    running = false; clearTimeout(timer);
    $('g-play').textContent = '▶ Играть';
    [].forEach.call(strumEl.children, function (el) { el.classList.remove('cur'); });
    barEls.forEach(function (el) { el.classList.remove('cur'); [].forEach.call(el.children, function (g) { g.classList.remove('now'); }); });
    $('g-now').textContent = '—'; $('g-now').style.color = ''; $('g-next').textContent = '—'; $('g-sec-now').textContent = '';
  }
  $('g-play').onclick = function () { running ? stop() : start(); };

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
})();
