<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../includes/auth_page.php';

$next = auth_page_next((string)($_POST['next'] ?? $_GET['next'] ?? '/'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $password2 = (string)($_POST['password2'] ?? '');
    $fail = function (string $message) use ($next, $username): void {
        http_response_code(400);
        auth_page('register', $next, $message, $username);
    };

    if (!csrf_check((string)($_POST['csrf'] ?? ''))) $fail('Сессия устарела, попробуй ещё раз.');
    if (!preg_match(USERNAME_RE, $username)) $fail('Логин: от 3 до 32 символов, латиница, цифры, точка, дефис или подчёркивание.');
    if (strlen($password) < PASSWORD_MIN_LEN) $fail('Пароль должен быть не короче ' . PASSWORD_MIN_LEN . ' символов.');
    if ($password !== $password2) $fail('Пароли не совпадают.');

    $data = users_load();
    if (user_find_by_username($data, $username)) $fail('Такой логин уже занят.');

    // Первый зарегистрированный становится админом, остальные приходят без ролей
    $u = user_create($username, $password, count($data["users"]) === 0 ? ['admin'] : []);
    $data["users"][] = $u;
    users_save($data);

    auth_login($username, $password);
    header('Location: ' . $next);
    exit;
}

// Уже вошёл: форма не нужна
if (auth_is_logged_in()) {
    header('Location: ' . $next);
    exit;
}

auth_page('register', $next);
