<?php
use function KitAudit\e;
use function KitAudit\format_date;
ob_start();
?>
<main class="main narrow">
    <section class="hero hero-small"><div><p class="eyebrow">Re-engagement cohort</p><h1>Review before tagging.</h1><p class="lede">The app will apply the configured Kit tag to this list. It will not send an email or unsubscribe anyone.</p></div></section>

    <?php if ($candidates === []): ?>
        <div class="notice notice-warn">No selected subscribers remain in this filtered group. Return to the audit and refresh the list.</div>
        <a class="button button-secondary" href="/">Return to audit</a>
    <?php else: ?>
        <section class="review-card">
            <div class="section-heading"><div><span class="section-kicker">Proposed cohort</span><h2><?= number_format(count($candidates)) ?> subscriber<?= count($candidates) === 1 ? '' : 's' ?></h2></div><span class="status-pill status-pending">Kit tag #<?= (int) $settings['reengagement_tag_id'] ?></span></div>
            <div class="review-list">
                <?php foreach ($candidates as $row): ?>
                    <div class="review-row"><span class="avatar"><?= e(strtoupper(substr((string) ($row['first_name'] ?: $row['email_address']), 0, 1))) ?></span><span><strong><?= e($row['email_address']) ?></strong><small><?= e($row['first_name'] ?: 'No first name') ?> · <?= (int) $row['sent'] ?> emails sent · last open <?= e(format_date($row['last_opened'])) ?> · last click <?= e(format_date($row['last_clicked'])) ?></small></span></div>
                <?php endforeach; ?>
            </div>
            <form method="post" action="/reengagement/start" class="confirm-form" data-reengagement-start>
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <label class="check-label"><input type="checkbox" name="confirm_tag" value="1" required><span><strong>I reviewed this cohort and want to apply the configured Kit tag.</strong><small>This only adds the tag; it does not send a broadcast or unsubscribe anyone. You can remove the tag in Kit.</small></span></label>
                <label><span>Type TAG to confirm</span><input type="text" name="confirm_phrase" autocomplete="off" required></label>
                <div class="form-actions"><button class="button button-primary" type="submit">Apply tag and track cohort</button><a href="/">Cancel</a></div>
            </form>
        </section>
    <?php endif; ?>
</main>
<?php $content = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
