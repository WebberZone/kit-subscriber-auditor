<?php use function KitAudit\e; ob_start(); ?>
<main class="main narrow">
    <section class="hero hero-small"><div><p class="eyebrow">Configuration</p><h1>Settings</h1><p class="lede">Connect Kit privately, then tune the local audit rules. Credentials never reach the browser JavaScript.</p></div></section>

    <section class="settings-card">
        <div class="settings-row">
            <div><span class="section-kicker">Kit connection</span><strong><?= $apiConfigured ? 'Ready to sync' : 'Connection needed' ?></strong><small>OAuth is preferred. An API key can be saved as an encrypted local fallback.</small></div>
            <span class="status-pill status-<?= $apiConfigured ? 'complete' : 'failed' ?>"><?= $apiConfigured ? 'Configured' : 'Missing' ?></span>
        </div>

        <div class="settings-row">
            <div><strong>OAuth</strong><small><?= $oauthStatus['connected'] ? 'Connected with an encrypted access and refresh token.' : 'Authorize this local app through Kit. No API key is required.' ?></small></div>
            <?php if ($oauthStatus['connected'] && (int) ($oauthStatus['expires_at'] ?? 0) > 0): ?><small>Token refreshes automatically before expiry (<?= e(gmdate('Y-m-d H:i', (int) $oauthStatus['expires_at'])) ?> UTC).</small><?php endif; ?>
        </div>
        <div class="form-actions">
            <?php if ($oauthConfigured): ?>
                <a class="button button-primary" href="/oauth/start"><?= $oauthStatus['connected'] ? 'Reconnect with OAuth' : 'Connect with OAuth' ?></a>
            <?php else: ?>
                <span class="muted">Add the OAuth settings from <code>.env.example</code> to enable account linking.</span>
            <?php endif; ?>
            <?php if ($oauthStatus['connected']): ?>
                <form method="post" action="/oauth/disconnect"><input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>"><button class="button button-secondary" type="submit">Disconnect OAuth</button></form>
            <?php endif; ?>
        </div>

        <div class="settings-row">
            <div><strong>API key fallback</strong><small>Status: <?= e($apiKeySource) ?>. The key value is never displayed after saving.</small></div>
            <span class="status-pill status-<?= $apiKeySource === 'not configured' ? 'idle' : 'complete' ?>"><?= $apiKeySource === 'not configured' ? 'Not set' : 'Available' ?></span>
        </div>
        <form method="post" action="/settings" class="settings-form">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="credential_action" value="save_api_key">
            <label><span>Save a Kit v4 API key</span><small>Paste it here only if you prefer API-key authentication or OAuth is unavailable. It is encrypted before being stored in SQLite.</small><input type="password" name="kit_api_key" autocomplete="new-password" placeholder="Kit v4 API key" required></label>
            <div class="form-actions"><button class="button button-secondary" type="submit">Save encrypted API key</button></div>
        </form>
        <?php if ($apiKeySource === 'encrypted SQLite'): ?>
            <form method="post" action="/settings" class="form-actions">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="credential_action" value="clear_api_key">
                <button class="button button-danger" type="submit">Remove stored API key</button>
            </form>
        <?php endif; ?>
    </section>

    <section class="settings-card">
        <div class="settings-row"><div><span class="section-kicker">Audit rules</span><strong>Local analysis settings</strong><small>These rules affect proposed lists only; syncing never unsubscribes anyone.</small></div></div>
        <form method="post" action="/settings" class="settings-form">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <label><span>Inactivity threshold</span><small>Days without an open and click before a subscriber becomes a removal candidate.</small><div class="input-with-suffix"><input type="number" min="1" max="3650" name="inactivity_threshold_days" value="<?= (int) $settings['inactivity_threshold_days'] ?>" required><span>days</span></div></label>
            <label><span>Minimum emails sent</span><small>Required sends before a subscriber can be a removal candidate.</small><input type="number" min="0" max="100000" name="min_emails_sent" value="<?= (int) $settings['min_emails_sent'] ?>" required></label>
            <label><span>Stats batch size</span><small>Stats requests per progress step. Smaller values keep each web request short.</small><input type="number" min="1" max="50" name="batch_size" value="<?= (int) $settings['batch_size'] ?>" required></label>
            <label class="check-label"><input type="checkbox" name="dry_run" value="1" <?= (int) $settings['dry_run'] === 1 ? 'checked' : '' ?>><span><strong>Dry-run cleanup mode</strong><small>When enabled, the cleanup flow simulates the selected unsubscribe calls and makes no Kit changes.</small></span></label>
            <div class="form-actions"><button class="button button-primary" type="submit">Save settings</button><a href="/">Back to audit</a></div>
        </form>
    </section>

    <section class="info-card"><span class="section-kicker">Rules in this app</span><p><strong>Removal candidates:</strong> no open and no click for the configured threshold, subscribed before the threshold, and at least the configured number of emails sent.</p><p><strong>Very cold:</strong> no open and no click for 365 days and at least 10 emails sent.</p><p><strong>Unsubscribe:</strong> Kit moves a subscriber to <code>cancelled</code>. Kit retains the record, history, and tags; Kit documents this as effectively permanent and requiring the subscriber's explicit permission to re-subscribe.</p><p><strong>Encryption:</strong> local credentials are authenticated-encrypted with a key in <code>storage/.credentials.key</code>. Both the key and SQLite database are excluded from Git.</p></section>
</main>
<?php $content = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
