<?php
/**
 * Icons, in the two forms a caller may give one
 *
 * @package     SugarCommerce\Flyouts
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts\Utils;

use SugarCommerce\Fields\Icons\Icons;

/**
 * Every icon this library prints goes through here.
 *
 * An `icon` a caller hands a panel -- an avatar's, an event type's, a
 * stat's, an empty state's -- may be an icon from the fields package's sets
 * (`heroicons-outline:shopping-cart`), drawn as inline SVG, or a bare
 * Dashicons name (`cart`, or the whole class `dashicons-cart`), drawn as
 * the font's span it always was. Either way the element carries the fields package's
 * `sugarcommerce-icon` class, which is what the stylesheet sizes.
 *
 * The library's own glyphs -- close, the menu, previous and next, the
 * accordion's chevron, an alert's mark -- are Heroicons, named once here.
 * The script draws some of the same ones, and is handed them already drawn
 * (see markup()), so there is no second copy of a path in JavaScript.
 *
 * @since 1.0.0
 */
final class Icon {

	/**
	 * The library's own icons, by what they are for.
	 *
	 * @var array<string, string>
	 */
	public const DEFAULTS = [
		'close'    => 'heroicons-outline:x-mark',
		'menu'     => 'heroicons-outline:ellipsis-horizontal',
		'previous' => 'heroicons-outline:chevron-left',
		'next'     => 'heroicons-outline:chevron-right',
		'chevron'  => 'heroicons-outline:chevron-down',
		'copy'     => 'heroicons-outline:document-duplicate',
		'copied'   => 'heroicons-outline:check',
		'empty'    => 'heroicons-outline:inbox',
		'event'    => 'heroicons-outline:clock',
		'success'  => 'heroicons-outline:check-circle',
		'error'    => 'heroicons-outline:x-circle',
		'warning'  => 'heroicons-outline:exclamation-triangle',
		'info'     => 'heroicons-outline:information-circle',
		'up'       => 'heroicons-outline:arrow-up',
		'down'     => 'heroicons-outline:arrow-down',
		'card'     => 'heroicons-outline:credit-card',
		'cart'     => 'heroicons-outline:shopping-cart',
		'image'    => 'heroicons-outline:photo',
		'trash'    => 'heroicons-outline:trash',
		'add'      => 'heroicons-outline:plus',
		'change'   => 'heroicons-outline:arrow-path',
	];

	/**
	 * An icon a caller gave, ready to print.
	 *
	 * @param mixed                $value A `set:name` value or a Dashicons name.
	 * @param array<string, mixed> $args  `size`, `class`, `style`, `label`.
	 *
	 * @return string Safe markup, or '' when the value is neither.
	 * @since 1.0.0
	 */
	public static function render( $value, array $args = [] ): string {
		return Icons::render( $value, $args );
	}

	/**
	 * One of the library's own icons.
	 *
	 * @param string               $key  A key of DEFAULTS.
	 * @param array<string, mixed> $args As render().
	 *
	 * @return string Safe markup, or '' for a key there is no icon for.
	 * @since 1.0.0
	 */
	public static function get( string $key, array $args = [] ): string {
		return isset( self::DEFAULTS[ $key ] ) ? self::render( self::DEFAULTS[ $key ], $args ) : '';
	}

	/**
	 * The library's own icons, drawn, for the script.
	 *
	 * Published beside the REST URL. Each is the fields package's cleaned SVG, so the
	 * script inserts it as it is.
	 *
	 * @return array<string, string> Key => markup.
	 * @since 1.0.0
	 */
	public static function markup(): array {
		$drawn = [];

		foreach ( array_keys( self::DEFAULTS ) as $key ) {
			$drawn[ $key ] = self::get( $key );
		}

		return $drawn;
	}
}
