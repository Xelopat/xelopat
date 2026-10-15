<?php
require __DIR__ . '/../includes/layout.php';

// Порядок важен: ядро, типы и схемы, интерфейс, окна последними
$lab_scripts = ['lab/net.js', 'lab/types.js', 'lab/presets.js', 'lab/tasks.js', 'lab/theory.js', 'lab/ui.js', 'lab/windows.js', 'lab/maps.js', 'lab/tasks-ui.js'];

$head = '<link rel="stylesheet" href="' . cs_h(cs_asset('lab/lab.css')) . '">';
foreach ($lab_scripts as $s) {
    $head .= "\n  <script src=\"" . cs_h(cs_asset($s)) . '" defer></script>';
}

// Карты хранятся в аккаунте основного сайта. С поддомена API вызывается по адресу xelopat.ru:
// это тот же сайт, поэтому cookie входа уходят с запросом (подробности в api.php)
$lab_api = $cs_base === '' ? $cs_main_site . '/cybersecurity/lab/api.php' : cs_url('lab/api.php');
$lab_login = $cs_main_site . '/auth/login?next=' . rawurlencode('/cybersecurity/lab');

cs_head('Сетевая лаборатория', 'lab', $head);
?>
<main class="page page--lab">
  <div class="page-wrap page-wrap--lab">
    <h1 class="visually-hidden" data-type="off">Сетевая лаборатория</h1>
    <div class="lab" id="lab" data-api="<?= cs_h($lab_api) ?>" data-login="<?= cs_h($lab_login) ?>"></div>
    <noscript><p class="panel">Для лаборатории нужен JavaScript.</p></noscript>
  </div>
</main>
</body>
</html>
