<?php
/**
 * InfoGrid Component
 *
 * Displays information in a clean grid layout.
 *
 * @package     SugarCommerce\Flyouts\Components
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts\Components;

use SugarCommerce\Flyouts\Renderable;
use SugarCommerce\Fields\Support\Display;

class InfoGrid implements Renderable {

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
            $this->config['id'] = 'info-grid-' . wp_generate_uuid4();
        }
    }

    /**
     * Get default configuration
     *
     * @return array
     */
    private static function get_defaults(): array {
        return [
			'id'          => '',
			'class'       => '',
			'items'       => [],
			'columns'     => 2,
			'empty_value' => '—',
        ];
    }

    /**
     * Render the component
     *
     * @return string
     */
    public function render(): string {
        if ( empty( $this->config['items'] ) ) {
            return '';
        }

        $classes = [
			'wp-flyout-info-grid',
			'columns-' . $this->config['columns'],
        ];

        if ( ! empty( $this->config['class'] ) ) {
            $classes[] = $this->config['class'];
        }

        ob_start();
        ?>
        <div id="<?php echo esc_attr( $this->config['id'] ); ?>"
            class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
            data-columns="<?php echo esc_attr( $this->config['columns'] ); ?>">
            <?php foreach ( $this->config['items'] as $item ) : ?>
                <?php $this->render_item( $item ); ?>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * A grid of facts as markup, for putting under something else a panel
     * drew.
     *
     * The component is a field, and a field is a whole row of a card to
     * itself. A card that wants its facts directly under a person or a
     * payment it drew builds one piece of markup and ends it with this --
     * the same markup and classes as the component, so the two cannot be
     * told apart on a panel.
     *
     * The values here are markup, and escaping them is the caller's job;
     * the labels and titles are escaped here. A row with no label or no
     * value is left out, so a fact a record does not have takes no room.
     *
     *     InfoGrid::grid( [
     *         [ 'label' => 'Country', 'value' => $flag . esc_html( $country ) ],
     *         [ 'label' => 'Browser', 'value' => esc_html( 'Chrome on a Mac' ), 'title' => $user_agent ],
     *     ] );
     *
     * @param array<int, array{label?: string, value?: string, title?: string}> $rows Each a `label`,
     *                                         a `value` (markup, already escaped) and optionally a
     *                                         `title` shown on hover.
     * @param array{columns?: int, class?: string}                              $args How many columns
     *                                         (2), and a class of the caller's own.
     *
     * @return string Markup. Empty when no row has anything to say.
     * @since 1.0.0
     */
    public static function grid( array $rows, array $args = [] ): string {
        $columns = max( 1, (int) ( $args['columns'] ?? 2 ) );
        $class   = trim( 'wp-flyout-info-grid columns-' . $columns . ' ' . (string) ( $args['class'] ?? '' ) );
        $items   = '';

        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $label = is_scalar( $row['label'] ?? null ) ? trim( (string) $row['label'] ) : '';
            $value = is_scalar( $row['value'] ?? null ) ? trim( (string) $row['value'] ) : '';
            $title = is_scalar( $row['title'] ?? null ) ? trim( (string) $row['title'] ) : '';

            if ( '' === $label || '' === $value ) {
                continue;
            }

            $items .= sprintf(
                '<div class="wp-flyout-info-item"><div class="wp-flyout-info-label">%1$s</div><div class="wp-flyout-info-value"%3$s>%2$s</div></div>',
                esc_html( $label ),
                $value,
                '' === $title ? '' : ' title="' . esc_attr( $title ) . '"'
            );
        }

        return '' === $items
            ? ''
            : sprintf( '<div class="%1$s" data-columns="%2$d">%3$s</div>', esc_attr( $class ), $columns, $items );
    }

    /**
     * Render a single grid item
     *
     * @param array $item Item configuration
     */
    private function render_item( array $item ): void {
        if ( empty( $item['label'] ) ) {
            return;
        }

        // Through the fields package, which decides what "empty" is and escapes what it
        // prints. Both were wrong here: `empty()` made a count of nought
        // render as a dash, and escaping the result a second time printed the
        // placeholder's own markup as visible tags.
        //
        // Unless the item says its value is markup (`html => true`): a flag
        // beside a country, a link, a copy button. Then it is printed as it
        // is, which is the caller's promise that it was escaped where it was
        // built -- a value from the panel's `load`, never from a request.
        $markup = ! empty( $item['html'] ) && is_scalar( $item['value'] ?? null ) && '' !== trim( (string) $item['value'] );
        $value  = $markup
            ? (string) $item['value']
            : Display::text( $item['value'] ?? '', (string) $this->config['empty_value'] );

        // What the value is short for, on hover: the date behind "2 weeks
        // ago", the user agent behind "Chrome on a Mac". On the value only,
        // so the label is not read out with it.
        $title = is_scalar( $item['title'] ?? null ) ? trim( (string) $item['title'] ) : '';
        ?>
        <div class="wp-flyout-info-item">
            <div class="wp-flyout-info-label">
                <?php echo esc_html( $item['label'] ); ?>
            </div>
            <div class="wp-flyout-info-value"<?php echo '' === $title ? '' : ' title="' . esc_attr( $title ) . '"'; ?>>
                <?php echo $value; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </div>
        </div>
        <?php
    }
}