<?php
require __DIR__ . '/../includes/layout.php';

// Списки подключаются автоматически: любой owasp/data/*.js. Порядок вкладок задаёт поле order.
// osi.css даёт общие вкладки (.osi-tabs), owasp.css всё остальное.
$ow_scripts = ['owasp/owasp.js'];
foreach (glob(__DIR__ . '/data/*.js') ?: [] as $f) {
    $ow_scripts[] = 'owasp/data/' . basename($f);
}

$head = '<link rel="stylesheet" href="' . cs_h(cs_asset('osi/osi.css')) . '">'
    . "\n  " . '<link rel="stylesheet" href="' . cs_h(cs_asset('owasp/owasp.css')) . '">';
foreach ($ow_scripts as $s) {
    $head .= "\n  <script src=\"" . cs_h(cs_asset($s)) . '" defer></script>';
}

cs_head('OWASP Top 10', 'owasp', $head);
?>
<main class="page">
  <div class="page-wrap">
    <div class="page-label">// appsec / owasp</div>
    <h1 class="page-title">OWASP Top 10</h1>
    <p class="page-sub">Самые частые и опасные уязвимости в вебе, API, мобильных приложениях, системах с ИИ, Kubernetes, CI/CD и интернете вещей. Для каждого риска: суть, пример атаки и защита. Сравни темы между списками и проверь себя в тренажёре.</p>

    <div id="owasp"></div>
    <noscript><p class="panel">Для этой страницы нужен JavaScript.</p></noscript>
  </div>
</main>
<?php cs_foot(); ?>
