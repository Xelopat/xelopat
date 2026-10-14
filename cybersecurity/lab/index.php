<?php
require __DIR__ . '/../includes/layout.php';

// Порядок важен: ядро, типы и схемы, оболочки и терминал, интерфейс, окна последними
$lab_scripts = ['lab/net.js', 'lab/types.js', 'lab/presets.js', 'lab/term.js', 'lab/shell-ios.js', 'lab/shell-linux.js', 'lab/ui.js', 'lab/windows.js'];

$head = '<link rel="stylesheet" href="' . cs_h(cs_asset('lab/lab.css')) . '">';
foreach ($lab_scripts as $s) {
    $head .= "\n  <script src=\"" . cs_h(cs_asset($s)) . '" defer></script>';
}

cs_head('Сетевая лаборатория', 'lab', $head);
?>
<main class="page page--lab">
  <div class="page-wrap page-wrap--lab">
    <h1 class="visually-hidden" data-type="off">Сетевая лаборатория</h1>
    <div class="lab" id="lab"></div>
    <noscript><p class="panel">Для лаборатории нужен JavaScript.</p></noscript>
  </div>
</main>
</body>
</html>
