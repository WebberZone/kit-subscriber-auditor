(function () {
    'use strict';

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    async function postJson(url, data = {}) {
        const response = await fetch(url, {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrfToken, 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(data),
            credentials: 'same-origin'
        });
        const payload = await response.json().catch(() => ({ error: 'The server returned an invalid response.' }));
        if (!response.ok) throw new Error(payload.error || 'Request failed.');
        return payload;
    }

    async function getJson(url) {
        const response = await fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
        const payload = await response.json().catch(() => ({ error: 'The server returned an invalid response.' }));
        if (!response.ok) throw new Error(payload.error || 'Request failed.');
        return payload;
    }

    function updateSync(progress) {
        const panel = document.querySelector('[data-sync-panel]');
        if (!panel) return;
        panel.dataset.status = progress.status || 'idle';
        const bar = panel.querySelector('[data-sync-progress]');
        if (bar) bar.value = progress.percent || 0;
        const message = panel.querySelector('[data-sync-message]');
        if (message) message.textContent = progress.message || '';
        const status = panel.querySelector('[data-sync-status]');
        if (status) { status.textContent = (progress.status || 'idle').replaceAll('_', ' '); status.className = `status-pill status-${progress.status || 'idle'}`; }
        const count = panel.querySelector('[data-sync-count]');
        if (count) count.textContent = progress.count_message || `${progress.processed || 0} / ${progress.total || 0} subscribers with stats`;
        const phase = panel.querySelector('[data-sync-phase]');
        if (phase) phase.textContent = (progress.phase || 'idle').replaceAll('_', ' ');
        const worker = panel.querySelector('[data-sync-worker]');
        if (worker) {
            const workerStatus = progress.worker?.status || 'not_running';
            const workerPid = progress.worker?.pid;
            worker.textContent = workerStatus === 'active'
                ? `Worker active${workerPid ? ` · PID ${workerPid}` : ''}`
                : workerStatus === 'stale' ? 'Worker heartbeat stale' : 'Worker stopped';
            worker.className = `sync-worker-status sync-worker-${workerStatus}`;
        }
    }

    function setSyncButtonLabel() {
        const button = document.querySelector('[data-sync-start]');
        const forceFull = document.querySelector('[data-sync-force-full]')?.checked || false;
        if (button) button.textContent = forceFull ? 'Force full resync' : 'Sync changes';
    }

    async function runSync(forceFull = false) {
        const buttons = document.querySelectorAll('[data-sync-start]');
        buttons.forEach(button => { button.disabled = true; button.textContent = 'Syncing…'; });
        try {
            let progress = await postJson('/sync/start', { force_full: forceFull ? '1' : '0' });
            updateSync(progress);
            do {
                await new Promise(resolve => setTimeout(resolve, 1500));
                progress = await getJson('/sync/status');
                updateSync(progress);
            } while (progress.status === 'running');
            if (progress.status === 'failed') throw new Error(progress.message || 'Sync failed.');
            window.setTimeout(() => window.location.reload(), 500);
        } catch (error) {
            updateSync({ status: 'failed', message: error.message, percent: 0, processed: 0, total: 0, phase: 'failed' });
            buttons.forEach(button => { button.disabled = false; });
            setSyncButtonLabel();
        }
    }

    const syncButton = document.querySelector('[data-sync-start]');
    const forceFullToggle = document.querySelector('[data-sync-force-full]');
    if (forceFullToggle) forceFullToggle.addEventListener('change', setSyncButtonLabel);
    if (syncButton) syncButton.addEventListener('click', function () {
        const forceFull = forceFullToggle?.checked || false;
        if (forceFull && !window.confirm('Force a full stats refresh for every subscriber? This may take a long time and uses the Kit API rate limit.')) return;
        runSync(forceFull);
    });
    const syncPanel = document.querySelector('[data-sync-panel]');
    if (syncPanel && syncPanel.dataset.status === 'running') runSync(false);

    const selections = document.querySelectorAll('[data-selection]');
    const selectedCount = document.querySelector('[data-selected-count]');
    const reviewButton = document.querySelector('[data-review-button]');
    const reengagementReviewButton = document.querySelector('[data-reengagement-review-button]');
    const selectionMode = document.querySelector('[data-selection-mode]');
    const selectPage = document.querySelector('[data-select-page]');
    const selectAllMatchingButton = document.querySelector('[data-select-all-matching]');
    const clearSelectionButton = document.querySelector('[data-clear-selection]');
    const selectionNotice = document.querySelector('[data-selection-notice]');
    const selectionNoticeText = document.querySelector('[data-selection-notice-text]');
    const selectionTotal = Number(selectAllMatchingButton?.dataset.total || 0);
    const pageTotal = selections.length;
    function setSelectionMode(mode) {
        if (!selectionMode) return;
        selectionMode.value = mode;
        selectionMode.setAttribute('value', mode);
    }
    if (selectionMode && !selectionMode.value) setSelectionMode('visible');
    function updateSelection() {
        const allSelected = selectionMode?.value === 'all';
        const checkedCount = Array.from(selections).filter(input => input.checked).length;
        const selected = allSelected ? selectionTotal : checkedCount;
        const pageSelected = pageTotal > 0 && checkedCount === pageTotal;
        if (selectedCount) selectedCount.textContent = String(selected);
        if (reviewButton) reviewButton.disabled = selected === 0;
        if (reengagementReviewButton) reengagementReviewButton.disabled = selected === 0;
        if (selectPage) {
            selectPage.checked = allSelected || pageSelected;
            selectPage.indeterminate = !allSelected && checkedCount > 0 && !pageSelected;
        }
        if (clearSelectionButton) clearSelectionButton.hidden = selected === 0;
        if (selectionNotice) selectionNotice.hidden = allSelected || !pageSelected || selectionTotal <= pageTotal;
        if (selectionNoticeText && pageSelected && !allSelected) selectionNoticeText.textContent = `All ${pageTotal.toLocaleString()} subscribers on this page are selected.`;
    }
    selections.forEach(input => input.addEventListener('change', () => {
        setSelectionMode('visible');
        updateSelection();
    }));
    if (selectPage) selectPage.addEventListener('change', () => {
        setSelectionMode('visible');
        selections.forEach(input => { input.checked = selectPage.checked; });
        updateSelection();
    });
    if (selectAllMatchingButton) selectAllMatchingButton.addEventListener('click', () => {
        setSelectionMode('all');
        selections.forEach(input => { input.checked = true; });
        updateSelection();
    });
    if (clearSelectionButton) clearSelectionButton.addEventListener('click', () => {
        setSelectionMode('visible');
        selections.forEach(input => { input.checked = false; });
        updateSelection();
    });
    const selectionForm = document.querySelector('[data-selection-form]');
    if (selectionForm) selectionForm.addEventListener('submit', () => {
        setSelectionMode(selectionMode?.value === 'all' ? 'all' : 'visible');
    });
    updateSelection();

    const cleanupForm = document.querySelector('[data-cleanup-start]');
    if (cleanupForm) {
        const cleanupDryRunToggle = cleanupForm.querySelector('[data-cleanup-dry-run]');
        const cleanupModeLabel = cleanupForm.querySelector('[data-cleanup-mode-label]');
        const cleanupModeHelp = cleanupForm.querySelector('[data-cleanup-mode-help]');
        const cleanupModeNotice = cleanupForm.querySelector('[data-cleanup-mode-notice]');
        const cleanupSubmitButton = cleanupForm.querySelector('[data-cleanup-submit-button]');
        function updateCleanupMode() {
            const dryRun = cleanupDryRunToggle?.checked ?? true;
            if (cleanupModeLabel) cleanupModeLabel.textContent = dryRun ? 'Dry-run mode is enabled' : 'Live unsubscribe mode is enabled';
            if (cleanupModeHelp) cleanupModeHelp.textContent = dryRun
                ? 'Keep checked to simulate this job locally. Uncheck to allow real Kit unsubscribe calls for this job.'
                : 'This job will make real Kit unsubscribe calls. Check to simulate it locally instead.';
            if (cleanupModeNotice) {
                cleanupModeNotice.className = `notice ${dryRun ? 'notice-info' : 'notice-danger'}`;
                cleanupModeNotice.textContent = dryRun
                    ? 'Dry-run mode is enabled. Starting this will simulate the action locally and will not call Kit.'
                    : 'Live mode is selected. Starting this will make real Kit unsubscribe calls.';
            }
            if (cleanupSubmitButton) cleanupSubmitButton.textContent = dryRun ? 'Run dry-run review' : 'Start unsubscribe job';
        }
        if (cleanupDryRunToggle) cleanupDryRunToggle.addEventListener('change', updateCleanupMode);
        updateCleanupMode();
        cleanupForm.addEventListener('submit', async function (event) {
            event.preventDefault();
            const button = cleanupForm.querySelector('button[type="submit"]');
            if (button) { button.disabled = true; button.textContent = 'Starting…'; }
            try {
                const response = await fetch('/cleanup/start', { method: 'POST', body: new FormData(cleanupForm), headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
                const payload = await response.json();
                if (!response.ok) throw new Error(payload.error || 'Unable to start cleanup.');
                window.location.href = '/cleanup/progress';
            } catch (error) {
                window.alert(error.message);
                if (button) { button.disabled = false; button.textContent = 'Start unsubscribe job'; }
            }
        });
    }

    const cleanupPanel = document.querySelector('[data-cleanup-panel]');
    async function runCleanup() {
        if (!cleanupPanel) return;
        try {
            let progress;
            do {
                progress = await postJson('/cleanup/step');
                const bar = cleanupPanel.querySelector('[data-cleanup-progress]');
                if (bar) bar.value = progress.percent || 0;
                const message = cleanupPanel.querySelector('[data-cleanup-message]');
                if (message) message.textContent = progress.message || (progress.dry_run ? 'Dry-run complete.' : 'Processing unsubscribe calls.');
                const status = cleanupPanel.querySelector('[data-cleanup-status]');
                if (status) { status.textContent = (progress.status || 'idle').replaceAll('_', ' '); status.className = `status-pill status-${progress.status || 'idle'}`; }
                const count = cleanupPanel.querySelector('[data-cleanup-count]');
                if (count) count.textContent = `${progress.processed || 0} / ${progress.total || 0} processed`;
                const failed = cleanupPanel.querySelector('[data-cleanup-failed]');
                if (failed) failed.textContent = `${progress.failed || 0} failed`;
                if (progress.status === 'running' || progress.status === 'pending') await new Promise(resolve => setTimeout(resolve, 250));
            } while (progress.status === 'running' || progress.status === 'pending');
        } catch (error) {
            const message = cleanupPanel.querySelector('[data-cleanup-message]');
            if (message) message.textContent = error.message;
        }
    }
    if (cleanupPanel && ['pending', 'running', 'dry_run_pending'].includes(cleanupPanel.dataset.status)) runCleanup();

    function updateReengagement(progress) {
        const panel = document.querySelector('[data-reengagement-panel]');
        if (!panel) return;
        panel.dataset.status = progress.status || 'idle';
        const bar = panel.querySelector('[data-reengagement-progress]');
        if (bar) bar.value = progress.percent || 0;
        const message = panel.querySelector('[data-reengagement-message]');
        if (message) message.textContent = progress.message || '';
        const status = panel.querySelector('[data-reengagement-status]');
        if (status) { status.textContent = (progress.status || 'idle').replaceAll('_', ' '); status.className = 'status-pill status-' + (progress.status || 'idle'); }
        const count = panel.querySelector('[data-reengagement-count]');
        if (count) count.textContent = String(progress.processed || 0) + ' / ' + String(progress.total || 0) + ' processed';
        const phase = panel.querySelector('[data-reengagement-phase]');
        if (phase) phase.textContent = (progress.phase || 'idle').replaceAll('_', ' ');
    }

    async function runReengagement() {
        const panel = document.querySelector('[data-reengagement-panel]');
        if (!panel) return;
        try {
            let progress = await getJson('/reengagement/status');
            updateReengagement(progress);
            while (['tagging', 'resyncing'].includes(progress.status)) {
                await new Promise(resolve => setTimeout(resolve, 1500));
                progress = await getJson('/reengagement/status');
                updateReengagement(progress);
            }
            window.setTimeout(() => window.location.reload(), 500);
        } catch (error) {
            const message = panel.querySelector('[data-reengagement-message]');
            if (message) message.textContent = error.message;
        }
    }

    async function submitReengagementForm(form, endpoint, redirectPath) {
        const button = form.querySelector('button[type="submit"]');
        if (button) { button.disabled = true; button.textContent = 'Starting…'; }
        try {
            const response = await fetch(endpoint, { method: 'POST', body: new FormData(form), headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
            const payload = await response.json().catch(() => ({ error: 'The server returned an invalid response.' }));
            if (!response.ok) throw new Error(payload.error || 'Unable to start re-engagement.');
            window.location.href = redirectPath;
        } catch (error) {
            window.alert(error.message);
            if (button) { button.disabled = false; button.textContent = endpoint === '/reengagement/start' ? 'Apply tag and track cohort' : 'Resync tagged subscribers'; }
        }
    }

    const reengagementStartForm = document.querySelector('[data-reengagement-start]');
    if (reengagementStartForm) reengagementStartForm.addEventListener('submit', event => {
        event.preventDefault();
        submitReengagementForm(reengagementStartForm, '/reengagement/start', '/reengagement');
    });
    const reengagementResyncForm = document.querySelector('[data-reengagement-resync]');
    if (reengagementResyncForm) reengagementResyncForm.addEventListener('submit', event => {
        event.preventDefault();
        submitReengagementForm(reengagementResyncForm, '/reengagement/resync', '/reengagement');
    });
    const reengagementPanel = document.querySelector('[data-reengagement-panel]');
    if (reengagementPanel && ['tagging', 'resyncing'].includes(reengagementPanel.dataset.status)) runReengagement();
}());
