<?php
// Личные настройки вошедших пользователей (сейчас только кубики на главной).
// Файл лежит рядом с users.json в /.private, деплой его не трогает; запись под блокировкой.
declare(strict_types=1);

const PREFS_FILE = __DIR__ . '/../.private/prefs.json';
const DICE_TYPES = [2, 4, 6, 8, 10, 12, 20];
const DICE_MAX_COUNT = 6;

function prefs_clean_dice($value): ?array {
    if (!is_array($value)) return null;
    $type = $value['type'] ?? null;
    $count = $value['count'] ?? null;
    if (!is_int($type) || !in_array($type, DICE_TYPES, true)) return null;
    if (!is_int($count) || $count < 1 || $count > DICE_MAX_COUNT) return null;
    return ['type' => $type, 'count' => $count];
}

function prefs_get(string $userId): array {
    if (!is_file(PREFS_FILE)) return [];
    $fp = @fopen(PREFS_FILE, 'r');
    if (!$fp) return [];
    flock($fp, LOCK_SH);
    $all = json_decode((string)stream_get_contents($fp), true);
    flock($fp, LOCK_UN);
    fclose($fp);
    return is_array($all) && is_array($all[$userId] ?? null) ? $all[$userId] : [];
}

function prefs_set(string $userId, string $key, $value): bool {
    $dir = dirname(PREFS_FILE);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $fp = @fopen(PREFS_FILE, 'c+');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    $all = json_decode((string)stream_get_contents($fp), true);
    $all = is_array($all) ? $all : [];
    $mine = is_array($all[$userId] ?? null) ? $all[$userId] : [];
    $mine[$key] = $value;
    $all[$userId] = $mine;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, (string)json_encode($all, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}
