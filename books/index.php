<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/auth/lib.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/books_lib.php';
$user = auth_current_user();
$editor = user_has_role($user, 'editor');
header('Cache-Control: private, no-store');

function books_out(int $status, array $data): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
try {
    if (isset($_GET['cover'])) {
        foreach (books_load() as $book) {
            if ($book['id'] !== (string)$_GET['cover'] || !books_access($book, $user)) continue;
            $path = books_cover_path($book['cover']);
            if (!$path || !is_file($path)) break;
            header('Content-Type: image/' . (pathinfo($path, PATHINFO_EXTENSION) === 'jpg' ? 'jpeg' : pathinfo($path, PATHINFO_EXTENSION)));
            header('X-Content-Type-Options: nosniff');
            readfile($path);
            exit;
        }
        http_response_code(404);
        exit;
    }
    if (isset($_GET['api'])) {
        if ($_GET['api'] === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
            $items = array_values(array_filter(books_load(), fn($b) => books_access($b, $user)));
            books_out(200, ['items' => array_map(fn($b) => books_public($b, $editor), $items)]);
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') books_out(405, ['error' => 'Только POST.']);
        if (!$editor) books_out(403, ['error' => 'Войди как редактор.']);
        if (!csrf_check((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) books_out(403, ['error' => 'Сессия устарела. Войди снова; черновик останется в этом браузере.']);
        $raw = file_get_contents('php://input', false, null, 0, 3 * 1024 * 1024 + 1);
        if (strlen($raw) > 3 * 1024 * 1024) books_out(413, ['error' => 'Обложка слишком большая.']);
        $input = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($input)) books_out(400, ['error' => 'Не удалось прочитать запись.']);
        if ($_GET['api'] === 'save') books_out(200, ['item' => books_public(books_save($input, $user), true)]);
        if ($_GET['api'] === 'delete') {
            books_mutate(function (array &$items) use ($input, $user) {
                foreach ($items as $i => $book) {
                    if ($book['id'] !== ($input['id'] ?? '') || !books_access($book, $user)) continue;
                    if ($book['revision'] !== (int)($input['revision'] ?? -1)) throw new RuntimeException('Книга изменилась. Обнови страницу перед удалением.', 409);
                    array_splice($items, $i, 1);
                    return;
                }
                throw new RuntimeException('Книга не найдена.', 404);
            });
            books_out(200, ['ok' => true]);
        }
        books_out(400, ['error' => 'Неизвестное действие.']);
    }
    $items = array_values(array_filter(books_load(), fn($b) => books_access($b, $user)));
} catch (Throwable $e) {
    $code = $e instanceof InvalidArgumentException || $e instanceof JsonException ? 400 : (in_array($e->getCode(), [404, 409], true) ? $e->getCode() : 500);
    if (isset($_GET['api'])) books_out($code, ['error' => $code === 500 ? 'Не удалось сохранить данные на сервере. Попробуй ещё раз.' : $e->getMessage()]);
    http_response_code(503);
    exit('Библиотека временно недоступна. Попробуй обновить страницу позже.');
}
$site_page_title = 'Книги — xelopat';
include $_SERVER['DOCUMENT_ROOT'] . '/header.php';
?>
<link rel="stylesheet" href="/books/books.css?v=<?= filemtime(__DIR__ . '/books.css') ?>">
<main class="page books"><div class="page-wrap">
  <div class="bk-heading"><div><div class="page-label">// личная библиотека</div><h1 class="page-title" data-type="off">Книги</h1></div>
  <?php if ($editor): ?><div class="bk-actions"><button type="button" id="bkDrafts">Черновики <span id="bkDraftCount">0</span></button><button type="button" id="bkAdd" class="bk-primary">+ Добавить книгу</button></div><?php endif; ?></div>
  <div id="bkRecovery" class="bk-notice" hidden></div>
  <div class="bk-toolbar"><label class="bk-search"><span class="bk-sr">Поиск по названию или автору</span><input id="bkSearch" type="search" placeholder="Название или автор" autocomplete="off"></label>
    <label class="bk-sort">Порядок <select id="bkSort"><option value="read">Прочитанные сначала</option><option value="added">Недавно добавленные</option><option value="title">По названию</option><option value="author">По автору</option></select></label></div>
  <nav id="bkStatuses" class="bk-chips" aria-label="Статус чтения"></nav>
  <div class="bk-facets"><details><summary>Жанры</summary><div id="bkGenres" class="bk-chips"></div></details><details><summary>Теги</summary><div id="bkTags" class="bk-chips"></div></details></div>
  <div class="bk-results"><span id="bkCount" role="status"></span><div id="bkApplied" class="bk-chips"></div><button type="button" id="bkReset" hidden>Сбросить</button></div>
  <div id="bkGrid" class="bk-grid"></div><div id="bkEmpty" class="bk-empty" hidden></div>
  <button type="button" id="bkMore" class="bk-more" hidden>Показать ещё</button>
  <noscript>Для поиска и редактирования книг включи JavaScript.</noscript>
</div></main>
<dialog id="bkView" class="bk-dialog"><div class="bk-dialog-head"><span>Книга</span><button type="button" data-close="bkView" aria-label="Закрыть книгу">×</button></div><div id="bkViewContent" class="bk-view-content"></div><?php if ($editor): ?><div class="bk-dialog-foot"><button type="button" id="bkEdit" class="bk-primary">Изменить</button></div><?php endif; ?></dialog>
<?php if ($editor): ?>
<dialog id="bkDraftDialog" class="bk-dialog"><div class="bk-dialog-head"><h2>Черновики</h2><button type="button" data-close="bkDraftDialog" aria-label="Закрыть черновики">×</button></div><p class="bk-hint">Видны только тебе. Продолжай с любого устройства после сохранения на сервере.</p><div id="bkDraftList"></div></dialog>
<dialog id="bkEditor" class="bk-dialog bk-editor"><form id="bkForm">
  <div class="bk-dialog-head"><h2 id="bkEditorTitle">Новая книга</h2><button type="button" id="bkCloseEditor" aria-label="Закрыть редактор">×</button></div>
  <p id="bkDraftHint" class="bk-hint">Это черновик. Добавь книгу в библиотеку, когда будешь готов.</p>
  <div class="bk-editor-top"><div class="bk-cover-control"><button type="button" id="bkPickCover" class="bk-cover-pick" aria-label="Выбрать обложку"><img id="bkCoverPreview" alt="Обложка" hidden><span id="bkCoverLabel">+ Обложка</span></button><input type="file" id="bkCoverFile" accept="image/jpeg,image/png,image/webp" hidden><button type="button" id="bkRemoveCover" class="bk-text-button" hidden>Убрать обложку</button><span class="bk-hint">Можно перетащить или вставить картинку</span></div>
  <div class="bk-fields"><label>Название <input name="title" maxlength="300" placeholder="Например, Дюна" autocomplete="off"></label><label>Автор <input name="description" maxlength="300" placeholder="Фрэнк Герберт" list="bkAuthors" autocomplete="off"></label><datalist id="bkAuthors"></datalist><label>Статус <select name="status"><?php foreach (BOOK_STATUSES as $key => $label): ?><option value="<?= $key ?>"><?= $label ?></option><?php endforeach; ?></select></label><label id="bkDateLabel" hidden>Дата завершения <input name="finished" type="date"><span class="bk-hint">Можно оставить пустой, если не помнишь</span></label></div></div>
  <div class="bk-fields"><div class="bk-tag-field" data-field="genres"><label for="bkGenreInput">Жанры</label><div class="bk-chips bk-edit-chips"></div><div class="bk-tag-entry"><input id="bkGenreInput" placeholder="Найти или добавить жанр" maxlength="40" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="bkGenreSuggestions"><button type="button" class="bk-tag-add">Добавить</button></div><div id="bkGenreSuggestions" class="bk-suggestions" role="listbox" hidden></div></div>
  <details id="bkExtra"><summary>Заметка и свои теги</summary><label>Заметка <textarea name="details" rows="4" maxlength="30000" placeholder="Что запомнилось, кому посоветовал бы…"></textarea></label><div class="bk-tag-field" data-field="tags"><label for="bkTagInput">Свои теги</label><div class="bk-chips bk-edit-chips"></div><div class="bk-tag-entry"><input id="bkTagInput" placeholder="Например, Любимое" maxlength="40" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="bkTagSuggestions"><button type="button" class="bk-tag-add">Добавить</button></div><div id="bkTagSuggestions" class="bk-suggestions" role="listbox" hidden></div></div></details></div>
  <div id="bkSaveStatus" class="bk-save-status" role="status" aria-live="polite"></div><div class="bk-actions"><button type="button" id="bkRetry" hidden>Повторить сохранение</button><button type="button" id="bkCopy" hidden>Сохранить отдельным черновиком</button></div>
  <div class="bk-dialog-foot"><button type="button" id="bkDelete" class="bk-danger">Удалить черновик</button><div class="bk-actions"><button type="button" id="bkDone">Закрыть</button><button type="submit" id="bkPublish" class="bk-primary">Добавить в библиотеку</button></div></div>
</form></dialog>
<?php endif; ?>
<script id="bkData" type="application/json"><?= json_encode(['items' => array_map(fn($b) => books_public($b, $editor), $items), 'editor' => $editor, 'user' => $editor ? (string)$user['id'] : '', 'csrf' => $editor ? csrf_token() : '', 'statuses' => BOOK_STATUSES], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="/books/books.js?v=<?= filemtime(__DIR__ . '/books.js') ?>" defer></script>
