<?php
/**
 * The Header
 *
 * @package SugarCommerce\Flyouts
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts\Traits\Manager;

use SugarCommerce\Flyouts\Flyout;
use SugarCommerce\Text\Display\Color;

/**
 * Dressing a flyout's header: its title and subtitle, steps, tags and the
 * header menu.
 */
trait Header {

	/**
	 * Give a panel the header that says who or what it is about.
	 *
	 * `header` is the opt-in, and a panel without one is left exactly as it
	 * was. With one -- even an empty array -- the title and badge keep to
	 * one line, the menu comes before previous and next, and those two are
	 * drawn quieter than the menu and the close button. Its keys:
	 *
	 * - `avatar`: a picture before the title. A URL, or `image`, `name` (its
	 *   initials stand in for a missing picture) and `icon` (a dashicon's
	 *   name or a fields icon such as `heroicons-outline:user`, when there is
	 *   no name either). `initials` gives the letters outright, and
	 *   `background` with `color` (six-digit hex) draws them as a monogram;
	 * - `meta`: a list of quiet facts after the subtitle, each a `value` with
	 *   an optional `image`, `icon` and `title`;
	 * - `copy`: `subtitle` for a copy button beside the subtitle;
	 * - `steps`: how the record before and the record after are reached.
	 *   True, the default, is the two arrows; false is nothing at all;
	 *   `keys` is no arrows and the keyboard instead (J and K, and the left
	 *   and right arrow keys when focus is not somewhere that uses them);
	 *   `both` is the arrows and the keys.
	 *
	 * `avatar`, `meta` and `copy` may each be a callback handed the record,
	 * as the title may -- so a record with nothing worth copying under its
	 * name has no button offering to.
	 *
	 * And one thing about the foot of the panel, for a record that is read
	 * far more often than it is changed: `footer_when => 'editing'` keeps
	 * Save and Cancel out of sight until the form has a change in it or a
	 * folded card has been opened.
	 *
	 * @param Flyout               $flyout The panel being built.
	 * @param array<string, mixed> $config Flyout configuration.
	 * @param mixed                $data   Whatever `load` returned.
	 * @param mixed                $id     The record's id, if it has one.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function dress_header( Flyout $flyout, array $config, $data, $id ): void {
		if ( 'editing' === ( $config['footer_when'] ?? '' ) ) {
			$flyout->add_class( 'wp-flyout-footer-when-editing' );
		}

		$header = $config['header'] ?? null;

		if ( ! is_array( $header ) ) {
			return;
		}

		$flyout->set_identity(
			[
				'avatar' => $this->resolve( $header['avatar'] ?? [], $data, $id ),
				'meta'   => $this->resolve( $header['meta'] ?? [], $data, $id ),
				'copy'   => $this->resolve( $header['copy'] ?? '', $data, $id ),
			]
		);

		$steps = self::steps( $header );

		if ( ! $steps['buttons'] ) {
			$flyout->add_class( 'wp-flyout-no-steps' );
		}

		if ( $steps['keys'] ) {
			$flyout->add_class( 'wp-flyout-step-keys' );
		}
	}

	/**
	 * What a header's `steps` asks for: the arrows, the keys, both or neither.
	 *
	 * Anything this does not know is read as the default, the arrows alone,
	 * which is what every panel had before there was a choice.
	 *
	 * @param array<string, mixed> $header A panel's `header`.
	 *
	 * @return array{buttons: bool, keys: bool, mode: string} `mode` is what a
	 *         trigger carries so the placeholder can do the same: empty for
	 *         the default, or `0`, `keys` or `both`.
	 * @since 1.0.0
	 */
	public static function steps( array $header ): array {
		$steps = $header['steps'] ?? true;

		if ( false === $steps ) {
			return [
				'buttons' => false,
				'keys'    => false,
				'mode'    => '0',
			];
		}

		if ( 'keys' === $steps || 'both' === $steps ) {
			return [
				'buttons' => 'both' === $steps,
				'keys'    => true,
				'mode'    => $steps,
			];
		}

		return [
			'buttons' => true,
			'keys'    => false,
			'mode'    => '',
		];
	}

	/**
	 * A resolved value as the text it will be shown as.
	 *
	 * A callback can return anything. A number is its digits and null is
	 * nothing; an array or an object has no text, and is nothing too rather
	 * than a TypeError in a string-typed setter.
	 *
	 * @param mixed $value A resolved configuration value.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	private static function text( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * A panel's `tags`, as the markup that stands beside its badge.
	 *
	 * Markup a plugin drew itself -- its own tag chips, each with the colour
	 * somebody chose -- is printed as it is; it comes from the panel's
	 * configuration or its `load`, never from a request. A list is drawn
	 * here, each item a label, or a `label` with a `tone` (as the badge
	 * takes) or a `background` and `color` of its own (six-digit hex) and
	 * an optional `title` for hover.
	 *
	 * @param mixed $tags A string of markup, or a list.
	 *
	 * @return string Markup; empty for none.
	 * @since 1.0.0
	 */
	public static function tags( $tags ): string {
		if ( is_string( $tags ) ) {
			return trim( $tags );
		}

		if ( ! is_array( $tags ) ) {
			return '';
		}

		$html = '';

		foreach ( $tags as $tag ) {
			$tag   = is_array( $tag ) ? $tag : [ 'label' => $tag ];
			$label = trim( self::text( $tag['label'] ?? '' ) );

			if ( '' === $label ) {
				continue;
			}

			$tone       = self::text( $tag['tone'] ?? 'neutral' );
			$tone       = in_array( $tone, Flyout::VALID_TONES, true ) ? $tone : 'neutral';
			$background = Color::hex( $tag['background'] ?? '' );
			$color      = Color::hex( $tag['color'] ?? '' );
			$title      = trim( self::text( $tag['title'] ?? '' ) );

			// Both or neither, as an avatar's monogram takes them: a
			// background with the badge's own text colour may not be read.
			$style = '' !== $background && '' !== $color
				? sprintf( ' style="background:%s;color:%s"', esc_attr( $background ), esc_attr( $color ) )
				: '';

			$html .= sprintf(
				'<span class="wp-flyout-badge wp-flyout-badge--%1$s wp-flyout-tag"%2$s%3$s>%4$s</span>',
				esc_attr( $tone ),
				$style,
				'' === $title ? '' : ' title="' . esc_attr( $title ) . '"',
				esc_html( $label )
			);
		}

		return $html;
	}

	/**
	 * The header menu for one record.
	 *
	 * `menu` is a list of items, or a callback handed the record and
	 * returning one -- so a record that cannot be deleted has no Delete,
	 * and a new one has no "View on site". Each item is a `label` and one
	 * of:
	 *
	 * - `url`, with an optional `target`: a link;
	 * - `action` and `callback`: a key the REST endpoint runs the callback
	 *   for, the same way it runs a component's action;
	 * - `flyout` and `id`: another of this manager's panels, opened where
	 *   this one is -- "View order" on a licence. It asks before throwing
	 *   away anything typed, as every other way of opening a record does.
	 *
	 * And optionally `danger`, a `confirm` question asked before an action
	 * runs, and a `capability` -- the flyout's own when it names none.
	 *
	 * Anything else is dropped here, once, so the header that draws the
	 * menu and the endpoint that runs it are reading the same list. The
	 * capability is filled in but not enforced: drawing leaves out what
	 * the person may not do, and the endpoint refuses it, and those are
	 * different answers to give.
	 *
	 * @param array<string, mixed> $config Flyout configuration.
	 * @param mixed                $data   Whatever `load` returned.
	 * @param mixed                $id     The record's id, if it has one.
	 *
	 * @return array<int, array<string, mixed>>
	 * @since 1.0.0
	 */
	public function menu_items( array $config, $data, $id = null ): array {
		$menu  = $this->resolve( $config['menu'] ?? [], $data, $id );
		$items = [];

		foreach ( is_array( $menu ) ? $menu : [] as $item ) {
			if ( ! is_array( $item ) || '' === self::text( $item['label'] ?? '' ) ) {
				continue;
			}

			$is_link   = '' !== self::text( $item['url'] ?? '' );
			$is_action = '' !== self::text( $item['action'] ?? '' ) && is_callable( $item['callback'] ?? null );
			$opens     = self::text( $item['flyout'] ?? '' );
			$is_panel  = ! $is_link && ! $is_action && '' !== $opens && isset( $this->flyouts[ $opens ] );

			if ( ! $is_link && ! $is_action && ! $is_panel ) {
				continue;
			}

			// Drawn only for somebody who may open that panel, and as the
			// same trigger a row of a list is.
			if ( $is_panel ) {
				if ( ! $this->can_access( $opens ) ) {
					continue;
				}

				$item['trigger'] = $this->build_trigger_attributes( $opens, [ 'id' => self::text( $item['id'] ?? '' ) ], 'wp-flyout-menu-item' );
			}

			$item['capability'] = (string) ( $item['capability'] ?? $config['capability'] ?? 'manage_options' );

			// The key as the route will receive it, so the two can be
			// compared: the route's argument is run through sanitize_key().
			if ( $is_action ) {
				$item['action'] = sanitize_key( (string) $item['action'] );
			}

			$items[] = $item;
		}

		return $items;
	}
}
