<?php
// Web Push без библиотек: отправка «пустого» пуша с подписью VAPID (RFC 8292).
// Текст уведомления собирает сам service worker (workout/sw.js), поэтому шифровать полезную нагрузку не нужно.
declare(strict_types=1);

function push_b64u(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function push_b64u_decode(string $s): string {
    return (string)base64_decode(strtr($s, '-_', '+/'));
}

// Ключи VAPID создаются при первом обращении и лежат рядом с остальными данными в /.private
function push_vapid(): ?array {
    static $keys = null;
    if ($keys !== null) return $keys;

    $file = dirname(workout_file()) . '/workout_vapid.json';
    $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    if (is_array($data) && !empty($data['pem']) && !empty($data['public'])) return $keys = $data;

    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if ($key === false || !openssl_pkey_export($key, $pem)) return null;
    $ec = openssl_pkey_get_details($key)['ec'] ?? null;
    if (!$ec) return null;
    $point = "\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);

    $keys = ['pem' => $pem, 'public' => push_b64u($point)];
    file_put_contents($file, json_encode($keys), LOCK_EX);
    @chmod($file, 0600);
    return $keys;
}

// Подпись openssl отдаёт в DER, а JWT ждёт сырые r||s по 32 байта
function push_der_to_raw(string $der): string {
    $o = 2;
    if (ord($der[1]) & 0x80) $o += ord($der[1]) & 0x7f;
    $parts = [];
    for ($i = 0; $i < 2; $i++) {
        $len = ord($der[$o + 1]);
        $int = substr($der, $o + 2, $len);
        $parts[] = str_pad(ltrim($int, "\0"), 32, "\0", STR_PAD_LEFT);
        $o += 2 + $len;
    }
    return $parts[0] . $parts[1];
}

function push_jwt(string $endpoint, array $vapid): ?string {
    $p = parse_url($endpoint);
    if (empty($p['scheme']) || empty($p['host'])) return null;
    $aud = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    $head = push_b64u((string)json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $body = push_b64u((string)json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => 'https://xelopat.ru']));
    if (!openssl_sign($head . '.' . $body, $der, $vapid['pem'], OPENSSL_ALGO_SHA256)) return null;
    return $head . '.' . $body . '.' . push_b64u(push_der_to_raw($der));
}

function push_endpoint_ok(string $endpoint): bool {
    $p = parse_url($endpoint);
    if (strlen($endpoint) > 1000 || ($p['scheme'] ?? '') !== 'https' || empty($p['host'])) return false;
    // Адрес приходит из браузера: не даём подставить локальный или числовой хост
    return !filter_var($p['host'], FILTER_VALIDATE_IP) && strpos($p['host'], '.') !== false && $p['host'] !== 'localhost';
}

// Возвращает HTTP-статус push-сервиса: 201 — принято, 404/410 — подписка умерла, 0 — не достучались.
function push_send(string $endpoint, int $ttl = 7200): int {
    $vapid = push_vapid();
    if (!$vapid || !push_endpoint_ok($endpoint)) return 0;
    $jwt = push_jwt($endpoint, $vapid);
    if (!$jwt) return 0;

    $headers = [
        'Authorization: vapid t=' . $jwt . ', k=' . $vapid['public'],
        'TTL: ' . $ttl,
        'Urgency: normal',
        'Content-Length: 0',
    ];
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => '',
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    ]);
    curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $status;
}

// Шлёт пуш на все подписки пользователя, мёртвые подписки удаляет. Возвращает число принятых.
function push_send_user(string $uid): int {
    $subs = workout_user($uid)['reminder']['subs'] ?? [];
    $ok = 0;
    $dead = [];
    foreach ($subs as $hash => $s) {
        $status = push_send((string)($s['endpoint'] ?? ''));
        if ($status >= 200 && $status < 300) $ok++;
        elseif ($status === 404 || $status === 410) $dead[] = $hash;
    }
    if ($dead) {
        workout_mutate(function (array &$users) use ($uid, $dead) {
            foreach ($dead as $h) unset($users[$uid]['reminder']['subs'][$h]);
        });
    }
    return $ok;
}
