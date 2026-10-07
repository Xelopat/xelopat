<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/auth/lib.php';

$auth_user = auth_current_user();
$auth_csrf = csrf_token();
$auth_next = (string)($_SERVER['REQUEST_URI'] ?? '/');
$auth_is_admin = user_has_role($auth_user, 'admin');

// Локальная статистика посещений (без IP, ботов и визитов админов)
require_once __DIR__ . '/includes/stats.php';
stats_track($auth_user);

$brand_name = 'xelopat';
$brand_href = '/';

$univer_sections = [
    'crypto' => [
        'title' => 'Криптография',
        'items' => [
            ['Треугольник', '/crypto/triangle.php'],
            ['Построение циклов', '/crypto/hmm_cycles.php'],
            ['Берлекэмп-Мэсси', '/crypto/messi.php'],
            ['Проверка подписи', '/crypto/check_signature.php'],
            ['Создание подписи', '/crypto/get_signature.php'],
            ['Эллиптические кривые', '/crypto/eleptic_sum.php'],
        ],
    ],
    'admin' => [
        'title' => 'Администрирование',
        'items' => [
            ['Апельсин', '/adminis/ip.php'],
        ],
    ],
    'coursework' => [
        'title' => 'Курсовая',
        'items' => [
            ['Курсовая: Инъекции', '/coursework/injection.php'],
        ],
    ],
];

$hobby_items = [
    ['Путешествия', '/travel/index.php'],
    ['Фото', '/photo/index.php'],
    ['Духи', '/perfumes/index.php'],
];

$base_items = [
    ['Домофоны', '/bases/domophones.php'],
    ['Пароли по умолчанию', '/bases/default-creds.php'],
];

$uri = strtok((string)($_SERVER['REQUEST_URI'] ?? '/'), '?');

function site_h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function site_active(string $href, string $uri): bool {
    $h = rtrim($href, '/');
    $u = rtrim($uri, '/');
    if ($h === $u) {
        return true;
    }
    if ($h === '') {
        return false;
    }
    return strpos($u . '/', $h . '/') === 0;
}
?>
<?php
// Страницы без собственного <head> задают $site_page_title до подключения шапки —
// тогда шапка сама открывает документ (doctype, кодировка, заголовок вкладки).
$site_css_v = @filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/site.css') ?: 1;
$site_js_v = @filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/site.js') ?: 1;
if (isset($site_page_title)):
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?= site_h((string)$site_page_title) ?></title>
  <link rel="icon" type="image/png" href="/img/xelopat.png">
  <link rel="stylesheet" href="/assets/site.css?v=<?= $site_css_v ?>">
  <script src="/assets/site.js?v=<?= $site_js_v ?>" defer></script>
</head>
<body>
<?php else: ?>
<link rel="stylesheet" href="/assets/site.css?v=<?= $site_css_v ?>">
<script src="/assets/site.js?v=<?= $site_js_v ?>" defer></script>
<?php endif; ?>
<script>
(function () {
  if (!document.querySelector('meta[name="viewport"]')) {
    const meta = document.createElement('meta');
    meta.name = 'viewport';
    meta.content = 'width=device-width, initial-scale=1, viewport-fit=cover';
    document.head.appendChild(meta);
  }

  if (!document.querySelector('link[rel="icon"]')) {
    const icon = document.createElement('link');
    icon.rel = 'icon';
    icon.type = 'image/png';
    icon.href = '/img/xelopat.png';
    document.head.appendChild(icon);
  }

  if (!document.querySelector('link[rel="apple-touch-icon"]')) {
    const appleIcon = document.createElement('link');
    appleIcon.rel = 'apple-touch-icon';
    appleIcon.href = '/img/xelopat.png';
    document.head.appendChild(appleIcon);
  }
})();
</script>

<header class="site-header" id="siteHeader">
  <div class="wrap">
    <a class="brand" href="<?= site_h($brand_href) ?>">
      <span class="brand-mark"><img src="/img/xelopat.png" alt="xelopat"></span>
      <span class="brand-text"><?= site_h($brand_name) ?></span>
    </a>

    <nav class="nav" id="navRoot" aria-label="Навигация">
      <a class="nav-link<?= $uri === '/' ? ' active' : '' ?>" href="/">Главная</a>
      <a class="nav-link<?= strpos($uri, '/projects/') === 0 ? ' active' : '' ?>" href="/projects/index.php">Проекты</a>

      <div class="dd" id="dd-univer-wrap">
        <button type="button" class="nav-btn<?= strpos($uri, '/crypto/') === 0 || strpos($uri, '/adminis/') === 0 || strpos($uri, '/coursework/') === 0 ? ' active' : '' ?>" data-dd-btn="univer" aria-expanded="false">МЭИ</button>
        <div class="dd-panel" id="dd-univer" role="dialog" aria-label="МЭИ">
          <?php foreach ($univer_sections as $section): ?>
            <section class="dd-group">
              <div class="dd-group-title"><?= site_h((string)$section['title']) ?></div>
              <ul class="dd-group-list">
                <?php foreach ($section['items'] as $it): ?>
                  <?php $label = (string)$it[0]; $href = (string)$it[1]; ?>
                  <li><a href="<?= site_h($href) ?>" class="<?= site_active($href, $uri) ? 'active' : '' ?>"><?= site_h($label) ?></a></li>
                <?php endforeach; ?>
              </ul>
            </section>
          <?php endforeach; ?>
        </div>
      </div>

      <?php // Хобби видно только после входа; сами страницы открываются и по прямой ссылке ?>
      <?php if ($auth_user): ?>
      <div class="dd" id="dd-hobby-wrap">
        <button type="button" class="nav-btn<?= strpos($uri, '/perfumes/') === 0 || strpos($uri, '/photo/') === 0 || strpos($uri, '/travel/') === 0 ? ' active' : '' ?>" data-dd-btn="hobby" aria-expanded="false">Хобби</button>
        <div class="dd-panel" id="dd-hobby" role="dialog" aria-label="Хобби">
          <ul class="menu-root">
            <?php foreach ($hobby_items as $it): ?>
              <?php $label = (string)$it[0]; $href = (string)$it[1]; ?>
              <li><a href="<?= site_h($href) ?>" class="<?= site_active($href, $uri) ? 'active' : '' ?>"><?= site_h($label) ?></a></li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
      <?php endif; ?>

      <div class="dd" id="dd-bases-wrap">
        <button type="button" class="nav-btn<?= strpos($uri, '/bases/') === 0 ? ' active' : '' ?>" data-dd-btn="bases" aria-expanded="false">Базы</button>
        <div class="dd-panel" id="dd-bases" role="dialog" aria-label="Базы">
          <ul class="menu-root">
            <?php foreach ($base_items as $it): ?>
              <?php $label = (string)$it[0]; $href = (string)$it[1]; ?>
              <li><a href="<?= site_h($href) ?>" class="<?= site_active($href, $uri) ? 'active' : '' ?>"><?= site_h($label) ?></a></li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
    </nav>

    <div class="auth-area">
      <?php if ($auth_user): ?>
        <span class="auth-user"><?= site_h((string)$auth_user['username']) ?></span>
        <?php if ($auth_is_admin): ?>
          <a class="auth-action" href="/admin.php">Админка</a>
        <?php endif; ?>
        <a class="auth-action" href="/auth/logout.php">Выйти</a>
      <?php else: ?>
        <button type="button" class="auth-action" id="authOpenBtn">Войти</button>
      <?php endif; ?>

      <button class="burger" type="button" id="burgerBtn" aria-label="Открыть меню" aria-expanded="false">
        <span class="burger-lines" aria-hidden="true"><span></span><span></span><span></span></span>
      </button>
    </div>
  </div>
</header>

<?php if (!$auth_user): ?>
<div class="auth-modal" id="authModal" aria-hidden="true">
  <div class="auth-backdrop" id="authBackdrop"></div>
  <div class="auth-box" role="dialog" aria-label="Авторизация">
    <button class="auth-close" type="button" id="authCloseBtn">×</button>

    <div class="auth-tabs">
      <button class="auth-tab active" type="button" data-auth-tab="login">Вход</button>
      <button class="auth-tab" type="button" data-auth-tab="register">Регистрация</button>
    </div>

    <div id="authErr" class="auth-err" style="display:none;"></div>

    <form id="authLoginForm" method="post" action="/auth/login.php">
      <input type="hidden" name="csrf" value="<?= site_h($auth_csrf) ?>">
      <input type="hidden" name="next" value="<?= site_h($auth_next) ?>">
      <div class="auth-row">
        <label>Логин</label>
        <input name="username" autocomplete="username" required>
      </div>
      <div class="auth-row">
        <label>Пароль</label>
        <input type="password" name="password" autocomplete="current-password" required>
      </div>
      <div class="auth-actions">
        <button class="auth-btn primary" type="submit">Войти</button>
      </div>
    </form>

    <form id="authRegisterForm" method="post" action="/auth/register.php" style="display:none;">
      <input type="hidden" name="csrf" value="<?= site_h($auth_csrf) ?>">
      <input type="hidden" name="next" value="<?= site_h($auth_next) ?>">
      <div class="auth-row">
        <label>Логин</label>
        <input name="username" autocomplete="username" required>
      </div>
      <div class="auth-row">
        <label>Пароль</label>
        <input type="password" name="password" autocomplete="new-password" required>
      </div>
      <div class="auth-row">
        <label>Повтори пароль</label>
        <input type="password" name="password2" autocomplete="new-password" required>
      </div>
      <div class="auth-actions">
        <button class="auth-btn primary" type="submit">Создать</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
(function () {
  const header = document.getElementById('siteHeader');
  const navRoot = document.getElementById('navRoot');
  const burgerBtn = document.getElementById('burgerBtn');

  const btnUniver = document.querySelector('[data-dd-btn="univer"]');
  const btnHobby = document.querySelector('[data-dd-btn="hobby"]');
  const btnBases = document.querySelector('[data-dd-btn="bases"]');
  const univerWrap = document.getElementById('dd-univer-wrap');
  const hobbyWrap = document.getElementById('dd-hobby-wrap');
  const basesWrap = document.getElementById('dd-bases-wrap');
  const panelUniver = document.getElementById('dd-univer');
  const panelHobby = document.getElementById('dd-hobby');
  const panelBases = document.getElementById('dd-bases');
  let univerCloseTimer = null;
  let hobbyCloseTimer = null;
  let basesCloseTimer = null;
  const DESKTOP_CLOSE_DELAY = 420;

  function isMobile() {
    return window.matchMedia('(max-width: 900px)').matches;
  }

  function menuIsOpen() {
    return !!(navRoot && navRoot.classList.contains('open'));
  }

  function setMenuOpen(open) {
    if (!navRoot || !burgerBtn) return;
    navRoot.classList.toggle('open', open);
    burgerBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    document.body.classList.toggle('nav-open', open);
    if (!open) {
      closeAll();
    }
  }

  function fadeClose(node) {
    if (!node) return;
    if (!node.classList.contains('open') && !node.classList.contains('closing')) return;
    node.classList.remove('open');
    node.classList.add('closing');
    window.setTimeout(() => {
      node.classList.remove('closing');
    }, 180);
  }

  function clearDesktopCloseTimers() {
    if (univerCloseTimer) {
      clearTimeout(univerCloseTimer);
      univerCloseTimer = null;
    }
    if (hobbyCloseTimer) {
      clearTimeout(hobbyCloseTimer);
      hobbyCloseTimer = null;
    }
    if (basesCloseTimer) {
      clearTimeout(basesCloseTimer);
      basesCloseTimer = null;
    }
  }

  function openDesktopPanel(which) {
    if (isMobile()) return;
    if (which === 'univer') {
      if (univerCloseTimer) {
        clearTimeout(univerCloseTimer);
        univerCloseTimer = null;
      }
    } else if (which === 'hobby') {
      if (hobbyCloseTimer) {
        clearTimeout(hobbyCloseTimer);
        hobbyCloseTimer = null;
      }
    } else if (which === 'bases') {
      if (basesCloseTimer) {
        clearTimeout(basesCloseTimer);
        basesCloseTimer = null;
      }
    }
    const panel = which === 'univer' ? panelUniver : (which === 'hobby' ? panelHobby : panelBases);
    const btn = which === 'univer' ? btnUniver : (which === 'hobby' ? btnHobby : btnBases);
    if (!panel || !btn) return;
    panel.classList.remove('closing');
    [panelUniver, panelHobby, panelBases].forEach(otherPanel => {
      if (!otherPanel || otherPanel === panel) return;
      otherPanel.classList.remove('open');
      otherPanel.classList.remove('closing');
    });
    [btnUniver, btnHobby, btnBases].forEach(otherBtn => {
      if (!otherBtn || otherBtn === btn) return;
      otherBtn.classList.remove('active');
      otherBtn.setAttribute('aria-expanded', 'false');
    });
    panel.classList.add('open');
    btn.classList.add('active');
    btn.setAttribute('aria-expanded', 'true');
  }

  function scheduleDesktopPanelClose(which) {
    if (isMobile()) return;
    const timerRef = which === 'univer' ? 'univer' : (which === 'hobby' ? 'hobby' : 'bases');
    if (timerRef === 'univer') {
      if (univerCloseTimer) clearTimeout(univerCloseTimer);
      univerCloseTimer = setTimeout(() => {
        if (univerWrap && univerWrap.matches(':hover')) return;
        if (panelUniver) fadeClose(panelUniver);
        if (btnUniver) {
          btnUniver.classList.remove('active');
          btnUniver.setAttribute('aria-expanded', 'false');
        }
      }, DESKTOP_CLOSE_DELAY);
    } else if (timerRef === 'hobby') {
      if (hobbyCloseTimer) clearTimeout(hobbyCloseTimer);
      hobbyCloseTimer = setTimeout(() => {
        if (hobbyWrap && hobbyWrap.matches(':hover')) return;
        if (panelHobby) fadeClose(panelHobby);
        if (btnHobby) {
          btnHobby.classList.remove('active');
          btnHobby.setAttribute('aria-expanded', 'false');
        }
      }, DESKTOP_CLOSE_DELAY);
    } else {
      if (basesCloseTimer) clearTimeout(basesCloseTimer);
      basesCloseTimer = setTimeout(() => {
        if (basesWrap && basesWrap.matches(':hover')) return;
        if (panelBases) fadeClose(panelBases);
        if (btnBases) {
          btnBases.classList.remove('active');
          btnBases.setAttribute('aria-expanded', 'false');
        }
      }, DESKTOP_CLOSE_DELAY);
    }
  }

  function closeAll() {
    clearDesktopCloseTimers();
    [panelUniver, panelHobby, panelBases].forEach(panel => panel && fadeClose(panel));
    [btnUniver, btnHobby, btnBases].forEach(btn => {
      if (!btn) return;
      btn.classList.remove('active');
      btn.setAttribute('aria-expanded', 'false');
    });
  }

  function togglePanel(which) {
    const panel = which === 'univer' ? panelUniver : (which === 'hobby' ? panelHobby : panelBases);
    const btn = which === 'univer' ? btnUniver : (which === 'hobby' ? btnHobby : btnBases);
    if (!panel || !btn) return;
    const open = panel.classList.contains('open');
    closeAll();
    if (open) return;
    panel.classList.remove('closing');
    panel.classList.add('open');
    btn.classList.add('active');
    btn.setAttribute('aria-expanded', 'true');
  }

  if (btnUniver) btnUniver.addEventListener('click', function (e) {
    e.stopPropagation();
    togglePanel('univer');
  });

  if (btnHobby) btnHobby.addEventListener('click', function (e) {
    e.stopPropagation();
    togglePanel('hobby');
  });

  if (btnBases) btnBases.addEventListener('click', function (e) {
    e.stopPropagation();
    togglePanel('bases');
  });

  document.addEventListener('click', function (e) {
    if (!e.target.closest('#siteHeader')) {
      closeAll();
      if (isMobile()) setMenuOpen(false);
    }
  });

  if (burgerBtn && navRoot) {
    burgerBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      setMenuOpen(!menuIsOpen());
    });
  }

  if (navRoot) {
    navRoot.addEventListener('click', function (e) {
      if (!isMobile()) return;
      const link = e.target.closest('a[href]');
      if (link) {
        setMenuOpen(false);
      }
    });
  }

  if (univerWrap) {
    univerWrap.addEventListener('mouseenter', function () {
      openDesktopPanel('univer');
    });
    univerWrap.addEventListener('mouseleave', function () {
      scheduleDesktopPanelClose('univer');
    });
  }

  if (hobbyWrap) {
    hobbyWrap.addEventListener('mouseenter', function () {
      openDesktopPanel('hobby');
    });
    hobbyWrap.addEventListener('mouseleave', function () {
      scheduleDesktopPanelClose('hobby');
    });
  }

  if (basesWrap) {
    basesWrap.addEventListener('mouseenter', function () {
      openDesktopPanel('bases');
    });
    basesWrap.addEventListener('mouseleave', function () {
      scheduleDesktopPanelClose('bases');
    });
  }

  const authOpenBtn = document.getElementById('authOpenBtn');
  const authModal = document.getElementById('authModal');
  const authCloseBtn = document.getElementById('authCloseBtn');
  const authBackdrop = document.getElementById('authBackdrop');
  const authTabs = Array.from(document.querySelectorAll('[data-auth-tab]'));
  const authLoginForm = document.getElementById('authLoginForm');
  const authRegisterForm = document.getElementById('authRegisterForm');
  const authErr = document.getElementById('authErr');

  function openAuth(mode) {
    if (!authModal) return;
    if (isMobile()) setMenuOpen(false);
    authModal.classList.add('open');
    authModal.setAttribute('aria-hidden', 'false');
    setAuthTab(mode || 'login');
  }

  function closeAuth() {
    if (!authModal) return;
    authModal.classList.remove('open');
    authModal.setAttribute('aria-hidden', 'true');
  }

  function setAuthTab(mode) {
    const isLogin = mode === 'login';
    authTabs.forEach(tab => tab.classList.toggle('active', tab.getAttribute('data-auth-tab') === mode));
    if (authLoginForm) authLoginForm.style.display = isLogin ? 'block' : 'none';
    if (authRegisterForm) authRegisterForm.style.display = isLogin ? 'none' : 'block';
  }

  function showAuthErr(msg) {
    if (!authErr || !msg) return;
    authErr.style.display = 'block';
    authErr.textContent = msg;
  }

  if (authOpenBtn) authOpenBtn.addEventListener('click', () => openAuth('login'));
  if (authCloseBtn) authCloseBtn.addEventListener('click', closeAuth);
  if (authBackdrop) authBackdrop.addEventListener('click', closeAuth);
  authTabs.forEach(tab => tab.addEventListener('click', () => setAuthTab(tab.getAttribute('data-auth-tab'))));

  if (window.__AUTH_OPEN__ === 'login') openAuth('login');
  if (window.__AUTH_OPEN__ === 'register') openAuth('register');

  const params = new URLSearchParams(window.location.search);
  const err = params.get('err');
  if (err) {
    const map = {
      bad: 'Неверный логин или пароль.',
      csrf: 'CSRF. Обнови страницу и попробуй снова.',
      username: 'Логин: 3-32 символа. Разрешены латиница, цифры, точка, подчёркивание, дефис.',
      pass: 'Пароль слишком короткий.',
      pass2: 'Пароли не совпадают.',
      exists: 'Такой логин уже существует.'
    };
    showAuthErr(map[err] || 'Ошибка.');
    openAuth(window.__AUTH_OPEN__ || 'login');
  }

  window.addEventListener('resize', function () {
    if (!isMobile() && menuIsOpen()) {
      setMenuOpen(false);
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (menuIsOpen()) setMenuOpen(false);
  });
})();
</script>
