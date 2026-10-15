<?php
// Отдаётся по адресу /sitemap.xml (см. .htaccess). Только открытые страницы, которые стоит видеть в поиске.
require_once __DIR__ . '/crypto/theme.php';

$paths = ['/', '/projects', '/crypto'];
foreach (array_keys(crypto_tools()) as $slug) {
    $paths[] = '/crypto/' . $slug;
}
$paths[] = '/adminis/ip';
$paths[] = '/coursework/injection';
$paths[] = '/bases/domophones';
$paths[] = '/bases/default-creds';
$paths[] = '/bases/mac';

header('Content-Type: application/xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>', "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', "\n";
foreach ($paths as $path) {
    echo '  <url><loc>', htmlspecialchars('https://xelopat.ru' . $path, ENT_XML1, 'UTF-8'), '</loc></url>', "\n";
}
echo '</urlset>', "\n";
