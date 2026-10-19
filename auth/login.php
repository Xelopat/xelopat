<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../includes/auth_page.php';

$next = auth_page_next((string)($_POST['next'] ?? $_GET['next'] ?? '/'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (!csrf_check((string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        auth_page('login', $next, 'Сессия устарела, попробуй ещё раз.', $username);
    }
    if (auth_login($username, $password)) {
        header('Location: ' . $next);
        exit;
    }
    http_response_code(401);
    auth_page('login', $next, 'Неверный логин или пароль.', $username);
}

// Уже вошёл: форма не нужна
if (auth_is_logged_in()) {
    header('Location: ' . $next);
    exit;
}

auth_page('login', $next);
