<?php
// /lisa-alisa/index.php — учёт квадратов на упаковке мебели. Редактировать может кто угодно.
declare(strict_types=1);

const LA_RATE = 18;          // ₽ за квадрат
const LA_MAX_SQUARES = 100000;

$LA_DATA_FILE = $_SERVER['DOCUMENT_ROOT'] . '/data/lisa_alisa.json';

function la_read(string $file): array {
    if (!is_file($file)) return [];
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data['entries'] ?? null) ? $data['entries'] : [];
}

function la_json(int $code, array $payload): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($_GET['api'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        la_json(200, ['entries' => (object)la_read($LA_DATA_FILE)]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        la_json(405, ['error' => 'Метод не поддерживается']);
    }

    $in = json_decode((string)file_get_contents('php://input'), true);
    $date = (string)($in['date'] ?? '');
    $raw = $in['squares'] ?? null;

    $d = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) {
        la_json(400, ['error' => 'Некорректная дата']);
    }
    if ($raw === null || $raw === '' || !is_numeric($raw)) {
        la_json(400, ['error' => 'Введите число квадратов']);
    }
    $squares = round((float)$raw, 2);
    if ($squares < 0 || $squares > LA_MAX_SQUARES) {
        la_json(400, ['error' => 'Число квадратов должно быть от 0 до ' . LA_MAX_SQUARES]);
    }

    $dir = dirname($LA_DATA_FILE);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    $fp = @fopen($LA_DATA_FILE, 'c+');
    if (!$fp) la_json(500, ['error' => 'Не удалось открыть файл данных']);
    flock($fp, LOCK_EX);
    $data = json_decode((string)stream_get_contents($fp), true);
    $entries = is_array($data['entries'] ?? null) ? $data['entries'] : [];

    if ($squares == 0) {
        unset($entries[$date]);
    } else {
        $entries[$date] = $squares;
    }
    ksort($entries);

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode(['entries' => (object)$entries], JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    la_json(200, ['entries' => (object)$entries]);
}

include $_SERVER['DOCUMENT_ROOT'] . '/header.php';
?>
<title>Лиса-Алиса</title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Inter:wght@400;500;600;700;800&display=swap');

  .la{
    --bg:#151518;
    --card:#1e1e25;
    --card-2:#24242d;
    --line:#333340;
    --line-soft:#2b2b36;
    --text:#efeff1;
    --muted:#a4a8bb;
    --fox:#ff8a3d;
    --fox-2:#ffb26b;
    --fox-soft:rgba(255,138,61,.14);
    --mint:#61d1ad;
    --gold:#f9c940;
    --bad:#ff8f8f;
    --mono:'Space Mono',ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;

    min-height:calc(100vh - 60px);
    background:
      radial-gradient(900px 380px at 85% -60px, rgba(255,138,61,.13), transparent 70%),
      radial-gradient(700px 300px at 0% 0%, rgba(97,209,173,.06), transparent 70%),
      var(--bg);
    color:var(--text);
    padding:28px 0 48px;
  }
  .la *{ box-sizing:border-box; }

  .la-wrap{ width:min(1040px, calc(100vw - 32px)); margin:0 auto; }

  .la-head{ display:flex; align-items:center; gap:16px; margin-bottom:22px; }
  .la-logo{
    width:56px; height:56px; flex:none;
    border-radius:50%;
    object-fit:cover;
    border:2px solid var(--fox);
    box-shadow:0 10px 30px rgba(255,110,40,.25);
  }
  .la-title{ margin:0; font-size:30px; line-height:1.15; font-weight:800; letter-spacing:-.01em; }

  .la-card{
    background:var(--card);
    border:1px solid var(--line);
    border-radius:16px;
    padding:18px;
  }
  .la-card h2{
    margin:0 0 14px;
    font-size:15px;
    font-weight:700;
    display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;
  }
  .la-card h2 small{ font-weight:500; color:var(--muted); font-size:13px; }

  /* ---- ввод ---- */
  .la-top{ display:grid; grid-template-columns:minmax(0,1.05fr) minmax(0,1.6fr); gap:14px; margin-bottom:14px; }

  .la-form{ display:grid; gap:12px; }
  .la-field label{ display:block; font-size:12px; color:var(--muted); margin-bottom:6px; font-weight:600; }
  .la-input{
    width:100%;
    background:var(--bg);
    border:1px solid var(--line);
    border-radius:10px;
    padding:10px 12px;
    color:var(--text);
    font:inherit; font-size:15px;
    outline:none;
    color-scheme:dark;
  }
  .la-input:focus{ border-color:var(--fox); box-shadow:0 0 0 3px var(--fox-soft); }
  .la-input.big{ font-size:30px; font-weight:800; padding:10px 14px; font-family:var(--mono); }

  .la-date-row{ display:flex; gap:8px; }
  .la-date-row .la-input{ flex:1; min-width:0; }
  .la-chip{
    border:1px solid var(--line); background:var(--bg); color:var(--muted);
    border-radius:10px; padding:0 12px; font:inherit; font-size:13px; cursor:pointer; white-space:nowrap;
  }
  .la-chip:hover{ color:var(--text); border-color:var(--fox); }

  .la-preview{ font-size:13px; color:var(--muted); min-height:18px; }
  .la-preview b{ color:var(--gold); font-weight:700; }

  .la-btns{ display:flex; gap:8px; }
  .la-btn{
    flex:1;
    border:none; border-radius:10px;
    padding:12px 14px;
    font:inherit; font-size:15px; font-weight:700;
    cursor:pointer;
    background:linear-gradient(140deg, var(--fox), #e8601f);
    color:#1a0f08;
    transition:transform .12s ease, filter .12s ease;
  }
  .la-btn:hover{ filter:brightness(1.08); }
  .la-btn:active{ transform:translateY(1px); }
  .la-btn:disabled{ opacity:.6; cursor:default; }
  .la-btn.ghost{ flex:none; background:var(--bg); border:1px solid var(--line); color:var(--muted); font-weight:600; }
  .la-btn.ghost:hover{ color:var(--bad); border-color:var(--bad); filter:none; }

  .la-msg{ font-size:13px; min-height:18px; }
  .la-msg.ok{ color:var(--mint); }
  .la-msg.err{ color:var(--bad); }

  /* ---- плитки ---- */
  .la-tiles{ display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:10px; }
  .la-tile{
    background:var(--card-2);
    border:1px solid var(--line-soft);
    border-radius:14px;
    padding:14px;
    display:flex; flex-direction:column; gap:4px;
    min-width:0;
  }
  .la-tile.main{ grid-column:1 / -1; background:linear-gradient(140deg, rgba(255,138,61,.16), rgba(255,138,61,.04)); border-color:rgba(255,138,61,.35); }
  .la-tile-k{ font-family:var(--mono); font-size:11px; color:var(--muted); text-transform:uppercase; letter-spacing:.05em; }
  .la-tile-v{ font-size:24px; font-weight:800; line-height:1.15; color:var(--gold); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .la-tile.main .la-tile-v{ font-size:36px; }
  .la-tile-s{ font-size:13px; color:var(--muted); }
  .la-tile-s b{ color:var(--text); font-weight:600; }

  /* ---- месяц ---- */
  .la-month-nav{ display:flex; align-items:center; gap:6px; }
  .la-nav-btn{
    width:32px; height:32px; border-radius:9px;
    border:1px solid var(--line); background:var(--bg); color:var(--text);
    cursor:pointer; font-size:16px; line-height:1;
  }
  .la-nav-btn:hover{ border-color:var(--fox); color:var(--fox-2); }
  .la-nav-btn:disabled{ opacity:.35; cursor:default; border-color:var(--line); color:var(--text); }
  .la-month-name{ min-width:140px; text-align:center; font-weight:700; text-transform:capitalize; }

  .la-summary{ display:flex; flex-wrap:wrap; gap:8px 18px; margin-bottom:14px; font-size:13px; color:var(--muted); }
  .la-summary b{ color:var(--text); font-weight:700; }
  .la-summary .money{ color:var(--gold); }

  .la-chart{
    display:grid;
    grid-template-columns:repeat(var(--days), minmax(0,1fr));
    align-items:end;
    gap:3px;
    height:150px;
    padding:8px 0 0;
    border-bottom:1px solid var(--line);
    margin-bottom:6px;
  }
  .la-bar{
    position:relative;
    height:100%;
    display:flex; align-items:flex-end;
    cursor:pointer;
    border-radius:4px;
  }
  .la-bar:hover{ background:rgba(255,255,255,.04); }
  .la-bar i{
    display:block; width:100%;
    min-height:2px;
    border-radius:4px 4px 1px 1px;
    background:linear-gradient(180deg, var(--fox-2), var(--fox));
  }
  .la-bar.empty i{ background:var(--line); }
  .la-bar.future i{ background:transparent; }
  .la-bar.today i{ background:linear-gradient(180deg, #ffe08a, var(--gold)); }
  .la-bar.today.empty i{ background:var(--gold); }
  .la-bar.sel::after{ content:""; position:absolute; left:50%; bottom:-6px; width:4px; height:4px; border-radius:50%; background:var(--fox-2); transform:translateX(-50%); }
  .la-axis{ display:grid; grid-template-columns:repeat(var(--days), minmax(0,1fr)); gap:3px; margin-bottom:16px; }
  .la-axis span{ font-family:var(--mono); font-size:9px; color:var(--muted); text-align:center; }
  .la-axis span.we{ color:#7f6a5c; }

  .la-tablewrap{ overflow-x:auto; }
  .la-table{ width:100%; border-collapse:collapse; font-size:14px; }
  .la-table th, .la-table td{ padding:9px 10px; border-bottom:1px solid var(--line-soft); text-align:right; white-space:nowrap; }
  .la-table th:first-child, .la-table td:first-child{ text-align:left; }
  .la-table th{ font-family:var(--mono); font-size:10px; color:var(--fox-2); text-transform:uppercase; letter-spacing:.05em; font-weight:400; }
  .la-table tbody tr{ cursor:pointer; transition:background .1s; }
  .la-table tbody tr:hover{ background:rgba(255,255,255,.03); }
  .la-table tr.sel{ background:var(--fox-soft) !important; }
  .la-table tr.zero td{ color:#666a7c; }
  .la-table tr.today td:first-child::after{ content:'сегодня'; margin-left:8px; font-size:10px; font-family:var(--mono); color:var(--gold); }
  .la-table td.money{ color:var(--gold); font-weight:600; }
  .la-table tr.zero td.money{ color:#666a7c; font-weight:400; }
  .la-table td.cum{ color:var(--muted); }
  .la-table .dow{ color:var(--muted); font-size:12px; margin-left:6px; }
  .la-table tfoot td{ font-weight:700; border-bottom:none; border-top:1px solid var(--line); }

  .la-grid2{ display:grid; grid-template-columns:minmax(0,1.5fr) minmax(0,1fr); gap:14px; margin-top:14px; }

  .la-months{ display:grid; gap:10px; }
  .la-mrow{
    display:grid; grid-template-columns:110px minmax(0,1fr); gap:10px; align-items:center;
    cursor:pointer; padding:6px; margin:-6px; border-radius:10px;
  }
  .la-mrow:hover{ background:rgba(255,255,255,.03); }
  .la-mrow.sel{ background:var(--fox-soft); }
  .la-mrow-name{ font-size:13px; font-weight:600; text-transform:capitalize; }
  .la-mrow-name small{ display:block; color:var(--muted); font-weight:400; font-size:11px; }
  .la-mrow-bar{ position:relative; height:28px; background:var(--bg); border-radius:8px; overflow:hidden; }
  .la-mrow-bar i{ position:absolute; inset:0 auto 0 0; background:linear-gradient(90deg, rgba(255,138,61,.55), var(--fox)); border-radius:8px; }
  .la-mrow-bar span{ position:relative; display:flex; height:100%; align-items:center; justify-content:space-between; padding:0 10px; font-size:12px; font-weight:600; gap:8px; }
  .la-mrow-bar span em{ font-style:normal; color:#fff3e6; }

  .la-empty{ color:var(--muted); font-size:13px; padding:8px 0; }
  .la-loading{ color:var(--muted); font-size:14px; padding:30px 0; text-align:center; }

  @media (max-width: 860px){
    .la-top, .la-grid2{ grid-template-columns:1fr; }
  }
  @media (max-width: 560px){
    .la{ padding-top:18px; }
    .la-wrap{ width:calc(100vw - 32px); }
    .la-title{ font-size:24px; }
    .la-logo{ width:46px; height:46px; }
    .la-card{ padding:14px; }
    .la-tiles{ grid-template-columns:1fr 1fr; }
    .la-tile.main .la-tile-v{ font-size:30px; }
    .la-tile-v{ font-size:20px; }
    #laTiles .la-tile:nth-child(4){ grid-column:1 / -1; }
    .la-table .dow{ display:none; }
    .la-table th{ font-size:9px; letter-spacing:.02em; }
    .la-chart{ gap:1px; height:120px; }
    .la-axis{ gap:1px; }
    .la-axis span:nth-child(even){ visibility:hidden; }
    .la-table th, .la-table td{ padding:8px 4px; font-size:12.5px; }
    .la-table tr.today td:first-child::after{ content:"•"; margin-left:4px; }
    .la-month-name{ min-width:110px; }
  }
</style>

<main class="la">
  <div class="la-wrap">
    <header class="la-head">
      <img class="la-logo" src="/lisa-alisa/avatar.webp" alt="Лиса-Алиса" width="56" height="56">
      <h1 class="la-title">Лиса-Алиса</h1>
    </header>

    <div class="la-top">
      <section class="la-card">
        <h2>Записать день</h2>
        <form class="la-form" id="laForm" autocomplete="off">
          <div class="la-field">
            <label for="laDate">Дата</label>
            <div class="la-date-row">
              <input class="la-input" type="date" id="laDate" required>
              <button class="la-chip" type="button" id="laToday">Сегодня</button>
            </div>
          </div>
          <div class="la-field">
            <label for="laSquares">Сделано квадратов</label>
            <input class="la-input big" type="number" id="laSquares" min="0" step="0.01" inputmode="decimal" placeholder="0">
          </div>
          <div class="la-preview" id="laPreview"></div>
          <div class="la-btns">
            <button class="la-btn" type="submit" id="laSave">Сохранить</button>
            <button class="la-btn ghost" type="button" id="laClear" title="Удалить запись за этот день">Очистить</button>
          </div>
          <div class="la-msg" id="laMsg"></div>
        </form>
      </section>

      <section class="la-card">
        <h2>Заработано <small id="laNow"></small></h2>
        <div class="la-tiles" id="laTiles"><div class="la-loading">Загрузка…</div></div>
      </section>
    </div>

    <section class="la-card">
      <h2>
        <span>По дням</span>
        <span class="la-month-nav">
          <button class="la-nav-btn" type="button" id="laPrev" aria-label="Предыдущий месяц">‹</button>
          <span class="la-month-name" id="laMonthName"></span>
          <button class="la-nav-btn" type="button" id="laNext" aria-label="Следующий месяц">›</button>
        </span>
      </h2>
      <div class="la-summary" id="laSummary"></div>
      <div class="la-chart" id="laChart"></div>
      <div class="la-axis" id="laAxis"></div>
      <div class="la-tablewrap" id="laDays"></div>
    </section>

    <div class="la-grid2">
      <section class="la-card">
        <h2>По месяцам</h2>
        <div class="la-months" id="laMonths"></div>
      </section>
      <section class="la-card">
        <h2>Рекорды</h2>
        <div id="laRecords"></div>
      </section>
    </div>
  </div>
</main>

<script>
(function () {
  const RATE = <?= LA_RATE ?>;
  const API = '/lisa-alisa/index.php?api=1';

  const MONTHS = ['январь','февраль','март','апрель','май','июнь','июль','август','сентябрь','октябрь','ноябрь','декабрь'];
  const MONTHS_GEN = ['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];
  const DOW = ['вс','пн','вт','ср','чт','пт','сб'];

  const $ = (id) => document.getElementById(id);
  const fmtNum = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 });
  const fmtRub = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 });
  const num = (n) => fmtNum.format(n);
  const rub = (n) => fmtRub.format(Math.round(n * 100) / 100) + ' ₽';

  const pad = (n) => String(n).padStart(2, '0');
  const iso = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  const parseIso = (s) => { const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d); };
  const daysIn = (y, m) => new Date(y, m + 1, 0).getDate();
  const monthKey = (y, m) => `${y}-${pad(m + 1)}`;
  const human = (s) => { const d = parseIso(s); return `${d.getDate()} ${MONTHS_GEN[d.getMonth()]}`; };

  function plural(n, one, few, many) {
    const a = Math.abs(n) % 100, b = a % 10;
    if (a > 10 && a < 20) return many;
    if (b > 1 && b < 5) return few;
    if (b === 1) return one;
    return many;
  }

  let entries = {};
  let todayIso = iso(new Date());
  const now = new Date();
  let viewY = now.getFullYear();
  let viewM = now.getMonth();
  let selected = todayIso;

  const get = (date) => Number(entries[date] || 0);

  function sumRange(fromIso, toIso) {
    let s = 0, days = 0;
    for (const [d, v] of Object.entries(entries)) {
      if (d >= fromIso && d <= toIso) { s += Number(v); days++; }
    }
    return { squares: s, days };
  }

  // ---------- API ----------
  async function load() {
    const r = await fetch(API, { cache: 'no-store' });
    const j = await r.json();
    entries = j.entries || {};
  }

  async function save(date, squares) {
    const r = await fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ date, squares }),
    });
    const j = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(j.error || 'Не удалось сохранить');
    entries = j.entries || {};
  }

  // ---------- форма ----------
  function selectDate(date, focus) {
    selected = date;
    $('laDate').value = date;
    const v = get(date);
    $('laSquares').value = v ? v : '';
    updatePreview();
    const d = parseIso(date);
    if (d.getFullYear() !== viewY || d.getMonth() !== viewM) {
      viewY = d.getFullYear(); viewM = d.getMonth();
    }
    renderMonth();
    renderMonths();
    if (focus) {
      $('laSquares').focus({ preventScroll: true });
      $('laForm').scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  }

  function updatePreview() {
    const v = parseFloat(String($('laSquares').value).replace(',', '.'));
    const stored = get($('laDate').value);
    const parts = [];
    if (Number.isFinite(v) && v > 0) parts.push(`${num(v)} × ${RATE} ₽ = <b>${rub(v * RATE)}</b>`);
    parts.push(stored ? `сейчас записано ${num(stored)}` : 'за этот день записей нет');
    $('laPreview').innerHTML = parts.join(' · ');
  }

  function msg(text, kind) {
    const el = $('laMsg');
    el.textContent = text;
    el.className = 'la-msg ' + (kind || '');
    clearTimeout(msg.t);
    if (kind === 'ok') msg.t = setTimeout(() => { el.textContent = ''; }, 2500);
  }

  async function submit(squares) {
    const date = $('laDate').value;
    if (!date) { msg('Выберите дату', 'err'); return; }
    $('laSave').disabled = $('laClear').disabled = true;
    try {
      await save(date, squares);
      msg(squares > 0 ? `Сохранено: ${human(date)} — ${num(squares)} кв. (${rub(squares * RATE)})` : `Запись за ${human(date)} удалена`, 'ok');
      renderAll();
    } catch (e) {
      msg(e.message, 'err');
    } finally {
      $('laSave').disabled = $('laClear').disabled = false;
    }
  }

  $('laForm').addEventListener('submit', (e) => {
    e.preventDefault();
    const raw = String($('laSquares').value).replace(',', '.').trim();
    const v = raw === '' ? 0 : Number(raw);
    if (!Number.isFinite(v) || v < 0) { msg('Введите неотрицательное число', 'err'); return; }
    submit(Math.round(v * 100) / 100);
  });
  $('laClear').addEventListener('click', () => {
    if (!get($('laDate').value)) { $('laSquares').value = ''; updatePreview(); return; }
    if (confirm(`Удалить запись за ${human($('laDate').value)}?`)) { $('laSquares').value = ''; submit(0); }
  });
  $('laToday').addEventListener('click', () => selectDate(todayIso));
  $('laDate').addEventListener('change', () => { if ($('laDate').value) selectDate($('laDate').value); });
  $('laSquares').addEventListener('input', updatePreview);

  $('laPrev').addEventListener('click', () => { viewM--; if (viewM < 0) { viewM = 11; viewY--; } renderMonth(); renderMonths(); });
  $('laNext').addEventListener('click', () => { viewM++; if (viewM > 11) { viewM = 0; viewY++; } renderMonth(); renderMonths(); });

  // ---------- плитки ----------
  function renderTiles() {
    const t = parseIso(todayIso);
    const dow = (t.getDay() + 6) % 7; // 0 = понедельник
    const mon = new Date(t); mon.setDate(t.getDate() - dow);
    const sun = new Date(mon); sun.setDate(mon.getDate() + 6);
    const monthStart = `${monthKey(t.getFullYear(), t.getMonth())}-01`;

    const day = get(todayIso);
    const week = sumRange(iso(mon), iso(sun));
    const month = sumRange(monthStart, `${monthKey(t.getFullYear(), t.getMonth())}-31`);

    $('laNow').textContent = `${t.getDate()} ${MONTHS_GEN[t.getMonth()]}, ${DOW[t.getDay()]}`;
    const weekLabel = `${mon.getDate()} ${MONTHS_GEN[mon.getMonth()]} – ${sun.getDate()} ${MONTHS_GEN[sun.getMonth()]}`;

    $('laTiles').innerHTML = `
      <div class="la-tile main">
        <div class="la-tile-k">Сегодня</div>
        <div class="la-tile-v">${rub(day * RATE)}</div>
        <div class="la-tile-s">${day ? `<b>${num(day)}</b> кв.` : 'ещё ничего не записано'}</div>
      </div>
      <div class="la-tile">
        <div class="la-tile-k">Неделя</div>
        <div class="la-tile-v">${rub(week.squares * RATE)}</div>
        <div class="la-tile-s"><b>${num(week.squares)}</b> кв. · ${weekLabel}</div>
      </div>
      <div class="la-tile">
        <div class="la-tile-k">Месяц</div>
        <div class="la-tile-v">${rub(month.squares * RATE)}</div>
        <div class="la-tile-s"><b>${num(month.squares)}</b> кв. · ${month.days} ${plural(month.days, 'день', 'дня', 'дней')}</div>
      </div>
      <div class="la-tile">
        <div class="la-tile-k">В среднем за день</div>
        <div class="la-tile-v">${rub(month.days ? month.squares * RATE / month.days : 0)}</div>
        <div class="la-tile-s">в этом месяце</div>
      </div>
    `;
  }

  // ---------- месяц ----------
  function renderMonth() {
    const y = viewY, m = viewM;
    const nDays = daysIn(y, m);
    const key = monthKey(y, m);
    const isCurrent = key === todayIso.slice(0, 7);
    const isFuture = key > todayIso.slice(0, 7);
    // Для текущего месяца показываем дни с 1-го по сегодня, для прошлых — весь месяц
    const lastDay = isCurrent ? parseIso(todayIso).getDate() : (isFuture ? 0 : nDays);

    $('laMonthName').textContent = `${MONTHS[m]} ${y}`;
    $('laNext').disabled = key >= todayIso.slice(0, 7);

    let max = 0, total = 0, worked = 0;
    for (let d = 1; d <= nDays; d++) {
      const v = get(`${key}-${pad(d)}`);
      if (v > max) max = v;
      if (v > 0) { total += v; worked++; }
    }

    $('laSummary').innerHTML = `
      <span>Квадратов: <b>${num(total)}</b></span>
      <span>Заработано: <b class="money">${rub(total * RATE)}</b></span>
      <span>Рабочих дней: <b>${worked}</b></span>
      <span>Среднее: <b>${worked ? num(Math.round(total / worked * 100) / 100) : 0}</b> кв./день</span>
    `;

    const chart = $('laChart'), axis = $('laAxis');
    chart.style.setProperty('--days', nDays);
    axis.style.setProperty('--days', nDays);
    let bars = '', ticks = '';
    for (let d = 1; d <= nDays; d++) {
      const date = `${key}-${pad(d)}`;
      const v = get(date);
      const future = date > todayIso;
      const h = max ? Math.max(v / max * 100, v ? 3 : 0) : 0;
      const cls = ['la-bar', v ? '' : 'empty', future ? 'future' : '', date === todayIso ? 'today' : '', date === selected ? 'sel' : ''].join(' ');
      const title = `${human(date)}: ${num(v)} кв. · ${rub(v * RATE)}`;
      bars += `<div class="${cls}" data-date="${date}" title="${title}"><i style="height:${v ? h : 1.5}%"></i></div>`;
      const wd = new Date(y, m, d).getDay();
      ticks += `<span class="${wd === 0 || wd === 6 ? 'we' : ''}">${d}</span>`;
    }
    chart.innerHTML = bars;
    axis.innerHTML = ticks;

    if (!lastDay) {
      $('laDays').innerHTML = '<div class="la-empty">Этот месяц ещё не начался.</div>';
      return;
    }

    const rows = [];
    let cum = 0;
    for (let d = 1; d <= lastDay; d++) {
      const date = `${key}-${pad(d)}`;
      const v = get(date);
      cum += v;
      const wd = new Date(y, m, d).getDay();
      const cls = [v ? '' : 'zero', date === todayIso ? 'today' : '', date === selected ? 'sel' : ''].join(' ');
      rows.push(`
        <tr class="${cls}" data-date="${date}">
          <td>${d} ${MONTHS_GEN[m]}<span class="dow">${DOW[wd]}</span></td>
          <td>${v ? num(v) : '—'}</td>
          <td class="money">${v ? rub(v * RATE) : '—'}</td>
          <td class="cum">${rub(cum * RATE)}</td>
        </tr>`);
    }
    // Свежие дни сверху — так удобнее смотреть каждый день
    const rowList = rows.reverse().join('');

    $('laDays').innerHTML = `
      <table class="la-table">
        <thead><tr><th>Дата</th><th>Квадраты</th><th>За день</th><th title="Сумма с 1-го числа месяца">Накоплено</th></tr></thead>
        <tbody>${rowList}</tbody>
        <tfoot><tr><td>Итого</td><td>${num(total)}</td><td class="money">${rub(total * RATE)}</td><td></td></tr></tfoot>
      </table>`;
  }

  function onPick(e) {
    const el = e.target.closest('[data-date]');
    if (el) selectDate(el.dataset.date, true);
  }
  $('laChart').addEventListener('click', onPick);
  $('laDays').addEventListener('click', onPick);

  // ---------- месяцы ----------
  function renderMonths() {
    const byMonth = {};
    for (const [d, v] of Object.entries(entries)) {
      const k = d.slice(0, 7);
      byMonth[k] = byMonth[k] || { squares: 0, days: 0 };
      byMonth[k].squares += Number(v);
      byMonth[k].days++;
    }
    const keys = Object.keys(byMonth).sort().reverse();
    if (!keys.length) {
      $('laMonths').innerHTML = '<div class="la-empty">Пока нет записей — добавьте первый день.</div>';
      return;
    }
    const max = Math.max(...keys.map((k) => byMonth[k].squares));
    const viewKey = monthKey(viewY, viewM);
    $('laMonths').innerHTML = keys.map((k) => {
      const [y, m] = k.split('-').map(Number);
      const s = byMonth[k];
      const w = max ? s.squares / max * 100 : 0;
      return `
        <div class="la-mrow ${k === viewKey ? 'sel' : ''}" data-month="${k}">
          <div class="la-mrow-name">${MONTHS[m - 1]} ${y}<small>${s.days} ${plural(s.days, 'день', 'дня', 'дней')} · ${num(s.squares)} кв.</small></div>
          <div class="la-mrow-bar"><i style="width:${w}%"></i><span><em>${rub(s.squares * RATE)}</em></span></div>
        </div>`;
    }).join('');
  }
  $('laMonths').addEventListener('click', (e) => {
    const el = e.target.closest('[data-month]');
    if (!el) return;
    const [y, m] = el.dataset.month.split('-').map(Number);
    viewY = y; viewM = m - 1;
    renderMonth(); renderMonths();
  });

  // ---------- рекорды ----------
  function renderRecords() {
    const list = Object.entries(entries).map(([d, v]) => [d, Number(v)]);
    if (!list.length) { $('laRecords').innerHTML = '<div class="la-empty">Появятся после первых записей.</div>'; return; }
    const best = list.reduce((a, b) => (b[1] > a[1] ? b : a));
    const total = list.reduce((s, [, v]) => s + v, 0);
    const top = list.slice().sort((a, b) => b[1] - a[1]).slice(0, 5);
    $('laRecords').innerHTML = `
      <div class="la-tiles" style="grid-template-columns:1fr 1fr;margin-bottom:12px">
        <div class="la-tile"><div class="la-tile-k">Лучший день</div><div class="la-tile-v">${num(best[1])} кв.</div><div class="la-tile-s">${human(best[0])} ${best[0].slice(0, 4)}</div></div>
        <div class="la-tile"><div class="la-tile-k">Всего</div><div class="la-tile-v">${rub(total * RATE)}</div><div class="la-tile-s">${num(total)} кв. · ${list.length} ${plural(list.length, 'день', 'дня', 'дней')}</div></div>
      </div>
      <table class="la-table">
        <thead><tr><th>Топ-5 дней</th><th>Кв.</th><th>₽</th></tr></thead>
        <tbody>${top.map(([d, v]) => `<tr data-date="${d}"><td>${human(d)} ${d.slice(0, 4)}</td><td>${num(v)}</td><td class="money">${rub(v * RATE)}</td></tr>`).join('')}</tbody>
      </table>`;
  }
  $('laRecords').addEventListener('click', onPick);

  function renderAll() {
    todayIso = iso(new Date());
    renderTiles();
    renderMonth();
    renderMonths();
    renderRecords();
    updatePreview();
  }

  $('laDate').value = todayIso;
  load()
    .then(() => { selectDate(todayIso); renderAll(); })
    .catch(() => { $('laTiles').innerHTML = '<div class="la-empty" style="color:var(--bad)">Не удалось загрузить данные.</div>'; });

  // Подтягиваем изменения других людей, когда вкладка снова активна
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') load().then(renderAll).catch(() => {});
  });
})();
</script>
