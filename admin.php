<?php
declare(strict_types=1);

require_once __DIR__ . '/auth/lib.php';
require_once __DIR__ . '/includes/collection_lib.php';
require_once __DIR__ . '/includes/stats.php';
require_role('admin');

// Если что-то упадёт, админ увидит причину, а не пустую страницу
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        echo '<pre style="margin:20px;padding:14px;border:1px solid #ff8f8f;border-radius:10px;color:#ff8f8f;background:#1e1e25;white-space:pre-wrap">Ошибка админки: '
            . htmlspecialchars($e['message'] . ' (' . basename($e['file']) . ':' . $e['line'] . ')', ENT_QUOTES, 'UTF-8') . '</pre>';
    }
});

$me = auth_current_user();
$csrf = csrf_token();
$config_path = __DIR__ . '/data/site_config.json';

function admin_h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function admin_flash(string $kind, string $text): void {
    $_SESSION['admin_flash'] = [$kind, $text];
}

function admin_back(string $tab): void {
    header('Location: /admin.php#' . $tab);
    exit;
}

function admin_config(string $path): array {
    $d = is_file($path) ? json_decode((string)file_get_contents($path), true) : [];
    return is_array($d) ? $d : [];
}

function admin_config_save(string $path, array $data): bool {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $json !== false && file_put_contents($path, $json, LOCK_EX) !== false;
}

function admin_when(int $ts): string {
    if ($ts <= 0) return '—';
    $diff = time() - $ts;
    if ($diff < 120) return 'только что';
    if ($diff < 3600) return floor($diff / 60) . ' мин назад';
    if ($diff < 86400) return floor($diff / 3600) . ' ч назад';
    return date('d.m.Y H:i', $ts);
}

// Короткое описание устройства по User-Agent
function admin_device(string $ua): string {
    $os = 'неизвестно';
    foreach (['iPhone' => 'iPhone', 'iPad' => 'iPad', 'Android' => 'Android', 'Windows' => 'Windows', 'Mac OS' => 'macOS', 'Linux' => 'Linux'] as $k => $v) {
        if (stripos($ua, $k) !== false) { $os = $v; break; }
    }
    $br = '';
    foreach (['YaBrowser' => 'Яндекс Браузер', 'Edg/' => 'Edge', 'OPR/' => 'Opera', 'Firefox' => 'Firefox', 'Chrome' => 'Chrome', 'Safari' => 'Safari'] as $k => $v) {
        if (stripos($ua, $k) !== false) { $br = $v; break; }
    }
    return $br ? "$br, $os" : $os;
}

/* ---------------- действия ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check((string)($_POST['csrf'] ?? ''))) {
        admin_flash('err', 'Сессия устарела, попробуй ещё раз.');
        admin_back('');
    }
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_home') {
        $cfg = admin_config($config_path);
        $cfg['hero']['tag'] = trim((string)($_POST['hero_tag'] ?? ''));
        $cfg['hero']['name'] = trim((string)($_POST['hero_name'] ?? ''));
        $cfg['hero']['subtitle'] = trim((string)($_POST['hero_subtitle'] ?? ''));
        $cfg['footer']['text'] = trim((string)($_POST['footer_text'] ?? ''));
        $cfg['terminal']['hostname'] = trim((string)($_POST['term_hostname'] ?? ''));
        $cfg['terminal']['welcome'] = trim((string)($_POST['term_welcome'] ?? ''));
        $cfg['terminal']['whoami'] = trim((string)($_POST['term_whoami'] ?? ''));
        admin_config_save($config_path, $cfg)
            ? admin_flash('ok', 'Главная сохранена.')
            : admin_flash('err', 'Не удалось записать конфиг.');
        admin_back('home');
    }

    if ($action === 'save_json') {
        $decoded = json_decode((string)($_POST['json'] ?? ''), true);
        if (!is_array($decoded)) {
            admin_flash('err', 'JSON невалидный: проверь запятые и кавычки.');
        } else {
            admin_config_save($config_path, $decoded)
                ? admin_flash('ok', 'Конфиг сохранён.')
                : admin_flash('err', 'Не удалось записать конфиг.');
        }
        admin_back('home');
    }

    if ($action === 'import_logs') {
        $r = stats_import_logs();
        $r['ok']
            ? admin_flash('ok', 'Из логов добавлено просмотров: ' . $r['added'] . ($r['added'] ? ' (' . $r['from'] . ' — ' . $r['to'] . ')' : '') . '. Файлов: ' . $r['files'] . ', строк: ' . $r['lines'] . '.')
            : admin_flash('err', $r['error']);
        admin_back('stats');
    }

    if ($action === 'set_roles') {
        $uid = (string)($_POST['uid'] ?? '');
        $roles = array_values(array_intersect(array_keys(ROLES), (array)($_POST['roles'] ?? [])));
        // Себе админа не снимаем, иначе некому будет вернуть
        if ($uid === ($me['id'] ?? '') && !in_array('admin', $roles, true)) $roles[] = 'admin';
        $data = users_load();
        foreach ($data['users'] as $u) {
            if (($u['id'] ?? '') === $uid) {
                $u['roles'] = $roles;
                unset($u['role']);
                $u['updated_at'] = date('c');
                user_update($data, $u);
                users_save($data);
                $labels = array_map(function ($r) { return ROLES[$r]['label']; }, $roles);
                admin_flash('ok', ($u['username'] ?? '') . ': ' . ($labels ? implode(', ', $labels) : 'без ролей') . '.');
                admin_back('users');
            }
        }
        admin_flash('err', 'Пользователь не найден.');
        admin_back('users');
    }

    if ($action === 'revoke_session' || $action === 'revoke_others') {
        $target = (string)($_POST['token'] ?? '');
        $mine = remember_parse_cookie();
        $myToken = $mine['id'] ?? '';
        $uid = (string)($me['id'] ?? '');
        $n = remember_mutate(function (array &$tokens) use ($action, $target, $myToken, $uid) {
            $n = 0;
            foreach ($tokens as $id => $t) {
                if (($t['uid'] ?? '') !== $uid) continue;
                if ($action === 'revoke_session' ? $id === $target : $id !== $myToken) { unset($tokens[$id]); $n++; }
            }
            return $n;
        });
        admin_flash('ok', $action === 'revoke_session' ? 'Вход отозван.' : 'Отозвано входов: ' . (int)$n . '.');
        admin_back('sessions');
    }

    admin_back('');
}

$flash = $_SESSION['admin_flash'] ?? null;
unset($_SESSION['admin_flash']);

/* ---------------- данные для страницы ---------------- */

$load_errors = [];
$sections = [];
try {
foreach (COLLECTIONS as $key => $def) {
    $items = coll_load($key);
    $last = '';
    foreach ($items as $it) if ($it['updated'] > $last) $last = $it['updated'];
    $media = 0;
    foreach ($items as $it) $media += count($it['media']);
    $sections[] = [
        'title' => $def['title'], 'url' => $def['url'], 'add' => $def['url'] . '?add=1',
        'stat' => count($items) . ' шт., файлов: ' . $media,
        'last' => $last ? admin_when((int)strtotime($last)) : 'ещё не менялось',
    ];
}

$perf = is_file(__DIR__ . '/perfumes/data.json') ? json_decode((string)file_get_contents(__DIR__ . '/perfumes/data.json'), true) : [];
$sections[] = ['title' => 'Духи', 'url' => '/perfumes/index.php', 'add' => '', 'stat' => count((array)($perf['items'] ?? [])) . ' шт.', 'last' => 'редактируются на странице'];

$la = is_file(__DIR__ . '/data/lisa_alisa.json') ? json_decode((string)file_get_contents(__DIR__ . '/data/lisa_alisa.json'), true) : [];
$laMonth = 0.0; $laDays = 0;
foreach ((array)($la['entries'] ?? []) as $d => $e) {
    if (strpos((string)$d, date('Y-m')) !== 0) continue;
    $s = is_array($e) ? (float)($e['s'] ?? 0) : (float)$e;
    $r = is_array($e) ? (float)($e['r'] ?? 18) : 18.0;
    $laMonth += $s * $r; $laDays++;
}
$sections[] = ['title' => 'Лиса-Алиса', 'url' => '/lisa-alisa/', 'add' => '', 'stat' => 'В этом месяце ' . number_format(floor($laMonth), 0, ',', ' ') . ' ₽', 'last' => 'рабочих дней: ' . $laDays];

$domStat = 'база не найдена';
$domPath = __DIR__ . '/data/domophones.sqlite';
if (is_file($domPath) && class_exists('PDO')) {
    try {
        $pdo = new PDO('sqlite:' . $domPath);
        $domStat = number_format((int)$pdo->query('SELECT COUNT(*) FROM codes')->fetchColumn(), 0, ',', ' ') . ' кодов';
    } catch (Throwable $e) {
        $domStat = 'ошибка чтения базы';
    }
}
$sections[] = ['title' => 'Домофоны', 'url' => '/bases/domophones.php', 'add' => '', 'stat' => $domStat, 'last' => 'обновляется вручную'];
} catch (Throwable $e) {
    $load_errors[] = 'Разделы: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
}

$stats_days = in_array((int)($_GET['days'] ?? 30), [7, 30, 90, 365], true) ? (int)($_GET['days'] ?? 30) : 30;
$stats = ['ok' => false];
$stats_logs = [];
try {
    $stats = stats_report($stats_days);
    $stats_logs = stats_log_files();
} catch (Throwable $e) {
    $load_errors[] = 'Статистика: ' . $e->getMessage();
}

$cfg = admin_config($config_path);
$cfg_json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
$users = users_load()['users'];

$sessions = [];
try {
$myTokenId = remember_parse_cookie()['id'] ?? '';
$tokensRaw = is_file(REMEMBER_FILE) ? json_decode((string)file_get_contents(REMEMBER_FILE), true) : [];
foreach ((array)$tokensRaw as $id => $t) {
    if (($t['uid'] ?? '') !== ($me['id'] ?? '') || (int)($t['expires'] ?? 0) < time()) continue;
    $sessions[] = ['id' => (string)$id, 'device' => admin_device((string)($t['ua'] ?? '')), 'created' => (int)($t['created'] ?? 0),
                   'refreshed' => (int)($t['refreshed'] ?? 0), 'expires' => (int)($t['expires'] ?? 0), 'current' => $id === $myTokenId];
}
usort($sessions, function ($a, $b) { return $b['refreshed'] <=> $a['refreshed']; });
} catch (Throwable $e) {
    $load_errors[] = 'Входы: ' . $e->getMessage();
}

$site_page_title = 'Админка — xelopat';
include __DIR__ . '/header.php';
?>
<style>
  .cp-head{ display:flex; justify-content:space-between; align-items:flex-end; gap:12px; flex-wrap:wrap; margin-bottom:18px; }
  .cp-head .page-title{ margin:0; }
  .cp-tabs{ display:flex; gap:4px; background:var(--panel); border:1px solid var(--line); border-radius:11px; padding:4px; margin-bottom:16px; overflow-x:auto; }
  .cp-tabs a{ flex:none; padding:8px 14px; border-radius:8px; color:var(--text-2); text-decoration:none; font-size:14px; font-weight:600; }
  .cp-tabs a:hover{ color:var(--text); }
  .cp-tabs a.on{ background:var(--accent); color:#1b1606; }
  .cp-pane{ display:none; }
  .cp-pane.on{ display:block; }

  .cp-flash{ padding:12px 14px; border-radius:10px; margin-bottom:14px; font-size:14px; border:1px solid; }
  .cp-flash.ok{ color:var(--green); border-color:rgba(97,209,173,.35); background:rgba(97,209,173,.07); }
  .cp-flash.err{ color:var(--danger); border-color:rgba(255,143,143,.35); background:rgba(255,143,143,.07); }

  .cp-grid{ display:grid; grid-template-columns:repeat(auto-fill, minmax(240px, 1fr)); gap:12px; }
  .cp-tile{ display:flex; flex-direction:column; gap:6px; }
  .cp-tile h3{ margin:0; font-size:17px; }
  .cp-tile .stat{ font-size:20px; font-weight:800; color:var(--accent); }
  .cp-tile .muted{ font-size:12px; color:var(--muted); }
  .cp-tile .row{ display:flex; gap:8px; margin-top:auto; padding-top:8px; }
  .cp-tile .row .btn{ flex:1; text-align:center; text-decoration:none; }
  .cp-add{ border-color:var(--accent); color:var(--accent); }

  .cp-form{ display:grid; gap:12px; }
  .cp-form .two{ display:grid; grid-template-columns:1fr 1fr; gap:12px; }
  .cp-form h3{ margin:8px 0 0; font-size:14px; color:var(--text-2); font-weight:600; }
  .cp-save{ background:var(--accent); color:#1b1606; border-color:var(--accent); justify-self:start; }
  .cp-save:hover{ color:#1b1606; filter:brightness(1.08); }
  .cp-json{ margin-top:16px; }
  .cp-json summary{ cursor:pointer; color:var(--text-2); font-size:14px; }
  .cp-json textarea{ min-height:360px; font-family:var(--mono); font-size:12px; }

  .cp-table td, .cp-table th{ vertical-align:middle; }
  .cp-pill{ display:inline-block; padding:2px 8px; border-radius:999px; font-size:12px; border:1px solid var(--line); color:var(--text-2); }
  .cp-pill.admin{ color:var(--accent); border-color:rgba(249,201,64,.4); }
  .cp-pill.me{ color:var(--green); border-color:rgba(97,209,173,.4); }
  .cp-inline{ display:inline; margin:0; }
  .cp-small{ padding:6px 10px; font-size:13px; }
  .cp-danger:hover{ border-color:var(--danger); color:var(--danger); }
  .cp-note{ color:var(--muted); font-size:13px; margin:0 0 12px; }
  .cp-h3{ margin:0 0 12px; font-size:14px; font-weight:700; }
  .cp-range{ display:inline-flex; gap:3px; background:var(--panel); border:1px solid var(--line); border-radius:10px; padding:3px; margin-bottom:12px; }
  .cp-range a{ padding:6px 12px; border-radius:7px; color:var(--text-2); text-decoration:none; font-size:13px; font-weight:600; }
  .cp-range a.on{ background:var(--panel-2); color:var(--text); box-shadow:inset 0 0 0 1px var(--line); }
  .cp-kpis{ display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:10px; margin-bottom:12px; }
  .cp-kpis .k{ font-family:var(--mono); font-size:11px; color:var(--muted); text-transform:uppercase; letter-spacing:.04em; }
  .cp-kpis .v{ font-size:26px; font-weight:800; margin-top:4px; }
  .cp-chart{ position:relative; display:grid; grid-template-columns:repeat(var(--n), minmax(0,1fr)); gap:2px; align-items:end; height:160px; border-bottom:1px solid var(--line); }
  .cp-bar{ height:100%; display:flex; align-items:flex-end; cursor:default; }
  .cp-bar i{ display:block; width:100%; background:var(--accent); border-radius:4px 4px 0 0; }
  .cp-bar:hover i{ filter:brightness(1.15); }
  .cp-bar:hover{ background:rgba(255,255,255,.03); }
  .cp-tip{ position:absolute; z-index:2; pointer-events:none; white-space:pre; background:var(--bg); border:1px solid var(--line); border-radius:8px; padding:6px 9px; font-size:12px; color:var(--text); box-shadow:var(--shadow); transform:translate(-50%, -100%); }
  .cp-axis{ display:flex; justify-content:space-between; font-family:var(--mono); font-size:11px; color:var(--muted); margin-top:6px; }
  .cp-pages-head{ display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:10px; }
  .cp-sort{ margin:0; }
  .cp-sort button{ border:none; background:none; font:inherit; padding:6px 12px; border-radius:7px; color:var(--text-2); font-size:13px; font-weight:600; cursor:pointer; }
  .cp-sort button.on{ background:var(--panel-2); color:var(--text); box-shadow:inset 0 0 0 1px var(--line); }
  .cp-row{ position:relative; display:grid; grid-template-columns:minmax(0,1fr) 90px 90px; gap:10px; padding:6px 8px; font-size:13px; border-radius:6px; overflow:hidden; }
  .cp-row-head{ font-family:var(--mono); font-size:10px; text-transform:uppercase; letter-spacing:.04em; color:var(--muted); }
  .cp-row-n{ position:relative; text-align:right; font-family:var(--mono); color:var(--text-2); }
  .cp-row-n.on{ color:var(--text); font-weight:700; }
  .cp-row-bar{ position:absolute; inset:0 auto 0 0; background:rgba(249,201,64,.12); border-radius:6px; }
  .cp-row-k{ position:relative; }
  .cp-row-k{ overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

  .cp-roles{ display:flex; flex-wrap:wrap; gap:6px; align-items:center; margin:0; }
  .cp-check{ display:inline-flex; align-items:center; gap:6px; padding:5px 10px; border:1px solid var(--line); border-radius:999px; font-size:13px; cursor:pointer; user-select:none; color:var(--text-2); }
  .cp-check input{ margin:0; accent-color:var(--accent); }
  .cp-check:has(input:checked){ border-color:rgba(249,201,64,.45); color:var(--text); background:rgba(249,201,64,.07); }
  .cp-check.locked{ cursor:default; opacity:.75; }

  @media (max-width: 700px){
    .cp-form .two{ grid-template-columns:1fr; }
    .cp-table th:nth-child(3), .cp-table td:nth-child(3){ display:none; }
    .cp-check{ padding:5px 8px; }
    .cp-kpis{ grid-template-columns:1fr 1fr; }
    .cp-row{ grid-template-columns:minmax(0,1fr) 64px 64px; gap:6px; }
    .cp-chart{ gap:1px; }
  }
</style>

<main class="page">
  <div class="page-wrap">
    <div class="cp-head">
      <div>
        <div class="page-label">// admin</div>
        <h1 class="page-title">Админка</h1>
      </div>
      <a class="btn" href="/auth/logout.php">Выйти</a>
    </div>

    <?php foreach ($load_errors as $le): ?>
      <div class="cp-flash err"><?= admin_h($le) ?></div>
    <?php endforeach; ?>
    <?php if ($flash): ?>
      <div class="cp-flash <?= $flash[0] === 'ok' ? 'ok' : 'err' ?>"><?= admin_h($flash[1]) ?></div>
    <?php endif; ?>

    <nav class="cp-tabs" id="cpTabs">
      <a href="#sections" data-tab="sections" class="on">Разделы</a>
      <a href="#stats" data-tab="stats">Статистика</a>
      <a href="#home" data-tab="home">Главная</a>
      <a href="#users" data-tab="users">Пользователи</a>
      <a href="#sessions" data-tab="sessions">Мои входы</a>
    </nav>

    <section class="cp-pane on" id="pane-sections">
      <div class="cp-grid">
        <?php foreach ($sections as $s): ?>
          <div class="panel cp-tile">
            <h3><?= admin_h($s['title']) ?></h3>
            <div class="stat"><?= admin_h($s['stat']) ?></div>
            <div class="muted"><?= admin_h($s['last']) ?></div>
            <div class="row">
              <a class="btn cp-small" href="<?= admin_h($s['url']) ?>">Открыть</a>
              <?php if ($s['add']): ?><a class="btn cp-small cp-add" href="<?= admin_h($s['add']) ?>">+ Добавить</a><?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="cp-pane" id="pane-stats">
      <?php if (!$stats['ok']): ?>
        <div class="panel"><p class="cp-note">База статистики недоступна: на сервере нет SQLite для PHP.</p></div>
      <?php else: ?>
        <?php
          // Подписи страниц берём из меню сайта
          $page_names = ['/' => 'Главная', '/lisa-alisa/' => 'Лиса-Алиса'];
          foreach (COLLECTIONS as $cd) $page_names[stats_norm_path($cd['url'])] = $cd['title'];
          foreach ($univer_sections as $sec) foreach ($sec['items'] as $it) $page_names[stats_norm_path($it[1])] = $it[0];
          foreach (array_merge($hobby_items, $base_items) as $it) $page_names[stats_norm_path($it[1])] = $it[0];
          $maxDay = max(1, max(array_column($stats['series'], 'views')));
          $avg = $stats['views'] / max(1, count($stats['series']));
        ?>
        <div class="cp-range">
          <?php foreach ([7 => '7 дней', 30 => '30 дней', 90 => '90 дней', 365 => 'Год'] as $d => $lbl): ?>
            <a class="<?= $d === $stats_days ? 'on' : '' ?>" href="/admin.php?days=<?= $d ?>#stats"><?= $lbl ?></a>
          <?php endforeach; ?>
        </div>

        <div class="cp-kpis">
          <div class="panel"><div class="k">Просмотры</div><div class="v"><?= number_format($stats['views'], 0, ',', ' ') ?></div></div>
          <div class="panel"><div class="k">Посетители</div><div class="v"><?= number_format($stats['uniques'], 0, ',', ' ') ?></div></div>
          <div class="panel"><div class="k">Сегодня</div><div class="v"><?= number_format($stats['today'], 0, ',', ' ') ?></div></div>
          <div class="panel"><div class="k">В среднем в день</div><div class="v"><?= number_format($avg, $avg < 10 ? 1 : 0, ',', ' ') ?></div></div>
        </div>

        <div class="panel cp-chart-card">
          <h3 class="cp-h3">Просмотры по дням</h3>
          <div class="cp-chart" id="cpChart" style="--n:<?= count($stats['series']) ?>">
            <?php foreach ($stats['series'] as $i => $p): ?>
              <?php $ts = strtotime($p['day']); ?>
              <div class="cp-bar<?= $p['views'] ? '' : ' empty' ?>" data-tip="<?= admin_h(date('d.m.Y', $ts) . "\nпросмотры: " . $p['views'] . "\nпосетители: " . $p['uniques']) ?>">
                <i style="height:<?= $p['views'] ? max(2, round($p['views'] / $maxDay * 100, 1)) : 0 ?>%"></i>
              </div>
            <?php endforeach; ?>
            <div class="cp-tip" id="cpTip" hidden></div>
          </div>
          <div class="cp-axis">
            <span><?= admin_h(date('d.m', strtotime($stats['series'][0]['day']))) ?></span>
            <span>макс. <?= $maxDay ?> в день</span>
            <span>сегодня</span>
          </div>
        </div>

        <div class="panel cp-pages" style="margin-top:12px">
          <div class="cp-pages-head">
            <h3 class="cp-h3" style="margin:0">Страницы</h3>
            <div class="cp-range cp-sort" id="cpSort">
              <button type="button" data-sort="v" class="on">По просмотрам</button>
              <button type="button" data-sort="u">По посетителям</button>
            </div>
          </div>
          <?php if (!$stats['pages']): ?>
            <p class="cp-note">Пока пусто.</p>
          <?php else: ?>
            <div class="cp-row cp-row-head"><span class="cp-row-k">Страница</span><span class="cp-row-n">Просмотры</span><span class="cp-row-n">Посетители</span></div>
            <div id="cpPages">
              <?php foreach ($stats['pages'] as $r): ?>
                <div class="cp-row" title="<?= admin_h($r['k']) ?>" data-v="<?= (int)$r['v'] ?>" data-u="<?= (int)$r['u'] ?>">
                  <span class="cp-row-bar"></span>
                  <span class="cp-row-k"><?= admin_h($page_names[$r['k']] ?? $r['k']) ?></span>
                  <span class="cp-row-n"><?= number_format((int)$r['v'], 0, ',', ' ') ?></span>
                  <span class="cp-row-n"><?= number_format((int)$r['u'], 0, ',', ' ') ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <div class="panel" style="margin-top:12px">
          <h3 class="cp-h3">История из логов FastPanel</h3>
          <?php if ($stats_logs): ?>
            <p class="cp-note">Найдено файлов логов: <?= count($stats_logs) ?> (<?= admin_h(implode(', ', array_map('basename', array_slice($stats_logs, 0, 4)))) ?><?= count($stats_logs) > 4 ? ' и другие' : '' ?>). Импорт берёт только время до начала собственного подсчёта, поэтому ничего не задвоится. Можно запускать повторно.</p>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= admin_h($csrf) ?>">
              <input type="hidden" name="action" value="import_logs">
              <button class="btn cp-save" type="submit">Подтянуть историю</button>
            </form>
          <?php else: ?>
            <p class="cp-note">Логи не найдены или PHP не может их прочитать. Проверял: <?= admin_h(implode(', ', stats_log_dirs_checked())) ?>.</p>
          <?php endif; ?>
          <p class="cp-note" style="margin:10px 0 0">Всего в базе: <?= number_format($stats['all_views'], 0, ',', ' ') ?> просмотров<?= $stats['first_day'] ? ' с ' . admin_h(date('d.m.Y', strtotime($stats['first_day']))) : '' ?><?= $stats['log_rows'] ? ', из них из логов ' . number_format($stats['log_rows'], 0, ',', ' ') : '' ?>. Боты и твои визиты как админа не считаются, IP не сохраняется.</p>
        </div>
      <?php endif; ?>
    </section>

    <section class="cp-pane" id="pane-home">
      <div class="panel">
        <form class="cp-form" method="post">
          <input type="hidden" name="csrf" value="<?= admin_h($csrf) ?>">
          <input type="hidden" name="action" value="save_home">
          <h3>Шапка главной</h3>
          <div class="two">
            <div class="field"><label>Плашка над именем</label><input name="hero_tag" value="<?= admin_h((string)($cfg['hero']['tag'] ?? '')) ?>"></div>
            <div class="field"><label>Имя</label><input name="hero_name" value="<?= admin_h((string)($cfg['hero']['name'] ?? '')) ?>"></div>
          </div>
          <div class="field"><label>Текст под именем</label><textarea name="hero_subtitle" rows="3"><?= admin_h((string)($cfg['hero']['subtitle'] ?? '')) ?></textarea></div>
          <h3>Терминал</h3>
          <div class="two">
            <div class="field"><label>Имя хоста</label><input name="term_hostname" value="<?= admin_h((string)($cfg['terminal']['hostname'] ?? '')) ?>"></div>
            <div class="field"><label>Ответ на whoami</label><input name="term_whoami" value="<?= admin_h((string)($cfg['terminal']['whoami'] ?? '')) ?>"></div>
          </div>
          <div class="field"><label>Приветствие</label><textarea name="term_welcome" rows="2"><?= admin_h((string)($cfg['terminal']['welcome'] ?? '')) ?></textarea></div>
          <h3>Подвал</h3>
          <div class="field"><label>Текст внизу страницы</label><input name="footer_text" value="<?= admin_h((string)($cfg['footer']['text'] ?? '')) ?>"></div>
          <button class="btn cp-save" type="submit">Сохранить</button>
        </form>

        <details class="cp-json">
          <summary>Весь конфиг в JSON (файлы терминала и остальное)</summary>
          <form class="cp-form" method="post" style="margin-top:10px">
            <input type="hidden" name="csrf" value="<?= admin_h($csrf) ?>">
            <input type="hidden" name="action" value="save_json">
            <div class="field"><textarea name="json" spellcheck="false"><?= admin_h($cfg_json) ?></textarea></div>
            <button class="btn cp-save" type="submit">Сохранить JSON</button>
          </form>
        </details>
      </div>
    </section>

    <section class="cp-pane" id="pane-users">
      <div class="panel" style="overflow-x:auto">
        <p class="cp-note">Админ может всё. <?php foreach (ROLES as $rk => $rd): if ($rk === 'admin') continue; ?><?= admin_h($rd['label']) ?>: <?= admin_h($rd['hint']) ?>. <?php endforeach; ?></p>
        <table class="data-table cp-table">
          <thead><tr><th>Логин</th><th>Роли</th><th>Зарегистрирован</th></tr></thead>
          <tbody>
            <?php foreach ($users as $u): ?>
              <?php $isMe = ($u['id'] ?? '') === ($me['id'] ?? ''); $uRoles = user_roles($u); ?>
              <tr>
                <td><?= admin_h((string)($u['username'] ?? '')) ?> <?php if ($isMe): ?><span class="cp-pill me">это ты</span><?php endif; ?></td>
                <td>
                  <form class="cp-roles" method="post">
                    <input type="hidden" name="csrf" value="<?= admin_h($csrf) ?>">
                    <input type="hidden" name="action" value="set_roles">
                    <input type="hidden" name="uid" value="<?= admin_h((string)($u['id'] ?? '')) ?>">
                    <?php foreach (ROLES as $rk => $rd): ?>
                      <?php $locked = $isMe && $rk === 'admin'; ?>
                      <label class="cp-check<?= $locked ? ' locked' : '' ?>" title="<?= admin_h($locked ? 'Себе админа снять нельзя' : $rd['hint']) ?>">
                        <input type="checkbox" name="roles[]" value="<?= admin_h($rk) ?>"<?= in_array($rk, $uRoles, true) ? ' checked' : '' ?><?= $locked ? ' disabled' : '' ?>>
                        <span><?= admin_h($rd['label']) ?></span>
                      </label>
                    <?php endforeach; ?>
                    <button class="btn cp-small cp-roles-save" type="submit" hidden>Сохранить</button>
                  </form>
                </td>
                <td><?= admin_h(($ts = strtotime((string)($u['created_at'] ?? ''))) ? date('d.m.Y', $ts) : '—') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="cp-pane" id="pane-sessions">
      <div class="panel" style="overflow-x:auto">
        <p class="cp-note">Устройства, где ты вошёл. Вход держится 90 дней с последнего захода и продлевается сам.</p>
        <?php if (!$sessions): ?>
          <p class="cp-note">Сохранённых входов нет. Выйди и войди заново, чтобы включить долгий вход на этом устройстве.</p>
        <?php else: ?>
          <table class="data-table cp-table">
            <thead><tr><th>Устройство</th><th>Последний заход</th><th>Вход</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($sessions as $s): ?>
                <tr>
                  <td><?= admin_h($s['device']) ?> <?php if ($s['current']): ?><span class="cp-pill me">это устройство</span><?php endif; ?></td>
                  <td><?= admin_h(admin_when($s['refreshed'])) ?></td>
                  <td><?= admin_h(date('d.m.Y', $s['created'])) ?></td>
                  <td style="text-align:right">
                    <?php if (!$s['current']): ?>
                      <form class="cp-inline" method="post">
                        <input type="hidden" name="csrf" value="<?= admin_h($csrf) ?>">
                        <input type="hidden" name="action" value="revoke_session">
                        <input type="hidden" name="token" value="<?= admin_h($s['id']) ?>">
                        <button class="btn cp-small cp-danger" type="submit">Отозвать</button>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php if (count($sessions) > 1): ?>
            <form method="post" style="margin-top:12px">
              <input type="hidden" name="csrf" value="<?= admin_h($csrf) ?>">
              <input type="hidden" name="action" value="revoke_others">
              <button class="btn cp-danger" type="submit" onclick="return confirm('Выйти на всех остальных устройствах?')">Выйти на остальных устройствах</button>
            </form>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </section>
  </div>
</main>

<script>
(function () {
  const tabs = document.querySelectorAll('#cpTabs [data-tab]');
  function show(name) {
    if (!document.getElementById('pane-' + name)) name = 'sections';
    tabs.forEach((t) => t.classList.toggle('on', t.dataset.tab === name));
    document.querySelectorAll('.cp-pane').forEach((p) => p.classList.toggle('on', p.id === 'pane-' + name));
  }
  tabs.forEach((t) => t.addEventListener('click', (e) => {
    e.preventDefault();
    history.replaceState(null, '', '#' + t.dataset.tab);
    show(t.dataset.tab);
  }));
  show(location.hash.slice(1));

  const chart = document.getElementById('cpChart'), tip = document.getElementById('cpTip');
  if (chart && tip) {
    chart.addEventListener('mousemove', (e) => {
      const bar = e.target.closest('.cp-bar');
      if (!bar) { tip.hidden = true; return; }
      const r = chart.getBoundingClientRect(), b = bar.getBoundingClientRect();
      tip.textContent = bar.dataset.tip.replace('\\n', '\n');
      tip.hidden = false;
      const x = Math.min(Math.max(b.left + b.width / 2 - r.left, 70), r.width - 70);
      tip.style.left = x + 'px';
      tip.style.top = '-6px';
    });
    chart.addEventListener('mouseleave', () => { tip.hidden = true; });
  }

  // Страницы: сортировка и полоска по выбранной метрике
  const pages = document.getElementById('cpPages');
  function sortPages(key) {
    if (!pages) return;
    const rows = [...pages.children];
    rows.sort((a, b) => b.dataset[key] - a.dataset[key]);
    const max = Math.max(1, ...rows.map((r) => +r.dataset[key]));
    const col = key === 'v' ? 0 : 1;
    rows.forEach((r) => {
      r.querySelector('.cp-row-bar').style.width = (r.dataset[key] / max * 100) + '%';
      r.querySelectorAll('.cp-row-n').forEach((n, k) => n.classList.toggle('on', k === col));
      pages.append(r);
    });
    document.querySelectorAll('#cpSort [data-sort]').forEach((b) => b.classList.toggle('on', b.dataset.sort === key));
  }
  document.querySelectorAll('#cpSort [data-sort]').forEach((b) => b.addEventListener('click', () => sortPages(b.dataset.sort)));
  sortPages('v');

  document.querySelectorAll('.cp-roles').forEach((f) => {
    const btn = f.querySelector('.cp-roles-save');
    const initial = () => [...f.querySelectorAll('input[type=checkbox]')].map((c) => c.checked).join();
    const start = initial();
    f.addEventListener('change', () => { btn.hidden = initial() === start; });
  });
})();
</script>
