<?php
// /guitar/lib.php — общие функции раздела «Гитара»: страница и JSON-API берут данные отсюда.
declare(strict_types=1);

function guitar_songs(): array {
    return require __DIR__ . '/songs.php';
}

function guitar_find_song(string $slug): ?array {
    foreach (guitar_songs() as $s) {
        if ($s['slug'] === $slug) {
            return $s;
        }
    }
    return null;
}

// Уникальные аккорды песни в порядке появления
function guitar_chords_of(array $song): array {
    $out = [];
    foreach ($song['sections'] as $sec) {
        foreach ($sec['bars'] as $bar) {
            foreach ((array)$bar as $c) {
                if (!in_array($c, $out, true)) {
                    $out[] = $c;
                }
            }
        }
    }
    return $out;
}

// Адрес записи, если файл лежит в /guitar/audio/
function guitar_audio_src(array $song): ?string {
    if (empty($song['audio']['file'])) {
        return null;
    }
    $f = basename((string)$song['audio']['file']);
    return is_file(__DIR__ . '/audio/' . $f) ? '/guitar/audio/' . rawurlencode($f) : null;
}

// Всё, что нужно браузеру: песня, аккорды, бой, темп, структура. Отдаётся как JSON.
function guitar_payload(array $song, bool $withAudio): array {
    $chordDefs = require __DIR__ . '/chords.php';
    $strumDefs = require __DIR__ . '/strums.php';

    $meter = $song['meter'] ?? ['beats' => 4, 'sub' => 2];
    $slots = (int)$meter['beats'] * (int)$meter['sub'];

    $chords = [];
    foreach (guitar_chords_of($song) as $name) {
        if (isset($chordDefs[$name])) {
            $chords[$name] = $chordDefs[$name];
        }
    }

    $strums = [];
    foreach ($strumDefs as $key => $def) {
        if (count($def['pattern']) !== $slots) {
            continue;
        }
        $hits = [];
        foreach ($def['pattern'] as $slot => $t) {
            if ($t === '') {
                continue;
            }
            $hits[] = [
                'slot' => $slot,
                'beat' => $slot / (int)$meter['sub'],
                'type' => $t === 'D' ? 'down' : ($t === 'U' ? 'up' : 'mute'),
            ];
        }
        $strums[$key] = ['title' => $def['title'], 'slots' => $slots, 'hits' => $hits];
    }

    $payload = [
        'slug'     => $song['slug'],
        'title'    => $song['title'],
        'artist'   => $song['artist'],
        'key'      => $song['key'],
        'level'    => $song['level'],
        'note'     => $song['note'],
        'meter'    => ['beats' => (int)$meter['beats'], 'sub' => (int)$meter['sub']],
        'slots'    => $slots,
        'bpm'      => [
            'default'  => (int)($song['bpm'] ?? 60),
            'original' => isset($song['bpm_orig']) ? (int)$song['bpm_orig'] : null,
            'min'      => (int)($song['bpm_min'] ?? 30),
            'max'      => (int)($song['bpm_max'] ?? 140),
            'presets'  => [50, 60, 70, 80],
        ],
        'chords'   => $chords,
        'strums'   => $strums,
        'strum'    => isset($strums[$song['strum'] ?? '']) ? $song['strum'] : (array_key_first($strums) ?? ''),
        'order'    => $song['order'] ?? [],
        'sections' => $song['sections'],
    ];
    if ($withAudio && !empty($song['audio']['timeline'])) {
        $payload['audio'] = [
            'file'     => $song['audio']['file'],
            'duration' => $song['audio']['duration'],
            'timeline' => $song['audio']['timeline'],
        ];
    }
    return $payload;
}

function guitar_json_flags(): int {
    return JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
}
