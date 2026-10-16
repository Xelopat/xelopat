<?php
$site_page_title = 'xelopat';
include $_SERVER['DOCUMENT_ROOT'] . '/header.php';

$config_path = __DIR__ . '/data/site_config.json';
$config = [];
if (is_file($config_path)) {
    $json = file_get_contents($config_path);
    if ($json !== false) {
        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            $config = $decoded;
        }
    }
}

function cfg(array $source, string $path, $default = null) {
    $parts = explode('.', $path);
    $value = $source;
    foreach ($parts as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function normalize_images($item): array {
    if (!is_array($item)) return [];

    $images = [];
    if (isset($item['images']) && is_array($item['images'])) {
        foreach ($item['images'] as $img) {
            $url = trim((string)$img);
            if ($url === '') continue;
            $images[] = $url;
        }
    }

    if (!$images) {
        $legacy = trim((string)($item['image'] ?? ''));
        if ($legacy !== '') {
            $images[] = $legacy;
        }
    }

    return array_values(array_unique($images));
}

function normalize_videos($item): array {
    if (!is_array($item)) return [];

    $videos = [];
    if (isset($item['videos']) && is_array($item['videos'])) {
        foreach ($item['videos'] as $video) {
            $url = trim((string)$video);
            if ($url === '') continue;
            $videos[] = $url;
        }
    }

    if (!$videos) {
        $legacy = trim((string)($item['video'] ?? ''));
        if ($legacy !== '') {
            $videos[] = $legacy;
        }
    }

    return array_values(array_unique($videos));
}

function normalize_cards($items): array {
    if (!is_array($items)) return [];
    $out = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $images = normalize_images($item);
        $videos = normalize_videos($item);
        $out[] = [
            'title' => (string)($item['title'] ?? 'Без названия'),
            'date' => (string)($item['date'] ?? ''),
            'description' => (string)($item['description'] ?? ''),
            'details' => (string)($item['details'] ?? ''),
            'images' => $images,
            'image' => $images[0] ?? '',
            'videos' => $videos,
            'video' => $videos[0] ?? '',
        ];
    }
    return $out;
}

function load_cards_collection(array $config, string $primary, string $fallback): array {
    $items = normalize_cards(cfg($config, $primary, []));
    if (!$items && $fallback !== '') {
        $fallback_items = normalize_cards(cfg($config, $fallback, []));
        if ($fallback_items) {
            return $fallback_items;
        }
    }
    return $items;
}

$hero_tag = (string)cfg($config, 'hero.tag', '// личный сайт');
$hero_name = (string)cfg($config, 'hero.name', 'Xelopat');

require_once __DIR__ . '/includes/collection_lib.php';

// Карточки для главной в старом формате (images/videos) из новых разделов
function home_collection(string $key): array {
    $out = [];
    foreach (coll_sorted(coll_load($key)) as $it) {
        $images = $videos = [];
        foreach ($it['media'] as $m) {
            if ($m['type'] === 'video') $videos[] = $m['src'];
            else $images[] = $m['preview'] ?: $m['src'];
        }
        $out[] = ['title' => $it['title'], 'description' => $it['description'], 'details' => $it['details'],
                  'images' => $images, 'videos' => $videos, 'url' => coll_item_url($key, $it)];
    }
    return $out;
}

$travels = home_collection('travel');
$photos = home_collection('photo');

// Кубики под терминалом: у вошедшего пользователя тип и количество берутся с сервера
require_once __DIR__ . '/includes/user_prefs.php';
$dice_prefs = $auth_user ? prefs_clean_dice(prefs_get((string)$auth_user['id'])['dice'] ?? null) : null;
$dice_json = $dice_prefs ? (string)json_encode($dice_prefs) : '';

$terminal_config = cfg($config, 'terminal', []);
if (!is_array($terminal_config)) {
    $terminal_config = [];
}
$client_ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$terminal_config['client_ip'] = filter_var($client_ip, FILTER_VALIDATE_IP) ? $client_ip : '';
$terminal_json = json_encode($terminal_config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if ($terminal_json === false) {
    $terminal_json = '{}';
}

$footer_text = (string)cfg($config, 'footer.text', 'xelopat 2026');
?>
<style>
  .site-shell{
    min-height:calc(100vh - 60px);
    position:relative;
    overflow:hidden;
    color:var(--text);
  }

  .site-shell::before{
    content:"";
    position:absolute;
    inset:0;
    background-image:radial-gradient(circle at 1px 1px, rgba(51,51,64,.55) 1px, transparent 0);
    background-size:80px 80px;
    pointer-events:none;
    opacity:.7;
  }

  .site-container{
    width:min(1280px, calc(100vw - 24px));
    margin:0 auto;
    position:relative;
    z-index:1;
  }

  .hero{
    padding:72px 0 64px;
    display:grid;
    grid-template-columns:minmax(0, 1fr) 420px;
    gap:32px;
    align-items:center;
  }

  .hero-tag{
    display:inline-flex;
    align-items:center;
    background:rgba(249,201,64,.1);
    border-radius:var(--radius-sm);
    padding:3px 10px;
    font-family:var(--mono);
    font-size:11px;
    color:var(--accent);
    margin-bottom:16px;
  }

  .hero-name{
    font-family:var(--mono);
    font-weight:700;
    font-size:52px;
    line-height:1.1;
    margin:0 0 12px;
  }


  .hero-meta-block--hidden{
    display:none;
  }

  .hero-divider{
    width:min(320px, 100%);
    height:1px;
    background:var(--line);
    margin-bottom:10px;
  }

  .hero-side{ min-width:0; position:relative; }
  /* Зона под терминалом ловит клики и броски, а рисует кубики холст на всю колонку:
     при сильном броске они взлетают над терминалом */
  .dice-zone{
    height:210px;
    margin-top:6px;
    cursor:grab;
    touch-action:none;
    outline:none;
  }
  .dice-zone.is-grabbing{ cursor:grabbing; }
  .dice-zone:focus-visible{ outline:2px solid var(--accent); outline-offset:2px; border-radius:var(--radius); }
  .dice{
    position:absolute;
    left:0;
    top:-72px;
    width:100%;
    height:calc(100% + 72px);
    pointer-events:none;
    z-index:2;
  }
  .visually-hidden{
    position:absolute;
    width:1px;
    height:1px;
    overflow:hidden;
    clip:rect(0 0 0 0);
    white-space:nowrap;
  }

  .terminal{
    background:var(--panel);
    border:1px solid var(--line);
    border-radius:var(--radius-lg);
    overflow:hidden;
    box-shadow:0 18px 40px rgba(0,0,0,.18);
    transition:border-color .18s ease, box-shadow .18s ease;
  }
  .term-bar{
    background:var(--bg);
    padding:10px 14px;
    display:flex;
    align-items:center;
    justify-content:flex-end;
    border-bottom:1px solid #2a2a34;
  }

  .term-actions{
    display:flex;
    align-items:center;
    gap:8px;
  }
  .term-dot-btn{
    width:12px;
    height:12px;
    border-radius:999px;
    border:none;
    cursor:pointer;
    opacity:.9;
    transition:transform .12s ease, opacity .12s ease, filter .12s ease;
  }
  .term-dot-btn:hover{
    opacity:1;
    transform:translateY(-1px);
    filter:brightness(1.06);
  }
  .term-dot-btn:active{
    transform:translateY(0);
  }
  .term-dot-btn--collapse{ background:var(--accent); }
  .term-dot-btn--clear{ background:var(--danger); }
  .term-dot-btn--retype{ background:var(--green); }

  .term-body{
    padding:14px 16px;
    height:380px;
    max-height:380px;
    opacity:1;
    transform:translateY(0);
    display:flex;
    flex-direction:column;
    overflow:hidden;
    font-family:var(--mono);
    font-size:12px;
    transition:
      max-height .28s ease,
      opacity .2s ease,
      transform .28s ease,
      padding .28s ease;
  }
  .terminal--collapsed .term-body{
    max-height:0;
    opacity:0;
    transform:translateY(-6px);
    padding-top:0;
    padding-bottom:0;
    pointer-events:none;
  }

  .term-output{
    display:flex;
    flex-direction:column;
    gap:7px;
    flex:1;
    min-height:0;
    overflow:auto;
    padding-right:2px;
    scrollbar-width:thin;
    scrollbar-color:var(--green) #1a1a22;
  }
  .term-output::-webkit-scrollbar{
    width:10px;
    height:10px;
  }
  .term-output::-webkit-scrollbar-track{
    background:#1a1a22;
    border-radius:999px;
  }
  .term-output::-webkit-scrollbar-thumb{
    background:linear-gradient(180deg, var(--green), #4bb996);
    border-radius:999px;
    border:2px solid #1a1a22;
  }
  .term-output::-webkit-scrollbar-thumb:hover{
    background:linear-gradient(180deg, #71e2be, #56c7a2);
  }
  .term-line{ display:flex; flex-wrap:wrap; line-height:1.5; word-break:break-word; white-space:pre-wrap; color:#d0d4dc; }
  .term-line a{ color:var(--green); text-decoration:underline; }
  .term-line a:hover{ opacity:.88; }
  .term-host{ color:#11df7f; font-weight:700; }
  .term-path{ color:#5aa7ff; font-weight:700; }
  .term-symbol{ color:#d0d4dc; }
  .term-dir{ color:#5aa7ff; font-weight:700; }
  .term-dim{ color:#8b90a0; }
  .term-error{ color:#d0d4dc; }
  .term-cmd{ color:#f2f4f8; white-space:pre-wrap; }
  .term-line--prompt{
    align-items:center;
    white-space:nowrap;
  }
  .term-inline-input{
    flex:1;
    min-width:24px;
    background:transparent;
    border:none;
    color:#f2f4f8;
    font:inherit;
    outline:none;
    margin-left:1ch;
    padding:0;
    caret-color:#d0d4dc;
  }

  .section{ padding:0 0 40px; position:relative; z-index:1; }
  .sec-label{
    font-family:var(--mono);
    font-size:11px;
    color:var(--accent);
    margin-bottom:6px;
  }
  .sec-title{ font-size:24px; font-weight:700; margin-bottom:20px; }

  .cards{
    display:grid;
    grid-template-columns:repeat(3, minmax(0, 1fr));
    gap:14px;
  }

  .card{
    background:var(--panel);
    border:1px solid var(--line);
    border-radius:var(--radius-lg);
    overflow:hidden;
    transition:border-color .18s ease;
  }
  .card:hover{
    border-color:#555566;
  }
  .card-media{
    width:100%;
    background:var(--bg);
    border-bottom:1px solid var(--line);
    display:flex;
    align-items:center;
    justify-content:center;
    padding:10px;
    min-height:180px;
  }
  .card-media img{
    width:100%;
    height:auto;
    max-height:560px;
    object-fit:contain;
    display:block;
  }
  .card-media video{
    width:100%;
    height:auto;
    max-height:560px;
    object-fit:contain;
    display:block;
    background:var(--bg);
  }
  .card-inner{ padding:14px 16px; }
  .card-title{ font-size:15px; font-weight:700; margin-bottom:6px; line-height:1.4; }
  .card-desc{ font-size:12px; color:var(--muted); line-height:1.6; margin:0; }
  .card-link-btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    width:max-content;
    border:1px solid #3a3f51;
    border-radius:var(--radius);
    background:var(--panel);
    color:var(--text);
    text-decoration:none;
    font-size:12px;
    line-height:1;
    padding:8px 10px;
  }
  .footer-bar{
    border-top:1px solid var(--line);
    padding:14px 0 28px;
    display:flex;
    justify-content:space-between;
    gap:16px;
    align-items:center;
  }
  .footer-txt,
  .footer-up{
    font-family:var(--mono);
    font-size:11px;
    color:var(--muted);
  }
  .footer-up{
    color:var(--accent);
    text-decoration:none;
  }

  @media (max-width: 980px){
    .site-shell{
      min-height:auto;
    }
    .site-container{
      width:calc(100vw - 20px);
    }
    .hero{
      grid-template-columns:1fr;
      gap:14px;
      padding:30px 0 22px;
    }
    .hero-tag{
      margin-bottom:10px;
      font-size:12px;
    }
    .hero-name{
      font-size:clamp(36px, 10vw, 48px);
      margin:0 0 10px;
    }
    .hero-divider{
      width:100%;
    }
    .terminal, .dice, .dice-zone{ display:none; }
    .section{
      padding:0 0 28px;
    }
    .sec-title{
      font-size:26px;
      margin-bottom:14px;
    }
    .cards{ grid-template-columns:1fr; }
    .card-inner{
      padding:12px 13px;
    }
    .card-title{
      font-size:18px;
    }
    .card-desc{
      font-size:14px;
      line-height:1.55;
    }
    .footer-bar{
      padding:12px 0 20px;
      flex-direction:column;
      align-items:flex-start;
      gap:8px;
    }
    .footer-txt,
    .footer-up{
      font-size:12px;
    }
    .footer-up{
      align-self:flex-end;
    }
  }

  @media (max-width: 640px){
    .site-shell::before{
      background-size:58px 58px;
      opacity:.55;
    }
    .hero-name{
      font-size:clamp(32px, 12vw, 40px);
    }
    .sec-label{
      font-size:11px;
    }
    .cards{
      gap:10px;
    }
    .card-media img,
    .card-media video{
      max-height:72vh;
    }
  }
</style>

<div class="site-shell">
  <div class="site-container">
    <section class="hero">
      <div class="hero-main">
        <div class="hero-tag"><?= e($hero_tag) ?></div>
        <h1 class="hero-name" id="heroName"><?= e($hero_name) ?></h1>
        <div class="hero-meta-block" id="heroMetaBlock">
          <div class="hero-divider"></div>
        </div>
      </div>

      <div class="hero-side">
      <div class="terminal" id="fakeTerminal" data-terminal='<?= e($terminal_json) ?>'>
        <div class="term-bar">
          <div class="term-actions" role="toolbar" aria-label="Управление терминалом">
            <button type="button" class="term-dot-btn term-dot-btn--collapse" data-term-action="collapse" title="Свернуть/развернуть" aria-label="Свернуть/развернуть"></button>
            <button type="button" class="term-dot-btn term-dot-btn--clear" data-term-action="clear" title="Очистить вывод" aria-label="Очистить вывод"></button>
            <button type="button" class="term-dot-btn term-dot-btn--retype" data-term-action="retype" title="Перепечатать имя" aria-label="Перепечатать имя"></button>
          </div>
        </div>
        <div class="term-body">
          <div class="term-output" id="termOutput"></div>
        </div>
      </div>
      <div class="dice-zone" id="diceZone" tabindex="0" role="button" aria-label="Кубики: нажмите, чтобы бросить, или перетащите кубик и отпустите"
        data-dice='<?= e($dice_json) ?>' data-auth="<?= $auth_user ? '1' : '0' ?>" data-csrf="<?= e($auth_csrf) ?>"></div>
      <canvas class="dice" id="dice" aria-hidden="true"></canvas>
      <div class="visually-hidden" id="diceResult" aria-live="polite"></div>
      </div>
    </section>

    <?php if ($travels): ?>
    <section class="section" id="travel">
      <div class="sec-label">// travel</div>
      <div class="sec-title">Путешествия</div>
        <div class="cards">
          <?php foreach ($travels as $travel_index => $travel): ?>
            <?php
              $title = (string)($travel['title'] ?? 'Без названия');
              $desc_source = (string)(($travel['description'] ?? '') !== '' ? ($travel['description'] ?? '') : ($travel['details'] ?? ''));
              $description = trim(preg_replace('/\s+/u', ' ', strip_tags($desc_source)) ?? '');
              $images = $travel['images'] ?? [];
              $videos = $travel['videos'] ?? [];
              if (!is_array($images) || !$images) {
                  $legacy = trim((string)($travel['image'] ?? ''));
                  $images = $legacy !== '' ? [$legacy] : [];
              }
              if (!is_array($videos) || !$videos) {
                  $legacyVideo = trim((string)($travel['video'] ?? ''));
                  $videos = $legacyVideo !== '' ? [$legacyVideo] : [];
              }
            ?>
            <article class="card">
              <div class="card-media">
                <?php $first_image = (string)($images[0] ?? ''); ?>
                <?php $first_video = (string)($videos[0] ?? ''); ?>
                <?php if ($first_image !== ''): ?>
                  <img src="<?= e($first_image) ?>" alt="<?= e($title) ?>">
                <?php elseif ($first_video !== ''): ?>
                  <video src="<?= e($first_video) ?>" controls preload="metadata" playsinline title="<?= e($title . ' видео') ?>"></video>
                <?php endif; ?>
              </div>
              <div class="card-inner">
                <div class="card-title"><?= e($title) ?></div>
                <div class="card-desc"><?= e($description) ?></div>
                <div style="margin-top:10px;">
                  <a class="card-link-btn" href="<?= e((string)$travel['url']) ?>">Подробнее</a>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($photos): ?>
    <section class="section" id="photo">
      <div class="sec-label">// photo</div>
      <div class="sec-title">Фото</div>
        <div class="cards">
          <?php foreach ($photos as $photo_index => $photo): ?>
            <?php
              $title = (string)($photo['title'] ?? 'Без названия');
              $desc_source = (string)(($photo['description'] ?? '') !== '' ? ($photo['description'] ?? '') : ($photo['details'] ?? ''));
              $description = trim(preg_replace('/\s+/u', ' ', strip_tags($desc_source)) ?? '');
              $images = $photo['images'] ?? [];
              $videos = $photo['videos'] ?? [];
              if (!is_array($images) || !$images) {
                  $legacy = trim((string)($photo['image'] ?? ''));
                  $images = $legacy !== '' ? [$legacy] : [];
              }
              if (!is_array($videos) || !$videos) {
                  $legacyVideo = trim((string)($photo['video'] ?? ''));
                  $videos = $legacyVideo !== '' ? [$legacyVideo] : [];
              }
            ?>
            <article class="card">
              <div class="card-media">
                <?php $first_image = (string)($images[0] ?? ''); ?>
                <?php $first_video = (string)($videos[0] ?? ''); ?>
                <?php if ($first_image !== ''): ?>
                  <img src="<?= e($first_image) ?>" alt="<?= e($title) ?>">
                <?php elseif ($first_video !== ''): ?>
                  <video src="<?= e($first_video) ?>" controls preload="metadata" playsinline title="<?= e($title . ' видео') ?>"></video>
                <?php endif; ?>
              </div>
              <div class="card-inner">
                <div class="card-title"><?= e($title) ?></div>
                <div class="card-desc"><?= e($description) ?></div>
                <div style="margin-top:10px;">
                  <a class="card-link-btn" href="<?= e((string)$photo['url']) ?>">Подробнее</a>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <footer class="footer-bar">
      <span class="footer-txt"><?= e($footer_text) ?></span>
      <a class="footer-up" href="#top" onclick="window.scrollTo({top:0,behavior:'smooth'}); return false;">↑ наверх</a>
    </footer>
  </div>
</div>
<script>
// Кубики под терминалом: прозрачные многогранники в псевдо-3D (d2 монетка, d4, d6, d8, d10, d12, d20).
// Клик, Enter или команда dice в терминале бросают все кубики, отдельный кубик можно взять и швырнуть.
// Когда кубики замирают, грань к зрителю подсвечивается. Тип и количество лежат в localStorage,
// у вошедших пользователей ещё и на сервере.
(function () {
  const cv = document.getElementById('dice');
  const zone = document.getElementById('diceZone');
  if (!cv || !zone || !cv.getContext) return;
  const live = document.getElementById('diceResult');
  const ctx = cv.getContext('2d');
  const PHI = (1 + Math.sqrt(5)) / 2;
  const TYPES = [2, 4, 6, 8, 10, 12, 20];
  const MAX_COUNT = 6;
  const G = 0.0025;
  const STORE_KEY = 'xelopat.dice';

  const add = (a, b) => [a[0] + b[0], a[1] + b[1], a[2] + b[2]];
  const sub = (a, b) => [a[0] - b[0], a[1] - b[1], a[2] - b[2]];
  const mul = (a, k) => [a[0] * k, a[1] * k, a[2] * k];
  const dot = (a, b) => a[0] * b[0] + a[1] * b[1] + a[2] * b[2];
  const cross = (a, b) => [a[1] * b[2] - a[2] * b[1], a[2] * b[0] - a[0] * b[2], a[0] * b[1] - a[1] * b[0]];
  const len = (a) => Math.hypot(a[0], a[1], a[2]);
  const norm = (a) => mul(a, 1 / len(a));

  // Все сочетания знаков у ненулевых координат: pm(1, 1, 1) даёт 8 вершин куба
  function pm(x, y, z) {
    const out = [];
    for (const sx of x ? [-1, 1] : [1]) for (const sy of y ? [-1, 1] : [1]) for (const sz of z ? [-1, 1] : [1]) out.push([x * sx, y * sy, z * sz]);
    return out;
  }

  function vertices(n) {
    if (n === 4) return [[1, 1, 1], [1, -1, -1], [-1, 1, -1], [-1, -1, 1]];
    if (n === 6) return pm(1, 1, 1);
    if (n === 8) return [...pm(1, 0, 0), ...pm(0, 1, 0), ...pm(0, 0, 1)];
    if (n === 10) {
      // Пентагональный трапецоэдр: при такой высоте полюсов грани-«воздушные змеи» плоские
      const z = 0.1056, c = Math.cos(Math.PI / 5), h = z * (1 + c) / (1 - c);
      const ring = Array.from({ length: 10 }, (_, k) => [Math.cos(k * Math.PI / 5), Math.sin(k * Math.PI / 5), k % 2 ? -z : z]);
      return [[0, 0, h], [0, 0, -h], ...ring];
    }
    if (n === 12) return [...pm(1, 1, 1), ...pm(0, 1 / PHI, PHI), ...pm(1 / PHI, PHI, 0), ...pm(PHI, 0, 1 / PHI)];
    if (n === 20) return [...pm(0, 1, PHI), ...pm(1, PHI, 0), ...pm(PHI, 0, 1)];
    // Монетка: плоская 24-угольная призма
    return Array.from({ length: 48 }, (_, k) => [Math.cos((k % 24) * Math.PI / 12), Math.sin((k % 24) * Math.PI / 12), k < 24 ? 0.09 : -0.09]);
  }

  // Выпуклая оболочка перебором: плоскость через три вершины, по одну сторону от которой лежат все остальные
  const geoCache = {};
  function geometry(n) {
    if (geoCache[n]) return geoCache[n];
    let V = vertices(n);
    const r = Math.max(...V.map(len));
    V = V.map((v) => mul(v, 1 / r));
    const faces = [];
    const seen = new Set();
    for (let i = 0; i < V.length; i += 1) for (let j = i + 1; j < V.length; j += 1) for (let k = j + 1; k < V.length; k += 1) {
      let nrm = cross(sub(V[j], V[i]), sub(V[k], V[i]));
      if (len(nrm) < 1e-9) continue;
      nrm = norm(nrm);
      let d = dot(nrm, V[i]);
      let above = 0, below = 0;
      for (const v of V) { const s = dot(nrm, v) - d; if (s > 1e-6) above += 1; else if (s < -1e-6) below += 1; }
      if (above && below) continue;
      if (above) { nrm = mul(nrm, -1); d = -d; }
      const key = nrm.map((c) => Math.round(c * 1e4)).join(',');
      if (seen.has(key)) continue;
      seen.add(key);
      const idx = V.map((v, q) => q).filter((q) => Math.abs(dot(nrm, V[q]) - d) < 1e-6);
      const c = mul(idx.reduce((acc, q) => add(acc, V[q]), [0, 0, 0]), 1 / idx.length);
      const u = norm(sub(V[idx[0]], c)), w = cross(nrm, u);
      idx.sort((a, b) => Math.atan2(dot(sub(V[a], c), w), dot(sub(V[a], c), u)) - Math.atan2(dot(sub(V[b], c), w), dot(sub(V[b], c), u)));
      let area = 0;
      for (let q = 1; q < idx.length - 1; q += 1) area += len(cross(sub(V[idx[q]], V[idx[0]]), sub(V[idx[q + 1]], V[idx[0]]))) / 2;
      // Куда смотрит верх числа: у квадрата на середину ребра, у остальных на самую дальнюю вершину
      const dist = idx.map((q) => len(sub(V[q], c)));
      const square = idx.length === 4 && Math.max(...dist) - Math.min(...dist) < 1e-6;
      const tip = square ? mul(add(V[idx[0]], V[idx[1]]), 0.5) : V[idx[dist.indexOf(Math.max(...dist))]];
      faces.push({ v: idx, n: nrm, c: c, up: norm(sub(tip, c)), area: area, num: 0 });
    }
    // Числа получают n самых крупных граней (у монетки это две плоскости), противоположные в сумме дают n + 1
    const numbered = faces.slice().sort((a, b) => b.area - a.area).slice(0, n);
    let lo = 1, hi = n;
    numbered.forEach((f) => {
      if (f.num) return;
      f.num = lo; lo += 1;
      const opp = numbered.find((o) => !o.num && dot(o.n, f.n) < -0.999);
      if (opp) { opp.num = hi; hi -= 1; }
    });
    // У треугольных граней меньше места под число, чем у квадратов и пятиугольников той же площади
    const font = n === 2 ? 0.8 : Math.min(numbered[0].v.length === 3 ? 0.46 : 0.5, Math.max(0.22, 0.55 * Math.sqrt(numbered[0].area)));
    const edges = [];
    faces.forEach((f, fi) => f.v.forEach((a, q) => {
      const b = f.v[(q + 1) % f.v.length];
      const p = a < b ? [a, b] : [b, a];
      const e = edges.find((x) => x.a === p[0] && x.b === p[1]);
      if (e) e.f.push(fi); else edges.push({ a: p[0], b: p[1], f: [fi] });
    }));
    // Рёбра между почти соседними по наклону гранями (бок монетки) не рисуем, чтобы не рябило
    const drawn = edges.filter((e) => e.f.length < 2 || dot(faces[e.f[0]].n, faces[e.f[1]].n) < 0.94);
    geoCache[n] = { n: n, V: V, faces: faces, edges: drawn, font: font };
    return geoCache[n];
  }

  // Монетка: орёл помечен X, решка единицей, оба знака рисуются только контуром
  function label(n, num) {
    if (n === 2) return num === 1 ? 'X' : '1';
    return n >= 6 && (num === 6 || num === 9) ? num + '.' : String(num);
  }
  function word(n, num) { return n === 2 ? (num === 1 ? 'орёл' : 'решка') : String(num); }

  const apply = (m, v) => [dot(m[0], v), dot(m[1], v), dot(m[2], v)];
  function rotate(d, w, dt) {
    const ang = len(w) * dt;
    if (ang < 1e-9) return;
    const [x, y, z] = norm(w), c = Math.cos(ang), s = Math.sin(ang), t = 1 - c;
    const r = [
      [t * x * x + c, t * x * y - s * z, t * x * z + s * y],
      [t * x * y + s * z, t * y * y + c, t * y * z - s * x],
      [t * x * z - s * y, t * y * z + s * x, t * z * z + c],
    ];
    const M = r.map((row) => [0, 1, 2].map((j) => row[0] * d.M[0][j] + row[1] * d.M[1][j] + row[2] * d.M[2][j]));
    // Против накопления ошибки снова делаем строки ортонормированными
    const a = norm(M[0]), b = norm(sub(M[1], mul(a, dot(a, M[1]))));
    d.M = [a, b, cross(a, b)];
  }

  function loadSettings() {
    const ok = (s) => s && TYPES.includes(+s.type) && +s.count >= 1 && +s.count <= MAX_COUNT;
    try { const s = JSON.parse(zone.dataset.dice || 'null'); if (ok(s)) return { type: +s.type, count: +s.count }; } catch (e) { /* нет данных с сервера */ }
    try { const s = JSON.parse(localStorage.getItem(STORE_KEY) || 'null'); if (ok(s)) return { type: +s.type, count: +s.count }; } catch (e) { /* хранилище недоступно */ }
    return { type: 20, count: 1 };
  }
  function saveSettings() {
    try { localStorage.setItem(STORE_KEY, JSON.stringify(settings)); } catch (e) { /* приватный режим */ }
    if (zone.dataset.auth === '1') {
      fetch('/auth/prefs', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ csrf: zone.dataset.csrf || '', dice: settings }),
      }).catch(function () {});
    }
  }

  let settings = loadSettings();
  let dice = [];
  let W = 0, H = 0, dpr = 1;
  let raf = 0, last = 0, grab = null, waiters = [], deadline = 0, snapping = false, rollId = 0;
  let sum = null, sumAlpha = 0;
  const radius = () => [0, 44, 40, 36, 32, 30, 28][settings.count];
  const floorY = (d) => H - d.R - 4;

  function build() {
    const g = geometry(settings.type);
    const R = radius();
    dice = Array.from({ length: settings.count }, (_, i) => {
      const d = { g: g, R: R, M: [[1, 0, 0], [0, 1, 0], [0, 0, 1]], x: 0, y: 0, vx: 0, vy: 0, w: [0, 0, 0], result: 0 };
      rotate(d, [0.6 + i * 0.3, 1, 0.3 - i * 0.2], 0.9 + i * 0.7);
      return d;
    });
    place();
  }
  function place() {
    if (!W) return;
    dice.forEach((d, i) => { d.x = W * (i + 1) / (dice.length + 1); d.y = floorY(d); });
  }

  function resize() {
    dpr = Math.min(window.devicePixelRatio || 1, 2);
    const first = !W;
    W = cv.clientWidth; H = cv.clientHeight;
    if (!W || !H) return;
    cv.width = Math.round(W * dpr); cv.height = Math.round(H * dpr);
    if (first) place();
    dice.forEach((d) => { d.x = Math.min(Math.max(d.x, d.R), W - d.R); d.y = Math.min(d.y, floorY(d)); });
    draw();
  }

  function drawDie(d) {
    const g = d.g, R = d.R;
    const project = (p) => { const k = 260 / (260 - p[2] * R); return [d.x + p[0] * R * k, d.y + p[1] * R * k, k]; };
    const P = g.V.map((v) => project(apply(d.M, v)));
    const F = g.faces.map((f) => ({ f: f, n: apply(d.M, f.n), c: apply(d.M, f.c) }));
    const light = norm([-0.4, -0.7, 0.6]);
    const front = (e) => e.f.some((fi) => F[fi].n[2] > 0);

    // Тень на полу: чем выше кубик, тем она меньше и бледнее
    const lift = Math.max(0, floorY(d) - d.y);
    ctx.fillStyle = 'rgba(0,0,0,' + (0.35 * Math.max(0.2, 1 - lift / 160)).toFixed(3) + ')';
    ctx.beginPath(); ctx.ellipse(d.x, floorY(d) + R * 0.92, R * (0.85 - Math.min(lift, 160) / 500), 4, 0, 0, Math.PI * 2); ctx.fill();

    ctx.lineJoin = 'round';
    ctx.strokeStyle = 'rgba(239,239,241,.12)';
    ctx.lineWidth = 1;
    g.edges.forEach((e) => {
      if (front(e)) return;
      ctx.beginPath(); ctx.moveTo(P[e.a][0], P[e.a][1]); ctx.lineTo(P[e.b][0], P[e.b][1]); ctx.stroke();
    });
    F.forEach((q) => {
      if (q.n[2] <= 0) return;
      const hit = d.result && q.f.num === d.result;
      ctx.fillStyle = hit ? 'rgba(249,201,64,.30)' : 'rgba(249,201,64,' + (0.03 + Math.max(0, dot(q.n, light)) * 0.09).toFixed(3) + ')';
      ctx.beginPath();
      q.f.v.forEach((vi, i) => (i ? ctx.lineTo(P[vi][0], P[vi][1]) : ctx.moveTo(P[vi][0], P[vi][1])));
      ctx.closePath(); ctx.fill();
    });
    ctx.strokeStyle = 'rgba(239,239,241,.55)';
    ctx.lineWidth = 1.2;
    g.edges.forEach((e) => {
      if (!front(e)) return;
      ctx.beginPath(); ctx.moveTo(P[e.a][0], P[e.a][1]); ctx.lineTo(P[e.b][0], P[e.b][1]); ctx.stroke();
    });

    // Числа лежат в плоскости грани: базис грани проецируется на экран, отсюда наклон и сжатие
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.font = '700 22px "Space Mono", ui-monospace, monospace';
    F.forEach((q) => {
      if (!q.f.num || q.n[2] < 0.2) return;
      const hit = d.result && q.f.num === d.result;
      const t1 = apply(d.M, q.f.up);
      const t2 = cross(q.n, t1);
      const [px, py, k] = project(q.c);
      const s = R * g.font / 22 * k;
      ctx.setTransform(t2[0] * s * dpr, t2[1] * s * dpr, -t1[0] * s * dpr, -t1[1] * s * dpr, px * dpr, py * dpr);
      const color = hit ? 'rgba(249,201,64,1)' : 'rgba(239,239,241,' + (0.2 + 0.7 * q.n[2]).toFixed(3) + ')';
      if (g.n === 2) {
        ctx.font = '900 22px Inter, system-ui, sans-serif';
        ctx.strokeStyle = color;
        ctx.lineWidth = 1.6 / s;
        ctx.strokeText(label(g.n, q.f.num), 0, 1);
      } else {
        ctx.fillStyle = color;
        ctx.fillText(label(g.n, q.f.num), 0, 2);
      }
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    });
  }

  function draw() {
    if (!W) return;
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, W, H);
    // Сумма крупно и еле заметным контуром на фоне, за кубиками
    if (sum !== null) {
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.font = '700 ' + Math.round(Math.min(zone.offsetHeight * 0.7, 140)) + 'px "Space Mono", ui-monospace, monospace';
      ctx.strokeStyle = 'rgba(249,201,64,' + (0.22 * sumAlpha).toFixed(3) + ')';
      ctx.lineWidth = 1.2;
      ctx.strokeText(String(sum), W / 2, H - 4 - zone.offsetHeight / 2);
    }
    dice.forEach(drawDie);
  }
  function showSum(value) {
    sum = value;
    sumAlpha = document.hidden ? 1 : 0;
    const t0 = performance.now();
    const tick = (now) => {
      if (sum === null) return;
      sumAlpha = Math.min(1, (now - t0) / 350);
      draw();
      if (sumAlpha < 1) requestAnimationFrame(tick);
    };
    draw();
    requestAnimationFrame(tick);
  }

  function face(d) {
    let best = null;
    d.g.faces.forEach((f) => { if (!f.num) return; const z = apply(d.M, f.n)[2]; if (!best || z > best.z) best = { z: z, f: f }; });
    return best.f;
  }

  // Кубики замерли: выпавшая грань плавно доворачивается к зрителю с небольшим наклоном,
  // чтобы был виден объём, а число встаёт ровно
  const SNAP_TO = norm([0.2, -0.32, 1]);
  function beginSnap() {
    dice.forEach((d) => {
      d.vx = d.vy = 0; d.w = [0, 0, 0];
      const f = face(d);
      d.result = f.num; d.snapN = f.n; d.snapUp = f.up;
    });
    snapping = true;
  }
  function snapStep(dt) {
    let done = true;
    dice.forEach((d) => {
      const n = apply(d.M, d.snapN);
      const axis = cross(n, SNAP_TO);
      const ang = Math.atan2(len(axis), dot(n, SNAP_TO));
      const t1 = apply(d.M, d.snapUp);
      const up = [0, -1, 0];
      const want = norm(sub(up, mul(n, dot(up, n))));
      const twist = Math.atan2(dot(cross(t1, want), n), dot(t1, want));
      if (ang < 0.004 && Math.abs(twist) < 0.004) return;
      done = false;
      const turn = add(len(axis) > 1e-6 ? mul(norm(axis), ang) : [0, 0, 0], mul(n, twist));
      rotate(d, mul(turn, (1 - Math.pow(0.985, dt)) / dt), dt);
    });
    return done;
  }

  function finish() {
    snapping = false;
    const res = dice.map((d) => d.result);
    if (live) live.textContent = 'Выпало: ' + res.map((r) => word(settings.type, r)).join(', ');
    // Сумма имеет смысл, когда кубиков несколько; у монеток её нет
    if (settings.type !== 2 && res.length > 1) showSum(res.reduce((a, b) => a + b, 0));
    else draw();
    const list = waiters; waiters = [];
    list.forEach((fn) => fn(res));
  }

  function physics(d, dt) {
    if (grab && grab.d === d) return;
    const fy = floorY(d);
    d.vy += G * dt;
    d.x += d.vx * dt; d.y += d.vy * dt;
    if (d.x < d.R) { d.x = d.R; d.vx = -d.vx * 0.6; d.w = add(d.w, [0, 0.004, 0]); }
    if (d.x > W - d.R) { d.x = W - d.R; d.vx = -d.vx * 0.6; d.w = add(d.w, [0, -0.004, 0]); }
    // На полу только тот, кто падает или лежит; кубик, которого только что подбросили, отрывается от пола
    d.onFloor = d.y >= fy && d.vy >= 0;
    if (d.onFloor) {
      d.y = fy;
      if (d.vy > 0.08) {
        const hit = d.vy;
        d.vy = -d.vy * 0.5; d.vx *= 0.85;
        d.w = add(mul(d.w, 0.7), [(Math.random() - 0.5) * hit * 0.02, (Math.random() - 0.5) * hit * 0.02, (Math.random() - 0.5) * hit * 0.01]);
      } else {
        d.vy = 0;
        d.vx *= Math.pow(0.996, dt);
        if (Math.abs(d.vx) < 0.004) d.vx = 0;
        // Катится по полу: вращение подтягивается к качению без проскальзывания
        const blend = 1 - Math.pow(0.985, dt);
        d.w = [d.w[0] * (1 - blend), d.w[1] * (1 - blend), d.w[2] + (d.vx / d.R - d.w[2]) * blend];
        if (!d.vx) d.w = mul(d.w, Math.pow(0.993, dt));
      }
    } else {
      d.w = mul(d.w, Math.pow(0.9995, dt));
    }
    rotate(d, d.w, dt);
  }

  // Кубики толкают друг друга как шары: раздвигаем и обмениваемся скоростью вдоль линии центров
  function collide() {
    for (let i = 0; i < dice.length; i += 1) for (let j = i + 1; j < dice.length; j += 1) {
      const a = dice[i], b = dice[j];
      const dx = b.x - a.x, dy = b.y - a.y, dist = Math.hypot(dx, dy) || 0.01, min = (a.R + b.R) * 0.98;
      if (dist >= min) continue;
      const nx = dx / dist, ny = dy / dist, push = (min - dist) / 2;
      a.x -= nx * push; a.y -= ny * push; b.x += nx * push; b.y += ny * push;
      const rel = (b.vx - a.vx) * nx + (b.vy - a.vy) * ny;
      if (rel < 0) {
        const imp = -0.8 * rel;
        a.vx -= imp * nx; a.vy -= imp * ny; b.vx += imp * nx; b.vy += imp * ny;
        a.w = add(a.w, [0, 0, -imp * 0.01]); b.w = add(b.w, [0, 0, imp * 0.01]);
      }
    }
  }

  function step(now) {
    raf = 0;
    const dt = Math.min(32, now - (last || now));
    last = now;
    if (snapping && !grab) {
      const done = snapStep(dt || 16);
      draw();
      if (done) { last = 0; finish(); return; }
      raf = requestAnimationFrame(step);
      return;
    }
    dice.forEach((d) => physics(d, dt));
    collide();
    const resting = !grab && dice.every((d) => d.onFloor && !d.vy && !d.vx && len(d.w) < 0.0004);
    if (resting || (!grab && deadline && now > deadline)) { deadline = 0; beginSnap(); }
    draw();
    raf = requestAnimationFrame(step);
  }
  function play() {
    snapping = false;
    sum = null;
    dice.forEach((d) => { d.result = 0; });
    if (live) live.textContent = '';
    if (!raf) { last = 0; raf = requestAnimationFrame(step); }
  }

  function roll() {
    dice.forEach((d) => {
      d.vx = (Math.random() - 0.5) * 1.0;
      d.vy = -(1.2 + Math.random() * 0.35);
      d.w = [(Math.random() - 0.5) * 0.14, (Math.random() - 0.5) * 0.14, (Math.random() - 0.5) * 0.06];
    });
    // Если вкладка уйдёт в фон посреди броска, кадры встанут; дедлайн не даёт команде dice зависнуть
    deadline = performance.now() + 9000;
    const done = new Promise((resolve) => waiters.push(resolve));
    play();
    if (!W) { beginSnap(); finish(); }
    const id = ++rollId;
    setTimeout(() => { if (id === rollId && waiters.length && !grab) { if (!snapping) beginSnap(); finish(); } }, 11000);
    return done;
  }

  function local(e) { const r = cv.getBoundingClientRect(); return [e.clientX - r.left, e.clientY - r.top]; }
  function hitDie(px, py) {
    for (let i = dice.length - 1; i >= 0; i -= 1) if (Math.hypot(px - dice[i].x, py - dice[i].y) <= dice[i].R * 1.15) return dice[i];
    return null;
  }
  zone.addEventListener('pointerdown', (e) => {
    const [px, py] = local(e);
    const d = hitDie(px, py);
    zone.setPointerCapture(e.pointerId);
    grab = { d: d, dx: d ? px - d.x : 0, dy: d ? py - d.y : 0, sx: px, sy: py, t0: e.timeStamp, trail: [[px, py, e.timeStamp]] };
    if (d) { zone.classList.add('is-grabbing'); d.vx = d.vy = 0; play(); }
  });
  zone.addEventListener('pointermove', (e) => {
    const [px, py] = local(e);
    if (!grab || !grab.d) { zone.style.cursor = hitDie(px, py) ? '' : 'pointer'; if (grab) grab.trail.push([px, py, e.timeStamp]); return; }
    const d = grab.d;
    const nx = Math.min(Math.max(px - grab.dx, d.R), W - d.R), ny = Math.min(Math.max(py - grab.dy, d.R), floorY(d));
    d.w = [(ny - d.y) * 0.004, -(nx - d.x) * 0.004, 0];
    d.x = nx; d.y = ny;
    grab.trail.push([px, py, e.timeStamp]);
    if (grab.trail.length > 6) grab.trail.shift();
  });
  function release(e) {
    if (!grab) return;
    const g = grab; grab = null;
    zone.classList.remove('is-grabbing');
    const [px, py] = local(e);
    if (Math.hypot(px - g.sx, py - g.sy) < 6 && e.timeStamp - g.t0 < 350) { roll(); return; }
    if (!g.d) return;
    const a = g.trail[0], b = g.trail[g.trail.length - 1];
    const span = Math.max(16, b[2] - a[2]);
    const cap = (v) => Math.max(-2.5, Math.min(2.5, v));
    g.d.vx = cap((b[0] - a[0]) / span); g.d.vy = cap((b[1] - a[1]) / span);
    const speed = Math.hypot(g.d.vx, g.d.vy);
    g.d.w = add(g.d.w, [(Math.random() - 0.5) * speed * 0.03, (Math.random() - 0.5) * speed * 0.03, g.d.vx * 0.01]);
    deadline = performance.now() + 9000;
    play();
  }
  zone.addEventListener('pointerup', release);
  zone.addEventListener('pointercancel', release);
  zone.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); roll(); }
  });

  window.siteDice = {
    TYPES: TYPES,
    MAX_COUNT: MAX_COUNT,
    get: () => ({ type: settings.type, count: settings.count }),
    set: (s) => { settings = { type: s.type, count: s.count }; saveSettings(); build(); draw(); },
    roll: roll,
    word: word,
  };

  build();
  if ('ResizeObserver' in window) new ResizeObserver(resize).observe(cv);
  else window.addEventListener('resize', resize);
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(draw);
  resize();
})();
</script>
<script>
(function () {
  const terminal = document.getElementById('fakeTerminal');
  if (!terminal) return;

  const output = document.getElementById('termOutput');
  const heroMetaBlock = document.getElementById('heroMetaBlock');
  const collapseBtn = terminal.querySelector('[data-term-action="collapse"]');
  const clearBtn = terminal.querySelector('[data-term-action="clear"]');
  const retypeBtn = terminal.querySelector('[data-term-action="retype"]');
  let input = null;

  let config = {};
  try {
    config = JSON.parse(terminal.dataset.terminal || '{}');
  } catch (e) {
    config = {};
  }

  const rootNode = config.filesystem || { type: 'dir', children: {} };
  let cwd = [];
  const history = [];
  let historyIndex = -1;
  const COMMANDS = ['help', 'ls', 'cd', 'pwd', 'cat', 'tree', 'whoami', 'echo', 'clear', 'uname', 'date', 'uptime', 'hostname', 'id', 'history', 'dice'];
  const COMMANDS_WITH_ARG = new Set(['ls', 'cd', 'cat', 'tree', 'echo', 'dice']);
  const PATH_COMMANDS = new Set(['ls', 'cd', 'cat', 'tree']);
  const LINK_PATTERN = /\[([^\]]+)\]\((\/[^)\s]+|https?:\/\/[^)\s]+)\)|(https?:\/\/[^\s]+)/g;
  const hostname = String(config.hostname || 'xelopat@site');
  const userName = hostname.split('@')[0];
  const hostShort = hostname.split('@')[1] || hostname;
  const bootTime = new Date();

  function appendLinkedText(container, text) {
    const source = String(text ?? '');
    LINK_PATTERN.lastIndex = 0;
    let lastIndex = 0;
    let match;

    while ((match = LINK_PATTERN.exec(source)) !== null) {
      if (match.index > lastIndex) {
        container.appendChild(document.createTextNode(source.slice(lastIndex, match.index)));
      }

      const href = match[2] || match[3];
      const label = match[1] || href;
      const link = document.createElement('a');
      link.href = href;
      link.textContent = label;
      if (href.startsWith('http://') || href.startsWith('https://')) {
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
      }
      container.appendChild(link);
      lastIndex = LINK_PATTERN.lastIndex;
    }

    if (lastIndex < source.length) {
      container.appendChild(document.createTextNode(source.slice(lastIndex)));
    }
  }

  function printLine(parts, className) {
    const row = document.createElement('div');
    row.className = 'term-line' + (className ? ' ' + className : '');
    if (Array.isArray(parts)) {
      parts.forEach(part => {
        if (typeof part === 'string') {
          const span = document.createElement('span');
          appendLinkedText(span, part);
          row.appendChild(span);
        } else if (part && typeof part === 'object') {
          const span = document.createElement('span');
          appendLinkedText(span, part.text || '');
          if (part.className) span.className = part.className;
          row.appendChild(span);
        }
      });
    } else {
      row.textContent = String(parts ?? '');
    }
    output.appendChild(row);
    output.scrollTop = output.scrollHeight;
  }

  function printInfo(text) {
    printLine([String(text ?? '')]);
  }

  function printError(text) {
    printLine([String(text ?? '')], 'term-error');
  }

  function getPromptPath() {
    if (!cwd.length) return '~';
    return '~/' + cwd.join('/');
  }

  function focusInput() {
    if (!input) return;
    input.focus();
    const end = input.value.length;
    input.setSelectionRange(end, end);
  }

  function createPromptLine(prefill) {
    const row = document.createElement('div');
    row.className = 'term-line term-line--prompt';
    row.setAttribute('data-prompt-path', getPromptPath());

    const host = document.createElement('span');
    host.className = 'term-host';
    host.textContent = hostname;
    row.appendChild(host);

    const sep = document.createElement('span');
    sep.className = 'term-symbol';
    sep.textContent = ':';
    row.appendChild(sep);

    const path = document.createElement('span');
    path.className = 'term-path';
    path.textContent = getPromptPath();
    row.appendChild(path);

    const symbol = document.createElement('span');
    symbol.className = 'term-symbol';
    symbol.textContent = '$';
    row.appendChild(symbol);

    const inputEl = document.createElement('input');
    inputEl.type = 'text';
    inputEl.className = 'term-inline-input';
    inputEl.autocomplete = 'off';
    inputEl.spellcheck = false;
    inputEl.value = typeof prefill === 'string' ? prefill : '';
    row.appendChild(inputEl);

    bindPromptInput(row, inputEl);
    output.appendChild(row);
    output.scrollTop = output.scrollHeight;
    input = inputEl;
    focusInput();
  }

  function finalizePromptRow(row, raw) {
    const path = row.getAttribute('data-prompt-path') || '~';
    row.classList.remove('term-line--prompt');
    row.innerHTML = '';

    const host = document.createElement('span');
    host.className = 'term-host';
    host.textContent = hostname;
    row.appendChild(host);

    const sep = document.createElement('span');
    sep.className = 'term-symbol';
    sep.textContent = ':';
    row.appendChild(sep);

    const pathNode = document.createElement('span');
    pathNode.className = 'term-path';
    pathNode.textContent = path;
    row.appendChild(pathNode);

    const symbol = document.createElement('span');
    symbol.className = 'term-symbol';
    symbol.textContent = '$';
    row.appendChild(symbol);

    const commandText = document.createElement('span');
    commandText.className = 'term-cmd';
    commandText.textContent = ' ' + raw;
    row.appendChild(commandText);
  }

  function setCollapsed(next) {
    terminal.classList.toggle('terminal--collapsed', !!next);
    if (heroMetaBlock) {
      heroMetaBlock.classList.toggle('hero-meta-block--hidden', !!next);
    }
    if (next && input) input.blur();
    if (!next) focusInput();
  }

  function typeHeroName() {
    if (window.siteTypeTitle) window.siteTypeTitle(document.getElementById('heroName'));
  }

  function getNode(pathParts) {
    let node = rootNode;
    for (const part of pathParts) {
      if (!node || node.type !== 'dir' || !node.children || !node.children[part]) {
        return null;
      }
      node = node.children[part];
    }
    return node;
  }

  function normalizePath(inputPath) {
    const parts = inputPath.split('/');
    const stack = inputPath.startsWith('/') ? [] : cwd.slice();
    for (const part of parts) {
      if (!part || part === '.') continue;
      if (part === '..') {
        stack.pop();
      } else {
        stack.push(part);
      }
    }
    return stack;
  }

  function pwd() {
    return '/' + cwd.join('/');
  }

  function commonPrefix(values) {
    if (!values.length) return '';
    let prefix = values[0];
    for (let i = 1; i < values.length; i++) {
      while (!values[i].startsWith(prefix) && prefix.length > 0) {
        prefix = prefix.slice(0, -1);
      }
      if (!prefix) break;
    }
    return prefix;
  }

  function completeCommand(rawToken) {
    const token = rawToken.toLowerCase();
    const matches = COMMANDS.filter(cmd => cmd.startsWith(token));
    if (!matches.length) return false;

    if (matches.length === 1) {
      const full = matches[0];
      input.value = COMMANDS_WITH_ARG.has(full) ? full + ' ' : full;
      return true;
    }

    const prefix = commonPrefix(matches);
    if (prefix.length > rawToken.length) {
      input.value = prefix;
    }
    printInfo(matches.join('   '));
    return true;
  }

  function completePath(cmdToken, rawArg) {
    const arg = rawArg || '';
    const lastSlashIndex = arg.lastIndexOf('/');
    const baseInput = lastSlashIndex >= 0 ? arg.slice(0, lastSlashIndex + 1) : '';
    const namePrefix = lastSlashIndex >= 0 ? arg.slice(lastSlashIndex + 1) : arg;
    const baseParts = normalizePath(baseInput || '.');
    const baseNode = getNode(baseParts);

    if (!baseNode || baseNode.type !== 'dir') {
      return false;
    }

    const matches = Object.entries(baseNode.children || {})
      .filter(([name]) => name.startsWith(namePrefix))
      .map(([name, node]) => ({ name, node }));

    if (!matches.length) {
      return false;
    }

    if (matches.length === 1) {
      const item = matches[0];
      const suffix = item.node.type === 'dir' ? '/' : '';
      input.value = cmdToken + ' ' + baseInput + item.name + suffix;
      return true;
    }

    const names = matches.map(item => item.name);
    const prefix = commonPrefix(names);
    if (prefix.length > namePrefix.length) {
      input.value = cmdToken + ' ' + baseInput + prefix;
    }

    printInfo(matches.map(item => item.name + (item.node.type === 'dir' ? '/' : '')).join('   '));
    return true;
  }

  function handleTabCompletion() {
    if (!input) return;
    const raw = input.value;
    const trimmed = raw.trim();
    if (!trimmed) return;

    const hasTrailingSpace = /\s$/.test(raw);
    const tokens = trimmed.split(/\s+/);

    if (tokens.length === 1 && !hasTrailingSpace) {
      completeCommand(tokens[0]);
      return;
    }

    const cmdToken = tokens[0];
    const cmd = cmdToken.toLowerCase();
    if (!PATH_COMMANDS.has(cmd)) return;
    if (tokens.length > 2) return;

    const arg = tokens.length === 1 ? '' : tokens[1];
    completePath(cmdToken, arg);
  }

  const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
  const pad2 = n => String(n).padStart(2, '0');

  function fmtDate(d) {
    return DAYS[d.getDay()] + ' ' + MONTHS[d.getMonth()] + ' ' + String(d.getDate()).padStart(2, ' ') +
      ' ' + pad2(d.getHours()) + ':' + pad2(d.getMinutes()) + ':' + pad2(d.getSeconds());
  }

  function nodeSize(node) {
    if (node.type === 'dir') return 4096;
    return new TextEncoder().encode(String(node.content || '') + '\n').length;
  }

  function sortedEntries(node) {
    return Object.entries(node.children || {}).sort((a, b) => a[0].localeCompare(b[0], 'en'));
  }

  function listDir(pathParts, flags) {
    const node = getNode(pathParts);
    const label = pathParts[pathParts.length - 1] || '/';
    if (!node) {
      printError("ls: cannot access '" + label + "': No such file or directory");
      return;
    }
    const showAll = flags.includes('a');
    const longFmt = flags.includes('l');
    let entries;
    if (node.type !== 'dir') {
      entries = [[label, node]];
    } else {
      entries = sortedEntries(node);
      if (showAll) entries = [['.', node], ['..', node]].concat(entries);
    }
    if (!entries.length) return;

    if (!longFmt) {
      const parts = [];
      entries.forEach(([name, child], i) => {
        if (i) parts.push('  ');
        parts.push({ text: name, className: child.type === 'dir' ? 'term-dir' : '' });
      });
      printLine(parts);
      return;
    }

    const sizes = entries.map(([, child]) => String(nodeSize(child)));
    const width = Math.max(...sizes.map(x => x.length));
    const stamp = MONTHS[bootTime.getMonth()] + ' ' + String(bootTime.getDate()).padStart(2, ' ') +
      ' ' + pad2(bootTime.getHours()) + ':' + pad2(bootTime.getMinutes());
    if (node.type === 'dir') {
      const total = entries.reduce((sum, [, child]) => sum + Math.max(4, Math.ceil(nodeSize(child) / 4096) * 4), 0);
      printInfo('total ' + total);
    }
    entries.forEach(([name, child], i) => {
      const isDir = child.type === 'dir';
      const left = (isDir ? 'drwxr-xr-x ' : '-rw-r--r-- ') + (isDir ? '2' : '1') + ' ' + userName + ' ' + userName + ' ' +
        sizes[i].padStart(width, ' ') + ' ' + stamp + ' ';
      printLine([left, { text: name, className: isDir ? 'term-dir' : '' }]);
    });
  }

  function catFile(pathParts, rawName) {
    const node = getNode(pathParts);
    if (!node) {
      printError('cat: ' + rawName + ': No such file or directory');
      return;
    }
    if (node.type !== 'file') {
      printError('cat: ' + rawName + ': Is a directory');
      return;
    }
    String(node.content || '').split('\n').forEach(line => printInfo(line));
  }

  function drawTree(node, prefix, counts) {
    const entries = sortedEntries(node);
    entries.forEach(([name, child], index) => {
      const last = index === entries.length - 1;
      const isDir = child.type === 'dir';
      printLine([prefix + (last ? '└── ' : '├── '), { text: name, className: isDir ? 'term-dir' : '' }]);
      if (isDir) {
        counts.dirs++;
        drawTree(child, prefix + (last ? '    ' : '│   '), counts);
      } else {
        counts.files++;
      }
    });
  }

  // dice: бросок кубиков под терминалом. dice | dice NdM | dice -n count | dice -t dM
  function diceCommand(rest) {
    const D = window.siteDice;
    if (!D) {
      printError('dice: not available');
      return;
    }
    const usage = () => printError('usage: dice [NdM] [-n count] [-t dM]');
    let { type, count } = D.get();
    let changed = false;
    let roll = true;
    for (let i = 0; i < rest.length; i++) {
      const t = rest[i].toLowerCase();
      if (t === '-n' || t === '-t') {
        const v = (rest[++i] || '').toLowerCase();
        if (!v) { usage(); return; }
        if (t === '-n') {
          if (!/^\d+$/.test(v) || +v < 1 || +v > D.MAX_COUNT) { printError('dice: invalid count: ' + v); return; }
          count = +v;
        } else {
          const m = /^d?(\d+)$/.exec(v);
          if (!m || !D.TYPES.includes(+m[1])) { printError('dice: invalid type: ' + v); return; }
          type = +m[1];
        }
        changed = true;
        roll = false;
        continue;
      }
      const m = /^(\d*)d(\d+)$/.exec(t);
      if (!m) { usage(); return; }
      const c = m[1] ? +m[1] : 1;
      if (c < 1 || c > D.MAX_COUNT) { printError('dice: invalid count: ' + m[1]); return; }
      if (!D.TYPES.includes(+m[2])) { printError('dice: invalid type: d' + m[2]); return; }
      count = c;
      type = +m[2];
      changed = true;
    }
    if (changed) D.set({ type: type, count: count });
    if (!roll) return;
    return {
      wait: D.roll().then(res => {
        const words = res.map(r => D.word(type, r));
        const sum = res.reduce((a, b) => a + b, 0);
        printInfo(type === 2 || res.length === 1 ? words.join(' ') : words.join(' ') + ' = ' + sum);
      }),
    };
  }

  function handleCommand(command) {
    const tokens = command.split(/\s+/);
    const cmd = tokens[0].toLowerCase();
    const rest = tokens.slice(1);
    const flags = rest.filter(t => /^-[a-zA-Z]+$/.test(t)).join('').replace(/-/g, '');
    const args = rest.filter(t => !/^-[a-zA-Z]+$/.test(t));
    const arg = args.join(' ');
    const resolve = p => (p === '~' ? [] : p.startsWith('~/') ? normalizePath('/' + p.slice(2)) : normalizePath(p));

    switch (cmd) {
      case 'help': {
        // Как compgen -c: только имена, по колонкам сверху вниз
        const names = COMMANDS.slice().sort();
        const width = Math.max(...names.map(n => n.length)) + 2;
        const rows = Math.ceil(names.length / 4);
        for (let r = 0; r < rows; r++) {
          let line = '';
          for (let c = 0; c < 4; c++) {
            const name = names[c * rows + r];
            if (name) line += name.padEnd(width, ' ');
          }
          printInfo(line.trimEnd());
        }
        return;
      }

      case 'dice':
        return diceCommand(rest);

      case 'clear':
        output.innerHTML = '';
        return { cleared: true };

      case 'pwd':
        printInfo('/home/' + userName + (cwd.length ? '/' + cwd.join('/') : ''));
        return;

      case 'whoami':
        printInfo(userName);
        return;

      case 'id':
        printInfo('uid=1000(' + userName + ') gid=1000(' + userName + ') groups=1000(' + userName + ')');
        return;

      case 'hostname':
        printInfo(hostShort);
        return;

      case 'uname':
        printInfo(flags.includes('a')
          ? 'Linux ' + hostShort + ' 5.15.0-101-generic #111-Ubuntu SMP Tue Mar 5 20:16:58 UTC 2026 x86_64 x86_64 x86_64 GNU/Linux'
          : 'Linux');
        return;

      case 'date':
        printInfo(fmtDate(new Date()) + ' UTC ' + new Date().getFullYear());
        return;

      case 'uptime': {
        const now = new Date();
        printInfo(' ' + pad2(now.getHours()) + ':' + pad2(now.getMinutes()) + ':' + pad2(now.getSeconds()) +
          ' up 41 days,  3:12,  1 user,  load average: 0.08, 0.03, 0.01');
        return;
      }

      case 'history':
        history.forEach((h, i) => printInfo(String(i + 1).padStart(5, ' ') + '  ' + h));
        return;

      case 'echo':
        printInfo(command.replace(/^\S+\s?/, ''));
        return;

      case 'ls':
        listDir(arg ? resolve(arg) : cwd, flags);
        return;

      case 'cd': {
        if (args.length > 1) {
          printError('bash: cd: too many arguments');
          return;
        }
        const target = arg ? resolve(arg) : [];
        const node = getNode(target);
        if (!node) {
          printError('bash: cd: ' + arg + ': No such file or directory');
          return;
        }
        if (node.type !== 'dir') {
          printError('bash: cd: ' + arg + ': Not a directory');
          return;
        }
        cwd = target;
        return;
      }

      case 'cat':
        if (!arg) return;
        args.forEach(name => catFile(resolve(name), name));
        return;

      case 'tree': {
        const target = arg ? resolve(arg) : cwd;
        const node = getNode(target);
        if (!node) {
          printError(arg + ' [error opening dir]');
          return;
        }
        const counts = { dirs: 0, files: 0 };
        printLine([{ text: arg || '.', className: 'term-dir' }]);
        if (node.type === 'dir') drawTree(node, '', counts);
        printInfo('');
        printInfo(counts.dirs + ' directories, ' + counts.files + ' files');
        return;
      }

      default:
        printError('bash: ' + tokens[0] + ': command not found');
        return;
    }
  }

  function runCurrentPrompt(row, inputEl) {
    const raw = String(inputEl.value || '');
    const command = raw.trim();
    finalizePromptRow(row, raw);
    input = null;

    if (!command) {
      createPromptLine('');
      return;
    }

    history.push(command);
    historyIndex = history.length;
    const result = handleCommand(command);

    if (result && result.cleared) {
      createPromptLine('');
      return;
    }
    // Команда ещё работает (кубики катятся): приглашение появится, когда она закончит
    if (result && result.wait) {
      result.wait.then(() => { createPromptLine(''); focusInput(); });
      return;
    }
    createPromptLine('');
  }

  function bindPromptInput(row, inputEl) {
    inputEl.addEventListener('keydown', function (e) {
      if (e.key === 'Tab') {
        e.preventDefault();
        handleTabCompletion();
        return;
      }

      if (e.key === 'Enter') {
        e.preventDefault();
        runCurrentPrompt(row, inputEl);
        return;
      }

      if (e.key === 'ArrowUp') {
        e.preventDefault();
        if (!history.length) return;
        historyIndex = Math.max(0, historyIndex - 1);
        inputEl.value = history[historyIndex] || '';
        return;
      }

      if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (!history.length) return;
        historyIndex = Math.min(history.length, historyIndex + 1);
        inputEl.value = history[historyIndex] || '';
      }
    });
  }

  terminal.addEventListener('click', function () {
    if (terminal.classList.contains('terminal--collapsed')) return;
    focusInput();
  });

  if (collapseBtn) {
    collapseBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      setCollapsed(!terminal.classList.contains('terminal--collapsed'));
    });
  }

  if (clearBtn) {
    clearBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      output.innerHTML = '';
      createPromptLine('');
    });
  }

  if (retypeBtn) {
    retypeBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      typeHeroName();
      focusInput();
    });
  }

  const welcome = Array.isArray(config.welcome) ? config.welcome : [];
  welcome.forEach((line) => printInfo(String(line)));
  printLine([{ text: 'Last login: ' + fmtDate(new Date(bootTime.getTime() - 5 * 3600 * 1000)) + ' ' + bootTime.getFullYear() + (config.client_ip ? ' from ' + config.client_ip : ''), className: 'term-dim' }]);
  createPromptLine('');
})();
</script>
