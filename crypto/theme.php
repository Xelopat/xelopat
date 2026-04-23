<?php
declare(strict_types=1);

if (!function_exists('crypto_h')) {
    function crypto_h(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('crypto_page_start')) {
    function crypto_page_start(string $title, string $subtitle = ''): void {
        ?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= crypto_h($title) ?></title>
    <link rel="stylesheet" href="/crypto/theme.css">
</head>
<body class="crypto-page">
<?php @include $_SERVER['DOCUMENT_ROOT'] . '/header.php'; ?>
<main class="crypto-shell">
    <section class="crypto-card">
        <a class="crypto-back" href="/">← На главную</a>
        <h1 class="crypto-title"><?= crypto_h($title) ?></h1>
        <?php if ($subtitle !== ''): ?>
            <p class="crypto-subtitle"><?= crypto_h($subtitle) ?></p>
        <?php endif; ?>
<?php
    }
}

if (!function_exists('crypto_page_end')) {
    function crypto_page_end(): void {
        ?>
    </section>
</main>
</body>
</html>
<?php
    }
}
