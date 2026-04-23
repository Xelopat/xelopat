<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';
auth_session_start();

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

foreach (PUBLIC_PATHS as $p) {
    if ($path === $p) return;
}

if (!auth_is_logged_in()) {
    $next = urlencode($_SERVER['REQUEST_URI'] ?? '/');
    header("Location: /auth/login.php?next={$next}");
    exit;
}
