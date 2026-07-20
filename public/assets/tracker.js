const gameSearch = document.querySelector('[data-game-search]');

if (gameSearch) {
    gameSearch.addEventListener('input', (event) => {
        const term = event.target.value.trim().toLowerCase();

        document.querySelectorAll('[data-game-name]').forEach((game) => {
            game.hidden = !game.dataset.gameName.includes(term);
        });
    });
}

const syncForm = document.querySelector('[data-sync-achievements]');
const refreshAllForm = document.querySelector('[data-refresh-all-games]');
const syncModal = document.querySelector('[data-sync-modal]');

if (syncForm && syncModal) {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
    const submitButton = syncForm.querySelector('button[type="submit"]');
    const refreshAllButton = refreshAllForm?.querySelector('button[type="submit"]');
    const leftLabel = syncForm.querySelector('[data-sync-left]');
    const statusLabel = syncModal.querySelector('[data-sync-status]');
    const detailLabel = syncModal.querySelector('[data-sync-detail]');
    const progressTrack = syncModal.querySelector('.sync-progress');
    const progressBar = syncModal.querySelector('[data-sync-progress]');
    const dismissButton = syncModal.querySelector('[data-sync-dismiss]');

    const setProgress = (completed, total) => {
        const percent = total > 0 ? Math.min(100, Math.round((completed / total) * 100)) : 100;

        progressBar.style.width = `${percent}%`;
    };

    const setWorking = (working) => {
        progressTrack.classList.toggle('is-working', working);
    };

    const finishWithReload = (message) => {
        setWorking(false);
        statusLabel.textContent = message;
        detailLabel.textContent = 'Refreshing the dashboard with the latest achievement data...';
        setProgress(1, 1);

        window.setTimeout(() => window.location.reload(), 900);
    };

    const stopForRetry = (message, activeButton = submitButton) => {
        setWorking(false);
        statusLabel.textContent = 'Steam stopped answering cleanly.';
        detailLabel.textContent = message;
        activeButton.disabled = false;
        dismissButton.hidden = false;
    };

    dismissButton.addEventListener('click', () => window.location.reload());

    syncForm.addEventListener('submit', async (event) => {
        event.preventDefault();

        let total = Number.parseInt(syncForm.dataset.unsyncedGames || '0', 10);
        let remaining = total;
        let synced = 0;
        let failed = 0;
        let previousRemaining = Number.POSITIVE_INFINITY;

        submitButton.disabled = true;
        dismissButton.hidden = true;
        syncModal.hidden = false;
        statusLabel.textContent = 'Starting achievement sync...';
        detailLabel.textContent = total > 0 ? `${total} games waiting to be checked.` : 'Checking Steam for anything outstanding.';
        setProgress(0, total);
        setWorking(true);

        try {
            while (remaining > 0 || total === 0) {
                const nextBatch = total === 0 ? 15 : Math.min(15, remaining);

                statusLabel.textContent = synced > 0 || failed > 0
                    ? `Checking next ${nextBatch} games...`
                    : 'Checking first batch of games...';
                setWorking(true);

                const response = await fetch(syncForm.action, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                });

                const payload = await response.json().catch(() => ({}));

                if (!response.ok) {
                    throw new Error(payload.message || 'Steam sync failed.');
                }

                setWorking(false);

                if (total === 0) {
                    total = payload.remaining + payload.synced + payload.failed;
                }

                synced += payload.synced;
                failed += payload.failed;
                remaining = payload.remaining;

                const completed = Math.max(0, total - remaining);

                setProgress(completed, total);
                statusLabel.textContent = remaining > 0
                    ? `Checked ${completed} of ${total} games`
                    : `Checked ${completed} games`;
                detailLabel.textContent = failed > 0
                    ? `${synced} synced, ${failed} failed, ${remaining} left.`
                    : `${synced} synced, ${remaining} left.`;

                if (leftLabel) {
                    leftLabel.textContent = `${remaining} left`;
                }

                if (payload.attempted === 0 || remaining <= 0) {
                    finishWithReload('Achievement sync complete.');
                    return;
                }

                if (remaining >= previousRemaining) {
                    stopForRetry(`${synced} games synced, but ${remaining} still need another pass. Refreshing will show what made it through.`);
                    return;
                }

                previousRemaining = remaining;
            }

            finishWithReload('Achievement sync complete.');
        } catch (error) {
            stopForRetry(error.message || 'Something went wrong while talking to Steam.');
        }
    });

    refreshAllForm?.addEventListener('submit', async (event) => {
        event.preventDefault();

        let total = Number.parseInt(refreshAllForm.dataset.refreshableGames || '0', 10);
        let processed = 0;
        let synced = 0;
        let failed = 0;
        let afterId = 0;

        refreshAllButton.disabled = true;
        dismissButton.hidden = true;
        syncModal.hidden = false;
        statusLabel.textContent = 'Refreshing all games...';
        detailLabel.textContent = total > 0 ? `${total} games queued for a full achievement check.` : 'Checking the full library for refreshable games.';
        setProgress(0, total);
        setWorking(true);

        try {
            while (true) {
                const nextBatch = total > 0 ? Math.min(15, Math.max(total - processed, 1)) : 15;

                statusLabel.textContent = processed > 0
                    ? `Refreshing next ${nextBatch} games...`
                    : 'Refreshing first batch of games...';
                setWorking(true);

                const response = await fetch(refreshAllForm.action, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({ after_id: afterId }),
                });

                const payload = await response.json().catch(() => ({}));

                if (!response.ok) {
                    throw new Error(payload.message || 'Full refresh failed.');
                }

                setWorking(false);

                if (processed === 0) {
                    total = payload.total;
                }

                processed += payload.attempted;
                synced += payload.synced;
                failed += payload.failed;
                afterId = payload.next_after_id;

                setProgress(processed, total);
                statusLabel.textContent = payload.remaining > 0
                    ? `Refreshed ${processed} of ${total} games`
                    : `Refreshed ${processed} games`;
                detailLabel.textContent = failed > 0
                    ? `${synced} updated, ${failed} failed, ${payload.remaining} left.`
                    : `${synced} updated, ${payload.remaining} left.`;

                if (payload.attempted === 0 || payload.remaining <= 0) {
                    finishWithReload('Full achievement refresh complete.');
                    return;
                }
            }
        } catch (error) {
            stopForRetry(error.message || 'Something went wrong while refreshing every game.', refreshAllButton);
        }
    });
}

document.querySelectorAll('[data-progress-slider]').forEach((slider) => {
    const progress = slider.closest('.achievement-progress');
    const form = document.getElementById(slider.getAttribute('form'));

    if (!progress || !form) {
        return;
    }

    const updateManualProgress = () => {
        const current = Number.parseInt(slider.value || '0', 10);
        const target = Number.parseInt(slider.max || '0', 10);

        if (!Number.isFinite(current) || !Number.isFinite(target) || target <= 0) {
            return;
        }

        const cappedCurrent = Math.max(0, Math.min(current, target));
        const percent = Math.min(100, Math.round((cappedCurrent / target) * 100));

        progress.style.setProperty('--value', `${percent}%`);
        progress.querySelector('[data-progress-label]').textContent = `${cappedCurrent.toLocaleString()} / ${target.toLocaleString()}`;
    };

    slider.addEventListener('input', updateManualProgress);
    slider.addEventListener('change', () => {
        updateManualProgress();
        form.requestSubmit();
    });
});
