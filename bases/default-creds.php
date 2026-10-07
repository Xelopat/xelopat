<?php
// База заводских логинов/паролей по умолчанию. Публичные данные из мануалов —
// чтобы проверить и сменить дефолты на своём оборудовании.
declare(strict_types=1);

$site_page_title = 'Пароли по умолчанию — xelopat';

$dc_file = $_SERVER['DOCUMENT_ROOT'] . '/data/default_creds.json';
$dc_data = is_file($dc_file) ? json_decode((string)file_get_contents($dc_file), true) : [];
$dc_items = is_array($dc_data['items'] ?? null) ? $dc_data['items'] : [];
$dc_updated = (string)($dc_data['updated'] ?? '');

function dc_lower(string $s): string {
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

$dc_query = trim((string)($_GET['q'] ?? ''));
$dc_type = trim((string)($_GET['type'] ?? ''));

// Типы для фильтра — в порядке появления
$dc_types = [];
foreach ($dc_items as $it) {
    $t = (string)($it['type'] ?? '');
    if ($t !== '' && !in_array($t, $dc_types, true)) $dc_types[] = $t;
}

$dc_results = [];
$needles = preg_split('/\s+/u', $dc_query, -1, PREG_SPLIT_NO_EMPTY) ?: [];
foreach ($dc_items as $it) {
    if ($dc_type !== '' && (string)($it['type'] ?? '') !== $dc_type) continue;
    if ($needles) {
        $hay = dc_lower(implode(' ', [$it['vendor'] ?? '', $it['model'] ?? '', $it['type'] ?? '', $it['login'] ?? '', $it['password'] ?? '', $it['note'] ?? '']));
        $ok = true;
        foreach ($needles as $n) {
            if (strpos($hay, dc_lower($n)) === false) { $ok = false; break; }
        }
        if (!$ok) continue;
    }
    $dc_results[] = $it;
}

include $_SERVER['DOCUMENT_ROOT'] . '/header.php';

function dc_h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
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
  .dc-count{ font-family:var(--mono); font-size:11px; color:var(--muted); margin-bottom:10px; }
  .dc-tablewrap{ overflow-x:auto; }
  .dc-cred{ font-family:var(--mono); color:var(--accent); }
  .dc-type{
    display:inline-block; font-size:11px; font-family:var(--mono);
    padding:2px 7px; border-radius:5px; background:var(--panel-2); border:1px solid var(--line); color:var(--text-2);
  }
  .dc-note-cell{ color:var(--muted); font-size:12px; }
  .dc-empty{ padding:30px 0; text-align:center; color:var(--muted); }
  .data-table td{ vertical-align:top; }
  @media (max-width: 640px){
    .data-table thead{ display:none; }
    .data-table tbody tr{ display:block; border-bottom:1px solid var(--line); padding:8px 0; }
    .data-table td{ display:block; padding:3px 8px; border:none; }
    .data-table td::before{ content:attr(data-label) ": "; color:var(--muted); font-family:var(--mono); font-size:10px; text-transform:uppercase; }
    .data-table td.dc-td-vendor::before{ content:""; }
    .dc-td-vendor{ font-weight:700; font-size:15px; padding-top:6px; }
  }
</style>

<main class="page">
  <div class="page-wrap">
    <div class="page-label">// базы</div>
    <h1 class="page-title">Пароли по умолчанию</h1>
    <p class="page-sub">Заводские логины и пароли роутеров, камер и сетевых хранилищ из открытых мануалов.</p>

    <div class="dc-note">
      <span>🔒</span>
      <span>Только для проверки <b>своего</b> оборудования. Если в вашу камеру или роутер до сих пор можно войти с заводским паролем — его немедленно нужно сменить. Доступ к чужим устройствам без разрешения незаконен.</span>
    </div>

    <form class="dc-tools" method="get">
      <?php if ($dc_type !== ''): ?><input type="hidden" name="type" value="<?= dc_h($dc_type) ?>"><?php endif; ?>
      <input type="search" name="q" value="<?= dc_h($dc_query) ?>" placeholder="Например: Hikvision, TP-Link, камера" autofocus>
      <button class="btn" type="submit">Найти</button>
    </form>

    <div class="dc-chips">
      <a class="dc-chip<?= $dc_type === '' ? ' on' : '' ?>" href="?<?= $dc_query !== '' ? 'q=' . urlencode($dc_query) : '' ?>">Все</a>
      <?php foreach ($dc_types as $t): ?>
        <a class="dc-chip<?= $dc_type === $t ? ' on' : '' ?>" href="?type=<?= urlencode($t) ?><?= $dc_query !== '' ? '&q=' . urlencode($dc_query) : '' ?>"><?= dc_h($t) ?></a>
      <?php endforeach; ?>
    </div>

    <div class="dc-count">Найдено: <?= count($dc_results) ?> из <?= count($dc_items) ?><?= $dc_updated !== '' ? ' · обновлено ' . dc_h($dc_updated) : '' ?></div>

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
                <td class="dc-td-vendor" data-label="Производитель"><?= dc_h((string)($it['vendor'] ?? '')) ?></td>
                <td data-label="Модель"><?= dc_h((string)($it['model'] ?? '')) ?></td>
                <td data-label="Тип"><span class="dc-type"><?= dc_h((string)($it['type'] ?? '')) ?></span></td>
                <td data-label="Логин"><span class="dc-cred"><?= dc_h((string)($it['login'] ?? '')) ?></span></td>
                <td data-label="Пароль"><span class="dc-cred"><?= dc_h((string)($it['password'] ?? '')) ?></span></td>
                <td data-label="Доступ"><?= dc_h((string)($it['access'] ?? '')) ?></td>
                <td class="dc-note-cell" data-label="Примечание"><?= dc_h((string)($it['note'] ?? '')) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</main>
