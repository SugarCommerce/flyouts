/**
 * WP Flyout Activity Component
 *
 * The note box over an activity feed. Everything else in the feed --
 * "Show Earlier Activity", a note's Delete -- is one of the panel's row
 * actions and is run by the manager; this sends what was written, and puts
 * the feed the server draws back in place of the old one.
 *
 * Delegated from the document: a panel arrives over REST long after this
 * has run, and is replaced by the next one.
 *
 * @package WPFlyout
 */
(function ($) {
    'use strict';

    // This build's REST config; see the resolver in wp-flyout.js.
    var wpFlyout = window.SugarCommerceFlyouts.resolve(document.currentScript);

    var Activity = {

        init: function () {
            var self = this;

            $(document)
                .on('click', '.wp-flyout-activity-add', function (e) {
                    e.preventDefault();
                    self.add($(this));
                })
                .on('keydown', '.wp-flyout-activity-input', function (e) {
                    // Command or Ctrl with Enter, as every comment box takes
                    // it; Shift with Enter is kept for whoever learned that
                    // first. Enter alone is still a new line in the note.
                    if (e.key === 'Enter' && (e.metaKey || e.ctrlKey || e.shiftKey)) {
                        e.preventDefault();
                        self.add($(this).closest('.wp-flyout-activity').find('.wp-flyout-activity-add'));
                    }
                });
        },

        /**
         * The panel's manager, for its toast and its alert, when there is
         * one on the page.
         */
        manager: function () {
            return window.WPFlyoutManager || null;
        },

        /**
         * Send the note, and draw the feed the server answers with.
         */
        add: function ($button) {
            var self = this;
            var $activity = $button.closest('.wp-flyout-activity');
            var $flyout = $activity.closest('.wp-flyout');
            var $input = $activity.find('.wp-flyout-activity-input');
            var content = $.trim($input.val() || '');
            var config = $flyout.data() || {};
            var manager = this.manager();

            if (!content || $button.hasClass('is-busy')) {
                $input.trigger('focus');
                return;
            }

            if (manager) {
                manager.setButtonState($button, true);
            } else {
                $button.addClass('is-busy');
            }

            fetch(wpFlyout.restUrl + '/action', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': wpFlyout.restNonce
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    manager: config.manager,
                    flyout: config.flyout,
                    action_key: $button.attr('data-action'),
                    item_id: $flyout.find('input[name="id"]').val() || (config.data && config.data.id) || 0,
                    content: content
                })
            })
                .then(function (response) {
                    return response.json().then(function (json) {
                        if (!response.ok || !json.success) {
                            throw new Error(json.message || '');
                        }

                        return json;
                    });
                })
                .then(function (response) {
                    $.each(response.updates || {}, function (selector, html) {
                        $flyout.find(selector).html(html);
                    });

                    $input.val('').trigger('focus');

                    if (manager) {
                        manager.toast($flyout, response.message || '');
                    }

                    // For a panel that shows the same history somewhere
                    // else as well, and has to redraw it.
                    $activity.trigger('wpflyout:note-added', [response]);
                })
                .catch(function (error) {
                    var message = error.message ||
                        (wpFlyout.strings && wpFlyout.strings.requestFailed) ||
                        'Something went wrong. Please try again.';

                    if (manager) {
                        manager.showAlert($flyout, message, 'error');
                    } else {
                        window.alert(message);
                    }
                })
                .finally(function () {
                    if (manager) {
                        manager.setButtonState($button, false);
                    } else {
                        $button.removeClass('is-busy');
                    }
                });
        }
    };

    $(function () {
        Activity.init();
    });

    window.WPFlyoutActivity = Activity;

})(jQuery);
