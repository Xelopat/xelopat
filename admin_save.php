<?php
declare(strict_types=1);

require_once __DIR__ . '/auth/lib.php';
require_role('admin');

header('Content-Type: application/json; charset=utf-8');

function admin_json_response(bool $ok, string $message, array $extra = []): void {
    if (!$ok) {
        http_response_code(400);
    }
    $payload = array_merge(['ok' => $ok, 'message' => $message], $extra);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        $json = '{"ok":false,"message":"JSON encode error"}';
    }
    echo $json;
    exit;
}

function admin_decode_config(string $json): array {
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

function admin_clean_image_urls($raw): array {
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $value) {
        $url = trim((string)$value);
        if ($url === '') continue;
        $out[] = $url;
    }
    return array_values(array_unique($out));
}

function admin_clean_video_urls($raw): array {
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $value) {
        $url = trim((string)$value);
        if ($url === '') continue;
        $out[] = $url;
    }
    return array_values(array_unique($out));
}

function admin_save_config_array(string $config_path, array $decoded, string &$err): ?string {
    $dir = dirname($config_path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        $err = 'Не удалось создать папку data.';
        return null;
    }

    $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($encoded === false) {
        $err = 'Не удалось закодировать конфиг.';
        return null;
    }

    if (file_put_contents($config_path, $encoded . PHP_EOL) === false) {
        $err = 'Не удалось записать конфиг на диск.';
        return null;
    }

    return $encoded;
}

function admin_file_count(?array $files): int {
    if (!$files || !isset($files['name']) || !is_array($files['name'])) return 0;
    return count($files['name']);
}

function admin_nested_item_count(?array $files): int {
    if (!$files || !isset($files['name']) || !is_array($files['name'])) return 0;
    return count($files['name']);
}

function admin_nested_file_count(?array $files, int $item_index): int {
    if (!$files || !isset($files['name'][$item_index]) || !is_array($files['name'][$item_index])) return 0;
    return count($files['name'][$item_index]);
}

function admin_save_uploaded_image(?array $files, int $index, string &$err): string {
    $err = '';
    if (!$files || !isset($files['error']) || !is_array($files['error']) || !array_key_exists($index, $files['error'])) {
        return '';
    }

    $error = (int)$files['error'][$index];
    if ($error === UPLOAD_ERR_NO_FILE) return '';
    if ($error !== UPLOAD_ERR_OK) {
        $err = 'Ошибка загрузки файла (код: ' . $error . ').';
        return '';
    }

    $tmp = (string)($files['tmp_name'][$index] ?? '');
    $name = (string)($files['name'][$index] ?? '');
    $size = (int)($files['size'][$index] ?? 0);

    if ($tmp === '' || !is_uploaded_file($tmp)) {
        $err = 'Невалидный временный файл загрузки.';
        return '';
    }
    if ($size <= 0 || $size > 8 * 1024 * 1024) {
        $err = 'Фото слишком большое. Лимит: 8 МБ.';
        return '';
    }

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allowed_ext = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    if (!in_array($ext, $allowed_ext, true)) {
        $err = 'Разрешены только JPG, PNG, WEBP, GIF.';
        return '';
    }

    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = (string)finfo_file($finfo, $tmp);
            finfo_close($finfo);
            $allowed_mime = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            if ($mime !== '' && !in_array($mime, $allowed_mime, true)) {
                $err = 'Файл не похож на изображение.';
                return '';
            }
        }
    }

    $doc_root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? __DIR__), '/\\');
    $upload_dir = $doc_root . '/uploads/site';
    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0775, true) && !is_dir($upload_dir)) {
        $err = 'Не удалось создать папку uploads/site.';
        return '';
    }

    try {
        $rand = bin2hex(random_bytes(4));
    } catch (Throwable $e) {
        $rand = bin2hex(pack('N', mt_rand()));
    }
    $filename = 'card_' . date('Ymd_His') . '_' . $rand . '.' . $ext;
    $dest = $upload_dir . '/' . $filename;

    if (!move_uploaded_file($tmp, $dest)) {
        $err = 'Не удалось сохранить загруженное фото.';
        return '';
    }

    return '/uploads/site/' . $filename;
}

function admin_save_uploaded_image_nested(?array $files, int $item_index, int $file_index, string &$err): string {
    $err = '';
    if (
        !$files
        || !isset($files['error'][$item_index])
        || !is_array($files['error'][$item_index])
        || !array_key_exists($file_index, $files['error'][$item_index])
    ) {
        return '';
    }

    $error = (int)$files['error'][$item_index][$file_index];
    if ($error === UPLOAD_ERR_NO_FILE) return '';
    if ($error !== UPLOAD_ERR_OK) {
        $err = 'Ошибка загрузки файла (код: ' . $error . ').';
        return '';
    }

    $tmp = (string)($files['tmp_name'][$item_index][$file_index] ?? '');
    $name = (string)($files['name'][$item_index][$file_index] ?? '');
    $size = (int)($files['size'][$item_index][$file_index] ?? 0);

    if ($tmp === '' || !is_uploaded_file($tmp)) {
        $err = 'Невалидный временный файл загрузки.';
        return '';
    }
    if ($size <= 0 || $size > 8 * 1024 * 1024) {
        $err = 'Фото слишком большое. Лимит: 8 МБ.';
        return '';
    }

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allowed_ext = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    if (!in_array($ext, $allowed_ext, true)) {
        $err = 'Разрешены только JPG, PNG, WEBP, GIF.';
        return '';
    }

    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = (string)finfo_file($finfo, $tmp);
            finfo_close($finfo);
            $allowed_mime = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            if ($mime !== '' && !in_array($mime, $allowed_mime, true)) {
                $err = 'Файл не похож на изображение.';
                return '';
            }
        }
    }

    $doc_root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? __DIR__), '/\\');
    $upload_dir = $doc_root . '/uploads/site';
    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0775, true) && !is_dir($upload_dir)) {
        $err = 'Не удалось создать папку uploads/site.';
        return '';
    }

    try {
        $rand = bin2hex(random_bytes(4));
    } catch (Throwable $e) {
        $rand = bin2hex(pack('N', mt_rand()));
    }
    $filename = 'card_' . date('Ymd_His') . '_' . $rand . '.' . $ext;
    $dest = $upload_dir . '/' . $filename;

    if (!move_uploaded_file($tmp, $dest)) {
        $err = 'Не удалось сохранить загруженное фото.';
        return '';
    }

    return '/uploads/site/' . $filename;
}

function admin_save_uploaded_video(?array $files, int $index, string &$err): string {
    $err = '';
    if (!$files || !isset($files['error']) || !is_array($files['error']) || !array_key_exists($index, $files['error'])) {
        return '';
    }

    $error = (int)$files['error'][$index];
    if ($error === UPLOAD_ERR_NO_FILE) return '';
    if ($error !== UPLOAD_ERR_OK) {
        $err = 'Ошибка загрузки видео (код: ' . $error . ').';
        return '';
    }

    $tmp = (string)($files['tmp_name'][$index] ?? '');
    $name = (string)($files['name'][$index] ?? '');
    $size = (int)($files['size'][$index] ?? 0);

    if ($tmp === '' || !is_uploaded_file($tmp)) {
        $err = 'Невалидный временный файл загрузки видео.';
        return '';
    }
    if ($size <= 0 || $size > 64 * 1024 * 1024) {
        $err = 'Видео слишком большое. Лимит: 64 МБ.';
        return '';
    }

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allowed_ext = ['mp4', 'webm', 'ogg', 'mov', 'm4v'];
    if (!in_array($ext, $allowed_ext, true)) {
        $err = 'Разрешены только MP4, WEBM, OGG, MOV, M4V.';
        return '';
    }

    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = (string)finfo_file($finfo, $tmp);
            finfo_close($finfo);
            $allowed_mime = ['video/mp4', 'video/webm', 'video/ogg', 'video/quicktime', 'application/octet-stream'];
            if ($mime !== '' && !in_array($mime, $allowed_mime, true)) {
                $err = 'Файл не похож на видео.';
                return '';
            }
        }
    }

    $doc_root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? __DIR__), '/\\');
    $upload_dir = $doc_root . '/uploads/site';
    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0775, true) && !is_dir($upload_dir)) {
        $err = 'Не удалось создать папку uploads/site.';
        return '';
    }

    try {
        $rand = bin2hex(random_bytes(4));
    } catch (Throwable $e) {
        $rand = bin2hex(pack('N', mt_rand()));
    }
    $filename = 'card_' . date('Ymd_His') . '_' . $rand . '.' . $ext;
    $dest = $upload_dir . '/' . $filename;

    if (!move_uploaded_file($tmp, $dest)) {
        $err = 'Не удалось сохранить загруженное видео.';
        return '';
    }

    return '/uploads/site/' . $filename;
}

function admin_save_uploaded_video_nested(?array $files, int $item_index, int $file_index, string &$err): string {
    $err = '';
    if (
        !$files
        || !isset($files['error'][$item_index])
        || !is_array($files['error'][$item_index])
        || !array_key_exists($file_index, $files['error'][$item_index])
    ) {
        return '';
    }

    $error = (int)$files['error'][$item_index][$file_index];
    if ($error === UPLOAD_ERR_NO_FILE) return '';
    if ($error !== UPLOAD_ERR_OK) {
        $err = 'Ошибка загрузки видео (код: ' . $error . ').';
        return '';
    }

    $tmp = (string)($files['tmp_name'][$item_index][$file_index] ?? '');
    $name = (string)($files['name'][$item_index][$file_index] ?? '');
    $size = (int)($files['size'][$item_index][$file_index] ?? 0);

    if ($tmp === '' || !is_uploaded_file($tmp)) {
        $err = 'Невалидный временный файл загрузки видео.';
        return '';
    }
    if ($size <= 0 || $size > 64 * 1024 * 1024) {
        $err = 'Видео слишком большое. Лимит: 64 МБ.';
        return '';
    }

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allowed_ext = ['mp4', 'webm', 'ogg', 'mov', 'm4v'];
    if (!in_array($ext, $allowed_ext, true)) {
        $err = 'Разрешены только MP4, WEBM, OGG, MOV, M4V.';
        return '';
    }

    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = (string)finfo_file($finfo, $tmp);
            finfo_close($finfo);
            $allowed_mime = ['video/mp4', 'video/webm', 'video/ogg', 'video/quicktime', 'application/octet-stream'];
            if ($mime !== '' && !in_array($mime, $allowed_mime, true)) {
                $err = 'Файл не похож на видео.';
                return '';
            }
        }
    }

    $doc_root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? __DIR__), '/\\');
    $upload_dir = $doc_root . '/uploads/site';
    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0775, true) && !is_dir($upload_dir)) {
        $err = 'Не удалось создать папку uploads/site.';
        return '';
    }

    try {
        $rand = bin2hex(random_bytes(4));
    } catch (Throwable $e) {
        $rand = bin2hex(pack('N', mt_rand()));
    }
    $filename = 'card_' . date('Ymd_His') . '_' . $rand . '.' . $ext;
    $dest = $upload_dir . '/' . $filename;

    if (!move_uploaded_file($tmp, $dest)) {
        $err = 'Не удалось сохранить загруженное видео.';
        return '';
    }

    return '/uploads/site/' . $filename;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    admin_json_response(false, 'Только POST-запросы.');
}

if (!csrf_check((string)($_POST['csrf'] ?? ''))) {
    admin_json_response(false, 'CSRF: обнови страницу.');
}

$action = (string)($_POST['action'] ?? '');
$config_path = __DIR__ . '/data/site_config.json';
$config_json = '{}';
if (is_file($config_path)) {
    $raw = file_get_contents($config_path);
    if ($raw !== false) {
        $config_json = $raw;
    }
}

if ($action === 'save_site_config') {
    $incoming = trim((string)($_POST['site_config_json'] ?? ''));
    $decoded = json_decode($incoming, true);
    if (!is_array($decoded)) {
        admin_json_response(false, 'JSON невалидный. Проверь запятые, кавычки и структуру.');
    }

    $err = '';
    $saved = admin_save_config_array($config_path, $decoded, $err);
    if ($saved === null) {
        admin_json_response(false, $err !== '' ? $err : 'Не удалось сохранить конфиг.');
    }

    admin_json_response(true, 'Конфиг сайта сохранён.', ['config_json' => $saved]);
}

if ($action === 'save_collection') {
    $collection_raw = (string)($_POST['collection'] ?? '');
    $collection = $collection_raw === 'projects' ? 'cards' : $collection_raw;
    $labels = [
        'cards' => 'Проекты',
        'travels' => 'Путешествия',
        'photos' => 'Фото',
    ];

    if (!isset($labels[$collection])) {
        admin_json_response(false, 'Неизвестный раздел для сохранения.');
    }

    $decoded = admin_decode_config($config_json);
    $titles = $_POST['item_title'] ?? [];
    $descriptions = $_POST['item_description'] ?? [];
    $images_by_item = $_POST['item_images'] ?? [];
    $videos_by_item = $_POST['item_videos'] ?? [];
    $legacy_images = $_POST['item_image'] ?? [];
    $legacy_videos = $_POST['item_video'] ?? [];
    $uploads_nested = $_FILES['item_upload_images'] ?? null;
    $video_uploads_nested = $_FILES['item_upload_videos'] ?? null;
    $uploads_legacy = $_FILES['item_upload_image'] ?? null;
    $video_uploads_legacy = $_FILES['item_upload_video'] ?? null;

    if (!is_array($titles)) $titles = [];
    if (!is_array($descriptions)) $descriptions = [];
    if (!is_array($images_by_item)) $images_by_item = [];
    if (!is_array($videos_by_item)) $videos_by_item = [];
    if (!is_array($legacy_images)) $legacy_images = [];
    if (!is_array($legacy_videos)) $legacy_videos = [];

    $max_count = max(
        count($titles),
        count($descriptions),
        count($images_by_item),
        count($videos_by_item),
        count($legacy_images),
        count($legacy_videos),
        admin_nested_item_count($uploads_nested),
        admin_nested_item_count($video_uploads_nested),
        admin_file_count($uploads_legacy),
        admin_file_count($video_uploads_legacy)
    );
    $items = [];

    for ($i = 0; $i < $max_count; $i++) {
        $title = trim((string)($titles[$i] ?? ''));
        $description = trim((string)($descriptions[$i] ?? ''));
        $images = admin_clean_image_urls($images_by_item[$i] ?? []);
        $videos = admin_clean_video_urls($videos_by_item[$i] ?? []);

        if (!$images) {
            $legacy_image = trim((string)($legacy_images[$i] ?? ''));
            if ($legacy_image !== '') {
                $images[] = $legacy_image;
            }
        }
        if (!$videos) {
            $legacy_video = trim((string)($legacy_videos[$i] ?? ''));
            if ($legacy_video !== '') {
                $videos[] = $legacy_video;
            }
        }

        $upload_err = '';
        $uploaded = admin_save_uploaded_image($uploads_legacy, $i, $upload_err);
        if ($upload_err !== '') {
            admin_json_response(false, $upload_err);
        }
        if ($uploaded !== '') {
            $images[] = $uploaded;
        }
        $video_upload_err = '';
        $video_uploaded = admin_save_uploaded_video($video_uploads_legacy, $i, $video_upload_err);
        if ($video_upload_err !== '') {
            admin_json_response(false, $video_upload_err);
        }
        if ($video_uploaded !== '') {
            $videos[] = $video_uploaded;
        }

        $nested_count = admin_nested_file_count($uploads_nested, $i);
        for ($j = 0; $j < $nested_count; $j++) {
            $nested_err = '';
            $nested_uploaded = admin_save_uploaded_image_nested($uploads_nested, $i, $j, $nested_err);
            if ($nested_err !== '') {
                admin_json_response(false, $nested_err);
            }
            if ($nested_uploaded !== '') {
                $images[] = $nested_uploaded;
            }
        }
        $video_nested_count = admin_nested_file_count($video_uploads_nested, $i);
        for ($j = 0; $j < $video_nested_count; $j++) {
            $video_nested_err = '';
            $video_nested_uploaded = admin_save_uploaded_video_nested($video_uploads_nested, $i, $j, $video_nested_err);
            if ($video_nested_err !== '') {
                admin_json_response(false, $video_nested_err);
            }
            if ($video_nested_uploaded !== '') {
                $videos[] = $video_nested_uploaded;
            }
        }

        $images = array_values(array_unique($images));
        $videos = array_values(array_unique($videos));
        if ($title === '' && $description === '' && !$images && !$videos) {
            continue;
        }

        $items[] = [
            'title' => $title !== '' ? $title : 'Без названия',
            'description' => $description,
            'images' => $images,
            'image' => $images[0] ?? '',
            'videos' => $videos,
            'video' => $videos[0] ?? '',
        ];
    }

    $decoded[$collection] = $items;
    $err = '';
    $saved = admin_save_config_array($config_path, $decoded, $err);
    if ($saved === null) {
        admin_json_response(false, $err !== '' ? $err : 'Не удалось сохранить раздел.');
    }

    admin_json_response(true, 'Раздел «' . $labels[$collection] . '» сохранён.', ['config_json' => $saved]);
}

admin_json_response(false, 'Неизвестное действие.');
