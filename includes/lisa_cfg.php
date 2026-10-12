<?php
// Настройки страницы «Лиса-Алиса»: data/site_config.json, ключ lisa (правятся в админке)
declare(strict_types=1);

const LA_BLOCKS = [
    'chart'   => 'График по дням',
    'period'  => 'Блок «За период»',
    'months'  => 'Блок «По месяцам»',
    'records' => 'Блок «Рекорды»',
];

function la_config(): array {
    $file = dirname(__DIR__) . '/data/site_config.json';
    $all = is_file($file) ? json_decode((string)file_get_contents($file), true) : [];
    $c = is_array($all) && is_array($all['lisa'] ?? null) ? $all['lisa'] : [];
    $blocks = [];
    foreach (LA_BLOCKS as $k => $_) {
        $blocks[$k] = !isset($c['blocks']) || !is_array($c['blocks']) || !array_key_exists($k, $c['blocks']) || !empty($c['blocks'][$k]);
    }
    $sub = trim((string)($c['subtitle'] ?? ''));
    return [
        'subtitle' => $sub !== '' ? $sub : 'Лучший работник месяца',
        'start_day' => max(1, min(28, (int)($c['start_day'] ?? 1))),
        'blocks' => $blocks,
    ];
}
