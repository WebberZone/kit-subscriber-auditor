<?php
use function KitAudit\e;
use function KitAudit\format_date;
$dryRunEnabled = (int) $settings['dry_run'] === 1;
ob_start();
?>
<main class="main narrow">
    <section class="hero hero-small"><div><p class="eyebrow">Destructive action review</p><h1>Review before unsubscribe.</h1><p class="lede">This is the proposed list, rechecked against the selected filter. Nothing has changed in Kit yet.</p></div></section>
    <section class="notice notice-danger"><strong><?= number_format(count($candidates)) ?> subscriber<?= count($candidates) === 1 ? '' : 's' ?> selected.</strong> Unsubscribing moves them to Kit's cancelled state. It is not a delete, but Kit describes it as effectively permanent.</section>
    <?php if ($candidates !== []): ?>
        <section class="review-card">
            <div class="section-heading"><div><span class="section-kicker">Proposed removal list</span><h2>Selected subscribers</h2></div><a class="button button-secondary" href="/cleanup/export.csv">Export proposed list</a></div>
            <div class="review-list">
                <?php foreach ($candidates as $row): ?><div class="review-row"><span class="avatar"><?= e(strtoupper(substr((string) ($row['first_name'] ?: $row['email_address']), 0, 1))) ?></span><span><strong><?= e($row['email_address']) ?></strong><small><?= e($row['first_name'] ?: 'No first name') ?> · <?= (int) $row['sent'] ?> emails sent · <?= (int) ($row['sends_since_last_open'] ?? $row['sent'] ?? 0) ?> since open · <?= (int) ($row['sends_since_last_click'] ?? $row['sent'] ?? 0) ?> since click · last open <?= e(format_date($row['last_opened'])) ?> · last click <?= e(format_date($row['last_clicked'])) ?></small></span></div><?php endforeach; ?>
            </div>
            <form method="post" action="/cleanup/start" class="confirm-form" data-cleanup-start>
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <label class="check-label"><input type="checkbox" name="confirm_export" value="1" required><span><strong>I reviewed or exported this proposed list.</strong><small>Export it above if you want a durable record before proceeding.</small></span></label>
                <label class="check-label"><input type="hidden" name="cleanup_dry_run" value="0"><input type="checkbox" name="cleanup_dry_run" value="1" data-cleanup-dry-run <?= $dryRunEnabled ? 'checked' : '' ?>><span><strong data-cleanup-mode-label><?= $dryRunEnabled ? 'Dry-run mode is enabled' : 'Live unsubscribe mode is enabled' ?></strong><small data-cleanup-mode-help><?= $dryRunEnabled ? 'Keep checked to simulate this job locally. Uncheck to allow real Kit unsubscribe calls for this job.' : 'This job will make real Kit unsubscribe calls. Check to simulate it locally instead.' ?></small></span></label>
                <label><span>Type <code>UNSUBSCRIBE</code> to confirm</span><input type="text" name="confirm_phrase" autocomplete="off" required pattern="UNSUBSCRIBE" placeholder="UNSUBSCRIBE"></label>
                <div class="notice <?= $dryRunEnabled ? 'notice-info' : 'notice-danger' ?>" data-cleanup-mode-notice><?= $dryRunEnabled ? 'Dry-run mode is enabled. Starting this will simulate the action locally and will not call Kit.' : 'Live mode is selected. Starting this will make real Kit unsubscribe calls.' ?></div>
                <div class="form-actions"><button class="button button-danger" type="submit" data-cleanup-submit-button><?= $dryRunEnabled ? 'Run dry-run review' : 'Start unsubscribe job' ?></button><a href="/">Cancel</a></div>
            </form>
        </section>
    <?php else: ?>
        <section class="empty-card"><h2>No candidates remain</h2><p>The selected records no longer match the chosen filter. Return to the audit and sync again if needed.</p><a class="button button-secondary" href="/">Back to audit</a></section>
    <?php endif; ?>
</main>
<?php $content = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
