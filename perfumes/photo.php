<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/auth/lib.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/perfumes_lib.php';
header('Cache-Control: private, no-store');
function perf_not_found(): void { http_response_code(404); exit; }
$id = (string)($_GET['id'] ?? '');
try { $item = perf_load()[$id] ?? null; } catch (Throwable $e) { http_response_code(503); exit; }
if (!$item || !perf_access($item, auth_current_user())) perf_not_found();
if ($item['photo_store'] !== '') {
    $path = perf_photo_path($item['photo_store']);
    if (!$path || !is_file($path)) perf_not_found();
    $ext = pathinfo($path, PATHINFO_EXTENSION);
    header('Content-Type: image/' . ($ext === 'jpg' ? 'jpeg' : $ext));
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}
$name = (string)($item['photo_file'] ?? '');
if (!preg_match('/^[a-zA-Z0-9._-]{1,160}\.(jpg|jpeg|png|webp)$/iD', $name)) perf_not_found();
header('Location: /perfumes/uploads/original/' . rawurlencode($name), true, 302);
