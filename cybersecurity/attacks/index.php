<?php
require __DIR__ . '/../includes/layout.php';

// Сценарии подключаются автоматически: любой attacks/scenarios/*.js.
$at_scripts = ['attacks/attacks.js'];
foreach (glob(__DIR__ . '/scenarios/*.js') ?: [] as $f) {
    $at_scripts[] = 'attacks/scenarios/' . basename($f);
}

$head = '<link rel="stylesheet" href="' . cs_h(cs_asset('osi/osi.css')) . '">'
    . "\n  " . '<link rel="stylesheet" href="' . cs_h(cs_asset('attacks/attacks.css')) . '">';
foreach ($at_scripts as $s) {
    $head .= "\n  <script src=\"" . cs_h(cs_asset($s)) . '" defer></script>';
}

cs_head('Сценарии атак', 'attacks', $head);
?>
<main class="page">
  <div class="page-wrap">
    <div class="page-label">// appsec / attacks</div>
    <h1 class="page-title">Справочник сценариев атак</h1>
    <p class="page-sub">Как устроен каждый класс атаки, как безопасно проверить свой стенд, по каким маркерам заметить его в логах и как закрыть. Для веба, API, аутентификации, сети, Kubernetes, облака, Android и цепочки поставок. Каждый сценарий связан с категориями OWASP, CWE, CAPEC и MITRE ATT&CK.</p>
    <p class="page-sub page-sub--note">Материал защитный: это публичные классы сценариев, а не инструкции по атаке на чужие системы. Лабораторные шаги предполагают собственный стенд или систему, на тестирование которой есть разрешение, и фиктивные данные.</p>

    <div id="attacks"></div>
    <noscript><p class="panel">Для этой страницы нужен JavaScript.</p></noscript>
  </div>
</main>
<?php cs_foot(); ?>
