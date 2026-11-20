<?php
/**
 * Amount Normalisation
 *
 * @package     SugarCommerce\Flyouts
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts\Utils;

use SugarCommerce\Money\Currencies;
use SugarCommerce\Money\Money;

/**
 * Class Amount
 *
 * Turns a caller's amount into the minor units `format_money()` wants.
 *
 * A component that shows money takes its amounts in one of two units, and
 * which is a stated choice rather than a guess:
 *
 * - **major units**, the default -- 148.00 is one hundred and forty-eight
 *   dollars, 5 is five dollars;
 * - **minor units**, with `money => 'minor'` on the field or on the panel --
 *   14800 is one hundred and forty-eight dollars. This is what a store keeps
 *   in its database and what sugarcommerce/money works in, so a caller that has minor
 *   units hands them over as they are. Converting them to major units for
 *   the panel to convert straight back was a round trip with a footgun at
 *   each end: handing the minor figure to a major-unit component renders it
 *   a hundred times too large, and errors nowhere.
 *
 * What this replaced inferred the unit from the value itself:
 *
 *     fmod( $amount, 1.0 ) !== 0.0 ? $amount * 100 : $amount
 *
 * -- a fractional value was major units, a whole number was already minor.
 * So a $148.00 subtotal rendered as $1.48 while the 14.80 discount on the
 * line below it rendered correctly, and any round amount was silently
 * divided by a hundred: $50.00 showed as $0.50. The rule cannot be made to
 * work, because nothing distinguishes 148 meaning $148.00 from 148 meaning
 * $1.48 -- and round amounts are exactly the ones a person types. Which is
 * why the unit is declared and never inferred.
 *
 * It also hard-coded two decimal places, which is wrong for the ~20% of
 * ISO-4217 currencies that do not have them: yen has none, Kuwaiti dinar
 * has three. The exponent comes from the currency here.
 */
final class Amount {

	/**
	 * Amounts like 148.00: the default.
	 *
	 * @since 1.0.0
	 */
	public const string MAJOR = 'major';

	/**
	 * Amounts like 14800: what a store keeps.
	 *
	 * @since 1.0.0
	 */
	public const string MINOR = 'minor';

	/**
	 * The unit a configuration names, as one of the two.
	 *
	 * Anything that is not `minor` is major units, which is what every
	 * component took before there was a choice.
	 *
	 * @param mixed $unit What a field or a panel said under `money`.
	 *
	 * @return string self::MAJOR or self::MINOR.
	 * @since 1.0.0
	 */
	public static function unit( $unit ): string {
		return self::MINOR === $unit ? self::MINOR : self::MAJOR;
	}

	/**
	 * Convert an amount to minor units.
	 *
	 * In major units it is rounded to the currency's own exponent before
	 * parsing, for two reasons. Money::parse() truncates a longer fraction,
	 * so 14.999 would become 1499 rather than 1500; and number_format()
	 * emits fixed notation, so a very large float never reaches parse() as
	 * "1.0e+25", where everything that is not a digit or separator is
	 * stripped and it would read as 1025.
	 *
	 * In minor units it is already what is wanted, and is only made a whole
	 * number: a minor unit has no fraction, and a half cent from a careless
	 * division rounds rather than truncating.
	 *
	 * @param mixed  $amount   The amount.
	 * @param string $currency ISO-4217 code.
	 * @param string $unit     self::MAJOR (the default) or self::MINOR.
	 *
	 * @return int Amount in the smallest unit of $currency.
	 */
	public static function minor( $amount, string $currency, string $unit = self::MAJOR ): int {
		if ( ! is_numeric( $amount ) ) {
			return 0;
		}

		if ( self::MINOR === $unit ) {
			return (int) round( (float) $amount );
		}

		$decimals = Currencies::decimals( $currency );

		return Money::parse( number_format( (float) $amount, $decimals, '.', '' ), $currency );
	}

	/**
	 * A minor-unit amount as the major-unit decimal an input or a script
	 * reads: 14800 USD is "148.00", 5000 JPY is "5000", 1500 KWD is "1.500".
	 *
	 * @param int    $amount   Amount in minor units.
	 * @param string $currency ISO-4217 code.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public static function decimal( int $amount, string $currency ): string {
		$decimals = Currencies::decimals( $currency );

		return number_format( $amount / ( 10 ** $decimals ), $decimals, '.', '' );
	}
}
