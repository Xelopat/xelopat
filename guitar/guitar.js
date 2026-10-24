// /guitar/guitar.js — схемы аккордов, бой по аккордам и тренажёр с записью
(function () {
  // Струны: 6-я (низкая E) → 1-я; -1 не играть, 0 открытая; f — пальцы (1 указательный … 4 мизинец)
  var CHORDS = {
    Am: { fr: [-1, 0, 2, 2, 1, 0], f: [0, 0, 2, 3, 1, 0] },
    Em: { fr: [0, 2, 2, 0, 0, 0], f: [0, 2, 3, 0, 0, 0] },
    G:  { fr: [3, 2, 0, 0, 0, 3], f: [2, 1, 0, 0, 0, 3] },
    D:  { fr: [-1, -1, 0, 2, 3, 2], f: [0, 0, 0, 1, 3, 2] },
    Dm: { fr: [-1, -1, 0, 2, 3, 1], f: [0, 0, 0, 2, 3, 1] },
    D7: { fr: [-1, -1, 0, 2, 1, 2], f: [0, 0, 0, 2, 1, 3] },
    C:  { fr: [-1, 3, 2, 0, 1, 0], f: [0, 3, 2, 0, 1, 0] }
  };
  var COL = { Am: '#ff8fa3', Em: '#f9c940', G: '#61d1ad', D: '#5ec4ff', Dm: '#9d8ff0', D7: '#7fd8ff', C: '#c3a6ff' };

  // Бой: слоты внутри такта; D — вниз, U — вверх, X — приглушённый удар.
  // Размер песни: song.meter = {beats, sub} — долей в такте и слотов на долю (по умолчанию 4×2 = 4/4 восьмыми;
  // 4×3 — это 12/8: четыре «пульса» по три восьмых).
  var STRUMS = {
    easy:   { title: 'Простой: ↓ на каждую долю',  s: ['D', '', 'D', '', 'D', '', 'D', ''] },
    six:    { title: '«Шестёрка»',                 s: ['D', '', 'D', 'U', '', 'U', 'D', 'U'] },
    eight:  { title: '«Восьмёрка»',                s: ['D', 'U', 'D', 'U', 'D', 'U', 'D', 'U'] },
    muted:  { title: 'С приглушкой',               s: ['D', 'U', 'X', 'U', 'D', 'U', 'X', 'U'] },
    easy12: { title: 'Простой: ↓ на каждый пульс', s: ['D', '', '', 'D', '', '', 'D', '', '', 'D', '', ''] },
    // схема автора разбора: ↓-↓-↑↑↓-↓↑↓↑ на такт; при смене аккорда посреди такта слоты 0–2 играет первый аккорд, 3–11 второй
    pat12:  { title: 'По схеме автора',            s: ['D', '', 'D', '', 'U', 'U', 'D', '', 'D', 'U', 'D', 'U'] }
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

  var meter = song.meter || { beats: 4, sub: 2 };
  var beats = meter.beats, sub = meter.sub, slots = beats * sub;
  var SLOT_LBL = slotLabels(beats, sub);
  var api = {};
  var audio = song.audio && song.audio.timeline ? song.audio : null;

  // ---- аккорды песни
  var used = [];
  song.sections.forEach(function (sec) {
    sec.bars.forEach(function (b) {
      (typeof b === 'string' ? [b] : b).forEach(function (c) { if (used.indexOf(c) < 0) used.push(c); });
    });
  });
  $('g-chords').innerHTML = used.map(function (n) {
    return '<div class="g-ch"><div class="g-ch-n" style="color:' + COL[n] + '">' + n + '</div>' + svg(n) + '</div>';
  }).join('');

  // ---- выбор боя (список)
  var avail = Object.keys(STRUMS).filter(function (k) { return STRUMS[k].s.length === slots; });
  var strumKey = avail.indexOf(song.strum) >= 0 ? song.strum : avail[0];
  var sel = $('g-strum-sel');
  sel.innerHTML = avail.map(function (k) {
    return '<option value="' + k + '"' + (k === strumKey ? ' selected' : '') + '>' + STRUMS[k].title + '</option>';
  }).join('');

  // ряд из слотов боя: arrow сверху, пульс/доля посередине, аккорд снизу (если известен)
  function rowHtml(chordAt, runAt, mineRun, curSlot) {
    var pat = STRUMS[strumKey].s, out = '';
    for (var i = 0; i < slots; i++) {
      var a = pat[i], ch = chordAt ? chordAt[i] : '';
      var arrow = a === 'D' ? '↓' : a === 'U' ? '↑' : a === 'X' ? '✕' : '·';
      var cls = 'g-sl' + (a ? ' hit' : '') + (chordAt && runAt[i] === mineRun ? ' mine' : chordAt ? ' dim' : '') + (i === curSlot ? ' cur' : '');
      var first = ch && (i === 0 || chordAt[i - 1] !== ch);
      out += '<div class="' + cls + '"' + (ch ? ' style="border-bottom-color:' + COL[ch] + '"' : '') + '>' +
        '<div class="g-a">' + arrow + '</div><div class="g-c">' + SLOT_LBL[i] + '</div>' +
        '<div class="g-k"' + (ch ? ' style="color:' + COL[ch] + '"' : '') + '>' + (first ? ch : '&nbsp;') + '</div></div>';
    }
    return out;
  }

  var staticRows = $('g-strum-rows');
  function drawStatic() { if (staticRows) staticRows.innerHTML = '<div class="g-strum">' + rowHtml(null, null, -1, -1) + '</div>'; }
  drawStatic();

  // ---- тренажёр с записью
  var au = $('g-au');
  if (au && audio) {
    var DUR = audio.duration, runs = audio.timeline.map(function (r) {
      return { t0: r[0], ch: r[1], p0: r[2], p1: r[3], sec: r[4], line: r[5] };
    });
    runs.forEach(function (r, i) { r.t1 = i + 1 < runs.length ? runs[i + 1].t0 : DUR; });
    // такты: новый такт начинается там, где аккорд стартует с пульса 0
    var bars = [];
    runs.forEach(function (r, i) {
      if (r.p0 === 0 || !bars.length) bars.push({ sec: r.sec, line: r.line, ch: [], run: [] });
      var b = bars[bars.length - 1]; r.bar = bars.length - 1;
      for (var s = r.p0 * sub; s < r.p1 * sub; s++) { b.ch[s] = r.ch; b.run[s] = i; }
    });
    var mmss = function (t) { return Math.floor(t / 60) + ':' + ('0' + Math.floor(t % 60)).slice(-2); };

    // --- крупная шкала: окно в N секунд вокруг текущего места, клик — перемотка
    var tlz = $('g-tlz'), tin = $('g-tlz-in'), WINS = [12, 24, 48, 96], wi = 1, pps = 1;
    function layout() {
      var w = tlz.clientWidth || 600; pps = w / WINS[wi];
      tin.style.width = DUR * pps + 'px';
      var h = '';
      runs.forEach(function (r) {
        h += '<div class="g-seg' + (r.p0 === 0 ? ' bs' : '') + '" style="left:' + r.t0 * pps + 'px;width:' + Math.max(1, (r.t1 - r.t0) * pps - 2) + 'px;background:' + COL[r.ch] + '">' +
          '<b>' + r.ch + '</b></div>';
      });
      for (var t = 0; t < DUR; t += 5) h += '<div class="g-tick" style="left:' + t * pps + 'px"><span>' + mmss(t) + '</span></div>';
      tin.innerHTML = h;
      $('g-zlab').textContent = WINS[wi] + ' с';
    }
    $('g-zin').onclick = function () { if (wi > 0) { wi--; layout(); } };
    $('g-zout').onclick = function () { if (wi < WINS.length - 1) { wi++; layout(); } };
    window.addEventListener('resize', layout);
    tlz.onclick = function (e) {
      var r = tlz.getBoundingClientRect();
      au.currentTime = Math.max(0, Math.min(DUR - 0.1, au.currentTime + (e.clientX - r.left - r.width / 2) / pps));
    };

    // --- обзор всей песни по частям
    var ov = $('g-ov'), ovHtml = '', blk = null;
    runs.forEach(function (r) {
      if (!blk || blk.sec !== r.sec || (r.sec !== 0 && r.line < blk.lastLine)) { blk = { sec: r.sec, t0: r.t0, lastLine: r.line }; ovHtml += '|' + blk.sec + ',' + r.t0; }
      blk.lastLine = r.line;
    });
    var starts = ovHtml.split('|').filter(Boolean).map(function (x) { var p = x.split(','); return { sec: +p[0], t0: +p[1] }; });
    var SECCOL = ['#5a5f6e', '#6c7bd6', '#d98a3d'];
    ov.innerHTML = starts.map(function (b, i) {
      var t1 = i + 1 < starts.length ? starts[i + 1].t0 : DUR;
      return '<div class="g-ovb" style="left:' + b.t0 / DUR * 100 + '%;width:' + (t1 - b.t0) / DUR * 100 + '%;background:' + SECCOL[b.sec % 3] + '"><span>' + song.sections[b.sec].name + '</span></div>';
    }).join('') + '<div class="g-ovph" id="g-ovph"></div>';
    ov.onclick = function (e) { var r = ov.getBoundingClientRect(); au.currentTime = (e.clientX - r.left) / r.width * DUR; };

    // --- управление
    var cur = 0;
    function runAt(t) {
      var r = runs[cur];
      if (t >= r.t0 && t < r.t1) return cur;
      var lo = 0, hi = runs.length - 1;
      while (lo < hi) { var mid = (lo + hi + 1) >> 1; if (runs[mid].t0 <= t) lo = mid; else hi = mid - 1; }
      return lo;
    }
    $('g-sp').onclick = function () { au.paused ? au.play() : au.pause(); };
    au.onplay = function () { $('g-sp').textContent = '⏸ Пауза'; };
    au.onpause = function () { $('g-sp').textContent = '▶ Играть'; };
    au.onended = au.onpause;
    $('g-prev').onclick = function () {
      var i = runAt(au.currentTime);
      if (au.currentTime - runs[i].t0 > 1.2 || i === 0) { au.currentTime = runs[i].t0; return; }
      au.currentTime = runs[i - 1].t0;
    };
    $('g-nextch').onclick = function () {
      var i = runAt(au.currentTime), j = i + 1;
      while (j < runs.length && runs[j].ch === runs[i].ch) j++;
      if (j < runs.length) au.currentTime = runs[j].t0;
    };
    [].forEach.call(document.querySelectorAll('.g-rate'), function (b) {
      b.onclick = function () {
        au.playbackRate = +b.dataset.r; au.preservesPitch = true;
        [].forEach.call(document.querySelectorAll('.g-rate'), function (x) { x.classList.toggle('on', x === b); });
      };
    });

    // --- что показываем для текущего аккорда
    var lastRun = -1, nowSlots = [];
    function drawRun(i) {
      var r = runs[i], b = bars[r.bar], nx = runs[i + 1], j = i + 1;
      while (j < runs.length && runs[j].ch === r.ch) j++;
      $('g-now').textContent = r.ch; $('g-now').style.color = COL[r.ch];
      $('g-next').textContent = j < runs.length ? runs[j].ch : 'конец'; $('g-next').style.color = j < runs.length ? COL[runs[j].ch] : '';
      var sec = song.sections[r.sec], lines = sec.lines ? sec.lines.length : 0;
      $('g-sec-now').textContent = sec.name + (lines > 1 ? ' · строка ' + (r.line + 1) + ' из ' + lines : '');
      $('g-bar-now').innerHTML = rowHtml(b.ch, b.run, i, -1);
      nowSlots = [].slice.call($('g-bar-now').children);
      var nb = bars[r.bar + 1];
      $('g-bar-next').innerHTML = nb ? rowHtml(nb.ch, nb.run, -1, -1) : '';
      if (api.lyrHi) api.lyrHi(sec.name, r.line);
      lastRun = i;
    }
    function frame() {
      var t = au.currentTime, i = runAt(t); cur = i;
      if (i !== lastRun) drawRun(i);
      var r = runs[i], frac = Math.min(0.999, Math.max(0, (t - r.t0) / (r.t1 - r.t0)));
      var slot = Math.floor(r.p0 * sub + frac * (r.p1 - r.p0) * sub);
      for (var k = 0; k < nowSlots.length; k++) nowSlots[k].classList.toggle('cur', k === slot);
      $('g-time').textContent = mmss(t) + ' / ' + mmss(DUR);
      tin.style.transform = 'translateX(' + (tlz.clientWidth / 2 - t * pps) + 'px)';
      $('g-ovph').style.left = t / DUR * 100 + '%';
      requestAnimationFrame(frame);
    }
    sel.onchange = function () { strumKey = sel.value; if (lastRun >= 0) drawRun(lastRun); };
    layout(); drawRun(0); requestAnimationFrame(frame);
  } else {
    sel.onchange = function () { strumKey = sel.value; drawStatic(); };
  }

  // ---- «Текст»: свой текст с аккордами, хранится только в этом браузере
  var lyrTa = $('g-lyr-ta'), lyrBox = $('g-lyr'), lyrEdit = $('g-lyr-edit');
  if (lyrTa && lyrBox && lyrEdit) {
    var KEY = 'guitar-text-' + (song.slug || song.title);
    var TOK = /^(?:[A-G][#b]?(?:m|maj|min|dim|aug|sus\d?|add\d|\d+)*(?:\/[A-G][#b]?)?|[xх×]\d+|\|)$/;
    var esc = function (t) { return t.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); };
    var load = function () { try { return localStorage.getItem(KEY) || ''; } catch (e) { return ''; } };
    var save = function (v) { try { localStorage.setItem(KEY, v); } catch (e) {} };
    var render = function () {
      var t = load();
      if (!t.trim()) { lyrBox.innerHTML = '<span class="g-note">Пока пусто. Нажми «Вставить / править» или загрузи .txt.</span>'; return; }
      var lines = t.split('\n'), out = '', head = '', idx = 0, seen = {};
      var kind = function (ln) {
        var tr = ln.trim();
        if (/^\[.*\]:?$/.test(tr)) return 'h';
        if (tr !== '' && tr.split(/\s+/).every(function (x) { return TOK.test(x); })) return 'c';
        return tr === '' ? 'e' : 't';
      };
      for (var i = 0; i < lines.length; i++) {
        var k = kind(lines[i]), ln = lines[i];
        if (k === 'h') { head = ln.replace(/[\[\]:]/g, '').replace(/\s*\d+\s*$/, '').trim(); idx = 0; out += '<div class="g-lyr-h">' + esc(ln) + '</div>'; }
        else if (k === 'c' && i + 1 < lines.length && kind(lines[i + 1]) === 't') {
          // строка песни = строка аккордов + строка слов под ней
          var key = head + '|' + idx, first = !seen[key]; seen[key] = true; idx++;
          out += '<div class="g-lyr-pair"' + (first ? ' data-key="' + esc(key) + '"' : '') + '><div class="g-lyr-ch">' + esc(ln) + '</div><div class="g-lyr-tx">' + esc(lines[i + 1]) + '</div></div>';
          i++;
        } else out += '<div class="' + (k === 'c' ? 'g-lyr-ch' : 'g-lyr-tx') + '">' + (esc(ln) || '&nbsp;') + '</div>';
      }
      lyrBox.innerHTML = out;
    };
    // подсветка строки текста, которая соответствует текущей строке записи
    api.lyrHi = function (name, i) {
      [].forEach.call(lyrBox.querySelectorAll('.g-lyr-pair'), function (el) {
        var on = name != null && el.dataset.key === name + '|' + i;
        el.classList.toggle('cur', on);
        // прокручиваем только сам блок текста, но не страницу
        if (on && !lyrBox.hidden && lyrBox.scrollHeight > lyrBox.clientHeight) lyrBox.scrollTop = Math.max(0, el.offsetTop - lyrBox.clientHeight / 3);
      });
    };
    var editing = false;
    lyrEdit.onclick = function () {
      editing = !editing;
      if (editing) { lyrTa.value = load(); lyrTa.hidden = false; lyrBox.hidden = true; lyrEdit.textContent = 'Готово'; lyrTa.focus(); }
      else { save(lyrTa.value); lyrTa.hidden = true; lyrBox.hidden = false; lyrEdit.textContent = 'Вставить / править'; render(); }
    };
    $('g-lyr-load').onclick = function () { $('g-lyr-file').click(); };
    $('g-lyr-file').onchange = function (e) {
      var f = e.target.files && e.target.files[0]; if (!f) return;
      var fr = new FileReader();
      fr.onload = function () {
        save(String(fr.result).replace(/\r\n?/g, '\n'));
        if (editing) { editing = false; lyrTa.hidden = true; lyrBox.hidden = false; lyrEdit.textContent = 'Вставить / править'; }
        render();
      };
      fr.readAsText(f, 'utf-8');
      e.target.value = '';
    };
    render();
  }
})();
