<?php
// Каркас страниц поддомена cybersecurity.
// Поддомен смотрит прямо в эту папку, но страницы открываются и как xelopat.ru/cybersecurity/...,
// поэтому ссылки строим от $cs_base, а не от корня.
//
// Новый раздел: добавить пункт в нужный каталог $cs_sections (или новый каталог),
// тогда он появится в выпадающем меню шапки и на главной поддомена.

$cs_root = str_replace('\\', '/', (string)realpath(__DIR__ . '/..'));
$cs_doc = str_replace('\\', '/', (string)realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? '')));
$cs_base = ($cs_doc !== '' && strpos($cs_root, $cs_doc) === 0) ? rtrim(substr($cs_root, strlen($cs_doc)), '/') : '';

$cs_main_site = 'https://xelopat.ru';

// Каталоги шапки: [ключ, название, [[папка, название, описание, тег, короткое имя для меню], ...]]
$cs_sections = [
    ['net', 'Сеть', [
        ['lab', 'Сетевая лаборатория', 'Собери сеть на холсте: ПК, коммутаторы, маршрутизаторы, серверы с DNS, DHCP и веб-сервером. Настройка через веб-интерфейс или SSH, пакеты бегут по кабелям, кадр разбирается по уровням OSI.', '// network / lab', 'Лаборатория'],
        ['osi', 'Модель OSI', 'Семь уровней: функции, PDU, протоколы, устройства, атаки и защита. Инкапсуляция по шагам, сравнение с TCP/IP и тренажёр.', '// network / osi', 'OSI'],
    ]],
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

// $active: ключ раздела из $cs_sections или '' для главной; $extra_head: свои <link>/<script> страницы
function cs_head(string $title, string $active = '', string $extra_head = ''): void {
    global $cs_sections, $cs_main_site;
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
    <nav class="nav" id="navRoot" aria-label="Навигация">
      <a class="nav-link<?= $active === '' ? ' active' : '' ?>" href="<?= cs_h(cs_url()) ?>">Разделы</a>
      <?php foreach ($cs_sections as $sec): ?>
        <?php $sec_on = in_array($active, array_column($sec[2], 0), true); ?>
        <div class="dd" data-dd>
          <button type="button" class="nav-link nav-dd<?= $sec_on ? ' active' : '' ?>" aria-expanded="false"><?= cs_h($sec[1]) ?></button>
          <div class="dd-panel" role="menu">
            <?php foreach ($sec[2] as $t): ?>
              <a class="dd-item<?= $active === $t[0] ? ' active' : '' ?>" href="<?= cs_h(cs_url($t[0])) ?>" role="menuitem">
                <b><?= cs_h($t[4] ?? $t[1]) ?></b>
                <small><?= cs_h($t[3]) ?></small>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </nav>

    <div class="auth-area">
      <a class="auth-action" href="<?= cs_h($cs_main_site) ?>">xelopat.ru</a>
      <button class="burger" type="button" id="burgerBtn" aria-label="Открыть меню" aria-expanded="false">
        <span class="burger-lines" aria-hidden="true"><span></span><span></span><span></span></span>
      </button>
    </div>
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
