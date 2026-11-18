/**
 * WP Flyout Prompt
 *
 * The form an action asks for before it runs: a small dialog over the
 * panel with a few fields, a button that sends them and a way out.
 *
 * An action answers with `form` when it needs something written first:
 *
 *     {
 *         title: 'Send an email',
 *         description: '',
 *         submit: 'Send',
 *         fields: [
 *             { name: 'to', label: 'To', type: 'static', value: 'ada@example.com' },
 *             { name: 'subject', label: 'Subject', type: 'text', required: true, maxlength: 200 },
 *             { name: 'message', label: 'Message', type: 'textarea', required: true, rows: 8 }
 *         ]
 *     }
 *
 * `static` is shown and not sent: the server already knows it, and a value
 * that came back from the browser is not one it should trust. Everything
 * else goes back to the same action as `fields`.
 *
 * It is a dialog in its own right. Focus goes to the first field and stays
 * inside; Escape and Cancel close it, asking first when something has been
 * typed; Command or Ctrl with Enter sends; and focus goes back to whatever
 * opened it.
 *
 * @package     SugarCommerce\Flyouts
 * @version     1.0.0
 */
(function ($) {
    'use strict';

    var count = 0;

    var focusable = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), ' +
        'select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

    var WPFlyoutPrompt = {

        /**
         * Open a form over a panel
         *
         * @param {jQuery}   $flyout          The panel
         * @param {Object}   form             What the action asked for
         * @param {Object}   options
         * @param {Function} options.submit   Given the fields; returns a promise of the answer
         * @param {Function} [options.done]   Given the answer, once it is a success
         * @param {Element}  [options.opener] What to hand focus back to
         * @param {Object}   [options.strings]
         * @return {jQuery} The dialog
         */
        open: function ($flyout, form, options) {
            var self = this;
            var id = 'wp-flyout-prompt-' + (++count);
            var strings = options.strings || {};
            var back = options.opener || document.activeElement;
            var busy = false;

            this.close($flyout);

            var $layer = $('<div class="wp-flyout-prompt-layer"></div>');
            var $dialog = $('<div class="wp-flyout-prompt" role="dialog" aria-modal="true"></div>')
                .attr({ id: id, 'aria-labelledby': id + '-title' })
                .appendTo($layer);
            var $form = $('<form class="wp-flyout-prompt-form" novalidate></form>').appendTo($dialog);

            $('<h3 class="wp-flyout-prompt-title"></h3>')
                .attr('id', id + '-title')
                .text(form.title || '')
                .appendTo($form);

            if (form.description) {
                $('<p class="wp-flyout-prompt-description"></p>')
                    .attr('id', id + '-about')
                    .text(form.description)
                    .appendTo($form);

                $dialog.attr('aria-describedby', id + '-about');
            }

            var $error = $('<div class="wp-flyout-prompt-error" role="alert" hidden></div>').appendTo($form);
            var $fields = $('<div class="wp-flyout-prompt-fields"></div>').appendTo($form);

            (form.fields || []).forEach(function (field, index) {
                $fields.append(self.field(field, id + '-field-' + index));
            });

            var $ask = $('<div class="wp-flyout-prompt-ask" hidden></div>').appendTo($form);
            var $actions = $('<div class="wp-flyout-prompt-actions"></div>').appendTo($form);

            var $cancel = $('<button type="button" class="button button-link wp-flyout-prompt-cancel"></button>')
                .text(form.cancel || strings.cancel || 'Cancel')
                .appendTo($actions);

            var $submit = $('<button type="submit" class="button button-primary wp-flyout-prompt-submit"></button>')
                .text(form.submit || strings.send || 'Send')
                .appendTo($actions);

            var typed = function () {
                return $fields.find('input, textarea, select').filter(function () {
                    return $.trim($(this).val() || '') !== '' && $(this).val() !== $(this).attr('data-initial');
                }).length > 0;
            };

            var shut = function () {
                $(document).off('.' + id);
                $layer.remove();
                $flyout.removeClass('has-prompt');

                if (back && document.contains(back) && typeof back.focus === 'function') {
                    back.focus();
                }
            };

            // Asked in the dialog itself, where the buttons already are.
            var leave = function () {
                if (busy) {
                    return;
                }

                if (!typed()) {
                    shut();
                    return;
                }

                $ask.empty().prop('hidden', false);
                $actions.prop('hidden', true);

                $('<strong class="wp-flyout-prompt-ask-text"></strong>')
                    .text(form.discard || strings.promptDiscard || 'Discard what you have written?')
                    .appendTo($ask);

                var $keep = $('<button type="button" class="button"></button>')
                    .text(strings.keepWriting || 'Keep Writing')
                    .appendTo($ask);

                $('<button type="button" class="button field-kit__button--delete"></button>')
                    .text(strings.promptDrop || 'Discard')
                    .on('click', shut)
                    .appendTo($ask);

                $keep.on('click', function () {
                    $ask.prop('hidden', true).empty();
                    $actions.prop('hidden', false);
                    $fields.find('input, textarea, select').filter(':visible').first().trigger('focus');
                }).trigger('focus');
            };

            var fail = function (message, data) {
                var names = (data && data.fields) || {};
                var $first = null;

                if (data && typeof data.field === 'string' && data.field && names[data.field] === undefined) {
                    names[data.field] = message;
                }

                $fields.find('.wp-flyout-prompt-field').each(function () {
                    var $control = $(this).find('input, textarea, select').first();
                    var text = names[$control.attr('name')];

                    $(this).find('.wp-flyout-prompt-field-error').remove();
                    $control.removeAttr('aria-invalid');

                    if (text === undefined) {
                        return;
                    }

                    $control.attr('aria-invalid', 'true');
                    $('<p class="wp-flyout-prompt-field-error" role="alert"></p>').text(text).appendTo(this);
                    $first = $first || $control;
                });

                if ($first) {
                    $error.prop('hidden', true).empty();
                    $first.trigger('focus');
                } else {
                    $error.text(message || strings.requestFailed || 'Something went wrong. Please try again.').prop('hidden', false);
                }
            };

            var send = function () {
                var fields = {};
                var missing = {};

                if (busy || !$ask.prop('hidden')) {
                    return;
                }

                $fields.find('input, textarea, select').each(function () {
                    var value = $(this).val();

                    fields[this.name] = value;

                    if ($(this).prop('required') && $.trim(value || '') === '') {
                        missing[this.name] = strings.promptEmpty || 'This needs filling in.';
                    }
                });

                if (!$.isEmptyObject(missing)) {
                    fail('', { fields: missing });
                    return;
                }

                busy = true;
                $error.prop('hidden', true).empty();
                $submit.addClass('is-busy').attr({ 'aria-busy': 'true', 'aria-disabled': 'true' });
                $dialog.attr('aria-busy', 'true');

                var settle = function () {
                    busy = false;
                    $submit.removeClass('is-busy').removeAttr('aria-busy aria-disabled');
                    $dialog.removeAttr('aria-busy');
                };

                options.submit(fields).then(function (answer) {
                    settle();

                    if (!answer || !answer.success) {
                        fail(answer && answer.message, answer && answer.data);
                        return;
                    }

                    shut();

                    if (typeof options.done === 'function') {
                        options.done(answer);
                    }
                }, function (error) {
                    settle();
                    fail(error && error.message, error && error.data);
                });
            };

            $form.on('submit', function (e) {
                e.preventDefault();
                send();
            });

            $cancel.on('click', leave);

            // What was wrong with a field stops being said once the field
            // is being put right.
            $fields.on('input', 'input, textarea, select', function () {
                $(this).removeAttr('aria-invalid')
                    .closest('.wp-flyout-prompt-field')
                    .find('.wp-flyout-prompt-field-error')
                    .remove();
            });

            // A press on the dimmed panel around the dialog is a way out too.
            $layer.on('mousedown', function (e) {
                if (e.target === $layer[0]) {
                    leave();
                }
            });

            $layer.on('keydown', function (e) {
                if (e.key === 'Escape') {
                    // The dialog's, and not the panel's under it as well.
                    e.preventDefault();
                    e.stopPropagation();

                    if (!$ask.prop('hidden')) {
                        $ask.find('button').first().trigger('click');
                    } else {
                        leave();
                    }

                    return;
                }

                if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) {
                    e.preventDefault();
                    e.stopPropagation();
                    send();
                    return;
                }

                if (e.key !== 'Tab') {
                    return;
                }

                // Tab stays in the dialog, and the panel's own trap, which
                // would walk the header behind it, never hears of it.
                e.stopPropagation();

                var $stops = $dialog.find(focusable).filter(':visible');
                var first = $stops[0];
                var last = $stops[$stops.length - 1];

                if (e.shiftKey && document.activeElement === first) {
                    e.preventDefault();
                    last.focus();
                } else if (!e.shiftKey && document.activeElement === last) {
                    e.preventDefault();
                    first.focus();
                }
            });

            // Focus that gets out some other way is brought back.
            $(document).on('focusin.' + id, function (e) {
                if (document.contains($layer[0]) && !$layer[0].contains(e.target) && $flyout[0].contains(e.target)) {
                    $dialog.find(focusable).filter(':visible').first().trigger('focus');
                }
            });

            // Closing the panel under a half-written form asks the same
            // question Cancel does, instead of throwing the form away.
            $(document).on('wpflyout:closing.' + id, function (e, detail) {
                if (document.contains($layer[0]) && detail && detail.element === $flyout[0] && !detail.force && typed()) {
                    e.preventDefault();
                    leave();
                }
            });

            $flyout.addClass('has-prompt').append($layer);

            var $start = $fields.find('input, textarea, select').filter(':visible').first();

            ($start.length ? $start : $cancel).trigger('focus');

            return $dialog;
        },

        /**
         * One field of the form
         *
         * @param {Object} field `name`, `label`, `type`, and what its type takes
         * @param {string} id    An id for its control
         * @return {jQuery}
         */
        field: function (field, id) {
            var $wrap = $('<div class="wp-flyout-prompt-field"></div>');
            var value = field.value == null ? '' : String(field.value);
            var $control;

            if (field.type === 'static') {
                $('<span class="wp-flyout-prompt-label"></span>').text(field.label || '').appendTo($wrap);
                $('<span class="wp-flyout-prompt-static"></span>').text(value).appendTo($wrap);

                return $wrap.addClass('wp-flyout-prompt-field--static');
            }

            $('<label class="wp-flyout-prompt-label"></label>').attr('for', id).text(field.label || '').appendTo($wrap);

            if (field.type === 'textarea') {
                $control = $('<textarea></textarea>').attr('rows', field.rows || 6);
            } else if (field.type === 'select') {
                // `options` is value to label, in the order to offer them.
                $control = $('<select></select>');

                $.each(field.options || {}, function (key, label) {
                    $('<option></option>').attr('value', key).text(label).appendTo($control);
                });
            } else {
                // The browser's own control for an address, a day or a
                // number; a line of text for anything else.
                $control = $('<input />').attr(
                    'type',
                    ['email', 'date', 'number'].indexOf(field.type) !== -1 ? field.type : 'text'
                );

                $.each(['min', 'max', 'step'], function (i, name) {
                    if (field[name] != null && field[name] !== '') {
                        $control.attr(name, field[name]);
                    }
                });
            }

            $control
                .attr({ id: id, name: field.name || '', 'data-initial': value })
                .addClass('wp-flyout-prompt-control')
                .val(value);

            if (field.required) {
                $control.prop('required', true).attr('aria-required', 'true');
            }

            if (field.maxlength) {
                $control.attr('maxlength', field.maxlength);
            }

            if (field.placeholder) {
                $control.attr('placeholder', field.placeholder);
            }

            $wrap.append($control);

            if (field.description) {
                $('<p class="wp-flyout-prompt-note"></p>')
                    .attr('id', id + '-note')
                    .text(field.description)
                    .appendTo($wrap);

                $control.attr('aria-describedby', id + '-note');
            }

            return $wrap;
        },

        /**
         * Take any form off a panel, without asking
         *
         * @param {jQuery} $flyout
         */
        close: function ($flyout) {
            $flyout.find('.wp-flyout-prompt-layer').remove();
            $flyout.removeClass('has-prompt');
        }
    };

    window.WPFlyoutPrompt = WPFlyoutPrompt;

})(jQuery);
