<?php
// CTF-афиша: список событий берём с CTFtime (даты и ссылки точные), ИИ через routerai.ru
// делает по ним краткую выжимку на русском, уровень и рекомендацию. Данные лежат в /data (закрыто от веба).
declare(strict_types=1);

const CTF_DEFAULT_MODEL = 'openai/gpt-4o-mini';
const CTF_AI_URL = 'https://routerai.ru/api/v1/chat/completions';
const CTF_BATCH = 15;          // сколько событий отдаём ИИ за один запрос (чтобы не упереться в таймаут)
const CTF_HORIZON_DAYS = 120;  // на сколько дней вперёд смотрим
const CTF_MAX_EVENTS = 80;

function ctf_dir(): string {
    return rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__)), '/\\') . '/data';
}

function ctf_read(string $name): array {
    $f = ctf_dir() . '/' . $name;
    $d = is_file($f) ? json_decode((string)file_get_contents($f), true) : [];
    return is_array($d) ? $d : [];
}

function ctf_write(string $name, array $data): bool {
    $dir = ctf_dir();
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    return $json !== false && file_put_contents($dir . '/' . $name, $json, LOCK_EX) !== false;
}

// Ключ и модель хранятся только на сервере: data/ctf_config.json (в git не попадает)
function ctf_config(): array {
    $c = ctf_read('ctf_config.json');
    $model = trim((string)($c['model'] ?? ''));
    return [
        'api_key' => trim((string)($c['api_key'] ?? '')),
        'model' => $model !== '' ? $model : CTF_DEFAULT_MODEL,
    ];
}

function ctf_mask_key(string $key): string {
    if ($key === '') return '';
    return (strlen($key) > 8 ? substr($key, 0, 3) . '…' . substr($key, -4) : '…');
}

function ctf_safe_url(string $u): string {
    $u = trim($u);
    return preg_match('~^https?://[^\s<>"\']+$~i', $u) ? $u : '';
}

// [код ответа, тело, текст ошибки]
function ctf_http(string $url, ?string $body, array $headers, int $timeout): array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, is_string($resp) ? $resp : '', $err];
    }

    $opts = ['http' => ['method' => $body === null ? 'GET' : 'POST', 'header' => implode("\r\n", $headers), 'timeout' => $timeout, 'ignore_errors' => true]];
    if ($body !== null) $opts['http']['content'] = $body;
    $resp = @file_get_contents($url, false, stream_context_create($opts));
    $code = 0;
    foreach (($http_response_header ?? []) as $h) {
        if (preg_match('~^HTTP/\S+\s+(\d{3})~', (string)$h, $m)) $code = (int)$m[1];
    }
    return [$code, is_string($resp) ? $resp : '', $resp === false ? 'сетевая ошибка' : ''];
}

// События с CTFtime: идущие сейчас и ближайшие
function ctf_fetch_events(): array {
    $now = time();
    $url = 'https://ctftime.org/api/v1/events/?limit=100&start=' . ($now - 5 * 86400) . '&finish=' . ($now + CTF_HORIZON_DAYS * 86400);
    [$code, $body, $err] = ctf_http($url, null, ['User-Agent: Mozilla/5.0 (compatible; xelopat.ru CTF digest)'], 30);
    if ($code !== 200) throw new RuntimeException('CTFtime не ответил (' . ($code ?: ($err ?: 'нет связи')) . ')');
    $list = json_decode($body, true);
    if (!is_array($list)) throw new RuntimeException('CTFtime вернул не JSON');

    $out = [];
    foreach ($list as $e) {
        if (!is_array($e) || empty($e['id'])) continue;
        $start = strtotime((string)($e['start'] ?? ''));
        $finish = strtotime((string)($e['finish'] ?? ''));
        if (!$start || !$finish || $finish < $now) continue;
        $orgs = [];
        foreach ((array)($e['organizers'] ?? []) as $o) {
            if (is_array($o) && !empty($o['name'])) $orgs[] = mb_substr(trim((string)$o['name']), 0, 60);
        }
        $out[] = [
            'id' => (int)$e['id'],
            'title' => mb_substr(trim((string)($e['title'] ?? '')), 0, 160),
            'start' => $start,
            'finish' => $finish,
            'url' => ctf_safe_url((string)($e['url'] ?? '')),
            'ctftime_url' => ctf_safe_url((string)($e['ctftime_url'] ?? '')),
            'weight' => round((float)($e['weight'] ?? 0), 2),
            'participants' => (int)($e['participants'] ?? 0),
            'onsite' => !empty($e['onsite']),
            'location' => mb_substr(trim((string)($e['location'] ?? '')), 0, 120),
            'format' => mb_substr(trim((string)($e['format'] ?? '')), 0, 60),
            'organizers' => array_slice($orgs, 0, 3),
            'restrictions' => mb_substr(trim((string)($e['restrictions'] ?? '')), 0, 60),
            'prizes' => mb_substr(trim(strip_tags((string)($e['prizes'] ?? ''))), 0, 300),
            'description' => mb_substr(trim(strip_tags((string)($e['description'] ?? ''))), 0, 1500),
        ];
    }
    usort($out, function ($a, $b) { return $a['start'] <=> $b['start']; });
    return array_slice($out, 0, CTF_MAX_EVENTS);
}

// Один запрос к routerai.ru (формат OpenAI). Возвращает разобранный JSON-ответ модели.
function ctf_ai_json(array $cfg, string $system, string $user, int $maxTokens): array {
    if ($cfg['api_key'] === '') throw new RuntimeException('Не задан API-ключ routerai.ru (настройки ИИ ниже)');
    $body = json_encode([
        'model' => $cfg['model'],
        'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
        'response_format' => ['type' => 'json_object'],
        'temperature' => 0.2,
        'max_tokens' => $maxTokens,
    ], JSON_UNESCAPED_UNICODE);
    [$code, $resp, $err] = ctf_http(CTF_AI_URL, (string)$body, ['Content-Type: application/json', 'Authorization: Bearer ' . $cfg['api_key']], 100);
    $j = json_decode($resp, true);
    if ($code !== 200 || !is_array($j)) {
        $e = is_array($j) ? ($j['error'] ?? '') : '';
        $msg = is_array($e) ? (string)($e['message'] ?? '') : (string)$e;
        throw new RuntimeException('ИИ не ответил (' . ($code ?: ($err ?: 'нет связи')) . ')' . ($msg !== '' ? ': ' . mb_substr($msg, 0, 160) : ''));
    }
    $txt = trim((string)($j['choices'][0]['message']['content'] ?? ''));
    $txt = preg_replace('~^```(?:json)?\s*|\s*```$~u', '', $txt);
    $out = json_decode((string)$txt, true);
    if (!is_array($out)) throw new RuntimeException('ИИ вернул ответ не в JSON, попробуй ещё раз');
    return $out;
}

function ctf_clean_ai(array $a): array {
    $tags = [];
    foreach ((array)($a['tags'] ?? []) as $t) {
        $t = mb_strtolower(trim((string)$t));
        if ($t !== '' && preg_match('~^[\p{L}\p{N}+\-. ]{1,20}$~u', $t)) $tags[] = $t;
    }
    $level = (string)($a['level'] ?? '');
    $kind = (string)($a['kind'] ?? '');
    return [
        'summary' => mb_substr(trim(strip_tags((string)($a['summary'] ?? ''))), 0, 420),
        'level' => in_array($level, ['beginner', 'medium', 'hard'], true) ? $level : '',
        'kind' => in_array($kind, ['jeopardy', 'attack-defense', 'mixed', 'other'], true) ? $kind : '',
        'tags' => array_slice(array_values(array_unique($tags)), 0, 5),
        'pick' => !empty($a['pick']),
        'why' => mb_substr(trim(strip_tags((string)($a['why'] ?? ''))), 0, 200),
    ];
}

// Выжимка по пачке событий: [id => поля от ИИ]
function ctf_ai_batch(array $events, array $cfg): array {
    $items = [];
    $ids = [];
    foreach ($events as $e) {
        $ids[$e['id']] = true;
        $items[] = [
            'id' => $e['id'],
            'title' => $e['title'],
            'start' => gmdate('Y-m-d', $e['start']),
            'finish' => gmdate('Y-m-d', $e['finish']),
            'format' => $e['format'],
            'onsite' => $e['onsite'],
            'weight' => $e['weight'],
            'restrictions' => $e['restrictions'],
            'prizes' => mb_substr($e['prizes'], 0, 200),
            'description' => mb_substr($e['description'], 0, 450),
        ];
    }
    $system = 'Ты помогаешь составить афишу CTF-соревнований для русскоязычного студента-новичка в инфобезе, который уже немного играет. '
        . 'Тебе дают события с CTFtime. Для КАЖДОГО события верни короткую выжимку на русском. '
        . 'Не выдумывай факты: если в описании чего-то нет, оставь поле пустым. Отвечай только JSON.';
    $user = 'Верни JSON вида {"events":[{"id":число,"summary":"1-2 предложения по-русски о чём событие и кто организатор, если известно",'
        . '"level":"beginner|medium|hard","tags":["web","pwn","crypto","rev","forensics","osint","misc" и т.п., до 5],'
        . '"kind":"jeopardy|attack-defense|mixed|other","pick":true или false (стоит ли новичку участвовать),"why":"коротко почему, можно пусто"}]}. '
        . 'События:' . "\n" . json_encode($items, JSON_UNESCAPED_UNICODE);

    $res = ctf_ai_json($cfg, $system, $user, 6000);
    $out = [];
    foreach ((array)($res['events'] ?? []) as $a) {
        if (!is_array($a)) continue;
        $id = (int)($a['id'] ?? 0);
        if ($id && isset($ids[$id])) $out[$id] = ctf_clean_ai($a);
    }
    return $out;
}

function ctf_ai_digest(array $events, array $cfg): string {
    $items = [];
    foreach ($events as $e) {
        $items[] = [
            'title' => $e['title'],
            'start' => gmdate('Y-m-d', $e['start']),
            'level' => $e['level'] ?? '',
            'pick' => !empty($e['pick']),
        ];
    }
    $res = ctf_ai_json(
        $cfg,
        'Ты пишешь короткую сводку для афиши CTF на русском. Не выдумывай ничего, чего нет в списке. Отвечай только JSON.',
        'Вот события в ближайшие недели. Верни JSON {"digest":"2-3 предложения: что интересного, на что обратить внимание новичку"}.' . "\n" . json_encode(array_slice($items, 0, 40), JSON_UNESCAPED_UNICODE),
        500
    );
    return mb_substr(trim(strip_tags((string)($res['digest'] ?? ''))), 0, 600);
}

/* ---------------- оформление ---------------- */

function ctf_when(int $s, int $f): string {
    $m = ['янв', 'фев', 'мар', 'апр', 'мая', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];
    $a = date('j', $s) . ' ' . $m[(int)date('n', $s) - 1] . ', ' . date('H:i', $s);
    $b = date('Y-m-d', $s) === date('Y-m-d', $f)
        ? date('H:i', $f)
        : date('j', $f) . ' ' . $m[(int)date('n', $f) - 1] . ', ' . date('H:i', $f);
    return $a . ' — ' . $b . ' МСК';
}

function ctf_duration(int $s, int $f): string {
    $h = (int)round(($f - $s) / 3600);
    if ($h < 48) return $h . ' ч';
    return (int)round($h / 24) . ' дн';
}
