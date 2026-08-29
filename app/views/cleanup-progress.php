<?php use function KitAudit\e; ob_start(); ?>
<main class="main narrow">
    <section class="hero hero-small"><div><p class="eyebrow">Cleanup job</p><h1>Cleanup progress.</h1><p class="lede">This page tracks every selected unsubscribe call. You can leave it open while the job runs.</p></div></section>
    <section class="progress-card cleanup-progress" data-cleanup-panel data-status="<?= e($cleanupProgress['status'] ?? 'idle') ?>">
        <div class="progress-header"><div><span class="section-kicker">Status</span><strong data-cleanup-message><?= e($cleanupProgress['message'] ?: 'No cleanup job has run yet.') ?></strong></div><span class="status-pill status-<?= e($cleanupProgress['status'] ?? 'idle') ?>" data-cleanup-status><?= e(str_replace('_', ' ', $cleanupProgress['status'] ?? 'idle')) ?></span></div>
        <div class="progress-track"><span data-cleanup-progress style="width: <?= (int) ($cleanupProgress['percent'] ?? 0) ?>%"></span></div>
        <div class="progress-meta"><span data-cleanup-count><?= (int) ($cleanupProgress['processed'] ?? 0) ?> / <?= (int) ($cleanupProgress['total'] ?? 0) ?> processed</span><span data-cleanup-failed><?= (int) ($cleanupProgress['failed'] ?? 0) ?> failed</span></div>
    </section>
    <section class="info-card"><p><strong>Important:</strong> a successful call moves the subscriber to Kit's <code>cancelled</code> state. The local job log remains available in SQLite for audit purposes. Re-subscribing should only happen with the person's explicit permission.</p><a class="button button-secondary" href="/">Return to audit</a></section>
</main>
<?php $content = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
