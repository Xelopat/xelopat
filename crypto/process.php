<?php
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['bits']) || !preg_match('/^[01]+$/', $data['bits'])) {
    echo json_encode(['error' => 'Некорректная двоичная последовательность']);
    exit;
}

$bits = $data['bits'];

// Адрес Flask-сервера
$flask_url = "http://127.0.0.1:5132/process?bits=" . urlencode($bits);

// Отправка запроса на сервер Flask
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $flask_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code !== 200) {
    echo json_encode(['error' => "Ошибка на сервере Flask. Код: $http_code"]);
    exit;
}

echo $response;
