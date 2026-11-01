<?php
// Утреннее напоминание. Запускается по cron из консоли, из браузера недоступен:
//   */10 * * * * php /var/www/xelopat_ru_usr/data/www/xelopat.ru/workout/cron.php
// Скрипт сам решает, кому пора слать: время и часовой пояс берутся из настроек пользователя,
// за день уходит один пуш, а опоздавший больше чем на 3 часа запуск пропускается (завтрак в 20:00 не нужен).
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/push_lib.php';

const REMINDER_WINDOW = 3 * 3600;

foreach (array_keys(workout_read()['users'] ?? []) as $uid) {
    $rem = workout_user((string)$uid)['reminder'] ?? [];
    if (empty($rem['subs'])) continue;

    $tz = new DateTimeZone(in_array($rem['tz'] ?? '', timezone_identifiers_list(), true) ? $rem['tz'] : 'Europe/Moscow');
    $now = new DateTimeImmutable('now', $tz);
    $today = $now->format('Y-m-d');
    [$h, $m] = array_map('intval', explode(':', (string)($rem['time'] ?? '07:50')));
    $due = $now->setTime($h, $m);

    $late = $now->getTimestamp() - $due->getTimestamp();
    if ($late < 0 || $late > REMINDER_WINDOW || ($rem['last_sent'] ?? '') === $today) continue;

    $sent = push_send_user((string)$uid);
    $left = count(workout_user((string)$uid)['reminder']['subs'] ?? []);
    // Если ничего не принято, а подписки живы (сбой сети), следующий запуск попробует снова
    if ($sent > 0 || $left === 0) {
        workout_mutate(function (array &$users) use ($uid, $today) {
            $users[$uid]['reminder']['last_sent'] = $today;
        });
    }
    echo date('c'), " user {$uid}: sent {$sent}\n";
}
