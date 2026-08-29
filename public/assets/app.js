(function () {
    'use strict';

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    async function postJson(url) {
        const response = await fetch(url, {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrfToken, 'Accept': 'application/json' },
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
        if (count) count.textContent = `${progress.processed || 0} / ${progress.total || 0} subscribers with stats`;
        const phase = panel.querySelector('[data-sync-phase]');
        if (phase) phase.textContent = (progress.phase || 'idle').replaceAll('_', ' ');
    }

    async function runSync() {
        const button = document.querySelector('[data-sync-start]');
        if (button) { button.disabled = true; button.textContent = 'Syncing…'; }
        try {
            let progress = await postJson('/sync/start');
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
            if (button) { button.disabled = false; button.textContent = 'Retry sync'; }
        }
    }

    const syncButton = document.querySelector('[data-sync-start]');
    if (syncButton) syncButton.addEventListener('click', runSync);
    const syncPanel = document.querySelector('[data-sync-panel]');
    if (syncPanel && syncPanel.dataset.status === 'running') runSync();

    const selections = document.querySelectorAll('[data-selection]');
    const selectedCount = document.querySelector('[data-selected-count]');
    const reviewButton = document.querySelector('[data-review-button]');
    function updateSelection() {
        const selected = Array.from(selections).filter(input => input.checked).length;
        if (selectedCount) selectedCount.textContent = String(selected);
        if (reviewButton) reviewButton.disabled = selected === 0;
    }
    selections.forEach(input => input.addEventListener('change', updateSelection));
    updateSelection();

    const cleanupForm = document.querySelector('[data-cleanup-start]');
    if (cleanupForm) {
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
}());
