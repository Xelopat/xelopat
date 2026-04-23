<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function auth_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_samesite', 'Lax');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', '1');
    }
    session_start();
}

function csrf_token(): string {
    auth_session_start();
    if (empty($_SESSION[CSRF_SESSION_KEY])) {
        $_SESSION[CSRF_SESSION_KEY] = bin2hex(random_bytes(16));
    }
    return (string)$_SESSION[CSRF_SESSION_KEY];
}

function csrf_check(string $token): bool {
    auth_session_start();
    $real = (string)($_SESSION[CSRF_SESSION_KEY] ?? '');
    return $real !== '' && hash_equals($real, $token);
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function users_load(): array {
    $file = USERS_FILE;
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    if (!file_exists($file)) {
        file_put_contents($file, json_encode(["users" => []], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    }

    $raw = file_get_contents($file);
    if ($raw === false || trim($raw) === '') return ["users" => []];

    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data["users"]) || !is_array($data["users"])) return ["users" => []];
    return $data;
}

function users_save(array $data): void {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) $json = '{"users":[]}';
    file_put_contents(USERS_FILE, $json, LOCK_EX);
}

function user_find_by_username(array $data, string $username): ?array {
    foreach ($data["users"] as $u) {
        if (($u["username"] ?? "") === $username) return $u;
    }
    return null;
}

function user_update(array &$data, array $user): void {
    foreach ($data["users"] as $i => $u) {
        if (($u["id"] ?? "") === ($user["id"] ?? "")) {
            $data["users"][$i] = $user;
            return;
        }
    }
}

function user_create(string $username, string $password, string $role): array {
    $now = date('c');
    return [
        "id" => bin2hex(random_bytes(8)),
        "username" => $username,
        "password_hash" => password_hash($password, PASSWORD_DEFAULT),
        "role" => $role, // admin|user
        "created_at" => $now,
        "updated_at" => $now,
    ];
}

function auth_current_user(): ?array {
    auth_session_start();
    $u = $_SESSION[AUTH_SESSION_KEY] ?? null;
    return is_array($u) ? $u : null;
}

function auth_is_logged_in(): bool {
    return auth_current_user() !== null;
}

function auth_login(string $username, string $password): bool {
    auth_session_start();
    $data = users_load();
    $u = user_find_by_username($data, $username);
    if (!$u) return false;

    $hash = (string)($u["password_hash"] ?? "");
    if ($hash === "" || !password_verify($password, $hash)) return false;

    session_regenerate_id(true);
    $_SESSION[AUTH_SESSION_KEY] = [
        "id" => $u["id"],
        "username" => $u["username"],
        "role" => $u["role"],
    ];
    return true;
}

function auth_logout(): void {
    auth_session_start();
    unset($_SESSION[AUTH_SESSION_KEY]);
    session_regenerate_id(true);
}

function safe_next(string $next, string $fallback = '/'): string {
    // только относительные пути, без // и без схем
    if ($next === '') return $fallback;
    if ($next[0] !== '/') return $fallback;
    if (strpos($next, '//') !== false) return $fallback;
    return $next;
}

function require_login(): void {
    if (auth_is_logged_in()) return;
    $next = urlencode($_SERVER['REQUEST_URI'] ?? '/');
    header("Location: /auth/login.php?next={$next}");
    exit;
}

function require_role(string $role): void {
    $u = auth_current_user();
    if (!$u) require_login();

    if (($u["role"] ?? "") !== $role) {
        http_response_code(403);
        echo "403 Forbidden";
        exit;
    }
}
