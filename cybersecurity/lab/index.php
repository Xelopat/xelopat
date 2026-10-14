<?php
require __DIR__ . '/../includes/layout.php';

// Порядок важен: ядро, потом типы, схемы и команды, интерфейс последним
$lab_scripts = ['lab/net.js', 'lab/types.js', 'lab/presets.js', 'lab/commands.js', 'lab/ui.js'];

$head = '<link rel="stylesheet" href="' . cs_h(cs_asset('lab/lab.css')) . '">';
foreach ($lab_scripts as $s) {
    $head .= "\n  <script src=\"" . cs_h(cs_asset($s)) . '" defer></script>';
}

cs_head('Сетевая лаборатория', 'lab', $head);
?>
<main class="page">
  <div class="page-wrap page-wrap--lab">
    <div class="page-label">// network / lab</div>
    <h1 class="page-title">Сетевая лаборатория</h1>
    <p class="page-sub">Собери сеть из ПК, коммутаторов и маршрутизаторов, задай адреса, маршруты и VLAN, а потом смотри, как пакет идёт по ней: ARP, кадры, TTL, ICMP. Полоска у каждого устройства показывает, до какого уровня OSI оно разбирает кадр. Схема сохраняется в браузере.</p>

    <div class="lab" id="lab"></div>
    <noscript><p class="panel">Для лаборатории нужен JavaScript.</p></noscript>
  </div>
</main>
<?php cs_foot(); ?>
