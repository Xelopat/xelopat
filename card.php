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
    .grid{display:grid;gap:14px;grid-template-columns:repeat(2,minmax(0,1fr))}
    .media{background:#1e1e25;border:1px solid #333340;border-radius:12px;overflow:hidden}
    .media img,.media video{width:100%;height:360px;object-fit:contain;display:block;background:#0f1118}
    .content{
      margin-top:14px;padding:16px 18px;border:1px solid #333340;border-radius:12px;background:#1e1e25;
      color:#d8dceb;line-height:1.7;font-size:15px;overflow-wrap:anywhere;
    }
    .content p{margin:0 0 12px}
    .content p:last-child{margin-bottom:0}
    .content img{max-width:100%;height:auto;display:block;margin:12px auto;border-radius:8px;border:1px solid #333340;background:#0f1118}
    .content a{color:#f9c940}
    .empty{color:#9aa0b8}
    @media (max-width:980px){
      .page{width:calc(100vw - 20px)}
      .title{font-size:24px}
      .grid{grid-template-columns:1fr}
      .media img,.media video{height:260px}
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
      <div class="grid">
        <?php foreach ((array)$item['images'] as $image): ?>
          <div class="media"><img src="<?= card_e((string)$image) ?>" alt="<?= card_e((string)$item['title']) ?>"></div>
        <?php endforeach; ?>
        <?php foreach ((array)$item['videos'] as $video): ?>
          <div class="media"><video src="<?= card_e((string)$video) ?>" controls preload="metadata" playsinline></video></div>
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
</body>
</html>
