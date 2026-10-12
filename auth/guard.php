<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';
auth_session_start();

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

$clean = preg_replace('~\.php$~', '', $path);
foreach (PUBLIC_PATHS as $p) {
    if ($path === $p || $clean === $p) return;
}

if (!auth_is_logged_in()) {
    $next = urlencode($_SERVER['REQUEST_URI'] ?? '/');
    header("Location: /auth/login?next={$next}");
    exit;
}
