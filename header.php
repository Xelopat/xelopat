<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/auth/lib.php';

$auth_user = auth_current_user();
$auth_csrf = csrf_token();
$auth_next = (string)($_SERVER['REQUEST_URI'] ?? '/');
$auth_is_admin = $auth_user && (($auth_user['role'] ?? '') === 'admin');

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
<style>
  @import url('https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Inter:wght@400;500;700&display=swap');

  *, *::before, *::after { box-sizing: border-box; }

  :root{
    --bg:#151518;
    --panel:#1e1e25;
    --panel-2:#191920;
    --text:#efeff1;
    --muted:#868899;
    --line:#333340;
    --accent:#f9c940;
    --green:#61d1ad;
    --violet:#9d8ff0;
    --danger:#ff8f8f;
    --shadow:0 18px 40px rgba(0,0,0,.24);
    --header-h:60px;
  }

  body{
    margin:0;
    background:var(--bg);
    color:var(--text);
    font-family:Inter,system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;
  }
  body.nav-open{
    overflow:hidden;
  }

  .site-header{
    position:sticky;
    top:0;
    z-index:1000;
    background:rgba(21,21,24,.94);
    backdrop-filter: blur(10px);
    border-bottom:1px solid var(--line);
    font-family:Inter,system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;
  }

  .site-header .wrap{
    width:min(1280px, calc(100vw - 24px));
    margin:0 auto;
    height:var(--header-h);
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
  }

  .brand{
    display:flex;
    align-items:center;
    gap:10px;
    color:var(--text);
    text-decoration:none;
    font-family:"Space Mono",ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
    font-size:14px;
  }

  .brand-mark{
    width:30px;
    height:30px;
    border-radius:6px;
    border:1px solid var(--line);
    background:#151518;
    overflow:hidden;
    display:flex;
    align-items:center;
    justify-content:center;
    flex:0 0 auto;
  }

  .brand-mark img{
    width:100%;
    height:100%;
    object-fit:cover;
    display:block;
  }

  .nav{
    display:flex;
    align-items:center;
    gap:18px;
  }

  .nav a, .nav button,
  .auth-area a, .auth-area button{
    appearance:none;
    border:none;
    background:transparent;
    color:var(--muted);
    margin:0;
    padding:0;
    font-family:inherit;
    font-weight:500;
    font-size:13px;
    line-height:20px;
    cursor:pointer;
    text-decoration:none;
    transition:color .18s ease;
    white-space:nowrap;
  }

  .nav-link,
  .nav-btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:20px;
    vertical-align:middle;
  }

  .nav a:hover, .nav button:hover,
  .auth-area a:hover, .auth-area button:hover,
  .nav a.active, .nav button.active{
    color:var(--accent);
  }

  .nav-link.active, .nav-btn.active{
    position:relative;
  }

  .nav-link.active::after, .nav-btn.active::after{
    content:"";
    position:absolute;
    left:0;
    right:0;
    bottom:-8px;
    height:2px;
    background:var(--accent);
  }

  .auth-area{
    display:flex;
    align-items:center;
    gap:12px;
  }

  .auth-user{
    color:var(--text);
    font-size:12px;
    padding:6px 10px;
    border:1px solid var(--line);
    border-radius:999px;
    background:var(--panel);
  }

  .auth-action{
    background:var(--panel) !important;
    border:1px solid var(--line) !important;
    border-radius:8px;
    padding:7px 14px !important;
    color:var(--text) !important;
  }

  .auth-action:hover{ background:#252532 !important; }

  .burger{
    display:none;
    width:38px;
    height:38px;
    border:1px solid var(--line) !important;
    border-radius:10px;
    background:var(--panel) !important;
    align-items:center;
    justify-content:center;
  }

  .burger-lines{ width:18px; height:12px; position:relative; }
  .burger-lines span{
    position:absolute;
    left:0;
    right:0;
    height:2px;
    border-radius:2px;
    background:var(--text);
  }
  .burger-lines span:nth-child(1){ top:0; }
  .burger-lines span:nth-child(2){ top:5px; }
  .burger-lines span:nth-child(3){ top:10px; }

  .dd{ position:relative; }

  .dd-panel,
  .flyout{
    display:none;
    position:absolute;
    top:calc(100% + 4px);
    left:0;
    min-width:250px;
    background:linear-gradient(180deg, rgba(37,37,46,.96), rgba(24,24,31,.96));
    border:1px solid rgba(255,255,255,.08);
    border-radius:18px;
    box-shadow:0 24px 48px rgba(0,0,0,.42), inset 0 1px 0 rgba(255,255,255,.04);
    backdrop-filter: blur(12px);
    padding:10px;
  }

  .dd-panel.open,
  .flyout.open{
    display:block;
    animation:ddIn .16s ease both;
  }

  @keyframes ddIn{
    from{ opacity:0; transform:translateY(-4px) scale(.985); }
    to{ opacity:1; transform:translateY(0) scale(1); }
  }

  .menu-root, .dd-list{
    list-style:none;
    margin:0;
    padding:0;
    display:flex;
    flex-direction:column;
    gap:4px;
  }

  .menu-root button,
  .menu-root a,
  .dd-list a{
    display:flex;
    align-items:center;
    justify-content:space-between;
    width:100%;
    padding:10px 12px;
    border-radius:12px;
    border:1px solid transparent;
    background:rgba(255,255,255,.01);
    color:var(--text);
    text-decoration:none;
    font-size:13px;
    line-height:1.25;
    font-family:inherit;
    font-weight:500;
    cursor:pointer;
    transition:background .14s ease, border-color .14s ease, color .14s ease;
  }

  .menu-root button:hover,
  .menu-root a:hover,
  .dd-list a:hover,
  .menu-root button.active{
    background:rgba(249,201,64,.1);
    border-color:rgba(249,201,64,.25);
    color:#fff4cb;
  }

  .menu-root button[data-sub]::after{
    content:'›';
    color:var(--muted);
  }

  .flyout{
    left:calc(100% - 2px);
    top:0;
    min-width:280px;
    overflow:visible;
  }

  @media (min-width: 901px){
    .dd::after{
      content:"";
      position:absolute;
      left:-8px;
      right:-8px;
      top:100%;
      height:14px;
      display:none;
    }
    .dd:hover::after{
      display:block;
    }
    .flyout::before{
      content:"";
      position:absolute;
      top:0;
      bottom:0;
      left:-16px;
      width:18px;
    }
    #dd-univer-wrap:hover > #dd-univer,
    #dd-hobby-wrap:hover > #dd-hobby{
      display:block;
      animation:ddIn .16s ease both;
    }
  }

  .flyout-title{
    padding:10px 12px 6px;
    color:var(--green);
    font-size:11px;
    font-family:"Space Mono",ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
    text-transform:uppercase;
    letter-spacing:.04em;
  }

  .auth-modal{ position:fixed; inset:0; display:none; z-index:2000; }
  .auth-modal.open{ display:block; }
  .auth-backdrop{ position:absolute; inset:0; background:rgba(0,0,0,.55); }
  .auth-box{
    position:relative;
    width:min(430px, calc(100vw - 24px));
    margin:80px auto 0;
    background:var(--panel);
    border:1px solid var(--line);
    border-radius:16px;
    box-shadow:var(--shadow);
    padding:16px;
  }
  .auth-close{
    position:absolute;
    top:10px;
    right:10px;
    width:32px;
    height:32px;
    border:none;
    background:transparent;
    color:var(--muted);
    cursor:pointer;
    font-size:20px;
  }
  .auth-tabs{ display:flex; gap:8px; margin-bottom:12px; }
  .auth-tab{
    border:1px solid var(--line);
    background:var(--panel-2);
    border-radius:10px;
    padding:8px 12px;
    color:var(--text);
    cursor:pointer;
  }
  .auth-tab.active{
    background:rgba(249,201,64,.08);
    border-color:rgba(249,201,64,.28);
    color:var(--accent);
  }
  .auth-row{ margin-top:10px; }
  .auth-row label{ display:block; font-size:13px; color:var(--muted); }
  .auth-row input{
    width:100%;
    margin-top:6px;
    border:1px solid var(--line);
    border-radius:10px;
    padding:10px 12px;
    background:#151518;
    color:var(--text);
    outline:none;
    font:inherit;
  }
  .auth-actions{ display:flex; gap:10px; margin-top:12px; }
  .auth-btn{
    border:1px solid var(--line);
    background:var(--panel-2);
    border-radius:10px;
    padding:10px 14px;
    color:var(--text);
    cursor:pointer;
  }
  .auth-btn.primary{
    background:var(--accent);
    color:#151518;
    border-color:transparent;
    font-weight:700;
  }
  .auth-err{ margin-top:10px; color:var(--danger); font-size:13px; }

  @media (max-width: 900px){
    :root{
      --header-h:56px;
    }
    .site-header .wrap{
      width:calc(100vw - 24px);
      gap:10px;
    }
    .nav{
      position:fixed;
      left:12px;
      right:12px;
      top:calc(var(--header-h) + 8px);
      z-index:999;
      display:none;
      flex-direction:column;
      align-items:stretch;
      gap:10px;
      padding:12px;
      border:1px solid var(--line);
      border-radius:14px;
      background:rgba(21,21,24,.98);
      box-shadow:var(--shadow);
      max-height:calc(100vh - var(--header-h) - 20px);
      overflow:auto;
    }
    .nav.open{ display:flex; }
    .nav-link,
    .nav-btn{
      justify-content:flex-start;
      min-height:38px;
    }
    .nav-link.active::after, .nav-btn.active::after{
      bottom:4px;
      left:0;
      right:auto;
      width:36px;
    }
    .dd{ width:100%; }
    .dd-panel, .flyout{
      position:static;
      width:100%;
      min-width:0;
      margin-top:8px;
    }
    .menu-root button,
    .menu-root a,
    .dd-list a{
      padding:11px 12px;
    }
    .auth-area{ gap:8px; }
    .auth-user{ display:none; }
    .auth-action{
      padding:6px 9px !important;
      max-width:92px;
      overflow:hidden;
      text-overflow:ellipsis;
    }
    .burger{
      display:flex;
      flex:0 0 auto;
    }
  }

  @media (max-width: 520px){
    .brand-text{ display:none; }
    .site-header .wrap{
      width:calc(100vw - 16px);
    }
    .auth-action{
      max-width:74px;
      font-size:11px;
      padding:6px 7px !important;
    }
    .nav{
      left:8px;
      right:8px;
    }
  }
</style>

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
        <button type="button" class="nav-btn<?= strpos($uri, '/crypto/') === 0 || strpos($uri, '/adminis/') === 0 || strpos($uri, '/coursework/') === 0 ? ' active' : '' ?>" data-dd-btn="univer" aria-expanded="false">Универ</button>
        <div class="dd-panel" id="dd-univer" role="dialog" aria-label="Универ">
          <ul class="menu-root" id="univerRoot">
            <?php foreach ($univer_sections as $key => $section): ?>
              <li><button type="button" data-sub="<?= site_h((string)$key) ?>"><?= site_h((string)$section['title']) ?></button></li>
            <?php endforeach; ?>
          </ul>
          <div class="flyout" id="univerFlyout" aria-hidden="true">
            <?php foreach ($univer_sections as $key => $section): ?>
              <div class="flyout-panel" data-fly-panel="<?= site_h((string)$key) ?>" style="display:none;">
                <div class="flyout-title"><?= site_h((string)$section['title']) ?></div>
                <ul class="dd-list">
                  <?php foreach ($section['items'] as $it): ?>
                    <?php $label = (string)$it[0]; $href = (string)$it[1]; ?>
                    <li><a href="<?= site_h($href) ?>" class="<?= site_active($href, $uri) ? 'active' : '' ?>"><?= site_h($label) ?></a></li>
                  <?php endforeach; ?>
                </ul>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

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
  const panelUniver = document.getElementById('dd-univer');
  const panelHobby = document.getElementById('dd-hobby');
  const univerRoot = document.getElementById('univerRoot');
  const univerFlyout = document.getElementById('univerFlyout');
  const flyPanels = univerFlyout ? univerFlyout.querySelectorAll('[data-fly-panel]') : [];
  let flyoutCloseTimer = null;

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

  function closeFlyout() {
    if (flyoutCloseTimer) {
      clearTimeout(flyoutCloseTimer);
      flyoutCloseTimer = null;
    }
    if (!univerFlyout) return;
    univerFlyout.classList.remove('open');
    univerFlyout.setAttribute('aria-hidden', 'true');
    flyPanels.forEach(panel => panel.style.display = 'none');
    if (!univerRoot) return;
    univerRoot.querySelectorAll('button[data-sub]').forEach(btn => btn.classList.remove('active'));
  }

  function openFlyoutByKey(key, triggerBtn) {
    if (!univerFlyout || !key) return;
    if (flyoutCloseTimer) {
      clearTimeout(flyoutCloseTimer);
      flyoutCloseTimer = null;
    }
    closeFlyout();
    if (triggerBtn) triggerBtn.classList.add('active');
    flyPanels.forEach(panel => {
      panel.style.display = panel.getAttribute('data-fly-panel') === key ? 'block' : 'none';
    });
    univerFlyout.classList.add('open');
    univerFlyout.setAttribute('aria-hidden', 'false');
  }

  function scheduleFlyoutClose() {
    if (isMobile()) return;
    if (flyoutCloseTimer) clearTimeout(flyoutCloseTimer);
    flyoutCloseTimer = setTimeout(() => {
      closeFlyout();
    }, 170);
  }

  function closeAll() {
    [panelUniver, panelHobby].forEach(panel => panel && panel.classList.remove('open'));
    [btnUniver, btnHobby].forEach(btn => {
      if (!btn) return;
      btn.classList.remove('active');
      btn.setAttribute('aria-expanded', 'false');
    });
    closeFlyout();
  }

  function togglePanel(which) {
    const panel = which === 'univer' ? panelUniver : panelHobby;
    const btn = which === 'univer' ? btnUniver : btnHobby;
    if (!panel || !btn) return;
    const open = panel.classList.contains('open');
    closeAll();
    if (open) return;
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

  if (univerRoot && univerFlyout) {
    const subButtons = Array.from(univerRoot.querySelectorAll('button[data-sub]'));
    subButtons.forEach(btn => {
      btn.addEventListener('mouseenter', function () {
        if (isMobile()) return;
        openFlyoutByKey(btn.getAttribute('data-sub'), btn);
      });
      btn.addEventListener('focus', function () {
        if (isMobile()) return;
        openFlyoutByKey(btn.getAttribute('data-sub'), btn);
      });
    });

    if (panelUniver) {
      panelUniver.addEventListener('mouseenter', function () {
        if (flyoutCloseTimer) {
          clearTimeout(flyoutCloseTimer);
          flyoutCloseTimer = null;
        }
      });
      panelUniver.addEventListener('mouseleave', function () {
        scheduleFlyoutClose();
      });
    }

    univerFlyout.addEventListener('mouseenter', function () {
      if (flyoutCloseTimer) {
        clearTimeout(flyoutCloseTimer);
        flyoutCloseTimer = null;
      }
    });
    univerFlyout.addEventListener('mouseleave', function () {
      scheduleFlyoutClose();
    });

    univerRoot.addEventListener('click', function (e) {
      const btn = e.target.closest('button[data-sub]');
      if (!btn) return;
      e.preventDefault();
      e.stopPropagation();

      const key = btn.getAttribute('data-sub');
      const active = btn.classList.contains('active') && univerFlyout.classList.contains('open');
      if (isMobile()) {
        closeFlyout();
        if (active) return;
      }
      openFlyoutByKey(key, btn);
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
