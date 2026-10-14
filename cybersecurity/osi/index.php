<?php
require __DIR__ . '/../includes/layout.php';

// Модули подключаются автоматически: любой osi/modules/*.js, в алфавитном порядке.
// Порядок вкладок задаёт поле order в самом модуле, а не имя файла.
$osi_scripts = ['osi/data.js', 'osi/osi.js'];
foreach (glob(__DIR__ . '/modules/*.js') ?: [] as $f) {
    $osi_scripts[] = 'osi/modules/' . basename($f);
}

$head = '<link rel="stylesheet" href="' . cs_h(cs_asset('osi/osi.css')) . '">';
foreach ($osi_scripts as $s) {
    $head .= "\n  <script src=\"" . cs_h(cs_asset($s)) . '" defer></script>';
}

cs_head('Модель OSI', 'osi', $head);
?>
<main class="page">
  <div class="page-wrap">
    <div class="page-label">// network / osi</div>
    <h1 class="page-title">Модель OSI</h1>
    <p class="page-sub">Семь уровней сетевого взаимодействия: что делает каждый, какие протоколы и устройства на нём работают, как его атакуют и защищают. Выбирай уровень, смотри путь пакета по шагам и проверяй себя в тренажёре.</p>

    <div class="osi-tabs" id="osiTabs" role="tablist" aria-label="Режимы"></div>
    <div id="osiPanels"></div>
    <noscript><p class="panel">Для визуализации нужен JavaScript.</p></noscript>
  </div>
</main>
<?php cs_foot(); ?>
