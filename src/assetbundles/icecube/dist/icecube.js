/**
 * Icecube — CP unlock modal and lock badge.
 *
 * Injected on element edit pages when a lock applies.
 * Intercepts the Save/Delete form submission, shows the password modal,
 * and posts to the unlock endpoint before allowing the form through.
 */
(function () {
    'use strict';

    // ── State ─────────────────────────────────────────────────
    const state = {
        targetType: null,
        targetId: null,
        pendingAction: null,   // 'edit' or 'delete'
        pendingForm: null,
        pendingEvent: null,
    };

    // ── Init ──────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        const meta = document.getElementById('icecube-meta');
        if (!meta) return;

        state.targetType = meta.dataset.targetType;
        state.targetId   = meta.dataset.targetId;
        const lockEdit     = meta.dataset.lockEdit === '1';
        const lockDelete   = meta.dataset.lockDelete === '1';
        const editUnlocked = meta.dataset.editUnlocked === '1';
        const autoPrompt   = meta.dataset.autoPrompt === '1';

        // Seed client unlock state from session
        if (editUnlocked) unlocked.edit = true;

        if (lockDelete) {
            interceptDelete();
        }

        // Always intercept save as a safety net in case the user somehow
        // bypasses the overlay (shouldn't happen, but server will verify too)
        if (lockEdit) {
            interceptSave();
        }

        // Show the unlock overlay up-front on page load for locked-edit elements
        if (autoPrompt) {
            lockPageForEditing();
            showModal('edit');
        }
    });

    // ── Page-level editor lock ────────────────────────────────
    function lockPageForEditing() {
        document.body.classList.add('icecube-page-locked');
    }

    function unlockPage() {
        document.body.classList.remove('icecube-page-locked');
    }

    // ── Intercept save ────────────────────────────────────────
    function interceptSave() {
        // Craft's main form for element editing
        const form = document.getElementById('main-form') || document.querySelector('form#main');
        if (!form) return;

        form.addEventListener('submit', function (e) {
            if (isUnlocked('edit')) return; // already unlocked, let through

            e.preventDefault();
            e.stopImmediatePropagation();
            state.pendingAction = 'edit';
            state.pendingForm = form;
            state.pendingEvent = e;
            showModal('edit');
        }, true); // capture phase so we fire first
    }

    // ── Intercept delete ──────────────────────────────────────
    function interceptDelete() {
        // Listen for clicks on delete buttons/actions
        document.addEventListener('click', function (e) {
            const btn = e.target.closest('[data-action="delete"], .btn.delete, #action-delete');
            if (!btn) return;
            if (isUnlocked('delete')) return;

            e.preventDefault();
            e.stopImmediatePropagation();
            state.pendingAction = 'delete';
            showModal('delete');
        }, true);
    }

    // ── Session unlock tracking ───────────────────────────────
    const unlocked = { edit: false, delete: false };

    function isUnlocked(action) {
        return unlocked[action] === true;
    }

    // ── Modal ─────────────────────────────────────────────────
    function showModal(action) {
        const modal = document.getElementById('icecube-unlock-modal');
        if (!modal) return;

        document.getElementById('icecube-target-type').value = state.targetType;
        document.getElementById('icecube-target-id').value   = state.targetId;
        document.getElementById('icecube-action').value       = action;
        document.getElementById('icecube-password').value     = '';
        document.getElementById('icecube-error').style.display = 'none';

        modal.style.display = 'flex';
        document.getElementById('icecube-password').focus();
    }

    function hideModal() {
        const modal = document.getElementById('icecube-unlock-modal');
        if (modal) modal.style.display = 'none';
    }

    // ── Modal event handlers ──────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('icecube-unlock-form');
        if (!form) return;

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            submitUnlock();
        });

        const cancelBtn = document.getElementById('icecube-cancel-btn');
        if (cancelBtn) {
            cancelBtn.addEventListener('click', function () {
                // If the page is locked for editing, cancelling means navigating away
                if (document.body.classList.contains('icecube-page-locked')) {
                    if (document.referrer && document.referrer !== window.location.href) {
                        window.location.href = document.referrer;
                    } else {
                        window.history.back();
                    }
                    return;
                }
                hideModal();
            });
        }

        // Backdrop clicks don't dismiss when the page is locked — user must unlock or cancel
        const backdrop = document.querySelector('.icecube-modal-backdrop');
        if (backdrop) {
            backdrop.addEventListener('click', function () {
                if (document.body.classList.contains('icecube-page-locked')) return;
                hideModal();
            });
        }
    });

    // ── AJAX unlock ───────────────────────────────────────────
    function submitUnlock() {
        const password   = document.getElementById('icecube-password').value;
        const targetType = document.getElementById('icecube-target-type').value;
        const targetId   = document.getElementById('icecube-target-id').value;
        const action     = document.getElementById('icecube-action').value;
        const errorEl    = document.getElementById('icecube-error');

        if (!password) {
            errorEl.textContent = 'Please enter a password.';
            errorEl.style.display = 'block';
            return;
        }

        const data = new FormData();
        data.append('targetType', targetType);
        data.append('targetId', targetId);
        data.append('action', action);
        data.append('password', password);
        data.append(Craft.csrfTokenName, Craft.csrfTokenValue);

        fetch(Craft.getActionUrl('icecube/unlock/validate'), {
            method: 'POST',
            headers: { 'Accept': 'application/json' },
            body: data,
        })
        .then(function (res) { return res.json(); })
        .then(function (json) {
            if (json.success) {
                unlocked[action] = true;
                hideModal();

                // If the page was locked at load, release the overlay
                if (action === 'edit') {
                    unlockPage();
                }

                // Re-trigger the original action (only if one was deferred)
                if (action === 'edit' && state.pendingForm) {
                    state.pendingForm.submit();
                    state.pendingForm = null;
                }
                if (action === 'delete') {
                    const btn = document.querySelector('[data-action="delete"], .btn.delete, #action-delete');
                    if (btn) btn.click();
                }
            } else {
                errorEl.textContent = json.error || 'Invalid password.';
                errorEl.style.display = 'block';
            }
        })
        .catch(function () {
            errorEl.textContent = 'Network error. Please try again.';
            errorEl.style.display = 'block';
        });
    }

    // ── Inline lock-management panel ──────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        initInlinePanels();
    });
    // Craft sometimes swaps in element editors after DOMContentLoaded
    document.addEventListener('click', function () {
        setTimeout(initInlinePanels, 100);
    });

    const initedPanels = new WeakSet();

    function initInlinePanels() {
        document.querySelectorAll('[data-icecube-panel]').forEach(function (panel) {
            if (initedPanels.has(panel)) return;
            initedPanels.add(panel);
            wirePanel(panel);
        });
    }

    function wirePanel(panel) {
        const saveBtn = panel.querySelector('[data-icecube-action="save"]');
        const deleteBtn = panel.querySelector('[data-icecube-action="delete"]');

        if (saveBtn) {
            saveBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                submitPanel(panel, 'save');
            });
        }
        if (deleteBtn) {
            deleteBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                if (!confirm('Remove the Icecube lock from this item?')) return;
                submitPanel(panel, 'delete');
            });
        }
    }

    function submitPanel(panel, action) {
        const targetType = panel.dataset.targetType;
        const targetId = panel.dataset.targetId;
        const message = panel.querySelector('[data-icecube-message]');

        const data = new FormData();
        data.append('targetType', targetType);
        data.append('targetId', targetId);
        data.append(Craft.csrfTokenName, Craft.csrfTokenValue);

        if (action === 'save') {
            data.append('lockEdit', panel.querySelector('[data-icecube-field="lockEdit"]').checked ? '1' : '0');
            data.append('lockDelete', panel.querySelector('[data-icecube-field="lockDelete"]').checked ? '1' : '0');
            data.append('notes', panel.querySelector('[data-icecube-field="notes"]').value);
            data.append('password', panel.querySelector('[data-icecube-field="password"]').value);
        }

        const url = Craft.getActionUrl(
            action === 'save' ? 'icecube/unlock/inline-save' : 'icecube/unlock/inline-delete'
        );

        message.style.display = 'block';
        message.className = 'icecube-inline-panel__message';
        message.textContent = action === 'save' ? 'Saving…' : 'Removing…';

        fetch(url, {
            method: 'POST',
            headers: { 'Accept': 'application/json' },
            body: data,
        })
        .then(function (res) { return res.json(); })
        .then(function (json) {
            if (json.success) {
                message.className = 'icecube-inline-panel__message icecube-inline-panel__message--success';
                message.textContent = action === 'save' ? 'Lock saved.' : 'Lock removed.';
                setTimeout(function () { window.location.reload(); }, 500);
            } else {
                message.className = 'icecube-inline-panel__message icecube-inline-panel__message--error';
                message.textContent = json.error || 'Something went wrong.';
            }
        })
        .catch(function () {
            message.className = 'icecube-inline-panel__message icecube-inline-panel__message--error';
            message.textContent = 'Network error.';
        });
    }

})();
