/**
 * WP Flyout Manager
 *
 * Handles flyout loading, saving, and deletion via REST API.
 * Always reloads page after successful save/delete operations.
 *
 * @package     SugarCommerce\Flyouts
 * @version     2.0.0
 */
(function ($) {
    'use strict';
    // This build's REST config; see the resolver in wp-flyout.js.
    var wpFlyout = window.SugarCommerceFlyouts.resolve(document.currentScript);

    /*
     * Everything this script says to a person.
     *
     * In English here, and replaced by whatever the page publishes beside
     * the REST URL -- which is where the translated ones come from. The
     * English stays as the fallback because a panel that says "undefined"
     * on its Discard button is worse than one that says it in English.
     */
    var strings = $.extend({
        saved: 'Saved',
        deleted: 'Deleted',
        done: 'Done',
        loading: 'Loading…',
        loadFailed: 'This could not be opened',
        requestFailed: 'Something went wrong. Please try again.',
        required: 'Please fill in all required fields.',
        close: 'Close',
        previous: 'Previous',
        next: 'Next',
        stepped: '%1$s, %2$s of %3$s',
        stepKeysHint: 'Next and previous',
        stepEnd: 'That is the end of the list.',
        copy: 'Copy',
        copied: 'Copied',
        copyFailed: 'That could not be copied.',
        unsavedTitle: 'Discard unsaved changes?',
        unsavedText: 'What you have changed here has not been saved.',
        keepEditing: 'Keep editing',
        discard: 'Discard changes',
        deleteTitle: 'Are you sure you want to delete this item?',
        deleteConfirm: 'Delete',
        cancel: 'Cancel',
        confirm: 'Continue',
        send: 'Send',
        promptDiscard: 'Discard what you have written?',
        keepWriting: 'Keep Writing',
        promptDrop: 'Discard',
        promptEmpty: 'This needs filling in.'
    }, wpFlyout.strings || {});

    /*
     * The library's own icons -- close, copy, previous and next -- drawn by
     * the server and published beside the REST URL, so they are the same
     * Heroicons the panel's own markup has and no path is copied here. They
     * are the fields package's cleaned SVG and go in as they are. A page that published
     * none gets the Dashicon each one used to be.
     */
    var icon = function (key, dashicon) {
        return (wpFlyout.icons && wpFlyout.icons[key]) ||
            '<span class="dashicons dashicons-' + dashicon + ' sugarcommerce-icon" aria-hidden="true"></span>';
    };

    // How long a toast stays, and how long a saved panel stays open under it.
    var TOAST_FOR = 2500;
    var CLOSE_AFTER = 900;

    const WPFlyoutManager = {

        // Counts loads, so an answer that arrives after a newer question
        // was asked can be told apart and dropped.
        loads: 0,

        /**
         * Initialize manager
         */
        init: function () {
            var self = this;

            $(document).on('click', '.wp-flyout-trigger', this.handleTrigger.bind(this));

            // The one place a panel is asked whether it may close: the X,
            // Escape and the overlay all end up at WPFlyout.close(), which
            // asks this before it does anything.
            $(document).on('wpflyout:closing', function (e, detail) {
                var $flyout = $(detail && detail.element);

                if (!$flyout.length) {
                    return;
                }

                if (!detail.force && self.isDirty($flyout)) {
                    e.preventDefault();

                    self.confirmDiscard($flyout).then(function (discard) {
                        if (discard) {
                            WPFlyout.close(detail.id, { force: true });
                        }
                    });

                    return;
                }

                self.teardown($flyout);
            });

            // Cmd or Ctrl and S saves, because that is what the hand does
            // after changing something whether or not anyone said it could.
            // The browser's own Save Page dialog is never what was meant
            // while a panel is open, so the key is taken either way.
            $(document).on('keydown.wpflyoutmanager', function (e) {
                if (!(e.metaKey || e.ctrlKey) || e.altKey || String(e.key).toLowerCase() !== 's') {
                    return;
                }

                var $flyout = self.current();

                if (!$flyout.length) {
                    return;
                }

                e.preventDefault();

                // Asked now rather than read off the button: the button
                // catches up a moment after a keystroke, and this is the
                // keystroke after it.
                self.refresh($flyout);
                $flyout.find('.wp-flyout-save:enabled').first().trigger('click');
            });

            // J and K, and the arrow keys, load the row after and the row
            // before, for a panel that asked for the keys.
            $(document).on('keydown.wpflyoutsteps', function (e) {
                self.stepKey(e);
            });

            // A click anywhere else puts the header menu away.
            $(document).on('click.wpflyoutmenu', function (e) {
                if (!$(e.target).closest('.wp-flyout-menu').length) {
                    self.closeMenu($('.wp-flyout-menu'));
                }
            });

            // A trigger marked data-flyout-auto opens itself once the page is
            // ready. It is how a panel opens after a round trip through the
            // server -- a bulk action that redirects back with a selection,
            // for instance, where there is nothing for the reader to click
            // that they have not already clicked.
            //
            // Only the first, and only once: two panels opening over each
            // other is not a thing anybody asked for.
            $(function () {
                $('.wp-flyout-trigger[data-flyout-auto]').first().trigger('click');
            });
        },

        /**
         * The panel on top, if one is open
         */
        current: function () {
            var id = WPFlyout.getLastId();

            return id ? $('#' + id) : $();
        },

        /**
         * Make a REST API request
         *
         * A failure rejects with an Error carrying the response's `code`
         * and `data`, which is where a save says which field was wrong.
         *
         * @param {string} endpoint REST endpoint path (e.g. '/load')
         * @param {Object} data     Request body data
         * @param {string} method   HTTP method (default: 'POST')
         * @return {Promise} Resolves with parsed JSON response
         */
        api: function (endpoint, data, method) {
            method = method || 'POST';

            const options = {
                method: method,
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': wpFlyout.restNonce
                },
                credentials: 'same-origin'
            };

            if (method === 'GET') {
                const params = new URLSearchParams(data);
                endpoint = endpoint + '?' + params.toString();
            } else {
                options.body = JSON.stringify(data);
            }

            return window.SugarCommerceFlyouts.request(wpFlyout.restUrl + endpoint, options)
                .then(function (response) {
                    return response.json().then(
                        function (json) {
                            if (!response.ok) {
                                var error = new Error((json && json.message) || strings.requestFailed);

                                error.code = json && json.code;
                                error.data = (json && json.data) || {};

                                throw error;
                            }
                            return json;
                        },
                        function () {
                            // Not JSON at all: a fatal error's HTML page, a
                            // proxy's. What the parser thought of it is of
                            // no use to the person looking at the panel.
                            throw new Error(strings.requestFailed);
                        }
                    );
                });
        },

        /**
         * Handle trigger click
         */
        handleTrigger: function (e) {
            e.preventDefault();

            var $btn = $(e.currentTarget);
            var config = this.extractConfig($btn);

            this.request(config);
        },

        /**
         * Extract configuration from trigger button
         */
        extractConfig: function ($btn) {
            var config = {
                flyout: $btn.data('flyout'),
                manager: $btn.data('flyout-manager'),
                size: $btn.data('flyout-size') || '',

                // What the panel's header will be, so the placeholder shown
                // while it loads can be dressed the same way.
                header: $btn.attr('data-flyout-header') || '',

                // The arrows, unless the panel asked for none or for the
                // keyboard in their place; and whether it asked for the
                // keyboard at all.
                steps: ['0', 'keys'].indexOf(String($btn.attr('data-flyout-steps'))) === -1,
                stepKeys: ['keys', 'both'].indexOf(String($btn.attr('data-flyout-steps'))) !== -1,
                trigger: $btn[0],
                data: {}
            };

            // Collect additional data attributes
            $.each($btn[0].dataset, function (key, value) {
                if (['flyout', 'flyoutManager', 'flyoutSize', 'flyoutHeader', 'flyoutSteps'].indexOf(key) === -1) {
                    config.data[key] = value;
                }
            });

            return config;
        },

        /**
         * Open a record, asking first if that would lose something
         *
         * Every way of putting a record in the panel comes through here,
         * so none of them can replace a half-edited form without saying so.
         *
         * @param {Object}  config          What extractConfig() made of a trigger
         * @param {Object}  [options]
         * @param {boolean} [options.force] Replace what is open without asking
         * @param {string}  [options.focus] Selector to focus once it is open
         */
        request: function (config, options) {
            var self = this;
            var $open = this.current();

            options = options || {};

            if ($open.length && !options.force && this.isDirty($open)) {
                this.confirmDiscard($open).then(function (discard) {
                    if (discard) {
                        self.loadFlyout(config, options);
                    }
                });

                return;
            }

            this.loadFlyout(config, options);
        },

        /**
         * Load flyout via REST API
         */
        loadFlyout: function (config, options) {
            var self = this;
            var load = ++this.loads;
            var started = Date.now();

            var requestData = {
                manager: config.manager,
                flyout: config.flyout,
                item_id: config.data.id || 0
            };

            options = options || {};

            // The panel is on screen before the record is. A click that is
            // answered by nothing for half a second gets clicked again.
            var skeleton = this.showSkeleton(config, options);

            // Whether that placeholder is still what this load is for: not
            // closed in the meantime, and not overtaken by another record.
            var wanted = function () {
                return load === self.loads && WPFlyout.active.indexOf(skeleton.id) !== -1;
            };

            // Not before the placeholder has finished sliding in. Swapped
            // halfway, the record appears at the end of a journey the eye
            // was still following.
            var settled = function (then) {
                var wait = skeleton.instant ? 0 : WPFlyout.config.animationDuration + 20 - (Date.now() - started);

                setTimeout(function () {
                    if (wanted()) {
                        then();
                    }
                }, Math.max(0, wait));
            };

            this.api('/load', requestData)
                .then(function (response) {
                    settled(function () {
                        if (response.success) {
                            self.displayFlyout(response.html, config, options);
                        } else {
                            self.showLoadFailure(skeleton.id, response.message);
                        }
                    });
                })
                .catch(function (error) {
                    settled(function () {
                        self.showLoadFailure(skeleton.id, error.message);
                    });
                });
        },

        /**
         * Put a placeholder panel where the record is about to be
         *
         * Grey blocks in the shape of a card of fields, in a panel of the
         * size the real one will be -- the trigger says which, and failing
         * that the panel already open does. It stands in for the record
         * completely: it has a close button, it traps focus, and when the
         * record has neighbours it has their arrows too, so somebody
         * stepping through a list does not wait for each one to load before
         * asking for the next.
         *
         * @return {{id: string, instant: boolean}}
         */
        showSkeleton: function (config, options) {
            var self = this;
            var $open = this.current();
            var instant = $open.length > 0;
            var shape = [];
            var id = 'wp-flyout-loading-' + this.loads;

            // The size and side of what it replaces, so nothing moves.
            if ($open.length) {
                shape = ($open.attr('class') || '').split(/\s+/).filter(function (name) {
                    return /^wp-flyout-(small|medium|large|full|left|right)$/.test(name);
                });
            }

            if (!shape.length) {
                shape = [
                    'wp-flyout-' + (['small', 'medium', 'large', 'full'].indexOf(config.size) !== -1 ? config.size : 'medium'),
                    'wp-flyout-right'
                ];
            }

            this.clear();

            var field = '<span class="wp-flyout-skeleton wp-flyout-skeleton-label"></span>' +
                '<span class="wp-flyout-skeleton wp-flyout-skeleton-input"></span>';

            var $skeleton = $(
                '<div class="wp-flyout wp-flyout-loading wp-flyout-dynamic" aria-busy="true">' +
                    '<div class="wp-flyout-header">' +
                        '<div class="wp-flyout-header-content">' +
                            '<h2 class="wp-flyout-title">' +
                                '<span class="wp-flyout-skeleton wp-flyout-skeleton-title" aria-hidden="true"></span>' +
                                '<span class="screen-reader-text wp-flyout-loading-text" role="status"></span>' +
                            '</h2>' +
                        '</div>' +
                        '<button type="button" class="wp-flyout-close">' +
                            icon('close', 'no-alt') +
                        '</button>' +
                    '</div>' +
                    '<div class="wp-flyout-body">' +
                        '<div class="wp-flyout-skeleton-card" aria-hidden="true">' +
                            '<span class="wp-flyout-skeleton wp-flyout-skeleton-heading"></span>' +
                            field +
                            '<span class="wp-flyout-skeleton wp-flyout-skeleton-label"></span>' +
                            '<span class="wp-flyout-skeleton wp-flyout-skeleton-input wp-flyout-skeleton-input--tall"></span>' +
                            field +
                        '</div>' +
                        '<div class="wp-flyout-skeleton-card" aria-hidden="true">' +
                            '<span class="wp-flyout-skeleton wp-flyout-skeleton-heading"></span>' +
                            field +
                        '</div>' +
                    '</div>' +
                '</div>'
            );

            $skeleton.attr('id', id).addClass(shape.join(' '));
            $skeleton.find('.wp-flyout-loading-text').text(strings.loading);

            $('body').append($skeleton);
            $skeleton.data(config);

            this.dressHeader($skeleton, config);

            // Only what a placeholder can do: close, and step to a neighbour.
            $skeleton.on('click', '.wp-flyout-prev, .wp-flyout-next', function (e) {
                e.preventDefault();
                self.step(config, $(this).hasClass('wp-flyout-next') ? 'next' : 'prev');
            });

            WPFlyout.open(id, {
                instant: instant,
                opener: config.trigger,
                focus: options.focus
            });

            return { id: id, instant: instant };
        },

        /**
         * Say in the placeholder that the record did not arrive
         *
         * In the panel, where the person is looking, and not in a browser
         * alert that has to be dismissed before anything else can happen.
         */
        showLoadFailure: function (id, message) {
            var $skeleton = $('#' + id);

            $skeleton.removeClass('wp-flyout-loading').attr('aria-busy', 'false');
            $skeleton.find('.wp-flyout-title').text(strings.loadFailed);
            $skeleton.find('.wp-flyout-body').empty();

            this.showAlert($skeleton, message || strings.requestFailed, 'error');

            $skeleton.find('.wp-flyout-close').trigger('focus');
        },

        /**
         * Take every panel off the page, open or not
         *
         * A closed panel is left in the document until the next one is
         * loaded, and an open one is about to be replaced. Either way it
         * goes without ceremony, and without being left on the list of
         * what is open -- which is what kept the overlay up after a panel
         * had been replaced and then closed.
         */
        clear: function () {
            var self = this;

            $('.wp-flyout').each(function () {
                self.teardown($(this));
                WPFlyout.discard(this.id);
            });
        },

        /**
         * Stop watching a panel that is going away
         */
        teardown: function ($flyout) {
            var observer = $flyout.data('wpFlyoutObserver');
            var settle = $flyout.data('wpFlyoutConfirm');

            if (observer) {
                observer.disconnect();
                $flyout.removeData('wpFlyoutObserver');
            }

            // A question nobody is going to answer now.
            if (typeof settle === 'function') {
                settle(false);
            }
        },

        /**
         * Display flyout and setup handlers
         */
        displayFlyout: function (html, config, options) {
            options = options || {};

            // Remove existing flyouts and add new one
            this.clear();
            $('body').append(html);

            var $flyout = $('.wp-flyout').last();
            var flyoutId = $flyout.attr('id');

            // Store config
            $flyout.data(config);

            // Ensure form wrapper exists
            this.ensureForm($flyout);

            // The controls this script adds to the header
            this.dressHeader($flyout, config);

            // And somewhere to say things quietly
            this.toastRegion($flyout);

            // Bind handlers
            this.bindHandlers($flyout, flyoutId, config);

            // Open it. In place: the placeholder it replaces has already
            // done the sliding in.
            WPFlyout.open(flyoutId, {
                instant: true,
                opener: config.trigger,
                focus: options.focus
            });

            this.announceStep($flyout, options.announce);

            // Every field in here is rendered by the fields package, and the package
            // binds its modules on DOMContentLoaded — which has long since
            // passed by the time this markup is fetched and appended. So none
            // of it ran: no combobox on a searchable select, no reorder on a
            // sortable, no rows on a repeater, no colour picker, no code
            // editor. init() is documented as safe to call again for exactly
            // this, and each module marks what it has bound.
            if (window.SugarCommerceFieldsModules && typeof window.SugarCommerceFieldsModules.init === 'function') {
                window.SugarCommerceFieldsModules.init($flyout[0]);
            }

            // After the fields package, so what it does to a field while setting it up
            // is part of how the form started and not a change to it.
            this.watch($flyout);
        },

        /**
         * Ensure form wrapper exists
         */
        ensureForm: function ($flyout) {
            if (!$flyout.find('form').length) {
                var $body = $flyout.find('.wp-flyout-body');
                var $form = $('<form class="wp-flyout-form" novalidate></form>');
                $form.append($body.children());
                $body.append($form);
            }
        },

        // =====================================================================
        // HEADER
        // =====================================================================

        /**
         * Give the header the controls this script adds to it
         *
         * The close button gets its name, and sits in a group at the end
         * of the header with whatever else is there -- the menu the server
         * rendered, and previous and next when the record has neighbours.
         * A header rendered without the group is given one, so a panel
         * built by an older server, or by hand, comes out the same.
         */
        dressHeader: function ($flyout, config) {
            var $header = $flyout.find('.wp-flyout-header').first();
            var $actions = $header.children('.wp-flyout-header-actions');
            var $close = $header.find('.wp-flyout-close');

            if (!$header.length) {
                return;
            }

            if (!$actions.length) {
                $actions = $('<div class="wp-flyout-header-actions"></div>').appendTo($header);
                $actions.append($close);
            }

            if (!$close.attr('aria-label')) {
                $close.attr('aria-label', strings.close);
            }

            if (!$close.attr('title')) {
                $close.attr('title', $close.attr('aria-label'));
            }

            this.addSteps($actions, config, $flyout);
            this.addCopy($flyout);
        },

        /**
         * Put a copy button beside a title that is something to copy
         *
         * A licence key, a code, a reference: a title somebody is going to
         * paste into an email. The panel says so with `copy_title`, which
         * arrives here as a class on the panel, and the button copies the
         * title as it is shown.
         */
        addCopy: function ($flyout) {
            var $title = $flyout.find('.wp-flyout-header .wp-flyout-title').first();

            if (!$flyout.hasClass('wp-flyout-copy-title') || !$title.length || !$.trim($title.text())) {
                return;
            }

            if ($title.siblings('.wp-flyout-copy').length) {
                return;
            }

            $('<button type="button" class="wp-flyout-copy"></button>')
                .attr({ 'aria-label': strings.copy, title: strings.copy })
                .append(icon('copy', 'admin-page'))
                .insertAfter($title);
        },

        /**
         * Copy text, and say whether it was
         *
         * The asynchronous clipboard needs a secure context, which an admin
         * on plain http is not, so there is the older way behind it.
         *
         * @param {string} text
         * @return {Promise<boolean>}
         */
        copy: function (text) {
            if (navigator.clipboard && window.isSecureContext) {
                return navigator.clipboard.writeText(text).then(function () {
                    return true;
                }, function () {
                    return false;
                });
            }

            var area = document.createElement('textarea');
            var ok = false;

            area.value = text;
            area.setAttribute('readonly', '');
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();

            try {
                ok = document.execCommand('copy');
            } catch (e) {
                ok = false;
            }

            document.body.removeChild(area);

            return Promise.resolve(ok);
        },

        /**
         * The triggers for other records of the same kind on this page
         *
         * Each row of a list table has one, and that is the whole of what
         * "the next record" means: the next row. One per record, however
         * many links a row has to it, and only records -- the Add button
         * opens the same flyout and is nobody's neighbour.
         *
         * @return {Element[]}
         */
        neighbours: function (config) {
            var seen = {};
            var list = [];

            $('.wp-flyout-trigger').each(function () {
                var $trigger = $(this);
                var id = String($trigger.attr('data-id') || '');

                // A link inside a panel names a record; it is not a row of
                // the list behind it, and is gone when that panel is.
                if ($trigger.closest('.wp-flyout').length) {
                    return;
                }

                if (
                    String($trigger.attr('data-flyout')) !== String(config.flyout) ||
                    String($trigger.attr('data-flyout-manager')) !== String(config.manager) ||
                    id === '' || id === '0' || seen[id]
                ) {
                    return;
                }

                seen[id] = true;
                list.push(this);
            });

            return list;
        },

        /**
         * Where a record is among its neighbours, or -1
         */
        position: function (config, list) {
            var id = String((config.data && config.data.id) || '');
            var at = -1;

            list.forEach(function (trigger, index) {
                if (String(trigger.getAttribute('data-id')) === id) {
                    at = index;
                }
            });

            return at;
        },

        /**
         * Add previous and next to the header, when there is either
         */
        addSteps: function ($actions, config, $flyout) {
            var list = this.neighbours(config);
            var at = this.position(config, list);
            var identity = config.header === 'identity' || ($flyout && $flyout.hasClass('wp-flyout-identity'));

            // Nothing to step to: a new record, or the only one listed.
            if (at === -1 || list.length < 2) {
                return;
            }

            // The keys in place of the arrows, or beside them: said once,
            // quietly, at the foot of the menu, where somebody looking for
            // what can be done will find it.
            if (config.stepKeys || ($flyout && $flyout.hasClass('wp-flyout-step-keys'))) {
                this.addStepHint($flyout);
            }

            // A panel that asked for none. The panel says so with a class
            // and its trigger with an attribute, because the placeholder
            // is drawn before the panel has arrived to say anything.
            if (config.steps === false || ($flyout && $flyout.hasClass('wp-flyout-no-steps'))) {
                return;
            }

            var button = function (direction, label, glyph, disabled) {
                return $('<button type="button"></button>')
                    .addClass('wp-flyout-step wp-flyout-' + direction)
                    .attr({ 'aria-label': label, title: label })
                    .prop('disabled', disabled)
                    .append(glyph);
            };

            var $steps = $('<div class="wp-flyout-steps" role="group"></div>')
                .append(button('prev', strings.previous, icon('previous', 'arrow-left-alt2'), at === 0))
                .append(button('next', strings.next, icon('next', 'arrow-right-alt2'), at === list.length - 1));

            // In a header with an identity the menu comes first: what can
            // be done to this record, then the way to another, then out.
            if (identity) {
                $steps.addClass('wp-flyout-steps--quiet').insertBefore($actions.children('.wp-flyout-close'));
            } else {
                $steps.prependTo($actions);
            }
        },

        /**
         * Say which keys step through the list, at the foot of the menu
         *
         * Not a menu item: it cannot be chosen and the arrow keys pass over
         * it. Left out where there is no menu, and hidden by the stylesheet
         * on a screen too narrow to have a keyboard beside it.
         */
        addStepHint: function ($flyout) {
            var $list = $flyout ? $flyout.find('.wp-flyout-menu-list').first() : $();

            if (!$list.length || $list.children('.wp-flyout-menu-hint').length) {
                return;
            }

            $('<div class="wp-flyout-menu-hint" role="presentation"></div>')
                .append('<kbd>J</kbd><kbd>K</kbd>')
                .append($('<span></span>').text(strings.stepKeysHint))
                .appendTo($list);
        },

        /**
         * Whether a key pressed here is somebody typing, or using a control
         * that has its own use for the key
         *
         * A letter belongs to whatever takes text. An arrow key belongs to
         * far more: a menu, a row of tabs, a slider, a chart that can be
         * read with them -- so an arrow steps only from the panel itself or
         * from a plain button or link.
         *
         * @param {Element} target What had focus
         * @param {boolean} arrow  Whether the key was an arrow key
         * @return {boolean}
         */
        keyIsTaken: function (target, arrow) {
            var $target = $(target);

            if (!target || target === document.body || $target.hasClass('wp-flyout')) {
                return false;
            }

            if (target.isContentEditable || $target.closest(
                'textarea, select, [contenteditable=""], [contenteditable="true"], ' +
                '[role="textbox"], [role="searchbox"], [role="combobox"], [role="listbox"], [role="menu"], .wp-flyout-menu'
            ).length) {
                return true;
            }

            if ($target.is('input')) {
                // A tick box takes no letters; everything else might.
                return arrow || ['checkbox', 'radio', 'button', 'submit'].indexOf(String(target.type)) === -1;
            }

            if (!arrow) {
                return false;
            }

            return !$target.is('a, button, summary') || $target.closest('[role]:not(.wp-flyout)').length > 0;
        },

        /**
         * Step with the keyboard: J or right for the next row, K or left
         * for the one before
         *
         * Only for a panel that asked for it, only when the key is not
         * somebody typing, and never over a question the panel is asking.
         * A form with changes in it is asked about first, by request(), as
         * it is for every other way of leaving a record.
         */
        stepKey: function (e) {
            var key = String(e.key);
            var arrow = key === 'ArrowRight' || key === 'ArrowLeft';
            var direction = { j: 'next', J: 'next', ArrowRight: 'next', k: 'prev', K: 'prev', ArrowLeft: 'prev' }[key];

            if (!direction || e.isDefaultPrevented() || e.metaKey || e.ctrlKey || e.altKey || e.repeat) {
                return;
            }

            var $flyout = $('.wp-flyout.active').last();
            var config = $flyout.data() || {};

            if (
                !$flyout.length ||
                !(config.stepKeys || $flyout.hasClass('wp-flyout-step-keys')) ||
                $flyout.hasClass('has-prompt') || $flyout.hasClass('has-confirm') ||
                this.keyIsTaken(e.target, arrow)
            ) {
                return;
            }

            // Something open over the panel -- the media library -- has
            // the keyboard, whatever has focus.
            if ($('[role="dialog"]:visible').not('.wp-flyout').length) {
                return;
            }

            var list = this.neighbours(config);
            var at = this.position(config, list);

            if (at === -1 || list.length < 2) {
                return;
            }

            e.preventDefault();

            if (!list[at + (direction === 'next' ? 1 : -1)]) {
                this.toast($flyout, strings.stepEnd);
                return;
            }

            this.step(config, direction, true);
        },

        /**
         * Load the record before or after this one, in place
         *
         * @param {Object}  config
         * @param {string}  direction 'next' or 'prev'
         * @param {boolean} [byKey]   Whether a key asked, and not an arrow
         */
        step: function (config, direction, byKey) {
            var list = this.neighbours(config);
            var at = this.position(config, list);
            var to = list[at + (direction === 'next' ? 1 : -1)];

            if (at === -1 || !to) {
                return;
            }

            var next = this.extractConfig($(to));
            var place = this.position(next, list);
            var last = place === (direction === 'next' ? list.length - 1 : 0);

            this.request(next, {
                // Focus stays on the arrow that was pressed, so it can be
                // pressed again -- or on the other one at the end of the
                // list, where the one that was pressed has gone dead. A
                // key leaves it on the panel, where the next key is heard.
                focus: byKey ? ':panel' : (last ? '.wp-flyout-step' : '.wp-flyout-' + direction),

                // Said once the record is there: which one, and where in
                // the list it is.
                announce: { at: place + 1, of: list.length }
            });
        },

        /**
         * Tell a screen reader which record a step arrived at
         *
         * In a region of its own inside the new panel, filled a moment
         * after it exists: a live region that arrives already full is not
         * read.
         */
        announceStep: function ($flyout, place) {
            var name = $.trim($flyout.find('.wp-flyout-title').first().text());

            if (!place || !name) {
                return;
            }

            var $region = $('<div class="screen-reader-text wp-flyout-step-status" role="status" aria-live="polite"></div>').appendTo($flyout);

            setTimeout(function () {
                $region.text(
                    strings.stepped.replace('%1$s', name).replace('%2$s', place.at).replace('%3$s', place.of)
                );
            }, 150);
        },

        // =====================================================================
        // HEADER MENU
        // =====================================================================

        /**
         * Open the header menu
         *
         * @param {jQuery} $menu   The menu's wrapper
         * @param {string} [which] 'last' to land on the last item
         */
        openMenu: function ($menu, which) {
            var $items = $menu.find('.wp-flyout-menu-item');

            $menu.find('.wp-flyout-menu-list').prop('hidden', false);
            $menu.find('.wp-flyout-menu-toggle').attr('aria-expanded', 'true');

            (which === 'last' ? $items.last() : $items.first()).trigger('focus');
        },

        /**
         * Put the header menu away
         *
         * @param {jQuery}  $menu     The menu's wrapper
         * @param {boolean} [refocus] Hand focus back to its button
         */
        closeMenu: function ($menu, refocus) {
            $menu.find('.wp-flyout-menu-list').prop('hidden', true);
            $menu.find('.wp-flyout-menu-toggle').attr('aria-expanded', 'false');

            if (refocus) {
                $menu.find('.wp-flyout-menu-toggle').trigger('focus');
            }
        },

        /**
         * Bind the header menu
         *
         * A menu button, as the authoring practices describe one: the
         * button opens it and says so, the arrows move through it, Home
         * and End go to its ends, Escape puts it away and gives focus back,
         * and Tab leaves it closed behind.
         */
        bindMenu: function ($flyout, config) {
            var self = this;

            $flyout.on('click', '.wp-flyout-menu-toggle', function (e) {
                var $menu = $(this).closest('.wp-flyout-menu');

                e.preventDefault();

                if ($(this).hasClass('is-busy')) {
                    return;
                }

                if ($(this).attr('aria-expanded') === 'true') {
                    self.closeMenu($menu);
                } else {
                    self.openMenu($menu);
                }
            });

            $flyout.on('keydown', '.wp-flyout-menu', function (e) {
                var $menu = $(this);
                var $items = $menu.find('.wp-flyout-menu-item');
                var open = $menu.find('.wp-flyout-menu-toggle').attr('aria-expanded') === 'true';
                var at = $items.index(document.activeElement);

                switch (e.key) {
                    case 'ArrowDown':
                        e.preventDefault();
                        if (!open) {
                            self.openMenu($menu);
                        } else {
                            $items.eq((at + 1) % $items.length).trigger('focus');
                        }
                        break;

                    case 'ArrowUp':
                        e.preventDefault();
                        if (!open) {
                            self.openMenu($menu, 'last');
                        } else {
                            $items.eq(at <= 0 ? $items.length - 1 : at - 1).trigger('focus');
                        }
                        break;

                    case 'Home':
                        if (open) {
                            e.preventDefault();
                            $items.first().trigger('focus');
                        }
                        break;

                    case 'End':
                        if (open) {
                            e.preventDefault();
                            $items.last().trigger('focus');
                        }
                        break;

                    case 'Escape':
                        if (open) {
                            // The menu's, and not the panel's as well.
                            e.preventDefault();
                            e.stopPropagation();
                            self.closeMenu($menu, true);
                        }
                        break;

                    case 'Tab':
                        self.closeMenu($menu);
                        break;
                }
            });

            $flyout.on('click', '.wp-flyout-menu-item', function (e) {
                var $item = $(this);

                // Focus goes back to the button, so it is somewhere when
                // the list it was in has gone.
                self.closeMenu($item.closest('.wp-flyout-menu'), true);

                // Another record's panel, opened where this one is: the
                // item is a trigger, and the handler every trigger has asks
                // about anything typed and then loads it.
                if ($item.hasClass('wp-flyout-trigger')) {
                    return;
                }

                // A link is the browser's to follow -- unless following it
                // in this window would throw away what has been typed.
                if ($item.is('a')) {
                    var href = $item.attr('href');

                    if (!$item.attr('target') && self.isDirty($flyout)) {
                        e.preventDefault();

                        self.confirmDiscard($flyout).then(function (discard) {
                            if (discard) {
                                window.location.href = href;
                            }
                        });
                    }

                    return;
                }

                e.preventDefault();

                var question = $item.attr('data-confirm');

                if (!question) {
                    self.runMenuAction($flyout, config, $item);
                    return;
                }

                self.confirm($flyout, {
                    title: question,
                    accept: $.trim($item.text()) || strings.confirm,
                    danger: $item.hasClass('is-danger')
                }).then(function (yes) {
                    if (yes) {
                        self.runMenuAction($flyout, config, $item);
                    }
                });
            });
        },

        /**
         * Run a menu item's action
         *
         * Through the same route a component's button uses, and answered
         * the same way: a message, and `reload` or `refresh_flyout` when
         * what the action did has to be seen.
         */
        runMenuAction: function ($flyout, config, $item) {
            var self = this;
            var $toggle = $flyout.find('.wp-flyout-menu-toggle');
            var itemId = $flyout.find('input[name="id"]').val() || config.data.id || 0;

            var body = {
                manager: config.manager,
                flyout: config.flyout,
                action_key: $item.attr('data-action'),
                item_id: itemId
            };

            this.setButtonState($toggle, true);

            this.api('/action', body)
                .then(function (response) {
                    self.setButtonState($toggle, false);

                    if (!response.success) {
                        self.showAlert($flyout, response.message || strings.requestFailed, 'error');
                        return;
                    }

                    if (self.askFor($flyout, config, response, body, $toggle)) {
                        return;
                    }

                    self.afterAction($flyout, config, response);

                    $flyout.trigger('wpflyout:menu-action', [response, $item.attr('data-action')]);
                })
                .catch(function (error) {
                    self.setButtonState($toggle, false);
                    self.showAlert($flyout, error.message || strings.requestFailed, 'error');
                });
        },

        /**
         * Run an action that belongs to one row of the panel
         *
         * A button with the class `wp-flyout-row-action` and a
         * `data-action`, wherever a consumer's markup put it -- a cell of a
         * data table, most often. Whatever else the button carries as
         * `data-*` goes with the request, which is how the callback knows
         * which row: `data-activation-id="12"` arrives as `activation_id`.
         *
         * Answered like a menu action, with one thing more. `updates` is a
         * map of selector to markup, each looked for inside this panel, so
         * what the action changed can be redrawn where it is and the panel
         * does not have to be loaded again.
         */
        runRowAction: function ($flyout, config, $button) {
            var self = this;
            var action = $button.attr('data-action');
            var body = {};

            if (!action) {
                return;
            }

            $.each($button[0].attributes, function (i, attribute) {
                if (attribute.name.indexOf('data-') !== 0 || attribute.name === 'data-action' || attribute.name === 'data-confirm') {
                    return;
                }

                body[attribute.name.slice(5).replace(/-/g, '_')] = attribute.value;
            });

            // After the button's own, so none of them can be one of these.
            body.manager = config.manager;
            body.flyout = config.flyout;
            body.action_key = action;
            body.item_id = $flyout.find('input[name="id"]').val() || config.data.id || 0;

            this.setButtonState($button, true);

            this.api('/action', body)
                .then(function (response) {
                    self.setButtonState($button, false);

                    if (!response.success) {
                        self.showAlert($flyout, response.message || strings.requestFailed, 'error');
                        return;
                    }

                    if (self.askFor($flyout, config, response, body, $button)) {
                        return;
                    }

                    self.afterAction($flyout, config, response);

                    $flyout.trigger('wpflyout:row-action', [response, action]);
                })
                .catch(function (error) {
                    self.setButtonState($button, false);
                    self.showAlert($flyout, error.message || strings.requestFailed, 'error');
                });
        },

        /**
         * Open the form an action asked for, if it asked for one
         *
         * An action that needs something written before it can run --
         * a subject and a message, a reason -- answers its first request
         * with a `form` and does nothing. The form is shown over the panel,
         * and what is typed into it goes back to the same action as
         * `fields`. So the server decides what to ask and with what
         * already filled in, and a request made without the form is as
         * harmless as one made with an empty one.
         *
         * @param {jQuery} $flyout
         * @param {Object} config
         * @param {Object} response What the action answered
         * @param {Object} body     What it was sent, to send again
         * @param {jQuery} $opener  What to hand focus back to
         * @return {boolean} Whether there was a form to open
         */
        askFor: function ($flyout, config, response, body, $opener) {
            var self = this;

            if (!response.form || !window.WPFlyoutPrompt) {
                return false;
            }

            window.WPFlyoutPrompt.open($flyout, response.form, {
                opener: $opener && $opener[0],
                strings: strings,
                submit: function (fields) {
                    return self.api('/action', $.extend({}, body, { fields: fields }));
                },
                done: function (answer) {
                    self.afterAction($flyout, config, answer);
                    $flyout.trigger('wpflyout:prompt-action', [answer, body.action_key]);
                }
            });

            return true;
        },

        /**
         * Show what an action did
         *
         * A message, briefly. `updates` is a map of selector to markup,
         * each looked for inside this panel, so what changed is redrawn
         * where it is; `refresh_flyout` loads the whole panel again, and
         * `reload` the page behind it.
         */
        afterAction: function ($flyout, config, response) {
            var self = this;

            this.toast($flyout, response.message || strings.done);

            $.each(response.updates || {}, function (selector, html) {
                $flyout.find(selector).html(html);
            });

            if (response.reload === true) {
                // The record is not what it was, so there is nothing here
                // left to lose.
                this.markClean($flyout);

                setTimeout(function () {
                    location.reload();
                }, CLOSE_AFTER);
            } else if (response.refresh_flyout === true) {
                // Drawn again from the server -- unless there is something
                // typed into its form, which a fresh copy would throw
                // away. Then it is asked about, as it is for every other
                // way of replacing what is in the panel; kept, the panel
                // stays as it is until it is next opened.
                setTimeout(function () {
                    self.request(config, { force: !self.isDirty($flyout) });
                }, CLOSE_AFTER);
            }
        },

        // =====================================================================
        // UNSAVED CHANGES
        // =====================================================================

        /**
         * What the form would submit, as one string
         */
        snapshot: function ($flyout) {
            var $form = $flyout.find('form').first();

            return $form.length ? JSON.stringify(this.collectFormData($form)) : '';
        },

        /**
         * Start watching a panel's form for changes
         *
         * Dirty means the form would now submit something other than what
         * it would have submitted when it opened. That is asked of the form
         * itself rather than counted up from events, for three reasons: a
         * repeater's row is added and removed by a click and a media field
         * is filled by a script, neither of which is an `input` event; a
         * search box typed into changes nothing that would be saved; and
         * somebody who types a letter and deletes it has not changed
         * anything, and should not be asked whether to discard it.
         *
         * The events and the observer only say when to look again.
         */
        watch: function ($flyout) {
            var self = this;
            var form = $flyout.find('form')[0];
            var timer = null;

            if (!form) {
                return;
            }

            var look = function () {
                clearTimeout(timer);
                timer = setTimeout(function () {
                    self.refresh($flyout);
                }, 60);
            };

            this.markClean($flyout);

            $flyout.on('input change field-kit:change', look);

            if (window.MutationObserver) {
                var observer = new MutationObserver(look);

                observer.observe(form, { childList: true, subtree: true });
                $flyout.data('wpFlyoutObserver', observer);
            }

            // A field that finishes setting itself up a moment after it is
            // drawn has not been changed by anybody. Until a key or a
            // pointer has been put to the panel, whatever the form holds
            // once it is ready is how it started.
            $flyout.one('keydown pointerdown', function () {
                $flyout.data('wpFlyoutTouched', true);
            });

            $flyout.one('flyout:ready', function () {
                if (!$flyout.data('wpFlyoutTouched')) {
                    self.markClean($flyout);
                }
            });
        },

        /**
         * Whether the panel holds changes that have not been saved
         */
        isDirty: function ($flyout) {
            var clean = $flyout.data('wpFlyoutClean');

            if ($flyout.data('wpFlyoutDirty') === true) {
                return true;
            }

            return typeof clean === 'string' && clean !== this.snapshot($flyout);
        },

        /**
         * Say a panel has changed, for a change the form cannot show
         *
         * For a component that keeps something outside the form's inputs.
         * It stays said until the panel is saved.
         */
        markDirty: function ($flyout) {
            $flyout.data('wpFlyoutDirty', true);
            this.refresh($flyout);
        },

        /**
         * Take the form as it is now to be the saved one
         */
        markClean: function ($flyout) {
            $flyout.data('wpFlyoutDirty', false);
            $flyout.data('wpFlyoutClean', this.snapshot($flyout));
            this.refresh($flyout);
        },

        /**
         * Bring the panel into line with whether it has changes
         *
         * Save is for saving something. On a record that already exists it
         * is only live once there is a change to save; on a new one it is
         * live from the start, because an empty form is a record nobody has
         * created yet and creating it is the point.
         *
         * A button marked data-always-enabled is left alone, for a panel
         * whose Save does something other than store the form.
         */
        refresh: function ($flyout) {
            var dirty = this.isDirty($flyout);
            var was = $flyout.hasClass('is-dirty');
            var $save = $flyout.find('.wp-flyout-save').not('[data-always-enabled]').not('.is-busy');

            $flyout.toggleClass('is-dirty', dirty);
            $save.prop('disabled', !dirty && !this.isNew($flyout));

            if (dirty !== was) {
                $flyout.trigger('wpflyout:dirty', [dirty]);
            }
        },

        /**
         * Whether the panel is for a record that does not exist yet
         */
        isNew: function ($flyout) {
            var config = $flyout.data() || {};
            var id = $flyout.find('input[name="id"]').val() || (config.data && config.data.id) || '';

            return String(id) === '' || String(id) === '0';
        },

        /**
         * Ask whether to throw away what has been changed
         *
         * @return {Promise<boolean>} True to discard
         */
        confirmDiscard: function ($flyout) {
            return this.confirm($flyout, {
                title: strings.unsavedTitle,
                text: strings.unsavedText,
                cancel: strings.keepEditing,
                accept: strings.discard,
                danger: true
            });
        },

        /**
         * Ask a yes-or-no question in the panel
         *
         * A bar above the footer, where the buttons already are, rather
         * than the browser's confirm(): that one stops the page, cannot be
         * styled or translated by anything here, and is offered a "do not
         * show again" by some browsers that then answers no to every
         * question for good.
         *
         * Focus goes to the answer that loses nothing, Escape gives that
         * answer, and focus goes back where it was afterwards.
         *
         * @param {jQuery}  $flyout
         * @param {Object}  options
         * @param {string}  options.title    The question
         * @param {string}  [options.text]   What it means, if it needs saying
         * @param {string}  [options.accept] Label for yes
         * @param {string}  [options.cancel] Label for no
         * @param {boolean} [options.danger] Yes destroys something
         * @return {Promise<boolean>}
         */
        confirm: function ($flyout, options) {
            var pending = $flyout.data('wpFlyoutConfirm');
            var base = $flyout.attr('id') + '-confirm';

            // One question at a time. The one it replaces is answered no.
            if (typeof pending === 'function') {
                pending(false);
            }

            return new Promise(function (resolve) {
                var back = document.activeElement;
                var $bar = $('<div class="wp-flyout-confirm" role="alertdialog"></div>');
                var $text = $('<div class="wp-flyout-confirm-text"></div>').appendTo($bar);
                var $buttons = $('<div class="wp-flyout-confirm-actions"></div>').appendTo($bar);

                $('<strong class="wp-flyout-confirm-title"></strong>')
                    .attr('id', base + '-title')
                    .text(options.title)
                    .appendTo($text);

                $bar.attr('aria-labelledby', base + '-title');

                if (options.text) {
                    $('<span class="wp-flyout-confirm-description"></span>')
                        .attr('id', base + '-text')
                        .text(options.text)
                        .appendTo($text);

                    $bar.attr('aria-describedby', base + '-text');
                }

                var $cancel = $('<button type="button" class="button wp-flyout-confirm-cancel"></button>')
                    .text(options.cancel || strings.cancel)
                    .appendTo($buttons);

                // Destructive is the fields package's treatment, which is core's: a
                // red outline, and not a red fill core does not have.
                var $accept = $('<button type="button" class="button wp-flyout-confirm-accept"></button>')
                    .addClass(options.danger ? 'field-kit__button--delete' : 'button-primary')
                    .text(options.accept || strings.confirm)
                    .appendTo($buttons);

                var settle = function (answer) {
                    $flyout.removeData('wpFlyoutConfirm').removeClass('has-confirm');
                    $bar.remove();

                    // Whatever the answer, to where it was asked from: the
                    // bar that had focus is gone, and focus left on nothing
                    // starts again from the top of the page.
                    if (back && $flyout[0].contains(back)) {
                        back.focus();
                    }

                    resolve(answer);
                };

                $cancel.on('click', function () {
                    settle(false);
                });

                $accept.on('click', function () {
                    settle(true);
                });

                $bar.on('keydown', function (e) {
                    if (e.key === 'Escape') {
                        // Answers the question, and stops there: the same
                        // key must not also be a second request to close.
                        e.preventDefault();
                        e.stopPropagation();
                        settle(false);
                    }
                });

                $flyout.data('wpFlyoutConfirm', settle).addClass('has-confirm');

                var $footer = $flyout.children('.wp-flyout-footer');

                if ($footer.length) {
                    $bar.insertBefore($footer);
                } else {
                    $bar.appendTo($flyout);
                }

                $cancel.trigger('focus');
            });
        },

        // =====================================================================
        // SAVING
        // =====================================================================

        /**
         * Bind event handlers
         */
        bindHandlers: function ($flyout, flyoutId, config) {
            var self = this;

            // Save button
            $flyout.on('click', '.wp-flyout-save', function (e) {
                e.preventDefault();
                self.handleSave($flyout, flyoutId, config);
            });

            // Delete button
            $flyout.on('click', '.wp-flyout-delete', function (e) {
                e.preventDefault();

                if ($(this).hasClass('is-busy')) {
                    return;
                }

                self.confirm($flyout, {
                    title: strings.deleteTitle,
                    accept: strings.deleteConfirm,
                    danger: true
                }).then(function (yes) {
                    if (yes) {
                        self.handleDelete($flyout, flyoutId, config);
                    }
                });
            });

            // Cancel is the close button with a word on it, and asks the
            // same question the close button does. The close button itself
            // is bound once for every panel, in wp-flyout.js.
            $flyout.on('click', '.wp-flyout-cancel', function (e) {
                e.preventDefault();
                WPFlyout.close(flyoutId);
            });

            // Previous and next
            $flyout.on('click', '.wp-flyout-prev, .wp-flyout-next', function (e) {
                e.preventDefault();
                self.step(config, $(this).hasClass('wp-flyout-next') ? 'next' : 'prev');
            });

            this.bindMenu($flyout, config);

            // The copy button beside a title
            $flyout.on('click', '.wp-flyout-copy', function (e) {
                e.preventDefault();

                var $button = $(this);

                // What the button itself carries, when the server gave it
                // something to copy; the title beside it otherwise.
                var text = $button.attr('data-copy') ||
                    $.trim($flyout.find('.wp-flyout-header .wp-flyout-title').first().text());

                self.copy(text).then(function (ok) {
                    if (!ok) {
                        self.showAlert($flyout, strings.copyFailed, 'error');
                        return;
                    }

                    self.toast($flyout, strings.copied);

                    // A tick where the pages were, for as long as the
                    // toast says so.
                    $button.html(icon('copied', 'yes'));

                    clearTimeout($button.data('wpFlyoutTimer'));

                    $button.data('wpFlyoutTimer', setTimeout(function () {
                        $button.html(icon('copy', 'admin-page'));
                    }, TOAST_FOR));
                });
            });

            // An action on one row of what the panel shows
            $flyout.on('click', '.wp-flyout-row-action', function (e) {
                e.preventDefault();

                var $button = $(this);

                if ($button.hasClass('is-busy')) {
                    return;
                }

                var question = $button.attr('data-confirm');

                if (!question) {
                    self.runRowAction($flyout, config, $button);
                    return;
                }

                self.confirm($flyout, {
                    title: question,
                    accept: $.trim($button.text()) || strings.confirm,
                    danger: $button.hasClass('is-danger')
                }).then(function (yes) {
                    if (yes) {
                        self.runRowAction($flyout, config, $button);
                    }
                });
            });

            // Clear error class on change
            $flyout.on('input change', '.error', function () {
                $(this).removeClass('error');
            });

            // And what a save said was wrong with a field, once the field
            // is being put right.
            $flyout.on('input change', '.wp-flyout-has-error', function () {
                self.clearFieldError($(this));
            });
        },

        /**
         * Validate form
         */
        validateForm: function ($form) {
            var isValid = true;
            var firstInvalid = null;

            $form.find('[required]:visible:enabled').each(function () {
                var $field = $(this);
                var value = $field.val();

                if (!value || (Array.isArray(value) && !value.length)) {
                    isValid = false;
                    $field.addClass('error');
                    firstInvalid = firstInvalid || $field;
                } else {
                    $field.removeClass('error');
                }
            });

            return { isValid: isValid, firstInvalid: firstInvalid };
        },

        /**
         * Collect form data as a plain object for JSON submission
         *
         * Handles nested field names like files[0][name], metadata[1][key], etc.
         */
        collectFormData: function ($form) {
            var data = {};
            var serialized = $form.serializeArray();

            // A switch is a hidden "0" and then a checkbox of the same name,
            // so that off is sent as well as on. A browser posting the form
            // sends both and the server keeps the last; collected here they
            // became ["0", "1"], which no toggle reads as on -- so saving a
            // panel turned off every switch that was on. Where a checked
            // box follows a hidden input of its name, the box is the value.
            var switched = {};
            $form.find('input[type="checkbox"]:checked').each(function () {
                var name = $(this).attr('name');
                if (name && name.indexOf('[') === -1 && $form.find('input[type="hidden"]').filter(function () {
                    return $(this).attr('name') === name;
                }).length) {
                    switched[name] = true;
                }
            });

            serialized.forEach(function (item) {
                var name = item.name;

                // Handle array fields: name[] or name[key]
                if (name.indexOf('[') !== -1) {
                    var keys = name.replace(/\]/g, '').split('[');
                    var current = data;

                    for (var i = 0; i < keys.length - 1; i++) {
                        var key = keys[i];
                        if (!current[key]) {
                            var nextKey = keys[i + 1];
                            // Empty next key (name[]) means array, numeric means array, otherwise object
                            current[key] = (nextKey === '' || /^\d+$/.test(nextKey)) ? [] : {};
                        }
                        current = current[key];
                    }

                    var lastKey = keys[keys.length - 1];

                    // Empty last key means append to array (name[])
                    if (lastKey === '') {
                        if (Array.isArray(current)) {
                            current.push(item.value);
                        }
                    } else {
                        current[lastKey] = item.value;
                    }
                } else {
                    // Simple field — handle duplicate names as arrays,
                    // but for a switch, where the later one is the answer.
                    if (switched[name]) {
                        data[name] = item.value;
                    } else if (data[name] !== undefined) {
                        if (!Array.isArray(data[name])) {
                            data[name] = [data[name]];
                        }
                        data[name].push(item.value);
                    } else {
                        data[name] = item.value;
                    }
                }
            });

            // Also collect unchecked checkboxes (they don't appear in serializeArray)
            $form.find('input[type="checkbox"]:not(:checked)').each(function () {
                var name = $(this).attr('name');
                if (name && data[name] === undefined) {
                    data[name] = '0';
                }
            });

            return data;
        },

        /**
         * Handle save action
         */
        handleSave: function ($flyout, flyoutId, config) {
            var self = this;
            var $form = $flyout.find('form').first();
            var $saveBtn = $flyout.find('.wp-flyout-save');
            var $body = $flyout.find('.wp-flyout-body');

            // Already on its way.
            if ($saveBtn.hasClass('is-busy')) {
                return;
            }

            this.clearFieldErrors($flyout);

            // Validate
            var validation = this.validateForm($form);
            if (!validation.isValid) {
                $body.animate({ scrollTop: 0 }, 300);
                this.showAlert($flyout, strings.required, 'error');
                if (validation.firstInvalid) {
                    validation.firstInvalid.focus();
                }
                return;
            }

            // Collect form data as object
            var formData = this.collectFormData($form);
            var sent = JSON.stringify(formData);

            // Save
            $flyout.find('.wp-flyout-alert').remove();
            this.setButtonState($saveBtn, true);

            this.api('/save', {
                manager: config.manager,
                flyout: config.flyout,
                item_id: config.data.id || formData.id || 0,
                form_data: formData
            })
                .then(function (response) {
                    self.setButtonState($saveBtn, false);

                    if (response.success) {
                        // What was sent is what is saved now. Anything
                        // typed while it was on its way is still a change.
                        $flyout.data('wpFlyoutDirty', false).data('wpFlyoutClean', sent);
                        self.refresh($flyout);

                        self.toast($flyout, strings.saved);

                        // Always close and reload after delay
                        setTimeout(function () {
                            WPFlyout.close(flyoutId, { force: true });
                            location.reload();
                        }, CLOSE_AFTER);
                    } else {
                        self.refresh($flyout);
                        self.showFailure($flyout, response.message, response.data);
                    }
                })
                .catch(function (error) {
                    self.setButtonState($saveBtn, false);
                    self.refresh($flyout);
                    self.showFailure($flyout, error.message, error.data);
                });
        },

        /**
         * Handle delete action
         */
        handleDelete: function ($flyout, flyoutId, config) {
            var self = this;
            var $deleteBtn = $flyout.find('.wp-flyout-delete');
            var deleteId = $flyout.find('input[name="id"]').val() || config.data.id;
            var $body = $flyout.find('.wp-flyout-body');

            this.setButtonState($deleteBtn, true);

            this.api('/delete', {
                manager: config.manager,
                flyout: config.flyout,
                item_id: deleteId
            })
                .then(function (response) {
                    if (response.success) {
                        self.toast($flyout, response.message || strings.deleted);

                        setTimeout(function () {
                            // Whatever was typed was about a record that
                            // is gone, so nobody is asked about it.
                            WPFlyout.close(flyoutId, { force: true });
                            location.reload();
                        }, CLOSE_AFTER);
                    } else {
                        self.setButtonState($deleteBtn, false);
                        self.showAlert($flyout, response.message || strings.requestFailed, 'error');
                        $body.animate({ scrollTop: 0 }, 300);
                    }
                })
                .catch(function (error) {
                    self.setButtonState($deleteBtn, false);
                    $body.animate({ scrollTop: 0 }, 300);
                    self.showAlert($flyout, error.message || strings.requestFailed, 'error');
                });
        },

        // =====================================================================
        // FEEDBACK
        // =====================================================================

        /**
         * Say what went wrong with a save, where it went wrong
         *
         * An error that names a field is shown under that field, with the
         * field brought into view and given focus. A save callback names
         * one by returning a WP_Error whose data has `field`, the field's
         * key, or `fields`, a map of key to message.
         *
         * Anything else -- no field named, or a field that is not in this
         * panel -- is one message at the top.
         *
         * @param {jQuery} $flyout
         * @param {string} message The error's own message
         * @param {Object} [data]  The error's data
         */
        showFailure: function ($flyout, message, data) {
            var self = this;
            var fields = {};
            var unplaced = [];
            var $first = null;

            data = data || {};
            message = message || strings.requestFailed;

            if (data.fields && typeof data.fields === 'object') {
                fields = $.extend({}, data.fields);
            }

            if (typeof data.field === 'string' && data.field !== '' && fields[data.field] === undefined) {
                fields[data.field] = message;
            }

            $.each(fields, function (key, text) {
                var $field = self.findField($flyout, key);

                text = String(text || message);

                if (!$field.length) {
                    unplaced.push(text);
                    return;
                }

                var $target = self.showFieldError($flyout, $field, text);

                $first = $first || $target;
            });

            if ($first) {
                $first.trigger('focus');

                if ($first[0] && $first[0].scrollIntoView) {
                    $first[0].scrollIntoView({ block: 'center' });
                }
            }

            if (unplaced.length || !$first) {
                this.showAlert($flyout, unplaced.length ? unplaced.join(' ') : message, 'error');

                if (!$first) {
                    $flyout.find('.wp-flyout-body').animate({ scrollTop: 0 }, 300);
                }
            }
        },

        /**
         * The wrapper of the field a key names, if it is in this panel
         *
         * By the name its control submits under: the key itself, the key
         * as a list, or the key as the start of something nested.
         */
        findField: function ($flyout, key) {
            key = String(key);

            var $control = $flyout.find('form [name]').filter(function () {
                var name = this.getAttribute('name') || '';

                return name === key || name === key + '[]' || name.indexOf(key + '[') === 0;
            }).first();

            if (!$control.length) {
                return $();
            }

            var $field = $control.closest('.wp-flyout-field');

            return $field.length ? $field : $control.parent();
        },

        /**
         * Show one message under one field
         *
         * @return {jQuery} What to focus: the field's control, or the field
         */
        showFieldError: function ($flyout, $field, text) {
            var id = $flyout.attr('id') + '-error-' + $flyout.find('.wp-flyout-field-error').length;

            this.clearFieldError($field);
            this.reveal($flyout, $field);

            var $control = $field
                .find('input:not([type="hidden"]), select, textarea, button')
                .filter(':visible:enabled')
                .first();

            // Inside the fields package's own wrapper where there is one, so it sits
            // directly under the control and its note, not under the gap
            // that separates this field from the next.
            var $inner = $field.children('.field-kit__field').first();

            $('<p class="wp-flyout-field-error" role="alert"></p>')
                .attr('id', id)
                .text(text)
                .appendTo($inner.length ? $inner : $field);

            $field.addClass('wp-flyout-has-error has-error');

            if (!$control.length) {
                return $field.attr('tabindex', '-1');
            }

            $control.attr('aria-invalid', 'true').attr('data-flyout-error', id);

            // Added to whatever already describes the control, so the note
            // under a field is still read out with the complaint about it.
            $control.attr('aria-describedby', $.trim(($control.attr('aria-describedby') || '') + ' ' + id));

            return $control;
        },

        /**
         * Bring a field out from wherever it is put away
         *
         * Its tab, if it is on one that is not showing, and any folded
         * section it is inside.
         */
        reveal: function ($flyout, $field) {
            var $page = $field.closest('.wp-flyout-tab-content');

            if ($page.length && !$page.hasClass('active')) {
                $flyout.find('.wp-flyout-tab').filter(function () {
                    return 'tab-' + $(this).data('tab') === $page.attr('id');
                }).trigger('click');
            }

            $field.parents('details').prop('open', true);
        },

        /**
         * Take a field's error away
         */
        clearFieldError: function ($field) {
            $field.find('[data-flyout-error]').each(function () {
                var $control = $(this);
                var id = $control.attr('data-flyout-error');
                var rest = $.trim((' ' + ($control.attr('aria-describedby') || '') + ' ').replace(' ' + id + ' ', ' '));

                $control.removeAttr('aria-invalid data-flyout-error');

                if (rest) {
                    $control.attr('aria-describedby', rest);
                } else {
                    $control.removeAttr('aria-describedby');
                }
            });

            $field.removeClass('wp-flyout-has-error has-error');
            $field.find('.wp-flyout-field-error').remove();
        },

        /**
         * Take every field's error away
         */
        clearFieldErrors: function ($flyout) {
            var self = this;

            $flyout.find('.wp-flyout-has-error').each(function () {
                self.clearFieldError($(this));
            });
        },

        /**
         * Make sure the panel has somewhere to say things quietly
         *
         * A status region has to be in the document before anything is put
         * in it, or a screen reader that was not listening when it arrived
         * says nothing. So it is there from the start, empty.
         */
        toastRegion: function ($flyout) {
            var $toast = $flyout.children('.wp-flyout-toast');

            if (!$toast.length) {
                $toast = $('<div class="wp-flyout-toast" role="status" aria-live="polite"></div>').appendTo($flyout);
            }

            return $toast;
        },

        /**
         * Say something briefly, and take it away again
         */
        toast: function ($flyout, message) {
            var $toast = this.toastRegion($flyout);

            clearTimeout($toast.data('wpFlyoutTimer'));

            $toast.empty()
                .append(icon('copied', 'yes'))
                .append($('<span></span>').text(message == null ? '' : String(message)))
                .addClass('is-visible');

            $toast.data('wpFlyoutTimer', setTimeout(function () {
                $toast.removeClass('is-visible').empty();
            }, TOAST_FOR));
        },

        /**
         * Show alert message
         */
        showAlert: function ($flyout, message, type) {
            if (window.WPFlyoutAlert) {
                $flyout.find('.wp-flyout-alert').remove();

                WPFlyoutAlert.show(message, type, {
                    target: $flyout.find('.wp-flyout-body'),
                    prepend: true,
                    timeout: type === 'success' ? 3000 : 0,
                    dismissible: true
                });
            }
        },

        /**
         * Set button loading state
         *
         * Busy is drawn by the stylesheet, as a spinner over a label that
         * keeps its place: a button that changes its words changes its
         * width, and the buttons beside it move under the pointer.
         *
         * And it is disabled with aria-disabled rather than the attribute.
         * A button that is given `disabled` while it has focus drops the
         * focus on the floor -- the page's <body> has it, and the next Tab
         * starts somewhere else -- and core paints a disabled primary
         * button grey, which reads as the press having been refused. This
         * way it keeps its place, its colour and the keyboard, says it is
         * unavailable to anything that asks, and ignores being pressed.
         *
         * `text` is still accepted and still replaces the label, for
         * anything outside this file that calls it that way.
         */
        setButtonState: function ($btn, disabled, text) {
            if (!$btn.length) return;

            if (disabled) {
                $btn.addClass('is-busy').attr({ 'aria-busy': 'true', 'aria-disabled': 'true' });

                if (text) {
                    $btn.data('original-text', $btn.text()).text(text);
                }
            } else {
                $btn.removeClass('is-busy').removeAttr('aria-busy aria-disabled');

                if ($btn.data('original-text')) {
                    $btn.text($btn.data('original-text')).removeData('original-text');
                }
            }
        }
    };

    // Initialize
    $(document).ready(function () {
        WPFlyoutManager.init();
    });

    // Published for components, and for anything that needs to say a panel
    // has changed in a way its form does not show.
    window.WPFlyoutManager = WPFlyoutManager;

})(jQuery);
