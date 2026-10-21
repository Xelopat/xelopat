<?php
declare(strict_types=1);

// /psy: личный воркбук «Уважение без самоунижения». Только для админа.
// Тексты лежат в includes/psy_content.php, ответы хранятся на сервере в .private/prefs.json (ключ psy) и не попадают в репозиторий.

require_once __DIR__ . '/auth/lib.php';
require_once __DIR__ . '/includes/user_prefs.php';
require_role('admin');

const PSY_MAX_KEYS = 4000;
const PSY_MAX_TEXT = 6000;

$me = auth_current_user();
$uid = (string)($me['id'] ?? '');

// Сохранение: POST {csrf, set: {ключ: значение | null}}. null удаляет ответ, остальные ключи не трогаем.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $reply = function (int $code, bool $ok): void {
        http_response_code($code);
        echo json_encode(['ok' => $ok]);
        exit;
    };

    $body = json_decode((string)file_get_contents('php://input', false, null, 0, 262144), true);
    if (!is_array($body) || !csrf_check((string)($body['csrf'] ?? ''))) $reply(403, false);
    $set = $body['set'] ?? null;
    if (!is_array($set) || count($set) > 500) $reply(400, false);

    $all = prefs_get($uid);
    $cur = is_array($all['psy'] ?? null) ? $all['psy'] : [];

    foreach ($set as $key => $val) {
        $key = (string)$key;
        if (!preg_match('/^[A-Za-z0-9._-]{1,60}$/', $key)) $reply(400, false);
        if ($val === null) {
            unset($cur[$key]);
        } elseif (is_string($val)) {
            // Обрезка по символам без mbstring; невалидный UTF-8 отклоняем
            if (!preg_match('/^.{0,' . PSY_MAX_TEXT . '}/su', $val, $m)) $reply(400, false);
            $cur[$key] = $m[0];
        } elseif (is_int($val) && $val > 0 && $val <= 999) {
            $cur[$key] = $val;
        } elseif ($val === true) {
            $cur[$key] = true;
        } else {
            $reply(400, false);
        }
    }
    if (count($cur) > PSY_MAX_KEYS) $reply(413, false);

    $saved_ok = prefs_set($uid, 'psy', $cur);
    $reply($saved_ok ? 200 : 500, $saved_ok);
}

$content = require __DIR__ . '/includes/psy_content.php';
$all = prefs_get($uid);
$saved = is_array($all['psy'] ?? null) ? $all['psy'] : [];

$json = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP;
$css_v = @filemtime(__DIR__ . '/assets/psy.css') ?: 1;
$js_v = @filemtime(__DIR__ . '/assets/psy.js') ?: 1;

$site_page_title = 'Уважение без самоунижения';
$site_page_noindex = true;
$site_page_head_extra = '<link rel="stylesheet" href="/assets/psy.css?v=' . $css_v . '">';
include __DIR__ . '/header.php';
?>
<main class="page">
  <div class="page-wrap">
    <div class="psy-top">
      <div>
        <div class="page-label">// личное, видно только админу</div>
        <h1 class="page-title"><?= htmlspecialchars((string)$content['title'], ENT_QUOTES, 'UTF-8') ?></h1>
      </div>
      <div class="psy-status" id="psyStatus" role="status" data-state="saved">сохранено</div>
    </div>
    <p class="page-sub"><?= htmlspecialchars((string)$content['sub'], ENT_QUOTES, 'UTF-8') ?></p>

    <div class="psy">
      <nav class="psy-nav" id="psyNav" aria-label="Разделы воркбука"></nav>
      <div class="psy-main" id="psyMain"></div>
    </div>
  </div>
</main>

<script type="application/json" id="psy-data"><?= json_encode($content, $json) ?></script>
<script type="application/json" id="psy-state"><?= json_encode(['csrf' => csrf_token(), 'v' => (object)$saved], $json) ?></script>
<script src="/assets/psy.js?v=<?= $js_v ?>" defer></script>
