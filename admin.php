<?php
declare(strict_types=1);

require_once __DIR__ . '/auth/lib.php';
require_role('admin');

$csrf = csrf_token();
$err = '';
$ok = '';
$data = users_load();
$config_path = __DIR__ . '/data/site_config.json';

$config_json = "{}";
if (is_file($config_path)) {
    $raw = file_get_contents($config_path);
    if ($raw !== false) {
        $config_json = $raw;
    }
}

function admin_h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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

function admin_load_collection(array $config, string $primary, string $fallback = ''): array {
    $raw = $config[$primary] ?? [];
    if (!is_array($raw) && $fallback !== '') {
        $raw = $config[$fallback] ?? [];
    }
    if (!is_array($raw)) return [];

    $out = [];
    foreach ($raw as $item) {
        if (!is_array($item)) continue;
        $images = admin_clean_image_urls($item['images'] ?? []);
        $videos = admin_clean_video_urls($item['videos'] ?? []);
        if (!$images) {
            $legacy_image = trim((string)($item['image'] ?? ''));
            if ($legacy_image !== '') {
                $images[] = $legacy_image;
            }
        }
        if (!$videos) {
            $legacy_video = trim((string)($item['video'] ?? ''));
            if ($legacy_video !== '') {
                $videos[] = $legacy_video;
            }
        }
        $out[] = [
            'title' => (string)($item['title'] ?? ''),
            'date' => (string)($item['date'] ?? ''),
            'description' => (string)($item['description'] ?? ''),
            'images' => $images,
            'image' => $images[0] ?? '',
            'videos' => $videos,
            'video' => $videos[0] ?? '',
        ];
    }
    return $out;
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check((string)($_POST['csrf'] ?? ''))) {
        $err = 'CSRF: обнови страницу.';
    } else {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'save_site_config') {
            $incoming = trim((string)($_POST['site_config_json'] ?? ''));
            $decoded = json_decode($incoming, true);
            if (!is_array($decoded)) {
                $err = 'JSON невалидный. Проверь запятые, кавычки и структуру.';
            } else {
                $saved = admin_save_config_array($config_path, $decoded, $err);
                if ($saved !== null) {
                    $config_json = $saved;
                    $ok = 'Конфиг сайта сохранён.';
                }
            }
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
                $err = 'Неизвестный раздел для сохранения.';
            } else {
                $decoded = admin_decode_config($config_json);

                $titles = $_POST['item_title'] ?? [];
                $dates = $_POST['item_date'] ?? [];
                $descriptions = $_POST['item_description'] ?? [];
                $images_by_item = $_POST['item_images'] ?? [];
                $videos_by_item = $_POST['item_videos'] ?? [];
                $legacy_images = $_POST['item_image'] ?? [];
                $legacy_videos = $_POST['item_video'] ?? [];
                $uploads_nested = $_FILES['item_upload_images'] ?? null;
                $video_uploads_nested = $_FILES['item_upload_videos'] ?? null;
                $uploads_legacy = $_FILES['item_upload_image'] ?? null;
                $video_uploads_legacy = $_FILES['item_upload_video'] ?? null;
                $bulk_images = $_FILES['bulk_upload_images'] ?? null;
                $bulk_videos = $_FILES['bulk_upload_videos'] ?? null;

                if (!is_array($titles)) $titles = [];
                if (!is_array($dates)) $dates = [];
                if (!is_array($descriptions)) $descriptions = [];
                if (!is_array($images_by_item)) $images_by_item = [];
                if (!is_array($videos_by_item)) $videos_by_item = [];
                if (!is_array($legacy_images)) $legacy_images = [];
                if (!is_array($legacy_videos)) $legacy_videos = [];

                $max_count = max(
                    count($titles),
                    count($dates),
                    count($descriptions),
                    count($images_by_item),
                    count($videos_by_item),
                    count($legacy_images),
                    count($legacy_videos),
                    admin_nested_item_count($uploads_nested),
                    admin_nested_item_count($video_uploads_nested),
                    admin_file_count($uploads_legacy),
                    admin_file_count($video_uploads_legacy),
                    admin_file_count($bulk_images),
                    admin_file_count($bulk_videos)
                );
                $items = [];

                for ($i = 0; $i < $max_count; $i++) {
                    $title = trim((string)($titles[$i] ?? ''));
                    $date_value = trim((string)($dates[$i] ?? ''));
                    if ($date_value !== '') {
                        $parts = explode('-', $date_value);
                        if (count($parts) !== 3 || !checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
                            $err = 'Неверная дата в карточке #' . ($i + 1) . '. Используй формат YYYY-MM-DD.';
                            break;
                        }
                    }
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
                        $err = $upload_err;
                        break;
                    }
                    if ($uploaded !== '') {
                        $images[] = $uploaded;
                    }
                    $video_upload_err = '';
                    $video_uploaded = admin_save_uploaded_video($video_uploads_legacy, $i, $video_upload_err);
                    if ($video_upload_err !== '') {
                        $err = $video_upload_err;
                        break;
                    }
                    if ($video_uploaded !== '') {
                        $videos[] = $video_uploaded;
                    }

                    $nested_count = admin_nested_file_count($uploads_nested, $i);
                    for ($j = 0; $j < $nested_count; $j++) {
                        $nested_err = '';
                        $nested_uploaded = admin_save_uploaded_image_nested($uploads_nested, $i, $j, $nested_err);
                        if ($nested_err !== '') {
                            $err = $nested_err;
                            break 2;
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
                            $err = $video_nested_err;
                            break 2;
                        }
                        if ($video_nested_uploaded !== '') {
                            $videos[] = $video_nested_uploaded;
                        }
                    }

                    $images = array_values(array_unique($images));
                    $videos = array_values(array_unique($videos));
                    if ($title === '' && $description === '' && $date_value === '' && !$images && !$videos) {
                        continue;
                    }

                    $items[] = [
                        'title' => $title !== '' ? $title : 'Без названия',
                        'date' => $date_value,
                        'description' => $description,
                        'images' => $images,
                        'image' => $images[0] ?? '',
                        'videos' => $videos,
                        'video' => $videos[0] ?? '',
                    ];
                }

                if ($err === '') {
                    $bulk_image_count = admin_file_count($bulk_images);
                    for ($i = 0; $i < $bulk_image_count; $i++) {
                        $bulk_err = '';
                        $bulk_image = admin_save_uploaded_image($bulk_images, $i, $bulk_err);
                        if ($bulk_err !== '') {
                            $err = $bulk_err;
                            break;
                        }
                        if ($bulk_image === '') continue;

                        $name = (string)($bulk_images['name'][$i] ?? '');
                        $title_from_name = pathinfo($name, PATHINFO_FILENAME);
                        $title_from_name = trim((string)$title_from_name);

                        $items[] = [
                            'title' => $title_from_name !== '' ? $title_from_name : 'Без названия',
                            'date' => '',
                            'description' => '',
                            'images' => [$bulk_image],
                            'image' => $bulk_image,
                            'videos' => [],
                            'video' => '',
                        ];
                    }
                }

                if ($err === '') {
                    $bulk_video_count = admin_file_count($bulk_videos);
                    for ($i = 0; $i < $bulk_video_count; $i++) {
                        $bulk_err = '';
                        $bulk_video = admin_save_uploaded_video($bulk_videos, $i, $bulk_err);
                        if ($bulk_err !== '') {
                            $err = $bulk_err;
                            break;
                        }
                        if ($bulk_video === '') continue;

                        $name = (string)($bulk_videos['name'][$i] ?? '');
                        $title_from_name = pathinfo($name, PATHINFO_FILENAME);
                        $title_from_name = trim((string)$title_from_name);

                        $items[] = [
                            'title' => $title_from_name !== '' ? $title_from_name : 'Без названия',
                            'date' => '',
                            'description' => '',
                            'images' => [],
                            'image' => '',
                            'videos' => [$bulk_video],
                            'video' => $bulk_video,
                        ];
                    }
                }

                if ($err === '') {
                    $decoded[$collection] = $items;
                    $saved = admin_save_config_array($config_path, $decoded, $err);
                    if ($saved !== null) {
                        $config_json = $saved;
                        $ok = 'Раздел «' . $labels[$collection] . '» сохранён.';
                    }
                }
            }
        }

        if ($action === 'set_admin' || $action === 'set_user') {
            $uid = (string)($_POST['uid'] ?? '');
            $found = null;
            foreach ($data['users'] as $u) {
                if (($u['id'] ?? '') === $uid) {
                    $found = $u;
                    break;
                }
            }

            if (!$found) {
                $err = 'Пользователь не найден.';
            } else {
                $found['role'] = $action === 'set_admin' ? 'admin' : 'user';
                $found['updated_at'] = date('c');
                user_update($data, $found);
                users_save($data);
                header('Location: /admin.php');
                exit;
            }
        }
    }
}

$config_data = admin_decode_config($config_json);
$projects = admin_load_collection($config_data, 'cards', 'projects');
$travels = admin_load_collection($config_data, 'travels', 'travel');
$photos = admin_load_collection($config_data, 'photos', 'photo');

if (!$projects) $projects[] = ['title' => '', 'date' => '', 'description' => '', 'images' => [''], 'videos' => [''], 'image' => '', 'video' => ''];
if (!$travels) $travels[] = ['title' => '', 'date' => '', 'description' => '', 'images' => [''], 'videos' => [''], 'image' => '', 'video' => ''];
if (!$photos) $photos[] = ['title' => '', 'date' => '', 'description' => '', 'images' => [''], 'videos' => [''], 'image' => '', 'video' => ''];

$collections = [
    ['key' => 'cards', 'title' => 'Проекты', 'hint' => 'Раздел /projects/index.php', 'items' => $projects, 'list_id' => 'projectsList'],
    ['key' => 'travels', 'title' => 'Путешествия', 'hint' => 'Раздел /travel/index.php и блок на главной', 'items' => $travels, 'list_id' => 'travelsList'],
    ['key' => 'photos', 'title' => 'Фото', 'hint' => 'Раздел /photo/index.php и блок на главной', 'items' => $photos, 'list_id' => 'photosList'],
];
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Админка</title>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Inter:wght@400;500;700&display=swap');
    *{box-sizing:border-box}
    :root{
      --bg:#0f1015;
      --panel:#171922;
      --panel-soft:#12141b;
      --line:#2a2f3d;
      --line-soft:#252a36;
      --text:#eff2ff;
      --muted:#949db4;
      --accent:#f9c940;
      --accent-soft:#f9c94033;
      --ok:#5fd7b0;
      --err:#ff9e9e;
    }
    body{
      margin:0;
      background:
        radial-gradient(1200px 420px at 10% -10%, #1e2434 0%, transparent 60%),
        radial-gradient(900px 360px at 100% -20%, #1a2130 0%, transparent 62%),
        var(--bg);
      color:var(--text);
      font-family:Inter,system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif
    }
    .page{width:min(1200px, calc(100vw - 24px));margin:24px auto 36px;display:grid;gap:12px}
    .card{
      background:var(--panel);
      border:1px solid var(--line);
      border-radius:12px;
      padding:16px;
    }
    .subcard{
      border:1px solid var(--line-soft);
      border-radius:10px;
      padding:12px;
      background:var(--panel-soft);
      display:grid;
      gap:10px;
    }
    .title{font-family:'Space Mono',ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:20px;margin:0 0 6px}
    .subtitle{font-size:18px;margin:0}
    .muted{color:var(--muted);font-size:13px;line-height:1.55}
    .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between}
    .ok{color:var(--ok)}
    .err{color:var(--err)}
    #adminSaveStatus{
      padding:12px 14px;
      border-radius:14px;
      border:1px solid var(--line);
      background:var(--panel-soft);
      font-size:14px;
    }
    .btn, button{
      appearance:none;
      border:1px solid var(--line);
      background:#1a1e2a;
      color:var(--text);
      border-radius:11px;
      padding:9px 12px;
      font:inherit;
      cursor:pointer;
      text-decoration:none;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:6px;
      transition:.15s ease;
      transition-property:border-color,background,transform;
    }
    .btn:hover, button:hover{background:#202536;border-color:#38425b}
    .btn:active, button:active{transform:translateY(1px)}
    .btn.primary, button.primary{background:var(--accent);color:#171922;border-color:transparent;font-weight:700}
    .config-editor{
      width:100%;
      min-height:260px;
      border:1px solid var(--line);
      border-radius:12px;
      background:#0f1219;
      color:var(--text);
      padding:12px;
      font:12px/1.6 'Space Mono',ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
      resize:vertical;
      outline:none
    }
    .config-editor:focus,
    input:focus,
    textarea:focus{
      border-color:var(--accent-soft);
      box-shadow:0 0 0 3px #f9c9401f;
    }
    .collection-grid{display:grid;grid-template-columns:1fr;gap:10px;margin-top:8px}
    .admin-tabs{display:flex;gap:8px;flex-wrap:wrap}
    .admin-tab-btn{
      appearance:none;
      border:1px solid var(--line);
      background:var(--panel-soft);
      color:var(--muted);
      border-radius:10px;
      padding:8px 12px;
      font:600 13px/1.3 Inter,system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;
      cursor:pointer;
    }
    .admin-tab-btn.is-active{
      color:var(--text);
      border-color:#49506a;
      background:#1b1f2b;
    }
    .tab-pane{display:none}
    .tab-pane.is-active{display:block}
    .projects-list{display:grid;gap:8px;counter-reset:item}
    .project-item{
      border:1px solid var(--line-soft);
      border-radius:8px;
      padding:10px;
      background:#11141c;
      display:grid;
      gap:8px;
      counter-increment:item;
    }
    .project-item::before{
      content:"Карточка " counter(item);
      color:var(--muted);
      font:600 11px/1.3 'Space Mono',ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
      letter-spacing:.02em;
    }
    .project-row{display:grid;grid-template-columns:1fr 180px;gap:8px}
    .media-grid{display:grid;grid-template-columns:1fr;gap:8px}
    .media-block{
      border:1px solid #232836;
      border-radius:8px;
      padding:8px;
      background:#0f1219;
      display:grid;
      gap:8px;
    }
    .media-head{font:700 11px/1.3 'Space Mono',ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;color:var(--muted)}
    .media-details{
      border:1px dashed #2b3140;
      border-radius:8px;
      padding:6px 8px;
      background:#101420;
    }
    .media-details summary{
      cursor:pointer;
      color:var(--muted);
      font-size:12px;
      list-style:none;
    }
    .media-details summary::-webkit-details-marker{display:none}
    .media-details[open] summary{margin-bottom:8px}
    .project-item label{display:grid;gap:6px;color:var(--muted);font-size:12px}
    .project-item input,
    .project-item textarea{
      width:100%;
      border:1px solid var(--line);
      border-radius:8px;
      background:#0f1219;
      color:var(--text);
      padding:9px 10px;
      font:13px/1.5 Inter,system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;
      outline:none
    }
    .project-item input[type="file"]{padding:8px}
    .project-item textarea{min-height:88px;resize:vertical}
    .image-urls,
    .video-urls{display:grid;gap:8px}
    .image-url-row,
    .video-url-row{display:grid;grid-template-columns:1fr auto;gap:8px;align-items:center}
    .remove-image-url,
    .remove-video-url,
    .remove-project{
      border-color:var(--line);
      color:var(--text);
      background:#1a1e2a;
    }
    .remove-image-url:hover,
    .remove-video-url:hover,
    .remove-project:hover{background:#202536}
    .project-tools{display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap}
    .project-order{display:flex;gap:8px}
    .project-order button{min-width:40px}
    .bulk-inline{
      display:flex;
      gap:8px;
      flex-wrap:wrap;
      align-items:flex-end;
    }
    .bulk-inline label{
      display:grid;
      gap:6px;
      color:var(--muted);
      font-size:12px;
      min-width:220px;
      flex:1 1 240px;
    }
    .bulk-inline input[type="file"]{
      width:100%;
      border:1px solid var(--line);
      border-radius:8px;
      background:#0f1219;
      color:var(--text);
      padding:8px;
    }
    .collection-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:space-between;align-items:flex-end}
    .collection-actions-left{display:flex;gap:8px;flex-wrap:wrap}
    .collection-actions-right{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end}
    .file-note{color:var(--muted);font-size:12px}
    table{width:100%;border-collapse:collapse;margin-top:6px}
    th,td{padding:11px 10px;border-bottom:1px solid var(--line);text-align:left;font-size:14px;vertical-align:top}
    th{color:var(--muted);font-weight:500}
    .pill{display:inline-flex;padding:3px 9px;border:1px solid var(--line);border-radius:999px;background:#0f1219;color:var(--text);font-size:12px}
    .actions{display:flex;gap:8px;flex-wrap:wrap}
    @media (max-width: 900px){
      .media-grid,
      .project-row,
      .image-url-row,
      .video-url-row{
        grid-template-columns:1fr;
      }
      .bulk-inline{
        flex-direction:column;
        align-items:stretch;
      }
      .bulk-inline label{
        min-width:0;
      }
    }
  </style>
</head>
<body>
<?php @include $_SERVER['DOCUMENT_ROOT'] . '/header.php'; ?>

<div class="page">
  <section class="card">
    <div class="row">
      <div>
        <h1 class="title">Панель управления сайтом</h1>
        <div class="muted">Управление карточками разделов, JSON-конфигом и ролями пользователей.</div>
      </div>
      <a class="btn" href="/auth/logout.php">Выйти</a>
    </div>
  </section>

  <?php if ($ok): ?><div class="card" style="color:var(--ok)"><?= admin_h($ok) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="card" style="color:var(--err)"><?= admin_h($err) ?></div><?php endif; ?>
  <div class="card" id="adminSaveStatus" hidden></div>

  <section class="card">
    <div class="admin-tabs" role="tablist" aria-label="Разделы админки">
      <button type="button" class="admin-tab-btn is-active" data-tab-target="cards" aria-selected="true">Карточки</button>
      <button type="button" class="admin-tab-btn" data-tab-target="config" aria-selected="false">JSON конфиг</button>
      <button type="button" class="admin-tab-btn" data-tab-target="users" aria-selected="false">Пользователи</button>
    </div>
  </section>

  <section class="card tab-pane is-active" data-tab-pane="cards">
    <div class="row">
      <div>
        <h2 class="title">Карточки разделов</h2>
        <div class="muted">Редактирование карточек: название, описание, фото и видео. Сохранение без перезагрузки.</div>
      </div>
    </div>

    <div class="collection-grid">
      <?php foreach ($collections as $collection): ?>
        <form method="post" enctype="multipart/form-data" class="subcard js-admin-save-form">
          <input type="hidden" name="csrf" value="<?= admin_h($csrf) ?>">
          <input type="hidden" name="action" value="save_collection">
          <input type="hidden" name="collection" value="<?= admin_h((string)$collection['key']) ?>">

          <div>
            <h3 class="subtitle"><?= admin_h((string)$collection['title']) ?></h3>
            <div class="muted"><?= admin_h((string)$collection['hint']) ?></div>
          </div>

          <div class="projects-list" id="<?= admin_h((string)$collection['list_id']) ?>" data-collection-list>
            <?php foreach ((array)$collection['items'] as $item_index => $item): ?>
              <?php
                $item_images = $item['images'] ?? [];
                if (!is_array($item_images) || !$item_images) {
                    $item_images = [((string)($item['image'] ?? ''))];
                }
                if (!$item_images) {
                    $item_images = [''];
                }
                $item_videos = $item['videos'] ?? [];
                if (!is_array($item_videos) || !$item_videos) {
                    $item_videos = [((string)($item['video'] ?? ''))];
                }
                if (!$item_videos) {
                    $item_videos = [''];
                }
              ?>
              <div class="project-item" data-project-item>
                <div class="project-row">
                  <label>
                    Название
                    <input type="text" data-field="item-title" name="item_title[<?= (int)$item_index ?>]" value="<?= admin_h((string)($item['title'] ?? '')) ?>" placeholder="Название">
                  </label>
                  <label>
                    Дата
                    <input type="date" data-field="item-date" name="item_date[<?= (int)$item_index ?>]" value="<?= admin_h((string)($item['date'] ?? '')) ?>">
                  </label>
                </div>

                <label>
                  Описание
                  <textarea data-field="item-description" name="item_description[<?= (int)$item_index ?>]" placeholder="Коротко о карточке"><?= admin_h((string)($item['description'] ?? '')) ?></textarea>
                </label>

                <div class="media-grid">
                  <div class="media-block">
                    <div class="media-head">Фото</div>
                    <details class="media-details">
                      <summary>URL фото (опционально)</summary>
                      <label>
                        <div class="image-urls" data-image-urls>
                          <?php foreach ($item_images as $image_url): ?>
                            <div class="image-url-row">
                              <input type="text" data-field="item-image-url" name="item_images[<?= (int)$item_index ?>][]" value="<?= admin_h((string)$image_url) ?>" placeholder="/uploads/site/example.jpg">
                              <button type="button" class="remove-image-url" data-remove-image-url>Убрать</button>
                            </div>
                          <?php endforeach; ?>
                        </div>
                        <button type="button" data-add-image-url>Добавить URL фото</button>
                      </label>
                    </details>
                    <label>
                      Загрузка фото (несколько файлов)
                      <input type="file" data-field="item-upload-images" name="item_upload_images[<?= (int)$item_index ?>][]" accept="image/png,image/jpeg,image/webp,image/gif" multiple>
                      <span class="file-note">JPG, PNG, WEBP, GIF, до 8 МБ на файл.</span>
                    </label>
                  </div>
                  <div class="media-block">
                    <div class="media-head">Видео</div>
                    <details class="media-details">
                      <summary>URL видео (опционально)</summary>
                      <label>
                        <div class="video-urls" data-video-urls>
                          <?php foreach ($item_videos as $video_url): ?>
                            <div class="video-url-row">
                              <input type="text" data-field="item-video-url" name="item_videos[<?= (int)$item_index ?>][]" value="<?= admin_h((string)$video_url) ?>" placeholder="/uploads/site/example.mp4">
                              <button type="button" class="remove-video-url" data-remove-video-url>Убрать</button>
                            </div>
                          <?php endforeach; ?>
                        </div>
                        <button type="button" data-add-video-url>Добавить URL видео</button>
                      </label>
                    </details>
                    <label>
                      Загрузка видео (несколько файлов)
                      <input type="file" data-field="item-upload-videos" name="item_upload_videos[<?= (int)$item_index ?>][]" accept="video/mp4,video/webm,video/ogg,video/quicktime,.m4v,.mov" multiple>
                      <span class="file-note">MP4, WEBM, OGG, MOV, M4V, до 64 МБ на файл.</span>
                    </label>
                  </div>
                </div>

                <div class="project-tools">
                  <div class="project-order">
                    <button type="button" data-move-up title="Выше">↑</button>
                    <button type="button" data-move-down title="Ниже">↓</button>
                  </div>
                  <button type="button" class="remove-project" data-remove-project>Удалить</button>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <div class="collection-actions">
            <div class="collection-actions-left">
              <button type="button" data-add-item data-target="<?= admin_h((string)$collection['list_id']) ?>">Добавить карточку</button>
              <button type="button" data-sort-by-date data-target="<?= admin_h((string)$collection['list_id']) ?>">Сортировать по дате ↓</button>
            </div>
            <div class="collection-actions-right">
              <div class="bulk-inline">
                <label>
                  Массово: фото
                  <input type="file" name="bulk_upload_images[]" accept="image/png,image/jpeg,image/webp,image/gif" multiple>
                </label>
                <label>
                  Массово: видео
                  <input type="file" name="bulk_upload_videos[]" accept="video/mp4,video/webm,video/ogg,video/quicktime,.m4v,.mov" multiple>
                </label>
              </div>
              <button class="primary" type="submit">Сохранить</button>
            </div>
          </div>
          <span class="file-note">Массовая загрузка добавляет файлы как новые карточки в этот раздел.</span>
        </form>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="card tab-pane" data-tab-pane="config">
    <div class="row">
      <div>
        <h2 class="title">Конфиг сайта (JSON)</h2>
        <div class="muted">Редактируется файл <code>/data/site_config.json</code>. Используй для тонкой настройки hero, терминала и нестандартных полей.</div>
      </div>
    </div>

    <form method="post" class="js-admin-save-form" style="margin-top:14px;display:grid;gap:12px;">
      <input type="hidden" name="csrf" value="<?= admin_h($csrf) ?>">
      <input type="hidden" name="action" value="save_site_config">
      <textarea class="config-editor" name="site_config_json"><?= admin_h($config_json) ?></textarea>
      <div class="actions">
        <button class="primary" type="submit">Сохранить конфиг</button>
      </div>
    </form>
    <div class="muted" style="margin-top:8px;">Данные карточек: <code>cards</code>, <code>travels</code>, <code>photos</code>. Поля карточки: <code>title</code>, <code>description</code>, <code>date</code>, медиа-массивы <code>images</code> и <code>videos</code>.</div>
  </section>

  <section class="card tab-pane" data-tab-pane="users">
    <div class="row">
      <div>
        <h2 class="title">Пользователи</h2>
        <div class="muted">Выдача и снятие роли admin.</div>
      </div>
    </div>

    <table>
      <thead>
        <tr>
          <th>Логин</th>
          <th>Роль</th>
          <th>Действия</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($data['users'] as $u): ?>
          <tr>
            <td><?= admin_h((string)($u['username'] ?? '')) ?></td>
            <td><span class="pill"><?= admin_h((string)($u['role'] ?? '')) ?></span></td>
            <td>
              <div class="actions">
                <form method="post" style="margin:0;">
                  <input type="hidden" name="csrf" value="<?= admin_h($csrf) ?>">
                  <input type="hidden" name="uid" value="<?= admin_h((string)($u['id'] ?? '')) ?>">
                  <input type="hidden" name="action" value="set_admin">
                  <button type="submit">Сделать admin</button>
                </form>
                <form method="post" style="margin:0;">
                  <input type="hidden" name="csrf" value="<?= admin_h($csrf) ?>">
                  <input type="hidden" name="uid" value="<?= admin_h((string)($u['id'] ?? '')) ?>">
                  <input type="hidden" name="action" value="set_user">
                  <button type="submit">Сделать user</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>
</div>
<script>
(function () {
  const endpoint = '/admin_save.php';
  const statusBox = document.getElementById('adminSaveStatus');

  function setStatus(kind, message) {
    if (!statusBox) return;
    statusBox.hidden = false;
    statusBox.classList.remove('ok', 'err');
    statusBox.classList.add(kind === 'ok' ? 'ok' : 'err');
    statusBox.textContent = message;
  }

  function setFormPending(form, pending) {
    const submitButtons = form.querySelectorAll('button[type="submit"]');
    submitButtons.forEach((btn) => {
      if (!(btn instanceof HTMLButtonElement)) return;
      if (pending) {
        btn.dataset.prevText = btn.textContent || '';
        btn.disabled = true;
        btn.textContent = 'Сохранение...';
      } else {
        btn.disabled = false;
        if (btn.dataset.prevText) btn.textContent = btn.dataset.prevText;
      }
    });
  }

  function makeImageUrlRow(value) {
    const row = document.createElement('div');
    row.className = 'image-url-row';
    row.innerHTML = ''
      + '<input type="text" data-field="item-image-url" placeholder="/uploads/site/example.jpg">'
      + '<button type="button" class="remove-image-url" data-remove-image-url>Убрать</button>';
    const input = row.querySelector('input[data-field="item-image-url"]');
    if (input instanceof HTMLInputElement) {
      input.value = value || '';
    }
    return row;
  }

  function makeVideoUrlRow(value) {
    const row = document.createElement('div');
    row.className = 'video-url-row';
    row.innerHTML = ''
      + '<input type="text" data-field="item-video-url" placeholder="/uploads/site/example.mp4">'
      + '<button type="button" class="remove-video-url" data-remove-video-url>Убрать</button>';
    const input = row.querySelector('input[data-field="item-video-url"]');
    if (input instanceof HTMLInputElement) {
      input.value = value || '';
    }
    return row;
  }

  function renumberCollection(list) {
    const items = list.querySelectorAll('[data-project-item]');
    items.forEach((item, itemIndex) => {
      const title = item.querySelector('input[data-field="item-title"]');
      if (title instanceof HTMLInputElement) {
        title.name = `item_title[${itemIndex}]`;
      }

      const date = item.querySelector('input[data-field="item-date"]');
      if (date instanceof HTMLInputElement) {
        date.name = `item_date[${itemIndex}]`;
      }

      const description = item.querySelector('textarea[data-field="item-description"]');
      if (description instanceof HTMLTextAreaElement) {
        description.name = `item_description[${itemIndex}]`;
      }

      const upload = item.querySelector('input[data-field="item-upload-images"]');
      if (upload instanceof HTMLInputElement) {
        upload.name = `item_upload_images[${itemIndex}][]`;
      }
      const videoUpload = item.querySelector('input[data-field="item-upload-videos"]');
      if (videoUpload instanceof HTMLInputElement) {
        videoUpload.name = `item_upload_videos[${itemIndex}][]`;
      }

      item.querySelectorAll('input[data-field="item-image-url"]').forEach((input) => {
        if (input instanceof HTMLInputElement) {
          input.name = `item_images[${itemIndex}][]`;
        }
      });
      item.querySelectorAll('input[data-field="item-video-url"]').forEach((input) => {
        if (input instanceof HTMLInputElement) {
          input.name = `item_videos[${itemIndex}][]`;
        }
      });
    });
  }

  function renumberAllCollections(root) {
    const scope = root instanceof Element ? root : document;
    scope.querySelectorAll('[data-collection-list]').forEach((list) => renumberCollection(list));
  }

  function makeItem() {
    const item = document.createElement('div');
    item.className = 'project-item';
    item.setAttribute('data-project-item', '1');
    item.innerHTML = ''
      + '<div class="project-row">'
      + '  <label>Название<input type="text" data-field="item-title" placeholder="Название"></label>'
      + '  <label>Дата<input type="date" data-field="item-date"></label>'
      + '</div>'
      + '<label>Описание<textarea data-field="item-description" placeholder="Коротко о карточке"></textarea></label>'
      + '<div class="media-grid">'
      + '  <div class="media-block">'
      + '    <div class="media-head">Фото</div>'
      + '    <details class="media-details"><summary>URL фото (опционально)</summary>'
      + '      <label>'
      + '        <div class="image-urls" data-image-urls></div>'
      + '        <button type="button" data-add-image-url>Добавить URL фото</button>'
      + '      </label>'
      + '    </details>'
      + '    <label>Загрузка фото (несколько файлов)'
      + '      <input type="file" data-field="item-upload-images" accept="image/png,image/jpeg,image/webp,image/gif" multiple>'
      + '      <span class="file-note">JPG, PNG, WEBP, GIF, до 8 МБ на файл.</span>'
      + '    </label>'
      + '  </div>'
      + '  <div class="media-block">'
      + '    <div class="media-head">Видео</div>'
      + '    <details class="media-details"><summary>URL видео (опционально)</summary>'
      + '      <label>'
      + '        <div class="video-urls" data-video-urls></div>'
      + '        <button type="button" data-add-video-url>Добавить URL видео</button>'
      + '      </label>'
      + '    </details>'
      + '    <label>Загрузка видео (несколько файлов)'
      + '      <input type="file" data-field="item-upload-videos" accept="video/mp4,video/webm,video/ogg,video/quicktime,.m4v,.mov" multiple>'
      + '      <span class="file-note">MP4, WEBM, OGG, MOV, M4V, до 64 МБ на файл.</span>'
      + '    </label>'
      + '  </div>'
      + '</div>'
      + '<div class="project-tools">'
      + '  <div class="project-order"><button type="button" data-move-up title="Выше">↑</button><button type="button" data-move-down title="Ниже">↓</button></div>'
      + '  <button type="button" class="remove-project" data-remove-project>Удалить</button>'
      + '</div>';

    const urls = item.querySelector('[data-image-urls]');
    if (urls) {
      urls.appendChild(makeImageUrlRow(''));
    }
    const videoUrls = item.querySelector('[data-video-urls]');
    if (videoUrls) {
      videoUrls.appendChild(makeVideoUrlRow(''));
    }
    return item;
  }

  document.querySelectorAll('[data-add-item]').forEach((btn) => {
    btn.addEventListener('click', function () {
      const target = btn.getAttribute('data-target');
      if (!target) return;
      const list = document.getElementById(target);
      if (!list) return;
      list.appendChild(makeItem());
      renumberCollection(list);
    });
  });

  document.querySelectorAll('[data-sort-by-date]').forEach((btn) => {
    btn.addEventListener('click', function () {
      const target = btn.getAttribute('data-target');
      if (!target) return;
      const list = document.getElementById(target);
      if (!list) return;

      const items = Array.from(list.querySelectorAll('[data-project-item]'));
      items.sort((a, b) => {
        const aDate = a.querySelector('input[data-field="item-date"]');
        const bDate = b.querySelector('input[data-field="item-date"]');
        const av = aDate instanceof HTMLInputElement ? (aDate.value || '') : '';
        const bv = bDate instanceof HTMLInputElement ? (bDate.value || '') : '';
        if (av === bv) return 0;
        if (!av) return 1;
        if (!bv) return -1;
        return av < bv ? 1 : -1;
      });

      items.forEach((item) => list.appendChild(item));
      renumberCollection(list);
    });
  });

  document.querySelectorAll('[data-collection-list]').forEach((list) => {
    list.addEventListener('click', function (event) {
      const target = event.target;
      if (!(target instanceof HTMLElement)) return;

      if (target.hasAttribute('data-add-image-url')) {
        const item = target.closest('[data-project-item]');
        if (!item) return;
        const urls = item.querySelector('[data-image-urls]');
        if (!urls) return;
        urls.appendChild(makeImageUrlRow(''));
        renumberCollection(list);
        return;
      }

      if (target.hasAttribute('data-add-video-url')) {
        const item = target.closest('[data-project-item]');
        if (!item) return;
        const urls = item.querySelector('[data-video-urls]');
        if (!urls) return;
        urls.appendChild(makeVideoUrlRow(''));
        renumberCollection(list);
        return;
      }

      if (target.hasAttribute('data-remove-image-url')) {
        const row = target.closest('.image-url-row');
        if (!row) return;
        const item = target.closest('[data-project-item]');
        if (!item) return;
        const rows = item.querySelectorAll('.image-url-row');
        if (rows.length <= 1) {
          const input = row.querySelector('input[data-field="item-image-url"]');
          if (input instanceof HTMLInputElement) input.value = '';
          return;
        }
        row.remove();
        renumberCollection(list);
        return;
      }

      if (target.hasAttribute('data-remove-video-url')) {
        const row = target.closest('.video-url-row');
        if (!row) return;
        const item = target.closest('[data-project-item]');
        if (!item) return;
        const rows = item.querySelectorAll('.video-url-row');
        if (rows.length <= 1) {
          const input = row.querySelector('input[data-field="item-video-url"]');
          if (input instanceof HTMLInputElement) input.value = '';
          return;
        }
        row.remove();
        renumberCollection(list);
        return;
      }

      if (target.hasAttribute('data-move-up')) {
        const row = target.closest('[data-project-item]');
        if (!row || !row.previousElementSibling) return;
        list.insertBefore(row, row.previousElementSibling);
        renumberCollection(list);
        return;
      }

      if (target.hasAttribute('data-move-down')) {
        const row = target.closest('[data-project-item]');
        if (!row || !row.nextElementSibling) return;
        const next = row.nextElementSibling;
        list.insertBefore(next, row);
        renumberCollection(list);
        return;
      }

      if (!target.hasAttribute('data-remove-project')) return;
      const items = list.querySelectorAll('[data-project-item]');
      if (items.length <= 1) return;
      const row = target.closest('[data-project-item]');
      if (row) row.remove();
      renumberCollection(list);
    });
  });

  renumberAllCollections(document);

  const tabButtons = Array.from(document.querySelectorAll('[data-tab-target]'));
  const tabPanes = Array.from(document.querySelectorAll('[data-tab-pane]'));
  function openTab(tab) {
    tabButtons.forEach((btn) => {
      const active = btn.getAttribute('data-tab-target') === tab;
      btn.classList.toggle('is-active', active);
      btn.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    tabPanes.forEach((pane) => {
      const active = pane.getAttribute('data-tab-pane') === tab;
      pane.classList.toggle('is-active', active);
    });
  }
  tabButtons.forEach((btn) => {
    btn.addEventListener('click', function () {
      const tab = btn.getAttribute('data-tab-target');
      if (!tab) return;
      openTab(tab);
      if (window.history && typeof window.history.replaceState === 'function') {
        window.history.replaceState(null, '', '#admin-' + tab);
      }
    });
  });
  const hashTab = (window.location.hash || '').replace(/^#admin-/, '').trim();
  if (hashTab && tabPanes.some((pane) => pane.getAttribute('data-tab-pane') === hashTab)) {
    openTab(hashTab);
  }

  document.querySelectorAll('.js-admin-save-form').forEach((form) => {
    form.addEventListener('submit', async function (event) {
      event.preventDefault();
      renumberAllCollections(form);
      setFormPending(form, true);

      const body = new FormData(form);
      try {
        const response = await fetch(endpoint, {
          method: 'POST',
          body,
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });

        let payload = null;
        try {
          payload = await response.json();
        } catch (e) {
          payload = null;
        }

        if (!response.ok || !payload || !payload.ok) {
          const message = payload && payload.message ? payload.message : 'Сохранение не удалось.';
          setStatus('err', message);
          return;
        }

        if (payload.config_json) {
          const configEditor = document.querySelector('textarea[name="site_config_json"]');
          if (configEditor instanceof HTMLTextAreaElement) {
            configEditor.value = payload.config_json;
          }
        }

        setStatus('ok', payload.message || 'Сохранено.');
      } catch (e) {
        setStatus('err', 'Ошибка сети при сохранении.');
      } finally {
        setFormPending(form, false);
      }
    });
  });
})();
</script>
</body>
</html>
