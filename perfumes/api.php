<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/auth/lib.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/perfumes_lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
function perf_out(int $status, array $data): void { http_response_code($status); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
$user = auth_current_user();
if (!user_has_role($user, 'editor')) perf_out(403, ['error' => 'Войди как редактор. Копия останется в этом браузере.']);
try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') perf_out(200, ['items' => array_values(array_map('perf_public', array_filter(perf_load(), fn($it) => perf_access($it, $user))))]);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') perf_out(405, ['error' => 'Только POST.']);
    if (!csrf_check((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) perf_out(403, ['error' => 'Сессия устарела. Войди снова; копия останется в этом браузере.']);
    $raw = file_get_contents('php://input', false, null, 0, 3 * 1024 * 1024 + 1);
    if (strlen($raw) > 3 * 1024 * 1024) perf_out(413, ['error' => 'Фото слишком большое.']);
    $input = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($input)) throw new InvalidArgumentException('Не удалось прочитать запись.');
    if (($input['action'] ?? '') === 'save') perf_out(200, ['item' => perf_public(perf_save($input, $user))]);
    if (($input['action'] ?? '') === 'delete') {
        perf_mutate(function (array &$items) use ($input, $user) {
            $id = (string)($input['id'] ?? ''); $item = $items[$id] ?? null;
            if (!$item || !perf_access($item, $user)) throw new RuntimeException('Запись не найдена.', 404);
            if ($item['revision'] !== (int)($input['revision'] ?? -1)) throw new RuntimeException('Запись изменилась. Обнови страницу перед удалением.', 409);
            unset($items[$id]);
        });
        perf_out(200, ['ok' => true]);
    }
    perf_out(400, ['error' => 'Неизвестное действие.']);
} catch (Throwable $e) {
    $code = $e instanceof InvalidArgumentException || $e instanceof JsonException ? 400 : (in_array($e->getCode(), [404, 409], true) ? $e->getCode() : 500);
    perf_out($code, ['error' => $code === 500 ? 'Не удалось сохранить на сервере. Попробуй ещё раз.' : $e->getMessage()]);
}
