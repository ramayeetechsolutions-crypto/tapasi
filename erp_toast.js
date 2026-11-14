/**
 * =========================================================================
 * TAPASI SOLUTIONS LLP - GLOBAL ERP TOAST NOTIFICATION ENGINE
 * File: erp_toast.js
 * =========================================================================
 * Standardizes notifications across the entire ERP portal.
 * 
 * Automatically captures and displays:
 *  1. URL Query Parameters (?msg=..., ?success=..., ?error=..., ?message=...)
 *  2. PHP Session Flash Messages (window.__ERP_FLASH_TOAST)
 *  3. In-page operation alerts (.alert-success, .alert-danger, .alert-warning)
 *  4. Direct JavaScript API (window.erpToast, window.showToast)
 *  5. Legacy static toast cards (.erp-toast-card)
 * 
 * Features:
 *  - Automated countdown timer (4.5s default) with dynamic progress bar
 *  - Pause on hover, resume on mouse leave
 *  - Smart semantic auto-theming (Success, Deleted, Warning, Updated, Notice)
 *  - URL query clean-up (prevents repeat toasts on page refresh)
 *  - Stacked multi-toast queue
 * =========================================================================
 */

(function(window, document) {
    'use strict';

    // Theme configurations
    const THEMES = {
        success: {
            className: 'erp-toast-success',
            icon: 'fa-circle-check',
            badge: 'Success',
            defaultTitle: 'Successfully Processed'
        },
        danger: {
            className: 'erp-toast-danger',
            icon: 'fa-circle-exclamation',
            badge: 'Error',
            defaultTitle: 'Action Failed'
        },
        warning: {
            className: 'erp-toast-warning',
            icon: 'fa-triangle-exclamation',
            badge: 'Warning',
            defaultTitle: 'Attention Required'
        },
        info: {
            className: 'erp-toast-info',
            icon: 'fa-circle-info',
            badge: 'Notice',
            defaultTitle: 'System Notification'
        }
    };

    /**
     * Resolves appropriate theme, icon, badge and title based on message content
     */
    function resolveToastMetadata(message, explicitType, explicitTitle) {
        const msgLower = (message || '').toLowerCase();
        let type = (explicitType || '').toLowerCase();

        // Normalize type
        if (type === 'error') type = 'danger';
        if (type === 'warn') type = 'warning';

        // Auto-detect type if not provided
        if (!type || !THEMES[type]) {
            if (msgLower.includes('delete') || msgLower.includes('remove') || msgLower.includes('failed') || msgLower.includes('error') || msgLower.includes('invalid') || msgLower.includes('cannot') || msgLower.includes('denied')) {
                type = 'danger';
            } else if (msgLower.includes('success') || msgLower.includes('added') || msgLower.includes('created') || msgLower.includes('saved') || msgLower.includes('assigned') || msgLower.includes('completed')) {
                type = 'success';
            } else if (msgLower.includes('warn') || msgLower.includes('caution') || msgLower.includes('missing') || msgLower.includes('required') || msgLower.includes('expired') || msgLower.includes('timeout')) {
                type = 'warning';
            } else {
                type = 'info';
            }
        }

        const config = THEMES[type] || THEMES.info;
        let icon = config.icon;
        let badge = config.badge;
        let title = explicitTitle || config.defaultTitle;

        // Semantic refinements
        if (msgLower.includes('delete') || msgLower.includes('remove')) {
            icon = 'fa-trash-alt';
            badge = 'Deleted';
            if (!explicitTitle) title = 'Record Deleted';
        } else if (msgLower.includes('add') || msgLower.includes('create') || msgLower.includes('assigned')) {
            icon = 'fa-circle-check';
            badge = 'Created';
            if (!explicitTitle) title = 'Successfully Added';
        } else if (msgLower.includes('status') || msgLower.includes('toggle') || msgLower.includes('switched')) {
            icon = 'fa-toggle-on';
            badge = 'Status';
            if (!explicitTitle) title = 'Status Updated';
        } else if (msgLower.includes('update') || msgLower.includes('modified') || msgLower.includes('edited')) {
            icon = 'fa-pen-to-square';
            badge = 'Updated';
            if (!explicitTitle) title = 'Successfully Updated';
        }

        return {
            type: type,
            className: config.className,
            icon: icon,
            badge: badge,
            title: title
        };
    }

    /**
     * Gets or creates the universal floating toast container
     */
    function getOrCreateWrapper() {
        let wrapper = document.getElementById('erpToastWrapper');
        if (!wrapper) {
            wrapper = document.createElement('div');
            wrapper.id = 'erpToastWrapper';
            wrapper.className = 'erp-toast-wrapper';
            wrapper.setAttribute('role', 'region');
            wrapper.setAttribute('aria-label', 'System Notifications');
            document.body.appendChild(wrapper);
        }
        return wrapper;
    }

    /**
     * Dismisses a toast card with exit animation
     */
    function dismissToast(card) {
        if (!card || card.classList.contains('erp-toast-hiding')) return;
        card.classList.add('erp-toast-hiding');
        
        setTimeout(function() {
            if (card.parentNode) {
                const wrapper = card.parentNode;
                card.remove();
                if (wrapper && wrapper.children.length === 0) {
                    wrapper.remove();
                }
            }
        }, 300);
    }

    /**
     * Primary Global Toast Function
     * @param {string} message - Notification text/HTML
     * @param {string} [type] - 'success' | 'danger' | 'warning' | 'info'
     * @param {string} [title] - Custom heading title
     * @param {number} [duration=4500] - Duration in ms (0 = sticky/persistent)
     */
    function erpToast(message, type, title, duration) {
        if (!message) return null;

        const dur = (typeof duration === 'number') ? duration : 4500;
        const meta = resolveToastMetadata(message, type, title);
        const wrapper = getOrCreateWrapper();

        // Create toast element
        const card = document.createElement('div');
        card.className = 'erp-toast-card ' + meta.className;
        card.setAttribute('role', 'alert');
        card.setAttribute('aria-live', 'assertive');
        card.setAttribute('aria-atomic', 'true');
        card.dataset.toastManaged = 'true';

        let progressBarHtml = '';
        if (dur > 0) {
            progressBarHtml = '<div class="erp-toast-progress-bar">' +
                '<div class="erp-toast-progress-fill" style="animation: erpToastProgress ' + dur + 'ms linear forwards;"></div>' +
            '</div>';
        }

        card.innerHTML = 
            '<div class="erp-toast-icon-box">' +
                '<i class="fas ' + meta.icon + '"></i>' +
            '</div>' +
            '<div class="erp-toast-content">' +
                '<div class="erp-toast-header">' +
                    '<span class="erp-toast-badge">' + meta.badge + '</span>' +
                    '<strong class="erp-toast-title">' + meta.title + '</strong>' +
                    '<span class="erp-toast-time">Just now</span>' +
                '</div>' +
                '<div class="erp-toast-body">' + message + '</div>' +
            '</div>' +
            '<button type="button" class="erp-toast-close" aria-label="Close notification">' +
                '<i class="fas fa-times"></i>' +
            '</button>' +
            progressBarHtml;

        // Close button handler
        const closeBtn = card.querySelector('.erp-toast-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                dismissToast(card);
            });
        }

        // Timer & hover pause handling
        if (dur > 0) {
            let remaining = dur;
            let startTime = Date.now();
            let timerId = null;

            const startTimer = function() {
                startTime = Date.now();
                timerId = setTimeout(function() {
                    dismissToast(card);
                }, remaining);
            };

            const pauseTimer = function() {
                if (timerId) {
                    clearTimeout(timerId);
                    timerId = null;
                    remaining -= (Date.now() - startTime);
                    if (remaining < 600) remaining = 600;
                }
            };

            card.addEventListener('mouseenter', pauseTimer);
            card.addEventListener('mouseleave', startTimer);
            startTimer();
        }

        wrapper.appendChild(card);
        return card;
    }

    /**
     * Checks URL search params for redirect messages
     */
    function checkUrlMessages() {
        try {
            if (!window.location.search) return;
            const urlParams = new URLSearchParams(window.location.search);
            
            const msg = urlParams.get('msg') || urlParams.get('message') || urlParams.get('status_msg');
            const success = urlParams.get('success');
            const error = urlParams.get('error');
            const type = urlParams.get('type');
            let shouldClean = false;

            if (success) {
                erpToast(decodeURIComponent(success), 'success');
                urlParams.delete('success');
                shouldClean = true;
            } else if (error) {
                let errText = error;
                if (error === 'session_timeout') errText = 'Your session has expired. Please sign in again.';
                else if (error === 'invalid_role') errText = 'Your user role is not authorized or recognized.';
                erpToast(decodeURIComponent(errText), 'danger', 'Error');
                urlParams.delete('error');
                shouldClean = true;
            } else if (msg) {
                erpToast(decodeURIComponent(msg), type || null);
                urlParams.delete('msg');
                urlParams.delete('message');
                urlParams.delete('status_msg');
                if (type) urlParams.delete('type');
                shouldClean = true;
            }

            // Clean address bar so page reload doesn't repeat toast
            if (shouldClean && window.history && window.history.replaceState) {
                const newQuery = urlParams.toString();
                const newUrl = window.location.pathname + (newQuery ? '?' + newQuery : '') + window.location.hash;
                window.history.replaceState({ path: newUrl }, document.title, newUrl);
            }
        } catch (e) {
            console.error('ERP Toast URL parsing error:', e);
        }
    }

    /**
     * Checks PHP session flash message injected via window.__ERP_FLASH_TOAST
     */
    function checkFlashMessages() {
        if (window.__ERP_FLASH_TOAST) {
            try {
                const flash = window.__ERP_FLASH_TOAST;
                if (typeof flash === 'string') {
                    erpToast(flash);
                } else if (flash && flash.message) {
                    erpToast(flash.message, flash.type || null, flash.title || null);
                }
                window.__ERP_FLASH_TOAST = null;
            } catch (e) {
                console.error('ERP Toast flash parse error:', e);
            }
        }
    }

    /**
     * Converts in-page flash alerts to elevated floating toasts
     */
    function checkInPageAlerts() {
        // Find visible dismissible alerts that are flash/status notices
        const alerts = document.querySelectorAll('.alert-success, .alert-danger, .alert-warning');
        alerts.forEach(function(alert) {
            // Ignore preview calculators, static containers, or explicitly opted-out alerts
            if (alert.closest('#weekDates') || alert.closest('#editWeekDates') || 
                alert.classList.contains('no-toast') || alert.dataset.noToast === 'true' || 
                alert.dataset.toasted === 'true') {
                return;
            }

            const clone = alert.cloneNode(true);
            // Remove dismiss buttons and icons from text calculation
            const btns = clone.querySelectorAll('.btn-close, button, i.fas, i.far');
            btns.forEach(function(b) { b.remove(); });
            
            const text = (clone.textContent || '').trim();
            if (text && text.length > 2 && text.length < 400) {
                let type = 'info';
                if (alert.classList.contains('alert-success')) type = 'success';
                else if (alert.classList.contains('alert-danger')) type = 'danger';
                else if (alert.classList.contains('alert-warning')) type = 'warning';

                erpToast(text, type);
                alert.dataset.toasted = 'true';
            }
        });
    }

    /**
     * Adopts any pre-rendered static toast markup
     */
    function adoptStaticToasts() {
        const cards = document.querySelectorAll('.erp-toast-card:not([data-toast-managed])');
        cards.forEach(function(card) {
            card.dataset.toastManaged = 'true';
            const closeBtn = card.querySelector('.erp-toast-close');
            if (closeBtn) {
                closeBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    dismissToast(card);
                });
            }

            // Attach auto timer with hover pause
            let remaining = 4500;
            let startTime = Date.now();
            let timerId = null;

            const startTimer = function() {
                startTime = Date.now();
                timerId = setTimeout(function() {
                    dismissToast(card);
                }, remaining);
            };

            const pauseTimer = function() {
                if (timerId) {
                    clearTimeout(timerId);
                    timerId = null;
                    remaining -= (Date.now() - startTime);
                    if (remaining < 600) remaining = 600;
                }
            };

            card.addEventListener('mouseenter', pauseTimer);
            card.addEventListener('mouseleave', startTimer);
            startTimer();
        });
    }

    // Expose global methods
    window.erpToast = erpToast;
    window.showToast = erpToast;
    window.dismissErpToast = function(cardOrId) {
        if (!cardOrId) {
            const first = document.querySelector('.erp-toast-card');
            if (first) dismissToast(first);
            return;
        }
        const el = (typeof cardOrId === 'string') ? document.getElementById(cardOrId) : cardOrId;
        if (el) dismissToast(el);
    };

    // Auto-initialize on load
    function init() {
        adoptStaticToasts();
        checkFlashMessages();
        checkUrlMessages();
        checkInPageAlerts();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})(window, document);
