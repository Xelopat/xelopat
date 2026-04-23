<?php
// /perfumes/photo.php
declare(strict_types=1);

function valid_id(string $id): bool {
    return (bool)preg_match('/^[a-zA-Z0-9._-]{1,48}$/', $id);
}

function not_found(): void {
    http_response_code(404);
    header("Content-Type: text/plain; charset=utf-8");
    echo "404 Not Found";
    exit;
}

$id = trim((string)($_GET["id"] ?? ""));
if ($id === "" || !valid_id($id)) not_found();

$data_file = __DIR__ . "/data.json";
if (!is_file($data_file)) not_found();

$raw = @file_get_contents($data_file);
$data = $raw ? json_decode($raw, true) : null;
if (!is_array($data) || !isset($data["items"][$id]) || !is_array($data["items"][$id])) not_found();

$item = $data["items"][$id];
$photo_file = trim((string)($item["photo_file"] ?? ""));
if ($photo_file === "") not_found();

$photo_file = basename($photo_file);
if (!preg_match('/^[a-zA-Z0-9._-]{1,160}\.(jpg|jpeg|png|webp)$/i', $photo_file)) not_found();

$updated = (string)($item["updated_at"] ?? "");
$qs = $updated !== "" ? ("?v=" . rawurlencode($updated)) : "";

header("Location: /perfumes/uploads/original/" . rawurlencode($photo_file) . $qs, true, 302);
exit;
