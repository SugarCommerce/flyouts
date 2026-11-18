<?php
/**
 * The identity a panel's header can carry
 *
 * @package     SugarCommerce\Flyouts
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts;

use SugarCommerce\Flyouts\Utils\Icon;
use SugarCommerce\Text\Display\Color;

/**
 * Class Identity
 *
 * Who or what a panel is about, drawn in its header: a picture, and a few
 * quiet facts after the subtitle.
 *
 * A panel used to say this twice. The header named the record, and the first
 * card under it named it again with a picture, an address and a flag. The
 * header is where the eye starts and it does not scroll away, so that is
 * where the picture goes, and the card can be left out.
 *
 * Everything here is opt-in: a panel that declares no `header` is drawn as it
 * always was. One that does gets, in order, an avatar, its title and badge on
 * one line, its subtitle with an optional copy button, and its meta -- and at
 * the far end the menu, the previous and next arrows, and close.
 *
 * This class only shapes and draws the parts. Flyout decides where they go.
 *
 * @since 1.0.0
 */
final class Identity {

	/**
	 * What a panel's `header` came to, in one shape.
	 *
	 * `avatar` may be a URL on its own, or `image`, `name` and `icon`: the
	 * picture, whose initials stand in when there is none, and the icon
	 * that stands in when there is no name either. A panel that has worked
	 * the letters out for itself hands them over as `initials`, and one with
	 * a colour for each person adds `background` and `color` -- the tile and
	 * the letters, as six-digit hex -- for a monogram in place of the plain
	 * grey one. `icon` is a dashicon's name (`category`) or an icon from the
	 * fields package's sets (`heroicons-outline:shopping-cart`), and takes the
	 * same `background` and `color`. `meta` is a list of items,
	 * each a `value` with an optional `image`, `icon` (either form) and `title`. `copy` is
	 * `subtitle` to put a copy button beside the subtitle.
	 *
	 * @param array<string, mixed> $header The resolved `header` configuration.
	 *
	 * @return array{avatar: array{image: string, name: string, icon: string, initials: string, background: string, color: string}, meta: array<int, array<string, string>>, copy: string}
	 * @since 1.0.0
	 */
	public static function normalize( array $header ): array {
		$avatar = $header['avatar'] ?? [];

		if ( is_string( $avatar ) ) {
			$avatar = [ 'image' => $avatar ];
		}

		$avatar = is_array( $avatar ) ? $avatar : [];
		$meta   = [];

		foreach ( is_array( $header['meta'] ?? null ) ? $header['meta'] : [] as $item ) {
			if ( ! is_array( $item ) || '' === self::text( $item['value'] ?? '' ) ) {
				continue;
			}

			$meta[] = [
				'value' => self::text( $item['value'] ),
				'image' => self::text( $item['image'] ?? '' ),
				'icon'  => self::text( $item['icon'] ?? '' ),
				'title' => self::text( $item['title'] ?? '' ),
			];
		}

		return [
			'avatar' => [
				'image'      => self::text( $avatar['image'] ?? '' ),
				'name'       => self::text( $avatar['name'] ?? '' ),
				'icon'       => self::text( $avatar['icon'] ?? '' ),
				'initials'   => self::text( $avatar['initials'] ?? '' ),
				// Into a style attribute, so a colour or nothing: a name, a
				// `url()` or anything with a semicolon in it is nothing.
				'background' => Color::hex( $avatar['background'] ?? '' ),
				'color'      => Color::hex( $avatar['color'] ?? '' ),
			],
			'meta'   => $meta,
			'copy'   => 'subtitle' === ( $header['copy'] ?? '' ) ? 'subtitle' : '',
		];
	}

	/**
	 * The picture at the start of the header.
	 *
	 * Forty pixels with a corner a fifth of its width, which is the corner
	 * an avatar has in a list table -- the row somebody clicked and the
	 * panel it opened show the same face in the same shape.
	 *
	 * Decorative either way: the title beside it is the name, and a screen
	 * reader told "Ada Lovelace, image, Ada Lovelace" has learned nothing.
	 *
	 * Initials are the panel's own when it gives them and the name's when
	 * it does not. With a `background` and a `color` they are a monogram:
	 * the tile in one and the letters in the other. Both or neither -- one
	 * colour on its own would be set against a grey it was never chosen
	 * for, and is left out.
	 *
	 * @param array{image: string, name: string, icon: string, initials?: string, background?: string, color?: string} $avatar From normalize().
	 *
	 * @return string Markup, or empty when there is nothing to draw.
	 * @since 1.0.0
	 */
	public static function avatar( array $avatar ): string {
		if ( '' !== $avatar['image'] ) {
			return sprintf(
				'<span class="wp-flyout-avatar"><img src="%s" alt="" width="40" height="40" /></span>',
				esc_url( $avatar['image'] )
			);
		}

		$initials = '' !== ( $avatar['initials'] ?? '' ) ? $avatar['initials'] : self::initials( $avatar['name'] );

		if ( '' !== $initials ) {
			$background = $avatar['background'] ?? '';
			$color      = $avatar['color'] ?? '';
			$tinted     = '' !== $background && '' !== $color;

			return sprintf(
				'<span class="wp-flyout-avatar wp-flyout-avatar--initials%s"%s aria-hidden="true">%s</span>',
				$tinted ? ' wp-flyout-avatar--tinted' : '',
				$tinted ? ' style="' . esc_attr( 'background:' . $background . ';color:' . $color ) . '"' : '',
				esc_html( $initials )
			);
		}

		if ( '' !== $avatar['icon'] ) {
			$background = $avatar['background'] ?? '';
			$color      = $avatar['color'] ?? '';
			$tinted     = '' !== $background && '' !== $color;

			// A fields icon value (a set and a name), or the dashicon name an
			// avatar has always taken.
			$glyph = Icon::render( $avatar['icon'] );

			if ( '' === $glyph ) {
				return '';
			}

			return sprintf(
				'<span class="wp-flyout-avatar wp-flyout-avatar--icon%s"%s aria-hidden="true">%s</span>',
				$tinted ? ' wp-flyout-avatar--tinted' : '',
				$tinted ? ' style="' . esc_attr( 'background:' . $background . ';color:' . $color ) . '"' : '',
				$glyph // Cleaned by the fields package, which draws only an allow-list of SVG.
			);
		}

		return '';
	}

	/**
	 * The first letters of a name: "Ada Lovelace" is AL, "ada@example.com" is A.
	 *
	 * The first and the last word, because a middle name is not what anybody
	 * is known by. An address gives the one letter before its @.
	 *
	 * @param string $name A name, or whatever stands in for one.
	 *
	 * @return string One or two capitals, or empty.
	 * @since 1.0.0
	 */
	public static function initials( string $name ): string {
		$name = trim( (string) preg_replace( '/@.*$/', '', $name ) );

		if ( '' === $name ) {
			return '';
		}

		$words = preg_split( '/[\s._-]+/u', $name, -1, PREG_SPLIT_NO_EMPTY );
		$words = is_array( $words ) ? $words : [];

		if ( [] === $words ) {
			return '';
		}

		$letters = self::first_letter( $words[0] );

		if ( count( $words ) > 1 ) {
			$letters .= self::first_letter( $words[ count( $words ) - 1 ] );
		}

		return $letters;
	}

	/**
	 * The quiet facts after the subtitle.
	 *
	 * @param array<int, array<string, string>> $meta From normalize().
	 *
	 * @return string Markup, or empty when there are none.
	 * @since 1.0.0
	 */
	public static function meta( array $meta ): string {
		$html = '';

		foreach ( $meta as $item ) {
			$mark = '';

			if ( '' !== $item['image'] ) {
				$mark = sprintf(
					'<img class="wp-flyout-meta-image" src="%s" alt="" width="16" height="16" />',
					esc_url( $item['image'] )
				);
			} elseif ( '' !== $item['icon'] ) {
				$mark = Icon::render( $item['icon'] );
			}

			$html .= sprintf(
				'<span class="wp-flyout-meta-item"%s>%s<span class="wp-flyout-meta-value">%s</span></span>',
				'' === $item['title'] ? '' : ' title="' . esc_attr( $item['title'] ) . '"',
				$mark,
				esc_html( $item['value'] )
			);
		}

		return '' === $html ? '' : '<span class="wp-flyout-meta">' . $html . '</span>';
	}

	/**
	 * A button that copies some text.
	 *
	 * The text travels on the button, so the script copies what the server
	 * said and not whatever the element beside it happens to contain.
	 *
	 * @param string $text What to copy.
	 *
	 * @return string Markup, or empty when there is nothing to copy.
	 * @since 1.0.0
	 */
	public static function copy_button( string $text ): string {
		if ( '' === trim( $text ) ) {
			return '';
		}

		return sprintf(
			'<button type="button" class="wp-flyout-copy" data-copy="%1$s" aria-label="%2$s" title="%2$s">%3$s</button>',
			esc_attr( $text ),
			esc_attr__( 'Copy', 'sugarcommerce' ),
			Icon::get( 'copy' )
		);
	}

	/**
	 * A word's first letter, as a capital.
	 *
	 * @param string $word The word.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	private static function first_letter( string $word ): string {
		$letter = function_exists( 'mb_substr' ) ? mb_substr( $word, 0, 1 ) : substr( $word, 0, 1 );

		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $letter ) : strtoupper( $letter );
	}

	/**
	 * A value as the text it will be shown as.
	 *
	 * @param mixed $value Anything.
	 *
	 * @return string Empty for what has no text.
	 * @since 1.0.0
	 */
	private static function text( $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
