<?php
declare(strict_types=1);

require_once __DIR__ . '/auth/lib.php';
require_once __DIR__ . '/includes/collection_lib.php';
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

    if ($action === 'set_role') {
        $uid = (string)($_POST['uid'] ?? '');
        $role = (string)($_POST['role'] ?? '') === 'admin' ? 'admin' : 'user';
        if ($uid === ($me['id'] ?? '') && $role !== 'admin') {
            admin_flash('err', 'Себя из админов убрать нельзя, иначе некому будет вернуть.');
            admin_back('users');
        }
        $data = users_load();
        foreach ($data['users'] as $u) {
            if (($u['id'] ?? '') === $uid) {
                $u['role'] = $role;
                $u['updated_at'] = date('c');
                user_update($data, $u);
                users_save($data);
                admin_flash('ok', ($u['username'] ?? '') . ': теперь ' . ($role === 'admin' ? 'админ' : 'обычный пользователь') . '.');
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
  .ad-head{ display:flex; justify-content:space-between; align-items:flex-end; gap:12px; flex-wrap:wrap; margin-bottom:18px; }
  .ad-head .page-title{ margin:0; }
  .ad-tabs{ display:flex; gap:4px; background:var(--panel); border:1px solid var(--line); border-radius:11px; padding:4px; margin-bottom:16px; overflow-x:auto; }
  .ad-tabs a{ flex:none; padding:8px 14px; border-radius:8px; color:var(--text-2); text-decoration:none; font-size:14px; font-weight:600; }
  .ad-tabs a:hover{ color:var(--text); }
  .ad-tabs a.on{ background:var(--accent); color:#1b1606; }
  .ad-pane{ display:none; }
  .ad-pane.on{ display:block; }

  .ad-flash{ padding:12px 14px; border-radius:10px; margin-bottom:14px; font-size:14px; border:1px solid; }
  .ad-flash.ok{ color:var(--green); border-color:rgba(97,209,173,.35); background:rgba(97,209,173,.07); }
  .ad-flash.err{ color:var(--danger); border-color:rgba(255,143,143,.35); background:rgba(255,143,143,.07); }

  .ad-grid{ display:grid; grid-template-columns:repeat(auto-fill, minmax(240px, 1fr)); gap:12px; }
  .ad-tile{ display:flex; flex-direction:column; gap:6px; }
  .ad-tile h3{ margin:0; font-size:17px; }
  .ad-tile .stat{ font-size:20px; font-weight:800; color:var(--accent); }
  .ad-tile .muted{ font-size:12px; color:var(--muted); }
  .ad-tile .row{ display:flex; gap:8px; margin-top:auto; padding-top:8px; }
  .ad-tile .row .btn{ flex:1; text-align:center; text-decoration:none; }
  .ad-add{ border-color:var(--accent); color:var(--accent); }

  .ad-form{ display:grid; gap:12px; }
  .ad-form .two{ display:grid; grid-template-columns:1fr 1fr; gap:12px; }
  .ad-form h3{ margin:8px 0 0; font-size:14px; color:var(--text-2); font-weight:600; }
  .ad-save{ background:var(--accent); color:#1b1606; border-color:var(--accent); justify-self:start; }
  .ad-save:hover{ color:#1b1606; filter:brightness(1.08); }
  .ad-json{ margin-top:16px; }
  .ad-json summary{ cursor:pointer; color:var(--text-2); font-size:14px; }
  .ad-json textarea{ min-height:360px; font-family:var(--mono); font-size:12px; }

  .ad-table td, .ad-table th{ vertical-align:middle; }
  .ad-pill{ display:inline-block; padding:2px 8px; border-radius:999px; font-size:12px; border:1px solid var(--line); color:var(--text-2); }
  .ad-pill.admin{ color:var(--accent); border-color:rgba(249,201,64,.4); }
  .ad-pill.me{ color:var(--green); border-color:rgba(97,209,173,.4); }
  .ad-inline{ display:inline; margin:0; }
  .ad-small{ padding:6px 10px; font-size:13px; }
  .ad-danger:hover{ border-color:var(--danger); color:var(--danger); }
  .ad-note{ color:var(--muted); font-size:13px; margin:0 0 12px; }

  @media (max-width: 700px){
    .ad-form .two{ grid-template-columns:1fr; }
    .ad-table th:nth-child(3), .ad-table td:nth-child(3){ display:none; }
  }
</style>

<main class="page">
  <div class="page-wrap">
    <div class="ad-head">
      <div>
        <div class="page-label">// admin</div>
        <h1 class="page-title">Админка</h1>
      </div>
      <a class="btn" href="/auth/logout.php">Выйти</a>
    </div>

    <?php foreach ($load_errors as $le): ?>
      <div class="ad-flash err"><?= admin_h($le) ?></div>
    <?php endforeach; ?>
    <?php if ($flash): ?>
      <div class="ad-flash <?= $flash[0] === 'ok' ? 'ok' : 'err' ?>"><?= admin_h($flash[1]) ?></div>
    <?php endif; ?>

    <nav class="ad-tabs" id="adTabs">
      <a href="#sections" data-tab="sections" class="on">Разделы</a>
      <a href="#home" data-tab="home">Главная</a>
      <a href="#users" data-tab="users">Пользователи</a>
      <a href="#sessions" data-tab="sessions">Мои входы</a>
    </nav>

    <section class="ad-pane on" id="pane-sections">
      <div class="ad-grid">
        <?php foreach ($sections as $s): ?>
          <div class="panel ad-tile">
            <h3><?= admin_h($s['title']) ?></h3>
            <div class="stat"><?= admin_h($s['stat']) ?></div>
            <div class="muted"><?= admin_h($s['last']) ?></div>
            <div class="row">
              <a class="btn ad-small" href="<?= admin_h($s['url']) ?>">Открыть</a>
              <?php if ($s['add']): ?><a class="btn ad-small ad-add" href="<?= admin_h($s['add']) ?>">+ Добавить</a><?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="ad-pane" id="pane-home">
      <div class="panel">
        <form class="ad-form" method="post">
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
          <button class="btn ad-save" type="submit">Сохранить</button>
        </form>

        <details class="ad-json">
          <summary>Весь конфиг в JSON (файлы терминала и остальное)</summary>
          <form class="ad-form" method="post" style="margin-top:10px">
            <input type="hidden" name="csrf" value="<?= admin_h($csrf) ?>">
            <input type="hidden" name="action" value="save_json">
            <div class="field"><textarea name="json" spellcheck="false"><?= admin_h($cfg_json) ?></textarea></div>
            <button class="btn ad-save" type="submit">Сохранить JSON</button>
          </form>
        </details>
      </div>
    </section>

    <section class="ad-pane" id="pane-users">
      <div class="panel" style="overflow-x:auto">
        <table class="data-table ad-table">
          <thead><tr><th>Логин</th><th>Роль</th><th>Зарегистрирован</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($users as $u): ?>
              <?php $isMe = ($u['id'] ?? '') === ($me['id'] ?? ''); $isAdmin = ($u['role'] ?? '') === 'admin'; ?>
              <tr>
                <td><?= admin_h((string)($u['username'] ?? '')) ?> <?php if ($isMe): ?><span class="ad-pill me">это ты</span><?php endif; ?></td>
                <td><span class="ad-pill<?= $isAdmin ? ' admin' : '' ?>"><?= $isAdmin ? 'админ' : 'пользователь' ?></span></td>
                <td><?= admin_h(($ts = strtotime((string)($u['created_at'] ?? ''))) ? date('d.m.Y', $ts) : '—') ?></td>
                <td style="text-align:right">
                  <?php if (!$isMe): ?>
                    <form class="ad-inline" method="post">
                      <input type="hidden" name="csrf" value="<?= admin_h($csrf) ?>">
                      <input type="hidden" name="action" value="set_role">
                      <input type="hidden" name="uid" value="<?= admin_h((string)($u['id'] ?? '')) ?>">
                      <input type="hidden" name="role" value="<?= $isAdmin ? 'user' : 'admin' ?>">
                      <button class="btn ad-small" type="submit"><?= $isAdmin ? 'Снять админа' : 'Сделать админом' ?></button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="ad-pane" id="pane-sessions">
      <div class="panel" style="overflow-x:auto">
        <p class="ad-note">Устройства, где ты вошёл. Вход держится 90 дней с последнего захода и продлевается сам.</p>
        <?php if (!$sessions): ?>
          <p class="ad-note">Сохранённых входов нет. Выйди и войди заново, чтобы включить долгий вход на этом устройстве.</p>
        <?php else: ?>
          <table class="data-table ad-table">
            <thead><tr><th>Устройство</th><th>Последний заход</th><th>Вход</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($sessions as $s): ?>
                <tr>
                  <td><?= admin_h($s['device']) ?> <?php if ($s['current']): ?><span class="ad-pill me">это устройство</span><?php endif; ?></td>
                  <td><?= admin_h(admin_when($s['refreshed'])) ?></td>
                  <td><?= admin_h(date('d.m.Y', $s['created'])) ?></td>
                  <td style="text-align:right">
                    <?php if (!$s['current']): ?>
                      <form class="ad-inline" method="post">
                        <input type="hidden" name="csrf" value="<?= admin_h($csrf) ?>">
                        <input type="hidden" name="action" value="revoke_session">
                        <input type="hidden" name="token" value="<?= admin_h($s['id']) ?>">
                        <button class="btn ad-small ad-danger" type="submit">Отозвать</button>
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
              <button class="btn ad-danger" type="submit" onclick="return confirm('Выйти на всех остальных устройствах?')">Выйти на остальных устройствах</button>
            </form>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </section>
  </div>
</main>

<script>
(function () {
  const tabs = document.querySelectorAll('#adTabs [data-tab]');
  function show(name) {
    if (!document.getElementById('pane-' + name)) name = 'sections';
    tabs.forEach((t) => t.classList.toggle('on', t.dataset.tab === name));
    document.querySelectorAll('.ad-pane').forEach((p) => p.classList.toggle('on', p.id === 'pane-' + name));
  }
  tabs.forEach((t) => t.addEventListener('click', (e) => {
    e.preventDefault();
    history.replaceState(null, '', '#' + t.dataset.tab);
    show(t.dataset.tab);
  }));
  show(location.hash.slice(1));
})();
</script>
