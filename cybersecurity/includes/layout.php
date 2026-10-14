<?php
// Каркас страниц поддомена cybersecurity.
// Поддомен смотрит прямо в эту папку, но страницы открываются и как xelopat.ru/cybersecurity/...,
// поэтому ссылки строим от $cs_base, а не от корня.
//
// Новый раздел: добавить пункт в $cs_tools, тогда он появится в шапке и на главной поддомена.

$cs_root = str_replace('\\', '/', (string)realpath(__DIR__ . '/..'));
$cs_doc = str_replace('\\', '/', (string)realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? '')));
$cs_base = ($cs_doc !== '' && strpos($cs_root, $cs_doc) === 0) ? rtrim(substr($cs_root, strlen($cs_doc)), '/') : '';

$cs_main_site = 'https://xelopat.ru';

// [ключ (папка), название, описание, тег]
$cs_tools = [
    ['osi', 'Модель OSI', 'Семь уровней: функции, PDU, протоколы, устройства, атаки и защита. Инкапсуляция по шагам, сравнение с TCP/IP и тренажёр.', '// network'],
];

function cs_h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function cs_url(string $path = ''): string {
    global $cs_base;
    return $cs_base . '/' . ltrim($path, '/');
}

// Версия файла для сброса кэша
function cs_asset(string $path): string {
    global $cs_root;
    $v = @filemtime($cs_root . '/' . ltrim($path, '/')) ?: 1;
    return cs_url($path) . '?v=' . $v;
}

// $active: ключ раздела из $cs_tools или '' для главной; $extra_head: свои <link>/<script> страницы
function cs_head(string $title, string $active = '', string $extra_head = ''): void {
    global $cs_tools, $cs_main_site;
    ?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?= cs_h($title) ?></title>
  <link rel="icon" type="image/png" href="<?= cs_h(cs_url('img/xelopat.png')) ?>">
  <link rel="apple-touch-icon" href="<?= cs_h(cs_url('img/xelopat.png')) ?>">
  <link rel="stylesheet" href="<?= cs_h(cs_asset('assets/cs.css')) ?>">
  <script src="<?= cs_h(cs_asset('assets/cs.js')) ?>" defer></script>
  <?= $extra_head ?>
</head>
<body>
<header class="site-header">
  <div class="wrap">
    <a class="brand" href="<?= cs_h(cs_url()) ?>">
      <span class="brand-mark"><img src="<?= cs_h(cs_url('img/xelopat.png')) ?>" alt="xelopat"></span>
      <span class="brand-text">xelopat<span class="brand-sub">/cybersecurity</span></span>
    </a>
    <nav class="nav" aria-label="Навигация">
      <a class="nav-link<?= $active === '' ? ' active' : '' ?>" href="<?= cs_h(cs_url()) ?>">Разделы</a>
      <?php foreach ($cs_tools as $t): ?>
        <a class="nav-link<?= $active === $t[0] ? ' active' : '' ?>" href="<?= cs_h(cs_url($t[0])) ?>"><?= cs_h($t[1]) ?></a>
      <?php endforeach; ?>
      <a class="nav-link nav-link--out" href="<?= cs_h($cs_main_site) ?>">xelopat.ru</a>
    </nav>
  </div>
</header>
<?php
}

function cs_foot(): void {
    ?>
<footer class="footer-bar">
  <span>xelopat / cybersecurity</span>
  <a href="#top" onclick="window.scrollTo({top:0,behavior:'smooth'}); return false;">↑ наверх</a>
</footer>
</body>
</html>
<?php
}
