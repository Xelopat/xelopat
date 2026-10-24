<?php
// /guitar/api.php — JSON для плеера гитары.
//   /guitar/api            — список песен
//   /guitar/api?song=slug  — песня: аккорды, бой, темп (BPM), структура куплет/припев
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

$slug = (string)($_GET['song'] ?? '');

if ($slug === '') {
    $list = [];
    foreach (guitar_songs() as $s) {
        $list[] = [
            'slug'   => $s['slug'],
            'title'  => $s['title'],
            'artist' => $s['artist'],
            'level'  => $s['level'],
            'chords' => guitar_chords_of($s),
        ];
    }
    echo json_encode(['songs' => $list], guitar_json_flags());
    exit;
}

$song = guitar_find_song($slug);
if ($song === null) {
    http_response_code(404);
    echo json_encode(['error' => 'not_found'], guitar_json_flags());
    exit;
}

echo json_encode(guitar_payload($song, guitar_audio_src($song) !== null), guitar_json_flags());
