<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$csrf = csrf_token();
$next = safe_next((string)($_GET['next'] ?? '/'), '/');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $next = safe_next((string)($_POST['next'] ?? '/'), '/');

    if (!csrf_check((string)($_POST['csrf'] ?? ''))) {
        header("Location: /auth/register.php?next=" . urlencode($next) . "&err=csrf");
        exit;
    }

    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $password2 = (string)($_POST['password2'] ?? '');

    if (!preg_match(USERNAME_RE, $username)) {
        header("Location: /auth/register.php?next=" . urlencode($next) . "&err=username");
        exit;
    }
    if (strlen($password) < PASSWORD_MIN_LEN) {
        header("Location: /auth/register.php?next=" . urlencode($next) . "&err=pass");
        exit;
    }
    if ($password !== $password2) {
        header("Location: /auth/register.php?next=" . urlencode($next) . "&err=pass2");
        exit;
    }

    $data = users_load();
    if (user_find_by_username($data, $username)) {
        header("Location: /auth/register.php?next=" . urlencode($next) . "&err=exists");
        exit;
    }

    $role = (count($data["users"]) === 0) ? 'admin' : 'user';
    $u = user_create($username, $password, $role);
    $data["users"][] = $u;
    users_save($data);

    auth_login($username, $password);
    header("Location: " . $next);
    exit;
}

// GET: показать страницу с header, чтобы модалка открылась
$err = (string)($_GET['err'] ?? '');
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Регистрация</title>
</head>
<body>
<?php @include $_SERVER["DOCUMENT_ROOT"] . "/header.php"; ?>
<div style="max-width:900px;margin:40px auto;padding:16px;color:#64748b;">
  Открой регистрацию через кнопку в шапке.
  <?php if ($err): ?>
    <div style="margin-top:10px;color:#7f1d1d;">Ошибка регистрации.</div>
  <?php endif; ?>
</div>
<script>
  window.__AUTH_OPEN__ = "register";
</script>
</body>
</html>
