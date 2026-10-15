<?php
require_once __DIR__ . '/theme.php';

$crypto_groups = [
    'Булевы функции и регистры сдвига' => ['triangle', 'no_line', 'hmm_cycles', 'messi'],
    'Электронная подпись' => ['check_signature', 'get_signature', 'check_signature_eleptic', 'get_signature_gost', 'check_signature_eleptic_gost'],
    'Эллиптические кривые' => ['eleptic_sum'],
];
$tools = crypto_tools();

$site_page_title = 'Калькуляторы по криптографии с решением — xelopat';
$site_page_description = 'Онлайн-калькуляторы по криптографии с пошаговым решением: полином Жегалкина, нелинейность булевой функции, алгоритм Берлекэмпа — Мэсси, подпись Эль-Гамаля, ECDSA, ГОСТ Р 34.10-2012 и сложение точек эллиптической кривой.';
$site_page_jsonld = [
    '@context' => 'https://schema.org',
    '@type' => 'CollectionPage',
    'name' => 'Калькуляторы по криптографии',
    'description' => $site_page_description,
    'url' => 'https://xelopat.ru/crypto',
    'inLanguage' => 'ru',
    'hasPart' => array_map(static function (string $slug, array $t): array {
        return ['@type' => 'WebApplication', 'name' => $t['h1'], 'url' => 'https://xelopat.ru/crypto/' . $slug];
    }, array_keys($tools), $tools),
];
include $_SERVER['DOCUMENT_ROOT'] . '/header.php';
?>
<style>
  .cr-group{ margin-top:18px; }
  .cr-group-title{
    margin:0 0 10px;
    font-family:var(--mono);
    font-size:12px;
    color:var(--green);
    text-transform:uppercase;
    letter-spacing:.04em;
  }
  .cr-list{
    display:grid;
    grid-template-columns:repeat(auto-fill, minmax(300px, 1fr));
    gap:12px;
    margin:0;
    padding:0;
    list-style:none;
  }
  .cr-item{
    display:block;
    height:100%;
    box-sizing:border-box;
    text-decoration:none;
    color:var(--text);
    transition:border-color .15s;
  }
  .cr-item:hover{ border-color:var(--accent); }
  .cr-item h3{ margin:0 0 6px; font-size:15px; line-height:1.35; }
  .cr-item p{ margin:0; color:var(--text-2); font-size:13px; line-height:1.55; }
</style>

<main class="page">
  <div class="page-wrap">
    <div class="page-label">// МЭИ</div>
    <h1 class="page-title">Калькуляторы по криптографии</h1>
    <p class="page-sub">Онлайн-калькуляторы к задачам по криптографии: каждый показывает решение по шагам, а не только ответ. Всё считается прямо в браузере.</p>

    <?php foreach ($crypto_groups as $group_title => $slugs): ?>
      <section class="cr-group">
        <h2 class="cr-group-title"><?= crypto_h($group_title) ?></h2>
        <ul class="cr-list">
          <?php foreach ($slugs as $slug): $t = $tools[$slug]; ?>
            <li>
              <a class="cr-item panel" href="/crypto/<?= crypto_h($slug) ?>">
                <h3><?= crypto_h($t['h1']) ?></h3>
                <p><?= crypto_h($t['description']) ?></p>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
    <?php endforeach; ?>
  </div>
</main>
</body>
</html>
