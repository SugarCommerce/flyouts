<?php
/**
 * Line Items Component
 *
 * @package     SugarCommerce\Flyouts\Components
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts\Components;

use SugarCommerce\Fields\Utils\Runtime as FieldRuntime;

use SugarCommerce\Flyouts\Renderable;
use SugarCommerce\Flyouts\Utils\Icon;
use SugarCommerce\Flyouts\RestApi;
use SugarCommerce\Flyouts\Utils\Amount;
use SugarCommerce\Money\Money;
use SugarCommerce\Fields\Attributes;

class LineItems implements Renderable {

    /**
     * Component configuration
     *
     * @var array
     */
    private array $config;

    /**
     * Constructor
     *
     * @param array $config Configuration options
     */
    public function __construct( array $config = [] ) {
        $this->config = wp_parse_args( $config, self::get_defaults() );

        if ( empty( $this->config['id'] ) ) {
            $this->config['id'] = 'line-items-' . wp_generate_uuid4();
        }

        if ( ! is_array( $this->config['items'] ) ) {
            $this->config['items'] = [];
        }

        $this->warn_about_unnamed_rows();
    }

    /**
     * Complain, under WP_DEBUG, about a row with no name.
     *
     * A row is `name` and `price`, and a price is in the unit `money` names
     * -- major units unless it says `minor`; see Utils\Amount. Get either
     * wrong and nothing
     * breaks: the wrong key renders a blank label and a total of zero, and
     * the wrong unit renders a hundred times too large. It cost an afternoon
     * in this library's own demo, where the rows said `title` and `amount`,
     * and another in a consumer passing minor units.
     *
     * @return void
     */
    private function warn_about_unnamed_rows(): void {
        if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
            return;
        }

        foreach ( $this->config['items'] as $index => $item ) {
            if ( ! is_array( $item ) || '' !== (string) ( $item['name'] ?? '' ) ) {
                continue;
            }

            _doing_it_wrong(
                __METHOD__,
                sprintf(
                    /* translators: 1: row index, 2: comma-separated list of the keys the row does have */
                    esc_html__( 'Line item %1$s has no "name". It has: %2$s. A row is name and price, and the price is in the unit its "money" option names.', 'sugarcommerce' ),
                    esc_html( (string) $index ),
                    esc_html( implode( ', ', array_keys( $item ) ) )
                ),
                '4.0.0'
            );
        }
    }

    /**
     * Get default configuration
     *
     * @return array
     */
    private static function get_defaults(): array {
        return [
			'id'            => '',
			'name'          => 'line_items',
			'items'         => [],
			'currency'      => 'USD',
			'show_quantity' => true,
			'search_key'    => '',       // Field key for the ajax_select search
			'details_key'   => '',       // Action key for fetching product details
			'manager'       => '',       // Manager prefix, set when the field is normalized
			'flyout'        => '',       // Flyout ID, set when the field is normalized
			'search_source' => '',       // Fields search source, registered when the field is normalized
			'placeholder'   => 'Search for products...',
			'empty_text'    => 'No items added yet.',
			'add_text'      => 'Add Item',
			'class'         => '',

			// The unit every price is in -- the rows here, the details
			// callback's product, and what the form posts back: `major`
			// (99.00) or `minor` (9900). See Utils\Amount.
			'money'         => Amount::MAJOR,
        ];
    }

    /**
     * Calculate total
     *
     * In minor units, so the sum is exact. It was a sum of prices cast to
     * whole major units, which dropped the cents from every row: two rows
     * at 99.99 totalled 198.00.
     *
     * @return int Total in minor units.
     */
    private function calculate_total(): int {
        $total = 0;
        foreach ( $this->config['items'] as $item ) {
            $quantity = max( 1, (int) ( $item['quantity'] ?? 1 ) );
            $total   += $this->to_minor( $item['price'] ?? 0 ) * $quantity;
        }

        return $total;
    }

    /**
     * A price in minor units, from whichever unit the component takes.
     *
     * @param mixed $amount A price in the component's unit.
     *
     * @return int
     */
    private function to_minor( $amount ): int {
        return Amount::minor( $amount, (string) $this->config['currency'], Amount::unit( $this->config['money'] ) );
    }

    /**
     * Render the component
     *
     * @return string
     */
    public function render(): string {
        $classes = [ 'wp-flyout-line-items' ];
        if ( ! empty( $this->config['class'] ) ) {
            $classes[] = $this->config['class'];
        }

        $attributes = new Attributes();
        $attributes->set( 'id', $this->config['id'] );
        $attributes->add_class( ...$classes );

        foreach (
            [
                'name'          => $this->config['name'],
                'currency'      => $this->config['currency'],
                // Which unit data-price and a picked product's price are
                // in, so the script never has to guess.
                'money'         => Amount::unit( $this->config['money'] ),
                'show-quantity' => $this->config['show_quantity'] ? '1' : '0',
                'manager'       => $this->config['manager'],
                'flyout'        => $this->config['flyout'],
                'details-key'   => $this->config['details_key'],
                'locale'        => get_bloginfo( 'language' ),
                'empty-text'    => $this->config['empty_text'],
            ] as $key => $value
        ) {
            $attributes->set_if( '' !== (string) $value, 'data-' . $key, (string) $value );
        }

        ob_start();
        ?>
        <div <?php echo $attributes->render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>

            <?php if ( $this->config['search_key'] ) : ?>
                <?php $this->render_product_selector(); ?>
            <?php endif; ?>

            <div class="line-items-table">
                <?php $this->render_items_table(); ?>
            </div>

            <?php $this->render_total(); ?>
            <?php $this->render_item_template(); ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render the product selector.
     *
     * The fields package's combobox, against the fields package's search endpoint. It used to be
     * select2 against a search endpoint of this library's own — two search
     * controls and two endpoints in one codebase, and seventy-three kilobytes
     * of script to serve the one picker that still needed the second.
     *
     * The source is registered by the manager when the field is normalized,
     * and its name means nothing to anyone who has not registered it.
     */
    private function render_product_selector(): void {
        $source = (string) ( $this->config['search_source'] ?? '' );
        ?>
        <div class="line-items-selector">
            <select class="field-kit__select field-kit__select--enhanced product-ajax-select"
                    data-search-endpoint="<?php echo esc_url( rest_url( FieldRuntime::rest_namespace() . '/search' ) ); ?>"
                    data-search-source="<?php echo esc_attr( $source ); ?>"
                    data-search-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
                    data-placeholder="<?php echo esc_attr( $this->config['placeholder'] ); ?>">
                <option value=""><?php echo esc_html( $this->config['placeholder'] ); ?></option>
            </select>
            <button type="button" class="button button-primary" data-action="add-item">
                <?php echo esc_html( $this->config['add_text'] ); ?>
            </button>
        </div>
        <?php
    }

    /**
     * Render items table
     */
    private function render_items_table(): void {
        if ( empty( $this->config['items'] ) ) {
            ?>
            <div class="line-items-empty">
                <?php echo Icon::get( 'cart' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the fields package's own markup. ?>
                <p><?php echo esc_html( $this->config['empty_text'] ); ?></p>
            </div>
            <?php
            return;
        }
        ?>
        <table>
            <thead>
            <tr>
                <th class="column-item"><?php esc_html_e( 'Item', 'sugarcommerce' ); ?></th>
                <?php if ( $this->config['show_quantity'] ) : ?>
                    <th class="column-quantity"><?php esc_html_e( 'Qty', 'sugarcommerce' ); ?></th>
                <?php endif; ?>
                <th class="column-price"><?php esc_html_e( 'Price', 'sugarcommerce' ); ?></th>
                <?php if ( $this->config['show_quantity'] ) : ?>
                    <th class="column-total"><?php esc_html_e( 'Total', 'sugarcommerce' ); ?></th>
                <?php endif; ?>
                <th class="column-actions"></th>
            </tr>
            </thead>
            <tbody class="line-items-list">
            <?php foreach ( $this->config['items'] as $index => $item ) : ?>
                <?php $this->render_item_row( $item, $index ); ?>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Render single item row
     *
     * @param array $item  Item data
     * @param int   $index Item index
     */
    private function render_item_row( array $item, int $index ): void {
        // As given, in the component's unit, for the attribute the script
        // reads and the input that is posted back; in minor units for what
        // is shown.
        $price    = is_numeric( $item['price'] ?? null ) ? $item['price'] : 0;
        $quantity = max( 1, (int) ( $item['quantity'] ?? 1 ) );
        $minor    = $this->to_minor( $price );
        ?>
        <tr class="line-item" data-index="<?php echo esc_attr( $index ); ?>"
            data-item-id="<?php echo esc_attr( $item['id'] ?? '' ); ?>">

            <td class="column-item">
                <div>
                    <?php if ( ! empty( $item['thumbnail'] ) ) : ?>
                        <img src="<?php echo esc_url( $item['thumbnail'] ); ?>"
                            alt="<?php echo esc_attr( $item['name'] ?? '' ); ?>"
                            class="item-thumbnail">
                    <?php else : ?>
                        <div class="item-thumbnail-placeholder">
                            <?php echo Icon::get( 'image' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the fields package's own markup. ?>
                        </div>
                    <?php endif; ?>
                    <span><?php echo esc_html( $item['name'] ?? '' ); ?></span>
                </div>
                <input type="hidden"
                        name="<?php echo esc_attr( $this->config['name'] ); ?>[<?php echo esc_attr( $index ); ?>][id]"
                        value="<?php echo esc_attr( $item['id'] ?? '' ); ?>">
                <input type="hidden"
                        name="<?php echo esc_attr( $this->config['name'] ); ?>[<?php echo esc_attr( $index ); ?>][name]"
                        value="<?php echo esc_attr( $item['name'] ?? '' ); ?>">
                <input type="hidden"
                        name="<?php echo esc_attr( $this->config['name'] ); ?>[<?php echo esc_attr( $index ); ?>][thumbnail]"
                        value="<?php echo esc_url( $item['thumbnail'] ?? '' ); ?>">
            </td>

            <?php if ( $this->config['show_quantity'] ) : ?>
                <td class="column-quantity">
                    <input type="number"
                            name="<?php echo esc_attr( $this->config['name'] ); ?>[<?php echo esc_attr( $index ); ?>][quantity]"
                            value="<?php echo esc_attr( (string) $quantity ); ?>"
                            min="1"
                            class="quantity-input small-text"
                            data-action="update-quantity">
                </td>
            <?php else : ?>
                <input type="hidden"
                        name="<?php echo esc_attr( $this->config['name'] ); ?>[<?php echo esc_attr( $index ); ?>][quantity]"
                        value="1">
            <?php endif; ?>

            <td class="column-price">
                <span data-price="<?php echo esc_attr( (string) $price ); ?>">
                    <?php echo esc_html( $this->money( $minor ) ); ?>
                </span>
                <input type="hidden"
                        name="<?php echo esc_attr( $this->config['name'] ); ?>[<?php echo esc_attr( $index ); ?>][price]"
                        value="<?php echo esc_attr( (string) $price ); ?>">
            </td>

            <?php if ( $this->config['show_quantity'] ) : ?>
                <td class="column-total">
                    <span class="item-total"><?php echo esc_html( $this->money( $minor * $quantity ) ); ?></span>
                </td>
            <?php endif; ?>

            <td class="column-actions">
                <button type="button" class="button-link" data-action="remove-item"
                        aria-label="<?php esc_attr_e( 'Remove item', 'sugarcommerce' ); ?>">
                    <?php echo Icon::get( 'trash' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the fields package's own markup. ?>
                </button>
            </td>
        </tr>
        <?php
    }

    /**
     * Render total section
     */
    private function render_total(): void {
        $total = $this->calculate_total();
        ?>
        <div class="line-items-total">
            <?php // What the items come to, before whatever a summary adds: a panel with a price summary under its items showed two different totals. ?>
            <span class="total-label"><?php esc_html_e( 'Subtotal', 'sugarcommerce' ); ?></span>
            <span class="total-amount" data-value="<?php echo esc_attr( Amount::decimal( $total, (string) $this->config['currency'] ) ); ?>">
                <?php echo esc_html( $this->money( $total ) ); ?>
            </span>
        </div>
        <?php
    }

    /**
     * Render JavaScript template for dynamic items
     */
    private function render_item_template(): void {
        ?>
        <script type="text/template" class="line-item-template">
            <tr class="line-item" data-item-id="{{item_id}}">
                <td class="column-item">
                    <div>
                        {{thumbnail_html}}
                        <span>{{name}}</span>
                    </div>
                    <input type="hidden" name="<?php echo esc_attr( $this->config['name'] ); ?>[{{index}}][id]"
                            value="{{item_id}}">
                    <input type="hidden" name="<?php echo esc_attr( $this->config['name'] ); ?>[{{index}}][name]"
                            value="{{name}}">
                    <input type="hidden" name="<?php echo esc_attr( $this->config['name'] ); ?>[{{index}}][thumbnail]"
                            value="{{thumbnail}}">
                </td>

                <?php if ( $this->config['show_quantity'] ) : ?>
                    <td class="column-quantity">
                        <input type="number"
                                name="<?php echo esc_attr( $this->config['name'] ); ?>[{{index}}][quantity]"
                                value="1"
                                min="1"
                                class="quantity-input small-text"
                                data-action="update-quantity">
                    </td>
                <?php else : ?>
                    <input type="hidden"
                            name="<?php echo esc_attr( $this->config['name'] ); ?>[{{index}}][quantity]"
                            value="1">
                <?php endif; ?>

                <td class="column-price">
                    <span data-price="{{price}}">{{price_formatted}}</span>
                    <input type="hidden"
                            name="<?php echo esc_attr( $this->config['name'] ); ?>[{{index}}][price]"
                            value="{{price}}">
                </td>

                <?php if ( $this->config['show_quantity'] ) : ?>
                    <td class="column-total">
                        <span class="item-total">{{total_formatted}}</span>
                    </td>
                <?php endif; ?>

                <td class="column-actions">
                    <button type="button" class="button-link" data-action="remove-item"
                            aria-label="<?php esc_attr_e( 'Remove item', 'sugarcommerce' ); ?>">
                        <?php echo Icon::get( 'trash' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the fields package's own markup. ?>
                    </button>
                </td>
            </tr>
        </script>
        <?php
    }

    /**
     * Format an amount for display.
     *
     * Everything here is converted to minor units first (to_minor()), so
     * this takes them as they are.
     *
     * @param int $amount Amount in minor units.
     *
     * @return string
     */
    private function money( int $amount ): string {
        return Money::format( $amount, [ 'currency' => $this->config['currency'] ] );
    }
}