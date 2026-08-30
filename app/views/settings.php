<?php use function KitAudit\e; ob_start(); ?>
<main class="main narrow">
    <section class="hero hero-small"><div><p class="eyebrow">Configuration</p><h1>Settings</h1><p class="lede">Configure the local Kit API connection and tune the audit rules. Credentials never reach the browser JavaScript.</p></div></section>

    <section class="settings-card">
        <div class="settings-row">
            <div><span class="section-kicker">Kit connection</span><strong><?= $apiConfigured ? 'Ready to sync' : 'Connection needed' ?></strong><small>Use a Kit v4 API key. It is kept server-side and encrypted when saved through this screen.</small></div>
            <span class="status-pill status-<?= $apiConfigured ? 'complete' : 'failed' ?>"><?= $apiConfigured ? 'Configured' : 'Missing' ?></span>
        </div>

        <div class="settings-row">
            <div><strong>API key</strong><small>Status: <?= e($apiKeySource) ?>. The key value is never displayed after saving.</small></div>
            <span class="status-pill status-<?= $apiKeySource === 'not configured' ? 'idle' : 'complete' ?>"><?= $apiKeySource === 'not configured' ? 'Not set' : 'Available' ?></span>
        </div>
        <form method="post" action="/settings" class="settings-form">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="credential_action" value="save_api_key">
            <label><span>Save a Kit v4 API key</span><small>It is authenticated-encrypted before being stored in SQLite.</small><input type="password" name="kit_api_key" autocomplete="new-password" placeholder="Kit v4 API key" required></label>
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
    </section>

    <section class="settings-card">
        <div class="settings-row"><div><span class="section-kicker">Audit rules</span><strong>Local analysis settings</strong><small>These rules affect proposed lists only; syncing never unsubscribes anyone.</small></div></div>
        <form method="post" action="/settings" class="settings-form">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <label><span>Inactivity threshold</span><small>Days without an open and click before a subscriber becomes a removal candidate.</small><div class="input-with-suffix"><input type="number" min="1" max="3650" name="inactivity_threshold_days" value="<?= (int) $settings['inactivity_threshold_days'] ?>" required><span>days</span></div></label>
            <label><span>Minimum emails sent</span><small>Required sends before a subscriber can be a removal candidate.</small><input type="number" min="0" max="100000" name="min_emails_sent" value="<?= (int) $settings['min_emails_sent'] ?>" required></label>
            <label><span>Minimum sends since last engagement</span><small>Requires this many sends since both the last open and last click. Six sends is approximately six monthly broadcasts.</small><input type="number" min="0" max="100000" name="min_sends_since_engagement" value="<?= (int) $settings['min_sends_since_engagement'] ?>" required></label>
            <label><span>Worker batch size</span><small>Subscribers handled per progress step for stats, tagging, and cleanup. 50 is the default; 100 makes fewer browser round-trips but each step takes longer.</small><input type="number" min="1" max="100" name="batch_size" value="<?= (int) $settings['batch_size'] ?>" required></label>
            <label><span>Stats refresh window</span><small>Normal syncs skip subscribers whose stats were refreshed within this many hours. Use Force full resync on the dashboard to ignore this window.</small><div class="input-with-suffix"><input type="number" min="1" max="8760" name="stats_refresh_hours" value="<?= (int) $settings['stats_refresh_hours'] ?>" required><span>hours</span></div></label>
            <label class="check-label"><input type="checkbox" name="dry_run" value="1" <?= (int) $settings['dry_run'] === 1 ? 'checked' : '' ?>><span><strong>Dry-run cleanup mode</strong><small>When enabled, the review screen defaults to simulating the selected unsubscribe calls. You can explicitly disable dry-run for one reviewed job.</small></span></label>
            <div class="form-actions"><button class="button button-primary" type="submit">Save settings</button><a href="/">Back to audit</a></div>
        </form>
    </section>

    <section class="settings-card">
        <div class="settings-row"><div><span class="section-kicker">Re-engagement</span><strong>Tagged click-back workflow</strong><small>Choose the default Kit tag used by resync. You can also choose or create a tag on the cohort review screen. The app never sends the email; create and send that broadcast from Kit.</small></div></div>
        <form method="post" action="/settings" class="settings-form">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <label><span>Default re-engagement tag</span><small>Tags are loaded from Kit. The review screen can choose a different tag for the next cohort.</small><select name="reengagement_tag_id"><option value="0">Choose a tag</option><?php foreach ($availableTags ?? [] as $tag): ?><option value="<?= (int) $tag['id'] ?>" <?= (int) $settings['reengagement_tag_id'] === (int) $tag['id'] ? 'selected' : '' ?>><?= e($tag['name']) ?> (#<?= (int) $tag['id'] ?>)</option><?php endforeach; ?><?php if ((int) $settings['reengagement_tag_id'] > 0 && !array_filter($availableTags ?? [], static fn (array $tag): bool => (int) $tag['id'] === (int) $settings['reengagement_tag_id'])): ?><option value="<?= (int) $settings['reengagement_tag_id'] ?>" selected>Configured tag #<?= (int) $settings['reengagement_tag_id'] ?> (not found)</option><?php endif; ?></select></label>
            <div class="form-actions"><button class="button button-primary" type="submit">Save re-engagement settings</button><a href="/reengagement">Open workflow</a></div>
        </form>
    </section>

    <section class="info-card"><span class="section-kicker">Rules in this app</span><p><strong>Removal candidates:</strong> no open and no click for the configured threshold, the configured number of sends since both forms of engagement, subscribed before the threshold, and at least the configured number of emails sent.</p><p><strong>Very cold:</strong> no open and no click for 365 days and at least 10 emails sent.</p><p><strong>Unsubscribe:</strong> Kit moves a subscriber to <code>cancelled</code>. Kit retains the record, history, and tags; Kit documents this as effectively permanent and requiring the subscriber's explicit permission to re-subscribe.</p><p><strong>Encryption:</strong> local credentials are authenticated-encrypted with a key in <code>storage/.credentials.key</code>. Both the key and SQLite database are excluded from Git.</p></section>
</main>
<?php $content = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
