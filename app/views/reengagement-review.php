<?php
use function KitAudit\e;
use function KitAudit\format_date;
$selectedTagId = (int) ($settings['reengagement_tag_id'] ?? 0);
$selectedTagName = null;
foreach ($availableTags ?? [] as $tag) {
    if ((int) $tag['id'] === $selectedTagId) {
        $selectedTagName = (string) $tag['name'];
        break;
    }
}
ob_start();
?>
<main class="main narrow">
    <section class="hero hero-small"><div><p class="eyebrow">Re-engagement cohort</p><h1>Review before tagging.</h1><p class="lede">Choose or create the Kit tag for this list. The app will not send an email or unsubscribe anyone.</p></div></section>

    <?php if ($candidates === []): ?>
        <div class="notice notice-warn">No selected subscribers remain in this filtered group. Return to the audit and refresh the list.</div>
        <a class="button button-secondary" href="/">Return to audit</a>
    <?php else: ?>
        <section class="review-card">
            <div class="section-heading"><div><span class="section-kicker">Proposed cohort</span><h2><?= number_format(count($candidates)) ?> subscriber<?= count($candidates) === 1 ? '' : 's' ?></h2></div><span class="status-pill status-<?= $selectedTagName !== null ? 'complete' : 'idle' ?>"><?= $selectedTagName !== null ? 'Tag: ' . e($selectedTagName) : 'No tag selected' ?></span></div>
            <div class="tag-choice">
                <div class="tag-choice-copy"><span class="section-kicker">Tag choice</span><strong>Choose an existing tag or create one here</strong><small>Creating a tag is safe and reversible. Kit returns the existing tag if the name is already in use.</small></div>
                <form method="post" action="/reengagement/tag/create" class="tag-create-form" data-reengagement-tag-create>
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <label><span class="sr-only">New Kit tag name</span><input type="text" name="tag_name" maxlength="100" placeholder="e.g. COLD" required <?= !$apiConfigured ? 'disabled' : '' ?>></label>
                    <button class="button button-secondary" type="submit" <?= !$apiConfigured ? 'disabled' : '' ?>>Create tag</button>
                </form>
            </div>
            <?php if ($tagError !== null): ?><div class="notice notice-warn">Unable to load Kit tags: <?= e($tagError) ?></div><?php endif; ?>
            <form method="post" action="/reengagement/start" class="confirm-form" data-reengagement-start>
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <label><span>Kit tag to apply</span><small>This choice becomes the default tag used by the resync workflow.</small><select name="tag_id" required <?= !$apiConfigured ? 'disabled' : '' ?>><option value="">Choose a tag</option><?php foreach ($availableTags ?? [] as $tag): ?><option value="<?= (int) $tag['id'] ?>" <?= (int) $tag['id'] === $selectedTagId ? 'selected' : '' ?>><?= e($tag['name']) ?> (#<?= (int) $tag['id'] ?>)</option><?php endforeach; ?><?php if ($selectedTagId > 0 && $selectedTagName === null): ?><option value="<?= $selectedTagId ?>" selected>Configured tag #<?= $selectedTagId ?> (not found)</option><?php endif; ?></select></label>
                <?php if (!$apiConfigured): ?><div class="notice notice-warn">Connect Kit in Settings before choosing or creating a tag.</div><?php endif; ?>
            <div class="review-list">
                <?php foreach ($candidates as $row): ?>
                    <div class="review-row"><span class="avatar"><?= e(strtoupper(substr((string) ($row['first_name'] ?: $row['email_address']), 0, 1))) ?></span><span><strong><?= e($row['email_address']) ?></strong><small><?= e($row['first_name'] ?: 'No first name') ?> · <?= (int) $row['sent'] ?> emails sent · last open <?= e(format_date($row['last_opened'])) ?> · last click <?= e(format_date($row['last_clicked'])) ?></small></span></div>
                <?php endforeach; ?>
            </div>
                <label class="check-label"><input type="checkbox" name="confirm_tag" value="1" required <?= !$apiConfigured ? 'disabled' : '' ?>><span><strong>I reviewed this cohort and want to apply the selected Kit tag.</strong><small>This only adds the tag; it does not send a broadcast or unsubscribe anyone. You can remove the tag in Kit.</small></span></label>
                <label><span>Type TAG to confirm</span><input type="text" name="confirm_phrase" autocomplete="off" required <?= !$apiConfigured ? 'disabled' : '' ?>></label>
                <div class="form-actions"><button class="button button-primary" type="submit" <?= !$apiConfigured ? 'disabled' : '' ?>>Apply tag and track cohort</button><a href="/">Cancel</a></div>
            </form>
        </section>
    <?php endif; ?>
</main>
<?php $content = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
