<?php
/** @var string $pageTitle */
$pageTitle = $pageTitle ?? APP_NAME;
$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
$flashes = consume_flashes();
?>
<!doctype html>
<html lang="sv">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#e7442e">
    <title><?= e($pageTitle) ?> · <?= e(APP_NAME) ?></title>
    <link rel="icon" href="<?= e(app_url('assets/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(app_url('assets/style.css')) ?>">
    <script src="<?= e(app_url('assets/app.js')) ?>" defer></script>
</head>
<body>
<header class="site-header">
    <div class="shell nav-wrap">
        <a class="brand" href="<?= e(app_url('index.php')) ?>" aria-label="Lokala fynd, startsida">
            <span class="brand-mark" aria-hidden="true">%</span>
            <span><?= e(APP_NAME) ?></span>
        </a>
        <nav class="main-nav" aria-label="Huvudmeny">
            <a class="<?= $currentPage === 'index.php' ? 'active' : '' ?>" href="<?= e(app_url('index.php')) ?>">Erbjudanden</a>
            <a class="<?= in_array($currentPage, ['manage.php', 'store_form.php', 'deal_form.php'], true) ? 'active' : '' ?>" href="<?= e(app_url('manage.php')) ?>">Hantera</a>
            <a class="<?= $currentPage === 'sync.php' ? 'active' : '' ?>" href="<?= e(app_url('sync.php')) ?>">ICA-synk</a>
        </nav>
    </div>
</header>

<?php if ($flashes): ?>
    <div class="shell flash-stack" aria-live="polite">
        <?php foreach ($flashes as $flash): ?>
            <div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<main>
