<?php
declare(strict_types=1);
require_once __DIR__ . '/auth/lib.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST' && $method !== 'GET') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit;
}
$input = $method === 'POST' ? $_POST : $_GET;
$mode = $input['mode'] ?? 'desktop';
$android = $mode === 'android';
$port = filter_var($input['port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1024, 'max_range' => 65535]]);
$state = $input['state'] ?? null;
$challenge = $input['challenge'] ?? null;
if (($mode !== 'desktop' && !$android) || ($android ? array_key_exists('port', $input) : $port === false) ||
    !is_string($state) || !preg_match('/^[a-f0-9]{64}$/D', $state) ||
    !is_string($challenge) || !preg_match('/^[a-f0-9]{64}$/D', $challenge)) {
    http_response_code(400);
    exit('Откройте вход из приложения SSHnesh.');
}
$callback = $android ? 'ru.xelopat.sshnesh.preview://auth/callback' : 'http://127.0.0.1:' . $port;
$nonce = base64_encode(random_bytes(18));
$formTargets = $android ? "'self'" : "'self' $callback";
$scriptPolicy = $android ? "script-src 'nonce-$nonce'; " : '';
header("Content-Security-Policy: default-src 'none'; {$scriptPolicy}style-src 'self' 'nonce-$nonce'; img-src 'self'; form-action $formTargets; frame-ancestors 'none'; base-uri 'none'");
$next = '/sshnesh-login?' . http_build_query(
    ($android ? ['mode' => 'android'] : ['port' => $port]) + ['state' => $state, 'challenge' => $challenge]
);
$user = auth_current_user();
if (!$user) {
    header('Location: /auth/login?next=' . rawurlencode($next), true, 303);
    exit;
}
$androidLink = null;
if ($method === 'POST') {
    if (!is_string($input['csrf'] ?? null) || !csrf_check($input['csrf'])) {
        http_response_code(403);
        exit('Сессия устарела. Откройте вход из SSHnesh заново.');
    }
    $secret = getenv('SSHNESH_ACCOUNT_SECRET');
    if (!is_string($secret) || !preg_match('/^[a-f0-9]{64}$/D', $secret)) {
        http_response_code(503);
        exit('Вход в SSHnesh пока недоступен.');
    }
    $claims = ['aud' => 'https://sshnesh.xelopat.ru', 'sub' => $user['id'], 'username' => $user['username'],
        'id' => bin2hex(random_bytes(16)), 'exp' => time() + 90, 'challenge' => $challenge];
    $payload = rtrim(strtr(base64_encode(json_encode($claims, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    $code = $payload . '.' . hash_hmac('sha256', $payload, hex2bin($secret));
    $query = http_build_query(['state' => $state, 'code' => $code]);
    if (!$android) {
        header('Location: ' . $callback . '/sshnesh-callback?' . $query, true, 303);
        exit;
    }
    $androidLink = $callback . '?' . $query;
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>Вход в SSHnesh — xelopat</title>
  <link rel="icon" href="/img/xelopat.png">
  <link rel="stylesheet" href="/assets/site.css">
  <style nonce="<?= h($nonce) ?>">
    body { min-height: 100svh; display: grid; place-items: center; padding: 24px; color-scheme: dark; }
    .auth-box { width: min(100%, 430px); margin: 0; padding: clamp(24px, 6vw, 36px); box-sizing: border-box; }
    .sshnesh-brand { display: flex; align-items: center; gap: 10px; color: var(--accent); font-weight: 700; }
    .sshnesh-brand img { border-radius: var(--radius); }
    h1 { margin: 24px 0 10px; font-size: 26px; }
    p { color: var(--muted); line-height: 1.55; margin: 0 0 24px; }
    .sshnesh-account { margin: 0 0 18px; padding: 12px 14px; border: 1px solid var(--line); border-radius: var(--radius); background: var(--panel-2); overflow-wrap: anywhere; }
    .sshnesh-account span { display: block; font-size: 13px; color: var(--muted); }
    .auth-btn { width: 100%; font: inherit; }
    a.auth-btn { display: block; text-align: center; box-sizing: border-box; }
  </style>
</head>
<body>
<main class="auth-box">
  <div class="sshnesh-brand"><img src="/img/xelopat.png" width="32" height="32" alt=""> xelopat</div>
  <?php if ($androidLink !== null): ?>
  <h1>Открываем SSHnesh</h1>
  <p>Если приложение не открылось автоматически, нажмите кнопку ниже.</p>
  <a class="auth-btn primary" href="<?= h($androidLink) ?>">Открыть SSHnesh</a>
  <script nonce="<?= h($nonce) ?>">location.replace(<?= json_encode($androidLink, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);</script>
  <?php else: ?>
  <h1>Вход в SSHnesh</h1>
  <p><?= $android ? 'Подключите аккаунт xelopat.ru к SSHnesh на этом устройстве.' : 'Подключите аккаунт для синхронизации SSHnesh на этом устройстве.' ?></p>
  <div class="sshnesh-account"><span>Аккаунт</span><strong><?= h($user['username']) ?></strong></div>
  <form method="post" action="/sshnesh-login">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <?php if ($android): ?>
    <input type="hidden" name="mode" value="android">
    <?php else: ?>
    <input type="hidden" name="port" value="<?= $port ?>">
    <?php endif; ?>
    <input type="hidden" name="state" value="<?= h($state) ?>">
    <input type="hidden" name="challenge" value="<?= h($challenge) ?>">
    <button class="auth-btn primary" type="submit">Подключить аккаунт</button>
  </form>
  <?php endif; ?>
</main>
</body>
</html>
