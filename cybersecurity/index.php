<?php
require __DIR__ . '/includes/layout.php';
cs_head('Кибербезопасность');
?>
<main class="page">
  <div class="page-wrap">
    <div class="hero-tag">// cybersecurity</div>
    <h1 class="page-title">Кибербезопасность</h1>
    <p class="page-sub">Интерактивные шпаргалки и тренажёры по сетям и безопасности: смотреть, щёлкать, проверять себя.</p>

    <div class="tools">
      <?php foreach ($cs_tools as $t): ?>
        <a class="tool-card" href="<?= cs_h(cs_url($t[0])) ?>">
          <span class="tool-card__tag"><?= cs_h($t[3]) ?></span>
          <span class="tool-card__title"><?= cs_h($t[1]) ?></span>
          <p class="tool-card__desc"><?= cs_h($t[2]) ?></p>
          <span class="tool-card__go">открыть →</span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</main>
<?php cs_foot(); ?>
