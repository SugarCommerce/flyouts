<?php
/**
 * EntityHeader Component
 *
 * Displays a unified header for any entity with optional interactive image picker.
 * Supports WordPress media library integration for selecting/replacing the header image.
 *
 * @package     SugarCommerce\Flyouts\Components
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts\Components;

use SugarCommerce\Flyouts\Renderable;
use SugarCommerce\Flyouts\Utils\Icon;

class Header implements Renderable {

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
            $this->config['id'] = 'entity-header-' . wp_generate_uuid4();
        }

        // Sanitize thumbnail width
        $this->config['thumbnail_width'] = max( 32, min( 200, (int) $this->config['thumbnail_width'] ) );

        // Resolve image from attachment_id if no image URL provided
        if ( empty( $this->config['image'] ) && ! empty( $this->config['attachment_id'] ) ) {
            $url = wp_get_attachment_image_url(
                    (int) $this->config['attachment_id'],
                    $this->config['image_size']
            );
            if ( $url ) {
                $this->config['image'] = $url;
            }
        }

        // Use fallback image if still no image
        if ( empty( $this->config['image'] ) ) {
            if ( ! empty( $this->config['fallback_attachment_id'] ) ) {
                $url = wp_get_attachment_image_url(
                        (int) $this->config['fallback_attachment_id'],
                        $this->config['image_size']
                );
                if ( $url ) {
                    $this->config['image'] = $url;
                }
            } elseif ( ! empty( $this->config['fallback_image'] ) ) {
                $this->config['image'] = $this->config['fallback_image'];
            }
        }
    }

    /**
     * Get default configuration
     *
     * @return array
     */
    private static function get_defaults(): array {
        return [
			'id'                     => '',
			'name'                   => 'header_image',
			'title'                  => '',
			'subtitle'               => '',

            // Image display
                'image'                  => '',
			'image_size'             => 'thumbnail',
			'image_shape'            => 'square', // square, circle, rounded
			'thumbnail_width'        => 60,       // Display size in pixels (always square)

            // Image picker (interactive)
			'editable'               => false,
			'attachment_id'          => 0,

            // Fallback when no image is set
                'fallback_image'         => '',
			'fallback_attachment_id' => 0,

            // Icon (used when no image at all)
                'icon'                   => '',

            // Other header content
                'badges'                 => [],
			'meta'                   => [],
			'description'            => '',
			'class'                  => '',
        ];
    }

    /**
     * Get the inline style string for the configured thumbnail dimensions.
     *
     * @return string
     */
    private function get_size_style(): string {
        $width = $this->config['thumbnail_width'];

        return sprintf( 'width:%dpx;height:%dpx;', $width, $width );
    }

    /**
     * Render the component
     *
     * @return string
     */
    public function render(): string {
        if ( empty( $this->config['title'] ) ) {
            return '';
        }

        $classes = [ 'entity-header' ];
        if ( ! empty( $this->config['class'] ) ) {
            $classes[] = $this->config['class'];
        }

        // Drawn once: an icon no set has is no icon, not an empty tile.
        $icon       = $this->config['image'] ? '' : Icon::render( $this->config['icon'] );
        $has_visual = $this->config['image'] || '' !== $icon || $this->config['editable'];

        ob_start();
        ?>
        <div id="<?php echo esc_attr( $this->config['id'] ); ?>"
            class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">

            <?php if ( $has_visual ) : ?>
                <div class="entity-header-visual">
                    <?php if ( $this->config['editable'] ) : ?>
                        <?php $this->render_editable_image(); ?>
                    <?php elseif ( $this->config['image'] ) : ?>
                        <img src="<?php echo esc_url( $this->config['image'] ); ?>"
                            alt="<?php echo esc_attr( $this->config['title'] ); ?>"
                            class="entity-header-image shape-<?php echo esc_attr( $this->config['image_shape'] ); ?>"
                            style="<?php echo esc_attr( $this->get_size_style() ); ?>">
                    <?php elseif ( '' !== $icon ) : ?>
                        <span class="entity-header-icon" style="<?php echo esc_attr( $this->get_size_style() ); ?>">
                            <?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the fields package's own markup. ?>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="entity-header-content">
                <h2 class="entity-header-title">
                    <?php echo esc_html( $this->config['title'] ); ?>
                    <?php $this->render_badges(); ?>
                </h2>

                <?php if ( $this->config['subtitle'] ) : ?>
                    <div class="entity-header-subtitle">
                        <?php echo esc_html( $this->config['subtitle'] ); ?>
                    </div>
                <?php endif; ?>

                <?php if ( ! empty( $this->config['meta'] ) ) : ?>
                    <div class="entity-header-meta">
                        <?php $this->render_meta(); ?>
                    </div>
                <?php endif; ?>

                <?php if ( $this->config['description'] ) : ?>
                    <div class="entity-header-description">
                        <?php echo wp_kses_post( $this->config['description'] ); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render the editable image picker.
     *
     * Displays the current image (or placeholder) with a clickable overlay
     * to open the WordPress media library. Stores the attachment ID in a
     * hidden input for form submission.
     *
     * @return void
     * @since 1.0.0
     */
    private function render_editable_image(): void {
        $attachment_id = (int) ( $this->config['attachment_id'] ?? 0 );
        $current_image = $this->config['image'] ?? '';
        $has_image     = ! empty( $current_image );
        $shape_class   = 'shape-' . ( $this->config['image_shape'] ?? 'square' );

        $picker_classes = [
			'image-picker',
			$shape_class,
        ];
        if ( $has_image ) {
            $picker_classes[] = 'has-image';
        }
        ?>
        <div class="<?php echo esc_attr( implode( ' ', $picker_classes ) ); ?>"
            style="<?php echo esc_attr( $this->get_size_style() ); ?>"
            data-name="<?php echo esc_attr( $this->config['name'] ); ?>"
            data-size="<?php echo esc_attr( $this->config['image_size'] ); ?>"
            data-placeholder="<?php echo esc_attr( $this->placeholder_icon() ); ?>"
            data-fallback-image="<?php echo esc_attr( $this->config['fallback_image'] ?? '' ); ?>"
            data-fallback-attachment-id="<?php echo esc_attr( (string) ( $this->config['fallback_attachment_id'] ?? 0 ) ); ?>">

            <?php // Current image or placeholder ?>
            <div class="image-picker-preview">
                <?php if ( $has_image ) : ?>
                    <img src="<?php echo esc_url( $current_image ); ?>"
                        alt="<?php echo esc_attr( $this->config['title'] ); ?>"
                        class="image-picker-img">
                <?php else : ?>
                    <div class="image-picker-placeholder">
                        <?php echo $this->placeholder_icon(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the fields package's own markup. ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php // Hover overlay with actions ?>
            <div class="image-picker-overlay">
                <button type="button"
                        class="image-picker-btn"
                        data-action="select-image"
                        title="
                        <?php
                        echo $has_image
                                ? esc_attr__( 'Change image', 'sugarcommerce' )
                                : esc_attr__( 'Select image', 'sugarcommerce' );
                                ?>
                                ">
                    <?php echo Icon::get( $has_image ? 'change' : 'add' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the fields package's own markup. ?>
                </button>

                <?php if ( $has_image ) : ?>
                    <button type="button"
                            class="image-picker-btn image-picker-remove"
                            data-action="remove-image"
                            title="<?php esc_attr_e( 'Remove image', 'sugarcommerce' ); ?>">
                        <?php echo Icon::get( 'trash' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the fields package's own markup. ?>
                    </button>
                <?php endif; ?>
            </div>

            <?php // Hidden input for form submission ?>
            <input type="hidden"
                    name="<?php echo esc_attr( $this->config['name'] ); ?>"
                    value="<?php echo esc_attr( (string) $attachment_id ); ?>"
                    class="image-picker-value">
        </div>
        <?php
    }

    /**
     * The picture an editable image shows while it has none.
     *
     * The header's own `icon` when it has one that draws, a photo
     * otherwise. Drawn here and carried on the picker too, so the script
     * puts back exactly this when an image is removed.
     *
     * @return string
     */
    private function placeholder_icon(): string {
        $icon = Icon::render( $this->config['icon'] );

        return '' !== $icon ? $icon : Icon::get( 'image' );
    }

    /**
     * Render badges
     *
     * @return void
     */
    private function render_badges(): void {
        foreach ( $this->config['badges'] as $badge ) {
            if ( is_array( $badge ) ) {
                $text = $badge['text'] ?? '';
                $type = $badge['type'] ?? 'default';
            } else {
                $text = $badge;
                $type = 'default';
            }

            if ( empty( $text ) ) {
                continue;
            }
            ?>
            <span class="badge badge-<?php echo esc_attr( $type ); ?>">
				<?php echo esc_html( $text ); ?>
			</span>
            <?php
        }
    }

    /**
     * Render meta items
     *
     * Each is a value, with a label before it when it needs one and a small
     * mark before that. The mark is an `icon` -- a dashicon's name, or a
     * fields icon (`heroicons-outline:calendar`) -- or a
     * picture, given as a URL in `image` -- a country's flag beside its
     * name, which no icon font has and which the value cannot carry
     * because the value is escaped. The picture is sixteen pixels, the
     * icon's own size, and has no alt text: the value beside it already
     * says what it is a picture of. Given both, the picture is drawn.
     *
     * @return void
     */
    private function render_meta(): void {
        foreach ( $this->config['meta'] as $meta ) {
            $label = $meta['label'] ?? '';
            $value = $meta['value'] ?? '';
            $icon  = $meta['icon'] ?? '';
            $image = is_string( $meta['image'] ?? null ) ? $meta['image'] : '';

            if ( empty( $value ) ) {
                continue;
            }
            ?>
            <span class="entity-header-meta-item">
				<?php if ( '' !== $image ) : ?>
                    <img class="entity-header-meta-image" src="<?php echo esc_url( $image ); ?>" alt="" width="16" height="16" loading="lazy" />
                <?php elseif ( $icon ) : ?>
                    <?php echo Icon::render( $icon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the fields package's own markup. ?>
                <?php endif; ?>
                <?php if ( $label ) : ?>
                    <span class="meta-label"><?php echo esc_html( $label ); ?>:</span>
                <?php endif; ?>
				<span class="meta-value"><?php echo esc_html( $value ); ?></span>
			</span>
            <?php
        }
    }
}