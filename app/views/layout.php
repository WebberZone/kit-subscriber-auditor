<?php use function KitAudit\e; ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e($csrfToken ?? '') ?>">
    <title><?= e($pageTitle ?? 'Kit subscriber audit') ?> · Kit Audit</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<div class="shell">
    <header class="topbar">
        <a class="brand" href="/">
            <span class="brand-mark">K</span>
            <span>Kit Audit</span>
        </a>
        <nav class="nav" aria-label="Primary navigation">
            <a href="/" class="<?= ($pageTitle ?? '') === 'Subscriber audit' ? 'active' : '' ?>">Audit</a>
            <a href="/settings" class="<?= ($pageTitle ?? '') === 'Settings' ? 'active' : '' ?>">Settings</a>
            <?php if (!empty($authEnabled)): ?><form method="post" action="/logout"><input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>"><button class="nav-logout" type="submit">Sign out</button></form><?php endif; ?>
        </nav>
    </header>

    <?php foreach (($flashMessages ?? []) as $flash): ?>
        <div class="flash flash-<?= e($flash['type']) ?>" role="alert"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>

    <?= $content ?>

    <footer class="footer">
        <span>Local data only · Kit credentials stay server-side</span>
        <a href="https://developers.kit.com/api-reference/subscribers/list-subscribers" target="_blank" rel="noreferrer">Kit API docs ↗</a>
    </footer>
</div>
<script src="/assets/app.js?v=2" defer></script>
</body>
</html>
