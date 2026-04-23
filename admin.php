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
                $dir = dirname($config_path);
                if (!is_dir($dir)) {
                    mkdir($dir, 0775, true);
                }
                $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                if ($encoded === false) {
                    $err = 'Не удалось сохранить конфиг.';
                } else {
                    file_put_contents($config_path, $encoded . PHP_EOL);
                    $config_json = $encoded;
                    $ok = 'Конфиг сайта сохранён.';
                }
            }
        }

        if ($action === 'save_projects') {
            $decoded = json_decode($config_json, true);
            if (!is_array($decoded)) {
                $decoded = [];
            }

            $titles = $_POST['project_title'] ?? [];
            $descriptions = $_POST['project_description'] ?? [];
            $images = $_POST['project_image'] ?? [];

            if (!is_array($titles)) $titles = [];
            if (!is_array($descriptions)) $descriptions = [];
            if (!is_array($images)) $images = [];

            $max_count = max(count($titles), count($descriptions), count($images));
            $projects = [];

            for ($i = 0; $i < $max_count; $i++) {
                $title = trim((string)($titles[$i] ?? ''));
                $description = trim((string)($descriptions[$i] ?? ''));
                $image = trim((string)($images[$i] ?? ''));

                if ($title === '' && $description === '' && $image === '') {
                    continue;
                }

                $projects[] = [
                    'title' => $title !== '' ? $title : 'Без названия',
                    'description' => $description,
                    'image' => $image,
                ];
            }

            $decoded['projects'] = $projects;
            $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if ($encoded === false) {
                $err = 'Не удалось сохранить проекты.';
            } else {
                file_put_contents($config_path, $encoded . PHP_EOL);
                $config_json = $encoded;
                $ok = 'Проекты сохранены.';
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

function admin_h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$config_data = json_decode($config_json, true);
if (!is_array($config_data)) {
    $config_data = [];
}

$projects = [];
if (isset($config_data['projects']) && is_array($config_data['projects'])) {
    foreach ($config_data['projects'] as $project) {
        if (!is_array($project)) {
            continue;
        }
        $projects[] = [
            'title' => (string)($project['title'] ?? ''),
            'description' => (string)($project['description'] ?? ''),
            'image' => (string)($project['image'] ?? ''),
        ];
    }
} elseif (isset($config_data['cards']) && is_array($config_data['cards'])) {
    foreach ($config_data['cards'] as $card) {
        if (!is_array($card)) {
            continue;
        }
        $projects[] = [
            'title' => (string)($card['title'] ?? ''),
            'description' => (string)($card['description'] ?? ''),
            'image' => '',
        ];
    }
}

if (!$projects) {
    $projects[] = ['title' => '', 'description' => '', 'image' => ''];
}
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
    .title{font-family:'Space Mono',ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:18px;margin:0 0 8px}
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
      width:100%;min-height:440px;border:1px solid #333340;border-radius:12px;background:#151518;color:#efeff1;
      padding:14px;font:12px/1.6 'Space Mono',ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;resize:vertical;outline:none
    }
    .projects-list{display:grid;gap:12px;margin-top:14px}
    .project-item{border:1px solid #333340;border-radius:12px;padding:12px;background:#151518;display:grid;gap:10px}
    .project-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
    .project-item label{display:grid;gap:6px;color:#868899;font-size:12px}
    .project-item input,
    .project-item textarea{
      width:100%;border:1px solid #333340;border-radius:10px;background:#1e1e25;color:#efeff1;padding:10px;font:13px/1.5 Inter,system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;outline:none
    }
    .project-item textarea{min-height:90px;resize:vertical}
    .project-tools{display:flex;justify-content:flex-end}
    .remove-project{border-color:#4b2a2a;color:#ffb0b0}
    .remove-project:hover{background:#312024}
    @media (max-width: 900px){.project-row{grid-template-columns:1fr}}
    table{width:100%;border-collapse:collapse;margin-top:10px}
    th,td{padding:12px 10px;border-bottom:1px solid #333340;text-align:left;font-size:14px;vertical-align:top}
    th{color:#868899;font-weight:500}
    .pill{display:inline-flex;padding:3px 9px;border:1px solid #333340;border-radius:999px;background:#151518;color:#efeff1;font-size:12px}
    .actions{display:flex;gap:8px;flex-wrap:wrap}
    .hint-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-top:14px}
    .hint{border:1px solid #333340;border-radius:12px;padding:12px;background:#151518}
    .hint code{font-family:'Space Mono',ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;color:#f9c940}
    @media (max-width: 900px){.hint-grid{grid-template-columns:1fr}}
  </style>
</head>
<body>
<?php @include $_SERVER['DOCUMENT_ROOT'] . '/header.php'; ?>

<div class="page">
  <section class="card">
    <div class="row">
      <div>
        <h1 class="title">Панель управления сайтом</h1>
        <div class="muted">Здесь можно менять роли пользователей и редактировать структуру интерактивного терминала через JSON-конфиг.</div>
      </div>
      <a class="btn" href="/auth/logout.php">Выйти</a>
    </div>
  </section>

  <section class="card">
    <div class="row">
      <div>
        <h2 class="title">Конфиг главной страницы</h2>
        <div class="muted">Редактируется файл <code>/data/site_config.json</code>. Через него настраиваются hero, карточки и фейковая файловая система терминала.</div>
      </div>
    </div>

    <?php if ($ok): ?><div class="ok"><?= admin_h($ok) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="err"><?= admin_h($err) ?></div><?php endif; ?>

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
        <div class="muted">Команды</div>
        <code>help, ls, cd, cat, tree, clear</code>
      </div>
    </div>
  </section>

  <section class="card">
    <div class="row">
      <div>
        <h2 class="title">Проекты на главной</h2>
        <div class="muted">Карточки без ссылок: фото, название и описание.</div>
      </div>
    </div>

    <form method="post" class="projects-form">
      <input type="hidden" name="csrf" value="<?= admin_h($csrf) ?>">
      <input type="hidden" name="action" value="save_projects">

      <div class="projects-list" id="projectsList">
        <?php foreach ($projects as $project): ?>
          <div class="project-item" data-project-item>
            <div class="project-row">
              <label>
                Название
                <input type="text" name="project_title[]" value="<?= admin_h((string)$project['title']) ?>" placeholder="Название проекта">
              </label>
              <label>
                Фото (URL или путь)
                <input type="text" name="project_image[]" value="<?= admin_h((string)$project['image']) ?>" placeholder="/img/xelopat.png">
              </label>
            </div>
            <label>
              Описание
              <textarea name="project_description[]" placeholder="Коротко о проекте"><?= admin_h((string)$project['description']) ?></textarea>
            </label>
            <div class="project-tools">
              <button type="button" class="remove-project" data-remove-project>Удалить</button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="actions" style="margin-top:12px;">
        <button type="button" id="addProjectBtn">Добавить проект</button>
        <button class="primary" type="submit">Сохранить проекты</button>
      </div>
    </form>
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
  const list = document.getElementById('projectsList');
  const addBtn = document.getElementById('addProjectBtn');
  if (!list || !addBtn) return;

  function makeProjectItem() {
    const item = document.createElement('div');
    item.className = 'project-item';
    item.setAttribute('data-project-item', '1');
    item.innerHTML = ''
      + '<div class="project-row">'
      + '  <label>Название<input type="text" name="project_title[]" placeholder="Название проекта"></label>'
      + '  <label>Фото (URL или путь)<input type="text" name="project_image[]" placeholder="/img/xelopat.png"></label>'
      + '</div>'
      + '<label>Описание<textarea name="project_description[]" placeholder="Коротко о проекте"></textarea></label>'
      + '<div class="project-tools"><button type="button" class="remove-project" data-remove-project>Удалить</button></div>';
    return item;
  }

  addBtn.addEventListener('click', function () {
    list.appendChild(makeProjectItem());
  });

  list.addEventListener('click', function (event) {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;
    if (!target.hasAttribute('data-remove-project')) return;
    const items = list.querySelectorAll('[data-project-item]');
    if (items.length <= 1) return;
    const row = target.closest('[data-project-item]');
    if (row) row.remove();
  });
})();
</script>
</body>
</html>
