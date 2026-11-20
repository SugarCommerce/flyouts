<?php
/**
 * EmptyState Component
 *
 * Displays helpful empty state messages with optional actions.
 *
 * @package     SugarCommerce\Flyouts\Components
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts\Components;

use SugarCommerce\Flyouts\Renderable;
use SugarCommerce\Flyouts\Utils\Icon;
use SugarCommerce\Fields\Attributes;
use SugarCommerce\Fields\Support\Button;

class EmptyState implements Renderable {

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

        // Auto-generate ID if not provided
        if ( empty( $this->config['id'] ) ) {
            $this->config['id'] = 'empty-state-' . wp_generate_uuid4();
        }
    }

    /**
     * Get default configuration
     *
     * @return array
     */
    private static function get_defaults(): array {
        return [
			'id'           => '',
			'icon'         => Icon::DEFAULTS['empty'],
			'title'        => '',
			'description'  => '',
			'action_text'  => '',
			'action_url'   => '',
			'action_class' => 'button',
			'action_attrs' => [],
			'class'        => '',
        ];
    }

    /**
     * Render the component
     *
     * @return string
     */
    #[\Override]
    public function render(): string {
        $classes = [ 'wp-flyout-empty-state' ];
        if ( $this->config['class'] ) {
            $classes[] = $this->config['class'];
        }

        ob_start();
        ?>
        <div id="<?php echo esc_attr( $this->config['id'] ); ?>"
            class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">

            <?php if ( $this->config['icon'] ) : ?>
                <?php
                // An icon no set has is the default, not an empty circle.
                $glyph = Icon::render( $this->config['icon'] );
                ?>
                <span class="empty-state-icon" aria-hidden="true"><?php echo '' !== $glyph ? $glyph : Icon::get( 'empty' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the fields package's own markup. ?></span>
            <?php endif; ?>

            <?php if ( $this->config['title'] ) : ?>
                <h3 class="empty-state-title"><?php echo esc_html( $this->config['title'] ); ?></h3>
            <?php endif; ?>

            <?php if ( $this->config['description'] ) : ?>
                <p class="empty-state-description"><?php echo esc_html( $this->config['description'] ); ?></p>
            <?php endif; ?>

            <?php if ( $this->config['action_text'] ) : ?>
                <?php $this->render_action(); ?>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render the action button/link
     */
    private function render_action(): void {
        $attributes = new Attributes( (array) $this->config['action_attrs'] );
        $attributes->add_class( (string) $this->config['action_class'] );

        // A link when it goes somewhere, a button when it does something.
        if ( $this->config['action_url'] ) {
            $attributes->set( 'href', $this->config['action_url'] );

            printf(
                '<a%s>%s</a>',
                $attributes->render(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                esc_html( (string) $this->config['action_text'] )
            );

            return;
        }

        $html = Button::render(
            [
                'label'      => (string) $this->config['action_text'],
                'class'      => (string) $this->config['action_class'],
                'attributes' => (array) $this->config['action_attrs'],
            ]
        );

        // The fields package escapes the label and the attributes; the rest is its own
        // markup.
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
}