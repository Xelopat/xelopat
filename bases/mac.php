<?php
// Производитель по MAC-адресу. Данные — реестр IEEE OUI (MA-L / MA-M / MA-S), публичный.
declare(strict_types=1);

$site_page_title = 'Производитель по MAC — xelopat';

const MAC_PER_PAGE = 100;

$mac_db_path = $_SERVER['DOCUMENT_ROOT'] . '/data/mac_oui.sqlite';
$mac_query = trim((string)($_GET['q'] ?? ''));
$mac_page = max(1, (int)($_GET['page'] ?? 1));

$mac_error = '';
$mac_total_db = 0;
$mac_mode = '';          // 'mac' — поиск по адресу, 'vendor' — по названию
$mac_hex = '';
$mac_pretty = '';
$mac_match = null;       // найденная запись при поиске по адресу
$mac_list = [];          // список при поиске по названию
$mac_total = 0;
$mac_pages = 1;

function mac_h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

try {
    if (!is_file($mac_db_path)) throw new RuntimeException('База не найдена.');
    $pdo = new PDO('sqlite:' . $mac_db_path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $mac_total_db = (int)$pdo->query('SELECT COUNT(*) FROM oui')->fetchColumn();

    if ($mac_query !== '') {
        $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $mac_query) ?? '');
        $letters = preg_replace('/[0-9A-Fa-f\s:.\-]/', '', $mac_query) ?? '';
        // Если есть буквы вне hex — это название производителя
        if ($letters !== '' || $hex === '') {
            $mac_mode = 'vendor';
            $like = '%' . $mac_query . '%';
            $cnt = $pdo->prepare('SELECT COUNT(*) FROM oui WHERE vendor LIKE ?');
            $cnt->execute([$like]);
            $mac_total = (int)$cnt->fetchColumn();
            $mac_pages = max(1, (int)ceil($mac_total / MAC_PER_PAGE));
            if ($mac_page > $mac_pages) $mac_page = $mac_pages;
            $st = $pdo->prepare('SELECT prefix, len, vendor, address FROM oui WHERE vendor LIKE ? ORDER BY vendor COLLATE NOCASE, len LIMIT ' . MAC_PER_PAGE . ' OFFSET ' . (($mac_page - 1) * MAC_PER_PAGE));
            $st->execute([$like]);
            $mac_list = $st->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $mac_mode = 'mac';
            $mac_hex = substr($hex, 0, 12);
            // Красивый вид: 28:6F:B9:...
            $mac_pretty = implode(':', str_split($mac_hex, 2));
            // Самый длинный подходящий префикс: сначала MA-S (9), потом MA-M (7), потом MA-L (6)
            $st = $pdo->prepare('SELECT prefix, len, vendor, address FROM oui WHERE prefix = ? AND len = ?');
            foreach ([9, 7, 6] as $len) {
                if (strlen($mac_hex) < $len) continue;
                $st->execute([substr($mac_hex, 0, $len), $len]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if ($row) { $mac_match = $row; break; }
            }
        }
    }
} catch (Throwable $e) {
    $mac_error = $e->getMessage();
}

include $_SERVER['DOCUMENT_ROOT'] . '/header.php';

function mac_fmt_prefix(string $prefix): string {
    return implode(':', str_split($prefix, 2)) . (strlen($prefix) % 2 ? '' : '');
}
function mac_qs(int $page): string {
    return '?' . http_build_query(['q' => $GLOBALS['mac_query'], 'page' => $page]);
}
?>
<link rel="stylesheet" href="/assets/bases.css">

<main class="b-shell">
  <div class="b-wrap">
    <section class="b-head">
      <div>
        <span class="b-kicker">// базы</span>
        <h1 class="b-title">Производитель по MAC</h1>
        <p class="b-sub">Введите MAC-адрес устройства, и я покажу, какая фирма его выпустила. Или наберите название фирмы, чтобы увидеть её префиксы.</p>
      </div>
      <div class="b-stats" style="--n:2">
        <div class="b-stat"><strong><?= number_format($mac_total_db, 0, '.', ' ') ?></strong><span>префиксов</span></div>
        <div class="b-stat"><strong>IEEE</strong><span>MA-L, MA-M, MA-S</span></div>
      </div>
    </section>

    <?php if ($mac_error !== ''): ?>
      <div class="b-alert">База недоступна: <?= mac_h($mac_error) ?></div>
    <?php else: ?>
      <section class="b-panel">
        <form class="b-form" method="get">
          <input class="b-input mono" type="search" name="q" value="<?= mac_h($mac_query) ?>" placeholder="28:6F:B9:12:34:56 или Apple" autofocus>
          <button class="b-btn" type="submit">Найти</button>
        </form>
        <p class="b-hint">Можно вводить в любом виде: <code>28:6F:B9</code>, <code>28-6F-B9</code>, <code>286FB9</code> или целиком.</p>
      </section>

      <?php if ($mac_mode === 'mac'): ?>
        <?php if ($mac_match): ?>
          <div class="b-result hit">
            <div class="mac"><b><?= mac_h(implode(':', str_split(substr($mac_hex, 0, (int)$mac_match['len']), 2))) ?></b><?= strlen($mac_hex) > (int)$mac_match['len'] ? mac_h(':' . implode(':', str_split(substr($mac_hex, (int)$mac_match['len']), 2))) : '' ?></div>
            <div class="who"><?= mac_h((string)$mac_match['vendor']) ?></div>
            <?php if ($mac_match['address'] !== ''): ?><div class="addr"><?= mac_h((string)$mac_match['address']) ?></div><?php endif; ?>
            <div class="meta">префикс <?= mac_h(implode(':', str_split((string)$mac_match['prefix'], 2))) ?>, <?= (int)$mac_match['len'] * 4 ?> бит</div>
          </div>
        <?php else: ?>
          <div class="b-result">
            <div class="mac"><b><?= mac_h($mac_pretty) ?></b></div>
            <div class="who miss">Производитель не найден</div>
            <div class="addr">Такого префикса нет в реестре IEEE. Возможно, это случайный (приватный) MAC: современные телефоны подставляют такой в Wi-Fi для приватности.</div>
          </div>
        <?php endif; ?>

      <?php elseif ($mac_mode === 'vendor'): ?>
        <div class="b-count">Найдено префиксов: <?= number_format($mac_total, 0, '.', ' ') ?><?= $mac_pages > 1 ? ', страница ' . $mac_page . ' из ' . $mac_pages : '' ?></div>
        <?php if (!$mac_list): ?>
          <div class="b-empty">Ничего не найдено.</div>
        <?php else: ?>
          <div class="b-tablewrap">
            <table class="b-table">
              <thead><tr><th>Префикс</th><th>Производитель</th><th>Адрес</th></tr></thead>
              <tbody>
                <?php foreach ($mac_list as $r): ?>
                  <tr>
                    <td data-label="Префикс"><span class="b-pill"><?= mac_h(implode(':', str_split((string)$r['prefix'], 2))) ?></span> <span class="b-tag"><?= (int)$r['len'] * 4 ?> бит</span></td>
                    <td class="b-cell-lead" data-label="Производитель"><?= mac_h((string)$r['vendor']) ?></td>
                    <td class="b-dim" data-label="Адрес"><?= mac_h((string)$r['address']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php if ($mac_pages > 1): ?>
            <div class="b-pager">
              <?php if ($mac_page > 1): ?><a href="<?= mac_h(mac_qs($mac_page - 1)) ?>">← Назад</a><?php endif; ?>
              <span class="cur"><?= $mac_page ?> / <?= $mac_pages ?></span>
              <?php if ($mac_page < $mac_pages): ?><a href="<?= mac_h(mac_qs($mac_page + 1)) ?>">Вперёд →</a><?php endif; ?>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      <?php else: ?>
        <div class="b-empty">Введите MAC-адрес или название производителя.</div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</main>
