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
    $images = $_POST['item_image'] ?? [];
    $uploads = $_FILES['item_upload_image'] ?? null;

    if (!is_array($titles)) $titles = [];
    if (!is_array($descriptions)) $descriptions = [];
    if (!is_array($images)) $images = [];

    $max_count = max(count($titles), count($descriptions), count($images), admin_file_count($uploads));
    $items = [];

    for ($i = 0; $i < $max_count; $i++) {
        $title = trim((string)($titles[$i] ?? ''));
        $description = trim((string)($descriptions[$i] ?? ''));
        $image = trim((string)($images[$i] ?? ''));

        $upload_err = '';
        $uploaded = admin_save_uploaded_image($uploads, $i, $upload_err);
        if ($upload_err !== '') {
            admin_json_response(false, $upload_err);
        }
        if ($uploaded !== '') {
            $image = $uploaded;
        }

        if ($title === '' && $description === '' && $image === '') {
            continue;
        }

        $items[] = [
            'title' => $title !== '' ? $title : 'Без названия',
            'description' => $description,
            'image' => $image,
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
