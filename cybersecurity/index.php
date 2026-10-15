<?php
require __DIR__ . '/includes/layout.php';
cs_head('Кибербезопасность');
?>
<main class="page">
  <div class="page-wrap">
    <div class="hero-tag">// cybersecurity</div>
    <h1 class="page-title">Кибербезопасность</h1>
    <p class="page-sub">Интерактивные шпаргалки и тренажёры по сетям и безопасности: смотреть, щёлкать, проверять себя.</p>

    <?php foreach ($cs_sections as $sec): ?>
      <section class="tools-sec">
        <div class="page-label">// <?= cs_h($sec[0]) ?></div>
        <h2 class="tools-sec__title"><?= cs_h($sec[1]) ?></h2>
        <div class="tools">
          <?php foreach ($sec[2] as $t): ?>
            <a class="tool-card" href="<?= cs_h(cs_url($t[0])) ?>">
              <span class="tool-card__tag"><?= cs_h($t[3]) ?></span>
              <span class="tool-card__title"><?= cs_h($t[1]) ?></span>
              <p class="tool-card__desc"><?= cs_h($t[2]) ?></p>
              <span class="tool-card__go">открыть -&gt;</span>
            </a>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endforeach; ?>
  </div>
</main>
<?php cs_foot(); ?>
