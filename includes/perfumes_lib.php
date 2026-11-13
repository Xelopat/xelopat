<?php
declare(strict_types=1);

function perf_root(): string { return rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__)), '/\\'); }
function perf_file(): string { return perf_root() . '/data/collections/perfumes.json'; }
function perf_normalize(array $item, string $id): array {
    $item['id'] = $id;
    $item['draft'] = ($item['draft'] ?? false) === true;
    $item['owner'] = (string)($item['owner'] ?? '');
    $item['revision'] = (int)($item['revision'] ?? 0);
    $item['photo_store'] = (string)($item['photo_store'] ?? '');
    return $item;
}

// The legacy file is read only until the first atomic write. It remains a backup.
function perf_load(): array {
    $file = is_file(perf_file()) ? perf_file() : perf_root() . '/perfumes/data.json';
    if (!is_file($file)) return [];
    $data = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) throw new RuntimeException('Не удалось прочитать коллекцию.');
    $items = [];
    foreach ($data['items'] as $id => $it) {
        if (!is_array($it)) throw new RuntimeException('Не удалось прочитать аромат.');
        $items[$id] = perf_normalize($it, (string)$id);
    }
    return $items;
}
function perf_access(array $item, ?array $user): bool {
    return !$item['draft'] || (user_has_role($user, 'editor') && $item['owner'] === (string)$user['id']);
}
function perf_photo_path(string $name): string {
    return preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/D', $name) ? perf_root() . '/data/perfume-photos/' . $name : '';
}
function perf_mutate(callable $fn) {
    $file = perf_file();
    if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0755, true)) throw new RuntimeException('Хранилище недоступно.');
    $lock = fopen($file . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Хранилище недоступно.');
    $tmp = null;
    try {
        $items = perf_load();
        $oldPhotos = array_filter(array_column($items, 'photo_store'));
        $oldLegacy = ['photo_file' => array_filter(array_column($items, 'photo_file')), 'photo_preview' => array_filter(array_column($items, 'photo_preview'))];
        $result = $fn($items);
        $tmp = tempnam(dirname($file), '.perfumes-');
        if (!$tmp || file_put_contents($tmp, json_encode(['items' => (object)$items], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false || !rename($tmp, $file)) throw new RuntimeException('Не удалось сохранить коллекцию.');
        foreach (array_diff($oldPhotos, array_column($items, 'photo_store')) as $name) {
            $path = perf_photo_path($name);
            if ($path && is_file($path)) @unlink($path);
        }
        foreach ($oldLegacy as $field => $files) foreach (array_diff($files, array_column($items, $field)) as $name) {
            if (!preg_match('/^[a-zA-Z0-9._-]{1,160}\.(jpg|jpeg|png|webp)$/iD', $name)) continue;
            $path = perf_root() . '/perfumes/uploads/' . ($field === 'photo_file' ? 'original/' : 'preview/') . $name;
            if (is_file($path)) @unlink($path);
        }
        return $result;
    } finally {
        if ($tmp && is_file($tmp)) unlink($tmp);
        flock($lock, LOCK_UN); fclose($lock);
    }
}
function perf_photo_store(string $data): string {
    if (!preg_match('~^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=]+)$~D', $data, $m)) throw new InvalidArgumentException('Выбери фото JPG, PNG или WebP.');
    $bytes = base64_decode($m[2], true);
    if ($bytes === false || strlen($bytes) > 2 * 1024 * 1024) throw new InvalidArgumentException('Фото должно быть меньше 2 МБ.');
    $info = @getimagesizefromstring($bytes);
    if (!$info || $info['mime'] !== 'image/' . $m[1] || max($info[0], $info[1]) > 6000) throw new InvalidArgumentException('Не удалось прочитать фотографию.');
    $name = bin2hex(random_bytes(16)) . '.' . ($m[1] === 'jpeg' ? 'jpg' : $m[1]);
    $path = perf_photo_path($name);
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0755, true)) throw new RuntimeException('Не удалось сохранить фото.');
    if (file_put_contents($path, $bytes) === false) throw new RuntimeException('Не удалось сохранить фото.');
    return $name;
}
function perf_public(array $it): array {
    $it['photo_url'] = $it['photo_store'] !== '' || !empty($it['photo_file']) ? '/perfumes/photo?id=' . rawurlencode($it['id']) . '&v=' . $it['revision'] : '';
    unset($it['owner'], $it['photo_store']);
    return $it;
}
function perf_save(array $input, array $user): array {
    $id = (string)($input['id'] ?? '');
    if (!preg_match('/^[a-zA-Z0-9._-]{1,48}$/D', $id)) throw new InvalidArgumentException('Некорректная запись.');
    $fields = ['name' => 300, 'brand' => 300, 'description' => 30000, 'notes' => 30000, 'notes_top' => 2000, 'notes_middle' => 2000, 'notes_base' => 2000, 'tags' => 2000];
    foreach ($fields as $field => $limit) {
        if (!is_string($input[$field] ?? null) || mb_strlen($input[$field]) > $limit) throw new InvalidArgumentException('Проверь заполненные поля.');
    }
    $numbers = [];
    foreach (['rating' => [1, 10], 'price' => [0, 999999]] as $field => [$min, $max]) {
        $raw = str_replace(',', '.', trim((string)($input[$field] ?? '')));
        if ($raw === '') { $numbers[$field] = null; continue; }
        if (!is_numeric($raw) || (float)$raw < $min || (float)$raw > $max || ($field === 'price' && (float)$raw !== (float)(int)$raw)) throw new InvalidArgumentException($field === 'rating' ? 'Оценка должна быть от 1 до 10.' : 'Цена должна быть целым числом от 0 до 999999 ₽.');
        $numbers[$field] = $field === 'price' ? (int)$raw : (float)$raw;
    }
    $photo = isset($input['photoData']) && $input['photoData'] !== '' ? perf_photo_store((string)$input['photoData']) : null;
    try {
        return perf_mutate(function (array &$items) use ($id, $input, $fields, $numbers, $user, $photo) {
            $old = $items[$id] ?? null;
            if ($old && !perf_access($old, $user)) throw new RuntimeException('Аромат не найден.', 404);
            if ((int)($input['revision'] ?? 0) !== ($old['revision'] ?? 0)) throw new RuntimeException('Запись изменилась или удалена в другой вкладке. Сохрани свои правки отдельным черновиком.', 409);
            $base = $old ?? [];
            $source = $items[(string)($input['copyFrom'] ?? '')] ?? null;
            if (!$old && $source && perf_access($source, $user)) $base = $source;
            $draft = ($old['draft'] ?? true) && (($input['publish'] ?? false) !== true);
            if (!$draft && trim($input['name']) === '' && (!$old || $old['draft'] || trim((string)($old['name'] ?? '')) !== '')) throw new InvalidArgumentException('Укажи название аромата.');
            $item = perf_normalize($base, $id);
            foreach ($fields as $field => $_) $item[$field] = trim($input[$field]);
            $tags = preg_split('/[,;\n]+/u', $item['tags']);
            $item['tags'] = array_values(array_unique(array_filter(array_map(fn($t) => mb_strtolower(trim($t), 'UTF-8'), $tags))));
            foreach ($numbers as $field => $value) $item[$field] = $value;
            $item['draft'] = $draft;
            $item['owner'] = $old['owner'] ?? (string)$user['id'];
            $item['revision'] = ($old['revision'] ?? 0) + 1;
            $item['created_at'] = $old['created_at'] ?? date('c');
            $item['updated_at'] = date('c');
            if ($photo !== null || ($input['photoData'] ?? null) === '') {
                $item['photo_store'] = $photo ?? '';
                $item['photo_file'] = ''; $item['photo_preview'] = '';
            }
            $items[$id] = $item;
            return $item;
        });
    } catch (Throwable $e) {
        if ($photo !== null) @unlink(perf_photo_path($photo));
        throw $e;
    }
}
