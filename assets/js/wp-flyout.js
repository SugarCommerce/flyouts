/**
 * WP Flyout Core JavaScript - Simplified
 *
 * Handles flyout mechanics for Manager-based flyouts only
 * @version 2.0.0
 */

/*
 * Per-build config lookup.
 *
 * Each Strauss-prefixed copy of this library enqueues its own scripts under
 * its own handle prefix and publishes its REST URL and nonce into this
 * registry keyed by that prefix. A script finds its own entry from the id
 * WordPress stamps on its <script> element ("{handle}-js"), falling back to
 * the longest registered key that prefixes it — component scripts are
 * registered under "{prefix}-{component}" but the config is published once
 * under "{prefix}".
 *
 * Defined idempotently: two builds load two identical copies of this file.
 */
window.SugarCommerceFlyouts = window.SugarCommerceFlyouts || {};
window.SugarCommerceFlyouts.resolve = window.SugarCommerceFlyouts.resolve || function (el) {
    var id = el && el.id ? el.id.replace(/-js$/, '') : '';
    var reg = window.SugarCommerceFlyouts;
    var best = '';
    var key;

    if (reg[id]) {
        return reg[id];
    }

    for (key in reg) {
        if (key === 'resolve' || !Object.prototype.hasOwnProperty.call(reg, key)) {
            continue;
        }
        if (id.indexOf(key) === 0 && key.length > best.length) {
            best = key;
        }
    }

    return best ? reg[best] : (window.wpFlyout || {});
};

/*
 * A REST request, the way fetch() makes one.
 *
 * Through wp.apiFetch when the page has it, because the nonce printed with
 * the page runs out after a day: a screen left open overnight then gets a
 * 403 from every panel. apiFetch notices the stale nonce, asks for a fresh
 * one and sends the request again. Its answer comes back shaped like a fetch
 * Response (ok, status, json()), so the callers read it the same either way;
 * a body that was not JSON makes json() reject, as it would have.
 *
 * Defined idempotently, like resolve(): two builds load two copies.
 *
 * @param {string} url  The route's full URL.
 * @param {Object} init As fetch's: method, headers, body.
 * @return {Promise<Object>} A Response, or something shaped like one.
 */
window.SugarCommerceFlyouts.request = window.SugarCommerceFlyouts.request || function (url, init) {
    var api = window.wp && window.wp.apiFetch;

    init = init || {};

    if (typeof api !== 'function') {
        return window.fetch(url, init);
    }

    function answer(ok, status, json, unreadable) {
        return {
            ok: ok,
            status: status,
            json: function () {
                return unreadable ? Promise.reject(new SyntaxError(json && json.message || '')) : Promise.resolve(json);
            }
        };
    }

    return api({
        url: url,
        method: init.method || 'GET',
        headers: init.headers || {},
        body: init.body,
        parse: true
    }).then(function (json) {
        return answer(true, 200, json, false);
    }, function (error) {
        if (!error || typeof error !== 'object' || error.name === 'AbortError' || error.code === 'fetch_error' || error.code === 'offline_error') {
            throw (error instanceof Error ? error : new Error((error && error.message) || ''));
        }

        return answer(false, (error.data && error.data.status) || 400, error, error.code === 'invalid_json');
    });
};

(function ($) {
    'use strict';

    /*
     * Whether an Escape belongs to something inside the panel rather than
     * to the panel.
     *
     * A dropdown that is open closes on Escape, and so did the panel under
     * it, in the same keypress -- the widget's own handler runs first and
     * by the time the key reaches the document there is nothing left to
     * say it had been open. So the question is asked on the way down,
     * before anything has handled it.
     */
    var escapeWasTaken = false;

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') {
            return;
        }

        var target = e.target && e.target.closest ? e.target : null;

        escapeWasTaken = !!(
            // An open combobox or menu the key was typed in. Not anything
            // that is merely expanded: an open accordion row is, and
            // Escape on one of those is still meant for the panel.
            (target && target.closest(
                '[role="combobox"][aria-expanded="true"], [aria-haspopup][aria-expanded="true"], [role="menu"], [role="listbox"]'
            )) ||
            // A dialog over this one -- the media library, a tag picker.
            Array.prototype.some.call(
                document.querySelectorAll('[role="dialog"]:not(.wp-flyout)'),
                function (dialog) {
                    return !dialog.hidden && dialog.getClientRects().length > 0;
                }
            )
        );
    }, true);

    window.WPFlyout = {
        // Configuration
        config: {
            animationDuration: 300,
            animationDelay: 10,
            focusDelay: 350
        },

        // Track active flyouts
        active: [],

        // What had focus when each flyout opened, by flyout ID
        openers: {},

        // What can take focus inside a panel
        focusable: 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), ' +
            'select:not([disabled]), textarea:not([disabled]), summary, [tabindex]:not([tabindex="-1"])',

        /**
         * Open a flyout by ID (flyout must already exist in DOM)
         *
         * @param {string}  id                 Flyout element ID
         * @param {Object}  [options]
         * @param {boolean} [options.instant]  Appear in place, without sliding in
         * @param {Element} [options.opener]   What to hand focus back to on close
         * @param {string}  [options.focus]    Selector to focus instead of the first field,
         *                                     or ':panel' for the panel itself
         */
        open: function(id, options) {
            const $flyout = $('#' + id);

            options = options || {};

            if (!$flyout.length) {
                console.warn('WP Flyout: Element not found with ID:', id);
                return false;
            }

            // Where focus goes back to. Remembered before anything in the
            // panel takes it, and never the page's <body>, which is what
            // activeElement is when nothing has focus.
            const opener = options.opener || document.activeElement;
            if (opener && opener !== document.body && !$flyout[0].contains(opener)) {
                this.openers[id] = opener;
            }

            this.describe($flyout);

            // Show overlay
            this.showOverlay();

            // Add body class
            $('body').addClass('wp-flyout-open');

            if (options.instant) {
                // Taking over from a panel that is already on screen: sliding
                // in again would read as a second panel arriving.
                $flyout.addClass('wp-flyout-instant active');
                setTimeout(() => $flyout.removeClass('wp-flyout-instant'), 50);
            } else {
                // Activate with animation delay
                setTimeout(() => {
                    $flyout.addClass('active');
                }, this.config.animationDelay);
            }

            // Track as active
            if (!this.active.includes(id)) {
                this.active.push(id);
            }

            // Initialize tabs if present
            this.initTabs($flyout);

            // Focus management after animation
            setTimeout(() => {
                // Unless something in the panel has taken it already, and
                // would lose it to this.
                const holder = document.activeElement;

                if (!holder || holder === $flyout[0] || !$flyout[0].contains(holder)) {
                    this.focusInside($flyout, options.focus);
                }

                // Trigger events
                $(document).trigger('wpflyout:opened', { id: id, element: $flyout[0] });
                $flyout.trigger('flyout:ready');
            }, options.instant ? this.config.animationDelay : this.config.focusDelay);

            return $flyout;
        },

        /**
         * Say what the panel is to anything that reads the page aloud
         *
         * A dialog, named by its own title. Set here as well as in the
         * markup so a panel built by hand is one too.
         */
        describe: function($flyout) {
            const $title = $flyout.find('.wp-flyout-title').first();

            if (!$flyout.attr('role')) {
                $flyout.attr({ role: 'dialog', 'aria-modal': 'true' });
            }

            if (!$flyout.attr('tabindex')) {
                $flyout.attr('tabindex', '-1');
            }

            if ($title.length && !$flyout.attr('aria-labelledby')) {
                if (!$title.attr('id')) {
                    $title.attr('id', $flyout.attr('id') + '-title');
                }
                $flyout.attr('aria-labelledby', $title.attr('id'));
            }
        },

        /**
         * Put focus in the panel
         *
         * The first field, as it always was. A panel with no fields still
         * takes focus itself: left on the page behind, Tab would walk the
         * list table under the overlay.
         */
        focusInside: function($flyout, selector) {
            let $target = selector && selector !== ':panel' ? $flyout.find(selector).filter(':visible:enabled').first() : $();

            // A panel that is read far more often than it is changed does
            // not open with the caret in a box: on a phone that is the
            // keyboard over the record somebody came to look at. Nor does
            // one whose list is stepped through with J and K, where a
            // caret in a box would make the next J a letter.
            if (!$target.length && (
                selector === ':panel' ||
                $flyout.hasClass('wp-flyout-footer-when-editing') ||
                $flyout.hasClass('wp-flyout-step-keys')
            )) {
                $target = $flyout;
            }

            if (!$target.length) {
                $target = $flyout
                    .find('.wp-flyout-body')
                    .find('input:visible:enabled, select:visible:enabled, textarea:visible:enabled')
                    .first();
            }

            if (!$target.length) {
                $target = $flyout;
            }

            $target.trigger('focus');
        },

        /**
         * Close a flyout
         *
         * @param {string}  id               Flyout element ID
         * @param {Object}  [options]
         * @param {boolean} [options.force]  Close whatever a listener thinks of it
         */
        close: function(id, options) {
            const $flyout = $('#' + id);

            options = options || {};

            if (!$flyout.length) return false;

            // Already on its way out. A second request for the same thing
            // -- a double click, a second listener on the same button --
            // must not ask every listener the same question again.
            if (!this.active.includes(id)) return false;

            // Trigger closing event (cancelable)
            const event = $.Event('wpflyout:closing');
            $(document).trigger(event, { id: id, element: $flyout[0], force: !!options.force });

            if (event.isDefaultPrevented() && !options.force) {
                return false;
            }

            // Start close animation
            $flyout.removeClass('active');

            // Remove from active list
            this.active = this.active.filter(activeId => activeId !== id);

            // Focus goes back where it came from, or it is left on a panel
            // that is no longer there and the next Tab starts the page again.
            const opener = this.openers[id];
            delete this.openers[id];

            if (opener && document.contains(opener) && typeof opener.focus === 'function') {
                opener.focus();
            }

            // Clean up after animation
            setTimeout(() => {
                // Remove dynamically created flyouts
                if ($flyout.hasClass('wp-flyout-dynamic')) {
                    $flyout.remove();
                }

                // Remove overlay if no more flyouts
                if (this.active.length === 0) {
                    this.hideOverlay();
                }

                // Trigger closed event
                $(document).trigger('wpflyout:closed', { id: id });
            }, this.config.animationDuration);

            return true;
        },

        /**
         * Take a flyout away without closing it
         *
         * For a panel that is being replaced by another in the same place.
         * No animation, no events, and the overlay stays.
         */
        discard: function(id) {
            delete this.openers[id];
            this.active = this.active.filter(activeId => activeId !== id);
            $('#' + id).remove();
        },

        /**
         * Close all flyouts
         */
        closeAll: function() {
            [...this.active].forEach(id => this.close(id));
        },

        /**
         * Get the last opened flyout ID
         */
        getLastId: function() {
            return this.active[this.active.length - 1] || null;
        },

        /**
         * Show overlay
         */
        showOverlay: function() {
            let $overlay = $('.wp-flyout-overlay');

            if (!$overlay.length) {
                $overlay = $('<div class="wp-flyout-overlay"></div>').appendTo('body');
            }

            // Delay for animation
            setTimeout(() => $overlay.addClass('active'), 10);
        },

        /**
         * Hide overlay
         */
        hideOverlay: function() {
            const $overlay = $('.wp-flyout-overlay');
            $overlay.removeClass('active');
            $('body').removeClass('wp-flyout-open');

            setTimeout(() => {
                // Unless a panel opened again in the meantime, in which case
                // the overlay it is standing on is this one.
                if (this.active.length === 0) {
                    $overlay.remove();
                }
            }, this.config.animationDuration);
        },

        /**
         * Initialize tab switching
         */
        initTabs: function($flyout) {
            $flyout.off('click.tabs').on('click.tabs', '.wp-flyout-tab', function(e) {
                e.preventDefault();

                const $tab = $(this);
                if ($tab.hasClass('disabled')) return;

                const tabId = $tab.data('tab');

                // Update active states
                $flyout.find('.wp-flyout-tab').removeClass('active').attr('aria-selected', 'false');
                $tab.addClass('active').attr('aria-selected', 'true');

                // Switch content
                $flyout.find('.wp-flyout-tab-content').removeClass('active');
                $flyout.find('#tab-' + tabId).addClass('active');

                // Trigger event
                $(document).trigger('wpflyout:tab-changed', {
                    flyoutId: $flyout.attr('id'),
                    tabId: tabId
                });
            });
        },

        /**
         * Keep Tab inside the open panel
         *
         * The page behind is under an overlay and cannot be clicked, but it
         * could still be tabbed into -- focus left the panel after its last
         * button and went on down a list table nobody could see.
         *
         * Only when focus is in the panel to begin with. The media library
         * and the colour picker open over it and have their own idea of
         * where Tab goes.
         */
        trapFocus: function(e) {
            const $flyout = $('#' + this.getLastId());
            const current = document.activeElement;

            if (!$flyout.length) return;

            if (current && current !== document.body && !$flyout[0].contains(current)) {
                return;
            }

            const $stops = $flyout.find(this.focusable).filter(':visible');

            if (!$stops.length) {
                e.preventDefault();
                $flyout.trigger('focus');
                return;
            }

            const first = $stops[0];
            const last = $stops[$stops.length - 1];

            if (e.shiftKey && (current === first || current === $flyout[0] || current === document.body)) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && (current === last || current === document.body)) {
                e.preventDefault();
                first.focus();
            }
        },

        /**
         * Initialize global event handlers
         */
        init: function() {
            // Close button
            $(document).on('click.wpflyout', '.wp-flyout-close', (e) => {
                const flyoutId = $(e.currentTarget).closest('.wp-flyout').attr('id');
                this.close(flyoutId);
            });

            // Overlay click to close
            $(document).on('click.wpflyout', '.wp-flyout-overlay', () => {
                const lastId = this.getLastId();
                if (lastId) this.close(lastId);
            });

            $(document).on('keydown.wpflyout', (e) => {
                if (!this.active.length) return;

                // Escape key to close, unless something in the panel has
                // already used it.
                if (e.key === 'Escape' && !escapeWasTaken && !e.isDefaultPrevented()) {
                    this.close(this.getLastId());
                }

                if (e.key === 'Tab') {
                    this.trapFocus(e);
                }
            });
        }
    };

    // Initialize on ready
    $(document).ready(() => WPFlyout.init());

})(jQuery);
