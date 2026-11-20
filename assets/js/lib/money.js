/**
 * Money in the components that show it: the line items' totals and the
 * refund form's button.
 *
 * The locale comes from the page rather than being hard-coded to en-US,
 * and the number of decimal places from the currency rather than being
 * assumed to be two -- yen has none, Kuwaiti dinar has three.
 */

/**
 * A currency formatter, and the factor that turns its major units into
 * whole minor ones.
 *
 * @param {string}  currency An ISO 4217 code.
 * @param {string}  [locale] A BCP 47 locale; the browser's when not given.
 * @param {boolean} [minor]  Whether amounts are already in minor units.
 * @return {{format: Intl.NumberFormat, factor: number, minor: boolean}} The formatter.
 */
export function formatter( currency, locale, minor = false ) {
	let format;

	try {
		format = new Intl.NumberFormat( locale || undefined, {
			style: 'currency',
			currency: currency || 'USD',
		} );
	} catch {
		// An unknown currency code throws rather than degrading, and it
		// would take the whole panel's scripting with it.
		format = new Intl.NumberFormat( locale || undefined );
	}

	return {
		format,
		factor: Math.pow(
			10,
			format.resolvedOptions().maximumFractionDigits || 0
		),
		minor: !! minor,
	};
}

/**
 * An amount in whole minor units, from the unit it is kept in.
 *
 * @param {number} amount The amount.
 * @param {Object} money  formatter()'s.
 * @return {number} Minor units.
 */
export const toMinor = ( amount, money ) =>
	money.minor ? Math.round( amount ) : Math.round( amount * money.factor );

/**
 * Minor units, said as money.
 *
 * @param {number} minor Minor units.
 * @param {Object} money formatter()'s.
 * @return {string} The amount.
 */
export const say = ( minor, money ) =>
	money.format.format( minor / money.factor );
