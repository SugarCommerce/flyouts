<?php
/**
 * Flyouts
 *
 * @package     SugarCommerce\Flyouts
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts;

/**
 * The way in: register a panel, and print the button or link that opens it.
 *
 * A panel is named `{prefix}_{name}`. The prefix picks the Manager -- one per
 * plugin, created the first time its prefix is seen -- and the name is the
 * panel within it, so `shop_order` is the `order` panel of the `shop`
 * manager.
 *
 * These were global functions -- register_flyout(), get_flyout_button() and
 * the rest. A library several plugins bundle must not declare any: Strauss
 * renames them per plugin, and an unprefixed copy guarded by
 * function_exists() quietly runs whichever plugin loaded first.
 */
final class Flyouts {

	/**
	 * Register a panel.
	 *
	 * @param string               $id     `{prefix}_{name}`.
	 * @param array<string, mixed> $config The panel.
	 *
	 * @return Manager|null The manager it joined, or null for an id with no prefix.
	 * @since 1.0.0
	 */
	public static function register( string $id, array $config = [] ): ?Manager {
		$resolved = Registry::resolve( $id, true );

		if ( ! $resolved ) {
			return null;
		}

		$resolved['manager']->register_flyout( $resolved['flyout_id'], $config );

		return $resolved['manager'];
	}

	/**
	 * The button that opens a panel.
	 *
	 * `text`, `class` and `icon` style the button; every other key is handed
	 * to the panel's `load` as data, `id` above all.
	 *
	 * @param string               $id   `{prefix}_{name}`.
	 * @param array<string, mixed> $args The button and its data.
	 *
	 * @return string Markup, or empty for a panel nobody registered.
	 * @since 1.0.0
	 */
	public static function button( string $id, array $args = [] ): string {
		$resolved = Registry::resolve( $id );

		if ( ! $resolved ) {
			return '';
		}

		$button = array_intersect_key( $args, array_flip( [ 'text', 'class', 'icon' ] ) );

		return $resolved['manager']->get_button( $resolved['flyout_id'], array_diff_key( $args, $button ), $button );
	}

	/**
	 * Print the button that opens a panel.
	 *
	 * @param string               $id   `{prefix}_{name}`.
	 * @param array<string, mixed> $args The button and its data.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function render_button( string $id, array $args = [] ): void {
		// Markup this library assembled and escaped as it built it.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::button( $id, $args );
	}

	/**
	 * A link that opens a panel: a name in a table that opens its record.
	 *
	 * `text`, `class` and `target` style the link; every other key is data.
	 *
	 * @param string               $id   `{prefix}_{name}`.
	 * @param array<string, mixed> $args The link and its data. `text` is required.
	 *
	 * @return string Markup, or empty without text or for a panel nobody registered.
	 * @since 1.0.0
	 */
	public static function link( string $id, array $args = [] ): string {
		$resolved = Registry::resolve( $id );
		$text     = (string) ( $args['text'] ?? '' );

		if ( ! $resolved || '' === $text ) {
			return '';
		}

		$link = array_intersect_key( $args, array_flip( [ 'text', 'class', 'target' ] ) );

		return $resolved['manager']->link( $resolved['flyout_id'], $text, array_diff_key( $args, $link ), $link );
	}

	/**
	 * Print a link that opens a panel.
	 *
	 * @param string               $id   `{prefix}_{name}`.
	 * @param array<string, mixed> $args The link and its data.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function render_link( string $id, array $args = [] ): void {
		// Markup this library assembled and escaped as it built it.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::link( $id, $args );
	}

	/**
	 * The manager behind a prefix.
	 *
	 * @param string $prefix The prefix panels were registered under.
	 *
	 * @return Manager|null
	 * @since 1.0.0
	 */
	public static function manager( string $prefix ): ?Manager {
		return Registry::instance()->get( $prefix );
	}
}
