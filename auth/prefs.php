<?php
// Сохранение личных настроек вошедшего пользователя: POST JSON {csrf, dice: {type, count}}.
declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../includes/user_prefs.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function prefs_reply(int $code, bool $ok): void {
    http_response_code($code);
    echo json_encode(['ok' => $ok]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    prefs_reply(405, false);
}

$user = auth_current_user();
if (!$user) prefs_reply(401, false);

$body = json_decode((string)file_get_contents('php://input', false, null, 0, 2048), true);
if (!is_array($body) || !csrf_check((string)($body['csrf'] ?? ''))) prefs_reply(403, false);

$dice = prefs_clean_dice($body['dice'] ?? null);
if ($dice === null) prefs_reply(400, false);

$saved = prefs_set((string)$user['id'], 'dice', $dice);
prefs_reply($saved ? 200 : 500, $saved);
