// /guitar/guitar-player.js — гитара, которая играет бой в браузере.
// Данные (аккорды, бой, BPM, структура) приходят из PHP как JSON: /guitar/api?song=<slug>.
// Звук: сэмплы гитары WebAudioFont; пока они грузятся или недоступны — запасной синтез струны (Karplus–Strong).
// Ритм: свой планировщик с запасом по времени AudioContext (точнее таймеров setTimeout), темп меняется на ходу.
(function () {
  'use strict';
  var root = document.getElementById('gp');
  if (!root) return;

  var $ = function (id) { return document.getElementById(id); };
  var COL = window.GUITAR_COL || {};
  var OPEN = [40, 45, 50, 55, 59, 64];            // E2 A2 D3 G3 B3 E4: от 6-й струны к 1-й
  var NAMES = ['E', 'A', 'D', 'G', 'B', 'e'];
  var WF_PLAYER = 'https://surikov.github.io/webaudiofont/npm/dist/WebAudioFontPlayer.js';
  var WF_PRESET = 'https://surikov.github.io/webaudiofontdata/sound/0250_Acoustic_Guitar_sf2_file.js';
  var WF_VAR = '_tone_0250_Acoustic_Guitar_sf2_file';
  var LOOKAHEAD = 0.25;

  var data = null, seq = [], hits = [], beats = 4, sub = 2, slots = 8;
  var bpm = 50, mode = 'listen', loop = '';          // loop: '', 'bar' | 'sec'
  var running = false, finished = false, endTime = 0;
  var barPtr = 0, slotPtr = 0, nextTime = 0, countLeft = 0, loopBar = 0, loopRange = [0, 0], timer = 0, raf = 0;
  var vq = [], shownBar = -1, shownChord = '', rowEls = [], strEls = [], pick = null, fromIdx = 0;
  var ac = null, master = null, sampler = { state: 'idle', wf: null, preset: null };

  function status(t) { $('gp-status').textContent = t || ''; }

  // ---------- данные ----------
  fetch(root.dataset.api + '?song=' + encodeURIComponent(root.dataset.slug), { headers: { Accept: 'application/json' } })
    .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
    .then(init)
    .catch(function (e) { status('Не удалось загрузить данные плеера: ' + e.message); });

  function barChords(b) { var a = []; for (var i = 0; i < beats; i++) a.push(typeof b === 'string' ? b : b[i]); return a; }

  function init(d) {
    data = d; beats = d.meter.beats; sub = d.meter.sub; slots = d.slots; bpm = d.bpm.default;

    // последовательность тактов по порядку частей песни
    var byName = {};
    d.sections.forEach(function (s, i) { if (!(s.name in byName)) byName[s.name] = i; });
    var order = d.order && d.order.length ? d.order.map(function (n) { return byName[n]; }).filter(function (i) { return i != null; }) : d.sections.map(function (s, i) { return i; });
    var inst = -1, seen = {}, froms = [];
    order.forEach(function (si) {
      var sec = d.sections[si], lineOf = [];
      if (sec.lines) sec.lines.forEach(function (c, li) { for (var k = 0; k < c; k++) lineOf.push(li); });
      inst++; seen[sec.name] = (seen[sec.name] || 0) + 1;
      froms.push({ idx: seq.length, label: sec.name + (seen[sec.name] > 1 ? ' (' + seen[sec.name] + ')' : '') });
      sec.bars.forEach(function (b, bi) {
        seq.push({ sec: si, inst: inst, name: sec.name, bar: bi, line: sec.lines ? lineOf[bi] : bi,
          lines: sec.lines ? sec.lines.length : sec.bars.length, chords: barChords(b) });
      });
    });

    buildStrings();
    buildBpmButtons();
    $('gp-from').innerHTML = froms.map(function (f) { return '<option value="' + f.idx + '">' + f.label + '</option>'; }).join('');
    $('gp-from').onchange = function () { fromIdx = +this.value; if (running) jumpTo(fromIdx); else preview(fromIdx); };
    var sel = $('g-strum-sel');
    sel.addEventListener('change', function () { readStrum(); if (shownBar >= 0) renderRow(shownBar); else preview(fromIdx); });
    readStrum();
    bind();
    setMode('listen');
    setBpm(bpm);
    preview(0);
    status(seq.length ? '' : 'Нет данных о тактах');
  }

  function readStrum() {
    var key = $('g-strum-sel').value || data.strum, def = data.strums[key];
    hits = [];
    for (var i = 0; i < slots; i++) hits.push('');
    if (def) def.hits.forEach(function (h) { hits[h.slot] = h.type; });
  }

  // ---------- интерфейс ----------
  function buildStrings() {
    var fb = $('gp-fb'), h = '';
    for (var i = 0; i < 6; i++) {
      h += '<div class="gp-s" data-i="' + i + '"><span class="gp-fr"></span><i class="gp-line" style="--w:' + (3.4 - i * 0.45).toFixed(2) + 'px"></i><span class="gp-nm">' + NAMES[i] + '</span></div>';
    }
    fb.innerHTML = h + '<div class="gp-pick" id="gp-pick"></div>';
    strEls = [].slice.call(fb.querySelectorAll('.gp-s'));
    pick = $('gp-pick');
  }

  function buildBpmButtons() {
    var box = $('gp-bpm-btns'), h = '';
    data.bpm.presets.forEach(function (v) { h += '<button type="button" class="g-btn gp-bp" data-v="' + v + '">' + v + '</button>'; });
    if (data.bpm.original) h += '<button type="button" class="g-btn gp-bp" data-v="' + data.bpm.original + '" title="Темп записи">Оригинал ' + data.bpm.original + '</button>';
    box.innerHTML = h;
    box.onclick = function (e) { var b = e.target.closest('.gp-bp'); if (b) setBpm(+b.dataset.v); };
  }

  function setBpm(v) {
    bpm = Math.max(data.bpm.min, Math.min(data.bpm.max, Math.round(v)));
    $('gp-bpm').textContent = bpm + (mode === 'watch' ? ' (×0,5)' : '');
    [].forEach.call(document.querySelectorAll('.gp-bp'), function (b) { b.classList.toggle('on', +b.dataset.v === bpm); });
  }

  function setMode(m) {
    mode = m;
    [].forEach.call($('gp-modes').children, function (b) { b.classList.toggle('on', b.dataset.mode === m); });
    // настройки по умолчанию для режима; галочками их можно поменять
    $('gp-snd').checked = m !== 'along';
    $('gp-met').checked = m === 'along';
    setBpm(bpm);
  }

  function tempo() { return bpm * (mode === 'watch' ? 0.5 : 1); }

  function renderFret(chord) {
    var def = data.chords[chord];
    strEls.forEach(function (el, i) {
      var f = def ? def.frets[i] : -1;
      el.classList.toggle('mute', f < 0);
      el.querySelector('.gp-fr').textContent = f < 0 ? '×' : String(f);
      el.style.setProperty('--c', COL[chord] || '#f9c940');
    });
  }

  function chordGroups(bar) {
    var g = [];
    bar.chords.forEach(function (c, j) { if (j && g[g.length - 1].c === c) g[g.length - 1].n++; else g.push({ c: c, j: j, n: 1 }); });
    return g;
  }

  function renderRow(bi) {
    var bar = seq[bi], h = '', firstOf = {};
    chordGroups(bar).forEach(function (g) { firstOf[g.j * sub] = g.c; });
    for (var s = 0; s < slots; s++) {
      var t = hits[s], chord = bar.chords[Math.floor(s / sub)];
      var arrow = t === 'down' ? '↓' : t === 'up' ? '↑' : t === 'mute' ? '✕' : '·';
      var lab = s % sub === 0 ? String(s / sub + 1) : (sub === 3 ? (s % sub === 1 ? 'и' : 'а') : 'и');
      h += '<div class="g-sl' + (t ? ' hit' : '') + '" style="border-bottom-color:' + (COL[chord] || '') + '"><div class="g-a">' + arrow + '</div><div class="g-c">' + lab + '</div>' +
        '<div class="g-k" style="color:' + (COL[chord] || '') + '">' + (firstOf[s] ? firstOf[s] : '&nbsp;') + '</div></div>';
    }
    $('gp-row').innerHTML = h;
    rowEls = [].slice.call($('gp-row').children);
  }

  function nextHitSlot(from) { for (var s = from + 1; s < slots; s++) if (hits[s]) return s; return -1; }

  // следующий аккорд, отличающийся от текущего (с учётом цикла)
  function nextChordAfter(bi, beat) {
    var cur = seq[bi].chords[beat], b = bi, j = beat;
    for (var n = 0; n < beats * 64; n++) {
      j++;
      if (j >= beats) { j = 0; b = advance(b); if (b < 0) return null; if (b === bi && loop === 'bar') return null; }
      var c = seq[b].chords[j];
      if (c !== cur) return c;
    }
    return null;
  }

  function updateLabels(bi, beat) {
    var bar = seq[bi], chord = bar.chords[beat];
    if (chord !== shownChord) {
      shownChord = chord;
      $('gp-now').textContent = chord; $('gp-now').style.color = COL[chord] || '';
      renderFret(chord);
    }
    var nx = nextChordAfter(bi, beat);
    $('gp-next').textContent = nx || '—'; $('gp-next').style.color = nx ? (COL[nx] || '') : '';
    $('gp-sec').textContent = bar.name + (bar.lines > 1 ? ' · строка ' + (bar.line + 1) + ' из ' + bar.lines : '') + ' · такт ' + (bi + 1) + ' из ' + seq.length;
  }

  // показать такт без звука (до старта или при переходах)
  function preview(bi) {
    bi = Math.max(0, Math.min(seq.length - 1, bi)); fromIdx = bi; shownBar = bi; shownChord = '';
    $('gp-from').value = String(sectionStartOf(bi));
    renderRow(bi); updateLabels(bi, 0);
    rowEls.forEach(function (el) { el.classList.remove('cur', 'next'); });
  }

  function sectionStartOf(bi) { var i = bi; while (i > 0 && seq[i - 1].inst === seq[bi].inst) i--; return i; }
  function sectionEndOf(bi) { var i = bi; while (i + 1 < seq.length && seq[i + 1].inst === seq[bi].inst) i++; return i; }

  function bind() {
    $('gp-modes').onclick = function (e) { var b = e.target.closest('button'); if (b) setMode(b.dataset.mode); };
    $('gp-play').onclick = function () { running ? stop() : start(); };
    $('gp-prev').onclick = function () { step(-1); };
    $('gp-nextbar').onclick = function () { step(1); };
    $('gp-bpm-dec').onclick = function () { setBpm(bpm - 2); };
    $('gp-bpm-inc').onclick = function () { setBpm(bpm + 2); };
    $('gp-loop-bar').onclick = function () { setLoop(loop === 'bar' ? '' : 'bar'); };
    $('gp-loop-sec').onclick = function () { setLoop(loop === 'sec' ? '' : 'sec'); };
  }

  function setLoop(l) {
    loop = l;
    $('gp-loop-bar').classList.toggle('on', l === 'bar');
    $('gp-loop-sec').classList.toggle('on', l === 'sec');
    var cur = shownBar >= 0 ? shownBar : fromIdx;
    loopBar = cur; loopRange = [sectionStartOf(cur), sectionEndOf(cur)];
  }

  function step(d) {
    var to = Math.max(0, Math.min(seq.length - 1, (shownBar >= 0 ? shownBar : fromIdx) + d));
    if (running) jumpTo(to); else preview(to);
    if (loop) setLoop(loop);
  }

  function jumpTo(bi) { barPtr = bi; slotPtr = 0; finished = false; vq = vq.filter(function (e) { return e.t < ac.currentTime; }); }

  // ---------- звук ----------
  function ensureAudio() {
    if (!ac) {
      var C = window.AudioContext || window.webkitAudioContext;
      ac = new C();
      master = ac.createGain(); master.gain.value = 0.9;
      var comp = ac.createDynamicsCompressor();
      master.connect(comp); comp.connect(ac.destination);
    }
    if (ac.state === 'suspended') ac.resume();
  }

  function loadScript(url, cb) {
    var s = document.createElement('script');
    s.src = url; s.async = true;
    s.onload = function () { cb(true); };
    s.onerror = function () { cb(false); };
    document.head.appendChild(s);
  }

  function loadSampler() {
    if (sampler.state !== 'idle') return;
    sampler.state = 'loading'; status('Загружаю гитару…');
    var fail = function () { if (sampler.state === 'loading') { sampler.state = 'failed'; status('Звук: синтез струны (сэмплы не загрузились)'); } };
    var t = setTimeout(fail, 10000);
    loadScript(WF_PLAYER, function (ok) {
      if (!ok || !window.WebAudioFontPlayer) { clearTimeout(t); return fail(); }
      sampler.wf = new window.WebAudioFontPlayer();
      sampler.wf.loader.startLoad(ac, WF_PRESET, WF_VAR);
      sampler.wf.loader.waitLoad(function () {
        clearTimeout(t);
        sampler.preset = window[WF_VAR];
        if (sampler.preset) { sampler.state = 'ready'; status('Звук: гитара (сэмплы)'); } else fail();
      });
    });
  }

  // запасной звук: щипок струны (Karplus–Strong), буфер на каждую ноту считается один раз
  var ksCache = {};
  function ks(midi) {
    if (ksCache[midi]) return ksCache[midi];
    var sr = ac.sampleRate, f = 440 * Math.pow(2, (midi - 69) / 12), N = Math.max(2, Math.round(sr / f)), len = Math.floor(sr * 2.4);
    var buf = ac.createBuffer(1, len, sr), out = buf.getChannelData(0), line = new Float32Array(N), seed = midi * 7919 + 13, i, p;
    for (i = 0; i < N; i++) { seed = (seed * 9301 + 49297) % 233280; line[i] = seed / 233280 * 2 - 1; }
    for (p = 0; p < 2; p++) for (i = 0; i < N; i++) line[i] = 0.5 * (line[i] + line[(i + 1) % N]);
    var idx = 0, decay = Math.max(0.985, 0.9975 - (midi - 40) * 0.00018), peak = 0;
    for (i = 0; i < len; i++) {
      var cur = line[idx], nxt = line[(idx + 1) % N];
      line[idx] = decay * 0.5 * (cur + nxt);
      out[i] = cur; if (Math.abs(cur) > peak) peak = Math.abs(cur);
      idx = (idx + 1) % N;
    }
    var k = peak ? 0.8 / peak : 1;
    for (i = 0; i < len; i++) out[i] *= k;
    return (ksCache[midi] = buf);
  }

  function note(midi, when, dur, vol) {
    if (sampler.state === 'ready') { sampler.wf.queueWaveTable(ac, master, sampler.preset, when, midi, dur, vol); return; }
    var buf = ks(midi), src = ac.createBufferSource(), g = ac.createGain(), end = when + Math.min(buf.duration, dur + 0.5);
    src.buffer = buf;
    g.gain.setValueAtTime(vol * 0.9, when);
    g.gain.exponentialRampToValueAtTime(0.001, end);
    src.connect(g); g.connect(master);
    src.start(when); src.stop(end + 0.02);
  }

  function click(when, accent) {
    var o = ac.createOscillator(), g = ac.createGain();
    o.frequency.value = accent ? 1600 : 1050;
    g.gain.setValueAtTime(accent ? 0.35 : 0.2, when);
    g.gain.exponentialRampToValueAtTime(0.001, when + 0.06);
    o.connect(g); g.connect(ac.destination);
    o.start(when); o.stop(when + 0.07);
  }

  // какие струны и в каком порядке задевает движение руки
  function strings(chord, type) {
    var def = data.chords[chord], idx = [];
    if (!def) return idx;
    def.frets.forEach(function (f, i) { if (f >= 0) idx.push(i); });
    if (type === 'up') return idx.slice(-4).reverse();          // вверх — тонкие струны, от 1-й вниз к басовым
    return idx;                                                  // вниз — от басовой к тонким
  }

  function strum(chord, type, when, slotDur, accent) {
    var def = data.chords[chord]; if (!def) return;
    var stag = Math.min(0.026, slotDur * 0.12), list = strings(chord, type), vol, dur;
    if (type === 'mute') { list = strings(chord, 'down'); vol = 0.28; dur = 0.05; stag = 0.004; }
    else { vol = (accent ? 0.95 : 0.75) * (type === 'up' ? 0.82 : 1); dur = Math.max(0.5, slotDur * 2.4); }
    list.forEach(function (si, k) { note(OPEN[si] + def.frets[si], when + k * stag, dur, vol * (1 - k * 0.025)); });
  }

  // ---------- планировщик ----------
  function advance(b) {
    if (loop === 'bar') return loopBar;
    if (loop === 'sec') return b + 1 > loopRange[1] ? loopRange[0] : b + 1;
    return b + 1 < seq.length ? b + 1 : -1;
  }

  function schedule() {
    if (countLeft > 0) {
      var d0 = 60 / tempo();
      click(nextTime, countLeft === beats);
      vq.push({ t: nextTime, type: 'count', n: countLeft });
      countLeft--; nextTime += d0; return;
    }
    var bar = seq[barPtr], slot = slotPtr, beat = Math.floor(slot / sub), hit = hits[slot];
    var dur = 60 / tempo() / sub, onBeat = slot % sub === 0, chord = bar.chords[beat];
    if (hit && $('gp-snd').checked) strum(chord, hit, nextTime, dur, onBeat);
    if (onBeat && $('gp-met').checked) click(nextTime, slot === 0);
    vq.push({ t: nextTime, type: 'slot', bar: barPtr, slot: slot, beat: beat, hit: hit, chord: chord, dur: dur });
    nextTime += dur; slotPtr++;
    if (slotPtr >= slots) {
      slotPtr = 0; barPtr = advance(barPtr);
      if (barPtr < 0) { finished = true; endTime = nextTime; barPtr = 0; }
    }
  }

  function pump() {
    if (!running) return;
    while (!finished && nextTime < ac.currentTime + LOOKAHEAD) schedule();
  }

  // ---------- показ того, что уже звучит ----------
  function show(ev, quiet) {
    if (ev.type === 'count') { $('gp-sec').textContent = 'Приготовься… ' + (beats - ev.n + 1); return; }
    if (ev.bar !== shownBar) { shownBar = ev.bar; renderRow(ev.bar); }
    updateLabels(ev.bar, ev.beat);
    var nx = nextHitSlot(ev.slot);
    rowEls.forEach(function (el, i) {
      el.classList.toggle('cur', i === ev.slot);
      el.classList.toggle('next', i === nx);
      el.classList.toggle('down-now', i === ev.slot && ev.hit === 'down');
      el.classList.toggle('up-now', i === ev.slot && ev.hit === 'up');
    });
    if (ev.hit && !quiet) animate(ev);
  }

  function animate(ev) {
    var list = strings(ev.chord, ev.hit === 'mute' ? 'down' : ev.hit), stag = ev.hit === 'mute' ? 4 : Math.min(26, ev.dur * 120);
    var col = COL[ev.chord] || '#f9c940';
    list.forEach(function (si, k) {
      setTimeout(function () {
        var el = strEls[si]; if (!el) return;
        el.style.setProperty('--c', col);
        el.classList.remove('on'); void el.offsetWidth; el.classList.add('on');
        setTimeout(function () { el.classList.remove('on'); }, 280);
      }, k * stag);
    });
    // медиатор: вниз — сверху вниз, вверх — снизу вверх
    var down = ev.hit !== 'up', box = $('gp-fb'), span = Math.max(0, box.clientHeight - 24);
    pick.classList.toggle('up', !down); pick.classList.add('go');
    pick.style.transition = 'none'; pick.style.top = (down ? 6 : 6 + span) + 'px';
    void pick.offsetWidth;
    pick.style.transition = 'top ' + Math.max(40, stag * 5) + 'ms linear';
    pick.style.top = (down ? 6 + span : 6) + 'px';
  }

  function frame() {
    if (!running) return;
    var lat = Math.min(0.2, ac.outputLatency || ac.baseLatency || 0), now = ac.currentTime - lat;
    while (vq.length && vq[0].t <= now) { var ev = vq.shift(); show(ev, now - ev.t > 0.3); }
    if (finished && !vq.length && ac.currentTime > endTime + 0.4) { stop(); return; }
    raf = requestAnimationFrame(frame);
  }

  function start() {
    if (!seq.length) return;
    ensureAudio(); loadSampler();
    running = true; finished = false; vq = []; shownBar = -1; shownChord = '';
    barPtr = fromIdx; slotPtr = 0; countLeft = mode === 'along' ? beats : 0;
    if (loop) setLoop(loop);
    nextTime = ac.currentTime + 0.1;
    $('gp-play').textContent = '⏹ Стоп';
    timer = setInterval(pump, 25); pump();
    raf = requestAnimationFrame(frame);
  }

  function stop() {
    var last = shownBar >= 0 ? shownBar : fromIdx;
    running = false; clearInterval(timer); cancelAnimationFrame(raf); vq = [];
    $('gp-play').textContent = '▶ Играть';
    strEls.forEach(function (el) { el.classList.remove('on'); });
    pick.classList.remove('go');
    preview(last);
  }
})();
