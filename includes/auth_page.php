<?php
// Страницы /auth/login и /auth/register: одна карточка с формой, ошибка показывается прямо в ней.
declare(strict_types=1);

// Куда вернуться после входа. Ссылки на сами страницы входа не годятся, иначе человек снова попадёт на форму
function auth_page_next(string $raw): string {
    $next = safe_next($raw, '/');
    return strpos($next, '/auth/') === 0 ? '/' : $next;
}

function auth_page(string $mode, string $next, string $error = '', string $username = ''): void {
    $is_login = $mode === 'login';
    $site_page_title = ($is_login ? 'Вход' : 'Регистрация') . ' — xelopat';
    $site_page_noindex = true;
    $other = ($is_login ? '/auth/register' : '/auth/login') . ($next !== '/' ? '?next=' . urlencode($next) : '');
    include $_SERVER['DOCUMENT_ROOT'] . '/header.php';
    $csrf = csrf_token();
    ?>
<style>
  .auth-page{ width:min(400px, calc(100vw - 24px)); margin:0 auto; padding-top:6vh; }
  .auth-page .page-title{ margin-bottom:18px; }
  .auth-form{ display:grid; gap:14px; }
  .auth-form .btn{ width:100%; min-height:44px; margin-top:4px; }
  .auth-switch{ margin:16px 0 0; text-align:center; color:var(--muted); font-size:13px; }
  .auth-switch a{ color:var(--accent); text-decoration:none; }
  .auth-switch a:hover{ text-decoration:underline; }
</style>
<main class="page">
  <div class="auth-page">
    <div class="page-label">// <?= $is_login ? 'login' : 'register' ?></div>
    <h1 class="page-title"><?= $is_login ? 'Вход' : 'Регистрация' ?></h1>
    <form class="panel auth-form" method="post" action="/auth/<?= $is_login ? 'login' : 'register' ?>">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="next" value="<?= h($next) ?>">
      <?php if ($error !== ''): ?>
        <p class="form-error" role="alert"><?= h($error) ?></p>
      <?php endif; ?>
      <div class="field">
        <label for="authUser">Логин</label>
        <input id="authUser" name="username" value="<?= h($username) ?>" autocomplete="username" autocapitalize="off" spellcheck="false" required<?= $username === '' ? ' autofocus' : '' ?>>
      </div>
      <div class="field">
        <label for="authPass">Пароль</label>
        <input id="authPass" type="password" name="password" autocomplete="<?= $is_login ? 'current-password' : 'new-password' ?>" required<?= $username !== '' ? ' autofocus' : '' ?>>
      </div>
      <?php if (!$is_login): ?>
        <div class="field">
          <label for="authPass2">Повтори пароль</label>
          <input id="authPass2" type="password" name="password2" autocomplete="new-password" required>
        </div>
      <?php endif; ?>
      <button class="btn btn--primary" type="submit"><?= $is_login ? 'Войти' : 'Создать аккаунт' ?></button>
    </form>
    <p class="auth-switch">
      <?= $is_login ? 'Нет аккаунта?' : 'Уже есть аккаунт?' ?>
      <a href="<?= h($other) ?>"><?= $is_login ? 'Регистрация' : 'Войти' ?></a>
    </p>
  </div>
</main>
<?php
    exit;
}
