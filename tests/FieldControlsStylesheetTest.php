<?php
/**
 * How the panel's stylesheet treats controls it did not draw.
 *
 * @package SugarCommerce\Flyouts
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The panel restyles the fields package's controls and core's, and twice that went
 * wrong in ways no markup test can see.
 *
 * A price showed "£" at one end of its box and the figure an inch away
 * until it was clicked: the panel's rule taking the fields package's room for the
 * symbol back tied with the fields package's in weight, so the stylesheet that loaded
 * last won, and only the rule's `:focus` copy -- one class heavier -- ever
 * beat the fields package's for certain. It was `padding-left`, too, which in a
 * right-to-left admin is the wrong side.
 *
 * And core's colour picker came out as "Select Color" squashed into a
 * rounded box, because the panel's rule for every `.button` reached its
 * swatch.
 */
final class FieldControlsStylesheetTest extends TestCase {

	/**
	 * The weight of the fields package's heaviest unfocused rule for the money input:
	 * `.wp-core-ui .field-kit__money--wide input.field-kit__money-value`.
	 */
	private const KIT_MONEY = [ 0, 3, 1 ];

	/**
	 * The panel's stylesheet.
	 *
	 * @return string
	 */
	private static function css(): string {
		return (string) file_get_contents( dirname( __DIR__ ) . '/assets/css/wp-flyout.css' );
	}

	/**
	 * Every rule: selectors and body.
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	private static function rules(): array {
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', self::css() );

		preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $matches, PREG_SET_ORDER );

		return array_map( static fn( $match ) => [ trim( $match[1] ), $match[2] ], $matches );
	}

	/**
	 * A selector's specificity, as [ ids, classes, elements ].
	 *
	 * Enough of the algorithm for this stylesheet: `:where()` weighs
	 * nothing, `:not()` weighs what it holds, a pseudo-element is an
	 * element.
	 *
	 * @param string $selector One selector.
	 *
	 * @return array{0: int, 1: int, 2: int}
	 */
	private static function specificity( string $selector ): array {
		$selector = (string) preg_replace( '/:where\([^)]*\)/', '', $selector );
		$selector = (string) preg_replace( '/:not\(([^)]*)\)/', ' $1', $selector );
		$selector = (string) preg_replace( '/\[[^\]]*\]/', '.attr', $selector );

		$ids      = preg_match_all( '/#[\w-]+/', $selector );
		$classes  = preg_match_all( '/\.[\w-]+/', $selector ) + preg_match_all( '/(?<!:):(?!:)[\w-]+/', $selector );
		$elements = preg_match_all( '/(?:^|[\s>+~])([a-z][\w-]*)/i', $selector ) + preg_match_all( '/::[\w-]+/', $selector );

		return [ (int) $ids, (int) $classes, (int) $elements ];
	}

	/**
	 * The panel's rule for the figure beside a currency sign out-ranks the
	 * fields package's, focused or not, and is written for either direction.
	 */
	public function test_a_price_starts_beside_its_sign_whatever_loads_last(): void {
		$found = false;

		foreach ( self::rules() as [ $selectors, $body ] ) {
			if ( ! str_contains( $selectors, 'field-kit__money-value' ) || ! preg_match( '/padding-inline-start\s*:/', $body ) ) {
				continue;
			}

			foreach ( explode( ',', $selectors ) as $selector ) {
				if ( ! str_contains( $selector, 'field-kit__money-value' ) || str_contains( $selector, ':focus' ) ) {
					continue;
				}

				$found = true;

				$this->assertGreaterThan(
					self::KIT_MONEY,
					self::specificity( trim( $selector ) ),
					trim( $selector ) . ' does not out-rank the fields package, so the stylesheet loaded last decides.'
				);
			}
		}

		$this->assertTrue( $found, 'No unfocused rule takes back the fields package\'s room for the symbol.' );
	}

	/**
	 * No physical padding on the sign or the figure: in a right-to-left
	 * admin it lands on the wrong side.
	 */
	public function test_the_sign_and_figure_have_no_left_or_right(): void {
		foreach ( self::rules() as [ $selectors, $body ] ) {
			if ( ! preg_match( '/field-kit__money-(value|symbol)/', $selectors ) ) {
				continue;
			}

			$this->assertDoesNotMatchRegularExpression( '/padding-(left|right)\s*:|padding\s*:\s*\S+\s+\S+\s+\S+\s+[1-9]/', $body, $selectors );
		}
	}

	/**
	 * Core's colour picker keeps core's look: the panel's rules for buttons,
	 * text inputs and labels leave its swatch, Clear, hex box and wrapper
	 * alone -- without the exclusion adding weight to those rules.
	 */
	public function test_cores_colour_picker_is_left_alone(): void {
		$css = self::css();

		preg_match_all( '/\.wp-flyout-body \.button:not\(\.button-primary\)[^{\n]*/', $css, $buttons );
		$this->assertNotEmpty( $buttons[0] );

		foreach ( $buttons[0] as $selector ) {
			$this->assertStringContainsString( ':not(:where(.wp-color-result, .wp-picker-clear))', $selector );
		}

		preg_match_all( '/\.wp-flyout-body input\[type="text"\][^,{\n]*/', $css, $inputs );
		$this->assertNotEmpty( $inputs[0] );

		foreach ( $inputs[0] as $selector ) {
			$this->assertStringContainsString( ':not(:where(.wp-color-picker))', $selector );
		}

		$this->assertStringNotContainsString( ".wp-flyout-field label {\n", $css );
		$this->assertStringContainsString( '.wp-flyout-field label:not(:where(.wp-picker-input-wrap))', $css );

		// And the exclusion weighs nothing.
		$this->assertSame( [ 0, 2, 1 ], self::specificity( '.wp-flyout-body input[type="text"]:not(:where(.wp-color-picker))' ) );
	}

}
