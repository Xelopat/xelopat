<?php
// Завтрак и воскресные замеры. Запускается по cron из консоли:
//   */10 * * * * php /var/www/xelopat_ru_usr/data/www/xelopat.ru/workout/cron.php
// Для каждого напоминания отправляется максимум один пуш в день.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/push_lib.php';

const REMINDER_WINDOW = 3 * 3600;

$lock = fopen(dirname(workout_file()) . '/workout_cron.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit;

function reminder_due(DateTimeImmutable $now, string $time, ?string $last): bool {
    [$h, $m] = array_map('intval', explode(':', $time));
    $late = $now->getTimestamp() - $now->setTime($h, $m)->getTimestamp();
    return $late >= 0 && $late <= REMINDER_WINDOW && $last !== $now->format('Y-m-d');
}

foreach (workout_read()['users'] ?? [] as $uid => $user) {
    $rem = $user['reminder'] ?? [];
    if (empty($rem['subs'])) continue;

    $tz = new DateTimeZone(in_array($rem['tz'] ?? '', timezone_identifiers_list(), true) ? $rem['tz'] : 'Europe/Moscow');
    $now = new DateTimeImmutable('now', $tz);
    $today = $now->format('Y-m-d');
    $schedule = ['breakfast' => [$rem['time'] ?? '07:50', 'last_sent']];
    if ($now->format('N') === '7' && empty($user['measures'][$today])) {
        $schedule['measurements'] = [WORKOUT_MEASURE_TIME, 'last_measure_sent'];
    }
    foreach ($schedule as $type => [$time, $field]) {
        if (!reminder_due($now, $time, $rem[$field] ?? null)) continue;
        $sent = push_send_user((string)$uid, $type);
        if ($sent > 0) {
            workout_mutate(function (array &$users) use ($uid, $today, $field) {
                $users[$uid]['reminder'][$field] = $today;
            });
        }
        echo date('c'), " user {$uid}: {$type} sent {$sent}\n";
    }
}
