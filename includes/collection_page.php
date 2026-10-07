<?php
// Страница раздела-коллекции. Перед подключением задать $collection_key.
// Смотреть могут все, добавлять и править — админы прямо на странице.
declare(strict_types=1);

require_once __DIR__ . '/collection_lib.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/auth/lib.php';

$coll_key = (string)($collection_key ?? '');
$coll = coll_def($coll_key);
$coll_user = auth_current_user();
$coll_is_admin = user_has_role($coll_user, 'editor');

const COLL_MONTHS_GEN = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];

function coll_h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function coll_date_human(string $d): string {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) return $d;
    return (int)$m[3] . ' ' . COLL_MONTHS_GEN[(int)$m[2] - 1] . ' ' . $m[1];
}

function coll_api_out(int $code, array $payload): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ---------------- API для админа ---------------- */

if (isset($_GET['api'])) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') coll_api_out(405, ['error' => 'Только POST']);
    if (!$coll_is_admin) coll_api_out(403, ['error' => 'Нужно войти как админ']);

    // Если запрос больше post_max_size, PHP молча отдаёт пустой $_POST
    if (!$_POST && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        coll_api_out(413, ['error' => 'Файлы слишком большие для сервера: за раз можно до ' . round(coll_upload_limit() / 1048576) . ' МБ.']);
    }
    if (!csrf_check((string)($_POST['csrf'] ?? ''))) coll_api_out(400, ['error' => 'Сессия устарела, обнови страницу.']);

    $api = (string)$_GET['api'];

    if ($api === 'delete') {
        $id = (string)($_POST['id'] ?? '');
        $removed = coll_mutate($coll_key, function (array &$items) use ($id) {
            foreach ($items as $i => $it) {
                if ($it['id'] === $id) { array_splice($items, $i, 1); return $it; }
            }
            return null;
        });
        if (!$removed) coll_api_out(404, ['error' => 'Запись не найдена']);
        foreach ($removed['media'] as $m) coll_delete_media_files($coll_key, $m);
        coll_api_out(200, ['ok' => true]);
    }

    if ($api === 'save') {
        $errors = [];
        $id = (string)($_POST['id'] ?? '');
        $title = trim((string)($_POST['title'] ?? ''));
        $date = trim((string)($_POST['date'] ?? ''));
        $link = trim((string)($_POST['link'] ?? ''));

        if ($title === '') coll_api_out(400, ['error' => 'Нужно название.']);
        if ($date !== '') {
            $dt = DateTime::createFromFormat('!Y-m-d', $date);
            if (!$dt || $dt->format('Y-m-d') !== $date) coll_api_out(400, ['error' => 'Некорректная дата.']);
        }
        if ($link !== '' && !preg_match('~^https?://~i', $link)) $link = 'https://' . $link;
        if ($link !== '' && !filter_var($link, FILTER_VALIDATE_URL)) coll_api_out(400, ['error' => 'Ссылка выглядит неправильно.']);

        // Порядок существующих медиа (и добавленные по ссылке) приходит из формы
        $keep = json_decode((string)($_POST['keep'] ?? '[]'), true);
        $keep = is_array($keep) ? $keep : [];

        $uploaded = coll_handle_uploads($coll_key, $_FILES['files'] ?? null, $errors);

        $result = coll_mutate($coll_key, function (array &$items) use ($id, $title, $date, $link, $keep, $uploaded) {
            $idx = null;
            foreach ($items as $i => $it) if ($it['id'] === $id) { $idx = $i; break; }
            if ($id !== '' && $idx === null) return ['error' => 'Запись не найдена (возможно, её удалили).'];

            $old = $idx === null ? [] : $items[$idx]['media'];
            $bySrc = [];
            foreach ($old as $m) $bySrc[$m['src']] = $m;

            $media = [];
            $usedNew = [];
            foreach ($keep as $k) {
                // {new: N} — N-й загруженный в этом запросе файл, на своём месте в порядке
                if (isset($k['new'])) {
                    $n = (int)$k['new'];
                    if (isset($uploaded[$n]) && !isset($usedNew[$n])) { $media[] = $uploaded[$n]; $usedNew[$n] = true; }
                    continue;
                }
                $src = trim((string)($k['src'] ?? ''));
                if ($src === '') continue;
                if (isset($bySrc[$src])) { $media[] = $bySrc[$src]; unset($bySrc[$src]); continue; }
                // Новая внешняя ссылка
                if (preg_match('~^https?://~i', $src) && filter_var($src, FILTER_VALIDATE_URL)) {
                    $media[] = ['type' => coll_media_kind($src), 'src' => $src, 'preview' => ''];
                }
            }
            foreach ($uploaded as $n => $m) if (!isset($usedNew[$n])) $media[] = $m;

            $item = coll_normalize_item([
                'id' => $idx === null ? coll_new_id() : $items[$idx]['id'],
                'title' => $title,
                'date' => $date,
                'description' => trim((string)($_POST['description'] ?? '')),
                'details' => trim((string)($_POST['details'] ?? '')),
                'tags' => (string)($_POST['tags'] ?? ''),
                'link' => $link,
                'fit' => (string)($_POST['fit'] ?? 'auto'),
                'media' => $media,
                'created' => $idx === null ? date('c') : $items[$idx]['created'],
                'updated' => date('c'),
                'legacy_index' => $idx === null ? null : $items[$idx]['legacy_index'],
            ]);
            if ($idx === null) $items[] = $item; else $items[$idx] = $item;
            return ['item' => $item, 'removed' => array_values($bySrc)];
        });

        if (isset($result['error'])) {
            foreach ($uploaded as $m) coll_delete_media_files($coll_key, $m);
            coll_api_out(409, ['error' => $result['error']]);
        }
        foreach ($result['removed'] as $m) coll_delete_media_files($coll_key, $m);
        coll_api_out(200, ['ok' => true, 'id' => $result['item']['id'], 'warnings' => $errors]);
    }

    coll_api_out(400, ['error' => 'Неизвестное действие']);
}

/* ---------------- страница ---------------- */

$coll_items = coll_sorted(coll_load($coll_key));
$coll_js_items = array_map(function ($it) {
    $it['date_human'] = coll_date_human($it['date']);
    unset($it['legacy_index'], $it['created'], $it['updated']);
    return $it;
}, $coll_items);

$site_page_title = $coll['title'] . ' — xelopat';
include $_SERVER['DOCUMENT_ROOT'] . '/header.php';
$coll_csrf = $coll_is_admin ? csrf_token() : '';
$coll_gallery = $coll['layout'] === 'gallery';
?>
<style>
  /* В разделах ссылки без подчёркивания: карточки целиком кликабельные */
  .page a{ text-decoration:none; }
  .co-head{ display:flex; align-items:flex-end; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-bottom:18px; }
  .co-head .page-title{ margin:0; }
  .co-tools{ display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
  .co-search{
    width:240px; max-width:100%; box-sizing:border-box;
    background:var(--panel); border:1px solid var(--line); border-radius:9px;
    padding:9px 12px; color:var(--text); font:inherit; font-size:14px; outline:none;
  }
  .co-search:focus{ border-color:var(--green); }
  .co-count{ font-family:var(--mono); font-size:11px; color:var(--muted); }
  .co-add{ border-color:var(--accent); color:var(--accent); }

  /* карточки */
  .co-grid{ display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:14px; }
  .co-card{
    display:flex; flex-direction:column; text-align:left;
    background:var(--panel); border:1px solid var(--line); border-radius:14px; overflow:hidden;
    color:inherit; font:inherit; padding:0; cursor:pointer;
    transition:border-color .16s ease, transform .16s ease;
  }
  .co-card:hover{ border-color:#4a4a5c; transform:translateY(-2px); }
  .co-cover{ position:relative; aspect-ratio:16 / 10; background:var(--panel-2); overflow:hidden; }
  .co-cover img, .co-cover video{ width:100%; height:100%; object-fit:cover; display:block; }
  /* По ширине: картинка целиком, высота своя. По высоте: рамка как обычно, картинка вписана без обрезки */
  .co-cover.fit-width{ aspect-ratio:auto; }
  .co-cover.fit-width img, .co-cover.fit-width video{ height:auto; }
  .co-cover.fit-height img, .co-cover.fit-height video{ object-fit:contain; }
  .co-cover-empty{ width:100%; height:100%; display:grid; place-items:center; color:#4a4a5c; font-family:var(--mono); font-size:28px; }
  .co-badge{
    position:absolute; right:8px; bottom:8px;
    background:rgba(21,21,24,.82); border:1px solid rgba(255,255,255,.08); border-radius:7px;
    padding:3px 8px; font-size:11px; font-family:var(--mono); color:var(--text);
  }
  .co-body{ padding:14px; display:flex; flex-direction:column; gap:6px; flex:1; }
  .co-date{ font-family:var(--mono); font-size:11px; color:var(--green); }
  .co-title{ font-size:17px; font-weight:700; line-height:1.3; }
  .co-desc{ color:var(--text-2); font-size:14px; line-height:1.55; display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden; }
  .co-tags{ display:flex; flex-wrap:wrap; gap:5px; margin-top:auto; padding-top:6px; }
  .co-tag{ font-size:11px; padding:3px 8px; border-radius:999px; background:var(--panel-2); border:1px solid var(--line); color:var(--text-2); }

  /* галерея (фото) */
  .co-gallery{ columns:3 260px; column-gap:12px; }
  .co-shot{
    break-inside:avoid; margin:0 0 12px; display:block; width:100%;
    position:relative; border:none; padding:0; border-radius:12px; overflow:hidden;
    background:var(--panel); cursor:pointer; font:inherit; color:inherit;
  }
  .co-shot img, .co-shot video{ width:100%; display:block; }
  .co-shot .co-cover-empty{ aspect-ratio:4 / 3; }
  .co-shot-cap{
    position:absolute; inset:auto 0 0 0; padding:28px 12px 10px; text-align:left;
    background:linear-gradient(180deg, transparent, rgba(0,0,0,.75));
    opacity:0; transition:opacity .16s ease;
  }
  .co-shot:hover .co-shot-cap, .co-shot:focus-visible .co-shot-cap{ opacity:1; }
  .co-shot-cap b{ display:block; font-size:14px; }
  .co-shot-cap span{ font-size:11px; font-family:var(--mono); color:#d9d9e0; }
  @media (hover:none){ .co-shot-cap{ opacity:1; } }

  .co-empty{ padding:40px 0; text-align:center; color:var(--muted); }

  /* модалки */
  .co-modal{ position:fixed; inset:0; z-index:2000; display:none; }
  .co-modal.open{ display:block; }
  .co-backdrop{ position:absolute; inset:0; background:rgba(8,8,10,.78); backdrop-filter:blur(3px); }
  .co-box{
    position:relative; margin:4vh auto; width:min(1100px, calc(100vw - 24px)); max-height:92vh;
    background:var(--panel); border:1px solid var(--line); border-radius:16px;
    display:flex; flex-direction:column; overflow:hidden; box-shadow:var(--shadow);
  }
  .co-box.narrow{ width:min(720px, calc(100vw - 24px)); }
  .co-x{
    position:absolute; right:10px; top:10px; z-index:3; width:36px; height:36px; border-radius:10px;
    border:1px solid var(--line); background:rgba(21,21,24,.85); color:var(--text); font-size:20px; line-height:1; cursor:pointer;
  }
  .co-x:hover{ border-color:var(--accent); color:var(--accent); }

  .co-view{ display:grid; grid-template-columns:minmax(0,1.6fr) minmax(0,1fr); min-height:0; overflow:auto; }
  .co-stage{ background:#0e0e11; display:flex; flex-direction:column; min-height:0; }
  .co-main{ position:relative; flex:1; min-height:300px; display:grid; place-items:center; }
  .co-main img, .co-main video{ max-width:100%; max-height:70vh; display:block; }
  .co-arrow{
    position:absolute; top:50%; transform:translateY(-50%); width:40px; height:40px; border-radius:50%;
    border:1px solid rgba(255,255,255,.15); background:rgba(21,21,24,.7); color:#fff; font-size:20px; cursor:pointer;
  }
  .co-arrow:hover{ border-color:var(--accent); color:var(--accent); }
  .co-arrow.prev{ left:10px; } .co-arrow.next{ right:10px; }
  .co-thumbs{ display:flex; gap:6px; padding:8px; overflow-x:auto; }
  .co-thumbs button{ flex:none; width:64px; height:48px; padding:0; border-radius:7px; overflow:hidden; border:2px solid transparent; background:var(--panel-2); cursor:pointer; opacity:.6; }
  .co-thumbs button.on{ border-color:var(--accent); opacity:1; }
  .co-thumbs img, .co-thumbs video{ width:100%; height:100%; object-fit:cover; display:block; }
  .co-thumbs .vid{ display:grid; place-items:center; color:var(--text); font-size:16px; }

  .co-info{ padding:22px 20px; display:flex; flex-direction:column; gap:10px; }
  .co-info h2{ margin:0; font-size:24px; line-height:1.25; padding-right:30px; }
  .co-info .co-desc{ -webkit-line-clamp:unset; display:block; color:var(--text); }
  .co-details{ color:var(--text-2); font-size:14px; line-height:1.65; white-space:pre-wrap; word-wrap:break-word; }
  .co-details a, .co-link{ color:var(--green); }
  .co-actions{ display:flex; gap:8px; flex-wrap:wrap; margin-top:6px; }
  .co-del:hover{ border-color:var(--danger); color:var(--danger); }
  .co-view.no-media{ grid-template-columns:1fr; }
  .co-view.no-media .co-stage{ display:none; }

  /* форма */
  .co-form{ padding:20px; overflow:auto; display:grid; gap:12px; }
  .co-form h2{ margin:0 0 4px; font-size:20px; }
  .co-row{ display:grid; grid-template-columns:minmax(0,1fr) 180px; gap:10px; }
  .co-form textarea{ min-height:70px; resize:vertical; }
  .co-form textarea[name="details"]{ min-height:130px; }
  .co-hint{ font-size:12px; color:var(--muted); }
  .co-media{ display:grid; grid-template-columns:repeat(auto-fill, minmax(110px, 1fr)); gap:8px; }
  .co-mi{ position:relative; aspect-ratio:1; border-radius:9px; overflow:hidden; background:var(--panel-2); border:1px solid var(--line); }
  .co-mi img, .co-mi video{ width:100%; height:100%; object-fit:cover; display:block; }
  .co-mi.new{ border-color:var(--green); }
  .co-mi .cover{ position:absolute; left:5px; top:5px; font-size:10px; font-family:var(--mono); background:var(--accent); color:#1b1606; border-radius:5px; padding:2px 5px; }
  .co-mi .ctl{ position:absolute; inset:auto 0 0 0; display:flex; justify-content:space-between; padding:4px; background:linear-gradient(180deg, transparent, rgba(0,0,0,.7)); }
  .co-mi .ctl button{ width:26px; height:26px; border-radius:6px; border:none; background:rgba(21,21,24,.85); color:#fff; cursor:pointer; font-size:13px; }
  .co-mi .ctl button:hover{ color:var(--accent); }
  .co-mi .ctl .rm:hover{ color:var(--danger); }
  .co-mi .vid{ width:100%; height:100%; display:grid; place-items:center; font-size:12px; color:var(--text-2); padding:6px; text-align:center; word-break:break-all; }
  .co-drop{
    border:1px dashed #4a4a5c; border-radius:10px; padding:16px; text-align:center; color:var(--text-2); font-size:14px; cursor:pointer;
  }
  .co-mi{ cursor:grab; }
  .co-mi.dragging{ opacity:.4; }
  .co-mi.drop-here{ outline:2px solid var(--accent); outline-offset:2px; }
  #coFormModal.dropping .co-box{ outline:2px dashed var(--green); outline-offset:-8px; }
  #coFormModal.dropping .co-drop{ border-color:var(--green); background:rgba(97,209,173,.08); color:var(--text); }
  .co-urlrow{ display:flex; gap:8px; }
  .co-urlrow input{ flex:1; }
  .co-progress{ height:4px; border-radius:2px; background:var(--panel-2); overflow:hidden; display:none; }
  .co-progress i{ display:block; height:100%; width:0; background:var(--green); transition:width .2s; }
  .co-err{ color:var(--danger); font-size:13px; min-height:18px; }
  .co-save{ background:var(--accent); color:#1b1606; border-color:var(--accent); }
  .co-save:hover{ color:#1b1606; filter:brightness(1.08); }
  .co-save:disabled{ opacity:.6; cursor:default; }

  @media (max-width: 760px){
    .co-view{ grid-template-columns:1fr; }
    .co-main{ min-height:220px; }
    .co-main img, .co-main video{ max-height:52vh; }
    .co-box{ margin:0; width:100vw; max-height:100vh; height:100vh; border-radius:0; border:none; }
    .co-box.narrow{ width:100vw; }
    .co-row{ grid-template-columns:1fr; }
    .co-search{ width:100%; }
    .co-tools{ width:100%; }
    .co-tools .co-search{ flex:1; }
  }
</style>

<main class="page">
  <div class="page-wrap">
    <div class="co-head">
      <div>
        <div class="page-label"><?= coll_h($coll['label']) ?></div>
        <h1 class="page-title"><?= coll_h($coll['title']) ?></h1>
      </div>
      <div class="co-tools">
        <?php if (count($coll_items) > 4): ?>
          <input class="co-search" type="search" id="coSearch" placeholder="Поиск" autocomplete="off">
        <?php endif; ?>
        <?php if ($coll_is_admin): ?>
          <button class="btn co-add" type="button" id="coAdd">+ Добавить</button>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!$coll_items): ?>
      <div class="co-empty"><?= coll_h($coll['empty']) ?><?= $coll_is_admin ? ' Нажми «Добавить».' : '' ?></div>
    <?php endif; ?>

    <div class="<?= $coll_gallery ? 'co-gallery' : 'co-grid' ?>" id="coList">
      <?php foreach ($coll_items as $it): ?>
        <?php
          $cover = coll_cover($it);
          $search = coll_lower($it['title'] . ' ' . $it['description'] . ' ' . $it['details'] . ' ' . implode(' ', $it['tags']));
          $count = count($it['media']);
        ?>
        <?php if ($coll_gallery): ?>
          <a class="co-shot" href="<?= coll_h(coll_item_url($coll_key, $it)) ?>" data-id="<?= coll_h($it['id']) ?>" data-search="<?= coll_h($search) ?>">
            <?php if ($cover && $cover['type'] === 'image'): ?>
              <img src="<?= coll_h($cover['preview'] ?: $cover['src']) ?>" alt="<?= coll_h($it['title']) ?>" loading="lazy">
            <?php elseif ($cover): ?>
              <video src="<?= coll_h($cover['src']) ?>#t=0.1" preload="metadata" muted playsinline></video>
            <?php else: ?>
              <div class="co-cover-empty">◇</div>
            <?php endif; ?>
            <?php if ($count > 1): ?><span class="co-badge"><?= $count ?></span><?php endif; ?>
            <span class="co-shot-cap"><b><?= coll_h($it['title']) ?></b><?php if ($it['date']): ?><span><?= coll_h(coll_date_human($it['date'])) ?></span><?php endif; ?></span>
          </a>
        <?php else: ?>
          <a class="co-card" href="<?= coll_h(coll_item_url($coll_key, $it)) ?>" data-id="<?= coll_h($it['id']) ?>" data-search="<?= coll_h($search) ?>">
            <div class="co-cover fit-<?= coll_h($it['fit']) ?>">
              <?php if ($cover && $cover['type'] === 'image'): ?>
                <img src="<?= coll_h($cover['preview'] ?: $cover['src']) ?>" alt="<?= coll_h($it['title']) ?>" loading="lazy">
              <?php elseif ($cover): ?>
                <video src="<?= coll_h($cover['src']) ?>#t=0.1" preload="metadata" muted playsinline></video>
              <?php else: ?>
                <div class="co-cover-empty">◇</div>
              <?php endif; ?>
              <?php if ($count > 1): ?><span class="co-badge"><?= $count ?> файл<?= $count % 10 >= 2 && $count % 10 <= 4 && ($count % 100 < 10 || $count % 100 >= 20) ? 'а' : 'ов' ?></span><?php endif; ?>
            </div>
            <div class="co-body">
              <?php if ($it['date']): ?><div class="co-date"><?= coll_h(coll_date_human($it['date'])) ?></div><?php endif; ?>
              <div class="co-title"><?= coll_h($it['title']) ?></div>
              <?php if ($it['description'] !== ''): ?><div class="co-desc"><?= coll_h($it['description']) ?></div><?php endif; ?>
              <?php if ($it['tags']): ?>
                <div class="co-tags"><?php foreach ($it['tags'] as $t): ?><span class="co-tag"><?= coll_h($t) ?></span><?php endforeach; ?></div>
              <?php endif; ?>
            </div>
          </a>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
    <div class="co-empty" id="coNothing" hidden>Ничего не найдено.</div>
  </div>
</main>

<!-- Просмотр -->
<div class="co-modal" id="coViewModal" aria-hidden="true">
  <div class="co-backdrop" data-close></div>
  <div class="co-box" role="dialog" aria-modal="true">
    <button class="co-x" type="button" data-close aria-label="Закрыть">×</button>
    <div class="co-view" id="coView">
      <div class="co-stage">
        <div class="co-main" id="coMain"></div>
        <div class="co-thumbs" id="coThumbs"></div>
      </div>
      <div class="co-info">
        <div class="co-date" id="coVDate"></div>
        <h2 id="coVTitle"></h2>
        <div class="co-tags" id="coVTags" style="margin-top:0;padding-top:0"></div>
        <div class="co-desc" id="coVDesc"></div>
        <div class="co-details" id="coVDetails"></div>
        <div class="co-actions">
          <a class="btn" id="coVLink" target="_blank" rel="noopener" hidden>Открыть ссылку ↗</a>
          <?php if ($coll_is_admin): ?>
            <button class="btn" type="button" id="coEdit">Изменить</button>
            <button class="btn co-del" type="button" id="coDelete">Удалить</button>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if ($coll_is_admin): ?>
<!-- Форма -->
<div class="co-modal" id="coFormModal" aria-hidden="true">
  <div class="co-backdrop" data-close></div>
  <div class="co-box narrow" role="dialog" aria-modal="true">
    <button class="co-x" type="button" data-close aria-label="Закрыть">×</button>
    <form class="co-form" id="coForm" autocomplete="off">
      <h2 id="coFTitle">Добавить</h2>
      <div class="co-row">
        <div class="field"><label for="coTitle">Название</label><input id="coTitle" name="title" required maxlength="200"></div>
        <div class="field"><label for="coDate">Дата</label><input id="coDate" name="date" type="date"></div>
      </div>
      <div class="field"><label for="coDescIn">Коротко (видно на карточке)</label><textarea id="coDescIn" name="description" maxlength="600"></textarea></div>
      <div class="field"><label for="coDetailsIn">Подробно</label><textarea id="coDetailsIn" name="details"></textarea></div>
      <div class="co-row">
        <div class="field"><label for="coTags">Теги через запятую</label><input id="coTags" name="tags"></div>
        <div class="field"><label for="coLinkIn">Ссылка</label><input id="coLinkIn" name="link" placeholder="https://"></div>
      </div>
      <div class="field">
        <label for="coFit">Обложка на карточке</label>
        <select id="coFit" name="fit">
          <option value="auto">Автоматически: заполнить рамку</option>
          <option value="width">По ширине: фото целиком, высота своя</option>
          <option value="height">По высоте: рамка обычная, фото целиком</option>
        </select>
      </div>

      <div class="field">
        <label>Фото и видео</label>
        <div class="co-media" id="coMedia"></div>
        <div class="co-hint" style="margin:6px 0 8px">Первый файл становится обложкой. Порядок меняется перетаскиванием или стрелками.</div>
        <div class="co-drop" id="coDrop">Перетащи файлы в окно, вставь Ctrl+V или нажми, чтобы выбрать<br><span class="co-hint">JPG, PNG, WEBP, GIF, MP4, WEBM, MOV. За раз до <?= round(coll_upload_limit() / 1048576) ?> МБ</span></div>
        <input type="file" id="coFiles" multiple accept="image/*,video/mp4,video/webm,video/quicktime" hidden>
        <div class="co-urlrow" style="margin-top:8px">
          <input id="coUrl" placeholder="или ссылка на картинку/видео" class="co-search" style="width:auto">
          <button class="btn" type="button" id="coUrlAdd">Добавить</button>
        </div>
      </div>

      <div class="co-progress" id="coProgress"><i></i></div>
      <div class="co-err" id="coErr"></div>
      <div class="co-actions" style="justify-content:flex-end">
        <button class="btn" type="button" data-close>Отмена</button>
        <button class="btn co-save" type="submit" id="coSave">Сохранить</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
(function () {
  const KEY = <?= json_encode($coll_key) ?>;
  const BASE = <?= json_encode($coll['url']) ?>;
  const ITEMS = <?= json_encode($coll_js_items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  const IS_ADMIN = <?= $coll_is_admin ? 'true' : 'false' ?>;
  const CSRF = <?= json_encode($coll_csrf) ?>;
  const byId = Object.fromEntries(ITEMS.map((it) => [it.id, it]));
  const $ = (id) => document.getElementById(id);

  function el(tag, attrs, text) {
    const n = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs || {})) n.setAttribute(k, v);
    if (text != null) n.textContent = text;
    return n;
  }

  // Текст с кликабельными ссылками, без innerHTML
  function linkify(container, text) {
    container.textContent = '';
    const re = /(https?:\/\/[^\s<]+)/g;
    let last = 0, m;
    while ((m = re.exec(text))) {
      container.append(text.slice(last, m.index));
      const a = el('a', { href: m[1], target: '_blank', rel: 'noopener' }, m[1]);
      container.append(a);
      last = m.index + m[1].length;
    }
    container.append(text.slice(last));
  }

  function mediaNode(m, thumb) {
    if (m.type === 'video') {
      if (thumb) { const d = el('div', { class: 'vid' }, '▶'); return d; }
      return el('video', { src: m.src, controls: '', playsinline: '', preload: 'metadata' });
    }
    return el('img', { src: thumb && m.preview ? m.preview : m.src, alt: '', loading: thumb ? 'lazy' : 'eager' });
  }

  /* ---------- модалки ---------- */
  let openModalEl = null;
  function openModal(m) { m.classList.add('open'); m.setAttribute('aria-hidden', 'false'); document.body.style.overflow = 'hidden'; openModalEl = m; }
  function closeModal(m) {
    m.classList.remove('open'); m.setAttribute('aria-hidden', 'true');
    m.querySelectorAll('video').forEach((v) => v.pause());
    if (!document.querySelector('.co-modal.open')) document.body.style.overflow = '';
    openModalEl = document.querySelector('.co-modal.open');
    if (m.id === 'coViewModal') history.replaceState(null, '', BASE);
  }
  document.querySelectorAll('.co-modal').forEach((m) => m.addEventListener('click', (e) => { if (e.target.closest('[data-close]')) closeModal(m); }));

  /* ---------- просмотр ---------- */
  let current = null, mediaIdx = 0;

  function showMedia(i) {
    const media = current.media;
    mediaIdx = (i + media.length) % media.length;
    const main = $('coMain');
    main.querySelectorAll('video').forEach((v) => v.pause());
    main.textContent = '';
    main.append(mediaNode(media[mediaIdx], false));
    if (media.length > 1) {
      const p = el('button', { class: 'co-arrow prev', type: 'button', 'aria-label': 'Назад' }, '‹');
      const n = el('button', { class: 'co-arrow next', type: 'button', 'aria-label': 'Вперёд' }, '›');
      p.onclick = () => showMedia(mediaIdx - 1);
      n.onclick = () => showMedia(mediaIdx + 1);
      main.append(p, n);
    }
    $('coThumbs').querySelectorAll('button').forEach((b, k) => b.classList.toggle('on', k === mediaIdx));
  }

  function openView(id, push) {
    const it = byId[id];
    if (!it) return;
    current = it;
    $('coView').classList.toggle('no-media', !it.media.length);
    $('coVDate').textContent = it.date_human || '';
    $('coVTitle').textContent = it.title;
    const tags = $('coVTags'); tags.textContent = '';
    it.tags.forEach((t) => tags.append(el('span', { class: 'co-tag' }, t)));
    $('coVDesc').textContent = it.description;
    linkify($('coVDetails'), it.details);
    const link = $('coVLink');
    link.hidden = !it.link;
    if (it.link) link.href = it.link;

    const thumbs = $('coThumbs'); thumbs.textContent = '';
    thumbs.hidden = it.media.length < 2;
    it.media.forEach((m, k) => {
      const b = el('button', { type: 'button', 'aria-label': 'Файл ' + (k + 1) });
      b.append(mediaNode(m, true));
      b.onclick = () => showMedia(k);
      thumbs.append(b);
    });
    if (it.media.length) showMedia(0);
    openModal($('coViewModal'));
    if (push !== false) history.replaceState(null, '', BASE + '?item=' + encodeURIComponent(id));
  }

  $('coList').addEventListener('click', (e) => {
    const a = e.target.closest('[data-id]');
    if (!a || e.ctrlKey || e.metaKey || e.shiftKey) return;
    e.preventDefault();
    openView(a.dataset.id);
  });

  document.addEventListener('keydown', (e) => {
    if (!openModalEl) return;
    if (e.key === 'Escape') closeModal(openModalEl);
    if (openModalEl.id === 'coViewModal' && current && current.media.length > 1 && !e.target.closest('input,textarea')) {
      if (e.key === 'ArrowLeft') showMedia(mediaIdx - 1);
      if (e.key === 'ArrowRight') showMedia(mediaIdx + 1);
    }
  });

  // Свайп по фото на телефоне
  let touchX = null;
  $('coMain').addEventListener('touchstart', (e) => { touchX = e.touches[0].clientX; }, { passive: true });
  $('coMain').addEventListener('touchend', (e) => {
    if (touchX == null || !current || current.media.length < 2) return;
    const dx = e.changedTouches[0].clientX - touchX;
    if (Math.abs(dx) > 50) showMedia(mediaIdx + (dx < 0 ? 1 : -1));
    touchX = null;
  });

  /* ---------- поиск ---------- */
  const search = $('coSearch');
  if (search) search.addEventListener('input', () => {
    const q = search.value.trim().toLowerCase();
    let shown = 0;
    document.querySelectorAll('#coList [data-id]').forEach((n) => {
      const ok = !q || n.dataset.search.includes(q);
      n.hidden = !ok;
      if (ok) shown++;
    });
    $('coNothing').hidden = shown > 0;
  });

  const startId = new URLSearchParams(location.search).get('item');
  if (startId) openView(startId, false);

  if (!IS_ADMIN) return;

  /* ---------- редактирование ---------- */
  const form = $('coForm');
  const formModal = $('coFormModal');
  let editing = null;   // id или null для новой записи
  // Единый список в нужном порядке: сохранённые файлы/ссылки и новые, ещё не загруженные
  // {kind:'kept', m:{src,type,preview}} | {kind:'new', file, url, type, ready}
  let media = [];
  let dragIndex = null;

  const MAX_SIDE = 2560;
  const VIDEO_RE = /\.(mp4|webm|mov|m4v)$/i;
  const IMAGE_RE = /\.(jpe?g|png|webp|gif)$/i;

  function kindOf(file) {
    if (file.type.startsWith('video/') || VIDEO_RE.test(file.name)) return 'video';
    if (file.type.startsWith('image/') || IMAGE_RE.test(file.name)) return 'image';
    return null;
  }

  // Большие фото уменьшаем в браузере: загрузка и сохранение идут в разы быстрее,
  // а заодно учитывается поворот с телефона
  async function shrink(file) {
    if (!/^image\/(jpeg|png|webp)$/.test(file.type) || !window.createImageBitmap) return file;
    let bmp;
    try { bmp = await createImageBitmap(file, { imageOrientation: 'from-image' }); } catch (_) { return file; }
    const scale = Math.min(1, MAX_SIDE / Math.max(bmp.width, bmp.height));
    if (scale === 1 && file.size < 1.5e6) { bmp.close(); return file; }
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(bmp.width * scale);
    canvas.height = Math.round(bmp.height * scale);
    canvas.getContext('2d').drawImage(bmp, 0, 0, canvas.width, canvas.height);
    bmp.close();
    // PNG может быть с прозрачностью — для него webp, остальное в jpeg
    const want = file.type === 'image/jpeg' ? 'image/jpeg' : 'image/webp';
    const blob = await new Promise((r) => canvas.toBlob(r, want, 0.88));
    if (!blob || blob.size >= file.size) return file;
    const ext = blob.type === 'image/jpeg' ? 'jpg' : blob.type === 'image/webp' ? 'webp' : 'png';
    return new File([blob], file.name.replace(/\.[^.]+$/, '') + '.' + ext, { type: blob.type });
  }

  function addFiles(list) {
    let skipped = 0;
    for (const file of list) {
      const type = kindOf(file);
      if (!type) { skipped++; continue; }
      const item = { kind: 'new', file, url: URL.createObjectURL(file), type };
      item.ready = shrink(file).then((f) => { item.file = f; }, () => {});
      media.push(item);
    }
    $('coErr').textContent = skipped ? 'Пропущено файлов неподходящего формата: ' + skipped : '';
    renderMedia();
  }

  function addUrl(u) {
    media.push({ kind: 'kept', m: { src: u, type: VIDEO_RE.test(u.split('?')[0]) ? 'video' : 'image', preview: '' } });
    renderMedia();
  }

  function moveTo(from, to) {
    if (from === to || from < 0 || to < 0 || from >= media.length || to >= media.length) return;
    const [x] = media.splice(from, 1);
    media.splice(to, 0, x);
    renderMedia();
  }

  function renderMedia() {
    const box = $('coMedia');
    box.textContent = '';
    media.forEach((x, i) => {
      const card = el('div', { class: 'co-mi' + (x.kind === 'new' ? ' new' : ''), draggable: 'true', title: 'Перетащи, чтобы поменять порядок' });
      const type = x.kind === 'new' ? x.type : x.m.type;
      if (type === 'video') card.append(el('div', { class: 'vid' }, x.kind === 'new' ? x.file.name : 'видео'));
      else card.append(el('img', { src: x.kind === 'new' ? x.url : (x.m.preview || x.m.src), alt: '', draggable: 'false' }));
      if (i === 0) card.append(el('span', { class: 'cover' }, 'обложка'));

      const ctl = el('div', { class: 'ctl' });
      const left = el('button', { type: 'button', title: 'Левее' }, '←');
      const rm = el('button', { type: 'button', class: 'rm', title: 'Убрать' }, '×');
      const right = el('button', { type: 'button', title: 'Правее' }, '→');
      left.onclick = () => moveTo(i, i - 1);
      right.onclick = () => moveTo(i, i + 1);
      rm.onclick = () => {
        if (x.kind === 'new') URL.revokeObjectURL(x.url);
        media.splice(i, 1);
        renderMedia();
      };
      ctl.append(left, rm, right);
      card.append(ctl);

      card.addEventListener('dragstart', (e) => {
        dragIndex = i;
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/x-co-media', String(i));
        card.classList.add('dragging');
      });
      card.addEventListener('dragend', () => { dragIndex = null; card.classList.remove('dragging'); });
      card.addEventListener('dragover', (e) => {
        if (dragIndex === null) return;
        e.preventDefault();
        card.classList.add('drop-here');
      });
      card.addEventListener('dragleave', () => card.classList.remove('drop-here'));
      card.addEventListener('drop', (e) => {
        if (dragIndex === null) return;
        e.preventDefault();
        e.stopPropagation();
        moveTo(dragIndex, i);
      });
      box.append(card);
    });
  }

  function openForm(it) {
    editing = it ? it.id : null;
    $('coFTitle').textContent = it ? 'Изменить' : 'Добавить';
    form.elements.title.value = it ? it.title : '';
    form.elements.date.value = it ? it.date : '';
    form.elements.description.value = it ? it.description : '';
    form.elements.details.value = it ? it.details : '';
    form.elements.tags.value = it ? it.tags.join(', ') : '';
    form.elements.link.value = it ? it.link : '';
    form.elements.fit.value = it && it.fit ? it.fit : 'auto';
    media.forEach((x) => { if (x.kind === 'new') URL.revokeObjectURL(x.url); });
    media = it ? it.media.map((m) => ({ kind: 'kept', m: { ...m } })) : [];
    $('coErr').textContent = '';
    renderMedia();
    openModal(formModal);
    setTimeout(() => form.elements.title.focus(), 50);
  }

  $('coAdd').onclick = () => openForm(null);
  // Кнопка «+ Добавить» из админки ведёт сюда с ?add=1
  if (new URLSearchParams(location.search).has('add')) {
    history.replaceState(null, '', BASE);
    openForm(null);
  }
  $('coEdit').onclick = () => { closeModal($('coViewModal')); openForm(current); };
  $('coDelete').onclick = async () => {
    if (!confirm(`Удалить «${current.title}» вместе с файлами?`)) return;
    const fd = new FormData();
    fd.append('csrf', CSRF);
    fd.append('id', current.id);
    const r = await fetch(BASE + '?api=delete', { method: 'POST', body: fd });
    const j = await r.json().catch(() => ({}));
    if (!r.ok) { alert(j.error || 'Не удалось удалить'); return; }
    location.href = BASE;
  };

  // Выбор файлов кнопкой
  const input = $('coFiles');
  $('coDrop').onclick = () => input.click();
  input.onchange = () => { addFiles(input.files); input.value = ''; };

  // Перетаскивание файлов на всё окно формы (из проводника, Телеграма, другой вкладки)
  let dragDepth = 0;
  const hasFiles = (e) => [...(e.dataTransfer?.types || [])].some((t) => t === 'Files' || t === 'text/uri-list');
  formModal.addEventListener('dragenter', (e) => {
    if (dragIndex !== null || !hasFiles(e)) return;
    e.preventDefault();
    dragDepth++;
    formModal.classList.add('dropping');
  });
  formModal.addEventListener('dragover', (e) => {
    if (dragIndex !== null || !hasFiles(e)) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = 'copy';
  });
  formModal.addEventListener('dragleave', () => {
    dragDepth = Math.max(0, dragDepth - 1);
    if (!dragDepth) formModal.classList.remove('dropping');
  });
  formModal.addEventListener('drop', (e) => {
    if (dragIndex !== null) return;
    e.preventDefault();
    dragDepth = 0;
    formModal.classList.remove('dropping');
    const files = e.dataTransfer.files;
    if (files && files.length) { addFiles(files); return; }
    // Картинку перетащили с другого сайта: приходит ссылка, а не файл
    const url = (e.dataTransfer.getData('text/uri-list') || '').split('\n').find((l) => /^https?:\/\//i.test(l.trim()));
    if (url) addUrl(url.trim());
  });
  // Чтобы промах мимо окна не открывал файл во вкладке
  window.addEventListener('dragover', (e) => { if (formModal.classList.contains('open') && hasFiles(e)) e.preventDefault(); });
  window.addEventListener('drop', (e) => { if (formModal.classList.contains('open') && hasFiles(e)) e.preventDefault(); });

  // Вставка из буфера (Ctrl+V): скриншоты и скопированные файлы
  document.addEventListener('paste', (e) => {
    if (!formModal.classList.contains('open') || !e.clipboardData) return;
    const files = [...e.clipboardData.files];
    if (!files.length) {
      for (const it of e.clipboardData.items || []) {
        if (it.kind === 'file') { const f = it.getAsFile(); if (f) files.push(f); }
      }
    }
    if (!files.length) return;
    e.preventDefault();
    addFiles(files.map((f, k) => (f.name && f.name !== 'image.png') ? f
      : new File([f], `вставка_${Date.now()}_${k}.${(f.type.split('/')[1] || 'png').replace('jpeg', 'jpg')}`, { type: f.type })));
  });

  $('coUrlAdd').onclick = () => {
    const u = $('coUrl').value.trim();
    if (!/^https?:\/\//i.test(u)) { $('coErr').textContent = 'Ссылка должна начинаться с http:// или https://'; return; }
    $('coUrl').value = '';
    $('coErr').textContent = '';
    addUrl(u);
  };

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const save = $('coSave'), bar = $('coProgress'), fill = bar.querySelector('i');
    save.disabled = true;
    $('coErr').textContent = '';

    const fresh = media.filter((x) => x.kind === 'new');
    if (fresh.length) {
      save.textContent = 'Готовлю фото…';
      await Promise.all(fresh.map((x) => x.ready));
    }

    const fd = new FormData(form);
    fd.append('csrf', CSRF);
    fd.append('id', editing || '');
    let n = 0;
    const order = media.map((x) => {
      if (x.kind === 'kept') return { src: x.m.src };
      fd.append('files[]', x.file, x.file.name);
      return { new: n++ };
    });
    fd.append('keep', JSON.stringify(order));

    save.textContent = fresh.length ? 'Загружаю…' : 'Сохраняю…';
    bar.style.display = fresh.length ? 'block' : 'none';
    fill.style.width = '0';
    const fail = (msg) => {
      save.disabled = false;
      save.textContent = 'Сохранить';
      bar.style.display = 'none';
      $('coErr').textContent = msg;
    };

    // XHR ради прогресса загрузки больших видео
    const xhr = new XMLHttpRequest();
    xhr.open('POST', BASE + '?api=save');
    xhr.upload.onprogress = (ev) => {
      if (!ev.lengthComputable) return;
      fill.style.width = (ev.loaded / ev.total * 100) + '%';
      if (ev.loaded >= ev.total) save.textContent = 'Сохраняю…';
    };
    xhr.onload = () => {
      let j = {};
      try { j = JSON.parse(xhr.responseText); } catch (_) {}
      if (xhr.status !== 200 || !j.ok) { fail(j.error || ('Ошибка сервера (' + xhr.status + ')')); return; }
      if (j.warnings && j.warnings.length) alert('Сохранено, но не все файлы загрузились:\n' + j.warnings.join('\n'));
      location.href = BASE + '?item=' + encodeURIComponent(j.id);
    };
    xhr.onerror = () => fail('Нет связи с сервером.');
    xhr.send(fd);
  });
})();
</script>
