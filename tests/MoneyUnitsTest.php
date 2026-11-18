<?php
/**
 * Amounts in minor units, when the panel says so.
 *
 * @package SugarCommerce\Flyouts
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts\Tests;

use SugarCommerce\Flyouts\Components\LineItems;
use SugarCommerce\Flyouts\Components\PriceSummary;
use SugarCommerce\Flyouts\Components\RefundForm;
use SugarCommerce\Flyouts\Manager;
use SugarCommerce\Flyouts\Utils\Amount;
use PHPUnit\Framework\TestCase;

/**
 * `money => 'minor'`, on a field or on the whole panel.
 *
 * A store keeps 14800 for $148.00, and handing the components major units
 * meant dividing on the way in for the library to multiply straight back --
 * and a component handed the minor figure by mistake renders it a hundred
 * times too large and errors nowhere. So a caller with minor units says so
 * once and hands them over as they are.
 */
final class MoneyUnitsTest extends TestCase {

	/**
	 * A clean hook registry and every capability.
	 */
	protected function setUp(): void {
		$GLOBALS['fk_filters'] = [];
		$GLOBALS['wf_caps']    = null;
	}

	/**
	 * In minor units an amount is already what is wanted -- made whole,
	 * and never scaled by the currency.
	 */
	public function test_minor_amounts_are_taken_as_they_are(): void {
		$this->assertSame( 14800, Amount::minor( 14800, 'USD', Amount::MINOR ) );
		$this->assertSame( 14800, Amount::minor( '14800', 'USD', Amount::MINOR ) );
		$this->assertSame( 5000, Amount::minor( 5000, 'JPY', Amount::MINOR ) );
		$this->assertSame( 1500, Amount::minor( 1500, 'KWD', Amount::MINOR ) );
		$this->assertSame( 14800, Amount::minor( 14799.6, 'USD', Amount::MINOR ) );
		$this->assertSame( -1480, Amount::minor( -1480, 'USD', Amount::MINOR ) );
		$this->assertSame( 0, Amount::minor( 'free', 'USD', Amount::MINOR ) );

		// And the default is still major.
		$this->assertSame( 14800, Amount::minor( 148, 'USD' ) );
	}

	/**
	 * Only `minor` is minor; anything else is the default.
	 */
	public function test_the_unit_is_minor_only_when_it_says_so(): void {
		$this->assertSame( Amount::MINOR, Amount::unit( 'minor' ) );
		$this->assertSame( Amount::MAJOR, Amount::unit( 'major' ) );
		$this->assertSame( Amount::MAJOR, Amount::unit( 'MINOR' ) );
		$this->assertSame( Amount::MAJOR, Amount::unit( null ) );
		$this->assertSame( Amount::MAJOR, Amount::unit( '' ) );
	}

	/**
	 * A minor amount as the decimal an input shows.
	 */
	public function test_a_minor_amount_as_a_decimal(): void {
		$this->assertSame( '148.00', Amount::decimal( 14800, 'USD' ) );
		$this->assertSame( '5000', Amount::decimal( 5000, 'JPY' ) );
		$this->assertSame( '1.500', Amount::decimal( 1500, 'KWD' ) );
	}

	/**
	 * A price summary in minor units draws exactly what it draws in major.
	 */
	public function test_a_price_summary_in_minor_units_matches_major(): void {
		$major = ( new PriceSummary(
			[
				'subtotal' => 148.00,
				'discount' => 14.80,
				'tax'      => 26.64,
				'total'    => 159.84,
				'refunded' => 59.84,
				'currency' => 'USD',
			]
		) )->render();

		$minor = ( new PriceSummary(
			[
				'subtotal' => 14800,
				'discount' => 1480,
				'tax'      => 2664,
				'total'    => 15984,
				'refunded' => 5984,
				'currency' => 'USD',
				'money'    => 'minor',
			]
		) )->render();

		$this->assertSame( $major, $minor );
		$this->assertStringContainsString( '$148.00', $minor );
		$this->assertStringNotContainsString( '$14,800.00', $minor );
	}

	/**
	 * A refund form in minor units draws exactly what it draws in major.
	 */
	public function test_a_refund_form_in_minor_units_matches_major(): void {
		$major = ( new RefundForm(
			[
				'amount_paid'     => 159.84,
				'amount_refunded' => 59.84,
				'currency'        => 'USD',
			]
		) )->render();

		$minor = ( new RefundForm(
			[
				'amount_paid'     => 15984,
				'amount_refunded' => 5984,
				'currency'        => 'USD',
				'money'           => 'minor',
			]
		) )->render();

		$this->assertSame( $major, $minor );
		$this->assertStringContainsString( 'data-refundable="10000"', $minor );
		$this->assertStringContainsString( 'value="100.00"', $minor );

		// And fully refunded is fully refunded in either unit.
		$this->assertStringContainsString(
			'is-fully-refunded',
			( new RefundForm(
				[
					'amount_paid'     => 15984,
					'amount_refunded' => 15984,
					'currency'        => 'USD',
					'money'           => 'minor',
				]
			) )->render()
		);
	}

	/**
	 * Line items in minor units show the same figures, and tell the script
	 * which unit their prices are in.
	 */
	public function test_line_items_in_minor_units(): void {
		$minor = ( new LineItems(
			[
				'items'    => [
					[
						'id'       => 1,
						'name'     => 'A thing',
						'quantity' => 2,
						'price'    => 9999,
					],
				],
				'currency' => 'USD',
				'money'    => 'minor',
			]
		) )->render();

		$this->assertStringContainsString( '$99.99', $minor );
		$this->assertStringContainsString( '$199.98', $minor );
		$this->assertStringContainsString( 'data-money="minor"', $minor );

		// What the script reads and what the form posts back are the unit
		// the rows were given in.
		$this->assertStringContainsString( 'data-price="9999"', $minor );
		$this->assertMatchesRegularExpression( '/name="line_items\[0\]\[price\]"\s+value="9999"/', $minor );

		$major = ( new LineItems(
			[
				'items'    => [
					[
						'id'    => 1,
						'name'  => 'A thing',
						'price' => 99.99,
					],
				],
				'currency' => 'USD',
			]
		) )->render();

		$this->assertStringContainsString( 'data-money="major"', $major );
	}

	/**
	 * The total is exact: it was a sum of prices cast to whole major units,
	 * which dropped the cents from every row.
	 */
	public function test_the_line_items_total_keeps_its_cents(): void {
		$markup = ( new LineItems(
			[
				'items'    => [
					[
						'id'    => 1,
						'name'  => 'One',
						'price' => 99.99,
					],
					[
						'id'    => 2,
						'name'  => 'Two',
						'price' => 99.99,
					],
				],
				'currency' => 'USD',
			]
		) )->render();

		$this->assertMatchesRegularExpression( '/class="total-amount" data-value="199.98">\s*\$199.98/', $markup );
	}

	/**
	 * Build a panel with one of each money component and render it.
	 *
	 * @param array<string, mixed> $panel  Panel configuration.
	 * @param array<string, mixed> $fields Extra per-field configuration.
	 *
	 * @return string
	 */
	private function panel( array $panel, array $fields = [] ): string {
		$manager = new Manager( 'money_panel' );

		return $manager->build_flyout(
			$panel + [
				'id'     => 'panel',
				'title'  => 'Order',
				'fields' => [
					'summary' => ( $fields['summary'] ?? [] ) + [
						'type'     => 'price_summary',
						'total'    => 14800,
						'currency' => 'USD',
					],
					'refund'  => ( $fields['refund'] ?? [] ) + [
						'type'        => 'refund_form',
						'amount_paid' => 14800,
						'currency'    => 'USD',
					],
				],
			],
			null
		)->render();
	}

	/**
	 * The panel says it once, and every money component on it hears.
	 */
	public function test_a_panel_wide_unit_reaches_every_money_component(): void {
		$html = $this->panel( [ 'money' => 'minor' ] );

		$this->assertStringContainsString( '$148.00', $html );
		$this->assertStringNotContainsString( '$14,800.00', $html );
		$this->assertStringContainsString( 'data-paid="14800"', $html );

		// Without it, the same figures are major units, as they always were.
		$this->assertStringContainsString( '$14,800.00', $this->panel( [] ) );
	}

	/**
	 * A field that names its own unit keeps it.
	 */
	public function test_a_field_unit_wins_over_the_panels(): void {
		$html = $this->panel(
			[ 'money' => 'minor' ],
			[
				'summary' => [
					'money' => 'major',
					'total' => 25.50,
				],
			]
		);

		$this->assertStringContainsString( '$25.50', $html );
		$this->assertStringContainsString( 'data-paid="14800"', $html );
	}
}
