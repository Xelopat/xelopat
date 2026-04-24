<?php
declare(strict_types=1);

$page_title = isset($page_title) ? (string)$page_title : 'Раздел';
$section_label = isset($section_label) ? (string)$section_label : '// section';
$data_key = isset($data_key) ? (string)$data_key : '';
$fallback_key = isset($fallback_key) ? (string)$fallback_key : '';

if ($data_key === '') {
    $data_key = 'cards';
}

function cards_cfg(array $source, string $path, $default = null) {
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

function cards_e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function cards_normalize_images($item): array {
    if (!is_array($item)) return [];

    $images = [];
    if (isset($item['images']) && is_array($item['images'])) {
        foreach ($item['images'] as $image) {
            $url = trim((string)$image);
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

function cards_normalize_videos($item): array {
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

function cards_normalize($items): array {
    if (!is_array($items)) return [];
    $out = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $images = cards_normalize_images($item);
        $videos = cards_normalize_videos($item);
        $out[] = [
            'title' => (string)($item['title'] ?? 'Без названия'),
            'description' => (string)($item['description'] ?? ''),
            'images' => $images,
            'image' => $images[0] ?? '',
            'videos' => $videos,
            'video' => $videos[0] ?? '',
        ];
    }
    return $out;
}

$config = [];
$config_path = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\') . '/data/site_config.json';
if ($config_path !== '/data/site_config.json' && is_file($config_path)) {
    $raw = file_get_contents($config_path);
    if ($raw !== false) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $config = $decoded;
        }
    }
}

$items = cards_normalize(cards_cfg($config, $data_key, []));
if (!$items && $fallback_key !== '') {
    $items = cards_normalize(cards_cfg($config, $fallback_key, []));
}

?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= cards_e($page_title) ?></title>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Inter:wght@400;500;700&display=swap');
    *{box-sizing:border-box}
    body{margin:0;background:#151518;color:#efeff1;font-family:Inter,system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif}
    .page{width:min(1280px, calc(100vw - 24px));margin:26px auto 40px}
    .sec-label{font-family:'Space Mono',ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:11px;color:#61d1ad;margin-bottom:6px}
    .sec-title{font-size:28px;font-weight:700;margin:0 0 18px}
    .cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
    .card{background:#1e1e25;border:1px solid #333340;border-radius:12px;overflow:hidden}
    .card-media{
      width:100%;
      aspect-ratio:16/9;
      background:#151518;
      border-bottom:1px solid #333340;
      display:grid;
      place-items:center;
      padding:10px;
    }
    .card-media img{
      width:100%;
      height:100%;
      object-fit:contain;
      display:block;
    }
    .card-media video{
      width:100%;
      height:100%;
      object-fit:contain;
      display:block;
      background:#0f1118;
    }
    .card-inner{padding:14px 16px}
    .card-title{font-size:16px;font-weight:700;margin:0 0 6px}
    .card-desc{margin:0;font-size:13px;line-height:1.55;color:#868899}
    @media (max-width:980px){
      .page{width:calc(100vw - 20px)}
      .sec-title{font-size:24px}
      .cards{grid-template-columns:1fr}
      .card-title{font-size:18px}
      .card-desc{font-size:14px}
    }
  </style>
</head>
<body>
<?php @include $_SERVER['DOCUMENT_ROOT'] . '/header.php'; ?>

<div class="page">
  <div class="sec-label"><?= cards_e($section_label) ?></div>
  <h1 class="sec-title"><?= cards_e($page_title) ?></h1>

  <?php if ($items): ?>
    <div class="cards">
      <?php foreach ($items as $item): ?>
        <article class="card">
          <div class="card-media">
            <?php $first_image = (string)($item['images'][0] ?? ''); ?>
            <?php $first_video = (string)($item['videos'][0] ?? ''); ?>
            <?php if ($first_image !== ''): ?>
              <img src="<?= cards_e($first_image) ?>" alt="<?= cards_e($item['title']) ?>">
            <?php elseif ($first_video !== ''): ?>
              <video src="<?= cards_e($first_video) ?>" controls preload="metadata" playsinline title="<?= cards_e($item['title'] . ' видео') ?>"></video>
            <?php endif; ?>
          </div>
          <div class="card-inner">
            <h2 class="card-title"><?= cards_e($item['title']) ?></h2>
            <p class="card-desc"><?= cards_e($item['description']) ?></p>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
