<?php
declare(strict_types=1);
require_once __DIR__ . '/collection_lib.php';

const BOOK_STATUSES = ['planned' => 'В планах', 'reading' => 'Читаю', 'read' => 'Прочитано', 'stopped' => 'Не дочитал'];

function books_normalize(array $raw): array {
    $book = coll_normalize_item($raw);
    $book['status'] = isset(BOOK_STATUSES[$raw['status'] ?? '']) ? $raw['status'] : 'planned';
    $book['genres'] = coll_clean_tags($raw['genres'] ?? []);
    $book['finished'] = (string)($raw['finished'] ?? '');
    $book['draft'] = ($raw['draft'] ?? false) === true;
    $book['owner'] = (string)($raw['owner'] ?? '');
    $book['revision'] = (int)($raw['revision'] ?? 0);
    $book['cover'] = (string)($raw['cover'] ?? '');
    return $book;
}

function books_load(): array {
    $path = coll_file('books');
    if (!is_file($path)) return [];
    $data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) throw new RuntimeException('Не удалось прочитать библиотеку.');
    return array_map('books_normalize', $data['items']);
}

// Readers see complete snapshots; writers check revisions under the same lock.
function books_mutate(callable $fn) {
    $file = coll_file('books');
    if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0755, true)) throw new RuntimeException('Не удалось создать хранилище.');
    $lock = fopen($file . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Не удалось открыть хранилище.');
    $tmp = null;
    try {
        $items = books_load();
        $oldCovers = array_filter(array_column($items, 'cover'));
        $result = $fn($items);
        $tmp = tempnam(dirname($file), '.books-');
        if (!$tmp || file_put_contents($tmp, json_encode(['items' => array_values($items)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) === false) throw new RuntimeException('Не удалось сохранить книгу.');
        if (!rename($tmp, $file)) throw new RuntimeException('Не удалось сохранить книгу.');
        foreach (array_diff($oldCovers, array_column($items, 'cover')) as $cover) {
            $path = books_cover_path($cover);
            if ($path && is_file($path)) @unlink($path);
        }
        return $result;
    } finally {
        if ($tmp && is_file($tmp)) unlink($tmp);
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function books_access(array $book, ?array $user): bool {
    return !$book['draft'] || (user_has_role($user, 'editor') && $book['owner'] === (string)$user['id']);
}

function books_cover_path(string $cover): string {
    if (!preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $cover)) return '';
    return coll_root() . '/data/book-covers/' . $cover;
}

function books_store_cover(string $data): string {
    if (!preg_match('~^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=]+)$~D', $data, $m)) throw new InvalidArgumentException('Выбери обложку JPG, PNG или WebP.');
    $bytes = base64_decode($m[2], true);
    if ($bytes === false || strlen($bytes) > 2 * 1024 * 1024) throw new InvalidArgumentException('Обложка должна быть меньше 2 МБ.');
    $info = @getimagesizefromstring($bytes);
    if (!$info || ($info['mime'] ?? '') !== 'image/' . $m[1] || $info[0] > 6000 || $info[1] > 6000) throw new InvalidArgumentException('Не удалось прочитать изображение.');
    $name = bin2hex(random_bytes(16)) . '.' . ($m[1] === 'jpeg' ? 'jpg' : $m[1]);
    $path = books_cover_path($name);
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0755, true)) throw new RuntimeException('Не удалось сохранить обложку.');
    if (file_put_contents($path, $bytes) === false) throw new RuntimeException('Не удалось сохранить обложку.');
    return $name;
}

function books_public(array $book, bool $editor): array {
    $book['coverUrl'] = $book['cover'] !== '' ? '/books?cover=' . rawurlencode($book['id']) . '&v=' . $book['revision'] : '';
    if ($book['coverUrl'] === '') {
        $cover = coll_cover($book);
        $src = (string)($cover['preview'] ?? '');
        if ($src === '' && $cover) $src = $cover['src'];
        if (coll_is_local_path($src) || preg_match('~^https?://~i', $src)) $book['coverUrl'] = $src;
    }
    unset($book['cover'], $book['owner'], $book['legacy_index'], $book['media']);
    if (!$editor) unset($book['revision']);
    return $book;
}

function books_save(array $input, array $user): array {
    $id = (string)($input['id'] ?? '');
    if (!preg_match('/^[a-f0-9]{10,36}$/', $id)) throw new InvalidArgumentException('Некорректная книга.');
    foreach (['title' => 300, 'description' => 300, 'details' => 30000] as $key => $limit) {
        if (!is_string($input[$key] ?? null) || mb_strlen($input[$key]) > $limit) throw new InvalidArgumentException('Поле слишком длинное: ' . $key);
    }
    if (!isset(BOOK_STATUSES[$input['status'] ?? ''])) throw new InvalidArgumentException('Выбери статус чтения.');
    $finished = (string)($input['finished'] ?? '');
    if ($finished !== '') {
        $date = DateTime::createFromFormat('!Y-m-d', $finished);
        if (!$date || $date->format('Y-m-d') !== $finished) throw new InvalidArgumentException('Проверь дату завершения.');
    }
    $publish = ($input['publish'] ?? false) === true;
    if ($publish && (trim($input['title']) === '' || trim($input['description']) === '')) throw new InvalidArgumentException('Для библиотеки нужны название и автор.');
    $newCover = null;
    if (isset($input['coverData']) && $input['coverData'] !== '') $newCover = books_store_cover((string)$input['coverData']);
    try {
        return books_mutate(function (array &$items) use ($input, $id, $user, $publish, $finished, $newCover) {
            $idx = null;
            foreach ($items as $i => $it) if ($it['id'] === $id) { $idx = $i; break; }
            $old = $idx === null ? null : $items[$idx];
            if ($old && !books_access($old, $user)) throw new RuntimeException('Книга не найдена.', 404);
            if ((int)($input['revision'] ?? 0) !== ($old['revision'] ?? 0)) throw new RuntimeException('Книгу уже изменили или удалили в другой вкладке. Твоя версия сохранена в браузере; можно сохранить её отдельным черновиком.', 409);
            $base = $old ?? [];
            if (!$old && !empty($input['copyFrom'])) {
                foreach ($items as $source) if ($source['id'] === $input['copyFrom'] && books_access($source, $user)) { $base = $source; break; }
            }
            $draft = $old ? $old['draft'] && !$publish : !$publish;
            if (!$draft && (trim($input['title']) === '' || trim($input['description']) === '')) throw new InvalidArgumentException('Название и автор не могут быть пустыми.');
            $book = books_normalize($base);
            foreach (['title', 'description', 'details'] as $key) $book[$key] = trim($input[$key]);
            foreach (['genres', 'tags'] as $key) {
                $known = [];
                foreach ($items as $it) if (books_access($it, $user)) foreach ($it[$key] as $tag) $known[coll_lower($tag)] = $tag;
                $book[$key] = array_map(fn($tag) => $known[coll_lower($tag)] ?? $tag, coll_clean_tags($input[$key] ?? []));
            }
            $book['id'] = $id;
            $book['status'] = $input['status'];
            $book['finished'] = $finished;
            $book['draft'] = $draft;
            $book['owner'] = $old['owner'] ?? (string)$user['id'];
            $book['created'] = $old['created'] ?? date('c');
            $book['updated'] = date('c');
            $book['revision'] = ($old['revision'] ?? 0) + 1;
            if ($newCover !== null) { $book['cover'] = $newCover; $book['media'] = []; }
            elseif (array_key_exists('coverData', $input) && $input['coverData'] === '') { $book['cover'] = ''; $book['media'] = []; }
            if ($idx === null) $items[] = $book; else $items[$idx] = $book;
            return $book;
        });
    } catch (Throwable $e) {
        if ($newCover !== null) @unlink(books_cover_path($newCover));
        throw $e;
    }
}
