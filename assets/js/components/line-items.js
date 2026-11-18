/**
 * Line Items Component JavaScript
 *
 * Product search is the fields package's combobox against the fields package's search endpoint.
 * Fetches product details via REST /action endpoint.
 *
 * @version 5.0.0
 */
(function ($) {
    'use strict';
    // This build's REST config; see the resolver in wp-flyout.js.
    var wpFlyout = window.SugarCommerceFlyouts.resolve(document.currentScript);

    /*
     * One of the library's own icons, as the server drew it (the fields package's
     * cleaned SVG, inserted as it is), or the Dashicon it used to be on a
     * page that published none.
     */
    var icon = function (key, dashicon) {
        return (wpFlyout.icons && wpFlyout.icons[key]) ||
            '<span class="dashicons dashicons-' + dashicon + ' sugarcommerce-icon" aria-hidden="true"></span>';
    };


    const LineItems = {

        init: function () {
            var self = this;

            $(document)
                .on('click', '.wp-flyout-line-items [data-action="add-item"]', function (e) {
                    self.handleAdd(e);
                })
                .on('click', '.wp-flyout-line-items [data-action="remove-item"]', function (e) {
                    self.handleRemove(e);
                })
                .on('change', '.wp-flyout-line-items [data-action="update-quantity"]', function (e) {
                    self.handleQuantityChange(e);
                })
                .on('wpflyout:opened', function (e, data) {
                    self.initComponent($(data.element));
                });

            $(function () {
                $('.wp-flyout-line-items').each(function () {
                    self.initComponent($(this).parent());
                });
            });
        },

        initComponent: function ($container) {
            var self = this;

            $container.find('.wp-flyout-line-items').each(function () {
                var $component = $(this);

                if ($component.data('lineItemsInitialized')) {
                    return;
                }

                $component.data('lineItemsInitialized', true);

                // The fields package's combobox upgrades the select; it is bound when
                // the flyout opens, along with every other field in the
                // panel, so there is nothing to initialise here.

                self.recalculateTotals($component);
            });
        },

        handleAdd: function (e) {
            e.preventDefault();

            var $button = $(e.currentTarget);
            var $component = $button.closest('.wp-flyout-line-items');
            var $select = $component.find('.product-ajax-select');

            var itemId = $select.val();

            if (!itemId) {
                alert('Please select a product first');
                return;
            }

            var existingItem = this.findExistingItem($component, itemId);
            if (existingItem.length) {
                var $qtyInput = existingItem.find('.quantity-input');
                var currentQty = parseInt($qtyInput.val()) || 1;
                $qtyInput.val(currentQty + 1).trigger('change');
                this.clearSelect($select);
                return;
            }

            this.fetchProductDetails($component, itemId);
        },

        /**
         * Fetch product details via REST /action endpoint
         */
        fetchProductDetails: function ($component, itemId) {
            var self = this;
            var $button = $component.find('[data-action="add-item"]');
            var originalHtml = $button.html();

            $button.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Loading...');

            var manager = $component.data('manager');
            var flyout = $component.data('flyout');
            var detailsKey = $component.data('details-key');

            window.SugarCommerceFlyouts.request(wpFlyout.restUrl + '/action', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': wpFlyout.restNonce
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    manager: manager,
                    flyout: flyout,
                    action_key: detailsKey,
                    item_id: String(itemId)
                })
            })
                .then(function (response) {
                    return response.json().then(function (json) {
                        if (!response.ok) {
                            throw new Error(json.message || 'Request failed');
                        }
                        return json;
                    });
                })
                .then(function (response) {
                    if (response.success && response.product) {
                        self.addItemToTable($component, response.product);
                        self.clearSelect($component.find('.product-ajax-select'));
                    } else {
                        alert(response.message || 'Product details not found');
                    }
                })
                .catch(function (error) {
                    alert(error.message || 'Error loading product details');
                })
                .finally(function () {
                    $button.prop('disabled', false).html(originalHtml);
                });
        },

        addItemToTable: function ($component, product) {
            var $tbody = $component.find('.line-items-list');

            // Create table if it doesn't exist yet
            if (!$tbody.length) {
                var showQty = $component.data('show-quantity') !== '0' && $component.data('show-quantity') !== false;
                var tableHtml = '<table><thead><tr>' +
                    '<th class="column-item">Item</th>' +
                    (showQty ? '<th class="column-quantity">Qty</th>' : '') +
                    '<th class="column-price">Price</th>' +
                    (showQty ? '<th class="column-total">Total</th>' : '') +
                    '<th class="column-actions"></th>' +
                    '</tr></thead><tbody class="line-items-list"></tbody></table>';
                $component.find('.line-items-table').html(tableHtml);
                $tbody = $component.find('.line-items-list');
            }

            var template = $component.find('.line-item-template').html();
            if (!template) {
                console.error('Line Items: Template not found');
                return;
            }

            var index = $tbody.find('.line-item').length;
            var money = this.formatterFor($component);

            // In the component's unit (data-money), the same thing the
            // details callback returns and the same thing data-price holds
            // on a server-rendered row.
            var price = parseFloat(product.price) || 0;
            var minor = this.toMinor(price, money);

            var thumbnailHtml = product.thumbnail ?
                '<img src="' + this.escapeHtml(product.thumbnail) + '" alt="' +
                this.escapeHtml(product.name) + '" class="item-thumbnail">' :
                '<div class="item-thumbnail-placeholder">' + icon('image', 'format-image') + '</div>';

            // Every value the server sent, escaped for the attribute or the
            // text it lands in. The one exception is the thumbnail markup,
            // which is built above from escaped parts.
            var values = {
                index: String(index),
                item_id: this.escapeHtml(product.id == null ? '' : product.id),
                name: this.escapeHtml(product.name || ''),
                price: this.escapeHtml(price),
                price_formatted: this.escapeHtml(money.format.format(minor / money.factor)),
                total_formatted: this.escapeHtml(money.format.format(minor / money.factor)),
                thumbnail: this.escapeHtml(product.thumbnail || ''),
                thumbnail_html: thumbnailHtml
            };

            // One pass with a function, not a chain of string replacements.
            // A string given to String.replace is read for $-patterns, so a
            // product named "$& Co" or a price of "$1" came out corrupted --
            // and a value containing a later placeholder would have been
            // replaced twice.
            var html = template.replace(/{{(\w+)}}/g, function (match, key) {
                return Object.prototype.hasOwnProperty.call(values, key) ? values[key] : match;
            });

            var $newRow = $(html);
            $tbody.append($newRow);

            $newRow.css('background', '#ffffcc');
            setTimeout(function () {
                $newRow.css('background', '');
            }, 1000);

            this.recalculateTotals($component);
        },

        handleRemove: function (e) {
            e.preventDefault();
            var self = this;

            var $row = $(e.currentTarget).closest('.line-item');
            var $component = $(e.currentTarget).closest('.wp-flyout-line-items');
            var $tbody = $row.closest('.line-items-list');

            $row.fadeOut(300, function () {
                $row.remove();
                self.reindexItems($component);

                if ($tbody.find('.line-item').length === 0) {
                    var emptyText = $component.data('empty-text') || '';
                    var emptyHtml = $('<div class="line-items-empty">')
                        .append(icon('cart', 'cart'))
                        .append($('<p>').text(emptyText));
                    $component.find('.line-items-table').empty().append(emptyHtml);
                }

                self.recalculateTotals($component);
            });
        },

        handleQuantityChange: function (e) {
            var $input = $(e.currentTarget);
            var $component = $input.closest('.wp-flyout-line-items');
            var $row = $input.closest('.line-item');

            var quantity = Math.max(1, parseInt($input.val()) || 1);
            $input.val(quantity);

            this.updateRowTotal($row);
            this.recalculateTotals($component);
        },

        /**
         * A currency formatter for a component, plus the factor that turns
         * its major units into whole minor ones.
         *
         * The locale comes from the page rather than being hard-coded to
         * en-US, and the number of decimal places comes from the currency
         * rather than being assumed to be two -- yen has none, Kuwaiti
         * dinar has three.
         */
        formatterFor: function ($component) {
            var currency = $component.data('currency') || 'USD';
            var locale = $component.data('locale') || undefined;
            var formatter;

            try {
                formatter = new Intl.NumberFormat(locale, {
                    style: 'currency',
                    currency: currency
                });
            } catch (e) {
                // An unknown currency code throws rather than degrading, and
                // it would take the whole panel's scripting with it.
                formatter = new Intl.NumberFormat(locale);
            }

            return {
                format: formatter,
                factor: Math.pow(10, formatter.resolvedOptions().maximumFractionDigits || 0),

                // Whether prices are already minor units -- 9900 for $99 --
                // which is what `money => 'minor'` on the field says.
                minor: $component.data('money') === 'minor'
            };
        },

        /**
         * A price in whole minor units, from the component's own unit.
         */
        toMinor: function (price, money) {
            return money.minor ? Math.round(price) : Math.round(price * money.factor);
        },

        /**
         * One row's price, in whole minor units.
         *
         * data-price holds the component's unit -- 99.99 by default, 9999
         * with `money => 'minor'` -- the same thing PHP renders from. It
         * used to be read with parseInt and then divided by 100, which
         * truncated every fractional price and divided every whole one by a
         * hundred: a $99 line became $0.99 the moment its quantity changed.
         */
        rowMinorUnits: function ($row, money) {
            var price = parseFloat($row.find('[data-price]').data('price')) || 0;
            var quantity = parseInt($row.find('.quantity-input').val(), 10) || 1;

            return this.toMinor(price, money) * quantity;
        },

        updateRowTotal: function ($row) {
            var money = this.formatterFor($row.closest('.wp-flyout-line-items'));
            var total = this.rowMinorUnits($row, money);

            $row.find('.item-total').text(money.format.format(total / money.factor));
        },

        recalculateTotals: function ($component) {
            var self = this;
            var money = this.formatterFor($component);
            var total = 0;

            $component.find('.line-item').each(function () {
                total += self.rowMinorUnits($(this), money);
            });

            $component.find('.total-amount')
                .text(money.format.format(total / money.factor))
                .attr('data-value', total / money.factor);
        },

        findExistingItem: function ($component, itemId) {
            var $found = null;
            $component.find('.line-item').each(function () {
                var $row = $(this);
                var rowItemId = $row.data('item-id') || $row.find('[name*="[id]"]').val();
                if (rowItemId == itemId) {
                    $found = $row;
                    return false;
                }
            });
            return $found ? $($found) : $();
        },

        /**
         * Put the product search back to holding nothing.
         *
         * The fields package's combobox keeps the chosen label in an input of its own
         * and the value on the select underneath, so emptying the select is
         * only half of it — the previous product's name would stay in the box
         * and read as though it were still chosen.
         */
        clearSelect: function ($select) {
            var wrap = $select.closest('.field-kit__combobox')[0];
            var clear = wrap && wrap.querySelector('.field-kit__combobox-clear');

            if (clear) {
                clear.click();

                return;
            }

            // No combobox — the script never reached it — so the plain
            // select is all there is to clear.
            $select.val('').trigger('change');
        },

        reindexItems: function ($component) {
            $component.find('.line-item').each(function (index) {
                var $item = $(this);
                $item.attr('data-index', index);
                $item.find('input').each(function () {
                    var name = $(this).attr('name');
                    if (name) {
                        $(this).attr('name', name.replace(/\[\d+\]/, '[' + index + ']'));
                    }
                });
            });
        },

        /**
         * Escape a value for HTML, attributes included.
         *
         * Not the textContent-to-innerHTML trick, which leaves quotes alone
         * because a text node has no reason to escape them -- and every
         * placeholder in the row template but two sits inside an attribute.
         */
        escapeHtml: function (text) {
            var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };

            return String(text == null ? '' : text).replace(/[&<>"']/g, function (character) {
                return map[character];
            });
        }
    };

    $(function () {
        LineItems.init();
    });

    window.WPFlyoutLineItems = LineItems;

})(jQuery);