<?php
declare(strict_types=1);

const TRAVEL_STORY_ELEMENTS = ['p', 'div', 'h3', 'h4', 'strong', 'em', 'u', 'ul', 'ol', 'li', 'blockquote', 'br', 'a', 'span'];

function travel_story_read(string $json): array {
    if (strlen($json) > 180000) throw new InvalidArgumentException('Рассказ слишком длинный.');
    $nodes = json_decode($json, true, 40);
    $budget = 6000;
    return travel_story_nodes($nodes, $budget);
}

function travel_story_nodes(mixed $nodes, int &$budget, int $depth = 0): array {
    if (!is_array($nodes) || !array_is_list($nodes) || $depth > 24) {
        throw new InvalidArgumentException('Не удалось прочитать форматирование рассказа.');
    }
    $out = [];
    foreach ($nodes as $node) {
        if (--$budget < 0) throw new InvalidArgumentException('В рассказе слишком много элементов.');
        if (is_string($node)) { $out[] = $node; continue; }
        $tag = is_array($node) ? ($node['tag'] ?? null) : null;
        if (!in_array($tag, TRAVEL_STORY_ELEMENTS, true)) {
            throw new InvalidArgumentException('В рассказе есть неподдерживаемый элемент.');
        }
        if ($tag === 'br') { $out[] = ['tag' => 'br']; continue; }
        $children = travel_story_nodes($node['children'] ?? [], $budget, $depth + 1);
        $item = ['tag' => $tag, 'children' => $children];
        if ($tag === 'a') {
            $href = $node['href'] ?? null;
            if (!is_string($href) || !preg_match('~^https?://~i', $href) || !filter_var($href, FILTER_VALIDATE_URL)) {
                array_push($out, ...$children);
                continue;
            }
            $item['href'] = $href;
        }
        if ($tag === 'span') {
            if (($node['date'] ?? false) === true) $item['date'] = true;
            $color = $node['color'] ?? null;
            if (is_string($color) && preg_match('/^#[0-9a-f]{6}$/iD', $color)) $item['color'] = strtolower($color);
        }
        $out[] = $item;
    }
    return $out;
}

function travel_story_plain(array $nodes): string {
    $text = '';
    foreach ($nodes as $node) {
        if (is_string($node)) { $text .= $node; continue; }
        if ($node['tag'] === 'br') { $text .= "\n"; continue; }
        $piece = travel_story_plain($node['children']);
        if (($node['date'] ?? false) === true) $piece .= ' · ';
        $text .= $piece;
        if (in_array($node['tag'], ['p', 'div', 'h3', 'h4', 'ul', 'ol', 'li', 'blockquote'], true)) $text .= "\n";
    }
    return $text;
}
