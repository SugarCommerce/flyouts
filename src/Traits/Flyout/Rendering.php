<?php
/**
 * Rendering
 *
 * @package SugarCommerce\Flyouts
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts\Traits\Flyout;

use SugarCommerce\Flyouts\Identity;
use SugarCommerce\Flyouts\Manager;
use SugarCommerce\Flyouts\Utils\Icon;

/**
 * Drawing a flyout: its header and menu, its tabs, and the body inside
 * them.
 */
trait Rendering {

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
