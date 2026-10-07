<?php
// Старые ссылки вида /card.php?section=cards&id=3 ведут на новую страницу раздела с открытой записью
declare(strict_types=1);
require_once __DIR__ . '/includes/collection_lib.php';

$map = ['cards' => 'projects', 'projects' => 'projects', 'travels' => 'travel', 'travel' => 'travel', 'photos' => 'photo', 'photo' => 'photo'];
$key = $map[(string)($_GET['section'] ?? '')] ?? 'projects';
$index = (int)($_GET['id'] ?? -1);

$target = coll_def($key)['url'];
foreach (coll_load($key) as $item) {
    if ($item['legacy_index'] === $index) { $target = coll_item_url($key, $item); break; }
}
header('Location: ' . $target, true, 301);
exit;
