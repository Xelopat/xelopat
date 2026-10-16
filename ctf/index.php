<?php
// /ctf — афиша CTF-соревнований. Для всех: подборка и архив. Для админа внизу страницы: кнопка «Проанализировать».
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
            $model = trim((string)($in['model'] ?? ''));
            if ($model !== '' && !preg_match('~^[A-Za-z0-9._:/\-]{3,80}$~', $model)) ctf_json(400, ['error' => 'Название модели выглядит неправильно']);
            $cur['model'] = $model;
            $px = trim((string)($in['proxy'] ?? ''));
            if ($px !== '' && !preg_match('#^(https?|socks4|socks5h?)://[A-Za-z0-9._~%!$&\'()*+,;=:@\-]{3,200}$#', $px)) ctf_json(400, ['error' => 'Прокси вида socks5://host:port или http://user:pass@host:port']);
            $cur['proxy'] = $px;
            $sm = trim((string)($in['search_model'] ?? ''));
            if ($sm !== '' && !preg_match('~^[A-Za-z0-9._:/\-]{3,80}$~', $sm)) ctf_json(400, ['error' => 'Название модели поиска выглядит неправильно']);
            $cur['search_model'] = $sm;
            if (!ctf_write('ctf_config.json', $cur)) ctf_json(500, ['error' => 'Не удалось сохранить настройки']);
            $c = ctf_config();
            ctf_json(200, ['ok' => true, 'key' => ctf_mask_key($c['api_key']), 'model' => $c['model'], 'search_model' => $c['search_model'], 'proxy' => $c['proxy']]);
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
                'extra' => 0, 'note' => implode('; ', $note), 'ctftime_ok' => count($events) > 0,
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

        // Поиск по интернету + склейка дублей
        if ($action === 'search') {
            $cfg = ctf_config();
            $p = ctf_read('ctf_pending.json');
            if (!isset($p['events'])) ctf_json(400, ['error' => 'Начни анализ заново']);
            $added = 0;
            if ($cfg['search_model'] !== '') {
                try {
                    $extra = ctf_ai_search($cfg, (array)$p['events'], empty($p['ctftime_ok']));
                    $p['events'] = array_merge((array)$p['events'], $extra);
                    $p['extra'] = (int)($p['extra'] ?? 0) + count($extra);
                    $added = count($extra);
                } catch (Throwable $e) {
                    $p['note'] = trim((string)($p['note'] ?? '') . '; ' . $e->getMessage(), '; ');
                }
            }
            $all = ctf_dedupe((array)$p['events']);
            if (!$all) ctf_json(400, ['error' => (string)($p['note'] ?? '') !== '' ? (string)$p['note'] : 'Ближайших событий не нашлось']);
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
                $aiv = $ai[(string)$e['id']] ?? ctf_clean_ai([]);
                $m = array_merge($e, $aiv);
                $m['ru'] = !empty($e['ru']) || !empty($aiv['ru']);
                $merged[] = $m;
            }
            $digest = '';
            try {
                $digest = ctf_ai_digest($merged, $cfg);
            } catch (Throwable $e) {
                // сводка не критична: афишу всё равно сохраняем
            }
            $prev = ctf_read('ctf_events.json');
            $ok = ctf_write('ctf_events.json', [
                'updated_at' => time(),
                'model' => $cfg['model'],
                'source' => 'ctftime.org + ctfnews.ru + ИИ-поиск',
                'search_model' => $cfg['search_model'],
                'extra' => (int)($p['extra'] ?? 0),
                'note' => (string)($p['note'] ?? ''),
                'digest' => $digest,
                'events' => ctf_with_archive($merged, (array)($prev['events'] ?? [])),
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
$current = [];
$past = [];
foreach ((array)($data['events'] ?? []) as $e) {
    if (!is_array($e) || empty($e['id'])) continue;
    if ((int)($e['finish'] ?? 0) >= $now) $current[] = $e; else $past[] = $e;
}
usort($current, function ($a, $b) { return (int)$a['start'] <=> (int)$b['start']; });
usort($past, function ($a, $b) { return (int)$b['finish'] <=> (int)$a['finish']; });

$running = array_values(array_filter($current, function ($e) use ($now) { return (int)$e['start'] <= $now; }));
$upcoming = array_values(array_filter($current, function ($e) use ($now) { return (int)$e['start'] > $now; }));
$online = count(array_filter($current, function ($e) { return empty($e['onsite']); }));
$picks = count(array_filter($current, function ($e) { return !empty($e['pick']); }));
$updated = (int)($data['updated_at'] ?? 0);
$cfg = $ctf_is_admin ? ctf_config() : ['api_key' => '', 'model' => CTF_DEFAULT_MODEL, 'search_model' => '', 'proxy' => ''];

// популярные теги для фильтра
$tagCount = [];
foreach (array_merge($current, $past) as $e) {
    foreach ((array)($e['tags'] ?? []) as $t) $tagCount[(string)$t] = ($tagCount[(string)$t] ?? 0) + 1;
}
arsort($tagCount);
$topTags = array_slice(array_keys($tagCount), 0, 12);

$levels = ['beginner' => 'Новичкам', 'medium' => 'Средний', 'hard' => 'Сложный'];
$kinds = ['jeopardy' => 'Jeopardy', 'attack-defense' => 'Attack-Defense', 'mixed' => 'Mixed'];

function ctf_h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ctf_card(array $e, array $levels, array $kinds, bool $isPast): void {
    $level = (string)($e['level'] ?? '');
    $kind = (string)($e['kind'] ?? '');
    $isOnline = empty($e['onsite']);
    $tags = array_map('strval', (array)($e['tags'] ?? []));
    $search = mb_strtolower((string)$e['title'] . ' ' . implode(' ', $tags) . ' ' . implode(' ', array_map('strval', (array)($e['organizers'] ?? []))) . ' ' . (string)($e['location'] ?? ''));
    $link = (string)(($e['url'] ?? '') ?: ($e['ctftime_url'] ?? ''));
    $mode = (string)($e['time_mode'] ?? (!empty($e['allday']) ? 'none' : 'full'));
    ?>
    <article class="ctf-card<?= $isPast ? ' past' : '' ?>" data-id="<?= (int)$e['id'] ?>" data-past="<?= $isPast ? '1' : '0' ?>" data-ru="<?= !empty($e['ru']) ? '1' : '0' ?>" data-online="<?= $isOnline ? '1' : '0' ?>" data-level="<?= ctf_h($level) ?>" data-kind="<?= ctf_h($kind) ?>" data-pick="<?= !empty($e['pick']) ? '1' : '0' ?>" data-tags="<?= ctf_h(implode(',', $tags)) ?>" data-start="<?= (int)$e['start'] ?>" data-finish="<?= (int)$e['finish'] ?>" data-search="<?= ctf_h($search) ?>">
      <div class="ctf-top">
        <h3 class="ctf-name"><?php if ($link !== ''): ?><a href="<?= ctf_h($link) ?>" target="_blank" rel="noopener noreferrer"><?= ctf_h((string)$e['title']) ?></a><?php else: ?><?= ctf_h((string)$e['title']) ?><?php endif; ?></h3>
        <button type="button" class="ctf-hide" data-hide title="Скрыть это событие (запомнится в этом браузере)">Скрыть</button>
      </div>
      <div class="ctf-badges">
        <?php if (!empty($e['pick']) && !$isPast): ?><span class="ctf-badge pick">Рекомендуем</span><?php endif; ?>
        <?php if (!empty($e['ru'])): ?><span class="ctf-badge">Россия</span><?php endif; ?>
        <?php if (isset($levels[$level])): ?><span class="ctf-badge lvl-<?= ctf_h($level) ?>"><?= ctf_h($levels[$level]) ?></span><?php endif; ?>
        <?php if (isset($kinds[$kind])): ?><span class="ctf-badge"><?= ctf_h($kinds[$kind]) ?></span><?php endif; ?>
        <span class="ctf-badge"><?= $isOnline ? 'Онлайн' : 'Очно' ?></span>
      </div>
      <div class="ctf-when"><?= ctf_h(ctf_when((int)$e['start'], (int)$e['finish'], $mode)) ?><?php if ($mode === 'full'): ?> <span class="ctf-dim">(<?= ctf_h(ctf_duration((int)$e['start'], (int)$e['finish'])) ?>)</span><?php endif; ?></div>
      <?php if ((string)($e['why'] ?? '') !== '' && !empty($e['pick']) && !$isPast): ?><p class="ctf-why"><?= ctf_h((string)$e['why']) ?></p><?php endif; ?>
      <div class="ctf-foot">
        <?php if ($tags): ?><div class="ctf-tags"><?php foreach ($tags as $t): ?><span class="ctf-tag"><?= ctf_h($t) ?></span><?php endforeach; ?></div><?php endif; ?>
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
  [hidden]{ display:none !important; }
  .ctf-h2{ margin:26px 0 12px; font:700 15px var(--mono); color:var(--green); }
  .ctf-grid{ display:grid; grid-template-columns:repeat(auto-fill, minmax(330px, 1fr)); gap:12px; }
  .ctf-card{ border:1px solid var(--line); background:var(--panel); border-radius:var(--radius-lg); padding:15px 16px; display:flex; flex-direction:column; gap:9px; min-width:0; }
  .ctf-card.past{ opacity:.72; }
  .ctf-card.is-hidden{ border-style:dashed; opacity:.6; }
  .ctf-top{ display:flex; align-items:flex-start; justify-content:space-between; gap:10px; }
  .ctf-name{ margin:0; font:700 16px/1.3 var(--mono); word-break:break-word; }
  .ctf-name a{ color:var(--text); text-decoration:none; }
  .ctf-name a:hover{ color:var(--accent); }
  .ctf-hide{ flex:none; border:1px solid transparent; background:transparent; color:var(--muted); font:11px var(--mono); padding:3px 7px; border-radius:var(--radius-sm); cursor:pointer; }
  .ctf-hide:hover{ border-color:var(--line); color:var(--text); }
  .ctf-badges{ display:flex; flex-wrap:wrap; gap:5px; }
  .ctf-badge{ font:11px var(--mono); padding:3px 8px; border-radius:var(--radius-sm); background:var(--panel-2); border:1px solid var(--line); color:var(--muted); }
  .ctf-badge.pick{ background:rgba(97,209,173,.12); border-color:rgba(97,209,173,.4); color:var(--green); }
  .ctf-badge.lvl-beginner{ color:var(--green); }
  .ctf-badge.lvl-medium{ color:var(--accent); }
  .ctf-badge.lvl-hard{ color:var(--danger); }
  .ctf-when{ font:12px var(--mono); color:var(--accent); }
  .ctf-dim{ color:var(--muted); }
  .ctf-why{ margin:0; font-size:13px; line-height:1.45; color:var(--green); }
  .ctf-foot{ margin-top:auto; display:flex; flex-direction:column; gap:8px; padding-top:2px; }
  .ctf-tags{ display:flex; flex-wrap:wrap; gap:5px; }
  .ctf-tag{ font:11px var(--mono); padding:2px 8px; border-radius:999px; background:var(--panel-2); border:1px solid var(--line); color:var(--muted); }
  .ctf-meta{ display:flex; flex-wrap:wrap; gap:4px 14px; font-size:12px; color:var(--muted); }
  .ctf-meta a{ color:var(--accent); }
  .ctf-digest{ border:1px solid var(--line); border-left:3px solid var(--green); background:var(--panel); border-radius:var(--radius); padding:14px 16px; font-size:14px; line-height:1.55; color:var(--muted); margin-bottom:16px; }

  /* фильтры */
  .ctf-filters{ border:1px solid var(--line); background:var(--panel); border-radius:var(--radius-lg); padding:14px 16px; display:grid; gap:11px; }
  .ctf-f-top{ display:flex; gap:10px; align-items:center; }
  .ctf-f-top .b-input{ flex:1; min-width:0; }
  .ctf-reset{ border:1px solid var(--line); background:transparent; color:var(--muted); border-radius:var(--radius); padding:8px 12px; font:12px var(--mono); cursor:pointer; white-space:nowrap; }
  .ctf-reset:hover{ color:var(--text); border-color:var(--accent); }
  .ctf-f-row{ display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
  .ctf-f-label{ flex:0 0 64px; font:11px var(--mono); color:var(--muted); text-transform:uppercase; letter-spacing:.04em; }
  .ctf-seg{ display:inline-flex; flex-wrap:wrap; border:1px solid var(--line); border-radius:var(--radius); overflow:hidden; }
  .ctf-seg button, .ctf-chip{ border:0; background:transparent; color:var(--muted); font:12px var(--mono); padding:6px 11px; cursor:pointer; }
  .ctf-seg button + button{ border-left:1px solid var(--line); }
  .ctf-seg button:hover, .ctf-chip:hover{ color:var(--text); background:rgba(255,255,255,.04); }
  .ctf-seg button.on{ background:rgba(249,201,64,.14); color:var(--accent); }
  .ctf-seg button .n, .ctf-chip .n{ margin-left:5px; color:var(--muted); font-size:11px; }
  .ctf-seg button[disabled], .ctf-chip[disabled]{ opacity:.35; cursor:default; }
  .ctf-chips{ display:flex; flex-wrap:wrap; gap:6px; }
  .ctf-chip{ border:1px solid var(--line); border-radius:999px; padding:4px 10px; }
  .ctf-chip.on{ border-color:var(--accent); color:var(--accent); background:rgba(249,201,64,.1); }
  .ctf-toggles{ display:flex; flex-wrap:wrap; gap:6px 18px; }
  .ctf-toggles label{ display:inline-flex; gap:7px; align-items:center; font:12px var(--mono); color:var(--muted); cursor:pointer; }
  .ctf-toggles .n{ color:var(--muted); }

  /* админ-панель внизу */
  .ctf-admin{ border:1px dashed var(--line); border-radius:var(--radius-lg); padding:14px 16px; margin-top:34px; background:var(--panel-2); }
  .ctf-admin-row{ display:flex; flex-wrap:wrap; gap:12px; align-items:center; }
  .ctf-status{ font:12px var(--mono); color:var(--muted); }
  .ctf-status.err{ color:var(--danger); }
  .ctf-settings{ margin-top:12px; }
  .ctf-settings summary{ cursor:pointer; font:12px var(--mono); color:var(--muted); }
  .ctf-settings form{ margin-top:10px; display:grid; gap:10px; max-width:520px; }
  .ctf-settings label{ display:grid; gap:4px; font:11px var(--mono); color:var(--muted); }
  @media (max-width: 760px){
    .ctf-grid{ grid-template-columns:1fr; }
    .ctf-f-label{ flex-basis:100%; }
  }
</style>

<main class="b-shell">
  <div class="b-wrap">
    <section class="b-head">
      <div>
        <span class="b-kicker">// хобби</span>
        <h1 class="b-title">CTF</h1>
        <p class="b-sub">Афиша ближайших соревнований по информационной безопасности. Международные события берутся с CTFtime, российские из календаря CTF News и поиска по интернету. ИИ оценивает уровень и подсказывает, куда стоит заглянуть.</p>
      </div>
      <div class="b-stats" style="--n:3">
        <div class="b-stat"><strong><?= count($current) ?></strong><span>событий</span></div>
        <div class="b-stat"><strong><?= $online ?></strong><span>онлайн</span></div>
        <div class="b-stat"><strong><?= $picks ?></strong><span>рекомендуем</span></div>
      </div>
    </section>

    <?php if (!$current && !$past): ?>
      <div class="b-empty"><?= $ctf_is_admin ? 'Афиши пока нет. Внизу страницы впиши ключ в настройках ИИ и нажми «Проанализировать».' : 'Афиша пока пустая, загляни позже.' ?></div>
    <?php else: ?>
      <?php if ((string)($data['digest'] ?? '') !== ''): ?>
        <div class="ctf-digest"><?= ctf_h((string)$data['digest']) ?></div>
      <?php endif; ?>

      <section class="ctf-filters" id="ctfFilters">
        <div class="ctf-f-top">
          <input class="b-input" type="search" id="ctfSearch" placeholder="Поиск по названию, организатору, тегу, городу">
          <button type="button" class="ctf-reset" id="ctfReset" hidden>Сбросить фильтры</button>
        </div>
        <div class="ctf-f-row"><span class="ctf-f-label">Откуда</span>
          <div class="ctf-seg" data-group="region">
            <button type="button" data-v="all" class="on">Все</button>
            <button type="button" data-v="ru">Россия</button>
            <button type="button" data-v="world">Другие страны</button>
          </div>
        </div>
        <div class="ctf-f-row"><span class="ctf-f-label">Формат</span>
          <div class="ctf-seg" data-group="format">
            <button type="button" data-v="all" class="on">Любой</button>
            <button type="button" data-v="online">Онлайн</button>
            <button type="button" data-v="onsite">Очно</button>
          </div>
        </div>
        <div class="ctf-f-row"><span class="ctf-f-label">Уровень</span>
          <div class="ctf-seg" data-group="level">
            <button type="button" data-v="all" class="on">Любой</button>
            <button type="button" data-v="beginner">Новичкам</button>
            <button type="button" data-v="medium">Средний</button>
            <button type="button" data-v="hard">Сложный</button>
          </div>
        </div>
        <div class="ctf-f-row"><span class="ctf-f-label">Когда</span>
          <div class="ctf-seg" data-group="when">
            <button type="button" data-v="all" class="on">Любое время</button>
            <button type="button" data-v="now">Идут сейчас</button>
            <button type="button" data-v="week">7 дней</button>
            <button type="button" data-v="month">30 дней</button>
          </div>
        </div>
        <div class="ctf-f-row"><span class="ctf-f-label">Тип</span>
          <div class="ctf-seg" data-group="kind">
            <button type="button" data-v="all" class="on">Любой</button>
            <button type="button" data-v="jeopardy">Jeopardy</button>
            <button type="button" data-v="attack-defense">Attack-Defense</button>
          </div>
        </div>
        <?php if ($topTags): ?>
          <div class="ctf-f-row"><span class="ctf-f-label">Теги</span>
            <div class="ctf-chips" id="ctfTags">
              <?php foreach ($topTags as $t): ?><button type="button" class="ctf-chip" data-tag="<?= ctf_h($t) ?>"><?= ctf_h($t) ?><span class="n"></span></button><?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
        <div class="ctf-toggles">
          <label><input type="checkbox" id="ctfPick"> Только рекомендуемые <span class="n" id="ctfPickN"></span></label>
          <label><input type="checkbox" id="ctfArchive"> Архив прошедших <span class="n">(<?= count($past) ?>)</span></label>
          <label><input type="checkbox" id="ctfShowHidden"> Скрытые мной <span class="n" id="ctfHiddenN"></span></label>
        </div>
      </section>

      <?php if ($running): ?>
        <h2 class="ctf-h2">// идут сейчас</h2>
        <div class="ctf-grid">
          <?php foreach ($running as $e) ctf_card($e, $levels, $kinds, false); ?>
        </div>
      <?php endif; ?>

      <?php if ($upcoming): ?>
        <h2 class="ctf-h2">// скоро</h2>
        <div class="ctf-grid">
          <?php foreach ($upcoming as $e) ctf_card($e, $levels, $kinds, false); ?>
        </div>
      <?php endif; ?>

      <?php if ($past): ?>
        <h2 class="ctf-h2" id="ctfArchiveH" hidden>// архив</h2>
        <div class="ctf-grid" id="ctfArchiveGrid" hidden>
          <?php foreach ($past as $e) ctf_card($e, $levels, $kinds, true); ?>
        </div>
      <?php endif; ?>

      <div class="b-empty" id="ctfNone" hidden>По этим фильтрам ничего нет.</div>

      <p class="b-hint" style="margin-top:22px">Даты и ссылки с CTFtime показаны без изменений. Российские события собраны из календаря CTF News и поиска ИИ, уровень и рекомендации тоже оценивает ИИ: время и условия участия проверяй на странице соревнования.<?= $updated ? ' Обновлено ' . ctf_h(date('d.m.Y H:i', $updated)) . ' МСК.' : '' ?> Скрытые события запоминаются только в этом браузере.</p>
    <?php endif; ?>

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
            <label>Модель для оценки событий
              <input class="b-input" type="text" id="ctfModel" value="<?= ctf_h($cfg['model']) ?>">
            </label>
            <label>Модель для поиска по интернету (пусто = искать только в CTFtime и календаре)
              <input class="b-input" type="text" id="ctfSearchModel" value="<?= ctf_h($cfg['search_model']) ?>">
            </label>
            <label>Прокси для CTFtime (необязательно, если сервер сам не достаёт до ctftime.org)
              <input class="b-input" type="text" id="ctfProxy" value="<?= ctf_h($cfg['proxy'] ?? '') ?>" placeholder="socks5://host:port или http://user:pass@host:port">
            </label>
            <div class="ctf-admin-row">
              <button class="b-btn" type="submit">Сохранить</button>
              <span class="ctf-status" id="ctfSetStatus"></span>
            </div>
          </form>
        </details>
      </section>
    <?php endif; ?>
  </div>
</main>

<script>
(function () {
  // ---------- хранилище (локально в браузере) ----------
  function load(key, def) {
    try { const v = JSON.parse(localStorage.getItem(key)); return v === null || v === undefined ? def : v; } catch (e) { return def; }
  }
  function save(key, val) {
    try { localStorage.setItem(key, JSON.stringify(val)); } catch (e) { /* приватный режим: просто не запомним */ }
  }

  const cards = [...document.querySelectorAll('.ctf-card')];
  const hiddenIds = new Set((load('ctf_hidden', []) || []).map(String));
  const saved = load('ctf_filters', {}) || {};
  const state = {
    q: '',
    region: saved.region || 'all', format: saved.format || 'all', level: saved.level || 'all',
    when: saved.when || 'all', kind: saved.kind || 'all',
    tags: new Set(saved.tags || []),
    pick: !!saved.pick, archive: !!saved.archive, showHidden: !!saved.showHidden,
  };
  const groups = ['region', 'format', 'level', 'when', 'kind'];
  const nowTs = Math.floor(Date.now() / 1000);

  function matches(c, skip) {
    const d = c.dataset;
    if (skip !== 'region' && state.region !== 'all' && (state.region === 'ru') !== (d.ru === '1')) return false;
    if (skip !== 'format' && state.format !== 'all' && (state.format === 'online') !== (d.online === '1')) return false;
    if (skip !== 'level' && state.level !== 'all' && d.level !== state.level) return false;
    if (skip !== 'kind' && state.kind !== 'all' && d.kind !== state.kind) return false;
    if (skip !== 'when' && state.when !== 'all') {
      const s = +d.start, f = +d.finish;
      if (state.when === 'now' && !(s <= nowTs && f >= nowTs)) return false;
      if (state.when === 'week' && !(s <= nowTs + 7 * 86400)) return false;
      if (state.when === 'month' && !(s <= nowTs + 30 * 86400)) return false;
    }
    if (skip !== 'tags' && state.tags.size) {
      const t = (d.tags || '').split(',');
      for (const x of state.tags) if (t.indexOf(x) === -1) return false;
    }
    if (skip !== 'pick' && state.pick && d.pick !== '1') return false;
    if (state.q && d.search.indexOf(state.q) === -1) return false;
    return true;
  }

  // карточка попадает в «область»: прошлые только при включённом архиве, скрытые только при показе скрытых
  function inScope(c) {
    if (c.dataset.past === '1' && !state.archive) return false;
    if (hiddenIds.has(c.dataset.id) && !state.showHidden) return false;
    return true;
  }

  function activeCount() {
    let n = 0;
    groups.forEach((g) => { if (state[g] !== 'all') n++; });
    if (state.tags.size) n++;
    if (state.pick) n++;
    if (state.q) n++;
    return n;
  }

  function apply() {
    let shown = 0;
    cards.forEach((c) => {
      const ok = inScope(c) && matches(c, '');
      c.hidden = !ok;
      c.classList.toggle('is-hidden', hiddenIds.has(c.dataset.id));
      const b = c.querySelector('[data-hide]');
      if (b) b.textContent = hiddenIds.has(c.dataset.id) ? 'Вернуть' : 'Скрыть';
      if (ok) shown++;
    });

    document.querySelectorAll('.ctf-grid').forEach((g) => {
      const any = [...g.querySelectorAll('.ctf-card')].some((c) => !c.hidden);
      const h = g.previousElementSibling;
      g.hidden = !any;
      if (h && h.classList.contains('ctf-h2')) h.hidden = !any;
    });
    const none = document.getElementById('ctfNone');
    if (none) none.hidden = shown > 0;

    // счётчики у вариантов: сколько событий будет, если выбрать именно это (при остальных фильтрах как есть)
    document.querySelectorAll('.ctf-seg').forEach((seg) => {
      const g = seg.dataset.group;
      seg.querySelectorAll('button').forEach((b) => {
        b.classList.toggle('on', state[g] === b.dataset.v);
        const saveV = state[g];
        state[g] = b.dataset.v;
        const n = cards.filter((c) => inScope(c) && matches(c, '')).length;
        state[g] = saveV;
        let sp = b.querySelector('.n');
        if (!sp) { sp = document.createElement('span'); sp.className = 'n'; b.appendChild(sp); }
        sp.textContent = n;
        b.disabled = n === 0 && b.dataset.v !== 'all' && state[g] !== b.dataset.v;
      });
    });
    document.querySelectorAll('#ctfTags .ctf-chip').forEach((b) => {
      const tag = b.dataset.tag;
      b.classList.toggle('on', state.tags.has(tag));
      const had = state.tags.has(tag);
      state.tags.add(tag);
      const n = cards.filter((c) => inScope(c) && matches(c, '')).length;
      if (!had) state.tags.delete(tag);
      b.querySelector('.n').textContent = n;
      b.disabled = n === 0 && !had;
    });

    const pn = document.getElementById('ctfPickN');
    if (pn) pn.textContent = '(' + cards.filter((c) => c.dataset.pick === '1' && c.dataset.past === '0' && !hiddenIds.has(c.dataset.id)).length + ')';
    const hn = document.getElementById('ctfHiddenN');
    if (hn) hn.textContent = '(' + cards.filter((c) => hiddenIds.has(c.dataset.id)).length + ')';

    document.getElementById('ctfPick').checked = state.pick;
    document.getElementById('ctfArchive').checked = state.archive;
    document.getElementById('ctfShowHidden').checked = state.showHidden;
    const reset = document.getElementById('ctfReset');
    if (reset) reset.hidden = activeCount() === 0;

    save('ctf_filters', {
      region: state.region, format: state.format, level: state.level, when: state.when, kind: state.kind,
      tags: [...state.tags], pick: state.pick, archive: state.archive, showHidden: state.showHidden,
    });
  }

  const filtersEl = document.getElementById('ctfFilters');
  if (filtersEl) {
    filtersEl.addEventListener('click', (e) => {
      const seg = e.target.closest('.ctf-seg button');
      if (seg) { state[seg.parentElement.dataset.group] = seg.dataset.v; apply(); return; }
      const chip = e.target.closest('#ctfTags .ctf-chip');
      if (chip) {
        if (state.tags.has(chip.dataset.tag)) state.tags.delete(chip.dataset.tag); else state.tags.add(chip.dataset.tag);
        apply();
      }
    });
    document.getElementById('ctfSearch').addEventListener('input', (e) => { state.q = e.target.value.trim().toLowerCase(); apply(); });
    document.getElementById('ctfPick').addEventListener('change', (e) => { state.pick = e.target.checked; apply(); });
    document.getElementById('ctfArchive').addEventListener('change', (e) => { state.archive = e.target.checked; apply(); });
    document.getElementById('ctfShowHidden').addEventListener('change', (e) => { state.showHidden = e.target.checked; apply(); });
    document.getElementById('ctfReset').addEventListener('click', () => {
      groups.forEach((g) => { state[g] = 'all'; });
      state.tags.clear(); state.pick = false; state.q = '';
      document.getElementById('ctfSearch').value = '';
      apply();
    });

    document.querySelectorAll('[data-hide]').forEach((b) => b.addEventListener('click', () => {
      const id = b.closest('.ctf-card').dataset.id;
      if (hiddenIds.has(id)) hiddenIds.delete(id); else hiddenIds.add(id);
      save('ctf_hidden', [...hiddenIds]);
      apply();
    }));
    apply();
  }

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
      await retry('ru_ai');

      say(status, 'ИИ ищет по интернету ещё…');
      const se = await retry('search');
      let left = se.left;
      const total = se.total;
      while (left > 0) {
        say(status, 'ИИ оценивает события: ' + (total - left) + ' из ' + total);
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
        proxy: document.getElementById('ctfProxy').value,
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
