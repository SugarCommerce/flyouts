<?php
/**
 * WP Flyout Core Class - Simplified
 *
 * Renders flyout panels with optional tabs.
 * This is a pure UI component - all business logic should be handled
 * by the implementing plugin via the Manager class.
 *
 * @package     SugarCommerce\Flyouts
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts;

use SugarCommerce\Flyouts\Traits\Flyout\Header;
use SugarCommerce\Flyouts\Traits\Flyout\Rendering;
use SugarCommerce\Flyouts\Utils\Runtime;

/**
 * Class Flyout
 *
 * Main flyout container for rendering slide-out panels.
 *
 * @since 1.0.0
 */
class Flyout {

	use Header;
	use Rendering;

    /**
     * Unique identifier for this flyout
     *
     * @since 1.0.0
     * @var string
     */
    private string $id;

    /**
     * Valid flyout size options
     *
     * @since 1.0.0
     * @var array<string>
     */
    const VALID_SIZES = [ 'small', 'medium', 'large', 'full' ];

    /**
     * Valid flyout position options
     *
     * @since 1.0.0
     * @var array<string>
     */
    const VALID_POSITIONS = [ 'left', 'right' ];

    /**
     * The tones a header badge can take
     *
     * What the state means rather than what colour it is: the stylesheet
     * decides the colour, and a tone nobody recognises is neutral.
     *
     * @since 1.0.0
     * @var array<string>
     */
    const VALID_TONES = [ 'success', 'warning', 'danger', 'info', 'neutral' ];

    /**
     * Flyout configuration
     *
     * @since 1.0.0
     * @var array{
     *     title: string,
     *     subtitle: string,
     *     eyebrow: string,
     *     badge: array{label: string, tone: string},
     *     tags: string,
     *     menu: array<int, array<string, mixed>>,
     *     identity: array<string, mixed>|null,
     *     size: string,
     *     position: string,
     *     classes: array<string>
     * }
     */
    private array $config = [
		'title'    => '',
		'subtitle' => '',      // Add subtitle support
		'eyebrow'  => '',      // Small text over the title: what kind of thing this is
		'badge'    => [
			'label' => '',
			'tone'  => 'neutral',
		],
		'tags'     => '',      // Markup beside the badge: the record's tags
		'menu'     => [],      // Header menu items
		'identity' => null,    // Avatar, meta and copy button; null for the plain header
		'size'     => 'medium', // small, medium, large, full
		'position' => 'right',  // right or left
		'classes'  => [],
    ];

    /**
     * Tab configuration
     *
     * @since 1.0.0
     * @var array<string, array{id: string, label: string}>
     */
    private array $tabs = [];

    /**
     * Currently active tab identifier
     *
     * @since 1.0.0
     * @var string
     */
    private string $active_tab = '';

    /**
     * Content storage for tabs or main body
     *
     * @since 1.0.0
     * @var array<string, string>
     */
    private array $content = [];

    /**
     * Footer content HTML
     *
     * @since 1.0.0
     * @var string
     */
    private string $footer = '';

    /**
     * Constructor
     *
     * @param string $id     Unique identifier for this flyout
     * @param array  $config Optional configuration array
     *
     * @since 1.0.0
     *
     */
    public function __construct( string $id, array $config = [] ) {
        $this->id     = $id;
        $this->config = array_merge( $this->config, $config );
    }

    /**
     * Set flyout size
     *
     * @param string $size Flyout size: 'small', 'medium', 'large', or 'full'
     *
     * @return self Returns instance for method chaining
     * @since 1.0.0
     *
     */
    public function set_size( string $size ): self {
        if ( in_array( $size, self::VALID_SIZES, true ) ) {
            $this->config['size'] = $size;
        }

        return $this;
    }

    /**
     * Get flyout size
     *
     * @return string Current size setting
     * @since 1.0.0
     */
    public function get_size(): string {
        return $this->config['size'];
    }

    /**
     * Set flyout position
     *
     * @param string $position Position: 'left' or 'right'
     *
     * @return self Returns instance for method chaining
     * @since 1.0.0
     *
     */
    public function set_position( string $position ): self {
        if ( in_array( $position, self::VALID_POSITIONS, true ) ) {
            $this->config['position'] = $position;
        }

        return $this;
    }

    /**
     * Get flyout ID
     *
     * @return string Flyout identifier
     * @since 1.0.0
     *
     */
    public function get_id(): string {
        return $this->id;
    }

    /**
     * Add a tab to the flyout
     *
     * @param string $id     Tab identifier (used for content association)
     * @param string $label  Tab label to display
     * @param bool   $active Whether this tab should be active by default
     *
     * @return self Returns instance for method chaining
     * @since 1.0.0
     *
     */
    public function add_tab( string $id, string $label, bool $active = false ): self {
        $this->tabs[ $id ] = [
			'id'    => $id,
			'label' => $label,
        ];

        // Set as active if requested or if it's the first tab
        if ( $active || empty( $this->active_tab ) ) {
            $this->active_tab = $id;
        }

        // Initialize content storage for this tab
        if ( ! isset( $this->content[ $id ] ) ) {
            $this->content[ $id ] = '';
        }

        return $this;
    }

    /**
     * Add content to a tab or main body
     *
     * Appends content to existing content.
     *
     * @param string $tab_id  Tab identifier (empty string for non-tabbed content)
     * @param string $content HTML content to add
     *
     * @return self Returns instance for method chaining
     * @since 1.0.0
     *
     */
    public function add_content( string $tab_id, string $content ): self {
        // If no tabs exist, use main content area
        if ( empty( $tab_id ) && empty( $this->tabs ) ) {
            $tab_id = 'main';
        }

        if ( ! isset( $this->content[ $tab_id ] ) ) {
            $this->content[ $tab_id ] = '';
        }

        $this->content[ $tab_id ] .= $content;

        return $this;
    }

    /**
     * Set content for a tab (replaces existing)
     *
     * Replaces any existing content for the specified tab.
     *
     * @param string $tab_id  Tab identifier
     * @param string $content HTML content to set
     *
     * @return self Returns instance for method chaining
     * @since 1.0.0
     *
     */
    public function set_tab_content( string $tab_id, string $content ): self {
        if ( empty( $tab_id ) && empty( $this->tabs ) ) {
            $tab_id = 'main';
        }

        $this->content[ $tab_id ] = $content;

        return $this;
    }

    /**
     * Clear all content
     *
     * @return self Returns instance for method chaining
     * @since 1.0.0
     *
     */
    public function clear_content(): self {
        $this->content = [];

        // Re-initialize content for existing tabs
        foreach ( $this->tabs as $tab ) {
            $this->content[ $tab['id'] ] = '';
        }

        // Initialize main content if no tabs
        if ( empty( $this->tabs ) ) {
            $this->content['main'] = '';
        }

        return $this;
    }

    /**
     * Set footer content
     *
     * @param string $content Footer HTML content (typically action buttons)
     *
     * @return self Returns instance for method chaining
     * @since 1.0.0
     *
     */
    public function set_footer( string $content ): self {
        $this->footer = $content;

        return $this;
    }

    /**
     * Get footer content
     *
     * @return string Current footer HTML
     * @since 1.0.0
     *
     */
    public function get_footer(): string {
        return $this->footer;
    }

    /**
     * Add CSS class to flyout
     *
     * @param string $class_name CSS class name to add
     *
     * @return self Returns instance for method chaining
     * @since 1.0.0
     *
     */
    public function add_class( string $class_name ): self {
        if ( ! in_array( $class_name, $this->config['classes'], true ) ) {
            $this->config['classes'][] = $class_name;
        }

        return $this;
    }

    /**
     * Check if flyout has tabs
     *
     * @return bool True if tabs are configured
     * @since 1.0.0
     *
     */
    public function has_tabs(): bool {
        return ! empty( $this->tabs );
    }

    /**
     * Check if flyout has footer
     *
     * @return bool True if footer content exists
     * @since 1.0.0
     *
     */
    public function has_footer(): bool {
        return ! empty( $this->footer );
    }

    /**
     * Render the complete flyout
     *
     * Generates the complete HTML for the flyout panel.
     *
     * @return string Generated HTML
     * @since 1.0.0
     *
     */
    public function render(): string {
        $classes = [
			'wp-flyout',
			'wp-flyout-' . $this->config['size'],  // Changed from 'width' to 'size'
			'wp-flyout-' . $this->config['position'],
			...$this->config['classes'],
        ];

        // Allow filtering classes
        $classes = apply_filters( "wp_flyout_classes_{$this->id}", $classes, $this->config );

        ob_start();
        ?>
        <div id="<?php echo esc_attr( $this->id ); ?>"
            class="<?php echo esc_attr( implode( ' ', array_filter( $classes ) ) ); ?>"
            role="dialog"
            aria-modal="true"
            aria-labelledby="<?php echo esc_attr( $this->id ); ?>-title"
            tabindex="-1"
            data-flyout-id="<?php echo esc_attr( $this->id ); ?>"
            data-flyout-build="<?php echo esc_attr( Runtime::handle() ); ?>">

            <?php do_action( "wp_flyout_before_header_{$this->id}", $this->config ); ?>

            <?php $this->render_header(); ?>

            <?php do_action( "wp_flyout_after_header_{$this->id}", $this->config ); ?>

            <?php if ( $this->has_tabs() ) : ?>
                <?php $this->render_tabs(); ?>
            <?php endif; ?>

            <?php $this->render_body(); ?>

        </div>
        <?php
        return ob_get_clean();
    }
}