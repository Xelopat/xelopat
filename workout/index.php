<?php
// /workout — дневник тренировок. Только админ.
// План: workout/plan.php, данные и напоминания: workout/api.php, утренний пуш: workout/cron.php.
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/auth/lib.php';
require_role('admin');

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/push_lib.php';

$user = auth_current_user();
$plan = workout_plan();
$vapid = push_vapid();

$boot = [
    'plan' => $plan,
    'state' => workout_public_state(workout_user((string)$user['id'])),
    'csrf' => csrf_token(),
    'vapid' => $vapid['public'] ?? null,
];

$v = static fn(string $f): int => @filemtime(__DIR__ . '/' . $f) ?: 1;

$site_page_title = 'Тренировки';
$site_page_noindex = true;
$site_page_head_extra = '<link rel="stylesheet" href="/workout/workout.css?v=' . $v('workout.css') . '">'
    . '<link rel="manifest" href="/workout/manifest.webmanifest">'
    . '<meta name="theme-color" content="#151518">'
    . '<meta name="apple-mobile-web-app-capable" content="yes">';
include $_SERVER['DOCUMENT_ROOT'] . '/header.php';
?>
<main class="page wo">
  <div class="page-wrap">
    <h1 class="page-title" data-type="off">Тренировки</h1>
    <div class="wo-tabs" role="tablist" id="woTabs">
      <button type="button" class="wo-tab" role="tab" data-tab="today">Сегодня</button>
      <button type="button" class="wo-tab" role="tab" data-tab="week">Неделя</button>
      <button type="button" class="wo-tab" role="tab" data-tab="progress">Прогресс</button>
      <button type="button" class="wo-tab" role="tab" data-tab="stats">Статистика</button>
      <button type="button" class="wo-tab" role="tab" data-tab="plan">План и питание</button>
    </div>

    <div id="woView"><noscript>Для страницы нужен JavaScript.</noscript></div>
    <section class="wo-reminder panel" id="woReminder" aria-label="Напоминания"></section>
  </div>

  <div class="wo-timer" id="woTimer" hidden>
    <span class="wo-timer-label" id="woTimerLabel">Отдых</span>
    <span class="wo-timer-time" id="woTimerTime">0:00</span>
    <button type="button" class="btn" id="woTimerAdd">+30 с</button>
    <button type="button" class="btn" id="woTimerStop">Стоп</button>
  </div>
</main>

<script>window.WORKOUT = <?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="/workout/workout.js?v=<?= $v('workout.js') ?>" defer></script>
