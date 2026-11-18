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

use SugarCommerce\Flyouts\Utils\Icon;
use SugarCommerce\Flyouts\Utils\Runtime;

/**
 * Class Flyout
 *
 * Main flyout container for rendering slide-out panels.
 *
 * @since 1.0.0
 */
class Flyout {

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
     * Set flyout title
     *
     * @param string $title Title to display in header
     *
     * @return self Returns instance for method chaining
     * @since 1.0.0
     *
     */
    public function set_title( string $title ): self {
        $this->config['title'] = $title;

        return $this;
    }

    /**
     * Get flyout title
     *
     * @return string Current title
     * @since 1.0.0
     *
     */
    public function get_title(): string {
        return $this->config['title'];
    }

    /**
     * Set flyout subtitle
     *
     * @param string $subtitle Subtitle to display in header
     *
     * @return self Returns instance for method chaining
     * @since 1.0.0
     */
    public function set_subtitle( string $subtitle ): self {
        $this->config['subtitle'] = $subtitle;

        return $this;
    }

    /**
     * Get flyout subtitle
     *
     * @return string Current subtitle
     * @since 1.0.0
     */
    public function get_subtitle(): string {
        return $this->config['subtitle'];
    }

    /**
     * Set the eyebrow
     *
     * The small line over the title. A title that is the record's own name
     * -- "Deep House Kit" -- no longer says what kind of thing it names, so
     * this does: "Product".
     *
     * @param string $eyebrow Text to display above the title
     *
     * @return self Returns instance for method chaining
     * @since 1.0.0
     */
    public function set_eyebrow( string $eyebrow ): self {
        $this->config['eyebrow'] = $eyebrow;

        return $this;
    }

    /**
     * Get the eyebrow
     *
     * @return string Current eyebrow
     * @since 1.0.0
     */
    public function get_eyebrow(): string {
        return $this->config['eyebrow'];
    }

    /**
     * Set the badge beside the title
     *
     * The state the record is in, as a word and a tone. An empty label is
     * no badge at all, and a tone that is not one of VALID_TONES is
     * neutral rather than an error: a badge the wrong colour is a smaller
     * failure than a panel that does not open.
     *
     * @param string $label What the badge says
     * @param string $tone  One of VALID_TONES
     *
     * @return self Returns instance for method chaining
     * @since 1.0.0
     */
    public function set_badge( string $label, string $tone = 'neutral' ): self {
        $this->config['badge'] = [
			'label' => $label,
			'tone'  => in_array( $tone, self::VALID_TONES, true ) ? $tone : 'neutral',
        ];

        return $this;
    }

    /**
     * Get the badge
     *
     * @return array{label: string, tone: string} Current badge
     * @since 1.0.0
     */
    public function get_badge(): array {
        return $this->config['badge'];
    }

    /**
     * Set the tags beside the badge
     *
     * The record's own labels -- "VIP", "Wholesale" -- in the title row
     * after its state, where a reader looks for what kind of record this
     * is. Markup, built by Manager::tags() or by the plugin; empty for none.
     *
     * @param string $html Escaped markup
     *
     * @return self Returns instance for method chaining
     * @since 1.0.0
     */
    public function set_tags( string $html ): self {
        $this->config['tags'] = trim( $html );

        return $this;
    }

    /**
     * Get the tags beside the badge
     *
     * @return string Markup
     * @since 1.0.0
     */
    public function get_tags(): string {
        return $this->config['tags'];
    }

    /**
     * Set the header menu
     *
     * Each item is a `label` and either a `url` (with an optional `target`),
     * an `action` the panel's script sends to the REST endpoint, or a
     * `trigger` -- the attributes of a button that opens another panel in
     * place, which the manager builds from an item's `flyout` and `id` --
     * plus optionally `danger` and a `confirm` question. An item that is
     * none of the three has nothing to do when it is chosen, so it is left
     * out rather than drawn.
     *
     * @param array<int, array<string, mixed>> $items Menu items
     *
     * @return self Returns instance for method chaining
     * @since 1.0.0
     */
    public function set_menu( array $items ): self {
        $this->config['menu'] = [];

        foreach ( $items as $item ) {
            if ( ! is_array( $item ) || '' === (string) ( $item['label'] ?? '' ) ) {
                continue;
            }

            if ( '' === (string) ( $item['url'] ?? '' ) && '' === (string) ( $item['action'] ?? '' ) && empty( $item['trigger'] ) ) {
                continue;
            }

            $this->config['menu'][] = $item;
        }

        return $this;
    }

    /**
     * Get the header menu
     *
     * @return array<int, array<string, mixed>> Current menu items
     * @since 1.0.0
     */
    public function get_menu(): array {
        return $this->config['menu'];
    }

    /**
     * Give the header an identity
     *
     * Opts the panel into the header that says who or what it is about: a
     * picture before the title, the title and badge on one line, and a few
     * quiet facts after the subtitle. See Identity::normalize() for the
     * shape. An empty array is still an opt-in -- the layout without the
     * picture.
     *
     * @param array<string, mixed> $identity `avatar`, `meta` and `copy`
     *
     * @return self Returns instance for method chaining
     * @since 1.0.0
     */
    public function set_identity( array $identity ): self {
        $this->config['identity'] = Identity::normalize( $identity );

        return $this->add_class( 'wp-flyout-identity' );
    }

    /**
     * Get the header's identity
     *
     * @return array<string, mixed>|null Null for a panel that has none
     * @since 1.0.0
     */
    public function get_identity(): ?array {
        return $this->config['identity'];
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
            data-flyout-id="<?php echo esc_attr( $this->id ); ?>">

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

    /**
     * Render flyout header
     *
     * What the record is on the left -- its kind, its name, its state --
     * and what can be done with the panel on the right. The script adds
     * previous and next to that group when the record has neighbours.
     *
     * A panel with an identity (see set_identity()) has its picture first
     * and its meta after the subtitle. One without is drawn exactly as it
     * was before identities existed.
     *
     * The title carries an id because the panel is a dialog, and a dialog
     * is announced by its name.
     *
     * @return void
     * @since  1.0.0
     * @access private
     */
    private function render_header(): void {
        $badge    = $this->config['badge'];
        $identity = $this->config['identity'];
        $subtitle = (string) $this->config['subtitle'];
        $meta     = null === $identity ? '' : Identity::meta( $identity['meta'] );
        $copy     = null !== $identity && 'subtitle' === $identity['copy'] ? Identity::copy_button( $subtitle ) : '';
        ?>
        <div class="wp-flyout-header<?php echo null === $identity ? '' : ' wp-flyout-header--identity'; ?>">
            <?php
            if ( null !== $identity ) {
                // Built and escaped by Identity.
                echo Identity::avatar( $identity['avatar'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }
            ?>
            <div class="wp-flyout-header-content">
                <?php if ( '' !== $this->config['eyebrow'] ) : ?>
                    <p class="wp-flyout-eyebrow"><?php echo esc_html( $this->config['eyebrow'] ); ?></p>
                <?php endif; ?>
                <div class="wp-flyout-title-row">
                    <h2 class="wp-flyout-title" id="<?php echo esc_attr( $this->id ); ?>-title"><?php echo esc_html( $this->config['title'] ); ?></h2>
                    <?php if ( '' !== $badge['label'] ) : ?>
                        <span class="wp-flyout-badge wp-flyout-badge--<?php echo esc_attr( $badge['tone'] ); ?>"><?php echo esc_html( $badge['label'] ); ?></span>
                    <?php endif; ?>
                    <?php if ( '' !== $this->config['tags'] ) : ?>
                        <span class="wp-flyout-tags"><?php echo $this->config['tags']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where it was built. ?></span>
                    <?php endif; ?>
                </div>
                <?php if ( null === $identity ) : ?>
                    <?php if ( '' !== $subtitle ) : ?>
                        <p class="wp-flyout-subtitle"><?php echo esc_html( $subtitle ); ?></p>
                    <?php endif; ?>
                <?php elseif ( '' !== $subtitle || '' !== $meta ) : ?>
                    <p class="wp-flyout-subtitle">
                        <?php if ( '' !== $subtitle ) : ?>
                            <span class="wp-flyout-subtitle-line"><span class="wp-flyout-subtitle-text"><?php echo esc_html( $subtitle ); ?></span><?php echo $copy; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                        <?php endif; ?>
                        <?php echo $meta; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </p>
                <?php endif; ?>
            </div>
            <div class="wp-flyout-header-actions">
                <?php $this->render_menu(); ?>
                <button type="button" class="wp-flyout-close" aria-label="<?php esc_attr_e( 'Close', 'sugarcommerce' ); ?>" title="<?php esc_attr_e( 'Close', 'sugarcommerce' ); ?>">
                    <?php echo Icon::get( 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the fields package's cleaned SVG. ?>
                </button>
            </div>
        </div>
        <?php
    }

    /**
     * Render the header menu
     *
     * A button and the list it opens. The list starts hidden and the button
     * says so, which is the whole contract with the script: it flips those
     * two and moves focus, and everything else is already here.
     *
     * A link is a link, so it can be opened in a new tab like any other. An
     * action is a button carrying the key the REST endpoint knows it by.
     *
     * @return void
     * @since  1.0.0
     * @access private
     */
    private function render_menu(): void {
        if ( empty( $this->config['menu'] ) ) {
            return;
        }

        $list_id = $this->id . '-menu';
        ?>
        <div class="wp-flyout-menu">
            <button type="button"
                class="wp-flyout-menu-toggle"
                aria-haspopup="true"
                aria-expanded="false"
                aria-controls="<?php echo esc_attr( $list_id ); ?>"
                aria-label="<?php esc_attr_e( 'More actions', 'sugarcommerce' ); ?>"
                title="<?php esc_attr_e( 'More actions', 'sugarcommerce' ); ?>">
                <?php echo Icon::get( 'menu' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the fields package's cleaned SVG. ?>
            </button>
            <div class="wp-flyout-menu-list" id="<?php echo esc_attr( $list_id ); ?>" role="menu" hidden>
                <?php foreach ( $this->config['menu'] as $item ) : ?>
                    <?php
                    $class = 'wp-flyout-menu-item' . ( empty( $item['danger'] ) ? '' : ' is-danger' );
                    ?>
                    <?php if ( '' !== (string) ( $item['url'] ?? '' ) ) : ?>
                        <a class="<?php echo esc_attr( $class ); ?>"
                            role="menuitem"
                            tabindex="-1"
                            href="<?php echo esc_url( (string) $item['url'] ); ?>"
                            <?php if ( '' !== (string) ( $item['target'] ?? '' ) ) : ?>
                                target="<?php echo esc_attr( (string) $item['target'] ); ?>"
                                rel="noopener noreferrer"
                            <?php endif; ?>
                        ><?php echo esc_html( (string) $item['label'] ); ?></a>
                    <?php elseif ( ! empty( $item['trigger'] ) && is_array( $item['trigger'] ) ) : ?>
                        <button type="button" role="menuitem" tabindex="-1"
                            <?php
                            // Escaped where they were built, in Manager::build_trigger_attributes().
                            foreach ( $item['trigger'] as $name => $value ) {
                                printf( ' %s="%s"', esc_attr( (string) $name ), $value ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                            }
                            ?>
                        ><?php echo esc_html( (string) $item['label'] ); ?></button>
                    <?php else : ?>
                        <button type="button"
                            class="<?php echo esc_attr( $class ); ?>"
                            role="menuitem"
                            tabindex="-1"
                            data-action="<?php echo esc_attr( (string) $item['action'] ); ?>"
                            <?php if ( '' !== (string) ( $item['confirm'] ?? '' ) ) : ?>
                                data-confirm="<?php echo esc_attr( (string) $item['confirm'] ); ?>"
                            <?php endif; ?>
                        ><?php echo esc_html( (string) $item['label'] ); ?></button>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render tab navigation
     *
     * @return void
     * @since  1.0.0
     * @access private
     *
     */
    private function render_tabs(): void {
        ?>
        <div class="wp-flyout-tabs">
            <nav class="wp-flyout-tab-nav" role="tablist">
                <?php foreach ( $this->tabs as $tab ) : ?>
                    <?php
                    $is_active = ( $tab['id'] === $this->active_tab );
                    $classes   = [ 'wp-flyout-tab' ];
                    if ( $is_active ) {
                        $classes[] = 'active';
                    }
                    ?>
                    <a href="#tab-<?php echo esc_attr( $tab['id'] ); ?>"
                        class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
                        role="tab"
                        data-tab="<?php echo esc_attr( $tab['id'] ); ?>"
                        aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>">
                        <?php echo esc_html( $tab['label'] ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>
        <?php
    }

    /**
     * Render flyout body and footer
     *
     * @return void
     * @since  1.0.0
     * @access private
     *
     */
    private function render_body(): void {
        ?>
        <div class="wp-flyout-body">
            <?php if ( $this->has_tabs() ) : ?>
                <?php $this->render_tabbed_content(); ?>
            <?php else : ?>
                <?php $this->render_single_content(); ?>
            <?php endif; ?>
        </div>

        <?php if ( $this->has_footer() ) : ?>
            <div class="wp-flyout-footer">
                <?php echo $this->footer; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </div>
        <?php endif; ?>
        <?php
    }

    /**
     * Render tabbed content panels
     *
     * @return void
     * @since  1.0.0
     * @access private
     *
     */
    private function render_tabbed_content(): void {
        foreach ( $this->tabs as $tab ) {
            $is_active = ( $tab['id'] === $this->active_tab );
            $classes   = [ 'wp-flyout-tab-content' ];
            if ( $is_active ) {
                $classes[] = 'active';
            }
            ?>
            <div id="tab-<?php echo esc_attr( $tab['id'] ); ?>"
                class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
                role="tabpanel">
                <?php echo $this->content[ $tab['id'] ] ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </div>
            <?php
        }
    }

    /**
     * Render single content panel (no tabs)
     *
     * @return void
     * @since  1.0.0
     * @access private
     *
     */
    private function render_single_content(): void {
        echo $this->content['main'] ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
}