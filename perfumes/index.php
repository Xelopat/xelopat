<?php
// /perfumes/index.php
declare(strict_types=1);

require_once $_SERVER["DOCUMENT_ROOT"] . "/auth/lib.php";

/*
  Просмотр доступен всем (в том числе неаутентифицированным).
  Добавление/редактирование/удаление доступно только админам.

  Фото теперь публичные:
    /perfumes/uploads/original/<file>
    /perfumes/uploads/preview/<preview_file>
*/

$user = null;
if (function_exists("auth_current_user")) {
    $user = auth_current_user();
}
$is_admin = $user && (($user["role"] ?? "") === "admin");

$DATA_FILE = __DIR__ . "/data.json";

$PUBLIC_UPLOAD_DIR = __DIR__ . "/uploads";
$PUBLIC_ORIG_DIR = $PUBLIC_UPLOAD_DIR . "/original";
$PUBLIC_PREVIEW_DIR = $PUBLIC_UPLOAD_DIR . "/preview";

$PUBLIC_ORIG_URL = "/perfumes/uploads/original";
$PUBLIC_PREVIEW_URL = "/perfumes/uploads/preview";

const PER_PAGE = 50;
const MAX_UPLOAD_BYTES = 6 * 1024 * 1024;
const PREVIEW_MAX_SIDE = 220; // размер превью
const PREVIEW_QUALITY = 78;   // качество webp/jpg превью

if (!is_dir($PUBLIC_ORIG_DIR)) {
    @mkdir($PUBLIC_ORIG_DIR, 0755, true);
}
if (!is_dir($PUBLIC_PREVIEW_DIR)) {
    @mkdir($PUBLIC_PREVIEW_DIR, 0755, true);
}
if (!file_exists($DATA_FILE)) {
    file_put_contents(
        $DATA_FILE,
        json_encode(["items" => []], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        LOCK_EX
    );
}

/* ---------------- helpers ---------------- */

if (!function_exists("esc")) {
    function esc(string $s): string {
        if (function_exists("h")) return h($s);
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
    }
}

function load_data(string $file): array {
    $raw = @file_get_contents($file);
    if ($raw === false || trim($raw) === "") return ["items" => []];
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data["items"]) || !is_array($data["items"])) return ["items" => []];
    return $data;
}

function save_data(string $file, array $data): void {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) $json = '{"items":{}}';
    file_put_contents($file, $json, LOCK_EX);
}

function valid_id(string $id): bool {
    return (bool)preg_match('/^[a-zA-Z0-9._-]{1,48}$/', $id);
}

function clamp_float(float $v, float $min, float $max): float {
    if ($v < $min) return $min;
    if ($v > $max) return $max;
    return $v;
}

function parse_float_or_null($v): ?float {
    $s = trim((string)$v);
    if ($s === "") return null;
    $s = str_replace(",", ".", $s);
    if (!preg_match('/^-?\d+(?:\.\d+)?$/', $s)) return null;
    return (float)$s;
}

function clamp_int(int $v, int $min, int $max): int {
    if ($v < $min) return $min;
    if ($v > $max) return $max;
    return $v;
}

function parse_int_or_null($v): ?int {
    $s = trim((string)$v);
    if ($s === "") return null;
    if (!preg_match('/^-?\d+$/', $s)) return null;
    return (int)$s;
}

function str_lower(string $s): string {
    if (function_exists("mb_strtolower")) return mb_strtolower($s, "UTF-8");
    return strtolower($s);
}

function str_contains_ci(string $hay, string $needle): bool {
    $needle = (string)$needle;
    if ($needle === "") return true;

    if (function_exists("mb_stripos")) {
        return mb_stripos($hay, $needle, 0, "UTF-8") !== false;
    }
    return stripos($hay, $needle) !== false;
}

function parse_tags(string $v): array {
    $v = trim($v);
    if ($v === "") return [];
    $parts = array_map("trim", explode(",", $v));
    $out = [];
    foreach ($parts as $p) {
        if ($p === "") continue;
        $out[] = str_lower($p);
    }
    $out = array_values(array_unique($out));
    sort($out);
    return $out;
}

function tags_to_str(array $tags): string {
    return implode(", ", $tags);
}

function safe_return_url(string $u): string {
    $u = trim($u);
    if ($u === "") return "/perfumes/index.php";
    if (strpos($u, "/perfumes/index.php") !== 0) return "/perfumes/index.php";
    return $u;
}

function build_page_url(int $page): string {
    $params = $_GET;
    $params["page"] = (string)$page;

    foreach ($params as $k => $v) {
        if ($v === null) continue;
        if (is_string($v) && trim($v) === "") unset($params[$k]);
    }

    $qs = http_build_query($params);
    return "/perfumes/index.php" . ($qs ? ("?" . $qs) : "");
}

function item_str(array $it, string $k): string {
    $v = $it[$k] ?? "";
    return is_string($v) ? $v : "";
}
function item_int(array $it, string $k): ?int {
    $v = $it[$k] ?? null;
    if ($v === null || $v === "") return null;
    if (is_int($v)) return $v;
    if (is_numeric($v)) return (int)$v;
    return null;
}
function item_float(array $it, string $k): ?float {
    $v = $it[$k] ?? null;
    if ($v === null || $v === "") return null;
    if (is_float($v) || is_int($v)) return (float)$v;
    if (is_numeric($v)) return (float)$v;
    return null;
}
function item_arr(array $it, string $k): array {
    $v = $it[$k] ?? [];
    return is_array($v) ? $v : [];
}

function add_badge(array &$badges, $v, string $label): void {
    if ($v === null || $v === "") return;

    if (is_float($v)) {
        $s = rtrim(rtrim(number_format($v, 1, ".", ""), "0"), ".");
        $badges[] = $label . ": " . $s;
        return;
    }
    $badges[] = $label . ": " . (string)$v;
}

function note_match_any(string $noteValue, array $needles): bool {
    if (!$needles) return true;
    $hay = str_lower($noteValue);
    foreach ($needles as $n) {
        $n = trim((string)$n);
        if ($n === "") continue;
        if (str_contains_ci($hay, $n)) return true;
    }
    return false;
}

function is_allowed_image_ext(string $ext): bool {
    $ext = strtolower($ext);
    return in_array($ext, ["jpg", "jpeg", "png", "webp"], true);
}

function detect_mime(string $path): string {
    $mime = "";
    if (function_exists("finfo_open")) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = (string)finfo_file($finfo, $path);
            finfo_close($finfo);
        }
    }
    return $mime;
}

function image_create_from_path(string $path, string $ext) {
    $ext = strtolower($ext);
    if ($ext === "jpg" || $ext === "jpeg") {
        return function_exists("imagecreatefromjpeg") ? @imagecreatefromjpeg($path) : false;
    }
    if ($ext === "png") {
        return function_exists("imagecreatefrompng") ? @imagecreatefrompng($path) : false;
    }
    if ($ext === "webp") {
        return function_exists("imagecreatefromwebp") ? @imagecreatefromwebp($path) : false;
    }
    return false;
}

function save_preview_image($img, string $destPath, int $quality): bool {
    $ext = strtolower(pathinfo($destPath, PATHINFO_EXTENSION));
    if ($ext === "webp" && function_exists("imagewebp")) {
        return @imagewebp($img, $destPath, $quality);
    }
    if (($ext === "jpg" || $ext === "jpeg") && function_exists("imagejpeg")) {
        return @imagejpeg($img, $destPath, $quality);
    }
    if ($ext === "png" && function_exists("imagepng")) {
        $q = (int)round(9 - ($quality / 100) * 9);
        if ($q < 0) $q = 0;
        if ($q > 9) $q = 9;
        return @imagepng($img, $destPath, $q);
    }
    return false;
}

function make_preview(string $srcPath, string $srcExt, string $destDir, string $baseNameNoExt, int $maxSide, int $quality): string {
    if (!function_exists("imagecreatetruecolor") || !function_exists("imagesx") || !function_exists("imagesy")) {
        return "";
    }

    $src = image_create_from_path($srcPath, $srcExt);
    if (!$src) return "";

    $w = imagesx($src);
    $h = imagesy($src);
    if ($w <= 0 || $h <= 0) {
        imagedestroy($src);
        return "";
    }

    $scale = 1.0;
    $max = max($w, $h);
    if ($max > $maxSide) $scale = $maxSide / $max;

    $nw = (int)max(1, floor($w * $scale));
    $nh = (int)max(1, floor($h * $scale));

    $dst = imagecreatetruecolor($nw, $nh);

    // прозрачность для png/webp
    if (in_array(strtolower($srcExt), ["png", "webp"], true)) {
        if (function_exists("imagealphablending")) imagealphablending($dst, false);
        if (function_exists("imagesavealpha")) imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $nw, $nh, $transparent);
    } else {
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $nw, $nh, $white);
    }

    if (!function_exists("imagecopyresampled")) {
        imagedestroy($src);
        imagedestroy($dst);
        return "";
    }

    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($src);

    // формат превью: webp, если возможно, иначе jpg
    $previewExt = function_exists("imagewebp") ? "webp" : "jpg";
    $destFile = $baseNameNoExt . "_p." . $previewExt;
    $destPath = rtrim($destDir, "/") . "/" . $destFile;

    $ok = save_preview_image($dst, $destPath, $quality);
    imagedestroy($dst);

    if (!$ok) {
        @unlink($destPath);
        return "";
    }
    return $destFile;
}

function delete_public_file_safe(string $dir, string $filename): void {
    $filename = trim($filename);
    if ($filename === "") return;
    $filename = basename($filename);

    if (!preg_match('/^[a-zA-Z0-9._-]{1,160}\.(jpg|jpeg|png|webp)$/i', $filename)) return;

    $path = rtrim($dir, "/") . "/" . $filename;
    if (is_file($path)) @unlink($path);
}

function handle_upload_with_preview(string $id, string $origDir, string $previewDir): array {
    if (empty($_FILES["photo"]) || !isset($_FILES["photo"]["error"])) return ["file" => "", "preview" => ""];
    if ($_FILES["photo"]["error"] !== UPLOAD_ERR_OK) return ["file" => "", "preview" => ""];

    $tmp = (string)($_FILES["photo"]["tmp_name"] ?? "");
    $size = (int)($_FILES["photo"]["size"] ?? 0);
    $name = (string)($_FILES["photo"]["name"] ?? "");

    if ($size <= 0 || $size > MAX_UPLOAD_BYTES) return ["file" => "", "preview" => ""];

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!is_allowed_image_ext($ext)) return ["file" => "", "preview" => ""];

    $mime = detect_mime($tmp);
    $allowedMime = ["image/jpeg", "image/png", "image/webp"];
    if ($mime !== "" && !in_array($mime, $allowedMime, true)) return ["file" => "", "preview" => ""];

    $safeId = preg_replace('/[^a-zA-Z0-9._-]/', "_", $id);
    $base = $safeId . "_" . date("Ymd_His") . "_" . bin2hex(random_bytes(3));
    $origFile = $base . "." . $ext;

    $origPath = rtrim($origDir, "/") . "/" . $origFile;
    if (!move_uploaded_file($tmp, $origPath)) return ["file" => "", "preview" => ""];

    $previewFile = make_preview($origPath, $ext, $previewDir, $base, PREVIEW_MAX_SIDE, PREVIEW_QUALITY);

    return ["file" => $origFile, "preview" => $previewFile];
}

/* ---------------- load ---------------- */

$data = load_data($DATA_FILE);
$items = $data["items"];
if (!is_array($items)) $items = [];

$csrf = $is_admin && function_exists("csrf_token") ? csrf_token() : "";
$errors = [];

/* ---------------- bounds for price slider ---------------- */

$price_bound_min = 0;
$price_bound_max = 999999;

$prices = [];
foreach ($items as $it) {
    if (!is_array($it)) continue;
    $p = item_int($it, "price");
    if ($p === null) continue;
    $prices[] = $p;
}
if ($prices) {
    $price_bound_min = min($prices);
    $price_bound_max = max($prices);
    if ($price_bound_min < 0) $price_bound_min = 0;
    if ($price_bound_max < $price_bound_min) $price_bound_max = $price_bound_min;
    if ($price_bound_max === $price_bound_min) $price_bound_max = $price_bound_min + 1;
}

/* ---------------- filters (GET) ---------------- */

$q = trim((string)($_GET["q"] ?? ""));

$page = parse_int_or_null($_GET["page"] ?? "") ?? 1;
if ($page < 1) $page = 1;

$f_tags = parse_tags((string)($_GET["tags"] ?? ""));

$f_notes_top_list = parse_tags((string)($_GET["notes_top"] ?? ""));
$f_notes_middle_list = parse_tags((string)($_GET["notes_middle"] ?? ""));
$f_notes_base_list = parse_tags((string)($_GET["notes_base"] ?? ""));

$has_rating_params = array_key_exists("rating_min", $_GET) || array_key_exists("rating_max", $_GET) || array_key_exists("rating", $_GET);
$has_price_params  = array_key_exists("price_min", $_GET)  || array_key_exists("price_max", $_GET);

$f_rating_min = null;
$f_rating_max = null;

if ($has_rating_params) {
    $raw_min = array_key_exists("rating_min", $_GET) ? ($_GET["rating_min"] ?? "") : ($_GET["rating"] ?? "");
    $raw_max = $_GET["rating_max"] ?? "";

    $f_rating_min = parse_float_or_null($raw_min);
    $f_rating_max = parse_float_or_null($raw_max);

    if ($f_rating_min !== null) $f_rating_min = clamp_float($f_rating_min, 1.0, 10.0);
    if ($f_rating_max !== null) $f_rating_max = clamp_float($f_rating_max, 1.0, 10.0);

    if ($f_rating_min !== null && $f_rating_max !== null && $f_rating_min > $f_rating_max) {
        $tmp = $f_rating_min;
        $f_rating_min = $f_rating_max;
        $f_rating_max = $tmp;
    }
}

$f_price_min = null;
$f_price_max = null;

if ($has_price_params) {
    $f_price_min = parse_int_or_null($_GET["price_min"] ?? "");
    $f_price_max = parse_int_or_null($_GET["price_max"] ?? "");

    if ($f_price_min !== null) $f_price_min = clamp_int($f_price_min, $price_bound_min, $price_bound_max);
    if ($f_price_max !== null) $f_price_max = clamp_int($f_price_max, $price_bound_min, $price_bound_max);

    if ($f_price_min !== null && $f_price_max !== null && $f_price_min > $f_price_max) {
        $tmp = $f_price_min;
        $f_price_min = $f_price_max;
        $f_price_max = $tmp;
    }
}

/* ---------------- POST (admin only) ---------------- */

$current_url = "/perfumes/index.php" . (($_SERVER["QUERY_STRING"] ?? "") ? ("?" . $_SERVER["QUERY_STRING"]) : "");
$return_url = safe_return_url((string)($_POST["return"] ?? $current_url));

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!$is_admin) {
        http_response_code(403);
        echo "403 Forbidden";
        exit;
    }

    if (!function_exists("csrf_check") || !csrf_check((string)($_POST["csrf"] ?? ""))) {
        $errors[] = "CSRF: обнови страницу и попробуй снова.";
    } else {
        $action = (string)($_POST["action"] ?? "");

        $id = trim((string)($_POST["id"] ?? ""));
        $name = trim((string)($_POST["name"] ?? ""));
        $brand = trim((string)($_POST["brand"] ?? ""));
        $description = trim((string)($_POST["description"] ?? ""));
        $notes = trim((string)($_POST["notes"] ?? ""));

        $notes_top = trim((string)($_POST["notes_top"] ?? ""));
        $notes_middle = trim((string)($_POST["notes_middle"] ?? ""));
        $notes_base = trim((string)($_POST["notes_base"] ?? ""));

        $tags_arr = parse_tags((string)($_POST["tags"] ?? ""));

        $rating = parse_float_or_null($_POST["rating"] ?? "");
        $price = parse_int_or_null($_POST["price"] ?? "");

        $rating = $rating === null ? null : clamp_float($rating, 1, 10);
        $price = $price === null ? null : clamp_int($price, 0, 999999);

        if ($action === "add") {
            if ($id === "" || !valid_id($id)) $errors[] = "ID обязателен. Разрешены латиница, цифры, точка, подчёркивание, дефис (до 48).";
            if (isset($items[$id])) $errors[] = "Такой ID уже существует.";

            if (!$errors) {
                $up = handle_upload_with_preview($id, $PUBLIC_ORIG_DIR, $PUBLIC_PREVIEW_DIR);
                $now = date("c");

                $items[$id] = [
                    "id" => $id,
                    "name" => $name,
                    "brand" => $brand,
                    "description" => $description,
                    "notes" => $notes,

                    "notes_top" => $notes_top,
                    "notes_middle" => $notes_middle,
                    "notes_base" => $notes_base,

                    "tags" => $tags_arr,

                    "photo_file" => $up["file"] ?? "",
                    "photo_preview" => $up["preview"] ?? "",

                    "rating" => $rating,
                    "price" => $price,
                    "created_at" => $now,
                    "updated_at" => $now,
                ];

                $data["items"] = $items;
                save_data($DATA_FILE, $data);

                header("Location: " . $return_url);
                exit;
            }
        }

        if ($action === "update") {
            if ($id === "" || !isset($items[$id])) $errors[] = "Запись не найдена.";

            if (!$errors) {
                $existing = (string)($items[$id]["photo_file"] ?? "");
                $existing_preview = (string)($items[$id]["photo_preview"] ?? "");

                $remove_photo = ((string)($_POST["remove_photo"] ?? "") === "1");
                $new_up = handle_upload_with_preview($id, $PUBLIC_ORIG_DIR, $PUBLIC_PREVIEW_DIR);

                $photo_final = $existing;
                $preview_final = $existing_preview;

                if ($remove_photo) {
                    delete_public_file_safe($PUBLIC_ORIG_DIR, $existing);
                    delete_public_file_safe($PUBLIC_PREVIEW_DIR, $existing_preview);
                    $photo_final = "";
                    $preview_final = "";
                }

                if (($new_up["file"] ?? "") !== "") {
                    // заменяем, старые удаляем
                    delete_public_file_safe($PUBLIC_ORIG_DIR, $existing);
                    delete_public_file_safe($PUBLIC_PREVIEW_DIR, $existing_preview);

                    $photo_final = (string)$new_up["file"];
                    $preview_final = (string)($new_up["preview"] ?? "");
                }

                $items[$id]["name"] = $name;
                $items[$id]["brand"] = $brand;
                $items[$id]["description"] = $description;
                $items[$id]["notes"] = $notes;

                $items[$id]["notes_top"] = $notes_top;
                $items[$id]["notes_middle"] = $notes_middle;
                $items[$id]["notes_base"] = $notes_base;

                $items[$id]["tags"] = $tags_arr;

                $items[$id]["photo_file"] = $photo_final;
                $items[$id]["photo_preview"] = $preview_final;

                $items[$id]["rating"] = $rating;
                $items[$id]["price"] = $price;
                $items[$id]["updated_at"] = date("c");

                unset(
                    $items[$id]["longevity"],
                    $items[$id]["sillage"],
                    $items[$id]["freshness"],
                    $items[$id]["sweetness"],
                    $items[$id]["versatility"],
                    $items[$id]["compliments"]
                );

                $data["items"] = $items;
                save_data($DATA_FILE, $data);

                header("Location: " . $return_url);
                exit;
            }
        }

        if ($action === "delete") {
            if ($id === "" || !isset($items[$id])) $errors[] = "Запись не найдена.";

            if (!$errors) {
                $existing = (string)($items[$id]["photo_file"] ?? "");
                $existing_preview = (string)($items[$id]["photo_preview"] ?? "");

                delete_public_file_safe($PUBLIC_ORIG_DIR, $existing);
                delete_public_file_safe($PUBLIC_PREVIEW_DIR, $existing_preview);

                unset($items[$id]);

                $data["items"] = $items;
                save_data($DATA_FILE, $data);

                header("Location: " . $return_url);
                exit;
            }
        }
    }
}

/* ---------------- list build ---------------- */

$sorted_ids = array_keys($items);
natcasesort($sorted_ids);

$filtered_ids = [];
foreach ($sorted_ids as $id) {
    $it = $items[$id];
    if (!is_array($it)) continue;

    if ($q !== "") {
        $tagsStr = implode(" ", item_arr($it, "tags"));
        $hay = (string)$id . " " .
            item_str($it, "name") . " " .
            item_str($it, "brand") . " " .
            item_str($it, "description") . " " .
            item_str($it, "notes") . " " .
            item_str($it, "notes_top") . " " .
            item_str($it, "notes_middle") . " " .
            item_str($it, "notes_base") . " " .
            $tagsStr;

        if (!str_contains_ci($hay, $q)) continue;
    }

    if ($has_rating_params && ($f_rating_min !== null || $f_rating_max !== null)) {
        $v = item_float($it, "rating");
        if ($v === null) continue;
        if ($f_rating_min !== null && $v < $f_rating_min) continue;
        if ($f_rating_max !== null && $v > $f_rating_max) continue;
    }

    if ($has_price_params && ($f_price_min !== null || $f_price_max !== null)) {
        $v = item_int($it, "price");
        if ($v === null) continue;
        if ($f_price_min !== null && $v < $f_price_min) continue;
        if ($f_price_max !== null && $v > $f_price_max) continue;
    }

    if ($f_tags) {
        $arr = item_arr($it, "tags");
        $arr = array_map(fn($x) => str_lower((string)$x), $arr);
        if (!array_intersect($f_tags, $arr)) continue;
    }

    if ($f_notes_top_list) {
        if (!note_match_any(item_str($it, "notes_top"), $f_notes_top_list)) continue;
    }
    if ($f_notes_middle_list) {
        if (!note_match_any(item_str($it, "notes_middle"), $f_notes_middle_list)) continue;
    }
    if ($f_notes_base_list) {
        if (!note_match_any(item_str($it, "notes_base"), $f_notes_base_list)) continue;
    }

    $filtered_ids[] = $id;
}

$total_count = count($items);
$match_count = count($filtered_ids);

$total_pages = ($match_count > 0) ? (int)ceil($match_count / PER_PAGE) : 1;
if ($page > $total_pages) $page = $total_pages;
if ($page < 1) $page = 1;

$start = ($page - 1) * PER_PAGE;
$paged_ids = array_slice($filtered_ids, $start, PER_PAGE);

/* items to JS */
$items_json = json_encode(
    $items,
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
if ($items_json === false) $items_json = "{}";

/* values in filter inputs */
$rating_min_raw = (string)($_GET["rating_min"] ?? (($_GET["rating"] ?? "")));
$rating_max_raw = (string)($_GET["rating_max"] ?? "");
$price_min_raw  = (string)($_GET["price_min"] ?? "");
$price_max_raw  = (string)($_GET["price_max"] ?? "");

$notes_top_raw = (string)($_GET["notes_top"] ?? "");
$notes_middle_raw = (string)($_GET["notes_middle"] ?? "");
$notes_base_raw = (string)($_GET["notes_base"] ?? "");

$has_any_filters =
    $q !== "" ||
    !empty($f_tags) ||
    !empty($f_notes_top_list) ||
    !empty($f_notes_middle_list) ||
    !empty($f_notes_base_list) ||
    ($has_rating_params && ($f_rating_min !== null || $f_rating_max !== null)) ||
    ($has_price_params && ($f_price_min !== null || $f_price_max !== null));

?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Духи</title>
  <style>
    *,*::before,*::after{box-sizing:border-box}
    :root{
      --bg:#151518;
      --panel:#1e1e25;
      --panel-2:#191920;
      --text:#efeff1;
      --muted:#868899;
      --line:#333340;
      --accent:#f9c940;
      --green:#61d1ad;
      --danger:#ff8f8f;
    }
    body{margin:0;font-family:Inter,system-ui,-apple-system,"Segoe UI",Roboto,Arial;background:var(--bg);color:var(--text)}

    .page{max-width:1200px;margin:0 auto;padding:18px}
    .top{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:14px}
    h1{margin:0;font-size:22px}

    .btn{
      border:1px solid var(--line);background:var(--panel);border-radius:12px;padding:8px 10px;cursor:pointer;
      font:inherit;text-decoration:none;color:inherit;display:inline-flex;align-items:center;gap:8px;
    }
    .btn:hover{background:#252532}
    .btn.primary{border-color:rgba(249,201,64,0.35);background:rgba(249,201,64,0.12);color:var(--accent)}
    .btn.danger{border-color:rgba(255,143,143,0.45);background:rgba(255,143,143,0.1);color:#ffb9b9}

    .pill{font-size:12px;color:var(--muted);border:1px solid var(--line);border-radius:999px;padding:4px 8px;background:var(--panel)}
    .pill.warn{color:#ffd17d;border-color:rgba(249,201,64,0.4);background:rgba(249,201,64,0.12)}

    .card{
      background:var(--panel);border:1px solid var(--line);border-radius:14px;
      box-shadow:0 18px 40px rgba(0,0,0,0.24);padding:12px;
    }
    .err{background:rgba(255,143,143,0.1);border:1px solid rgba(255,143,143,0.45);color:#ffb9b9;padding:10px 12px;border-radius:12px;margin-top:12px}

    .layout{display:grid;grid-template-columns:320px 1fr;gap:14px;align-items:start;margin-top:12px}
    @media (max-width: 980px){ .layout{grid-template-columns:1fr} }

    label{display:block;font-size:12px;color:#b7b8c4;margin-top:10px}
    input[type="text"], input[type="number"], textarea{
      width:100%;margin-top:6px;border:1px solid var(--line);border-radius:12px;padding:9px;font:inherit;outline:none;background:var(--panel-2);color:var(--text)
    }
    input[type="text"]::placeholder, input[type="number"]::placeholder, textarea::placeholder{color:#8f91a3}
    input[type="text"]:focus, input[type="number"]:focus, textarea:focus{border-color:rgba(97,209,173,0.65);box-shadow:0 0 0 3px rgba(97,209,173,0.14)}
    textarea{min-height:110px;resize:vertical}
    input[type="file"]{margin-top:8px}

    .filters-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;align-items:end}
    .filters-grid .full{grid-column:1/-1}
    .filters-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:12px}

    .muted{color:var(--muted);font-size:13px}

    .list{display:flex;flex-direction:column;gap:10px}
    .item{
      display:grid;grid-template-columns:72px 1fr auto;gap:10px;align-items:center;
      padding:10px;border:1px solid var(--line);border-radius:14px;background:var(--panel-2);
      cursor:pointer;
    }
    .item:hover{background:#252532}
    @media (max-width: 880px){
      .item{grid-template-columns:72px 1fr}
      .item .actions{grid-column:1/-1;justify-self:start}
    }

    .thumb{
      width:72px;height:72px;border-radius:12px;border:1px solid var(--line);background:#151518;
      overflow:hidden;display:flex;align-items:center;justify-content:center
    }
    .thumb img{width:100%;height:100%;object-fit:cover;display:block}

    .title{font-weight:700;margin:0;font-size:14px}
    .sub{margin:4px 0 0 0;color:var(--muted);font-size:12px}
    .desc{margin:6px 0 0 0;color:#c5c7d8;font-size:13px;line-height:1.35;white-space:pre-wrap}

    .chips{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}
    .chip{
      font-size:12px;color:#c5c7d8;border:1px solid var(--line);border-radius:999px;padding:4px 8px;background:#252532;
      display:inline-flex;gap:8px;align-items:center;
    }

    /* range (two-handles) */
    .rangebox{margin-top:6px;border:1px solid var(--line);border-radius:12px;padding:10px;background:var(--panel-2)}
    .range-track{position:relative;height:30px}
    .range-track::before{
      content:"";position:absolute;left:0;right:0;top:50%;
      transform:translateY(-50%);
      height:6px;border-radius:999px;background:#2d2d38;
    }
    .range-fill{
      position:absolute;top:50%;transform:translateY(-50%);
      height:6px;border-radius:999px;background:rgba(97,209,173,0.45);
      left:var(--a, 0%);right:calc(100% - var(--b, 100%));
    }
    .range-track input[type="range"]{
      position:absolute;left:0;top:0;width:100%;
      height:30px;margin:0;
      background:transparent;
      pointer-events:none;
      -webkit-appearance:none;
      appearance:none;
    }
    .range-track input[type="range"]::-webkit-slider-thumb{
      -webkit-appearance:none;appearance:none;
      width:16px;height:16px;border-radius:50%;
      background:#efeff1;border:1px solid #66677a;
      box-shadow:0 2px 10px rgba(0,0,0,0.26);
      pointer-events:auto;
      cursor:pointer;
    }
    .range-track input[type="range"]::-moz-range-thumb{
      width:16px;height:16px;border-radius:50%;
      background:#efeff1;border:1px solid #66677a;
      box-shadow:0 2px 10px rgba(0,0,0,0.26);
      pointer-events:auto;
      cursor:pointer;
    }
    .range-io{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:8px}
    .range-io .io{display:flex;gap:8px;align-items:center}
    .range-io .io span{min-width:18px;color:var(--muted);font-size:12px}
    .range-io input[type="number"]{margin-top:0}

    /* modal */
    .modal{position:fixed;inset:0;display:none;z-index:3000}
    .modal.open{display:block}
    .modal-backdrop{position:absolute;inset:0;background:rgba(2,6,23,0.45)}
    .modal-box{
      position:relative;
      width:min(820px, calc(100vw - 24px));
      margin:70px auto;
      background:var(--panel);
      border:1px solid var(--line);
      border-radius:14px;
      box-shadow:0 18px 40px rgba(0,0,0,0.3);
      padding:14px;

      max-height: calc(100vh - 140px);
      overflow-y: auto;
      -webkit-overflow-scrolling: touch;
    }
    .modal-close{position:absolute;top:10px;right:10px;border:0;background:transparent;color:var(--muted);cursor:pointer;font-size:18px;line-height:1}
    .modal-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding-right:28px}
    .modal-title{font-weight:800}
    .modal-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}

    .two{display:grid;grid-template-columns:280px 1fr;gap:12px;align-items:start;margin-top:10px}
    @media (max-width: 820px){ .two{grid-template-columns:1fr} }

    .bigpic{border:1px solid var(--line);border-radius:14px;overflow:hidden;background:#151518}
    .bigpic img{width:100%;height:280px;object-fit:cover;display:block}
    .bigpic .ph{height:280px;display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:13px}

    .section-title{margin:12px 0 6px 0;font-weight:800}
    .hr{height:1px;background:var(--line);margin:12px 0}

    .modal-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px}
    .modal-grid .full{grid-column:1/-1}
    @media (max-width: 760px){ .modal-grid{grid-template-columns:1fr} }

    .row{display:flex;gap:10px;align-items:center;margin-top:12px;flex-wrap:wrap}
    .checkline{display:flex;gap:8px;align-items:center;margin-top:10px;color:#b7b8c4;font-size:13px}

    /* paste box */
    .pastebox{
      margin-top:10px;
      border:1px dashed #505062;
      border-radius:12px;
      padding:10px;
      background:#1a1a21;
      outline:none;
      cursor:pointer;
    }
    .pastebox:focus{border-color:rgba(97,209,173,0.7); box-shadow:0 0 0 3px rgba(97,209,173,0.16);}
    .pastebox__img{
      margin-top:10px;
      width:100%;
      max-height:260px;
      object-fit:cover;
      border-radius:12px;
      border:1px solid var(--line);
      display:block;
    }

    .pager{display:flex;gap:10px;align-items:center;justify-content:flex-start;margin-top:12px;flex-wrap:wrap}
    .pager .sp{color:var(--muted);font-size:13px}

    /* mobile filters collapsible */
    .filters-details{margin:0}
    .filters-summary{
      list-style:none;
      cursor:pointer;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:10px;
      user-select:none;
      font-weight:800;
      color:var(--text);
    }
    .filters-summary::-webkit-details-marker{display:none}
    .filters-summary .arrow{transition:transform .15s ease}
    details[open] .filters-summary .arrow{transform:rotate(180deg)}
    @media (min-width: 981px){
      .filters-summary{display:none}
      .filters-details{padding-top:0}
    }
  </style>
</head>
<body>

<?php @include $_SERVER["DOCUMENT_ROOT"] . "/header.php"; ?>

<div class="page">
  <div class="top">
    <div><h1>Духи</h1></div>

    <div class="row" style="margin:0;">
      <span class="pill"><?= esc((string)$total_count) ?> всего</span>
      <span class="pill"><?= esc((string)$match_count) ?> найдено</span>
      <span class="pill"><?= esc((string)$page) ?>/<?= esc((string)$total_pages) ?> стр</span>
      <?php if ($total_count > 0 && $match_count === 0 && $has_any_filters): ?>
        <span class="pill warn">Фильтры скрыли все записи</span>
        <a class="btn" href="/perfumes/index.php">Сбросить</a>
      <?php endif; ?>

      <?php if ($is_admin): ?>
        <button class="btn primary" type="button" id="openAddBtn">Добавить</button>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($errors): ?>
    <div class="err">
      <?php foreach ($errors as $e): ?>
        <div><?= esc((string)$e) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="layout">
    <div class="card">
      <details class="filters-details" id="filtersDetails" open>
        <summary class="filters-summary">
          <span>Фильтры</span>
          <span class="arrow">▼</span>
        </summary>

        <form method="get" style="margin-top:10px;" id="filtersForm">
          <div class="filters-grid">
            <div class="full">
              <label>Поиск</label>
              <input type="text" name="q" value="<?= esc($q) ?>" placeholder="id, название, бренд, описание, заметки, ноты, теги">
            </div>

            <div class="full">
              <label>Оценка (диапазон)</label>
              <div class="rangebox"
                   data-min="1"
                   data-max="10"
                   data-step="0.1"
                   data-has="<?= $has_rating_params ? "1" : "0" ?>">
                <div class="range-track">
                  <div class="range-fill"></div>
                  <input type="range" class="r-min" min="1" max="10" step="0.1" value="1">
                  <input type="range" class="r-max" min="1" max="10" step="0.1" value="10">
                </div>
                <div class="range-io">
                  <div class="io">
                    <span>от</span>
                    <input type="number" name="rating_min" min="1" max="10" step="0.1" value="<?= esc($rating_min_raw) ?>" placeholder="1">
                  </div>
                  <div class="io">
                    <span>до</span>
                    <input type="number" name="rating_max" min="1" max="10" step="0.1" value="<?= esc($rating_max_raw) ?>" placeholder="10">
                  </div>
                </div>
              </div>
            </div>

            <div class="full">
              <label>Цена (диапазон)</label>
              <div class="rangebox"
                   data-min="<?= esc((string)$price_bound_min) ?>"
                   data-max="<?= esc((string)$price_bound_max) ?>"
                   data-step="1"
                   data-has="<?= $has_price_params ? "1" : "0" ?>">
                <div class="range-track">
                  <div class="range-fill"></div>
                  <input type="range" class="r-min" min="<?= esc((string)$price_bound_min) ?>" max="<?= esc((string)$price_bound_max) ?>" step="1" value="<?= esc((string)$price_bound_min) ?>">
                  <input type="range" class="r-max" min="<?= esc((string)$price_bound_min) ?>" max="<?= esc((string)$price_bound_max) ?>" step="1" value="<?= esc((string)$price_bound_max) ?>">
                </div>
                <div class="range-io">
                  <div class="io">
                    <span>от</span>
                    <input type="number" name="price_min" min="<?= esc((string)$price_bound_min) ?>" max="<?= esc((string)$price_bound_max) ?>" step="1" value="<?= esc($price_min_raw) ?>" placeholder="<?= esc((string)$price_bound_min) ?>">
                  </div>
                  <div class="io">
                    <span>до</span>
                    <input type="number" name="price_max" min="<?= esc((string)$price_bound_min) ?>" max="<?= esc((string)$price_bound_max) ?>" step="1" value="<?= esc($price_max_raw) ?>" placeholder="<?= esc((string)$price_bound_max) ?>">
                  </div>
                </div>
              </div>
            </div>

            <div class="full">
              <label>Теги (через запятую)</label>
              <input type="text" name="tags" value="<?= esc(tags_to_str($f_tags)) ?>" placeholder="например: свежий, древесный, цитрус">
            </div>

            <div class="full">
              <label>Верхние ноты (через запятую)</label>
              <input type="text" name="notes_top" value="<?= esc($notes_top_raw) ?>" placeholder="например: бергамот, лимон">
            </div>

            <div class="full">
              <label>Средние ноты (через запятую)</label>
              <input type="text" name="notes_middle" value="<?= esc($notes_middle_raw) ?>" placeholder="например: лаванда, жасмин">
            </div>

            <div class="full">
              <label>Базовые ноты (через запятую)</label>
              <input type="text" name="notes_base" value="<?= esc($notes_base_raw) ?>" placeholder="например: амбра, кедр, мускус">
            </div>
          </div>

          <div class="filters-actions">
            <button class="btn primary" type="submit">Применить</button>
            <a class="btn" href="/perfumes/index.php">Сбросить</a>
          </div>
        </form>
      </details>
    </div>

    <div class="card">
      <div style="font-weight:800;">Список</div>

      <div class="list" style="margin-top:12px;">
        <?php if (!$paged_ids): ?>
          <?php if ($total_count > 0 && $has_any_filters): ?>
            <div class="muted">Ничего не найдено по текущим фильтрам. Нажми «Сбросить».</div>
          <?php else: ?>
            <div class="muted">Пока нет записей.</div>
          <?php endif; ?>
        <?php else: ?>
          <?php foreach ($paged_ids as $id): ?>
            <?php $it = $items[$id]; ?>
            <?php
              $name = item_str($it, "name");
              $brand = item_str($it, "brand");
              $desc = item_str($it, "description");

              $photo_file = item_str($it, "photo_file");
              $photo_preview = item_str($it, "photo_preview");

              $tags = item_arr($it, "tags");

              $badges = [];
              add_badge($badges, item_float($it, "rating"), "Оценка");
              add_badge($badges, item_int($it, "price"), "₽");

              $updated = item_str($it, "updated_at");

              $thumb_url = "";
              if ($photo_preview !== "") {
                  $thumb_url = $PUBLIC_PREVIEW_URL . "/" . rawurlencode($photo_preview) . ($updated ? ("?v=" . rawurlencode($updated)) : "");
              } elseif ($photo_file !== "") {
                  $thumb_url = $PUBLIC_ORIG_URL . "/" . rawurlencode($photo_file) . ($updated ? ("?v=" . rawurlencode($updated)) : "");
              }

              $full_url = "";
              if ($photo_file !== "") {
                  $full_url = $PUBLIC_ORIG_URL . "/" . rawurlencode($photo_file) . ($updated ? ("?v=" . rawurlencode($updated)) : "");
              }
            ?>
            <div class="item js-open" data-id="<?= esc((string)$id) ?>" data-full-url="<?= esc($full_url) ?>">
              <div class="thumb">
                <?php if ($thumb_url !== ""): ?>
                  <img src="<?= esc($thumb_url) ?>" alt="">
                <?php else: ?>
                  <span class="muted">нет</span>
                <?php endif; ?>
              </div>

              <div>
                <p class="title">
                  <?= $name !== "" ? esc($name) : esc((string)$id) ?>
                  <span class="pill" style="margin-left:8px;"><?= esc((string)$id) ?></span>
                </p>

                <?php if ($brand !== ""): ?>
                  <div class="sub"><?= esc($brand) ?></div>
                <?php endif; ?>

                <?php if ($badges): ?>
                  <div class="chips">
                    <?php foreach ($badges as $b): ?>
                      <span class="chip"><?= esc($b) ?></span>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>

                <?php if ($tags): ?>
                  <div class="chips">
                    <?php foreach ($tags as $t): ?>
                      <span class="chip"><?= esc((string)$t) ?></span>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>

                <?php if ($desc !== ""): ?>
                  <p class="desc"><?= esc($desc) ?></p>
                <?php endif; ?>
              </div>

              <div class="actions" style="justify-self:end;">
                <?php if ($is_admin): ?>
                  <button class="btn js-edit" type="button" data-id="<?= esc((string)$id) ?>">Редактировать</button>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <?php if ($match_count > 0 && $total_pages > 1): ?>
        <div class="pager">
          <?php if ($page > 1): ?>
            <a class="btn" href="<?= esc(build_page_url($page - 1)) ?>">Назад</a>
          <?php endif; ?>

          <span class="sp">Страница <?= esc((string)$page) ?> из <?= esc((string)$total_pages) ?></span>

          <?php if ($page < $total_pages): ?>
            <a class="btn" href="<?= esc(build_page_url($page + 1)) ?>">Вперёд</a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
  window.PERFUMES_DATA = <?= $items_json ?>;
  window.PERFUMES_IS_ADMIN = <?= $is_admin ? "true" : "false" ?>;
</script>

<!-- VIEW MODAL -->
<div class="modal" id="viewModal" aria-hidden="true">
  <div class="modal-backdrop" data-close="1"></div>
  <div class="modal-box" role="dialog" aria-label="Просмотр">
    <button class="modal-close" type="button" data-close="1">×</button>

    <div class="modal-head">
      <div class="modal-title">Просмотр</div>
      <div class="modal-actions">
        <span class="pill" id="vIdPill"></span>
        <button class="btn" type="button" id="vEditBtn" style="display:none;">Редактировать</button>
      </div>
    </div>

    <div class="two">
      <div class="bigpic" id="vPic">
        <div class="ph">нет фото</div>
      </div>

      <div>
        <div style="font-weight:800;font-size:18px;" id="vName"></div>
        <div class="muted" style="margin-top:4px;" id="vBrand"></div>

        <div class="chips" id="vBadges" style="margin-top:10px;"></div>
        <div class="chips" id="vTags" style="margin-top:10px;"></div>

        <div class="hr"></div>

        <div class="section-title">Описание</div>
        <div class="desc" id="vDesc"></div>

        <div class="section-title">Заметки</div>
        <div class="desc" id="vNotes"></div>

        <div id="vNotesTopWrap">
          <div class="section-title">Верхние ноты</div>
          <div class="desc" id="vNotesTop"></div>
        </div>

        <div id="vNotesMiddleWrap">
          <div class="section-title">Средние ноты</div>
          <div class="desc" id="vNotesMiddle"></div>
        </div>

        <div id="vNotesBaseWrap">
          <div class="section-title">Базовые ноты</div>
          <div class="desc" id="vNotesBase"></div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if ($is_admin): ?>
<!-- FORM MODAL (ADD/EDIT) -->
<div class="modal" id="formModal" aria-hidden="true">
  <div class="modal-backdrop" data-close="1"></div>
  <div class="modal-box" role="dialog" aria-label="Форма">
    <button class="modal-close" type="button" data-close="1">×</button>

    <div class="modal-head">
      <div class="modal-title" id="fTitle">Добавить</div>
      <div class="modal-actions">
        <span class="pill" id="fIdPill" style="display:none;"></span>
      </div>
    </div>

    <form method="post" enctype="multipart/form-data" style="margin-top:10px;" id="perfForm">
      <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
      <input type="hidden" name="action" value="add" id="fAction">
      <input type="hidden" name="return" value="<?= esc($current_url) ?>">
      <input type="hidden" name="id" value="" id="fIdHidden">

      <div class="modal-grid">
        <div class="full" id="fIdRow">
          <label>ID (сам задаёшь)</label>
          <input type="text" value="" id="fIdInput" placeholder="например: dior_sauvage_2025">
        </div>

        <div>
          <label>Название</label>
          <input type="text" name="name" value="" id="fName">
        </div>

        <div>
          <label>Бренд</label>
          <input type="text" name="brand" value="" id="fBrand">
        </div>

        <div>
          <label>Оценка (1-10)</label>
          <input type="number" name="rating" min="1" max="10" step="0.1" value="" id="fRating">
        </div>

        <div>
          <label>Цена (₽)</label>
          <input type="number" name="price" min="0" max="999999" step="1" value="" id="fPrice">
        </div>

        <div class="full">
          <label>Теги (через запятую)</label>
          <input type="text" name="tags" value="" id="fTags" placeholder="например: свежий, древесный, цитрус">
        </div>

        <div class="full">
          <label>Описание</label>
          <textarea name="description" id="fDesc"></textarea>
        </div>

        <div class="full">
          <label>Заметки</label>
          <textarea name="notes" id="fNotes"></textarea>
        </div>

        <div class="full">
          <label>Верхние ноты</label>
          <input type="text" name="notes_top" value="" id="fNotesTop" placeholder="например: бергамот, лимон">
        </div>

        <div class="full">
          <label>Средние ноты</label>
          <input type="text" name="notes_middle" value="" id="fNotesMiddle" placeholder="например: лаванда, жасмин">
        </div>

        <div class="full">
          <label>Базовые ноты</label>
          <input type="text" name="notes_base" value="" id="fNotesBase" placeholder="например: амбра, кедр, мускус">
        </div>

        <div class="full">
          <label>Фото</label>
          <input id="fPhoto" type="file" name="photo" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">

          <div id="pasteBox" class="pastebox" tabindex="0" role="button" aria-label="Вставить фото из буфера">
            <div class="muted">Можно вставить фото из буфера: Ctrl+V</div>
            <img id="photoPreview" class="pastebox__img" alt="" style="display:none;">
          </div>
        </div>
      </div>

      <div class="checkline" id="fRemoveLine" style="display:none;">
        <input type="checkbox" name="remove_photo" value="1" id="fRemovePhoto">
        <label for="fRemovePhoto" style="margin:0;cursor:pointer;">Удалить фото</label>
      </div>

      <div class="row">
        <button class="btn primary" type="submit">Сохранить</button>
        <button class="btn" type="button" data-close="1">Отмена</button>
      </div>
    </form>

    <form method="post" style="margin-top:10px;display:none;" id="deleteForm">
      <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="return" value="<?= esc($current_url) ?>">
      <input type="hidden" name="id" value="" id="dId">
      <button class="btn danger" type="submit">Удалить</button>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
(function(){
  const isAdmin = window.PERFUMES_IS_ADMIN === true;

  const viewModal = document.getElementById("viewModal");
  const formModal = document.getElementById("formModal");

  function openModal(modal){
    if (!modal) return;
    modal.classList.add("open");
    modal.setAttribute("aria-hidden","false");
    document.body.style.overflow = "hidden";
  }
  function closeModal(modal){
    if (!modal) return;
    modal.classList.remove("open");
    modal.setAttribute("aria-hidden","true");
    const anyOpen = document.querySelector(".modal.open");
    if (!anyOpen) document.body.style.overflow = "";
  }

  document.addEventListener("click", (e) => {
    const t = e.target;
    if (!(t instanceof HTMLElement)) return;

    if (t.dataset.close === "1") {
      closeModal(t.closest(".modal"));
      return;
    }

    if (t.classList.contains("modal-backdrop")) {
      closeModal(t.closest(".modal"));
      return;
    }
  });

  document.addEventListener("keydown", (e) => {
    if (e.key !== "Escape") return;
    const open = document.querySelector(".modal.open");
    if (open) closeModal(open);
  });

  /* mobile: filters collapsed by default */
  const filtersDetails = document.getElementById("filtersDetails");
  if (filtersDetails && window.matchMedia) {
    const mq = window.matchMedia("(max-width: 980px)");
    const apply = () => {
      if (mq.matches) filtersDetails.removeAttribute("open");
      else filtersDetails.setAttribute("open", "open");
    };
    apply();
    if (mq.addEventListener) mq.addEventListener("change", apply);
    else if (mq.addListener) mq.addListener(apply);
  }

  /* -------- FILTERS RANGE -------- */

  function initRangeBox(box){
    if (!box) return;

    const minBound = parseFloat(box.getAttribute("data-min") || "0");
    const maxBound = parseFloat(box.getAttribute("data-max") || "100");
    const step = parseFloat(box.getAttribute("data-step") || "1");
    const has = (box.getAttribute("data-has") || "0") === "1";

    const rMin = box.querySelector(".r-min");
    const rMax = box.querySelector(".r-max");
    const fill = box.querySelector(".range-fill");

    const nMin = box.querySelector('input[name$="_min"]');
    const nMax = box.querySelector('input[name$="_max"]');

    const clamp = (v) => {
      if (Number.isNaN(v)) return null;
      if (v < minBound) return minBound;
      if (v > maxBound) return maxBound;
      return v;
    };

    const toPct = (v) => {
      if (maxBound === minBound) return 0;
      return ((v - minBound) / (maxBound - minBound)) * 100;
    };

    function setFill(a, b){
      const pa = Math.max(0, Math.min(100, toPct(a)));
      const pb = Math.max(0, Math.min(100, toPct(b)));
      box.style.setProperty("--a", pa + "%");
      box.style.setProperty("--b", pb + "%");
      if (fill) fill.style.display = "";
    }

    function applyToSliders(v1, v2){
      const s1 = (v1 === null || v1 === undefined) ? minBound : v1;
      const s2 = (v2 === null || v2 === undefined) ? maxBound : v2;

      const a = Math.min(s1, s2);
      const b = Math.max(s1, s2);

      if (rMin) rMin.value = String(a);
      if (rMax) rMax.value = String(b);

      setFill(a, b);
    }

    function applyFromSliders(){
      let a = parseFloat(rMin.value);
      let b = parseFloat(rMax.value);
      if (a > b) { const tmp = a; a = b; b = tmp; }

      if (nMin) nMin.value = String(a);
      if (nMax) nMax.value = String(b);
      setFill(a, b);
    }

    function applyFromNumbers(){
      let v1 = clamp(parseFloat(nMin.value));
      let v2 = clamp(parseFloat(nMax.value));

      if (nMin.value.trim() === "") v1 = null;
      if (nMax.value.trim() === "") v2 = null;

      if (v1 !== null && v2 !== null && v1 > v2) {
        const tmp = v1; v1 = v2; v2 = tmp;
        nMin.value = String(v1);
        nMax.value = String(v2);
      }

      applyToSliders(v1, v2);
    }

    if (rMin) { rMin.min = String(minBound); rMin.max = String(maxBound); rMin.step = String(step); }
    if (rMax) { rMax.min = String(minBound); rMax.max = String(maxBound); rMax.step = String(step); }

    if (!has) applyToSliders(null, null);
    else applyFromNumbers();

    if (rMin) rMin.addEventListener("input", applyFromSliders);
    if (rMax) rMax.addEventListener("input", applyFromSliders);
    if (nMin) nMin.addEventListener("input", applyFromNumbers);
    if (nMax) nMax.addEventListener("input", applyFromNumbers);
  }

  document.querySelectorAll(".rangebox").forEach(initRangeBox);

  /* -------- VIEW -------- */

  const vIdPill = document.getElementById("vIdPill");
  const vEditBtn = document.getElementById("vEditBtn");
  const vPic = document.getElementById("vPic");
  const vName = document.getElementById("vName");
  const vBrand = document.getElementById("vBrand");
  const vBadges = document.getElementById("vBadges");
  const vTags = document.getElementById("vTags");
  const vDesc = document.getElementById("vDesc");
  const vNotes = document.getElementById("vNotes");

  const vNotesTopWrap = document.getElementById("vNotesTopWrap");
  const vNotesMiddleWrap = document.getElementById("vNotesMiddleWrap");
  const vNotesBaseWrap = document.getElementById("vNotesBaseWrap");

  const vNotesTop = document.getElementById("vNotesTop");
  const vNotesMiddle = document.getElementById("vNotesMiddle");
  const vNotesBase = document.getElementById("vNotesBase");

  let currentViewId = null;

  function renderChips(container, items){
    container.innerHTML = "";
    if (!items || !items.length) return;
    for (const x of items) {
      const s = document.createElement("span");
      s.className = "chip";
      s.textContent = x;
      container.appendChild(s);
    }
  }

  function setWrapText(wrap, el, value){
    const v = (value || "").trim();
    if (el) el.textContent = v;
    if (wrap) wrap.style.display = v ? "" : "none";
  }

  function openView(id, fullUrl){
    const it = window.PERFUMES_DATA && window.PERFUMES_DATA[id] ? window.PERFUMES_DATA[id] : null;
    if (!it) return;

    currentViewId = id;
    vIdPill.textContent = id;

    vName.textContent = it.name ? it.name : id;
    vBrand.textContent = it.brand ? it.brand : "";

    const badges = [];
    if (it.rating !== null && it.rating !== undefined && it.rating !== "") badges.push("Оценка: " + it.rating);
    if (it.price !== null && it.price !== undefined && it.price !== "") badges.push("₽: " + it.price);
    renderChips(vBadges, badges);

    const tags = Array.isArray(it.tags) ? it.tags : [];
    renderChips(vTags, tags);

    vDesc.textContent = it.description ? it.description : "";
    vNotes.textContent = it.notes ? it.notes : "";

    setWrapText(vNotesTopWrap, vNotesTop, it.notes_top || "");
    setWrapText(vNotesMiddleWrap, vNotesMiddle, it.notes_middle || "");
    setWrapText(vNotesBaseWrap, vNotesBase, it.notes_base || "");

    vPic.innerHTML = "";
    if (fullUrl) {
      const img = document.createElement("img");
      img.src = fullUrl;
      img.alt = "";
      vPic.appendChild(img);
    } else {
      const ph = document.createElement("div");
      ph.className = "ph";
      ph.textContent = "нет фото";
      vPic.appendChild(ph);
    }

    if (isAdmin) vEditBtn.style.display = "inline-flex";
    else vEditBtn.style.display = "none";

    openModal(viewModal);
  }

  document.querySelectorAll(".js-open").forEach((row) => {
    row.addEventListener("click", (e) => {
      const t = e.target;
      if (t instanceof HTMLElement && t.classList.contains("js-edit")) return;

      const id = row.getAttribute("data-id");
      if (!id) return;
      const fullUrl = row.getAttribute("data-full-url") || "";
      openView(id, fullUrl);
    });
  });

  /* -------- FORM (admin) -------- */

  if (isAdmin && formModal) {
    const openAddBtn = document.getElementById("openAddBtn");

    const fTitle = document.getElementById("fTitle");
    const fAction = document.getElementById("fAction");
    const fIdPill = document.getElementById("fIdPill");

    const fIdRow = document.getElementById("fIdRow");
    const fIdInput = document.getElementById("fIdInput");
    const fIdHidden = document.getElementById("fIdHidden");

    const fName = document.getElementById("fName");
    const fBrand = document.getElementById("fBrand");
    const fRating = document.getElementById("fRating");
    const fPrice = document.getElementById("fPrice");
    const fTags = document.getElementById("fTags");
    const fDesc = document.getElementById("fDesc");
    const fNotes = document.getElementById("fNotes");

    const fNotesTop = document.getElementById("fNotesTop");
    const fNotesMiddle = document.getElementById("fNotesMiddle");
    const fNotesBase = document.getElementById("fNotesBase");

    const fRemoveLine = document.getElementById("fRemoveLine");
    const fRemovePhoto = document.getElementById("fRemovePhoto");

    const fPhoto = document.getElementById("fPhoto");
    const pasteBox = document.getElementById("pasteBox");
    const photoPreview = document.getElementById("photoPreview");

    const perfForm = document.getElementById("perfForm");
    const deleteForm = document.getElementById("deleteForm");
    const dId = document.getElementById("dId");

    function setPhotoFile(file){
      if (!fPhoto) return;
      const dt = new DataTransfer();
      dt.items.add(file);
      fPhoto.files = dt.files;

      if (photoPreview) {
        const url = URL.createObjectURL(file);
        photoPreview.src = url;
        photoPreview.style.display = "block";
      }
    }

    function clearPhotoFile(){
      if (fPhoto) fPhoto.value = "";
      if (photoPreview) {
        photoPreview.src = "";
        photoPreview.style.display = "none";
      }
    }

    if (pasteBox) pasteBox.addEventListener("click", () => pasteBox.focus());

    if (fPhoto && photoPreview) {
      fPhoto.addEventListener("change", () => {
        const file = fPhoto.files && fPhoto.files[0] ? fPhoto.files[0] : null;
        if (!file) { clearPhotoFile(); return; }
        const url = URL.createObjectURL(file);
        photoPreview.src = url;
        photoPreview.style.display = "block";
        if (fRemovePhoto) fRemovePhoto.checked = false;
      });
    }

    document.addEventListener("paste", (e) => {
      if (!formModal.classList.contains("open")) return;

      const cd = e.clipboardData;
      if (!cd || !cd.items) return;

      for (const it of cd.items) {
        if (!it.type || !it.type.startsWith("image/")) continue;

        const blob = it.getAsFile();
        if (!blob) continue;

        const ext = blob.type === "image/png" ? "png"
          : blob.type === "image/webp" ? "webp"
          : "jpg";

        const file = new File([blob], `paste_${Date.now()}.${ext}`, { type: blob.type });
        setPhotoFile(file);
        if (fRemovePhoto) fRemovePhoto.checked = false;

        e.preventDefault();
        break;
      }
    });

    if (fRemovePhoto) {
      fRemovePhoto.addEventListener("change", () => {
        if (fRemovePhoto.checked) clearPhotoFile();
      });
    }

    function setAddMode(){
      fTitle.textContent = "Добавить";
      fAction.value = "add";

      fIdPill.style.display = "none";
      fIdPill.textContent = "";

      fIdRow.style.display = "block";
      fIdInput.value = "";
      fIdHidden.value = "";

      fName.value = "";
      fBrand.value = "";
      fRating.value = "";
      fPrice.value = "";
      fTags.value = "";
      fDesc.value = "";
      fNotes.value = "";
      if (fNotesTop) fNotesTop.value = "";
      if (fNotesMiddle) fNotesMiddle.value = "";
      if (fNotesBase) fNotesBase.value = "";

      fRemoveLine.style.display = "none";
      if (fRemovePhoto) fRemovePhoto.checked = false;

      clearPhotoFile();

      deleteForm.style.display = "none";
      dId.value = "";
    }

    function setEditMode(id){
      const it = window.PERFUMES_DATA && window.PERFUMES_DATA[id] ? window.PERFUMES_DATA[id] : null;
      if (!it) return;

      fTitle.textContent = "Редактирование";
      fAction.value = "update";

      fIdPill.style.display = "inline-flex";
      fIdPill.textContent = id;

      fIdRow.style.display = "none";
      fIdHidden.value = id;
      fIdInput.value = id;

      fName.value = it.name || "";
      fBrand.value = it.brand || "";
      fDesc.value = it.description || "";
      fNotes.value = it.notes || "";

      if (fNotesTop) fNotesTop.value = it.notes_top || "";
      if (fNotesMiddle) fNotesMiddle.value = it.notes_middle || "";
      if (fNotesBase) fNotesBase.value = it.notes_base || "";

      fRating.value = (it.rating ?? "") === null ? "" : (it.rating ?? "");
      fPrice.value = (it.price ?? "") === null ? "" : (it.price ?? "");

      const tags = Array.isArray(it.tags) ? it.tags : [];
      fTags.value = tags.join(", ");

      if (it.photo_file) fRemoveLine.style.display = "flex";
      else {
        fRemoveLine.style.display = "none";
        if (fRemovePhoto) fRemovePhoto.checked = false;
      }

      clearPhotoFile();

      deleteForm.style.display = "block";
      dId.value = id;
    }

    if (openAddBtn) {
      openAddBtn.addEventListener("click", () => {
        setAddMode();
        openModal(formModal);
      });
    }

    document.querySelectorAll(".js-edit").forEach((btn) => {
      btn.addEventListener("click", (e) => {
        e.preventDefault();
        e.stopPropagation();
        const id = btn.getAttribute("data-id");
        if (!id) return;
        setEditMode(id);
        openModal(formModal);
      });
    });

    const vEditBtn = document.getElementById("vEditBtn");
    if (vEditBtn) {
      vEditBtn.addEventListener("click", () => {
        const openId = document.getElementById("vIdPill")?.textContent || "";
        if (!openId) return;
        closeModal(viewModal);
        setEditMode(openId);
        openModal(formModal);
      });
    }

    if (perfForm) {
      perfForm.addEventListener("submit", () => {
        if (fAction.value === "add") {
          fIdHidden.value = (fIdInput.value || "").trim();
        }
      });
    }

    if (deleteForm) {
      deleteForm.addEventListener("submit", (e) => {
        if (!confirm("Удалить запись?")) e.preventDefault();
      });
    }
  }
})();
</script>

</body>
</html>
