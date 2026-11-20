<?php
/**
 * The Header's Parts
 *
 * @package SugarCommerce\Flyouts
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts\Traits\Flyout;

use SugarCommerce\Flyouts\Identity;
use SugarCommerce\Flyouts\Manager;

/**
 * What a flyout's header says: its title, subtitle, eyebrow, badge, tags,
 * menu and the identity it is about.
 */
trait Header {

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
}
