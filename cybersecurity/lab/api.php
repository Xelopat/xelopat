<?php
declare(strict_types=1);
// API карт сетевой лаборатории: у каждого вошедшего пользователя до LAB_MAX_MAPS карт
// по LAB_MAX_DEVICES устройств. Хранится в /data/lab_maps.sqlite (закрыто от веба, деплой не трогает).
//
// Поддомен cybersecurity.xelopat.ru вызывает его как https://xelopat.ru/cybersecurity/lab/api.php:
// это тот же сайт (same-site), поэтому cookie входа основного сайта уходят вместе с запросом.
// Защита от подделки запросов: разрешённые Origin и обязательный Content-Type: application/json у POST
// (такой запрос с чужой страницы без предварительной CORS-проверки не отправить).
//
// GET  ?action=list               → { user, limits, maps: [{ id, name, devices, updated }] }
// GET  ?action=get&id=…[&plain=1] → { map: { id, name, gz | json } }
// POST { action: 'save', id?, name, gz | data } → { id, name }
// POST { action: 'rename', id, name }
// POST { action: 'delete', id }
// GET  ?action=progress            → { user, progress: { done: { taskId: время } } }
// POST { action: 'progress', done: { taskId: время } } → объединяет с сохранённым, возвращает итог

require_once __DIR__ . '/../../auth/lib.php';

const LAB_MAX_MAPS = 30;
const LAB_MAX_DEVICES = 5000;
const LAB_MAX_LINKS = 25000;
const LAB_MAX_JSON = 16 * 1024 * 1024; // карта после распаковки
const LAB_ORIGINS = ['https://xelopat.ru', 'https://www.xelopat.ru', 'https://cybersecurity.xelopat.ru'];

$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '') {
    if (!in_array($origin, LAB_ORIGINS, true)) {
        http_response_code(403);
        exit;
    }
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 600');
    header('Vary: Origin');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function lab_out(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function lab_db(): PDO {
    $dir = __DIR__ . '/../../data';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $pdo = new PDO('sqlite:' . $dir . '/lab_maps.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA busy_timeout = 3000');
    $pdo->exec('CREATE TABLE IF NOT EXISTS maps (
        id TEXT PRIMARY KEY,
        uid TEXT NOT NULL,
        name TEXT NOT NULL,
        devices INTEGER NOT NULL,
        bytes INTEGER NOT NULL,
        created INTEGER NOT NULL,
        updated INTEGER NOT NULL,
        data BLOB NOT NULL
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS maps_uid ON maps(uid, updated)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS progress (uid TEXT PRIMARY KEY, data TEXT NOT NULL, updated INTEGER NOT NULL)');
    return $pdo;
}

function lab_name($v): string {
    $name = trim(preg_replace('/\s+/u', ' ', (string)$v) ?? '');
    if ($name === '') lab_out(['error' => 'Укажи название карты'], 400);
    // Обрезка по символам, а не байтам (без зависимости от mbstring)
    return preg_match('/^.{0,60}/us', $name, $m) ? $m[0] : substr($name, 0, 60);
}

// Карта приходит сжатой (gz: base64 от deflate-raw) или обычным JSON (data)
function lab_map_json(array $in): string {
    if (isset($in['gz'])) {
        $bin = base64_decode((string)$in['gz'], true);
        $json = $bin === false ? false : @gzinflate($bin, LAB_MAX_JSON + 1);
        if ($json === false) lab_out(['error' => 'Не удалось распаковать карту'], 400);
    } else {
        $json = json_encode($in['data'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if (!is_string($json) || strlen($json) > LAB_MAX_JSON) lab_out(['error' => 'Карта слишком большая'], 413);
    return $json;
}

// Пройденные задачи: { id: время прохождения }. Чужие ключи и мусор отбрасываем
function lab_done($v): array {
    $out = [];
    if (!is_array($v)) return $out;
    foreach ($v as $id => $t) {
        if (count($out) >= 300) break;
        if (!is_string($id) || !preg_match('/^[a-z0-9-]{1,40}$/', $id)) continue;
        $t = (int)$t;
        $out[$id] = $t > 0 && $t < 4102444800 ? $t : time();
    }
    return $out;
}

function lab_progress(PDO $db, string $uid): array {
    $st = $db->prepare('SELECT data FROM progress WHERE uid = ?');
    $st->execute([$uid]);
    $data = json_decode((string)$st->fetchColumn(), true);
    return ['done' => lab_done($data['done'] ?? [])];
}

if (!class_exists('PDO') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    lab_out(['error' => 'На сервере нет SQLite'], 500);
}

$user = auth_current_user();
$action = (string)($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$limits = ['maps' => LAB_MAX_MAPS, 'devices' => LAB_MAX_DEVICES];

if ($method === 'GET' && $action === 'list') {
    if (!$user) lab_out(['user' => null, 'limits' => $limits, 'maps' => []]);
    $st = lab_db()->prepare('SELECT id, name, devices, updated FROM maps WHERE uid = ? ORDER BY updated DESC');
    $st->execute([(string)$user['id']]);
    $maps = array_map(fn($r) => ['id' => $r['id'], 'name' => $r['name'], 'devices' => (int)$r['devices'], 'updated' => (int)$r['updated']], $st->fetchAll(PDO::FETCH_ASSOC));
    lab_out(['user' => ['name' => (string)$user['username']], 'limits' => $limits, 'maps' => $maps]);
}

if ($method === 'GET' && $action === 'progress') {
    if (!$user) lab_out(['user' => null, 'progress' => null]);
    lab_out(['user' => ['name' => (string)$user['username']], 'progress' => lab_progress(lab_db(), (string)$user['id'])]);
}

if (!$user) lab_out(['error' => 'Нужно войти на xelopat.ru'], 401);
$uid = (string)$user['id'];

if ($method === 'GET' && $action === 'get') {
    $st = lab_db()->prepare('SELECT id, name, data FROM maps WHERE id = ? AND uid = ?');
    $st->execute([(string)($_GET['id'] ?? ''), $uid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) lab_out(['error' => 'Карта не найдена'], 404);
    // plain=1: для браузеров без DecompressionStream отдаём уже распакованный JSON
    if (!empty($_GET['plain'])) lab_out(['map' => ['id' => $row['id'], 'name' => $row['name'], 'json' => (string)gzinflate((string)$row['data'])]]);
    lab_out(['map' => ['id' => $row['id'], 'name' => $row['name'], 'gz' => base64_encode((string)$row['data'])]]);
}

if ($method !== 'POST') lab_out(['error' => 'Неизвестный запрос'], 400);
if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) lab_out(['error' => 'Нужен JSON'], 415);
$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) lab_out(['error' => 'Пустой или повреждённый запрос'], 400);
$db = lab_db();
$act = (string)($in['action'] ?? '');

if ($act === 'save') {
    $name = lab_name($in['name'] ?? '');
    $json = lab_map_json($in);
    $map = json_decode($json, true);
    if (!is_array($map) || !is_array($map['devices'] ?? null) || !is_array($map['links'] ?? null)) lab_out(['error' => 'Это не карта лаборатории'], 400);
    $devices = count($map['devices']);
    if ($devices > LAB_MAX_DEVICES) lab_out(['error' => 'В карте больше ' . LAB_MAX_DEVICES . ' устройств'], 413);
    if (count($map['links']) > LAB_MAX_LINKS) lab_out(['error' => 'В карте больше ' . LAB_MAX_LINKS . ' кабелей'], 413);
    $blob = gzdeflate($json, 6);
    $now = time();
    $id = (string)($in['id'] ?? '');

    $db->beginTransaction();
    if ($id !== '') {
        $st = $db->prepare('UPDATE maps SET name = ?, devices = ?, bytes = ?, updated = ?, data = ? WHERE id = ? AND uid = ?');
        $st->bindValue(1, $name);
        $st->bindValue(2, $devices, PDO::PARAM_INT);
        $st->bindValue(3, strlen($json), PDO::PARAM_INT);
        $st->bindValue(4, $now, PDO::PARAM_INT);
        $st->bindValue(5, $blob, PDO::PARAM_LOB);
        $st->bindValue(6, $id);
        $st->bindValue(7, $uid);
        $st->execute();
        if ($st->rowCount() === 0) { $db->rollBack(); lab_out(['error' => 'Карта не найдена'], 404); }
    } else {
        $cnt = $db->prepare('SELECT COUNT(*) FROM maps WHERE uid = ?');
        $cnt->execute([$uid]);
        if ((int)$cnt->fetchColumn() >= LAB_MAX_MAPS) { $db->rollBack(); lab_out(['error' => 'Уже ' . LAB_MAX_MAPS . ' карт: удали ненужную или перезапиши существующую'], 409); }
        $id = bin2hex(random_bytes(8));
        $st = $db->prepare('INSERT INTO maps (id, uid, name, devices, bytes, created, updated, data) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $st->bindValue(1, $id);
        $st->bindValue(2, $uid);
        $st->bindValue(3, $name);
        $st->bindValue(4, $devices, PDO::PARAM_INT);
        $st->bindValue(5, strlen($json), PDO::PARAM_INT);
        $st->bindValue(6, $now, PDO::PARAM_INT);
        $st->bindValue(7, $now, PDO::PARAM_INT);
        $st->bindValue(8, $blob, PDO::PARAM_LOB);
        $st->execute();
    }
    $db->commit();
    lab_out(['id' => $id, 'name' => $name]);
}

if ($act === 'rename') {
    $st = $db->prepare('UPDATE maps SET name = ? WHERE id = ? AND uid = ?');
    $st->execute([lab_name($in['name'] ?? ''), (string)($in['id'] ?? ''), $uid]);
    if ($st->rowCount() === 0) lab_out(['error' => 'Карта не найдена'], 404);
    lab_out(['ok' => true]);
}

if ($act === 'delete') {
    $st = $db->prepare('DELETE FROM maps WHERE id = ? AND uid = ?');
    $st->execute([(string)($in['id'] ?? ''), $uid]);
    lab_out(['ok' => true]);
}

if ($act === 'progress') {
    // Объединяем: задача, решённая хоть где-то, остаётся решённой; время берём самое раннее
    $db->beginTransaction();
    $cur = lab_progress($db, $uid)['done'];
    foreach (lab_done($in['done'] ?? []) as $id => $t) {
        $cur[$id] = isset($cur[$id]) ? min($cur[$id], $t) : $t;
    }
    $cur = lab_done($cur);
    $st = $db->prepare('INSERT OR REPLACE INTO progress (uid, data, updated) VALUES (?, ?, ?)');
    $st->execute([$uid, json_encode(['done' => (object)$cur]), time()]);
    $db->commit();
    lab_out(['progress' => ['done' => (object)$cur]]);
}

lab_out(['error' => 'Неизвестное действие'], 400);
