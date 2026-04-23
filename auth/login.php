<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$csrf = csrf_token();
$next = safe_next((string)($_GET['next'] ?? '/'), '/');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $next = safe_next((string)($_POST['next'] ?? '/'), '/');

    if (!csrf_check((string)($_POST['csrf'] ?? ''))) {
        header("Location: /auth/login.php?next=" . urlencode($next) . "&err=csrf");
        exit;
    }

    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (auth_login($username, $password)) {
        header("Location: " . $next);
        exit;
    }

    header("Location: /auth/login.php?next=" . urlencode($next) . "&err=bad");
    exit;
}

// GET: просто показываем страницу с header, чтобы модалка открылась
$err = (string)($_GET['err'] ?? '');
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Вход</title>
</head>
<body>
<?php @include $_SERVER["DOCUMENT_ROOT"] . "/header.php"; ?>
<div style="max-width:900px;margin:40px auto;padding:16px;color:#64748b;">
  Открой вход через кнопку в шапке.
  <?php if ($err): ?>
    <div style="margin-top:10px;color:#7f1d1d;">Ошибка входа.</div>
  <?php endif; ?>
</div>
<script>
  // открыть модалку входа автоматически
  window.__AUTH_OPEN__ = "login";
</script>
</body>
</html>
