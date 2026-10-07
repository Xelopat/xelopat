<?php
// Общие разделы-коллекции (проекты, путешествия, фото): хранение, миграция, загрузка файлов.
// Данные: /data/collections/<key>.json (закрыто от веба и не трогается деплоем).
// Файлы:  /uploads/<key>/ (публичные, деплой их тоже не трогает).
declare(strict_types=1);

const COLLECTIONS = [
    'projects' => [
        'title' => 'Проекты',
        'label' => '// projects',
        'url' => '/projects/index.php',
        'legacy' => ['cards', 'projects'],
        'layout' => 'cards',
        'empty' => 'Проектов пока нет.',
    ],
    'travel' => [
        'title' => 'Путешествия',
        'label' => '// travel',
        'url' => '/travel/index.php',
        'legacy' => ['travels', 'travel'],
        'layout' => 'cards',
        'empty' => 'Путешествий пока нет.',
    ],
    'photo' => [
        'title' => 'Фото',
        'label' => '// photo',
        'url' => '/photo/index.php',
        'legacy' => ['photos', 'photo'],
        'layout' => 'gallery',
        'empty' => 'Фотографий пока нет.',
    ],
];

const COLL_IMAGE_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
const COLL_VIDEO_EXT = ['mp4', 'webm', 'mov', 'm4v'];
const COLL_IMAGE_MAX = 25 * 1024 * 1024;
const COLL_VIDEO_MAX = 500 * 1024 * 1024;
const COLL_PREVIEW_SIDE = 900;

function coll_root(): string {
    return rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__)), '/\\');
}

function coll_def(string $key): array {
    if (!isset(COLLECTIONS[$key])) throw new InvalidArgumentException('Unknown collection ' . $key);
    return COLLECTIONS[$key];
}

function coll_file(string $key): string {
    return coll_root() . '/data/collections/' . $key . '.json';
}

function coll_upload_dir(string $key): string {
    return coll_root() . '/uploads/' . $key;
}

function coll_upload_url(string $key): string {
    return '/uploads/' . $key;
}

function coll_new_id(): string {
    return bin2hex(random_bytes(5));
}

function coll_media_kind(string $src): string {
    $ext = strtolower(pathinfo(parse_url($src, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
    return in_array($ext, COLL_VIDEO_EXT, true) ? 'video' : 'image';
}

function coll_lower(string $s): string {
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

function coll_clean_tags($raw): array {
    $parts = is_array($raw) ? $raw : preg_split('/[,;\n]+/u', (string)$raw);
    $out = [];
    foreach ($parts as $t) {
        $t = trim((string)$t);
        if ($t !== '' && !in_array(coll_lower($t), array_map('coll_lower', $out), true)) $out[] = function_exists('mb_substr') ? mb_substr($t, 0, 40) : substr($t, 0, 40);
    }
    return array_slice($out, 0, 20);
}

function coll_normalize_item(array $it): array {
    $media = [];
    foreach ((array)($it['media'] ?? []) as $m) {
        if (!is_array($m) || trim((string)($m['src'] ?? '')) === '') continue;
        $media[] = [
            'type' => ($m['type'] ?? '') === 'video' ? 'video' : 'image',
            'src' => (string)$m['src'],
            'preview' => (string)($m['preview'] ?? ''),
        ];
    }
    return [
        'id' => (string)($it['id'] ?? coll_new_id()),
        'title' => (string)($it['title'] ?? ''),
        'date' => (string)($it['date'] ?? ''),
        'description' => (string)($it['description'] ?? ''),
        'details' => (string)($it['details'] ?? ''),
        'tags' => coll_clean_tags($it['tags'] ?? []),
        'link' => (string)($it['link'] ?? ''),
        // Как показывать обложку на карточке: auto — заполнить рамку, width — по ширине, height — по высоте
        'fit' => in_array($it['fit'] ?? '', ['width', 'height'], true) ? $it['fit'] : 'auto',
        'media' => $media,
        'created' => (string)($it['created'] ?? date('c')),
        'updated' => (string)($it['updated'] ?? date('c')),
        'legacy_index' => isset($it['legacy_index']) ? (int)$it['legacy_index'] : null,
    ];
}

// Старые «подробности» могли быть HTML — переводим в обычный текст с переносами
function coll_html_to_text(string $html): string {
    if (strpos($html, '<') === false) return trim($html);
    $t = preg_replace('~<\s*br\s*/?>~i', "\n", $html);
    $t = preg_replace('~</\s*(p|div|li|h[1-6])\s*>~i', "\n", $t);
    $t = preg_replace('~<\s*li[^>]*>~i', '- ', $t);
    $t = preg_replace_callback('~<a\s[^>]*href=(["\'])([^"\']+)\1[^>]*>(.*?)</a>~is', function ($m) {
        $label = trim(strip_tags($m[3]));
        return $label === '' || $label === $m[2] ? $m[2] : $label . ' (' . $m[2] . ')';
    }, $t);
    $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace("~\n{3,}~", "\n\n", $t));
}

// Перенос старых карточек из site_config.json (их редактировала admin.php)
function coll_migrate(string $key): array {
    $path = coll_root() . '/data/site_config.json';
    $config = is_file($path) ? json_decode((string)file_get_contents($path), true) : [];
    if (!is_array($config)) return [];
    $old = [];
    foreach (coll_def($key)['legacy'] as $legacyKey) {
        if (!empty($config[$legacyKey]) && is_array($config[$legacyKey])) { $old = $config[$legacyKey]; break; }
    }
    $items = [];
    foreach (array_values($old) as $i => $o) {
        if (!is_array($o)) continue;
        $urls = [];
        foreach (['images', 'videos'] as $k) foreach ((array)($o[$k] ?? []) as $u) $urls[] = trim((string)$u);
        foreach (['image', 'video'] as $k) $urls[] = trim((string)($o[$k] ?? ''));
        $media = [];
        foreach (array_unique(array_filter($urls)) as $u) {
            // Локальные файлы, которые стёр прошлый деплой, не переносим — иначе будут битые картинки
            if ($u[0] === '/' && !is_file(coll_root() . parse_url($u, PHP_URL_PATH))) continue;
            $media[] = ['type' => coll_media_kind($u), 'src' => $u, 'preview' => ''];
        }
        $items[] = coll_normalize_item([
            'title' => $o['title'] ?? '',
            'date' => $o['date'] ?? '',
            'description' => coll_html_to_text((string)($o['description'] ?? '')),
            'details' => coll_html_to_text((string)($o['details'] ?? '')),
            'media' => $media,
            'legacy_index' => $i,
        ]);
    }
    return $items;
}

function coll_load(string $key): array {
    $file = coll_file($key);
    if (!is_file($file)) {
        $items = coll_migrate($key);
        coll_write($key, $items);
        return $items;
    }
    $data = json_decode((string)file_get_contents($file), true);
    $items = [];
    foreach ((array)($data['items'] ?? []) as $it) if (is_array($it)) $items[] = coll_normalize_item($it);
    return $items;
}

function coll_write(string $key, array $items): void {
    $file = coll_file($key);
    if (!is_dir(dirname($file))) @mkdir(dirname($file), 0755, true);
    file_put_contents($file, json_encode(['items' => array_values($items)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

// Изменение под блокировкой, чтобы две вкладки не затёрли друг друга
function coll_mutate(string $key, callable $fn) {
    $lock = fopen(coll_file($key) . '.lock', 'c');
    if ($lock) flock($lock, LOCK_EX);
    try {
        $items = coll_load($key);
        $result = $fn($items);
        coll_write($key, $items);
        return $result;
    } finally {
        if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    }
}

// Новые сверху; без даты — в конце, по времени создания
function coll_sorted(array $items): array {
    usort($items, function ($a, $b) {
        if ($a['date'] !== $b['date']) {
            if ($a['date'] === '') return 1;
            if ($b['date'] === '') return -1;
            return strcmp($b['date'], $a['date']);
        }
        return strcmp($b['created'], $a['created']);
    });
    return $items;
}

function coll_cover(array $item): ?array {
    foreach ($item['media'] as $m) if ($m['type'] === 'image') return $m;
    return $item['media'][0] ?? null;
}

function coll_item_url(string $key, array $item): string {
    return coll_def($key)['url'] . '?item=' . rawurlencode($item['id']);
}

/* ---------------- файлы ---------------- */

function coll_ini_bytes(string $v): int {
    $v = trim($v);
    $n = (int)$v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024;
        case 'm': $n *= 1024;
        case 'k': $n *= 1024;
    }
    return $n;
}

function coll_upload_limit(): int {
    $a = coll_ini_bytes((string)ini_get('upload_max_filesize'));
    $b = coll_ini_bytes((string)ini_get('post_max_size'));
    return min(array_filter([$a, $b]) ?: [0]);
}

function coll_make_preview(string $path, string $ext, string $destBase): string {
    if (!function_exists('imagecreatetruecolor')) return '';
    $src = null;
    if (in_array($ext, ['jpg', 'jpeg'], true) && function_exists('imagecreatefromjpeg')) $src = @imagecreatefromjpeg($path);
    elseif ($ext === 'png' && function_exists('imagecreatefrompng')) $src = @imagecreatefrompng($path);
    elseif ($ext === 'webp' && function_exists('imagecreatefromwebp')) $src = @imagecreatefromwebp($path);
    if (!$src) return '';

    // Фото с телефона часто лежат «на боку» — поворачиваем по EXIF
    if (in_array($ext, ['jpg', 'jpeg'], true) && function_exists('exif_read_data')) {
        $exif = @exif_read_data($path);
        $rot = [3 => 180, 6 => -90, 8 => 90][(int)($exif['Orientation'] ?? 1)] ?? 0;
        if ($rot) { $r = imagerotate($src, $rot, 0); if ($r) { imagedestroy($src); $src = $r; } }
    }

    $w = imagesx($src); $h = imagesy($src);
    $scale = min(1, COLL_PREVIEW_SIDE / max($w, $h, 1));
    $nw = max(1, (int)round($w * $scale)); $nh = max(1, (int)round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefilledrectangle($dst, 0, 0, $nw, $nh, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($src);

    $out = function_exists('imagewebp') ? $destBase . '_p.webp' : $destBase . '_p.jpg';
    $ok = function_exists('imagewebp') ? @imagewebp($dst, $out, 80) : @imagejpeg($dst, $out, 82);
    imagedestroy($dst);
    return $ok ? basename($out) : '';
}

// Возвращает список media + ошибки по отдельным файлам
function coll_handle_uploads(string $key, ?array $files, array &$errors): array {
    $media = [];
    if (!$files || !isset($files['name']) || !is_array($files['name'])) return $media;
    $dir = coll_upload_dir($key);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        $errors[] = 'Не удалось создать папку для файлов.';
        return $media;
    }
    $url = coll_upload_url($key);

    foreach ($files['name'] as $i => $name) {
        $name = (string)$name;
        $err = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) continue;
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) { $errors[] = "«{$name}»: слишком большой файл для сервера."; continue; }
        if ($err !== UPLOAD_ERR_OK) { $errors[] = "«{$name}»: ошибка загрузки ({$err})."; continue; }

        $tmp = (string)$files['tmp_name'][$i];
        $size = (int)$files['size'][$i];
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $isImage = in_array($ext, COLL_IMAGE_EXT, true);
        $isVideo = in_array($ext, COLL_VIDEO_EXT, true);
        if (!$isImage && !$isVideo) { $errors[] = "«{$name}»: такой формат не поддерживается."; continue; }
        if ($size > ($isImage ? COLL_IMAGE_MAX : COLL_VIDEO_MAX)) { $errors[] = "«{$name}»: слишком большой файл."; continue; }

        $mime = function_exists('mime_content_type') ? (string)@mime_content_type($tmp) : '';
        if ($mime !== '' && strpos($mime, $isImage ? 'image/' : 'video/') !== 0 && !($isVideo && $mime === 'application/octet-stream')) {
            $errors[] = "«{$name}»: содержимое не похоже на " . ($isImage ? 'картинку' : 'видео') . '.';
            continue;
        }

        $base = date('Ymd_His') . '_' . bin2hex(random_bytes(4));
        $file = $base . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
        if (!move_uploaded_file($tmp, $dir . '/' . $file)) { $errors[] = "«{$name}»: не удалось сохранить."; continue; }
        @chmod($dir . '/' . $file, 0644);

        $preview = ($isImage && $ext !== 'gif') ? coll_make_preview($dir . '/' . $file, $ext === 'jpeg' ? 'jpg' : $ext, $dir . '/' . $base) : '';
        // Ключ = порядковый номер файла в запросе: форма ссылается на него в списке порядка
        $media[$i] = [
            'type' => $isImage ? 'image' : 'video',
            'src' => $url . '/' . $file,
            'preview' => $preview !== '' ? $url . '/' . $preview : '',
        ];
    }
    return $media;
}

// Удаляем только свои файлы из /uploads/<key>/
function coll_delete_media_files(string $key, array $m): void {
    $prefix = coll_upload_url($key) . '/';
    foreach ([$m['src'] ?? '', $m['preview'] ?? ''] as $u) {
        if (strpos((string)$u, $prefix) !== 0) continue;
        $name = basename((string)$u);
        if (!preg_match('/^[A-Za-z0-9._-]{1,120}$/', $name)) continue;
        $path = coll_upload_dir($key) . '/' . $name;
        if (is_file($path)) @unlink($path);
    }
}
