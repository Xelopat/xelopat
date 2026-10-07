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

/* ---------------- «Запомнить меня» ----------------
   Сессия PHP живёт недолго, поэтому при входе выдаётся долгий refresh-токен в cookie.
   Токен действует 90 дней с последнего использования: раз в сутки он меняется на новый
   (новая «эпоха»), и срок снова становится 90 дней. Старый секрет ещё пару минут принимается,
   чтобы параллельные запросы из соседних вкладок не выкинули из аккаунта.
   В файле лежат только хэши секретов. */

const REMEMBER_COOKIE = 'xp_remember';
const REMEMBER_TTL = 90 * 86400;
const REMEMBER_ROTATE_AFTER = 86400;
const REMEMBER_GRACE = 120;
const REMEMBER_FILE = __DIR__ . '/../.private/remember.json';

function remember_mutate(callable $fn) {
    $dir = dirname(REMEMBER_FILE);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $fp = @fopen(REMEMBER_FILE, 'c+');
    if (!$fp) return null;
    flock($fp, LOCK_EX);
    $tokens = json_decode((string)stream_get_contents($fp), true);
    $tokens = is_array($tokens) ? $tokens : [];
    $result = $fn($tokens);
    $now = time();
    foreach ($tokens as $id => $t) {
        if ((int)($t['expires'] ?? 0) < $now) unset($tokens[$id]);
    }
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($tokens, JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $result;
}

function remember_set_cookie(string $value, int $expires): void {
    if ($expires > time()) $_COOKIE[REMEMBER_COOKIE] = $value; else unset($_COOKIE[REMEMBER_COOKIE]);
    if (headers_sent()) return;
    setcookie(REMEMBER_COOKIE, $value, [
        'expires' => $expires,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function remember_parse_cookie(): ?array {
    $raw = (string)($_COOKIE[REMEMBER_COOKIE] ?? '');
    if (!preg_match('/^([a-f0-9]{16})\.([a-f0-9]{64})$/', $raw, $m)) return null;
    return ['id' => $m[1], 'secret' => $m[2]];
}

function remember_issue(string $userId): void {
    $id = bin2hex(random_bytes(8));
    $secret = bin2hex(random_bytes(32));
    $now = time();
    remember_mutate(function (array &$tokens) use ($id, $secret, $userId, $now) {
        $tokens[$id] = [
            'uid' => $userId,
            'hash' => hash('sha256', $secret),
            'prev_hash' => '',
            'prev_until' => 0,
            'created' => $now,
            'refreshed' => $now,
            'expires' => $now + REMEMBER_TTL,
            'ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120),
        ];
    });
    remember_set_cookie($id . '.' . $secret, $now + REMEMBER_TTL);
}

// Проверяет cookie и при необходимости начинает новую эпоху. Возвращает id пользователя или null.
function remember_use(bool $forceRotate = false): ?string {
    $c = remember_parse_cookie();
    if (!$c) return null;
    $now = time();
    $hash = hash('sha256', $c['secret']);
    $newSecret = bin2hex(random_bytes(32));

    $res = remember_mutate(function (array &$tokens) use ($c, $hash, $now, $newSecret, $forceRotate) {
        $t = $tokens[$c['id']] ?? null;
        if (!$t || (int)$t['expires'] < $now) return ['ok' => false];
        $current = hash_equals((string)$t['hash'], $hash);
        $previous = !$current && $t['prev_hash'] !== '' && $now <= (int)$t['prev_until'] && hash_equals((string)$t['prev_hash'], $hash);
        if (!$current && !$previous) return ['ok' => false];
        if ($previous) return ['ok' => true, 'uid' => $t['uid'], 'rotated' => false];

        if ($forceRotate || $now - (int)$t['refreshed'] >= REMEMBER_ROTATE_AFTER) {
            $t['prev_hash'] = $t['hash'];
            $t['prev_until'] = $now + REMEMBER_GRACE;
            $t['hash'] = hash('sha256', $newSecret);
            $t['refreshed'] = $now;
            $t['expires'] = $now + REMEMBER_TTL;
            $tokens[$c['id']] = $t;
            return ['ok' => true, 'uid' => $t['uid'], 'rotated' => true];
        }
        return ['ok' => true, 'uid' => $t['uid'], 'rotated' => false];
    });

    if (!$res || !$res['ok']) {
        remember_set_cookie('', $now - 3600);
        return null;
    }
    if ($res['rotated']) remember_set_cookie($c['id'] . '.' . $newSecret, $now + REMEMBER_TTL);
    return (string)$res['uid'];
}

function remember_revoke(): void {
    $c = remember_parse_cookie();
    if ($c) remember_mutate(function (array &$tokens) use ($c) { unset($tokens[$c['id']]); });
    remember_set_cookie('', time() - 3600);
}

function auth_session_user(array $u): array {
    return ["id" => $u["id"], "username" => $u["username"], "role" => $u["role"]];
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
    static $checked = false;
    auth_session_start();
    $u = $_SESSION[AUTH_SESSION_KEY] ?? null;

    if (!$checked && isset($_COOKIE[REMEMBER_COOKIE])) {
        $checked = true;
        if (!is_array($u)) {
            // Сессия истекла: входим по refresh-токену, данные пользователя берём свежими
            $uid = remember_use(true);
            if ($uid !== null) {
                foreach (users_load()["users"] as $row) {
                    if (($row["id"] ?? "") === $uid) {
                        if (!headers_sent()) session_regenerate_id(true);
                        $u = $_SESSION[AUTH_SESSION_KEY] = auth_session_user($row);
                        $_SESSION['remember_checked'] = time();
                        break;
                    }
                }
                if (!is_array($u)) remember_revoke();
            }
        } elseif (time() - (int)($_SESSION['remember_checked'] ?? 0) >= REMEMBER_ROTATE_AFTER) {
            // Активная сессия: раз в сутки продлеваем токен ещё на 90 дней
            $_SESSION['remember_checked'] = time();
            remember_use();
        }
    }
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
    $_SESSION[AUTH_SESSION_KEY] = auth_session_user($u);
    $_SESSION['remember_checked'] = time();
    remember_issue((string)$u["id"]);
    return true;
}

function auth_logout(): void {
    auth_session_start();
    remember_revoke();
    unset($_SESSION[AUTH_SESSION_KEY], $_SESSION['remember_checked']);
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
