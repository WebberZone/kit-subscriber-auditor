<?php
use function KitAudit\e;
use function KitAudit\format_date;
$progressStatus = (string) ($reengagementProgress['status'] ?? 'idle');
$active = in_array($progressStatus, ['tagging', 'resyncing'], true);
$canResync = !$active && (int) ($settings['reengagement_tag_id'] ?? 0) > 0;
ob_start();
?>
<main class="main">
    <section class="hero">
        <div><p class="eyebrow">Kit workflow</p><h1>Re-engage before removing.</h1><p class="lede">Tag a cold cohort, write and send the email in Kit, then resync the tag against the actual broadcast you sent.</p></div>
        <div class="hero-actions"><a class="button button-secondary" href="/settings">Re-engagement settings</a></div>
    </section>

    <?php if (!$apiConfigured): ?>
        <div class="notice notice-warn"><strong>Kit connection not configured.</strong> Connect Kit via OAuth or add an API key from Settings before using this workflow.</div>
    <?php elseif ((int) ($settings['reengagement_tag_id'] ?? 0) < 1): ?>
        <div class="notice notice-warn"><strong>No re-engagement tag selected.</strong> Choose an existing Kit tag in Settings first.</div>
    <?php endif; ?>

    <section class="progress-card" data-reengagement-panel data-status="<?= e($progressStatus) ?>">
        <div class="progress-header"><div><span class="section-kicker">Cohort status</span><strong data-reengagement-message><?= e($reengagementProgress['message'] ?? 'No re-engagement run has started.') ?></strong></div><span class="status-pill status-<?= e($progressStatus) ?>" data-reengagement-status><?= e(str_replace('_', ' ', $progressStatus)) ?></span></div>
        <div class="progress-track"><progress data-reengagement-progress max="100" value="<?= (int) ($reengagementProgress['percent'] ?? 0) ?>" aria-label="Re-engagement progress"></progress></div>
        <div class="progress-meta"><span data-reengagement-count><?= (int) ($reengagementProgress['processed'] ?? 0) ?> / <?= (int) ($reengagementProgress['total'] ?? 0) ?> processed</span><span data-reengagement-phase><?= e(str_replace('_', ' ', $reengagementProgress['phase'] ?? 'idle')) ?></span></div>
        <?php if (!empty($reengagementProgress['broadcast_subject'])): ?><div class="sync-worker-row"><span>Broadcast: <?= e($reengagementProgress['broadcast_subject']) ?></span><span>Sent: <?= e(format_date($reengagementProgress['broadcast_sent_at'])) ?></span></div><?php endif; ?>
    </section>

    <section class="workflow-grid">
        <article class="settings-card workflow-card">
            <span class="section-kicker">Step 1</span>
            <h2>Tag cold subscribers</h2>
            <p>From the Audit page, open the <strong>Removal candidates</strong> view, select subscribers, and choose <strong>Tag for re-engagement</strong>.</p>
            <a class="button button-secondary" href="<?= e('/?group=removal') ?>">Open removal candidates</a>
        </article>
        <article class="settings-card workflow-card">
            <span class="section-kicker">Step 2</span>
            <h2>Send from Kit</h2>
            <p>In Kit, create the “do you still want to receive this newsletter?” broadcast and target the configured tag. Send it yourself; this app never sends broadcasts.</p>
        </article>
        <article class="settings-card workflow-card">
            <span class="section-kicker">Step 3</span>
            <h2>Resync when ready</h2>
            <p>Choose the completed broadcast below when you decide it has had enough time. There is no built-in seven-day assumption. The app fetches current active members of the tag and checks click activity from that broadcast's send date.</p>
            <?php if ($broadcastError !== null): ?><div class="notice notice-warn"><?= e('Unable to load completed broadcasts: ' . $broadcastError) ?></div><?php endif; ?>
            <?php if ($canResync): ?>
                <?php if ($availableBroadcasts === []): ?>
                    <p class="muted">No completed broadcasts with a usable send time were returned by Kit.</p>
                <?php else: ?>
                    <form method="post" action="/reengagement/resync" class="confirm-form" data-reengagement-resync>
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <label><span>Broadcast sent</span><select name="broadcast_id" required><?php foreach ($availableBroadcasts as $broadcast): ?><option value="<?= (int) $broadcast['id'] ?>"> <?= e($broadcast['subject']) ?> · <?= e(format_date($broadcast['sent_at'])) ?></option><?php endforeach; ?></select></label>
                        <label class="check-label"><input type="checkbox" name="confirm_resync" value="1" required><span><strong>I sent the selected broadcast to this tag.</strong><small>Resync will refresh the tag membership and post-broadcast click data.</small></span></label>
                        <button class="button button-primary" type="submit">Resync tagged subscribers</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </article>
    </section>

    <?php if (in_array($progressStatus, ['complete', 'complete_with_errors'], true)): ?>
        <section class="table-section">
            <div class="section-heading"><div><span class="section-kicker">Decision list</span><h2><?= number_format(count($staleRows)) ?> stale subscriber<?= count($staleRows) === 1 ? '' : 's' ?></h2></div><span class="muted"><?= (int) ($reengagementProgress['clicked'] ?? 0) ?> clicked since broadcast</span></div>
            <?php if ($staleRows === []): ?>
                <div class="empty">No subscribers were safely marked stale, or every tagged subscriber clicked.</div>
            <?php else: ?>
                <form method="post" action="/cleanup/review">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <div class="review-list">
                        <?php foreach ($staleRows as $row): ?><div class="review-row"><span class="avatar"><?= e(strtoupper(substr((string) ($row['first_name'] ?: $row['email_address']), 0, 1))) ?></span><span><strong><?= e($row['email_address']) ?></strong><small><?= e($row['first_name'] ?: 'No first name') ?> · no click since <?= e(format_date($reengagementProgress['broadcast_sent_at'])) ?> · last click <?= e(format_date($row['last_clicked'])) ?></small></span><input type="hidden" name="subscriber_ids[]" value="<?= (int) $row['subscriber_id'] ?>"></div><?php endforeach; ?>
                    </div>
                    <div class="form-actions"><button class="button button-danger" type="submit">Review stale for unsubscribe</button></div>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>
<?php $content = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
