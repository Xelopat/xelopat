<?php
// /guitar/songs.php
// Данные песен для раздела «Гитара».
//
// Формат раздела: ['name' => 'Куплет', 'bars' => ['Am', 'Em', ...]]
// Один элемент bars = один такт (4 доли), один аккорд на такт.
// Тексты песен тут не хранятся — только аккорды и структура.
// Аккорды подобраны по общедоступным любительским разборам (mychords.net) и упрощены.
//
// strum: ключ из STRUMS в guitar.js (easy | six | eight | muted)

return [
    [
        'slug'   => 'gruppa-krovi',
        'title'  => 'Группа крови',
        'artist' => 'Кино',
        'key'    => 'Am',
        'capo'   => 'без каподастра',
        'level'  => 'Очень просто',
        'bpm'    => 60,
        'strum'  => 'easy',
        'note'   => 'Всего 4 аккорда. Куплет и припев отличаются одним аккордом: в куплете Am Em Am G, в припеве Am Em Dm G. Начни с Am ↔ Em — они почти одной формы.',
        'sections' => [
            ['name' => 'Куплет',   'bars' => ['Am', 'Em', 'Am', 'G']],
            ['name' => 'Припев',   'bars' => ['Am', 'Em', 'Dm', 'G']],
            ['name' => 'Проигрыш', 'bars' => ['Am', 'Em']],
        ],
        'source' => 'https://mychords.net/ru/kino/63282-kino-gruppa-krovi.html',
    ],
    [
        'slug'   => 'zvezda-po-imeni-solntse',
        'title'  => 'Звезда по имени Солнце',
        'artist' => 'Кино',
        'key'    => 'Am',
        'capo'   => 'без каподастра',
        'level'  => 'Просто',
        'bpm'    => 60,
        'strum'  => 'muted',
        'note'   => 'Аккорды Am, C, Dm, G. Куплет — круг из четырёх аккордов, повторяется три раза. Классический бой для неё — с приглушкой (✕ — удар по приглушённым струнам ладонью).',
        'sections' => [
            ['name' => 'Вступление', 'bars' => ['Dm', 'Am', 'Dm', 'Am']],
            ['name' => 'Куплет',     'bars' => ['Am', 'C', 'Dm', 'G', 'Am', 'C', 'Dm', 'G', 'Dm', 'Am']],
            ['name' => 'Проигрыш',   'bars' => ['Dm']],
            ['name' => 'Кода',       'bars' => ['Dm', 'Am', 'Dm', 'Am']],
        ],
        'source' => 'https://mychords.net/ru/kino/1090-kino-zvezda-po-imeni-solnce.html',
    ],
    [
        'slug'   => 'vyhoda-net',
        'title'  => 'Выхода нет',
        'artist' => 'Сплин',
        'key'    => 'Em / G',
        'capo'   => 'без каподастра',
        'level'  => 'Просто',
        'bpm'    => 60,
        'strum'  => 'easy',
        'note'   => 'Аккорды C, G, D, Em. Это песня Сплина — если вы имели в виду другую, напишите. Длительность аккордов в куплете примерная: подстрой по записи.',
        'sections' => [
            ['name' => 'Куплет',   'bars' => ['C', 'G', 'D', 'Em', 'C', 'G', 'D', 'Em', 'C', 'G', 'D']],
            ['name' => 'Припев',   'bars' => ['D', 'Em', 'C', 'G', 'D', 'Em', 'C', 'G', 'D', 'C', 'D', 'Em']],
            ['name' => 'Проигрыш', 'bars' => ['Em']],
        ],
        'source' => 'https://mychords.net/uk/splin/3358-splin-vyxoda-net.html',
    ],
];
