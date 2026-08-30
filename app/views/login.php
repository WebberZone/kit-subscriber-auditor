<?php use function KitAudit\e; ob_start(); ?>
<main class="main narrow login-main">
    <section class="login-card">
        <span class="brand-mark">K</span>
        <p class="eyebrow">Private local tool</p>
        <h1>Sign in to Kit Audit.</h1>
        <?php if (empty($authConfigured)): ?>
            <div class="notice notice-warn">Set <code>APP_PASSWORD</code> in your ignored <code>.env</code> file before signing in. Use a long random value.</div>
        <?php else: ?>
            <p class="lede">This app requires the <code>APP_PASSWORD</code> from your local <code>.env</code>.</p>
            <form method="post" action="/login" class="settings-form">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <label><span>App password</span><input type="password" name="password" autocomplete="current-password" required autofocus></label>
                <button class="button button-primary" type="submit">Sign in</button>
            </form>
        <?php endif; ?>
    </section>
</main>
<?php $content = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
