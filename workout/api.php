<?php
// /workout/api — JSON-API страницы тренировок. Только админ; изменения только POST с csrf-токеном.
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/auth/lib.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/push_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function api_out(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$user = auth_current_user();
if (!$user || !user_has_role($user, 'admin')) api_out(['error' => 'forbidden'], 403);
$uid = (string)$user['id'];

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    api_out(['state' => workout_public_state(workout_user($uid))]);
}

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in) || !csrf_check((string)($in['csrf'] ?? ''))) api_out(['error' => 'csrf'], 400);

$action = (string)($in['action'] ?? '');
$date = (string)($in['date'] ?? '');
$exId = (string)($in['id'] ?? '');

if (in_array($action, ['done', 'weight', 'exercise_log'], true)) {
    $exercise = workout_exercises()[$exId] ?? null;
    if (!workout_valid_date($date) || !$exercise) api_out(['error' => 'bad_request'], 400);
}

switch ($action) {
    case 'done':
        $on = !empty($in['value']);
        workout_mutate(function (array &$users) use ($uid, $date, $exId, $on) {
            if ($on) $users[$uid]['days'][$date]['done'][$exId] = 1;
            else unset($users[$uid]['days'][$date]['done'][$exId]);
        });
        break;

    case 'weight':
        $w = $in['value'] ?? null;
        $w = is_numeric($w) ? round((float)$w, 2) : null;
        if ($w !== null && ($w <= 0 || $w > 1000)) api_out(['error' => 'bad_request'], 400);
        workout_mutate(function (array &$users) use ($uid, $date, $exId, $w) {
            if ($w !== null) $users[$uid]['days'][$date]['w'][$exId] = $w;
            else unset($users[$uid]['days'][$date]['w'][$exId]);
        });
        break;

    case 'exercise_log':
        $sets = $in['sets'] ?? null;
        $weight = $in['weight'] ?? null;
        $perSet = array_key_exists('weights', $in);
        $weights = $in['weights'] ?? [];
        $max = isset($exercise['seconds']) ? 7200 : 999;
        if (!is_array($sets) || array_values($sets) !== $sets || count($sets) > 30) api_out(['error' => 'bad_request'], 400);
        foreach ($sets as $value) {
            if ($value !== null && (!is_int($value) || $value < 1 || $value > $max)) api_out(['error' => 'bad_request'], 400);
        }
        if ($weight !== null && (empty($exercise['track']) || !is_numeric($weight) || $weight < 0.01 || $weight > 1000)) {
            api_out(['error' => 'bad_request'], 400);
        }
        if ($perSet) {
            if (!is_array($weights) || array_values($weights) !== $weights ||
                count($weights) !== (!empty($exercise['track']) ? count($sets) : 0)) {
                api_out(['error' => 'bad_request'], 400);
            }
            foreach ($weights as &$value) {
                if ($value === null) continue;
                if ((!is_int($value) && !is_float($value)) || $value < 0.01 || $value > 1000) api_out(['error' => 'bad_request'], 400);
                $value = round($value, 2);
            }
            unset($value);
        }
        $saved = workout_mutate(function (array &$users) use ($uid, $date, $exId, $sets, $weight, $perSet, $weights) {
            if ($sets) $users[$uid]['days'][$date]['sets'][$exId] = $sets;
            else unset($users[$uid]['days'][$date]['sets'][$exId]);
            if ($perSet) {
                if (array_filter($weights)) $users[$uid]['days'][$date]['sw'][$exId] = $weights;
                else unset($users[$uid]['days'][$date]['sw'][$exId]);
            }
            if (!$perSet && $weight !== null) $users[$uid]['days'][$date]['w'][$exId] = round((float)$weight, 2);
            else unset($users[$uid]['days'][$date]['w'][$exId]);
            return true;
        });
        if (!$saved) api_out(['error' => 'storage'], 500);
        break;

    case 'measure_add':
        if (!workout_valid_date($date)) api_out(['error' => 'bad_request'], 400);
        $row = [];
        foreach (array_keys(workout_plan()['measures']) as $k) {
            $v = $in['values'][$k] ?? null;
            if (is_numeric($v) && (float)$v > 0 && (float)$v < 1000) $row[$k] = round((float)$v, 1);
        }
        if (!$row) api_out(['error' => 'empty'], 400);
        workout_mutate(function (array &$users) use ($uid, $date, $row) {
            $users[$uid]['measures'][$date] = $row;
        });
        break;

    case 'measure_del':
        if (!workout_valid_date($date)) api_out(['error' => 'bad_request'], 400);
        workout_mutate(function (array &$users) use ($uid, $date) {
            unset($users[$uid]['measures'][$date]);
        });
        break;

    case 'reminder_time':
        $time = (string)($in['time'] ?? '');
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) api_out(['error' => 'bad_request'], 400);
        workout_mutate(function (array &$users) use ($uid, $time) {
            $users[$uid]['reminder']['time'] = $time;
            // Новое время может ещё не наступить сегодня: пусть сработает
            unset($users[$uid]['reminder']['last_sent']);
        });
        break;

    case 'push_sub':
        $endpoint = (string)($in['endpoint'] ?? '');
        $tz = (string)($in['tz'] ?? '');
        if (!push_endpoint_ok($endpoint)) api_out(['error' => 'bad_endpoint'], 400);
        if (!in_array($tz, timezone_identifiers_list(), true)) $tz = 'Europe/Moscow';
        $p256dh = (string)($in['p256dh'] ?? '');
        $auth = (string)($in['auth'] ?? '');
        if (($p256dh !== '' || $auth !== '') && !push_keys_ok($p256dh, $auth)) api_out(['error' => 'bad_keys'], 400);
        $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120);
        workout_mutate(function (array &$users) use ($uid, $endpoint, $tz, $ua, $p256dh, $auth) {
            $rem = $users[$uid]['reminder'] ?? [];
            $rem['time'] = $rem['time'] ?? '07:50';
            $rem['tz'] = $tz;
            $hash = sha1($endpoint);
            $sub = $rem['subs'][$hash] ?? [];
            $sub = array_merge($sub, ['endpoint' => $endpoint, 'ua' => $ua, 'added' => time()]);
            if ($p256dh !== '') $sub = array_merge($sub, ['p256dh' => $p256dh, 'auth' => $auth]);
            $rem['subs'][$hash] = $sub;
            $users[$uid]['reminder'] = $rem;
        });
        break;

    case 'push_unsub':
        $endpoint = (string)($in['endpoint'] ?? '');
        workout_mutate(function (array &$users) use ($uid, $endpoint) {
            unset($users[$uid]['reminder']['subs'][sha1($endpoint)]);
        });
        break;

    case 'push_test':
        $sent = push_send_user($uid, ($in['type'] ?? '') === 'measurements' ? 'measurements' : 'breakfast');
        api_out(['sent' => $sent, 'state' => workout_public_state(workout_user($uid))]);

    default:
        api_out(['error' => 'unknown_action'], 400);
}

api_out(['ok' => true, 'state' => workout_public_state(workout_user($uid))]);
