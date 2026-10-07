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
<?php $dc_vendors = $dc_error === '' ? (int)$pdo->query('SELECT COUNT(DISTINCT vendor COLLATE NOCASE) FROM creds')->fetchColumn() : 0; ?>
<link rel="stylesheet" href="/assets/bases.css">

<main class="b-shell">
  <div class="b-wrap">
    <section class="b-head">
      <div>
        <span class="b-kicker">// базы</span>
        <h1 class="b-title">Пароли по умолчанию</h1>
        <p class="b-sub">Заводские логины и пароли роутеров, камер, сетевых устройств и ПО из открытых мануалов и списка SecLists.</p>
      </div>
      <div class="b-stats" style="--n:3">
        <div class="b-stat"><strong><?= number_format($dc_all, 0, '.', ' ') ?></strong><span>записей</span></div>
        <div class="b-stat"><strong><?= number_format($dc_vendors, 0, '.', ' ') ?></strong><span>производителей</span></div>
        <div class="b-stat"><strong><?= count($dc_types) ?></strong><span>типов</span></div>
      </div>
    </section>

    <?php if ($dc_error !== ''): ?>
      <div class="b-alert">База недоступна: <?= dc_h($dc_error) ?></div>
    <?php else: ?>
      <section class="b-panel">
        <form class="b-form" method="get">
          <?php if ($dc_type !== ''): ?><input type="hidden" name="type" value="<?= dc_h($dc_type) ?>"><?php endif; ?>
          <input class="b-input" type="search" name="q" value="<?= dc_h($dc_query) ?>" placeholder="Hikvision, TP-Link, admin, камера" autofocus>
          <button class="b-btn" type="submit">Найти</button>
        </form>
        <p class="b-hint">Ищет по производителю, модели, логину, паролю и примечанию. Слова через пробел сужают поиск. Звёздочкой отмечены проверенные записи.</p>
      </section>

      <div class="b-chips">
        <a class="b-chip<?= $dc_type === '' ? ' on' : '' ?>" href="<?= dc_h(dc_qs(['type' => '', 'page' => 1])) ?>">Все <small><?= $dc_all ?></small></a>
        <?php foreach ($dc_types as $t => $c): ?>
          <a class="b-chip<?= $dc_type === $t ? ' on' : '' ?>" href="<?= dc_h(dc_qs(['type' => $t, 'page' => 1])) ?>"><?= dc_h($t) ?> <small><?= $c ?></small></a>
        <?php endforeach; ?>
      </div>

      <div class="b-count">Найдено: <?= number_format($dc_total, 0, '.', ' ') ?><?= $dc_pages > 1 ? ', страница ' . $dc_page . ' из ' . $dc_pages : '' ?></div>

      <?php if (!$dc_results): ?>
        <div class="b-empty">Ничего не найдено. Попробуйте другое название производителя или модели.</div>
      <?php else: ?>
        <div class="b-tablewrap">
          <table class="b-table">
            <thead>
              <tr><th>Устройство</th><th>Тип</th><th>Логин</th><th>Пароль</th><th>Доступ и примечание</th></tr>
            </thead>
            <tbody>
              <?php foreach ($dc_results as $it): ?>
                <?php $extra = trim(dc_h((string)$it['access']) . ((string)$it['access'] !== '' && (string)$it['note'] !== '' ? '. ' : '') . dc_h((string)$it['note'])); ?>
                <tr>
                  <td class="b-cell-lead" data-label="Устройство">
                    <?= $it['featured'] ? '<span class="b-star" title="Проверенная запись">★</span> ' : '' ?><?= dc_h((string)$it['vendor']) ?>
                    <?php if ((string)$it['model'] !== ''): ?><div class="b-dim" style="font-weight:400"><?= dc_h((string)$it['model']) ?></div><?php endif; ?>
                  </td>
                  <td data-label="Тип"><span class="b-tag"><?= dc_h((string)$it['type']) ?></span></td>
                  <td data-label="Логин"><span class="b-pill"><?= dc_h((string)$it['login']) ?></span></td>
                  <td data-label="Пароль"><span class="b-pill"><?= dc_h((string)$it['password']) ?></span></td>
                  <td class="b-dim" data-label="Доступ и примечание"><?= $extra ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <?php if ($dc_pages > 1): ?>
          <div class="b-pager">
            <?php if ($dc_page > 1): ?><a href="<?= dc_h(dc_qs(['page' => $dc_page - 1])) ?>">← Назад</a><?php endif; ?>
            <span class="cur"><?= $dc_page ?> / <?= $dc_pages ?></span>
            <?php if ($dc_page < $dc_pages): ?><a href="<?= dc_h(dc_qs(['page' => $dc_page + 1])) ?>">Вперёд →</a><?php endif; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</main>
