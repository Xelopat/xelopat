<?php
declare(strict_types=1);

require_once __DIR__ . '/auth/lib.php';
require_role('admin');

$csrf = csrf_token();
$err = '';
$ok = '';
$data = users_load();
$config_path = __DIR__ . '/data/site_config.json';

$config_json = "{}";
if (is_file($config_path)) {
    $raw = file_get_contents($config_path);
    if ($raw !== false) {
        $config_json = $raw;
    }
}

function admin_h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function admin_decode_config(string $json): array {
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

function admin_save_config_array(string $config_path, array $decoded, string &$err): ?string {
    $dir = dirname($config_path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        $err = 'Не удалось создать папку data.';
        return null;
    }

    $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($encoded === false) {
        $err = 'Не удалось закодировать конфиг.';
        return null;
    }

    if (file_put_contents($config_path, $encoded . PHP_EOL) === false) {
        $err = 'Не удалось записать конфиг на диск.';
        return null;
    }

    return $encoded;
}

function admin_load_collection(array $config, string $primary, string $fallback = ''): array {
    $raw = $config[$primary] ?? [];
    if (!is_array($raw) && $fallback !== '') {
        $raw = $config[$fallback] ?? [];
    }
    if (!is_array($raw)) return [];

    $out = [];
    foreach ($raw as $item) {
        if (!is_array($item)) continue;
        $out[] = [
            'title' => (string)($item['title'] ?? ''),
            'description' => (string)($item['description'] ?? ''),
            'image' => (string)($item['image'] ?? ''),
        ];
    }
    return $out;
}

function admin_file_count(?array $files): int {
    if (!$files || !isset($files['name']) || !is_array($files['name'])) return 0;
    return count($files['name']);
}

function admin_save_uploaded_image(?array $files, int $index, string &$err): string {
    $err = '';
    if (!$files || !isset($files['error']) || !is_array($files['error']) || !array_key_exists($index, $files['error'])) {
        return '';
    }

    $error = (int)$files['error'][$index];
    if ($error === UPLOAD_ERR_NO_FILE) return '';
    if ($error !== UPLOAD_ERR_OK) {
        $err = 'Ошибка загрузки файла (код: ' . $error . ').';
        return '';
    }

    $tmp = (string)($files['tmp_name'][$index] ?? '');
    $name = (string)($files['name'][$index] ?? '');
    $size = (int)($files['size'][$index] ?? 0);

    if ($tmp === '' || !is_uploaded_file($tmp)) {
        $err = 'Невалидный временный файл загрузки.';
        return '';
    }
    if ($size <= 0 || $size > 8 * 1024 * 1024) {
        $err = 'Фото слишком большое. Лимит: 8 МБ.';
        return '';
    }

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allowed_ext = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    if (!in_array($ext, $allowed_ext, true)) {
        $err = 'Разрешены только JPG, PNG, WEBP, GIF.';
        return '';
    }

    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = (string)finfo_file($finfo, $tmp);
            finfo_close($finfo);
            $allowed_mime = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            if ($mime !== '' && !in_array($mime, $allowed_mime, true)) {
                $err = 'Файл не похож на изображение.';
                return '';
            }
        }
    }

    $doc_root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? __DIR__), '/\\');
    $upload_dir = $doc_root . '/uploads/site';
    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0775, true) && !is_dir($upload_dir)) {
        $err = 'Не удалось создать папку uploads/site.';
        return '';
    }

    try {
        $rand = bin2hex(random_bytes(4));
    } catch (Throwable $e) {
        $rand = bin2hex(pack('N', mt_rand()));
    }
    $filename = 'card_' . date('Ymd_His') . '_' . $rand . '.' . $ext;
    $dest = $upload_dir . '/' . $filename;

    if (!move_uploaded_file($tmp, $dest)) {
        $err = 'Не удалось сохранить загруженное фото.';
        return '';
    }

    return '/uploads/site/' . $filename;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check((string)($_POST['csrf'] ?? ''))) {
        $err = 'CSRF: обнови страницу.';
    } else {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'save_site_config') {
            $incoming = trim((string)($_POST['site_config_json'] ?? ''));
            $decoded = json_decode($incoming, true);
            if (!is_array($decoded)) {
                $err = 'JSON невалидный. Проверь запятые, кавычки и структуру.';
            } else {
                $saved = admin_save_config_array($config_path, $decoded, $err);
                if ($saved !== null) {
                    $config_json = $saved;
                    $ok = 'Конфиг сайта сохранён.';
                }
            }
        }

        if ($action === 'save_collection') {
            $collection = (string)($_POST['collection'] ?? '');
            $labels = [
                'projects' => 'Проекты',
                'travels' => 'Путешествия',
                'photos' => 'Фото',
            ];

            if (!isset($labels[$collection])) {
                $err = 'Неизвестный раздел для сохранения.';
            } else {
                $decoded = admin_decode_config($config_json);

                $titles = $_POST['item_title'] ?? [];
                $descriptions = $_POST['item_description'] ?? [];
                $images = $_POST['item_image'] ?? [];
                $uploads = $_FILES['item_upload_image'] ?? null;

                if (!is_array($titles)) $titles = [];
                if (!is_array($descriptions)) $descriptions = [];
                if (!is_array($images)) $images = [];

                $max_count = max(count($titles), count($descriptions), count($images), admin_file_count($uploads));
                $items = [];

                for ($i = 0; $i < $max_count; $i++) {
                    $title = trim((string)($titles[$i] ?? ''));
                    $description = trim((string)($descriptions[$i] ?? ''));
                    $image = trim((string)($images[$i] ?? ''));

                    $upload_err = '';
                    $uploaded = admin_save_uploaded_image($uploads, $i, $upload_err);
                    if ($upload_err !== '') {
                        $err = $upload_err;
                        break;
                    }
                    if ($uploaded !== '') {
                        $image = $uploaded;
                    }

                    if ($title === '' && $description === '' && $image === '') {
                        continue;
                    }

                    $items[] = [
                        'title' => $title !== '' ? $title : 'Без названия',
                        'description' => $description,
                        'image' => $image,
                    ];
                }

                if ($err === '') {
                    $decoded[$collection] = $items;
                    $saved = admin_save_config_array($config_path, $decoded, $err);
                    if ($saved !== null) {
                        $config_json = $saved;
                        $ok = 'Раздел «' . $labels[$collection] . '» сохранён.';
                    }
                }
            }
        }

        if ($action === 'set_admin' || $action === 'set_user') {
            $uid = (string)($_POST['uid'] ?? '');
            $found = null;
            foreach ($data['users'] as $u) {
                if (($u['id'] ?? '') === $uid) {
                    $found = $u;
                    break;
                }
            }

            if (!$found) {
                $err = 'Пользователь не найден.';
            } else {
                $found['role'] = $action === 'set_admin' ? 'admin' : 'user';
                $found['updated_at'] = date('c');
                user_update($data, $found);
                users_save($data);
                header('Location: /admin.php');
                exit;
            }
        }
    }
}

$config_data = admin_decode_config($config_json);
$projects = admin_load_collection($config_data, 'projects', 'cards');
$travels = admin_load_collection($config_data, 'travels', 'travel');
$photos = admin_load_collection($config_data, 'photos', 'photo');

if (!$projects) $projects[] = ['title' => '', 'description' => '', 'image' => ''];
if (!$travels) $travels[] = ['title' => '', 'description' => '', 'image' => ''];
if (!$photos) $photos[] = ['title' => '', 'description' => '', 'image' => ''];

$collections = [
    ['key' => 'projects', 'title' => 'Проекты', 'hint' => 'Раздел /projects/index.php', 'items' => $projects, 'list_id' => 'projectsList'],
    ['key' => 'travels', 'title' => 'Путешествия', 'hint' => 'Раздел /travel/index.php и блок на главной', 'items' => $travels, 'list_id' => 'travelsList'],
    ['key' => 'photos', 'title' => 'Фото', 'hint' => 'Раздел /photo/index.php и блок на главной', 'items' => $photos, 'list_id' => 'photosList'],
];
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Админка</title>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Inter:wght@400;500;700&display=swap');
    *{box-sizing:border-box}
    body{margin:0;background:#151518;color:#efeff1;font-family:Inter,system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif}
    .page{width:min(1240px, calc(100vw - 24px));margin:28px auto 40px;display:grid;gap:18px}
    .card{background:#1e1e25;border:1px solid #333340;border-radius:16px;padding:18px;box-shadow:0 18px 40px rgba(0,0,0,.18)}
    .subcard{border:1px solid #333340;border-radius:12px;padding:12px;background:#151518;display:grid;gap:10px}
    .title{font-family:'Space Mono',ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:18px;margin:0 0 8px}
    .subtitle{font-size:16px;margin:0}
    .muted{color:#868899;font-size:13px;line-height:1.6}
    .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between}
    .ok{margin-top:10px;color:#61d1ad}
    .err{margin-top:10px;color:#ff8f8f}
    .btn, button{
      appearance:none;border:1px solid #333340;background:#191920;color:#efeff1;border-radius:10px;padding:9px 12px;
      font:inherit;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:6px
    }
    .btn:hover, button:hover{background:#252532}
    .btn.primary, button.primary{background:#f9c940;color:#151518;border-color:transparent;font-weight:700}
    .config-editor{
      width:100%;min-height:320px;border:1px solid #333340;border-radius:12px;background:#151518;color:#efeff1;
      padding:14px;font:12px/1.6 'Space Mono',ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;resize:vertical;outline:none
    }
    .collection-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-top:12px}
    .projects-list{display:grid;gap:10px}
    .project-item{border:1px solid #333340;border-radius:12px;padding:12px;background:#1a1a21;display:grid;gap:10px}
    .project-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
    .project-item label{display:grid;gap:6px;color:#868899;font-size:12px}
    .project-item input,
    .project-item textarea{
      width:100%;border:1px solid #333340;border-radius:10px;background:#1e1e25;color:#efeff1;padding:10px;font:13px/1.5 Inter,system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;outline:none
    }
    .project-item input[type="file"]{padding:8px}
    .project-item textarea{min-height:90px;resize:vertical}
    .project-tools{display:flex;justify-content:flex-end}
    .remove-project{border-color:#4b2a2a;color:#ffb0b0}
    .remove-project:hover{background:#312024}
    .file-note{color:#868899;font-size:12px}
    table{width:100%;border-collapse:collapse;margin-top:10px}
    th,td{padding:12px 10px;border-bottom:1px solid #333340;text-align:left;font-size:14px;vertical-align:top}
    th{color:#868899;font-weight:500}
    .pill{display:inline-flex;padding:3px 9px;border:1px solid #333340;border-radius:999px;background:#151518;color:#efeff1;font-size:12px}
    .actions{display:flex;gap:8px;flex-wrap:wrap}
    .hint-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-top:14px}
    .hint{border:1px solid #333340;border-radius:12px;padding:12px;background:#151518}
    .hint code{font-family:'Space Mono',ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;color:#f9c940}
    @media (max-width: 1100px){.collection-grid{grid-template-columns:1fr}}
    @media (max-width: 900px){.project-row,.hint-grid{grid-template-columns:1fr}}
  </style>
</head>
<body>
<?php @include $_SERVER['DOCUMENT_ROOT'] . '/header.php'; ?>

<div class="page">
  <section class="card">
    <div class="row">
      <div>
        <h1 class="title">Панель управления сайтом</h1>
        <div class="muted">Управление карточками разделов, JSON-конфигом и ролями пользователей.</div>
      </div>
      <a class="btn" href="/auth/logout.php">Выйти</a>
    </div>
  </section>

  <?php if ($ok): ?><div class="card ok"><?= admin_h($ok) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="card err"><?= admin_h($err) ?></div><?php endif; ?>

  <section class="card">
    <div class="row">
      <div>
        <h2 class="title">Карточки разделов</h2>
        <div class="muted">Проекты теперь отдельный раздел. Здесь можно редактировать карточки и загружать фото прямо из панели.</div>
      </div>
    </div>

    <div class="collection-grid">
      <?php foreach ($collections as $collection): ?>
        <form method="post" enctype="multipart/form-data" class="subcard">
          <input type="hidden" name="csrf" value="<?= admin_h($csrf) ?>">
          <input type="hidden" name="action" value="save_collection">
          <input type="hidden" name="collection" value="<?= admin_h((string)$collection['key']) ?>">

          <div>
            <h3 class="subtitle"><?= admin_h((string)$collection['title']) ?></h3>
            <div class="muted"><?= admin_h((string)$collection['hint']) ?></div>
          </div>

          <div class="projects-list" id="<?= admin_h((string)$collection['list_id']) ?>" data-collection-list>
            <?php foreach ((array)$collection['items'] as $item): ?>
              <div class="project-item" data-project-item>
                <div class="project-row">
                  <label>
                    Название
                    <input type="text" name="item_title[]" value="<?= admin_h((string)($item['title'] ?? '')) ?>" placeholder="Название">
                  </label>
                  <label>
                    Фото (URL или путь)
                    <input type="text" name="item_image[]" value="<?= admin_h((string)($item['image'] ?? '')) ?>" placeholder="/uploads/site/example.jpg">
                  </label>
                </div>

                <label>
                  Описание
                  <textarea name="item_description[]" placeholder="Коротко о карточке"><?= admin_h((string)($item['description'] ?? '')) ?></textarea>
                </label>

                <label>
                  Загрузка фото
                  <input type="file" name="item_upload_image[]" accept="image/png,image/jpeg,image/webp,image/gif">
                  <span class="file-note">Если загрузишь файл, он заменит поле пути для этой карточки.</span>
                </label>

                <div class="project-tools">
                  <button type="button" class="remove-project" data-remove-project>Удалить</button>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <div class="actions">
            <button type="button" data-add-item data-target="<?= admin_h((string)$collection['list_id']) ?>">Добавить карточку</button>
            <button class="primary" type="submit">Сохранить <?= admin_h((string)$collection['title']) ?></button>
          </div>
        </form>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="card">
    <div class="row">
      <div>
        <h2 class="title">Конфиг сайта (JSON)</h2>
        <div class="muted">Редактируется файл <code>/data/site_config.json</code>. Используй для тонкой настройки hero, терминала и нестандартных полей.</div>
      </div>
    </div>

    <form method="post" style="margin-top:14px;display:grid;gap:12px;">
      <input type="hidden" name="csrf" value="<?= admin_h($csrf) ?>">
      <input type="hidden" name="action" value="save_site_config">
      <textarea class="config-editor" name="site_config_json"><?= admin_h($config_json) ?></textarea>
      <div class="actions">
        <button class="primary" type="submit">Сохранить конфиг</button>
      </div>
    </form>

    <div class="hint-grid">
      <div class="hint">
        <div class="muted">Файл в терминале</div>
        <code>{"type":"file","content":"текст"}</code>
      </div>
      <div class="hint">
        <div class="muted">Папка в терминале</div>
        <code>{"type":"dir","children":{}}</code>
      </div>
      <div class="hint">
        <div class="muted">Массивы карточек</div>
        <code>projects / travels / photos</code>
      </div>
    </div>
  </section>

  <section class="card">
    <div class="row">
      <div>
        <h2 class="title">Пользователи</h2>
        <div class="muted">Выдача и снятие роли admin.</div>
      </div>
    </div>

    <table>
      <thead>
        <tr>
          <th>Логин</th>
          <th>Роль</th>
          <th>Действия</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($data['users'] as $u): ?>
          <tr>
            <td><?= admin_h((string)($u['username'] ?? '')) ?></td>
            <td><span class="pill"><?= admin_h((string)($u['role'] ?? '')) ?></span></td>
            <td>
              <div class="actions">
                <form method="post" style="margin:0;">
                  <input type="hidden" name="csrf" value="<?= admin_h($csrf) ?>">
                  <input type="hidden" name="uid" value="<?= admin_h((string)($u['id'] ?? '')) ?>">
                  <input type="hidden" name="action" value="set_admin">
                  <button type="submit">Сделать admin</button>
                </form>
                <form method="post" style="margin:0;">
                  <input type="hidden" name="csrf" value="<?= admin_h($csrf) ?>">
                  <input type="hidden" name="uid" value="<?= admin_h((string)($u['id'] ?? '')) ?>">
                  <input type="hidden" name="action" value="set_user">
                  <button type="submit">Сделать user</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>
</div>
<script>
(function () {
  function makeItem() {
    const item = document.createElement('div');
    item.className = 'project-item';
    item.setAttribute('data-project-item', '1');
    item.innerHTML = ''
      + '<div class="project-row">'
      + '  <label>Название<input type="text" name="item_title[]" placeholder="Название"></label>'
      + '  <label>Фото (URL или путь)<input type="text" name="item_image[]" placeholder="/uploads/site/example.jpg"></label>'
      + '</div>'
      + '<label>Описание<textarea name="item_description[]" placeholder="Коротко о карточке"></textarea></label>'
      + '<label>Загрузка фото'
      + '  <input type="file" name="item_upload_image[]" accept="image/png,image/jpeg,image/webp,image/gif">'
      + '  <span class="file-note">Если загрузишь файл, он заменит поле пути для этой карточки.</span>'
      + '</label>'
      + '<div class="project-tools"><button type="button" class="remove-project" data-remove-project>Удалить</button></div>';
    return item;
  }

  document.querySelectorAll('[data-add-item]').forEach((btn) => {
    btn.addEventListener('click', function () {
      const target = btn.getAttribute('data-target');
      if (!target) return;
      const list = document.getElementById(target);
      if (!list) return;
      list.appendChild(makeItem());
    });
  });

  document.querySelectorAll('[data-collection-list]').forEach((list) => {
    list.addEventListener('click', function (event) {
      const target = event.target;
      if (!(target instanceof HTMLElement)) return;
      if (!target.hasAttribute('data-remove-project')) return;
      const items = list.querySelectorAll('[data-project-item]');
      if (items.length <= 1) return;
      const row = target.closest('[data-project-item]');
      if (row) row.remove();
    });
  });
})();
</script>
</body>
</html>

