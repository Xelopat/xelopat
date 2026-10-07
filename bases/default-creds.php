<?php
// База заводских логинов/паролей по умолчанию (SQLite). Публичные данные из мануалов
// и списка SecLists — чтобы проверить и сменить дефолты на своём оборудовании.
declare(strict_types=1);

$site_page_title = 'Пароли по умолчанию — xelopat';

const DC_PER_PAGE = 100;

$dc_db_path = $_SERVER['DOCUMENT_ROOT'] . '/data/default_creds.sqlite';
$dc_query = trim((string)($_GET['q'] ?? ''));
$dc_type = trim((string)($_GET['type'] ?? ''));
$dc_page = max(1, (int)($_GET['page'] ?? 1));

$dc_error = '';
$dc_total = 0;
$dc_all = 0;
$dc_types = [];
$dc_results = [];

try {
    if (!is_file($dc_db_path)) throw new RuntimeException('База не найдена.');
    $pdo = new PDO('sqlite:' . $dc_db_path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $dc_all = (int)$pdo->query('SELECT COUNT(*) FROM creds')->fetchColumn();
    foreach ($pdo->query('SELECT type, COUNT(*) c FROM creds GROUP BY type') as $r) $dc_types[(string)$r['type']] = (int)$r['c'];
    // Сначала устройства, потом общие категории
    $order = ['Роутер', 'Камера', 'Видеорегистратор', 'NAS', 'Коммутатор', 'Принтер', 'IP-телефон', 'ИБП/питание', 'Сервер/ПО', 'Прочее'];
    uksort($dc_types, function ($a, $b) use ($order) {
        $ia = array_search($a, $order, true); $ib = array_search($b, $order, true);
        $ia = $ia === false ? 99 : $ia; $ib = $ib === false ? 99 : $ib;
        return $ia <=> $ib;
    });

    $where = [];
    $params = [];
    if ($dc_type !== '') { $where[] = 'type = :type'; $params[':type'] = $dc_type; }
    foreach (preg_split('/\s+/u', $dc_query, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $i => $term) {
        $k = ':t' . $i;
        $where[] = "(vendor LIKE $k OR model LIKE $k OR login LIKE $k OR password LIKE $k OR note LIKE $k)";
        $params[$k] = '%' . $term . '%';
    }
    $sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $st = $pdo->prepare('SELECT COUNT(*) FROM creds' . $sql);
    $st->execute($params);
    $dc_total = (int)$st->fetchColumn();

    $pages = max(1, (int)ceil($dc_total / DC_PER_PAGE));
    if ($dc_page > $pages) $dc_page = $pages;
    $offset = ($dc_page - 1) * DC_PER_PAGE;

    $st = $pdo->prepare('SELECT vendor, model, type, login, password, access, note, featured FROM creds'
        . $sql . ' ORDER BY featured DESC, vendor COLLATE NOCASE, model LIMIT ' . DC_PER_PAGE . ' OFFSET ' . $offset);
    $st->execute($params);
    $dc_results = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $dc_error = $e->getMessage();
}

include $_SERVER['DOCUMENT_ROOT'] . '/header.php';

function dc_h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function dc_qs(array $over): string {
    $base = ['q' => $GLOBALS['dc_query'], 'type' => $GLOBALS['dc_type'], 'page' => $GLOBALS['dc_page']];
    $q = array_filter(array_merge($base, $over), fn($v) => $v !== '' && $v !== null);
    return $q ? '?' . http_build_query($q) : '?';
}
$dc_pages = max(1, (int)ceil($dc_total / DC_PER_PAGE));
?>
<style>
  .dc-note{
    display:flex; gap:10px; align-items:flex-start;
    background:rgba(249,201,64,.08); border:1px solid rgba(249,201,64,.3);
    border-radius:10px; padding:12px 14px; margin:0 0 18px;
    font-size:13px; line-height:1.55; color:var(--text-2);
  }
  .dc-note b{ color:var(--accent); }
  .dc-tools{ display:flex; flex-wrap:wrap; gap:8px; margin-bottom:14px; }
  .dc-tools input[type="search"]{
    flex:1; min-width:220px;
    background:var(--panel); border:1px solid var(--line); border-radius:9px;
    padding:10px 13px; color:var(--text); font:inherit; font-size:14px; outline:none;
  }
  .dc-tools input:focus{ border-color:var(--green); }
  .dc-chips{ display:flex; flex-wrap:wrap; gap:6px; margin-bottom:16px; }
  .dc-chip{
    padding:6px 12px; border-radius:999px; border:1px solid var(--line);
    background:var(--panel); color:var(--text-2); font-size:13px; text-decoration:none;
  }
  .dc-chip:hover{ color:var(--text); border-color:var(--green); }
  .dc-chip.on{ background:var(--accent); color:#1b1606; border-color:transparent; font-weight:600; }
  .dc-chip small{ opacity:.65; margin-left:3px; }
  .dc-count{ font-family:var(--mono); font-size:11px; color:var(--muted); margin-bottom:10px; }
  .dc-tablewrap{ overflow-x:auto; }
  .dc-cred{ font-family:var(--mono); color:var(--accent); }
  .dc-type{
    display:inline-block; font-size:11px; font-family:var(--mono);
    padding:2px 7px; border-radius:5px; background:var(--panel-2); border:1px solid var(--line); color:var(--text-2);
  }
  .dc-star{ color:var(--accent); }
  .dc-note-cell{ color:var(--muted); font-size:12px; }
  .dc-empty{ padding:30px 0; text-align:center; color:var(--muted); }
  .data-table td{ vertical-align:top; }
  .dc-pager{ display:flex; gap:8px; align-items:center; justify-content:center; margin-top:18px; flex-wrap:wrap; }
  .dc-pager a, .dc-pager span{ padding:7px 12px; border-radius:8px; font-size:13px; }
  .dc-pager a{ border:1px solid var(--line); background:var(--panel); color:var(--text); text-decoration:none; }
  .dc-pager a:hover{ border-color:var(--accent); color:var(--accent); }
  .dc-pager .cur{ color:var(--muted); font-family:var(--mono); }
  @media (max-width: 680px){
    .data-table thead{ display:none; }
    .data-table tbody tr{ display:block; border-bottom:1px solid var(--line); padding:8px 0; }
    .data-table td{ display:block; padding:3px 8px; border:none; }
    .data-table td::before{ content:attr(data-label) ": "; color:var(--muted); font-family:var(--mono); font-size:10px; text-transform:uppercase; }
    .data-table td.dc-td-vendor::before{ content:""; }
    .dc-td-vendor{ font-weight:700; font-size:15px; padding-top:6px; }
    .dc-note-cell:empty, td[data-label="Доступ"]:empty, td[data-label="Модель"]:empty{ display:none; }
  }
</style>

<main class="page">
  <div class="page-wrap">
    <div class="page-label">// базы</div>
    <h1 class="page-title">Пароли по умолчанию</h1>
    <p class="page-sub">Заводские логины и пароли роутеров, камер, сетевых устройств и ПО из открытых мануалов и списка SecLists.</p>

    <div class="dc-note">
      <span>🔒</span>
      <span>Только для проверки <b>своего</b> оборудования. Если в вашу камеру или роутер до сих пор можно войти с заводским паролем — его немедленно нужно сменить. Доступ к чужим устройствам без разрешения незаконен.</span>
    </div>

    <?php if ($dc_error !== ''): ?>
      <div class="dc-empty" style="color:var(--danger)">База недоступна: <?= dc_h($dc_error) ?></div>
    <?php else: ?>
      <form class="dc-tools" method="get">
        <?php if ($dc_type !== ''): ?><input type="hidden" name="type" value="<?= dc_h($dc_type) ?>"><?php endif; ?>
        <input type="search" name="q" value="<?= dc_h($dc_query) ?>" placeholder="Например: Hikvision, TP-Link, admin, камера" autofocus>
        <button class="btn" type="submit">Найти</button>
      </form>

      <div class="dc-chips">
        <a class="dc-chip<?= $dc_type === '' ? ' on' : '' ?>" href="<?= dc_h(dc_qs(['type' => '', 'page' => 1])) ?>">Все <small><?= $dc_all ?></small></a>
        <?php foreach ($dc_types as $t => $c): ?>
          <a class="dc-chip<?= $dc_type === $t ? ' on' : '' ?>" href="<?= dc_h(dc_qs(['type' => $t, 'page' => 1])) ?>"><?= dc_h($t) ?> <small><?= $c ?></small></a>
        <?php endforeach; ?>
      </div>

      <div class="dc-count">Найдено: <?= number_format($dc_total, 0, '.', ' ') ?><?= $dc_pages > 1 ? ' · страница ' . $dc_page . ' из ' . $dc_pages : '' ?></div>

      <?php if (!$dc_results): ?>
        <div class="dc-empty">Ничего не найдено. Попробуйте другое название производителя или модели.</div>
      <?php else: ?>
        <div class="dc-tablewrap">
          <table class="data-table">
            <thead>
              <tr><th>Производитель</th><th>Модель</th><th>Тип</th><th>Логин</th><th>Пароль</th><th>Доступ</th><th>Примечание</th></tr>
            </thead>
            <tbody>
              <?php foreach ($dc_results as $it): ?>
                <tr>
                  <td class="dc-td-vendor" data-label="Производитель"><?= $it['featured'] ? '<span class="dc-star" title="Проверенная запись">★</span> ' : '' ?><?= dc_h((string)$it['vendor']) ?></td>
                  <td data-label="Модель"><?= dc_h((string)$it['model']) ?></td>
                  <td data-label="Тип"><span class="dc-type"><?= dc_h((string)$it['type']) ?></span></td>
                  <td data-label="Логин"><span class="dc-cred"><?= dc_h((string)$it['login']) ?></span></td>
                  <td data-label="Пароль"><span class="dc-cred"><?= dc_h((string)$it['password']) ?></span></td>
                  <td data-label="Доступ"><?= dc_h((string)$it['access']) ?></td>
                  <td class="dc-note-cell" data-label="Примечание"><?= dc_h((string)$it['note']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <?php if ($dc_pages > 1): ?>
          <div class="dc-pager">
            <?php if ($dc_page > 1): ?><a href="<?= dc_h(dc_qs(['page' => $dc_page - 1])) ?>">← Назад</a><?php endif; ?>
            <span class="cur"><?= $dc_page ?> / <?= $dc_pages ?></span>
            <?php if ($dc_page < $dc_pages): ?><a href="<?= dc_h(dc_qs(['page' => $dc_page + 1])) ?>">Вперёд →</a><?php endif; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</main>
