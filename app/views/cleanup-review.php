<?php
use function KitAudit\e;
use function KitAudit\format_date;
ob_start();
?>
<main class="main narrow">
    <section class="hero hero-small"><div><p class="eyebrow">Destructive action review</p><h1>Review before unsubscribe.</h1><p class="lede">This is the proposed list, rechecked against your current rule. Nothing has changed in Kit yet.</p></div></section>
    <section class="notice notice-danger"><strong><?= number_format(count($candidates)) ?> subscriber<?= count($candidates) === 1 ? '' : 's' ?> selected.</strong> Unsubscribing moves them to Kit's cancelled state. It is not a delete, but Kit describes it as effectively permanent.</section>
    <?php if ($candidates !== []): ?>
        <section class="review-card">
            <div class="section-heading"><div><span class="section-kicker">Proposed removal list</span><h2>Selected subscribers</h2></div><a class="button button-secondary" href="/export.csv?group=removal">Export all candidates</a></div>
            <div class="review-list">
                <?php foreach ($candidates as $row): ?><div class="review-row"><span class="avatar"><?= e(strtoupper(substr((string) ($row['first_name'] ?: $row['email_address']), 0, 1))) ?></span><span><strong><?= e($row['email_address']) ?></strong><small><?= e($row['first_name'] ?: 'No first name') ?> · <?= (int) $row['sent'] ?> emails sent · last open <?= e(format_date($row['last_opened'])) ?> · last click <?= e(format_date($row['last_clicked'])) ?></small></span></div><?php endforeach; ?>
            </div>
            <form method="post" action="/cleanup/start" class="confirm-form" data-cleanup-start>
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <label class="check-label"><input type="checkbox" name="confirm_export" value="1" required><span><strong>I reviewed or exported this proposed list.</strong><small>Export it above if you want a durable record before proceeding.</small></span></label>
                <label><span>Type <code>UNSUBSCRIBE</code> to confirm</span><input type="text" name="confirm_phrase" autocomplete="off" required pattern="UNSUBSCRIBE" placeholder="UNSUBSCRIBE"></label>
                <?php if ((int) $settings['dry_run'] === 1): ?><div class="notice notice-info">Dry-run mode is enabled. Starting this will simulate the action locally and will not call Kit.</div><?php endif; ?>
                <div class="form-actions"><button class="button button-danger" type="submit"><?= (int) $settings['dry_run'] === 1 ? 'Run dry-run review' : 'Start unsubscribe job' ?></button><a href="/">Cancel</a></div>
            </form>
        </section>
    <?php else: ?>
        <section class="empty-card"><h2>No candidates remain</h2><p>The selected records no longer match the configured rule. Return to the audit and sync again if needed.</p><a class="button button-secondary" href="/">Back to audit</a></section>
    <?php endif; ?>
</main>
<?php $content = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
