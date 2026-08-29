<?php use function KitAudit\e; ob_start(); ?>
<main class="main narrow"><section class="empty-card"><p class="eyebrow">Something went wrong</p><h1><?= e($pageTitle) ?></h1><p><?= e($message) ?></p><a class="button button-secondary" href="/">Return to audit</a></section></main>
<?php $content = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
