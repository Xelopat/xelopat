<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/lib.php';
require_role('admin');
$file = dirname(__DIR__) . '/.private/xelopat.apk';
if (!is_file($file)) {
    http_response_code(404);
    exit('Приложение ещё не собрано');
}
header('Content-Type: application/vnd.android.package-archive');
header('Content-Disposition: attachment; filename="xelopat.apk"');
header('Cache-Control: private, no-store');
header('Content-Length: ' . filesize($file));
readfile($file);
