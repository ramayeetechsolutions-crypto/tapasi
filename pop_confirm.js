/**
 * =========================================================================
 * TAPASI ERP - GLOBAL POP CONFIRM & DELETION MODAL SYSTEM
 * =========================================================================
 * Replaces primitive browser alert/confirm dialogues with a modern, 
 * senior-developer grade animated confirmation popup across the ENTIRE ERP.
 * 
 * Automatically intercepts:
 *  - Forms with delete actions (name="action" value="delete")
 *  - Buttons/Forms with onsubmit="return confirm(...)" / onclick="return confirm(...)"
 *  - Delete buttons (.btn-delete, [title*="delete"], icons with .fa-trash)
 *  - Action links with delete parameters
 *  - Custom triggers via data-confirm or window.erpPopConfirm()
 * =========================================================================
 */

(function () {
    'use strict';

    // Prevent duplicate initialization
    if (window._erpPopConfirmInitialized) return;
    window._erpPopConfirmInitialized = true;

    let activeConfirmation = null;
    let overlayEl = null;

    // Helper: Escape HTML
    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // Helper: Extract confirm message from inline attribute string
    function extractConfirmMessage(attrStr) {
        if (!attrStr) return null;
        // Matches confirm('...'), confirm("..."), confirm(`...`)
        const match = attrStr.match(/confirm\s*\(\s*(['"`])([\s\S]*?)\1\s*\)/i);
        if (match && match[2]) {
            return match[2].trim();
        }
        return null;
    }

    // Helper: Determine action intent (delete, status, warning, etc.)
    function detectActionIntent(message, element, form) {
        const textToCheck = (
            (message || '') + ' ' +
            (element ? element.className + ' ' + (element.title || '') + ' ' + (element.innerText || '') : '') + ' ' +
            (form ? (form.action || '') + ' ' + (form.className || '') : '')
        ).toLowerCase();

        // Check for hidden input action value
        let actionVal = '';
        if (form) {
            const actInput = form.querySelector('input[name="action"], input[name="act"]');
            if (actInput) actionVal = (actInput.value || '').toLowerCase();
        }

        // 1. Deletion / Removal (Highest Priority)
        if (actionVal === 'delete' || actionVal === 'remove' || actionVal === 'del' || textToCheck.includes('delete') || textToCheck.includes('remove')) {
            return {
                type: 'danger',
                icon: 'fa-trash-alt',
                title: 'Confirm Deletion',
                btnText: 'Yes, Delete',
                btnClass: 'danger',
                note: 'This action is permanent and cannot be undone.'
            };
        }

        // 2. Status toggle
        if (actionVal === 'toggle' || textToCheck.includes('toggle status') || (textToCheck.includes('status') && !textToCheck.includes('delete'))) {
            return {
                type: 'info',
                icon: 'fa-toggle-on',
                title: 'Confirm Status Change',
                btnText: 'Yes, Update Status',
                btnClass: 'info',
                note: 'You can switch the status back at any time.'
            };
        }

        // 3. OT / Request Approval
        if (textToCheck.includes('approve') || actionVal === 'approve') {
            return {
                type: 'success',
                icon: 'fa-check-circle',
                title: 'Confirm Approval',
                btnText: 'Yes, Approve',
                btnClass: 'success',
                note: 'This will authorize and finalize the request.'
            };
        }

        // 4. Rejection
        if (textToCheck.includes('reject') || actionVal === 'reject') {
            return {
                type: 'warning',
                icon: 'fa-times-circle',
                title: 'Confirm Rejection',
                btnText: 'Yes, Reject',
                btnClass: 'warning',
                note: 'This will reject the submitted request.'
            };
        }

        // 5. Default: Deletion / Removal
        return {
            type: 'danger',
            icon: 'fa-trash-alt',
            title: 'Confirm Deletion',
            btnText: 'Yes, Delete',
            btnClass: 'danger',
            note: 'This action is permanent and cannot be undone.'
        };
    }

    // Helper: Find entity name from row or form
    function findEntityName(element, form) {
        // 1. Check data attributes on button or form
        if (element) {
            if (element.dataset.name) return element.dataset.name;
            if (element.dataset.title) return element.dataset.title;
            if (element.dataset.entity) return element.dataset.entity;
        }
        if (form) {
            if (form.dataset.name) return form.dataset.name;
            const nameInput = form.querySelector('input[name*="name"], input[name*="title"], input[name*="id"]');
            if (nameInput && nameInput.type !== 'hidden' && nameInput.value) {
                return nameInput.value;
            }
        }

        // 2. Check closest table row
        const row = element ? element.closest('tr') : (form ? form.closest('tr') : null);
        if (row) {
            // Check for edit button data-name in same row
            const editBtn = row.querySelector('[data-name], .btn-edit, .action-btn');
            if (editBtn && editBtn.dataset.name) {
                return editBtn.dataset.name;
            }
            // Check common table columns (second or first non-index cell)
            const cells = row.querySelectorAll('td');
            if (cells.length >= 2) {
                // Find first cell with substantive text (skip S.No)
                for (let i = 1; i < Math.min(cells.length, 3); i++) {
                    const primaryHeading = cells[i].querySelector('strong, h6, .fw-bold, .item-name, a');
                    if (primaryHeading && primaryHeading.innerText.trim()) {
                        return primaryHeading.innerText.trim();
                    }
                    const text = cells[i].innerText.trim().split('\n')[0];
                    if (text && text.length < 50 && !text.match(/^(\d+|\s*)$/)) {
                        return text;
                    }
                }
            }
        }

        return null;
    }

    // Build and inject PopConfirm DOM element
    function ensureDOM() {
        if (overlayEl) return;

        const container = document.createElement('div');
        container.id = 'erpPopConfirmOverlay';
        container.className = 'erp-popconfirm-overlay';
        container.setAttribute('aria-hidden', 'true');
        container.setAttribute('role', 'dialog');
        container.setAttribute('aria-modal', 'true');

        container.innerHTML = `
            <div class="erp-popconfirm-card" id="erpPopConfirmCard">
                <div class="erp-popconfirm-header">
                    <button type="button" class="erp-popconfirm-close-btn" id="erpPopConfirmClose" aria-label="Close dialog">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="erp-popconfirm-body">
                    <div class="erp-popconfirm-icon-box danger" id="erpPopConfirmIconBox">
                        <i class="fas fa-trash-alt" id="erpPopConfirmIcon"></i>
                    </div>
                    <h5 class="erp-popconfirm-title" id="erpPopConfirmTitle">Confirm Deletion</h5>
                    <p class="erp-popconfirm-desc" id="erpPopConfirmDesc">
                        Are you sure you want to delete this record?
                    </p>
                    <div id="erpPopConfirmTargetWrapper" style="display:none; margin: 6px 0 10px 0;">
                        <span class="erp-popconfirm-target" id="erpPopConfirmTarget"></span>
                    </div>
                    <div class="erp-popconfirm-note" id="erpPopConfirmNote">
                        <i class="fas fa-exclamation-circle text-danger me-1"></i> This action cannot be undone.
                    </div>
                </div>
                <div class="erp-popconfirm-footer">
                    <button type="button" class="erp-popconfirm-btn erp-popconfirm-btn-cancel" id="erpPopConfirmCancel">
                        Cancel
                    </button>
                    <button type="button" class="erp-popconfirm-btn erp-popconfirm-btn-confirm danger" id="erpPopConfirmOk">
                        <i class="fas fa-trash-alt me-1" id="erpPopConfirmOkIcon"></i>
                        <span id="erpPopConfirmOkText">Yes, Delete</span>
                    </button>
                </div>
            </div>
        `;

        document.body.appendChild(container);
        overlayEl = container;

        // Bind internal events
        document.getElementById('erpPopConfirmClose').addEventListener('click', closePopConfirm);
        document.getElementById('erpPopConfirmCancel').addEventListener('click', closePopConfirm);
        document.getElementById('erpPopConfirmOk').addEventListener('click', handleConfirmClick);

        // Click on dark overlay backdrop dismisses
        overlayEl.addEventListener('click', function (e) {
            if (e.target === overlayEl) {
                closePopConfirm();
            }
        });

        // Keyboard handler: Esc to cancel, Enter to confirm
        document.addEventListener('keydown', function (e) {
            if (!overlayEl || !overlayEl.classList.contains('show')) return;
            if (e.key === 'Escape') {
                e.preventDefault();
                closePopConfirm();
            } else if (e.key === 'Enter') {
                e.preventDefault();
                handleConfirmClick();
            }
        });
    }

    // Open Pop Confirm Modal
    function openPopConfirm(opts) {
        ensureDOM();
        activeConfirmation = opts;

        const iconBox = document.getElementById('erpPopConfirmIconBox');
        const icon = document.getElementById('erpPopConfirmIcon');
        const title = document.getElementById('erpPopConfirmTitle');
        const desc = document.getElementById('erpPopConfirmDesc');
        const targetWrapper = document.getElementById('erpPopConfirmTargetWrapper');
        const target = document.getElementById('erpPopConfirmTarget');
        const note = document.getElementById('erpPopConfirmNote');
        const okBtn = document.getElementById('erpPopConfirmOk');
        const okIcon = document.getElementById('erpPopConfirmOkIcon');
        const okText = document.getElementById('erpPopConfirmOkText');

        // Reset classes
        iconBox.className = 'erp-popconfirm-icon-box ' + (opts.type || 'danger');
        icon.className = 'fas ' + (opts.icon || 'fa-trash-alt');
        okBtn.className = 'erp-popconfirm-btn erp-popconfirm-btn-confirm ' + (opts.btnClass || opts.type || 'danger');
        okIcon.className = 'fas ' + (opts.icon || 'fa-trash-alt') + ' me-1';
        okBtn.disabled = false;

        title.textContent = opts.title || 'Confirm Deletion';
        desc.textContent = opts.message || 'Are you sure you want to proceed?';
        okText.textContent = opts.btnText || 'Confirm';

        // Target badge
        if (opts.targetName) {
            target.textContent = opts.targetName;
            targetWrapper.style.display = 'block';
        } else {
            targetWrapper.style.display = 'none';
        }

        // Note
        if (opts.note) {
            note.innerHTML = `<i class="fas fa-exclamation-circle me-1"></i> ${escapeHtml(opts.note)}`;
            note.style.display = 'block';
        } else {
            note.style.display = 'none';
        }

        // Show with animation
        overlayEl.classList.add('show');
        overlayEl.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';

        // Focus confirm button
        setTimeout(() => {
            const cancelBtn = document.getElementById('erpPopConfirmCancel');
            if (cancelBtn) cancelBtn.focus();
        }, 50);
    }

    // Close Pop Confirm Modal
    function closePopConfirm() {
        if (!overlayEl) return;
        overlayEl.classList.remove('show');
        overlayEl.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';

        if (activeConfirmation && typeof activeConfirmation.onCancel === 'function') {
            try {
                activeConfirmation.onCancel();
            } catch (err) {
                console.error(err);
            }
        }
        activeConfirmation = null;
    }

    // Handle Confirm Action
    function handleConfirmClick() {
        if (!activeConfirmation) return;
        const conf = activeConfirmation;

        const okBtn = document.getElementById('erpPopConfirmOk');
        const okIcon = document.getElementById('erpPopConfirmOkIcon');
        const okText = document.getElementById('erpPopConfirmOkText');

        // Loading state
        okBtn.disabled = true;
        okIcon.className = 'fas fa-spinner fa-spin me-1';
        okText.textContent = 'Processing...';

        setTimeout(() => {
            closePopConfirm();

            if (typeof conf.onConfirm === 'function') {
                conf.onConfirm();
            } else if (conf.form) {
                // Execute form submit bypassing interceptor
                conf.form._erpConfirmed = true;
                if (typeof conf.form.onsubmit === 'function') {
                    conf.form.onsubmit = null;
                }
                // If a button triggered the submit, ensure its name/value are submitted if needed
                if (conf.trigger && conf.trigger.name && !conf.form.querySelector(`input[type="hidden"][name="${conf.trigger.name}"]`)) {
                    const hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = conf.trigger.name;
                    hidden.value = conf.trigger.value || '';
                    conf.form.appendChild(hidden);
                }
                HTMLFormElement.prototype.submit.call(conf.form);
            } else if (conf.link) {
                // Follow link href
                conf.link._erpConfirmed = true;
                window.location.href = conf.link.href;
            }
        }, 180);
    }

    // Main Universal Interceptor
    function interceptAction(e) {
        // If clicking inside PopConfirm modal itself, allow
        if (e.target.closest('#erpPopConfirmCard')) return;

        // Find relevant interactive element
        const trigger = e.target.closest('button, a, input[type="submit"], input[type="button"], .badge-status-pill, .badge-status');
        if (!trigger) return;

        // Exclude purely UI controls: modals, togglers, expanders, dismiss buttons, tabs, accordions, search/refresh buttons
        const triggerClass = trigger.className || '';
        if (
            trigger.hasAttribute('data-bs-toggle') ||
            trigger.hasAttribute('data-bs-target') ||
            trigger.hasAttribute('data-bs-dismiss') ||
            trigger.classList.contains('expand-toggle-btn') ||
            trigger.classList.contains('navbar-toggler') ||
            trigger.classList.contains('btn-close') ||
            trigger.classList.contains('accordion-button') ||
            trigger.classList.contains('dropdown-toggle') ||
            trigger.classList.contains('nav-link') ||
            trigger.classList.contains('page-link') ||
            trigger.classList.contains('btn-refresh') ||
            trigger.classList.contains('btn-open-add-member') ||
            trigger.classList.contains('btn-open-edit-member')
        ) {
            return;
        }

        // If already confirmed by PopConfirm, pass through
        if (trigger._erpConfirmed) return;
        const form = trigger.closest('form');
        if (form && form._erpConfirmed) return;

        const hasActionForm = !!form;
        const hasActionLink = (trigger.tagName === 'A' && trigger.href && trigger.href !== '#' && !trigger.href.startsWith('javascript:'));

        // Detect if this element or its parent form has an explicit confirm or delete intent
        let confirmMsg = null;
        let isDelete = false;
        let isStatus = false;
        let isApproval = false;

        // 1. Check data-confirm attribute
        if (trigger.dataset.confirm) {
            confirmMsg = trigger.dataset.confirm;
        } else if (form && form.dataset.confirm) {
            confirmMsg = form.dataset.confirm;
        }

        // 2. Check inline onclick attribute
        if (!confirmMsg) {
            const onclickAttr = trigger.getAttribute('onclick');
            if (onclickAttr && onclickAttr.includes('confirm(')) {
                confirmMsg = extractConfirmMessage(onclickAttr);
            }
        }

        // 3. Check inline onsubmit attribute of parent form
        if (!confirmMsg && form) {
            const onsubmitAttr = form.getAttribute('onsubmit');
            if (onsubmitAttr && onsubmitAttr.includes('confirm(')) {
                confirmMsg = extractConfirmMessage(onsubmitAttr);
            }
        }

        // If there is no form to submit, no link to navigate to, and no explicit confirm message, DO NOT intercept!
        if (!hasActionForm && !hasActionLink && !confirmMsg) {
            return;
        }

        // 4. Check for hidden delete or toggle input
        if (form) {
            const actInput = form.querySelector('input[name="action"], input[name="act"]');
            if (actInput) {
                const val = (actInput.value || '').toLowerCase();
                if (val === 'delete' || val === 'remove' || val === 'del') {
                    isDelete = true;
                } else if (val === 'toggle') {
                    isStatus = true;
                }
            }
        }

        // 5. Check trigger classes and attributes (strictly for actionable forms or links)
        const triggerTitle = (trigger.getAttribute('title') || '').toLowerCase();
        const hasTrashIcon = trigger.querySelector('.fa-trash, .fa-trash-alt') !== null || triggerClass.includes('fa-trash');

        if (
            (triggerClass.includes('btn-delete') || hasTrashIcon) && 
            (hasActionForm || hasActionLink)
        ) {
            isDelete = true;
        }

        if (
            (triggerClass.includes('badge-status-pill') || triggerTitle.includes('toggle status')) && 
            (hasActionForm || hasActionLink)
        ) {
            isStatus = true;
        }

        // If neither a confirm message nor an actionable delete/status trigger is detected, let normal event flow continue
        if (!confirmMsg && !isDelete && !isStatus) {
            return;
        }

        // INTERCEPT EVENT
        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();

        // Detect visual intent details
        const intent = detectActionIntent(confirmMsg, trigger, form);
        const entityName = findEntityName(trigger, form);

        // Format message
        let displayMessage = confirmMsg || (isDelete ? 'Are you sure you want to permanently delete this record?' : 'Are you sure you want to change the status of this record?');
        
        // Clean up common prefixes from inline confirm strings (e.g. "Are you sure you want to delete process: PAG?")
        let cleanTarget = entityName;
        if (confirmMsg) {
            const targetMatch = confirmMsg.match(/(?:delete|remove|status of|toggle)\s+([a-zA-Z0-9_\s\-#]+?)(?:\?|$)/i);
            if (targetMatch && targetMatch[1] && !cleanTarget) {
                cleanTarget = targetMatch[1].replace(/^(process:|job:|team:|language:|client:|role:|employee:)\s*/i, '').trim();
            }
        }

        openPopConfirm({
            type: intent.type,
            icon: intent.icon,
            title: intent.title,
            message: displayMessage,
            targetName: cleanTarget,
            btnText: intent.btnText,
            btnClass: intent.btnClass,
            note: intent.note,
            form: form,
            trigger: trigger,
            link: (trigger.tagName === 'A' && trigger.href) ? trigger : null
        });
    }

    // Submit Interceptor (for Enter key in forms or direct programmatic submit)
    function interceptSubmit(e) {
        const form = e.target;
        if (!form || form._erpConfirmed) return;

        let confirmMsg = null;
        let isDelete = false;

        const onsubmitAttr = form.getAttribute('onsubmit');
        if (onsubmitAttr && onsubmitAttr.includes('confirm(')) {
            confirmMsg = extractConfirmMessage(onsubmitAttr);
        }

        const actInput = form.querySelector('input[name="action"], input[name="act"]');
        if (actInput) {
            const val = (actInput.value || '').toLowerCase();
            if (val === 'delete' || val === 'remove' || val === 'del') {
                isDelete = true;
            }
        }

        if (!confirmMsg && !isDelete) return;

        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();

        const intent = detectActionIntent(confirmMsg, null, form);
        const entityName = findEntityName(null, form);

        openPopConfirm({
            type: intent.type,
            icon: intent.icon,
            title: intent.title,
            message: confirmMsg || 'Are you sure you want to permanently delete this record?',
            targetName: entityName,
            btnText: intent.btnText,
            btnClass: intent.btnClass,
            note: intent.note,
            form: form
        });
    }

    // Attach capture listeners so we intercept BEFORE inline handlers run
    document.addEventListener('click', interceptAction, true);
    document.addEventListener('submit', interceptSubmit, true);

    // =========================================================================
    // GLOBAL TOAST NOTIFICATION SYSTEM (FOR THE ENTIRE ERP SITE)
    // =========================================================================
    function showGlobalToast(message, type, customTitle) {
        if (!message) return;
        type = (type || 'info').toLowerCase();

        let title = customTitle || 'Notification';
        let icon = 'fa-info-circle';
        let badge = 'Notice';
        let toastClass = 'erp-toast-info';

        const lowerMsg = message.toLowerCase();
        if (lowerMsg.includes('delete') || lowerMsg.includes('remove') || type === 'danger' || type === 'warning') {
            title = customTitle || 'Record Deleted';
            icon = 'fa-trash-alt';
            badge = 'Deleted';
            toastClass = 'erp-toast-danger';
        } else if (lowerMsg.includes('add') || lowerMsg.includes('create') || type === 'success') {
            title = customTitle || 'Successfully Added';
            icon = 'fa-check-circle';
            badge = 'Added';
            toastClass = 'erp-toast-success';
        } else if (lowerMsg.includes('status') || lowerMsg.includes('toggle')) {
            title = customTitle || 'Status Updated';
            icon = 'fa-toggle-on';
            badge = 'Status';
            toastClass = 'erp-toast-info';
        } else if (lowerMsg.includes('update') || lowerMsg.includes('edit') || lowerMsg.includes('modify')) {
            title = customTitle || 'Successfully Updated';
            icon = 'fa-pen-to-square';
            badge = 'Updated';
            toastClass = 'erp-toast-info';
        }

        // Create or reuse container
        let wrapper = document.getElementById('erpToastWrapper');
        if (!wrapper) {
            wrapper = document.createElement('div');
            wrapper.id = 'erpToastWrapper';
            wrapper.className = 'erp-toast-wrapper';
            wrapper.setAttribute('role', 'region');
            wrapper.setAttribute('aria-label', 'Notifications');
            document.body.appendChild(wrapper);
        }

        const toastCard = document.createElement('div');
        toastCard.className = `erp-toast-card ${toastClass}`;
        toastCard.setAttribute('role', 'alert');
        toastCard.setAttribute('aria-live', 'assertive');
        toastCard.setAttribute('aria-atomic', 'true');

        toastCard.innerHTML = `
            <div class="erp-toast-icon-box">
                <i class="fas ${icon}"></i>
            </div>
            <div class="erp-toast-content">
                <div class="erp-toast-header">
                    <span class="erp-toast-badge">${badge}</span>
                    <strong class="erp-toast-title">${escapeHtml(title)}</strong>
                    <span class="erp-toast-time">Just now</span>
                </div>
                <div class="erp-toast-body">
                    ${escapeHtml(message)}
                </div>
            </div>
            <button type="button" class="erp-toast-close" aria-label="Close">
                <i class="fas fa-times"></i>
            </button>
            <div class="erp-toast-progress-bar">
                <div class="erp-toast-progress-fill"></div>
            </div>
        `;

        wrapper.appendChild(toastCard);

        const closeBtn = toastCard.querySelector('.erp-toast-close');
        function removeCard() {
            toastCard.style.animation = 'erpToastSlideOut 0.3s cubic-bezier(0.4, 0, 0.2, 1) forwards';
            setTimeout(() => {
                if (toastCard.parentNode) toastCard.parentNode.removeChild(toastCard);
            }, 300);
        }

        closeBtn.addEventListener('click', removeCard);

        // Auto remove after 4.5s
        setTimeout(removeCard, 4500);
    }

    // Auto-detect ?msg= in URL across ANY page in the entire ERP
    function checkUrlForToasts() {
        try {
            const url = new URL(window.location.href);
            const msg = url.searchParams.get('msg');
            const type = url.searchParams.get('type') || 'info';

            // If the page already has a server-rendered PHP toast, don't duplicate
            if (document.querySelector('.erp-toast-wrapper, .erp-toast-card')) {
                // Just clear the URL params
                url.searchParams.delete('msg');
                url.searchParams.delete('type');
                window.history.replaceState({}, document.title, url.toString());
                return;
            }

            if (msg) {
                showGlobalToast(msg, type);

                // Clean URL from browser address bar
                url.searchParams.delete('msg');
                url.searchParams.delete('type');
                window.history.replaceState({}, document.title, url.toString());
            }
        } catch (e) {
            console.warn('Auto toast detection:', e);
        }
    }

    // Global programmatic API
    window.erpPopConfirm = function (options) {
        openPopConfirm(options || {});
    };

    window.closePopConfirm = closePopConfirm;
    window.erpToast = showGlobalToast;

    // Auto-init DOM on load
    function initAll() {
        ensureDOM();
        checkUrlForToasts();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
})();
