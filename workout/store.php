<?php
// Хранилище тренировок: один JSON в /.private (деплой его не трогает), запись под блокировкой.
// Формат дня: done — отметки, sets — повторы или секунды по подходам, sw — веса в том же порядке.
// Старое поле w хранит один вес на упражнение и читается, пока запись не отредактирована.
// Формат: {"users": {"<id>": {"days": {"2026-11-03": {"done": {"<ex>": 1}, "sets": {"<ex>": [10, 8]}, "sw": {"<ex>": [22.5, 20]}}},
//                              "measures": {"2026-11-03": {...}},
//                              "reminder": {"time": "07:50", "tz": "Europe/Moscow", "subs": {"<hash>": {...}}, "last_sent": "2026-11-03"}}}}
declare(strict_types=1);

function workout_file(): string {
    // WORKOUT_DIR нужен только для локальных проверок, чтобы не трогать настоящие данные
    $dir = getenv('WORKOUT_DIR') ?: __DIR__ . '/../.private';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return rtrim($dir, '/\\') . '/workout.json';
}

function workout_read(): array {
    $file = workout_file();
    if (!is_file($file)) return [];
    $fp = @fopen($file, 'r');
    if (!$fp) return [];
    flock($fp, LOCK_SH);
    $all = json_decode((string)stream_get_contents($fp), true);
    flock($fp, LOCK_UN);
    fclose($fp);
    return is_array($all) ? $all : [];
}

// Читает, отдаёт массив пользователей в $fn по ссылке и записывает обратно. Возвращает результат $fn или null, если файл не открылся.
function workout_mutate(callable $fn) {
    $fp = @fopen(workout_file(), 'c+');
    if (!$fp) return null;
    flock($fp, LOCK_EX);
    $all = json_decode((string)stream_get_contents($fp), true);
    $all = is_array($all) ? $all : [];
    $all['users'] = is_array($all['users'] ?? null) ? $all['users'] : [];
    $result = $fn($all['users']);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, (string)json_encode($all, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $result;
}

function workout_user(string $uid): array {
    $u = workout_read()['users'][$uid] ?? [];
    return is_array($u) ? $u : [];
}

function workout_plan(): array {
    static $plan = null;
    return $plan ??= require __DIR__ . '/plan.php';
}

function workout_exercises(): array {
    $exercises = [];
    foreach (workout_plan()['days'] as $day) {
        foreach ($day['exercises'] as $ex) $exercises[$ex['id']] = $ex;
    }
    return $exercises;
}

const WORKOUT_MEASURE_TIME = '12:00';

function workout_valid_date(string $d): bool {
    return (bool)preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

// Что уходит на страницу: без подписок на пуши (там адреса, они клиенту не нужны)
function workout_public_state(array $u): array {
    $rem = is_array($u['reminder'] ?? null) ? $u['reminder'] : [];
    return [
        'days' => (object)($u['days'] ?? []),
        'measures' => (object)($u['measures'] ?? []),
        'reminder' => [
            'time' => (string)($rem['time'] ?? '07:50'),
            'measure_time' => WORKOUT_MEASURE_TIME,
            'subs' => array_keys(is_array($rem['subs'] ?? null) ? $rem['subs'] : []),
            'measure_subs' => array_keys(array_filter($rem['subs'] ?? [], static fn(array $s): bool => isset($s['p256dh'], $s['auth']))),
        ],
    ];
}
