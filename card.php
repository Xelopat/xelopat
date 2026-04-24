<?php
declare(strict_types=1);

function card_cfg(array $source, string $path, $default = null) {
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

function card_e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function card_normalize_images($item): array {
    if (!is_array($item)) return [];
    $images = [];
    if (isset($item['images']) && is_array($item['images'])) {
        foreach ($item['images'] as $image) {
            $url = trim((string)$image);
            if ($url !== '') $images[] = $url;
        }
    }
    if (!$images) {
        $legacy = trim((string)($item['image'] ?? ''));
        if ($legacy !== '') $images[] = $legacy;
    }
    return array_values(array_unique($images));
}

function card_normalize_videos($item): array {
    if (!is_array($item)) return [];
    $videos = [];
    if (isset($item['videos']) && is_array($item['videos'])) {
        foreach ($item['videos'] as $video) {
            $url = trim((string)$video);
            if ($url !== '') $videos[] = $url;
        }
    }
    if (!$videos) {
        $legacy = trim((string)($item['video'] ?? ''));
        if ($legacy !== '') $videos[] = $legacy;
    }
    return array_values(array_unique($videos));
}

function card_normalize_items($items): array {
    if (!is_array($items)) return [];
    $out = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $images = card_normalize_images($item);
        $videos = card_normalize_videos($item);
        $out[] = [
            'title' => (string)($item['title'] ?? 'Без названия'),
            'date' => (string)($item['date'] ?? ''),
            'description' => (string)($item['description'] ?? ''),
            'details' => (string)($item['details'] ?? ''),
            'images' => $images,
            'videos' => $videos,
        ];
    }
    return $out;
}

function card_is_safe_url(string $url): bool {
    $value = trim($url);
    if ($value === '') return false;
    if (strpos($value, '/') === 0) return true;
    if (strpos($value, './') === 0 || strpos($value, '../') === 0) return true;
    return (bool)preg_match('#^https?://#i', $value);
}

function card_extract_attr(string $tag, string $attr): string {
    $pattern = '/\b' . preg_quote($attr, '/') . '\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i';
    if (!preg_match($pattern, $tag, $m)) return '';
    return html_entity_decode((string)($m[2] !== '' ? $m[2] : ($m[3] !== '' ? $m[3] : ($m[4] ?? ''))), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function card_sanitize_rich_text(string $html): string {
    $html = trim($html);
    if ($html === '') return '';

    $html = preg_replace('/<\s*(script|style)\b[^>]*>.*?<\s*\/\s*\1\s*>/is', '', $html) ?? '';
    $html = strip_tags($html, '<p><br><strong><b><em><i><u><ul><ol><li><blockquote><code><pre><a><img><h2><h3><h4>');
    $html = preg_replace('/\s(on\w+|style)\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/i', '', $html) ?? $html;

    $html = preg_replace_callback('/<img\b[^>]*>/i', static function (array $match): string {
        $tag = (string)$match[0];
        $src = trim(card_extract_attr($tag, 'src'));
        if (!card_is_safe_url($src)) return '';
        $alt = trim(card_extract_attr($tag, 'alt'));
        return '<img src="' . card_e($src) . '" alt="' . card_e($alt) . '" loading="lazy" decoding="async">';
    }, $html) ?? '';

    $html = preg_replace_callback('/<a\b[^>]*>/i', static function (array $match): string {
        $tag = (string)$match[0];
        $href = trim(card_extract_attr($tag, 'href'));
        if (!card_is_safe_url($href)) {
            $href = '#';
        }
        $external = (bool)preg_match('#^https?://#i', $href);
        $attrs = ' href="' . card_e($href) . '"';
        if ($external) {
            $attrs .= ' target="_blank" rel="noopener noreferrer"';
        }
        return '<a' . $attrs . '>';
    }, $html) ?? '';

    return $html;
}

$map = [
    'cards' => ['title' => 'Проекты', 'label' => '// projects', 'path' => '/projects/', 'fallback' => 'projects'],
    'travels' => ['title' => 'Путешествия', 'label' => '// travel', 'path' => '/travel/', 'fallback' => 'travel'],
    'photos' => ['title' => 'Фото', 'label' => '// photo', 'path' => '/photo/', 'fallback' => 'photo'],
];

$section = trim((string)($_GET['section'] ?? 'cards'));
$aliases = ['projects' => 'cards', 'travel' => 'travels', 'photo' => 'photos'];
if (isset($aliases[$section])) {
    $section = $aliases[$section];
}
if (!isset($map[$section])) {
    $section = 'cards';
}
$id = (int)($_GET['id'] ?? -1);

$config = [];
$config_path = __DIR__ . '/data/site_config.json';
if (is_file($config_path)) {
    $raw = file_get_contents($config_path);
    if ($raw !== false) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) $config = $decoded;
    }
}

$items = card_normalize_items(card_cfg($config, $section, []));
if (!$items) {
    $items = card_normalize_items(card_cfg($config, (string)$map[$section]['fallback'], []));
}
$item = ($id >= 0 && isset($items[$id]) && is_array($items[$id])) ? $items[$id] : null;
if (!$item) {
    http_response_code(404);
}

$section_title = (string)$map[$section]['title'];
$section_label = (string)$map[$section]['label'];
$back_path = (string)$map[$section]['path'];
$page_title = $item ? ((string)$item['title'] . ' - ' . $section_title) : 'Карточка не найдена';
$details_source = $item ? ((string)$item['details'] !== '' ? (string)$item['details'] : (string)$item['description']) : '';
$details_html = card_sanitize_rich_text($details_source);
$image_count = $item ? count((array)$item['images']) : 0;
$video_count = $item ? count((array)$item['videos']) : 0;
$has_mixed_media = $image_count > 0 && $video_count > 0;
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= card_e($page_title) ?></title>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Inter:wght@400;500;700&display=swap');
    *{box-sizing:border-box}
    body{margin:0;background:#151518;color:#efeff1;font-family:Inter,system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif}
    .page{width:min(1180px,calc(100vw - 24px));margin:26px auto 40px}
    .top{display:grid;gap:12px;margin-bottom:18px}
    .label{font-family:'Space Mono',ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:11px;color:#61d1ad}
    .title{margin:0;font-size:30px;line-height:1.2}
    .meta{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
    .pill{border:1px solid #32384a;background:#1a1f2b;color:#aeb4cb;border-radius:8px;padding:6px 8px;font-size:12px}
    .back{
      display:inline-flex;align-items:center;justify-content:center;width:max-content;
      border:1px solid #3a3f51;border-radius:8px;background:#191d29;color:#efeff1;text-decoration:none;
      font-size:12px;line-height:1;padding:8px 10px;
    }
    .media-toolbar{
      display:flex;
      flex-wrap:wrap;
      gap:8px;
      margin-bottom:12px;
    }
    .filter-btn{
      border:1px solid #343a4c;
      background:#161b27;
      color:#bfc5dd;
      border-radius:8px;
      padding:7px 10px;
      font-size:12px;
      line-height:1;
      cursor:pointer;
    }
    .filter-btn.is-active{
      border-color:#f9c940;
      color:#f9c940;
      background:#232313;
    }
    .grid{display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(260px,1fr))}
    .media{
      background:#1e1e25;
      border:1px solid #333340;
      border-radius:12px;
      overflow:hidden;
      position:relative;
      padding:8px;
      min-height:140px;
      display:flex;
      align-items:center;
      justify-content:center;
    }
    .media img,.media video{
      width:auto;
      height:auto;
      max-width:100%;
      max-height:72vh;
      object-fit:contain;
      display:block;
      background:#0f1118;
      border-radius:8px;
    }
    .media img[data-open-media]{cursor:zoom-in}
    .media-open{
      position:absolute;
      top:12px;
      right:12px;
      border:1px solid #434a5f;
      background:rgba(13,16,25,.86);
      color:#eff1ff;
      border-radius:8px;
      padding:6px 8px;
      font-size:12px;
      line-height:1;
      cursor:pointer;
    }
    .content{
      margin-top:14px;padding:16px 18px;border:1px solid #333340;border-radius:12px;background:#1e1e25;
      color:#d8dceb;line-height:1.7;font-size:15px;overflow-wrap:anywhere;
    }
    .content p{margin:0 0 12px}
    .content p:last-child{margin-bottom:0}
    .content img{max-width:100%;height:auto;display:block;margin:12px auto;border-radius:8px;border:1px solid #333340;background:#0f1118}
    .content a{color:#f9c940}
    .empty{color:#9aa0b8}
    .viewer{
      position:fixed;
      inset:0;
      z-index:1000;
      background:rgba(9,10,16,.92);
      display:flex;
      align-items:center;
      justify-content:center;
      padding:20px;
    }
    .viewer[hidden]{display:none}
    .viewer-inner{
      width:min(96vw, 1400px);
      height:min(92vh, 900px);
      border:1px solid #343a4d;
      border-radius:12px;
      background:#10131d;
      position:relative;
      display:flex;
      align-items:center;
      justify-content:center;
      overflow:hidden;
    }
    .viewer-inner img,
    .viewer-inner video{
      width:auto;
      height:auto;
      max-width:100%;
      max-height:100%;
      object-fit:contain;
      background:#0a0d14;
    }
    .viewer-actions{
      position:absolute;
      top:10px;
      right:10px;
      display:flex;
      gap:8px;
      z-index:2;
    }
    .viewer-btn{
      border:1px solid #434a5f;
      background:rgba(13,16,25,.86);
      color:#eff1ff;
      border-radius:8px;
      padding:7px 10px;
      font-size:12px;
      line-height:1;
      cursor:pointer;
    }
    @media (max-width:980px){
      .page{width:calc(100vw - 20px)}
      .title{font-size:24px}
      .grid{grid-template-columns:1fr}
      .media img,.media video{max-height:62vh}
      .content{font-size:14px}
    }
  </style>
</head>
<body>
<?php @include $_SERVER['DOCUMENT_ROOT'] . '/header.php'; ?>
<div class="page">
  <?php if (!$item): ?>
    <div class="top">
      <div class="label">// 404</div>
      <h1 class="title">Карточка не найдена</h1>
      <a class="back" href="<?= card_e($back_path) ?>">Назад в раздел</a>
    </div>
  <?php else: ?>
    <div class="top">
      <div class="label"><?= card_e($section_label) ?></div>
      <h1 class="title"><?= card_e((string)$item['title']) ?></h1>
      <div class="meta">
        <span class="pill"><?= card_e($section_title) ?></span>
        <?php if ((string)$item['date'] !== ''): ?><span class="pill"><?= card_e((string)$item['date']) ?></span><?php endif; ?>
      </div>
      <a class="back" href="<?= card_e($back_path) ?>">← Назад в раздел</a>
    </div>

    <?php if (!empty($item['images']) || !empty($item['videos'])): ?>
      <?php if ($has_mixed_media): ?>
        <div class="media-toolbar" id="mediaToolbar">
          <button class="filter-btn is-active" type="button" data-filter="all">Все (<?= (int)($image_count + $video_count) ?>)</button>
          <button class="filter-btn" type="button" data-filter="image">Фото (<?= (int)$image_count ?>)</button>
          <button class="filter-btn" type="button" data-filter="video">Видео (<?= (int)$video_count ?>)</button>
        </div>
      <?php endif; ?>
      <div class="grid">
        <?php foreach ((array)$item['images'] as $image): ?>
          <article class="media" data-kind="image">
            <button class="media-open" type="button" data-open-media data-type="image" data-src="<?= card_e((string)$image) ?>">⤢</button>
            <img src="<?= card_e((string)$image) ?>" alt="<?= card_e((string)$item['title']) ?>" loading="lazy" decoding="async" data-open-media data-type="image" data-src="<?= card_e((string)$image) ?>">
          </article>
        <?php endforeach; ?>
        <?php foreach ((array)$item['videos'] as $video): ?>
          <article class="media" data-kind="video">
            <button class="media-open" type="button" data-open-media data-type="video" data-src="<?= card_e((string)$video) ?>">⤢</button>
            <video src="<?= card_e((string)$video) ?>" controls preload="metadata" playsinline></video>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="content">
      <?php if ($details_html !== ''): ?>
        <?= $details_html ?>
      <?php else: ?>
        <p class="empty">Подробное описание пока не добавлено.</p>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
<div class="viewer" id="mediaViewer" hidden>
  <div class="viewer-inner" id="mediaViewerInner">
    <div class="viewer-actions">
      <button class="viewer-btn" type="button" id="viewerFullscreenBtn">На весь экран</button>
      <button class="viewer-btn" type="button" id="viewerCloseBtn">Закрыть</button>
    </div>
    <img id="viewerImage" alt="" hidden>
    <video id="viewerVideo" controls playsinline hidden></video>
  </div>
</div>
<script>
(function () {
  const toolbar = document.getElementById('mediaToolbar');
  if (toolbar) {
    const buttons = Array.from(toolbar.querySelectorAll('[data-filter]'));
    const mediaItems = Array.from(document.querySelectorAll('.media[data-kind]'));
    buttons.forEach((btn) => {
      btn.addEventListener('click', function () {
        const filter = String(btn.getAttribute('data-filter') || 'all');
        buttons.forEach((b) => b.classList.toggle('is-active', b === btn));
        mediaItems.forEach((item) => {
          const kind = String(item.getAttribute('data-kind') || 'all');
          item.hidden = filter !== 'all' && filter !== kind;
        });
      });
    });
  }

  const viewer = document.getElementById('mediaViewer');
  const viewerInner = document.getElementById('mediaViewerInner');
  const viewerImage = document.getElementById('viewerImage');
  const viewerVideo = document.getElementById('viewerVideo');
  const closeBtn = document.getElementById('viewerCloseBtn');
  const fsBtn = document.getElementById('viewerFullscreenBtn');
  if (!viewer || !viewerInner || !viewerImage || !viewerVideo || !closeBtn || !fsBtn) return;

  function openViewer(type, src) {
    const mediaType = String(type || '');
    const mediaSrc = String(src || '');
    if (!mediaSrc) return;

    if (mediaType === 'video') {
      viewerImage.hidden = true;
      viewerImage.removeAttribute('src');
      viewerVideo.hidden = false;
      viewerVideo.src = mediaSrc;
      viewerVideo.currentTime = 0;
      viewerVideo.play().catch(() => {});
    } else {
      viewerVideo.pause();
      viewerVideo.hidden = true;
      viewerVideo.removeAttribute('src');
      viewerImage.hidden = false;
      viewerImage.src = mediaSrc;
    }
    viewer.hidden = false;
    document.body.style.overflow = 'hidden';
  }

  function closeViewer() {
    viewer.hidden = true;
    document.body.style.overflow = '';
    viewerVideo.pause();
    viewerVideo.removeAttribute('src');
    viewerImage.removeAttribute('src');
    if (document.fullscreenElement) {
      document.exitFullscreen().catch(() => {});
    }
  }

  document.addEventListener('click', function (event) {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;
    const trigger = target.closest('[data-open-media]');
    if (!trigger) return;
    const type = trigger.getAttribute('data-type') || 'image';
    const src = trigger.getAttribute('data-src') || '';
    openViewer(type, src);
  });

  viewer.addEventListener('click', function (event) {
    if (event.target === viewer) {
      closeViewer();
    }
  });

  closeBtn.addEventListener('click', closeViewer);

  fsBtn.addEventListener('click', function () {
    if (!document.fullscreenElement) {
      if (typeof viewerInner.requestFullscreen === 'function') {
        viewerInner.requestFullscreen().catch(() => {});
      }
    } else {
      if (typeof document.exitFullscreen === 'function') {
        document.exitFullscreen().catch(() => {});
      }
    }
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && !viewer.hidden) {
      closeViewer();
    }
  });
})();
</script>
</body>
</html>
