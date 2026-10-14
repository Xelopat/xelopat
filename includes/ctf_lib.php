<?php
// CTF-афиша: список событий берём с CTFtime (даты и ссылки точные), ИИ через routerai.ru
// делает по ним краткую выжимку на русском, уровень и рекомендацию. Данные лежат в /data (закрыто от веба).
declare(strict_types=1);

const CTF_DEFAULT_MODEL = 'openai/gpt-4o-mini';
const CTF_DEFAULT_SEARCH_MODEL = 'perplexity/sonar'; // модель с выходом в интернет
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
    // пустая строка в search_model = поиск по интернету выключен; если поля нет вовсе, берём модель по умолчанию
    $search = array_key_exists('search_model', $c) ? trim((string)$c['search_model']) : CTF_DEFAULT_SEARCH_MODEL;
    return [
        'api_key' => trim((string)($c['api_key'] ?? '')),
        'model' => $model !== '' ? $model : CTF_DEFAULT_MODEL,
        'search_model' => $search,
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
function ctf_http(string $url, ?string $body, array $headers, int $timeout, bool $v4 = false, int $connect = 10): array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $connect,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ]);
        if ($v4) curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
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

// CTFtime иногда недоступен с хостинга (таймаут соединения). Пробуем напрямую по IPv4, потом через публичные прокси.
function ctf_ctftime_json(string $apiUrl): array {
    $ua = 'User-Agent: Mozilla/5.0 (compatible; xelopat.ru CTF digest)';
    $tries = [
        ['напрямую', $apiUrl, true, 7],
        ['allorigins', 'https://api.allorigins.win/raw?url=' . rawurlencode($apiUrl), false, 10],
        ['jina', 'https://r.jina.ai/' . $apiUrl, false, 10],
    ];
    $errors = [];
    foreach ($tries as [$name, $url, $v4, $connect]) {
        [$code, $body, $err] = ctf_http($url, null, [$ua], 30, $v4, $connect);
        if ($code === 200 && $body !== '') {
            $a = strpos($body, '[{');
            if ($a === false) $a = strpos($body, '[');
            $z = strrpos($body, ']');
            if ($a !== false && $z !== false && $z > $a) {
                $list = json_decode(substr($body, $a, $z - $a + 1), true);
                if (is_array($list)) return $list;
            }
            $errors[] = $name . ': не JSON';
            continue;
        }
        $errors[] = $name . ': ' . ($code ?: ($err ?: 'нет связи'));
    }
    throw new RuntimeException('CTFtime недоступен (' . implode('; ', $errors) . ')');
}

// События с CTFtime: идущие сейчас и ближайшие
function ctf_fetch_events(): array {
    $now = time();
    $url = 'https://ctftime.org/api/v1/events/?limit=100&start=' . ($now - 5 * 86400) . '&finish=' . ($now + CTF_HORIZON_DAYS * 86400);
    $list = ctf_ctftime_json($url);

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

function ctf_norm_title(string $t): string {
    $t = mb_strtolower($t);
    $t = preg_replace('~\b(19|20)\d{2}\b~u', '', $t);
    return (string)preg_replace('~[^\p{L}\p{N}]+~u', '', (string)$t);
}

// Похоже ли название на уже известное (точно или одно содержит другое). Заодно запоминает новое.
function ctf_is_dup(string $nt, array &$seen): bool {
    if ($nt === '') return true;
    if (isset($seen[$nt])) return true;
    foreach (array_keys($seen) as $sk) {
        $s = (string)$sk;
        if (strlen($nt) >= 6 && strlen($s) >= 6 && (strpos($s, $nt) !== false || strpos($nt, $s) !== false)) return true;
    }
    $seen[$nt] = true;
    return false;
}

function ctf_valid_time(string $t): bool {
    return (bool)preg_match('~^([01]\d|2[0-3]):[0-5]\d$~', $t);
}

// Дата YYYY-MM-DD + время HH:MM (МСК) -> метка времени; null, если дата кривая
function ctf_ts(string $date, string $time, bool $endOfDay): ?int {
    if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $date)) return null;
    try {
        $clock = ctf_valid_time($time) ? $time . ':00' : ($endOfDay ? '23:59:59' : '00:00:00');
        return (new DateTime($date . ' ' . $clock, new DateTimeZone('Europe/Moscow')))->getTimestamp();
    } catch (Throwable $e) {
        return null;
    }
}

// Собирает событие не с CTFtime. $sTime / $fTime — время из источника или пустая строка.
function ctf_make_event(string $title, string $sd, string $sTime, string $fd, string $fTime, string $url, bool $online, string $org, string $city, string $desc, string $source): ?array {
    $start = ctf_ts($sd, $sTime, false);
    $finish = ctf_ts($fd, $fTime, true);
    if ($start === null || $finish === null || $finish < $start) return null;
    $hasS = ctf_valid_time($sTime);
    $hasF = ctf_valid_time($fTime);
    $nt = ctf_norm_title($title);
    return [
        'id' => ($source === 'ru' ? 800000000 : 900000000) + (crc32($nt . $sd) % 90000000),
        'title' => $title,
        'start' => $start,
        'finish' => $finish,
        'time_mode' => $hasS && $hasF ? 'full' : ($hasS ? 'start' : 'none'),
        'source' => $source,
        'ru' => $source === 'ru',
        'url' => $url,
        'ctftime_url' => '',
        'weight' => 0.0,
        'participants' => 0,
        'onsite' => !$online,
        'location' => $city,
        'format' => '',
        'organizers' => $org !== '' ? [$org] : [],
        'restrictions' => '',
        'prizes' => '',
        'description' => $desc,
    ];
}

/* ---------- российские CTF: календарь ctfnews.ru ---------- */

const CTF_RU_BASE = 'https://ctfnews.ru';
const CTF_UA = 'User-Agent: Mozilla/5.0 (compatible; xelopat.ru CTF digest)';

// Календарь на текущий и три следующих месяца. Многодневное событие встречается в каждой своей клетке.
function ctf_ru_calendar(): array {
    $first = new DateTime('first day of this month', new DateTimeZone('Europe/Moscow'));
    $today = date('Y-m-d');
    $events = [];
    $errors = 0;
    for ($i = 0; $i < 4; $i++) {
        $d = (clone $first)->modify('+' . $i . ' month');
        $y = (int)$d->format('Y');
        $m = (int)$d->format('n');
        [$code, $html, $err] = ctf_http(CTF_RU_BASE . '/calendar/month/' . $y . '/' . $m . '/', null, [CTF_UA], 20);
        if ($code !== 200 || $html === '') { $errors++; continue; }
        $cells = preg_split('~<div class="calendar-cell(?=[\s"])~', $html);
        array_shift($cells);
        foreach ($cells as $cell) {
            if (!preg_match('~calendar-cell__day">\s*(\d{1,2})\s*<~', $cell, $dm)) continue;
            $date = sprintf('%04d-%02d-%02d', $y, $m, (int)$dm[1]);
            if (!preg_match_all('~<a href="(/news/[^"]+)"[^>]*class="calendar-event"[^>]*>([^<]*)</a>~u', $cell, $ms, PREG_SET_ORDER)) continue;
            foreach ($ms as $x) {
                $label = trim(html_entity_decode($x[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($label === '') continue;
                $key = $x[1] . '|' . $label;
                if (!isset($events[$key])) {
                    $events[$key] = ['label' => $label, 'news' => $x[1], 'start' => $date, 'finish' => $date];
                } else {
                    if ($date < $events[$key]['start']) $events[$key]['start'] = $date;
                    if ($date > $events[$key]['finish']) $events[$key]['finish'] = $date;
                }
            }
        }
    }
    if (!$events && $errors) throw new RuntimeException('календарь ctfnews.ru недоступен');
    $list = array_values(array_filter($events, function ($e) use ($today) { return $e['finish'] >= $today; }));
    usort($list, function ($a, $b) { return strcmp($a['start'], $b['start']); });
    return array_slice($list, 0, 20);
}

// Текст статьи про событие и внешние ссылки из неё (там обычно официальный сайт)
function ctf_ru_article(string $path): array {
    [$code, $html] = ctf_http(CTF_RU_BASE . $path, null, [CTF_UA], 15);
    if ($code !== 200 || $html === '') return ['text' => '', 'links' => []];
    $body = preg_match('~<article.*?</article>~s', $html, $m) ? $m[0] : $html;
    $links = [];
    if (preg_match_all('~href="(https?://[^"]+)"~i', $body, $lm)) {
        foreach ($lm[1] as $u) {
            $u = html_entity_decode($u, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (stripos((string)parse_url($u, PHP_URL_HOST), 'ctfnews.ru') !== false) continue;
            if (ctf_safe_url($u) !== '') $links[$u] = true;
        }
    }
    $text = trim((string)preg_replace('~\s+~u', ' ', html_entity_decode(strip_tags((string)preg_replace('~<(script|style)[^>]*>.*?</\1>~is', ' ', $body)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    return ['text' => mb_substr($text, 0, 1800), 'links' => array_slice(array_keys($links), 0, 5)];
}

// Календарь + статьи (без ИИ)
function ctf_ru_collect(): array {
    $items = ctf_ru_calendar();
    foreach ($items as &$it) {
        $art = ctf_ru_article($it['news']);
        $it['text'] = $art['text'];
        $it['links'] = $art['links'];
    }
    unset($it);
    return $items;
}

// ИИ достаёт из текста статьи время, формат, город и официальную ссылку. Даты берём из календаря, их ИИ не трогает.
function ctf_ru_extract(array $cfg, array $items, array $known): array {
    $payload = [];
    foreach ($items as $i => $it) {
        $payload[] = ['n' => $i, 'name' => $it['label'], 'dates' => $it['start'] . ' .. ' . $it['finish'], 'links' => $it['links'], 'article' => $it['text']];
    }
    $res = ctf_ai_json(
        $cfg,
        'Ты разбираешь анонсы российских CTF и киберсоревнований. Используй только то, что написано в статье, ничего не выдумывай. Отвечай только JSON.',
        'Для каждого события верни JSON {"events":[{"n":номер,"start_time":"HH:MM" московское время начала или пустая строка,'
        . '"finish_time":"HH:MM" или пустая строка,"online":true если можно участвовать онлайн, false если только очно,'
        . '"city":"город или пусто","organizer":"организатор или пусто","url":"официальный сайт или регистрация, выбери из links, иначе пусто",'
        . '"summary":"1-2 предложения по-русски о событии, формате и том, кому подойдёт"}]}. Время указывай только если оно прямо названо в статье.' . "\n"
        . json_encode($payload, JSON_UNESCAPED_UNICODE),
        5000
    );
    $seen = [];
    foreach ($known as $k) $seen[ctf_norm_title((string)$k['title'])] = true;
    $out = [];
    foreach ((array)($res['events'] ?? []) as $a) {
        if (!is_array($a)) continue;
        $n = (int)($a['n'] ?? -1);
        if (!isset($items[$n])) continue;
        $it = $items[$n];
        if (ctf_is_dup(ctf_norm_title($it['label']), $seen)) continue;
        $url = ctf_safe_url((string)($a['url'] ?? ''));
        if ($url === '' ) $url = CTF_RU_BASE . $it['news'];
        $ev = ctf_make_event(
            mb_substr($it['label'], 0, 120),
            $it['start'], (string)($a['start_time'] ?? ''),
            $it['finish'], (string)($a['finish_time'] ?? ''),
            $url,
            !array_key_exists('online', $a) || !empty($a['online']),
            mb_substr(trim(strip_tags((string)($a['organizer'] ?? ''))), 0, 60),
            mb_substr(trim(strip_tags((string)($a['city'] ?? ''))), 0, 60),
            mb_substr(trim(strip_tags((string)($a['summary'] ?? ''))), 0, 500),
            'ru'
        );
        if ($ev) $out[] = $ev;
    }
    return $out;
}

/* ---------- поиск по интернету моделью с веб-поиском ---------- */

// Находит то, чего нет ни на CTFtime, ни в календаре ctfnews.ru
function ctf_ai_search(array $cfg, array $known): array {
    if ($cfg['api_key'] === '' || $cfg['search_model'] === '') return [];
    $now = time();
    $until = $now + CTF_HORIZON_DAYS * 86400;
    $prompt = 'Сегодня ' . date('Y-m-d', $now) . '. Найди в интернете предстоящие и идущие сейчас CTF-соревнования по информационной безопасности '
        . 'в ближайшие 4 месяца. Особенно ищи российские и русскоязычные (RuCTF, VolgaCTF, Codeby Games, Standoff, студенческие, от вузов и компаний, '
        . 'региональные), а также любые другие. Только реально существующие события с официальным сайтом или страницей анонса. '
        . 'Не включай события, которые уже закончились. Верни ТОЛЬКО JSON вида '
        . '{"events":[{"title":"","start":"YYYY-MM-DD","start_time":"HH:MM по Москве или пустая строка","finish":"YYYY-MM-DD","finish_time":"HH:MM или пустая строка",'
        . '"url":"https://...","online":true,"organizer":"","description":"1-2 предложения"}]}. '
        . 'Время указывай только если оно известно точно. Если точных дат нет, пропусти событие.';
    $body = json_encode([
        'model' => $cfg['search_model'],
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'temperature' => 0.1,
        'max_tokens' => 4000,
    ], JSON_UNESCAPED_UNICODE);
    [$code, $resp, $err] = ctf_http(CTF_AI_URL, (string)$body, ['Content-Type: application/json', 'Authorization: Bearer ' . $cfg['api_key']], 100);
    $j = json_decode($resp, true);
    if ($code !== 200 || !is_array($j)) throw new RuntimeException('ИИ-поиск не ответил (' . ($code ?: ($err ?: 'нет связи')) . ')');
    $txt = (string)($j['choices'][0]['message']['content'] ?? '');
    $a = strpos($txt, '{');
    $b = strrpos($txt, '}');
    $res = ($a !== false && $b !== false && $b > $a) ? json_decode(substr($txt, $a, $b - $a + 1), true) : null;
    if (!is_array($res)) throw new RuntimeException('ИИ-поиск вернул ответ не в JSON');

    $seen = [];
    foreach ($known as $k) $seen[ctf_norm_title((string)$k['title'])] = true;
    $out = [];
    foreach ((array)($res['events'] ?? []) as $e) {
        if (!is_array($e)) continue;
        $title = mb_substr(trim(strip_tags((string)($e['title'] ?? ''))), 0, 120);
        $url = ctf_safe_url((string)($e['url'] ?? ''));
        $sd = (string)($e['start'] ?? '');
        $fd = (string)($e['finish'] ?? '') !== '' ? (string)$e['finish'] : $sd;
        if ($title === '' || $url === '') continue;
        if (stripos((string)parse_url($url, PHP_URL_HOST), 'ctftime.org') !== false) continue; // эти события и так берём с CTFtime напрямую
        $ev = ctf_make_event(
            $title, $sd, (string)($e['start_time'] ?? ''), $fd, (string)($e['finish_time'] ?? ''), $url,
            !isset($e['online']) || !empty($e['online']),
            mb_substr(trim(strip_tags((string)($e['organizer'] ?? ''))), 0, 60), '',
            mb_substr(trim(strip_tags((string)($e['description'] ?? ''))), 0, 500),
            'ai'
        );
        if (!$ev || $ev['finish'] < $now || $ev['start'] > $until) continue;
        if (ctf_is_dup(ctf_norm_title($title), $seen)) continue;
        $out[] = $ev;
        if (count($out) >= 25) break;
    }
    return $out;
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
        'ru' => !empty($a['ru']),
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
            'start' => date('Y-m-d', $e['start']),
            'finish' => date('Y-m-d', $e['finish']),
            'format' => $e['format'],
            'onsite' => $e['onsite'],
            'weight' => $e['weight'],
            'restrictions' => $e['restrictions'],
            'prizes' => mb_substr($e['prizes'], 0, 200),
            'description' => mb_substr($e['description'], 0, 450),
        ];
    }
    $system = 'Ты помогаешь составить афишу CTF-соревнований для русскоязычного студента-новичка в инфобезе, который уже немного играет. '
        . 'Тебе дают события (с CTFtime, из российского календаря или найденные поиском). Для КАЖДОГО события верни короткую выжимку на русском. '
        . 'Не выдумывай факты: если в описании чего-то нет, оставь поле пустым. Отвечай только JSON.';
    $user = 'Верни JSON вида {"events":[{"id":число,"ru":true если организатор из России или соревнование для русскоязычных, иначе false,'
        . '"level":"beginner|medium|hard","tags":["web","pwn","crypto","rev","forensics","osint","misc" и т.п., до 5],'
        . '"kind":"jeopardy|attack-defense|mixed|other","pick":true или false (стоит ли новичку участвовать),"why":"одна короткая фраза почему, можно пусто"}]}. '
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
            'start' => date('Y-m-d', $e['start']),
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

// $mode: full — известны начало и конец, start — только время начала, none — только даты
function ctf_when(int $s, int $f, string $mode = 'full'): string {
    $m = ['янв', 'фев', 'мар', 'апр', 'мая', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];
    $day = function (int $t) use ($m) { return date('j', $t) . ' ' . $m[(int)date('n', $t) - 1]; };
    $same = date('Y-m-d', $s) === date('Y-m-d', $f);
    if ($mode === 'none') return $same ? $day($s) : $day($s) . ' — ' . $day($f);
    if ($mode === 'start') {
        $a = $day($s) . ', ' . date('H:i', $s);
        return ($same ? $a : $a . ' — ' . $day($f)) . ' МСК';
    }
    $a = $day($s) . ', ' . date('H:i', $s);
    $b = $same ? date('H:i', $f) : $day($f) . ', ' . date('H:i', $f);
    return $a . ' — ' . $b . ' МСК';
}

function ctf_duration(int $s, int $f): string {
    $h = (int)round(($f - $s) / 3600);
    if ($h >= 48) return (int)round($h / 24) . ' дн';
    if ($h < 48) return $h . ' ч';
    return (int)round($h / 24) . ' дн';
}

/* ---------------- дубли и архив ---------------- */

function ctf_translit(string $t): string {
    static $map = ['а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'j',
        'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h',
        'ц' => 'c', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sh', 'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya'];
    return strtr(mb_strtolower($t), $map);
}

// Значимые слова названия (латиницей), без общих вроде ctf и годов
function ctf_tokens(string $title): array {
    static $stop = ['ctf', 'capture', 'flag', 'cup', 'kubok', 'final', 'finals', 'quals', 'qualifier', 'online', 'edition', 'season', 'autumn', 'spring',
        'winter', 'summer', 'pervyj', 'kompanii', 'kompaniya', 'competition', 'cybersecurity', 'security', 'challenge', 'open', 'team'];
    $t = ctf_translit($title);
    $t = preg_replace('~\b(19|20)\d{2}\b~u', ' ', $t);
    $out = [];
    foreach (preg_split('~[^a-z0-9]+~', (string)$t, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
        if (strlen($w) >= 4 && !in_array($w, $stop, true)) $out[$w] = true;
    }
    return array_keys($out);
}

// Одно и то же событие из разных источников: даты рядом и совпадает ключевое слово или сайт
function ctf_same_event(array $a, array $b): bool {
    $ka = ctf_norm_title((string)$a['title']);
    $kb = ctf_norm_title((string)$b['title']);
    if ($ka !== '' && $ka === $kb) return true;
    if (abs((int)$a['start'] - (int)$b['start']) > 2 * 86400) return false;
    if (array_intersect(ctf_tokens((string)$a['title']), ctf_tokens((string)$b['title']))) return true;
    $ha = strtolower((string)parse_url((string)($a['url'] ?? ''), PHP_URL_HOST));
    $hb = strtolower((string)parse_url((string)($b['url'] ?? ''), PHP_URL_HOST));
    return $ha !== '' && $ha === $hb && !preg_match('~ctftime|ctfnews|github|google|t\.me|vk\.com~', $ha);
}

// Оставляем по одному событию; важнее те, что с CTFtime (точные даты), потом календарь, потом поиск
function ctf_dedupe(array $events): array {
    $rank = function (array $e) { $s = $e['source'] ?? ''; return $s === 'ru' ? 1 : ($s === 'ai' ? 2 : 0); };
    usort($events, function ($a, $b) use ($rank) { return $rank($a) <=> $rank($b); });
    $kept = [];
    foreach ($events as $e) {
        foreach ($kept as $k) {
            if (ctf_same_event($k, $e)) continue 2;
        }
        $kept[] = $e;
    }
    usort($kept, function ($a, $b) { return $a['start'] <=> $b['start']; });
    return $kept;
}

// Прошедшие события не пропадают: переносим из прошлой афиши в архив (до года)
function ctf_with_archive(array $fresh, array $previous): array {
    $now = time();
    $have = [];
    foreach ($fresh as $e) $have[(int)$e['id']] = true;
    foreach ($previous as $e) {
        if (!is_array($e) || empty($e['id'])) continue;
        if (isset($have[(int)$e['id']])) continue;
        if ((int)$e['finish'] >= $now) continue;             // будущее, которого больше нет в источниках, не храним
        if ((int)$e['finish'] < $now - 365 * 86400) continue;
        $fresh[] = $e;
    }
    return $fresh;
}
