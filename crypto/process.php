<?php
header('Content-Type: application/json');

function respond_error(string $message, int $status = 500): void {
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['bits']) || !preg_match('/^[01]+$/', $data['bits'])) {
    respond_error('Некорректная двоичная последовательность', 400);
}

$bits = $data['bits'];

// Адрес Flask-сервера
$flask_url = "http://127.0.0.1:5132/process?bits=" . urlencode($bits);

if (!function_exists('curl_init')) {
    respond_error('Модуль cURL недоступен на сервере.');
}

// Отправка запроса на сервер Flask
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $flask_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

if ($response === false) {
    respond_error('Не удалось подключиться к сервису расчёта: ' . ($curl_error !== '' ? $curl_error : 'unknown error'), 502);
}

if ($http_code !== 200) {
    respond_error("Ошибка на сервере Flask. Код: $http_code", 502);
}

$decoded = json_decode($response, true);
if (!is_array($decoded)) {
    respond_error('Сервис расчёта вернул некорректный JSON.', 502);
}

echo json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
