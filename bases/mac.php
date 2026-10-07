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
<style>
  .mc-tools{ display:flex; flex-wrap:wrap; gap:8px; margin-bottom:8px; }
  .mc-tools input[type="search"]{
    flex:1; min-width:220px;
    background:var(--panel); border:1px solid var(--line); border-radius:9px;
    padding:10px 13px; color:var(--text); font:inherit; font-size:15px; font-family:var(--mono); outline:none;
  }
  .mc-tools input:focus{ border-color:var(--green); }
  .mc-hint{ font-size:12px; color:var(--muted); margin:0 0 18px; }
  .mc-card{
    background:var(--panel); border:1px solid var(--line); border-radius:14px; padding:20px; margin-bottom:14px;
  }
  .mc-card.found{ border-color:rgba(97,209,173,.4); }
  .mc-mac{ font-family:var(--mono); font-size:22px; color:var(--text); letter-spacing:.02em; }
  .mc-mac b{ color:var(--accent); }
  .mc-vendor{ font-size:24px; font-weight:800; margin:10px 0 4px; }
  .mc-addr{ color:var(--text-2); font-size:14px; }
  .mc-meta{ font-family:var(--mono); font-size:11px; color:var(--muted); margin-top:10px; }
  .mc-notfound{ color:var(--muted); }
  .mc-count{ font-family:var(--mono); font-size:11px; color:var(--muted); margin-bottom:10px; }
  .dc-tablewrap{ overflow-x:auto; }
  .mc-prefix{ font-family:var(--mono); color:var(--accent); white-space:nowrap; }
  .mc-tag{ display:inline-block; font-size:10px; font-family:var(--mono); padding:1px 6px; border-radius:5px; background:var(--panel-2); border:1px solid var(--line); color:var(--muted); }
  .mc-empty{ padding:30px 0; text-align:center; color:var(--muted); }
  .dc-pager{ display:flex; gap:8px; align-items:center; justify-content:center; margin-top:18px; flex-wrap:wrap; }
  .dc-pager a, .dc-pager span{ padding:7px 12px; border-radius:8px; font-size:13px; }
  .dc-pager a{ border:1px solid var(--line); background:var(--panel); color:var(--text); text-decoration:none; }
  .dc-pager a:hover{ border-color:var(--accent); color:var(--accent); }
  .dc-pager .cur{ color:var(--muted); font-family:var(--mono); }
  @media (max-width: 600px){
    .data-table thead{ display:none; }
    .data-table tbody tr{ display:block; border-bottom:1px solid var(--line); padding:8px 0; }
    .data-table td{ display:block; padding:3px 8px; border:none; }
    .data-table td::before{ content:attr(data-label) ": "; color:var(--muted); font-family:var(--mono); font-size:10px; text-transform:uppercase; }
    .mc-mac{ font-size:18px; }
  }
</style>

<main class="page">
  <div class="page-wrap">
    <div class="page-label">// базы</div>
    <h1 class="page-title">Производитель по MAC</h1>
    <p class="page-sub">Введите MAC-адрес устройства — покажу, какая фирма его выпустила. Или наберите название фирмы, чтобы увидеть её префиксы.</p>

    <?php if ($mac_error !== ''): ?>
      <div class="mc-empty" style="color:var(--danger)">База недоступна: <?= mac_h($mac_error) ?></div>
    <?php else: ?>
      <form class="mc-tools" method="get">
        <input type="search" name="q" value="<?= mac_h($mac_query) ?>" placeholder="28:6F:B9:12:34:56  или  Apple" autofocus>
        <button class="btn" type="submit">Найти</button>
      </form>
      <p class="mc-hint">Можно вводить в любом виде: <code>28:6F:B9</code>, <code>28-6F-B9</code>, <code>286FB9</code> или целиком. Реестр IEEE, записей: <?= number_format($mac_total_db, 0, '.', ' ') ?>.</p>

      <?php if ($mac_mode === 'mac'): ?>
        <?php if ($mac_match): ?>
          <div class="mc-card found">
            <div class="mc-mac"><b><?= mac_h(implode(':', str_split(substr($mac_hex, 0, (int)$mac_match['len']), 2))) ?></b><?= strlen($mac_hex) > (int)$mac_match['len'] ? mac_h(':' . implode(':', str_split(substr($mac_hex, (int)$mac_match['len']), 2))) : '' ?></div>
            <div class="mc-vendor"><?= mac_h((string)$mac_match['vendor']) ?></div>
            <?php if ($mac_match['address'] !== ''): ?><div class="mc-addr"><?= mac_h((string)$mac_match['address']) ?></div><?php endif; ?>
            <div class="mc-meta">префикс <?= mac_h(implode(':', str_split((string)$mac_match['prefix'], 2))) ?> · <?= (int)$mac_match['len'] * 4 ?> бит</div>
          </div>
        <?php else: ?>
          <div class="mc-card">
            <div class="mc-mac"><b><?= mac_h($mac_pretty) ?></b></div>
            <div class="mc-vendor mc-notfound">Производитель не найден</div>
            <div class="mc-addr">Такого префикса нет в реестре IEEE. Возможно, это случайный (приватный) MAC — современные телефоны подставляют такой в Wi-Fi для приватности.</div>
          </div>
        <?php endif; ?>

      <?php elseif ($mac_mode === 'vendor'): ?>
        <div class="mc-count">Найдено префиксов: <?= number_format($mac_total, 0, '.', ' ') ?><?= $mac_pages > 1 ? ' · страница ' . $mac_page . ' из ' . $mac_pages : '' ?></div>
        <?php if (!$mac_list): ?>
          <div class="mc-empty">Ничего не найдено.</div>
        <?php else: ?>
          <div class="dc-tablewrap">
            <table class="data-table">
              <thead><tr><th>Префикс</th><th>Производитель</th><th>Адрес</th></tr></thead>
              <tbody>
                <?php foreach ($mac_list as $r): ?>
                  <tr>
                    <td data-label="Префикс"><span class="mc-prefix"><?= mac_h(implode(':', str_split((string)$r['prefix'], 2))) ?></span> <span class="mc-tag"><?= (int)$r['len'] * 4 ?> бит</span></td>
                    <td data-label="Производитель"><?= mac_h((string)$r['vendor']) ?></td>
                    <td data-label="Адрес" style="color:var(--text-2);font-size:12px"><?= mac_h((string)$r['address']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php if ($mac_pages > 1): ?>
            <div class="dc-pager">
              <?php if ($mac_page > 1): ?><a href="<?= mac_h(mac_qs($mac_page - 1)) ?>">← Назад</a><?php endif; ?>
              <span class="cur"><?= $mac_page ?> / <?= $mac_pages ?></span>
              <?php if ($mac_page < $mac_pages): ?><a href="<?= mac_h(mac_qs($mac_page + 1)) ?>">Вперёд →</a><?php endif; ?>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</main>
