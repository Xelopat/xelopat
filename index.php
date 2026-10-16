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

  .hero-side{ min-width:0; }
  .d20{
    display:block;
    width:100%;
    height:210px;
    margin-top:6px;
    cursor:grab;
    touch-action:none;
    outline:none;
  }
  .d20.is-grabbing{ cursor:grabbing; }
  .d20:focus-visible{ outline:2px solid var(--accent); outline-offset:2px; border-radius:var(--radius); }
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
    .terminal, .d20{ display:none; }
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
      <canvas class="d20" id="d20" tabindex="0" role="button" aria-label="Кубик d20: нажмите или перетащите и отпустите, чтобы бросить"></canvas>
      <div class="visually-hidden" id="d20Result" aria-live="polite"></div>
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
// Кубик d20 под терминалом: прозрачный икосаэдр в псевдо-3D. Клик или Enter бросает его,
// можно взять мышью или пальцем и швырнуть. Когда он замирает, грань к зрителю подсвечивается.
(function () {
  const cv = document.getElementById('d20');
  if (!cv || !cv.getContext) return;
  const live = document.getElementById('d20Result');
  const ctx = cv.getContext('2d');
  const PHI = (1 + Math.sqrt(5)) / 2;
  const R = 44;
  const G = 0.0025;

  const add = (a, b) => [a[0] + b[0], a[1] + b[1], a[2] + b[2]];
  const sub = (a, b) => [a[0] - b[0], a[1] - b[1], a[2] - b[2]];
  const mul = (a, k) => [a[0] * k, a[1] * k, a[2] * k];
  const dot = (a, b) => a[0] * b[0] + a[1] * b[1] + a[2] * b[2];
  const cross = (a, b) => [a[1] * b[2] - a[2] * b[1], a[2] * b[0] - a[0] * b[2], a[0] * b[1] - a[1] * b[0]];
  const norm = (a) => mul(a, 1 / Math.hypot(a[0], a[1], a[2]));

  // Вершины икосаэдра, грани: тройки вершин, попарно соединённых ребром длины 2
  const raw = [];
  [-1, 1].forEach((a) => [-1, 1].forEach((b) => raw.push([0, a, b * PHI], [a, b * PHI, 0], [b * PHI, 0, a])));
  const faces = [];
  const isEdge = (i, j) => Math.abs(Math.hypot(...sub(raw[i], raw[j])) - 2) < 1e-6;
  for (let i = 0; i < 12; i += 1) for (let j = i + 1; j < 12; j += 1) for (let k = j + 1; k < 12; k += 1) {
    if (!isEdge(i, j) || !isEdge(j, k) || !isEdge(i, k)) continue;
    const c = mul(add(add(raw[i], raw[j]), raw[k]), 1 / 3);
    const n = cross(sub(raw[j], raw[i]), sub(raw[k], raw[i]));
    faces.push({ v: dot(n, c) > 0 ? [i, j, k] : [i, k, j], c: c, n: norm(c), num: 0 });
  }
  const verts = raw.map(norm);
  faces.forEach((f) => { f.c = mul(f.c, 1 / Math.hypot(...raw[0])); });
  // Числа как на настоящем кубике: противоположные грани в сумме дают 21
  let next = 1;
  faces.forEach((f) => {
    if (f.num) return;
    const opp = faces.find((o) => !o.num && o !== f && dot(o.n, f.n) < -0.999);
    f.num = next; if (opp) opp.num = 21 - next; next += 1;
  });
  const edges = [];
  faces.forEach((f, fi) => [[0, 1], [1, 2], [2, 0]].forEach(([a, b]) => {
    const p = [f.v[a], f.v[b]].sort((x, y) => x - y);
    const e = edges.find((q) => q.a === p[0] && q.b === p[1]);
    if (e) e.f.push(fi); else edges.push({ a: p[0], b: p[1], f: [fi] });
  }));

  // Поворот хранится матрицей 3x3, угловая скорость в радианах за миллисекунду
  let M = [[1, 0, 0], [0, 1, 0], [0, 0, 1]];
  const apply = (m, v) => [dot(m[0], v), dot(m[1], v), dot(m[2], v)];
  function rotate(w, dt) {
    const ang = Math.hypot(w[0], w[1], w[2]) * dt;
    if (ang < 1e-9) return;
    const [x, y, z] = norm(w), c = Math.cos(ang), s = Math.sin(ang), t = 1 - c;
    const r = [
      [t * x * x + c, t * x * y - s * z, t * x * z + s * y],
      [t * x * y + s * z, t * y * y + c, t * y * z - s * x],
      [t * x * z - s * y, t * y * z + s * x, t * z * z + c],
    ];
    M = r.map((row) => [0, 1, 2].map((j) => row[0] * M[0][j] + row[1] * M[1][j] + row[2] * M[2][j]));
    // Против накопления ошибки: снова делаем строки ортонормированными
    const a = norm(M[0]), b = norm(sub(M[1], mul(a, dot(a, M[1]))));
    M = [a, b, cross(a, b)];
  }
  rotate([0.6, 1, 0.3], 0.9);

  let W = 0, H = 0, dpr = 1;
  let x = 0, y = 0, vx = 0, vy = 0, w = [0, 0, 0];
  let raf = 0, last = 0, grab = null, result = 0;
  const floorY = () => H - R - 4;

  function resize() {
    dpr = Math.min(window.devicePixelRatio || 1, 2);
    W = cv.clientWidth; H = cv.clientHeight;
    if (!W || !H) return;
    cv.width = Math.round(W * dpr); cv.height = Math.round(H * dpr);
    if (!x) { x = W / 2; y = floorY(); }
    x = Math.min(Math.max(x, R), W - R); y = Math.min(y, floorY());
    draw();
  }

  function project(p) {
    const k = 260 / (260 - p[2] * R);
    return [x + p[0] * R * k, y + p[1] * R * k, k];
  }

  function draw() {
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, W, H);
    const P = verts.map((v) => project(apply(M, v)));
    const F = faces.map((f) => ({ f: f, n: apply(M, f.n), c: apply(M, f.c) }));
    const light = norm([-0.4, -0.7, 0.6]);

    // Тень на полу: чем выше кубик, тем она меньше и бледнее
    const lift = Math.max(0, floorY() - y);
    ctx.fillStyle = 'rgba(0,0,0,' + (0.35 * Math.max(0.2, 1 - lift / 160)).toFixed(3) + ')';
    ctx.beginPath(); ctx.ellipse(x, floorY() + R * 0.92, R * (0.85 - Math.min(lift, 160) / 500), 5, 0, 0, Math.PI * 2); ctx.fill();

    ctx.lineJoin = 'round';
    // Задние рёбра видны сквозь прозрачное тело
    ctx.strokeStyle = 'rgba(239,239,241,.12)';
    ctx.lineWidth = 1;
    edges.forEach((e) => {
      if (e.f.some((fi) => F[fi].n[2] > 0)) return;
      ctx.beginPath(); ctx.moveTo(P[e.a][0], P[e.a][1]); ctx.lineTo(P[e.b][0], P[e.b][1]); ctx.stroke();
    });
    F.forEach((q) => {
      if (q.n[2] <= 0) return;
      const shade = Math.max(0, dot(q.n, light));
      const hit = result && q.f.num === result;
      ctx.fillStyle = hit ? 'rgba(249,201,64,.30)' : 'rgba(249,201,64,' + (0.03 + shade * 0.09).toFixed(3) + ')';
      const [a, b, c] = q.f.v;
      ctx.beginPath(); ctx.moveTo(P[a][0], P[a][1]); ctx.lineTo(P[b][0], P[b][1]); ctx.lineTo(P[c][0], P[c][1]); ctx.closePath(); ctx.fill();
    });
    ctx.strokeStyle = 'rgba(239,239,241,.55)';
    ctx.lineWidth = 1.2;
    edges.forEach((e) => {
      if (!e.f.some((fi) => F[fi].n[2] > 0)) return;
      ctx.beginPath(); ctx.moveTo(P[e.a][0], P[e.a][1]); ctx.lineTo(P[e.b][0], P[e.b][1]); ctx.stroke();
    });

    // Числа лежат в плоскости грани: базис грани проецируется на экран, отсюда наклон и сжатие
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    F.forEach((q) => {
      if (q.n[2] < 0.2) return;
      const hit = result && q.f.num === result;
      const t1 = norm(sub(apply(M, verts[q.f.v[0]]), q.c));
      const t2 = cross(q.n, t1);
      const [px, py, k] = project(q.c);
      const s = R * 0.017 * k;
      ctx.setTransform(t2[0] * s * dpr, t2[1] * s * dpr, -t1[0] * s * dpr, -t1[1] * s * dpr, px * dpr, py * dpr);
      ctx.fillStyle = hit ? 'rgba(249,201,64,1)' : 'rgba(239,239,241,' + (0.2 + 0.7 * q.n[2]).toFixed(3) + ')';
      ctx.font = '700 22px "Space Mono", ui-monospace, monospace';
      const label = q.f.num === 6 || q.f.num === 9 ? q.f.num + '.' : String(q.f.num);
      ctx.fillText(label, 0, 2);
    });
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  }

  function settle() {
    let best = null;
    faces.forEach((f) => { const n = apply(M, f.n); if (!best || n[2] > best.z) best = { z: n[2], num: f.num }; });
    result = best.num;
    if (live) live.textContent = 'Выпало ' + result;
    draw();
  }

  function step(now) {
    raf = 0;
    const dt = Math.min(32, now - (last || now));
    last = now;
    if (!grab) {
      vy += G * dt;
      x += vx * dt; y += vy * dt;
      const fy = floorY();
      if (x < R) { x = R; vx = -vx * 0.6; w = add(w, [0, 0.004 * Math.sign(vy || 1), 0]); }
      if (x > W - R) { x = W - R; vx = -vx * 0.6; w = add(w, [0, -0.004, 0]); }
      if (y < R) { y = R; vy = Math.abs(vy) * 0.5; }
      const onFloor = y >= fy;
      if (onFloor) {
        y = fy;
        if (vy > 0.08) {
          const impact = vy;
          vy = -vy * 0.5; vx *= 0.85;
          w = add(mul(w, 0.7), [(Math.random() - 0.5) * impact * 0.02, (Math.random() - 0.5) * impact * 0.02, (Math.random() - 0.5) * impact * 0.01]);
        } else {
          vy = 0;
          vx *= Math.pow(0.996, dt);
          if (Math.abs(vx) < 0.004) vx = 0;
          // Катится по полу: вращение подтягивается к качению без проскальзывания
          const roll = vx / R;
          const blend = 1 - Math.pow(0.985, dt);
          w = [w[0] * (1 - blend), w[1] * (1 - blend), w[2] + (roll - w[2]) * blend];
          if (!vx) w = mul(w, Math.pow(0.993, dt));
        }
      } else {
        w = mul(w, Math.pow(0.9995, dt));
      }
      rotate(w, dt);
      if (onFloor && !vy && !vx && Math.hypot(...w) < 0.0004) {
        w = [0, 0, 0]; last = 0; settle(); return;
      }
    }
    draw();
    raf = requestAnimationFrame(step);
  }
  function play() { result = 0; if (live) live.textContent = ''; if (!raf) { last = 0; raf = requestAnimationFrame(step); } }

  function toss() {
    vx = (Math.random() - 0.5) * 0.8;
    vy = -(0.75 + Math.random() * 0.25);
    w = [(Math.random() - 0.5) * 0.07, (Math.random() - 0.5) * 0.07, (Math.random() - 0.5) * 0.03];
    play();
  }

  function local(e) { const r = cv.getBoundingClientRect(); return [e.clientX - r.left, e.clientY - r.top]; }
  cv.addEventListener('pointerdown', (e) => {
    const [px, py] = local(e);
    if (Math.hypot(px - x, py - y) > R * 1.25) return;
    cv.setPointerCapture(e.pointerId);
    cv.classList.add('is-grabbing');
    grab = { dx: px - x, dy: py - y, sx: px, sy: py, t0: e.timeStamp, trail: [[px, py, e.timeStamp]] };
    vx = vy = 0;
    play();
  });
  cv.addEventListener('pointermove', (e) => {
    const [px, py] = local(e);
    if (!grab) { cv.style.cursor = Math.hypot(px - x, py - y) <= R * 1.25 ? '' : 'default'; return; }
    const nx = Math.min(Math.max(px - grab.dx, R), W - R), ny = Math.min(Math.max(py - grab.dy, R), floorY());
    w = [(ny - y) * 0.004, -(nx - x) * 0.004, 0];
    x = nx; y = ny;
    grab.trail.push([px, py, e.timeStamp]);
    if (grab.trail.length > 6) grab.trail.shift();
  });
  function release(e) {
    if (!grab) return;
    const g = grab; grab = null;
    cv.classList.remove('is-grabbing');
    const [px, py] = local(e);
    if (Math.hypot(px - g.sx, py - g.sy) < 6 && e.timeStamp - g.t0 < 350) { toss(); return; }
    const a = g.trail[0], b = g.trail[g.trail.length - 1];
    const span = Math.max(16, b[2] - a[2]);
    const cap = (v) => Math.max(-2.5, Math.min(2.5, v));
    vx = cap((b[0] - a[0]) / span); vy = cap((b[1] - a[1]) / span);
    const speed = Math.hypot(vx, vy);
    w = add(w, [(Math.random() - 0.5) * speed * 0.03, (Math.random() - 0.5) * speed * 0.03, vx * 0.01]);
    play();
  }
  cv.addEventListener('pointerup', release);
  cv.addEventListener('pointercancel', release);
  cv.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toss(); }
  });

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
  const COMMANDS = ['help', 'ls', 'cd', 'pwd', 'cat', 'tree', 'whoami', 'echo', 'clear', 'uname', 'date', 'uptime', 'hostname', 'id', 'history'];
  const COMMANDS_WITH_ARG = new Set(['ls', 'cd', 'cat', 'tree', 'echo']);
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

  function handleCommand(command) {
    const tokens = command.split(/\s+/);
    const cmd = tokens[0].toLowerCase();
    const rest = tokens.slice(1);
    const flags = rest.filter(t => /^-[a-zA-Z]+$/.test(t)).join('').replace(/-/g, '');
    const args = rest.filter(t => !/^-[a-zA-Z]+$/.test(t));
    const arg = args.join(' ');
    const resolve = p => (p === '~' ? [] : p.startsWith('~/') ? normalizePath('/' + p.slice(2)) : normalizePath(p));

    switch (cmd) {
      case 'help':
        printInfo('Доступные команды:');
        printInfo('  ls [-la] [path]   cd [path]   pwd   cat <file>   tree [path]');
        printInfo('  whoami   id   hostname   uname [-a]   date   uptime   history');
        printInfo('  echo <text>   clear');
        printInfo('Tab дополняет команды и пути, стрелки вверх и вниз листают историю.');
        return;

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
