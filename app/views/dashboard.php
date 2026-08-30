<?php
use function KitAudit\date_age;
use function KitAudit\e;
use function KitAudit\format_date;
use function KitAudit\format_percent;
use function KitAudit\query_url;
$workerStatus = $syncProgress['worker']['status'] ?? 'not_running';
$workerPid = $syncProgress['worker']['pid'] ?? null;
$workerLabel = $workerStatus === 'active' ? 'Worker active' : ($workerStatus === 'stale' ? 'Worker heartbeat stale' : 'Worker stopped');
if ($workerStatus === 'active' && $workerPid !== null) {
    $workerLabel .= ' · PID ' . (int) $workerPid;
}
$selectedGroup = $filters['group'];
ob_start();
?>
<main class="main">
    <section class="hero">
        <div>
            <p class="eyebrow">Subscriber intelligence</p>
            <h1>Keep your list healthy.</h1>
            <p class="lede">A local snapshot of engagement signals, with a deliberate review step before any subscriber is unsubscribed.</p>
        </div>
        <div class="hero-actions">
            <?php if (!$apiConfigured): ?>
                <a class="button button-warn" href="/settings">Connect Kit</a>
            <?php else: ?>
                <div class="sync-actions"><button class="button button-primary" type="button" data-sync-start>Sync changes</button><label class="sync-option"><input type="checkbox" data-sync-force-full><span>Force full resync<small>Ignore freshness window</small></span></label></div>
            <?php endif; ?>
        </div>
    </section>

    <?php if (!$apiConfigured): ?>
        <div class="notice notice-warn"><strong>Kit connection not configured.</strong> Add an API key from Settings before syncing.</div>
    <?php endif; ?>

    <section class="progress-card" data-sync-panel data-status="<?= e($syncProgress['status'] ?? 'idle') ?>">
        <div class="progress-header">
            <div>
                <span class="section-kicker">Sync status</span>
                <strong data-sync-message><?= e($syncProgress['message'] ?? 'No sync has run yet.') ?></strong>
            </div>
            <span class="status-pill status-<?= e($syncProgress['status'] ?? 'idle') ?>" data-sync-status><?= e(str_replace('_', ' ', $syncProgress['status'] ?? 'idle')) ?></span>
        </div>
        <div class="progress-track"><progress data-sync-progress max="100" value="<?= (int) ($syncProgress['percent'] ?? 0) ?>" aria-label="Sync progress"></progress></div>
        <div class="progress-meta">
            <span data-sync-count><?= e($syncProgress['count_message'] ?? ((int) ($syncProgress['processed'] ?? 0) . ' / ' . (int) ($syncProgress['total'] ?? 0) . ' subscribers with stats')) ?></span>
            <span data-sync-phase><?= e(str_replace('_', ' ', $syncProgress['phase'] ?? 'idle')) ?></span>
        </div>
        <div class="sync-worker-row"><span data-sync-worker class="sync-worker-status sync-worker-<?= e($workerStatus) ?>"><?= e($workerLabel) ?></span><span>Stats refresh window: <?= (int) $settings['stats_refresh_hours'] ?> hours</span></div>
    </section>

    <section class="metric-grid" aria-label="Subscriber metrics">
        <a class="metric-card metric-card-featured <?= $selectedGroup === 'all' ? 'metric-card-selected' : '' ?>" href="<?= e(query_url('/', ['group' => 'all'])) ?>" <?= $selectedGroup === 'all' ? 'aria-current="page"' : '' ?>>
            <span class="metric-label">Active subscribers</span>
            <strong><?= number_format((int) $metrics['total_active']) ?></strong>
            <span class="metric-detail">Current local snapshot</span>
        </a>
        <a class="metric-card metric-card-alert <?= $selectedGroup === 'removal' ? 'metric-card-selected' : '' ?>" href="<?= e(query_url('/', ['group' => 'removal'])) ?>" <?= $selectedGroup === 'removal' ? 'aria-current="page"' : '' ?>>
            <span class="metric-label">Removal candidates</span>
            <strong><?= number_format((int) $metrics['removal_candidates']) ?></strong>
            <span class="metric-detail">Review before action</span>
        </a>
        <a class="metric-card <?= $selectedGroup === 'very-cold' ? 'metric-card-selected' : '' ?>" href="<?= e(query_url('/', ['group' => 'very-cold'])) ?>" <?= $selectedGroup === 'very-cold' ? 'aria-current="page"' : '' ?>>
            <span class="metric-label">Very cold</span>
            <strong><?= number_format((int) $metrics['very_cold']) ?></strong>
            <span class="metric-detail">365d cold · 10+ sent</span>
        </a>
        <a class="metric-card <?= $selectedGroup === 'never-opened' ? 'metric-card-selected' : '' ?>" href="<?= e(query_url('/', ['group' => 'never-opened'])) ?>" <?= $selectedGroup === 'never-opened' ? 'aria-current="page"' : '' ?>>
            <span class="metric-label">Never opened</span>
            <strong><?= number_format((int) $metrics['never_opened']) ?></strong>
            <span class="metric-detail">No recorded open</span>
        </a>
        <a class="metric-card <?= $selectedGroup === 'never-clicked' ? 'metric-card-selected' : '' ?>" href="<?= e(query_url('/', ['group' => 'never-clicked'])) ?>" <?= $selectedGroup === 'never-clicked' ? 'aria-current="page"' : '' ?>>
            <span class="metric-label">Never clicked</span>
            <strong><?= number_format((int) $metrics['never_clicked']) ?></strong>
            <span class="metric-detail">No recorded click</span>
        </a>
        <a class="metric-card <?= $selectedGroup === 'recent' ? 'metric-card-selected' : '' ?>" href="<?= e(query_url('/', ['group' => 'recent'])) ?>" <?= $selectedGroup === 'recent' ? 'aria-current="page"' : '' ?>>
            <span class="metric-label">Recently subscribed</span>
            <strong><?= number_format((int) $metrics['recently_subscribed']) ?></strong>
            <span class="metric-detail">Last 30 days</span>
        </a>
        <a class="metric-card <?= $selectedGroup === 'sends-since-open' ? 'metric-card-selected' : '' ?>" href="<?= e(query_url('/', ['group' => 'sends-since-open'])) ?>" <?= $selectedGroup === 'sends-since-open' ? 'aria-current="page"' : '' ?>>
            <span class="metric-label">Sends since last open</span>
            <strong><?= number_format((int) $metrics['sends_since_last_open']) ?></strong>
            <span class="metric-detail">At least one send</span>
        </a>
    </section>

    <section class="cold-strip">
        <div><span class="section-kicker">Inactive by last engagement</span><strong>Open or click inactivity</strong></div>
        <div class="cold-stats">
            <?php foreach ([90, 180, 270, 365] as $days): ?>
                <a href="<?= e(query_url('/', ['group' => 'all', 'sort' => 'last_opened', 'direction' => 'asc'])) ?>"><b><?= number_format((int) $metrics['inactive_' . $days]) ?></b><span><?= $days ?> days</span></a>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="table-section">
        <div class="section-heading">
            <div>
                <span class="section-kicker">Local dataset</span>
                <h2>Subscribers <span class="muted">· <?= number_format((int) $subscriberResult['total']) ?></span></h2>
            </div>
            <a class="button button-secondary" href="<?= e(query_url('/export.csv', $filters)) ?>">Export current view</a>
        </div>

        <form class="filters" method="get" action="/">
            <label class="search-field"><span class="sr-only">Search</span><input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Search email or name"></label>
            <label><span class="sr-only">Group</span><select name="group">
                <?php foreach (['all' => 'All active', 'removal' => 'Removal candidates', 'very-cold' => 'Very cold', 'never-opened' => 'Never opened', 'never-clicked' => 'Never clicked', 'recent' => 'Recently subscribed', 'sends-since-open' => 'Sends since last open'] as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $filters['group'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select></label>
            <label><span class="sr-only">Sort</span><select name="sort">
                <?php foreach (['created' => 'Subscribed', 'email' => 'Email', 'last_opened' => 'Last opened', 'last_clicked' => 'Last clicked', 'sent' => 'Emails sent', 'sends_since_open' => 'Sends since open', 'open_rate' => 'Open rate', 'click_rate' => 'Click rate'] as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $filters['sort'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select></label>
            <label><span class="sr-only">Direction</span><select name="direction">
                <option value="desc" <?= $filters['direction'] !== 'asc' ? 'selected' : '' ?>>Descending</option>
                <option value="asc" <?= $filters['direction'] === 'asc' ? 'selected' : '' ?>>Ascending</option>
            </select></label>
            <button class="button button-secondary" type="submit">Apply</button>
        </form>

        <form method="post" action="/cleanup/review" data-selection-form>
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="selection_group" value="<?= e($filters['group']) ?>">
            <input type="hidden" name="selection_q" value="<?= e($filters['q']) ?>">
            <input type="hidden" name="selection_sort" value="<?= e($filters['sort']) ?>">
            <input type="hidden" name="selection_direction" value="<?= e($filters['direction']) ?>">
            <input type="hidden" name="selection_mode" value="visible" data-selection-mode>
            <?php if ($subscriberResult['total'] > 0): ?>
                <div class="selection-toolbar"><span class="selection-summary"><strong data-selected-count>0</strong> selected <button class="button button-secondary button-compact" type="button" data-clear-selection hidden>Clear selection</button></span><span class="selection-actions"><button class="button button-secondary" type="submit" formaction="/reengagement/review" data-reengagement-review-button disabled>Tag selected for re-engagement</button><button class="button button-danger" type="submit" data-review-button disabled>Review selected for unsubscribe</button></span></div>
                <div class="selection-notice" data-selection-notice hidden><span data-selection-notice-text>All subscribers on this page are selected.</span><button class="selection-link" type="button" data-select-all-matching data-total="<?= (int) $subscriberResult['total'] ?>">Select all <?= number_format((int) $subscriberResult['total']) ?> matching</button></div>
            <?php endif; ?>
            <div class="table-wrap">
                <table>
                    <thead><tr>
                        <th class="check-col"><input type="checkbox" data-select-page aria-label="Select all subscribers on this page"></th>
                        <th>Subscriber</th><th>Subscribed</th><th>Last open</th><th>Last click</th><th>Sent</th><th>Since open / click</th><th>Rates</th>
                    </tr></thead>
                    <tbody>
                    <?php if ($subscriberResult['rows'] === []): ?>
                        <tr><td colspan="8" class="empty">No cached subscribers match this view. Sync Kit to populate the local dataset.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($subscriberResult['rows'] as $row): ?>
                        <tr>
                            <td><input type="checkbox" name="subscriber_ids[]" value="<?= (int) $row['id'] ?>" data-selection></td>
                            <td><div class="subscriber"><span class="avatar"><?= e(strtoupper(substr((string) ($row['first_name'] ?: $row['email_address']), 0, 1))) ?></span><span><strong><?= e($row['email_address']) ?></strong><small><?= e($row['first_name'] ?: 'No first name') ?></small></span></div></td>
                            <td><span title="<?= e($row['created_at']) ?>"><?= e(format_date($row['created_at'])) ?></span><small><?= e(date_age($row['created_at'])) ?></small></td>
                            <td><span title="<?= e($row['last_opened'] ?? '') ?>"><?= e(format_date($row['last_opened'])) ?></span><small><?= e(date_age($row['last_opened'])) ?></small></td>
                            <td><span title="<?= e($row['last_clicked'] ?? '') ?>"><?= e(format_date($row['last_clicked'])) ?></span><small><?= e(date_age($row['last_clicked'])) ?></small></td>
                            <td><?= $row['sent'] === null ? '—' : number_format((int) $row['sent']) ?></td>
                            <td><?= $row['sends_since_last_open'] === null ? '—' : number_format((int) $row['sends_since_last_open']) ?> / <?= $row['sends_since_last_click'] === null ? '—' : number_format((int) $row['sends_since_last_click']) ?></td>
                            <td><span class="rate-chip">O <?= e(format_percent($row['open_rate'])) ?></span><span class="rate-chip">C <?= e(format_percent($row['click_rate'])) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </form>

        <?php if ($subscriberResult['pages'] > 1): ?>
            <div class="pagination">
                <?php if ($subscriberResult['page'] > 1): ?><a href="<?= e(query_url('/', array_merge($filters, ['page' => $subscriberResult['page'] - 1]))) ?>">← Previous</a><?php endif; ?>
                <span>Page <?= (int) $subscriberResult['page'] ?> of <?= (int) $subscriberResult['pages'] ?></span>
                <?php if ($subscriberResult['page'] < $subscriberResult['pages']): ?><a href="<?= e(query_url('/', array_merge($filters, ['page' => $subscriberResult['page'] + 1]))) ?>">Next →</a><?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
</main>
<?php $content = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
