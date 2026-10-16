<?php
// Upload this file as /tests/index.php. Synthetic content only.
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$action = isset($_GET['action']) ? $_GET['action'] : 'probe';
$via = isset($_GET['via']) ? $_GET['via'] : 'direct';
if (!is_string($action) || !is_string($via)) {
    http_response_code(400);
    exit;
}
$allowed = array('direct', 'link', 'external', 'iframe', 'object', 'download', 'attachment');
if (!in_array($via, $allowed, true)) {
    $via = 'direct';
}
$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

function qa_event($stage, $via, $method) {
    error_log('WEBVIEW_QA ' . json_encode(array(
        'utc' => gmdate('c'), 'stage' => $stage, 'via' => $via, 'method' => $method
    )));
}

if ($action === 'executed') {
    header('Content-Type: text/plain; charset=utf-8');
    if ($method !== 'POST') {
        header('Allow: POST');
        http_response_code(405);
        exit;
    }
    if (isset($_SERVER['CONTENT_LENGTH']) && (int) $_SERVER['CONTENT_LENGTH'] > 256) {
        http_response_code(413);
        exit;
    }
    $body = file_get_contents('php://input', false, null, 0, 257);
    if ($body !== 'WEBVIEW_QA_EXECUTED') {
        http_response_code(400);
        exit;
    }
    qa_event('EXECUTED', $via, $method);
    echo 'OK';
    exit;
}

if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}
if ($action === 'health') {
    header('Content-Type: text/plain; charset=utf-8');
    if ($method !== 'HEAD') echo 'WEBVIEW_QA_OK';
    exit;
}

$script = <<<'JS'
document.getElementById('status').textContent = 'ВЫПОЛНЕН JavaScript подгруженного файла';
document.getElementById('status').style.color = 'red';
var via = new URLSearchParams(location.search).get('via') || 'attachment';
var endpoint = 'https://xelopat.ru/tests/index.php?action=executed&via=' + encodeURIComponent(via);
if (navigator.sendBeacon) {
    navigator.sendBeacon(endpoint, 'WEBVIEW_QA_EXECUTED');
} else {
    fetch(endpoint, {method:'POST', body:'WEBVIEW_QA_EXECUTED', mode:'no-cors'}).catch(function(){});
}
JS;

if ($action === 'script') {
    header('Content-Type: application/javascript; charset=utf-8');
    qa_event('SCRIPT_REQUEST', $via, $method);
    if ($method !== 'HEAD') echo $script;
    exit;
}
if (!in_array($action, array('probe', 'external', 'download'), true)) {
    http_response_code(404);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
if ($action === 'download') {
    header('Content-Disposition: attachment; filename="probe.html"');
}
qa_event($action === 'download' ? 'DOWNLOAD' : 'LOAD', $via, $method);
if ($method === 'HEAD') exit;

echo '<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><body>';
echo '<p>Файл загружен</p><p id="status">JavaScript не выполнен</p>';
if ($action === 'external') {
    echo '<script src="https://xelopat.ru/tests/index.php?action=script&amp;via=' . rawurlencode($via) . '"></script>';
} else {
    echo '<script>' . $script . '</script>';
}
echo '</body></html>';
