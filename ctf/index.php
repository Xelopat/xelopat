<?php
// /ctf — афиша CTF-соревнований. Для всех: подборка. Для админа: кнопка «Проанализировать» (CTFtime + ИИ).
declare(strict_types=1);

date_default_timezone_set('Europe/Moscow');

require_once $_SERVER['DOCUMENT_ROOT'] . '/auth/lib.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/ctf_lib.php';

$ctf_is_admin = user_has_role(auth_current_user(), 'admin');

function ctf_json(int $code, array $payload): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------------- API (только админ) ---------------- */
if (isset($_GET['api'])) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') ctf_json(405, ['error' => 'Метод не поддерживается']);
    if (!$ctf_is_admin) ctf_json(403, ['error' => 'Только для админа']);
    if (!csrf_check((string)($_SERVER['HTTP_X_CSRF'] ?? ''))) ctf_json(400, ['error' => 'Сессия устарела, обнови страницу']);

    @set_time_limit(120);
    $in = json_decode((string)file_get_contents('php://input'), true);
    $in = is_array($in) ? $in : [];
    $action = (string)($in['action'] ?? '');

    try {
        if ($action === 'settings') {
            $cur = ctf_read('ctf_config.json');
            $key = trim((string)($in['api_key'] ?? ''));
            if ($key !== '') {
                if (!preg_match('~^[A-Za-z0-9._\-]{8,200}$~', $key)) ctf_json(400, ['error' => 'Ключ выглядит неправильно']);
                $cur['api_key'] = $key;
            }
            if (!empty($in['clear_key'])) $cur['api_key'] = '';
            $model = trim((string)($in['model'] ?? ''));
            if ($model !== '' && !preg_match('~^[A-Za-z0-9._:/\-]{3,80}$~', $model)) ctf_json(400, ['error' => 'Название модели выглядит неправильно']);
            $cur['model'] = $model;
            $sm = trim((string)($in['search_model'] ?? ''));
            if ($sm !== '' && !preg_match('~^[A-Za-z0-9._:/\-]{3,80}$~', $sm)) ctf_json(400, ['error' => 'Название модели поиска выглядит неправильно']);
            $cur['search_model'] = $sm;
            if (!ctf_write('ctf_config.json', $cur)) ctf_json(500, ['error' => 'Не удалось сохранить настройки']);
            $c = ctf_config();
            ctf_json(200, ['ok' => true, 'key' => ctf_mask_key($c['api_key']), 'model' => $c['model'], 'search_model' => $c['search_model']]);
        }

        if ($action === 'start') {
            $cfg = ctf_config();
            if ($cfg['api_key'] === '') ctf_json(400, ['error' => 'Сначала впиши API-ключ routerai.ru в настройках ИИ']);
            $note = [];
            try {
                $events = ctf_fetch_events();
            } catch (Throwable $e) {
                $events = [];
                $note[] = $e->getMessage();
            }
            ctf_write('ctf_pending.json', [
                'created' => time(), 'events' => $events, 'ai' => (object)[],
                'extra' => 0, 'note' => implode('; ', $note),
            ]);
            ctf_json(200, ['ok' => true, 'found' => count($events), 'note' => implode('; ', $note)]);
        }

        // Российский календарь: сбор страниц (без ИИ)
        if ($action === 'ru_fetch') {
            $p = ctf_read('ctf_pending.json');
            if (!isset($p['events'])) ctf_json(400, ['error' => 'Начни анализ заново']);
            try {
                $p['ru_raw'] = ctf_ru_collect();
            } catch (Throwable $e) {
                $p['ru_raw'] = [];
                $p['note'] = trim((string)($p['note'] ?? '') . '; ' . $e->getMessage(), '; ');
            }
            ctf_write('ctf_pending.json', $p);
            ctf_json(200, ['ok' => true, 'found' => count((array)$p['ru_raw'])]);
        }

        // Российский календарь: ИИ достаёт время, формат и ссылки из статей
        if ($action === 'ru_ai') {
            $cfg = ctf_config();
            $p = ctf_read('ctf_pending.json');
            if (!isset($p['events'])) ctf_json(400, ['error' => 'Начни анализ заново']);
            $raw = (array)($p['ru_raw'] ?? []);
            $added = 0;
            if ($raw) {
                try {
                    $ru = ctf_ru_extract($cfg, $raw, (array)$p['events']);
                    $p['events'] = array_merge((array)$p['events'], $ru);
                    $p['extra'] = (int)($p['extra'] ?? 0) + count($ru);
                    $added = count($ru);
                } catch (Throwable $e) {
                    $p['note'] = trim((string)($p['note'] ?? '') . '; ' . $e->getMessage(), '; ');
                }
            }
            unset($p['ru_raw']);
            ctf_write('ctf_pending.json', $p);
            ctf_json(200, ['ok' => true, 'added' => $added]);
        }

        // Поиск по интернету
        if ($action === 'search') {
            $cfg = ctf_config();
            $p = ctf_read('ctf_pending.json');
            if (!isset($p['events'])) ctf_json(400, ['error' => 'Начни анализ заново']);
            $added = 0;
            if ($cfg['search_model'] !== '') {
                try {
                    $extra = ctf_ai_search($cfg, (array)$p['events']);
                    $p['events'] = array_merge((array)$p['events'], $extra);
                    $p['extra'] = (int)($p['extra'] ?? 0) + count($extra);
                    $added = count($extra);
                } catch (Throwable $e) {
                    $p['note'] = trim((string)($p['note'] ?? '') . '; ' . $e->getMessage(), '; ');
                }
            }
            $all = (array)$p['events'];
            if (!$all) ctf_json(400, ['error' => (string)($p['note'] ?? '') !== '' ? (string)$p['note'] : 'Ближайших событий не нашлось']);
            usort($all, function ($a, $b) { return $a['start'] <=> $b['start']; });
            $p['events'] = array_slice($all, 0, 100);
            ctf_write('ctf_pending.json', $p);
            ctf_json(200, ['ok' => true, 'added' => $added, 'total' => count($p['events']), 'left' => count($p['events']), 'note' => (string)($p['note'] ?? '')]);
        }

        if ($action === 'batch') {
            $cfg = ctf_config();
            $p = ctf_read('ctf_pending.json');
            $events = (array)($p['events'] ?? []);
            $ai = (array)($p['ai'] ?? []);
            if (!$events) ctf_json(400, ['error' => 'Нечего разбирать: начни анализ заново']);
            $todo = [];
            foreach ($events as $e) {
                if (!isset($ai[(string)$e['id']])) $todo[] = $e;
                if (count($todo) >= CTF_BATCH) break;
            }
            if ($todo) {
                $res = ctf_ai_batch($todo, $cfg);
                foreach ($todo as $e) {
                    // событие, которое ИИ пропустил, помечаем пустым, чтобы не зациклиться
                    $ai[(string)$e['id']] = $res[$e['id']] ?? ctf_clean_ai([]);
                }
                $p['ai'] = $ai;
                ctf_write('ctf_pending.json', $p);
            }
            ctf_json(200, ['ok' => true, 'total' => count($events), 'left' => max(0, count($events) - count($ai))]);
        }

        if ($action === 'finish') {
            $cfg = ctf_config();
            $p = ctf_read('ctf_pending.json');
            $events = (array)($p['events'] ?? []);
            $ai = (array)($p['ai'] ?? []);
            if (!$events) ctf_json(400, ['error' => 'Нечего сохранять: начни анализ заново']);
            $merged = [];
            foreach ($events as $e) {
                $merged[] = array_merge($e, $ai[(string)$e['id']] ?? ctf_clean_ai([]));
            }
            $digest = '';
            try {
                $digest = ctf_ai_digest($merged, $cfg);
            } catch (Throwable $e) {
                // сводка не критична: афишу всё равно сохраняем
            }
            $ok = ctf_write('ctf_events.json', [
                'updated_at' => time(),
                'model' => $cfg['model'],
                'source' => 'ctftime.org + ИИ-поиск',
                'search_model' => $cfg['search_model'],
                'extra' => (int)($p['extra'] ?? 0),
                'note' => (string)($p['note'] ?? ''),
                'digest' => $digest,
                'events' => $merged,
            ]);
            if (!$ok) ctf_json(500, ['error' => 'Не удалось записать афишу']);
            @unlink(ctf_dir() . '/ctf_pending.json');
            ctf_json(200, ['ok' => true, 'count' => count($merged)]);
        }

        ctf_json(400, ['error' => 'Неизвестное действие']);
    } catch (Throwable $e) {
        ctf_json(500, ['error' => $e->getMessage()]);
    }
}

/* ---------------- страница ---------------- */
$data = ctf_read('ctf_events.json');
$now = time();
$events = [];
foreach ((array)($data['events'] ?? []) as $e) {
    if (is_array($e) && (int)($e['finish'] ?? 0) >= $now) $events[] = $e;
}
usort($events, function ($a, $b) { return (int)$a['start'] <=> (int)$b['start']; });

$running = array_values(array_filter($events, function ($e) use ($now) { return (int)$e['start'] <= $now; }));
$upcoming = array_values(array_filter($events, function ($e) use ($now) { return (int)$e['start'] > $now; }));
$online = count(array_filter($events, function ($e) { return empty($e['onsite']); }));
$picks = count(array_filter($events, function ($e) { return !empty($e['pick']); }));
$updated = (int)($data['updated_at'] ?? 0);
$cfg = $ctf_is_admin ? ctf_config() : ['api_key' => '', 'model' => CTF_DEFAULT_MODEL];

$levels = ['beginner' => 'Новичкам', 'medium' => 'Средний', 'hard' => 'Сложный'];
$kinds = ['jeopardy' => 'Jeopardy', 'attack-defense' => 'Attack-Defense', 'mixed' => 'Mixed'];

function ctf_h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ctf_card(array $e, array $levels, array $kinds): void {
    $level = (string)($e['level'] ?? '');
    $kind = (string)($e['kind'] ?? '');
    $isOnline = empty($e['onsite']);
    $tags = array_map('strval', (array)($e['tags'] ?? []));
    $search = mb_strtolower((string)$e['title'] . ' ' . implode(' ', $tags) . ' ' . (string)($e['summary'] ?? '') . ' ' . implode(' ', (array)($e['organizers'] ?? [])));
    $link = (string)(($e['url'] ?? '') ?: ($e['ctftime_url'] ?? ''));
    $mode = (string)($e['time_mode'] ?? (!empty($e['allday']) ? 'none' : 'full'));
    ?>
    <article class="ctf-card" data-pick="<?= !empty($e['pick']) ? '1' : '0' ?>" data-level="<?= ctf_h($level) ?>" data-online="<?= $isOnline ? '1' : '0' ?>" data-search="<?= ctf_h($search) ?>">
      <div class="ctf-top">
        <h3 class="ctf-name"><?php if ($link !== ''): ?><a href="<?= ctf_h($link) ?>" target="_blank" rel="noopener noreferrer"><?= ctf_h((string)$e['title']) ?></a><?php else: ?><?= ctf_h((string)$e['title']) ?><?php endif; ?></h3>
        <div class="ctf-badges">
          <?php if (!empty($e['pick'])): ?><span class="ctf-badge pick">Рекомендуем</span><?php endif; ?>
          <?php if (isset($levels[$level])): ?><span class="ctf-badge lvl-<?= ctf_h($level) ?>"><?= ctf_h($levels[$level]) ?></span><?php endif; ?>
          <?php if (isset($kinds[$kind])): ?><span class="ctf-badge"><?= ctf_h($kinds[$kind]) ?></span><?php endif; ?>
          <span class="ctf-badge"><?= $isOnline ? 'Онлайн' : 'Очно' ?></span>
        </div>
      </div>
      <div class="ctf-when"><?= ctf_h(ctf_when((int)$e['start'], (int)$e['finish'], $mode)) ?><?php if ($mode === 'full'): ?> <span class="ctf-dim">(<?= ctf_h(ctf_duration((int)$e['start'], (int)$e['finish'])) ?>)</span><?php endif; ?></div>
      <?php if ((string)($e['summary'] ?? '') !== ''): ?><p class="ctf-sum"><?= ctf_h((string)$e['summary']) ?></p><?php endif; ?>
      <?php if ((string)($e['why'] ?? '') !== ''): ?><p class="ctf-why"><?= ctf_h((string)$e['why']) ?></p><?php endif; ?>
      <div class="ctf-foot">
        <div class="ctf-tags">
          <?php foreach ($tags as $t): ?><span class="ctf-tag"><?= ctf_h($t) ?></span><?php endforeach; ?>
        </div>
        <div class="ctf-meta">
          <?php if (!empty($e['organizers'])): ?><span><?= ctf_h(implode(', ', array_map('strval', (array)$e['organizers']))) ?></span><?php endif; ?>
          <?php if ((float)($e['weight'] ?? 0) > 0): ?><span>вес <?= ctf_h((string)$e['weight']) ?></span><?php endif; ?>
          <?php if (!$isOnline && (string)($e['location'] ?? '') !== ''): ?><span><?= ctf_h((string)$e['location']) ?></span><?php endif; ?>
          <?php if ((string)($e['ctftime_url'] ?? '') !== ''): ?><a href="<?= ctf_h((string)$e['ctftime_url']) ?>" target="_blank" rel="noopener noreferrer">CTFtime</a><?php endif; ?>
        </div>
      </div>
    </article>
    <?php
}

$site_page_title = 'CTF — xelopat';
include $_SERVER['DOCUMENT_ROOT'] . '/header.php';
?>
<link rel="stylesheet" href="/assets/bases.css">
<style>
  .ctf-bar{ display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
  .ctf-chips{ display:flex; flex-wrap:wrap; gap:6px; }
  .ctf-chip{ border:1px solid var(--line); background:var(--panel); color:var(--text-2, #c4c6d2); border-radius:999px; padding:6px 12px; font:12px var(--mono); cursor:pointer; }
  .ctf-chip.on{ border-color:var(--accent); color:var(--accent); }
  .ctf-h2{ margin:26px 0 12px; font:700 15px var(--mono); color:var(--green); }
  .ctf-grid{ display:grid; grid-template-columns:repeat(auto-fill, minmax(340px, 1fr)); gap:12px; }
  .ctf-card{ border:1px solid var(--line); background:var(--panel); border-radius:12px; padding:16px; display:flex; flex-direction:column; gap:8px; min-width:0; }
  .ctf-card[hidden], .ctf-grid[hidden], .ctf-h2[hidden], .b-empty[hidden]{ display:none !important; }
  .ctf-top{ display:flex; flex-direction:column; gap:8px; }
  .ctf-name{ margin:0; font:700 16px/1.3 var(--mono); word-break:break-word; }
  .ctf-name a{ color:var(--text); text-decoration:none; }
  .ctf-name a:hover{ color:var(--accent); }
  .ctf-badges{ display:flex; flex-wrap:wrap; gap:5px; }
  .ctf-badge{ font:11px var(--mono); padding:3px 8px; border-radius:5px; background:var(--panel-2); border:1px solid var(--line); color:var(--text-2, #c4c6d2); }
  .ctf-badge.pick{ background:rgba(97,209,173,.12); border-color:rgba(97,209,173,.4); color:var(--green); }
  .ctf-badge.lvl-beginner{ color:var(--green); }
  .ctf-badge.lvl-medium{ color:var(--accent); }
  .ctf-badge.lvl-hard{ color:var(--danger); }
  .ctf-when{ font:12px var(--mono); color:var(--accent); }
  .ctf-dim{ color:var(--muted); }
  .ctf-sum{ margin:0; font-size:14px; line-height:1.5; color:var(--text-2, #c4c6d2); }
  .ctf-why{ margin:0; font-size:13px; line-height:1.45; color:var(--green); }
  .ctf-foot{ margin-top:auto; display:flex; flex-direction:column; gap:8px; padding-top:4px; }
  .ctf-tags{ display:flex; flex-wrap:wrap; gap:5px; }
  .ctf-tag{ font:11px var(--mono); padding:2px 8px; border-radius:999px; background:var(--panel-2); border:1px solid var(--line); color:var(--text-2, #c4c6d2); }
  .ctf-meta{ display:flex; flex-wrap:wrap; gap:4px 14px; font-size:12px; color:var(--muted); }
  .ctf-meta a{ color:var(--green); }
  .ctf-digest{ border:1px solid var(--line); border-left:3px solid var(--green); background:var(--panel); border-radius:10px; padding:14px 16px; font-size:14px; line-height:1.55; color:var(--text-2, #c4c6d2); margin-bottom:16px; }
  .ctf-admin{ border:1px dashed var(--line); border-radius:12px; padding:14px 16px; margin-bottom:18px; background:var(--panel-2); }
  .ctf-admin-row{ display:flex; flex-wrap:wrap; gap:12px; align-items:center; }
  .ctf-status{ font:12px var(--mono); color:var(--muted); }
  .ctf-status.err{ color:var(--danger); }
  .ctf-settings{ margin-top:12px; }
  .ctf-settings summary{ cursor:pointer; font:12px var(--mono); color:var(--muted); }
  .ctf-settings form{ margin-top:10px; display:grid; gap:10px; max-width:520px; }
  .ctf-settings label{ display:grid; gap:4px; font:11px var(--mono); color:var(--muted); }
  @media (max-width: 760px){ .ctf-grid{ grid-template-columns:1fr; } }
</style>

<main class="b-shell">
  <div class="b-wrap">
    <section class="b-head">
      <div>
        <span class="b-kicker">// хобби</span>
        <h1 class="b-title">CTF</h1>
        <p class="b-sub">Афиша ближайших соревнований по информационной безопасности. Международные события берутся с CTFtime, российские из календаря CTF News и поиска ИИ по интернету. ИИ коротко пересказывает их, оценивает уровень и подсказывает, куда стоит заглянуть.</p>
      </div>
      <div class="b-stats" style="--n:3">
        <div class="b-stat"><strong><?= count($events) ?></strong><span>событий</span></div>
        <div class="b-stat"><strong><?= $online ?></strong><span>онлайн</span></div>
        <div class="b-stat"><strong><?= $picks ?></strong><span>рекомендуем</span></div>
      </div>
    </section>

    <?php if ($ctf_is_admin): ?>
      <section class="ctf-admin" id="ctfAdmin" data-csrf="<?= ctf_h(csrf_token()) ?>">
        <div class="ctf-admin-row">
          <button class="b-btn" type="button" id="ctfAnalyze">Проанализировать</button>
          <span class="ctf-status" id="ctfStatus"><?= $updated ? 'Обновлено ' . ctf_h(date('d.m.Y H:i', $updated)) . ' МСК, модель ' . ctf_h((string)($data['model'] ?? '')) . ', российских и найденных поиском: ' . (int)($data['extra'] ?? 0) . (((string)($data['note'] ?? '')) !== '' ? '. Замечание: ' . ctf_h((string)$data['note']) : '') : 'Афиша ещё не собиралась' ?></span>
        </div>
        <details class="ctf-settings"<?= $cfg['api_key'] === '' ? ' open' : '' ?>>
          <summary>Настройки ИИ (routerai.ru)</summary>
          <form id="ctfSettings" autocomplete="off">
            <label>API-ключ <?= $cfg['api_key'] !== '' ? '(сохранён: ' . ctf_h(ctf_mask_key($cfg['api_key'])) . ', оставь пустым, чтобы не менять)' : '(не задан)' ?>
              <input class="b-input" type="password" id="ctfKey" placeholder="sk-..." autocomplete="new-password">
            </label>
            <label>Модель для описаний
              <input class="b-input" type="text" id="ctfModel" value="<?= ctf_h($cfg['model']) ?>">
            </label>
            <label>Модель для поиска по интернету (пусто = искать только на CTFtime)
              <input class="b-input" type="text" id="ctfSearchModel" value="<?= ctf_h($cfg['search_model']) ?>">
            </label>
            <div class="ctf-admin-row">
              <button class="b-btn" type="submit">Сохранить</button>
              <span class="ctf-status" id="ctfSetStatus"></span>
            </div>
          </form>
        </details>
      </section>
    <?php endif; ?>

    <?php if (!$events): ?>
      <div class="b-empty"><?= $ctf_is_admin ? 'Афиши пока нет. Впиши ключ в настройках ИИ и нажми «Проанализировать».' : 'Афиша пока пустая, загляни позже.' ?></div>
    <?php else: ?>
      <?php if ((string)($data['digest'] ?? '') !== ''): ?>
        <div class="ctf-digest"><?= ctf_h((string)$data['digest']) ?></div>
      <?php endif; ?>

      <div class="ctf-bar">
        <input class="b-input" type="search" id="ctfSearch" placeholder="Поиск по названию, тегу, организатору" style="flex:1;min-width:220px">
        <div class="ctf-chips" id="ctfChips">
          <button type="button" class="ctf-chip on" data-f="all">Все</button>
          <button type="button" class="ctf-chip" data-f="pick">Рекомендуем</button>
          <button type="button" class="ctf-chip" data-f="beginner">Новичкам</button>
          <button type="button" class="ctf-chip" data-f="online">Онлайн</button>
        </div>
      </div>

      <?php if ($running): ?>
        <h2 class="ctf-h2" data-group>// идут сейчас</h2>
        <div class="ctf-grid" data-group>
          <?php foreach ($running as $e) ctf_card($e, $levels, $kinds); ?>
        </div>
      <?php endif; ?>

      <?php if ($upcoming): ?>
        <h2 class="ctf-h2" data-group>// скоро</h2>
        <div class="ctf-grid" data-group>
          <?php foreach ($upcoming as $e) ctf_card($e, $levels, $kinds); ?>
        </div>
      <?php endif; ?>

      <div class="b-empty" id="ctfNone" hidden>По этим фильтрам ничего нет.</div>

      <p class="b-hint" style="margin-top:22px">Даты и ссылки с CTFtime показаны без изменений. Российские события собраны из календаря CTF News и поиска ИИ, краткие описания, уровень и рекомендации тоже пишет ИИ: время и условия участия проверяй на странице соревнования.<?= $updated ? ' Обновлено ' . ctf_h(date('d.m.Y H:i', $updated)) . ' МСК.' : '' ?></p>
    <?php endif; ?>
  </div>
</main>

<script>
(function () {
  // ---------- фильтры ----------
  const cards = [...document.querySelectorAll('.ctf-card')];
  const search = document.getElementById('ctfSearch');
  const chips = document.querySelectorAll('#ctfChips .ctf-chip');
  let filter = 'all';

  function apply() {
    const q = (search ? search.value : '').trim().toLowerCase();
    let shown = 0;
    cards.forEach((c) => {
      let ok = true;
      if (filter === 'pick') ok = c.dataset.pick === '1';
      if (filter === 'beginner') ok = c.dataset.level === 'beginner';
      if (filter === 'online') ok = c.dataset.online === '1';
      if (ok && q) ok = c.dataset.search.indexOf(q) !== -1;
      c.hidden = !ok;
      if (ok) shown++;
    });
    document.querySelectorAll('.ctf-grid').forEach((g) => {
      const any = [...g.querySelectorAll('.ctf-card')].some((c) => !c.hidden);
      g.hidden = !any;
      if (g.previousElementSibling && g.previousElementSibling.classList.contains('ctf-h2')) g.previousElementSibling.hidden = !any;
    });
    const none = document.getElementById('ctfNone');
    if (none) none.hidden = shown > 0;
  }
  chips.forEach((b) => b.addEventListener('click', () => {
    filter = b.dataset.f;
    chips.forEach((x) => x.classList.toggle('on', x === b));
    apply();
  }));
  if (search) search.addEventListener('input', apply);

  // ---------- админ: анализ ----------
  const admin = document.getElementById('ctfAdmin');
  if (!admin) return;
  const CSRF = admin.dataset.csrf;
  const btn = document.getElementById('ctfAnalyze');
  const status = document.getElementById('ctfStatus');

  function say(el, text, bad) {
    el.textContent = text;
    el.classList.toggle('err', !!bad);
  }

  async function call(action, payload) {
    const r = await fetch('/ctf?api=1', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF': CSRF },
      body: JSON.stringify(Object.assign({ action }, payload || {})),
    });
    const j = await r.json().catch(() => ({ error: 'Сервер ответил не так, как ожидалось (' + r.status + ')' }));
    if (!r.ok || j.error) throw new Error(j.error || ('Ошибка ' + r.status));
    return j;
  }

  async function retry(action) {
    let tries = 0;
    for (;;) {
      try { return await call(action); }
      catch (e) { if (++tries >= 2) throw e; }
    }
  }

  btn.addEventListener('click', async () => {
    btn.disabled = true;
    try {
      say(status, 'Загружаю список с CTFtime…');
      const st = await call('start');
      if (st.note) say(status, 'Замечание: ' + st.note);

      say(status, 'Собираю российские события из календаря CTF News…');
      await retry('ru_fetch');
      say(status, 'ИИ разбирает российские анонсы…');
      const ru = await retry('ru_ai');

      say(status, 'ИИ ищет по интернету ещё…');
      const se = await retry('search');
      let left = se.left;
      const total = se.total;
      while (left > 0) {
        say(status, 'ИИ описывает события: ' + (total - left) + ' из ' + total);
        left = (await retry('batch')).left;
      }
      say(status, 'Готовлю сводку…');
      await call('finish');
      say(status, 'Готово, обновляю страницу…');
      location.reload();
    } catch (e) {
      say(status, e.message, true);
      btn.disabled = false;
    }
  });

  const form = document.getElementById('ctfSettings');
  const setStatus = document.getElementById('ctfSetStatus');
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      const j = await call('settings', {
        api_key: document.getElementById('ctfKey').value,
        model: document.getElementById('ctfModel').value,
        search_model: document.getElementById('ctfSearchModel').value,
      });
      document.getElementById('ctfKey').value = '';
      document.getElementById('ctfModel').value = j.model;
      document.getElementById('ctfSearchModel').value = j.search_model;
      say(setStatus, 'Сохранено' + (j.key ? ', ключ ' + j.key : ''));
    } catch (err) {
      say(setStatus, err.message, true);
    }
  });
})();
</script>
