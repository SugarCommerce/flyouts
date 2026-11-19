<?php
/**
 * Field types that need more than their markup, inside a panel.
 *
 * @package SugarCommerce\Flyouts
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts\Tests;

use ReflectionMethod;
use SugarCommerce\Flyouts\Components;
use SugarCommerce\Flyouts\Manager;
use PHPUnit\Framework\TestCase;

/**
 * A panel's fields are drawn over REST, after the page has loaded, and
 * saved through the panel's own `save`. Each of these worked on a settings
 * page and not in a panel, found by opening every field type in one
 * (tests/browser/fields-in-panels.js):
 *
 * - a code field opened as a bare textarea and a visual editor as a
 *   textarea with an empty toolbar: what they need on the page was never
 *   asked for, so it was never there to start them;
 * - a `tab` or `accordion` field drew nothing, and the panel came out as
 *   one flat list;
 * - an amount's unit was dropped on save, so a flat discount read back as
 *   a percentage.
 */
final class FieldsInPanelsTest extends TestCase {

	/**
	 * What the panels on a manager need on the page.
	 *
	 * @param array<string, mixed> $fields One panel's fields.
	 *
	 * @return array<string, mixed>
	 */
	private function needs( array $fields ): array {
		$manager = new Manager( 'fip_' . substr( md5( serialize( $fields ) ), 0, 8 ) );
		$manager->register_flyout( 'panel', [ 'title' => 'Panel', 'fields' => $fields ] );

		return ( new ReflectionMethod( $manager, 'field_dependencies' ) )->invoke( $manager );
	}

	/**
	 * A code field gets an editor for its own language, nested or not.
	 */
	public function test_a_code_field_gets_its_editor(): void {
		$needs = $this->needs(
			[
				'css'  => [ 'type' => 'code', 'language' => 'text/css' ],
				'look' => [
					'type'   => 'group',
					'fields' => [
						'js' => [ 'type' => 'code', 'language' => 'application/javascript' ],
					],
				],
			]
		);

		$this->assertSame( [ 'text/css', 'application/javascript' ], $needs['code_editors'] );
	}

	/**
	 * A visual editor asks for the editor, and an email editor's body is
	 * one.
	 */
	public function test_a_visual_editor_asks_for_the_editor(): void {
		$this->assertTrue( $this->needs( [ 'body' => [ 'type' => 'wysiwyg' ] ] )['editor'] );
		$this->assertTrue( $this->needs( [ 'receipt' => [ 'type' => 'email_editor' ] ] )['editor'] );
		$this->assertFalse( $this->needs( [ 'name' => [ 'type' => 'text' ] ] )['editor'] );
	}

	/**
	 * Asked per field, so a native colour field loads no Iris and one that
	 * asked for core's picker does; an image in a repeater's rows brings
	 * the media frame.
	 */
	public function test_dependencies_are_asked_per_field_and_nested(): void {
		$this->assertSame( [], $this->needs( [ 'tint' => [ 'type' => 'color' ] ] )['scripts'] );
		$this->assertContains( 'wp-color-picker', $this->needs( [ 'tint' => [ 'type' => 'color', 'picker' => 'wordpress' ] ] )['scripts'] );

		$rows = $this->needs(
			[
				'rows' => [
					'type'   => 'repeater',
					'fields' => [
						'picture' => [ 'type' => 'image' ],
					],
				],
			]
		);

		$this->assertContains( 'media-views', $rows['scripts'] );
	}

	/**
	 * Fields by this library's older names are asked as the fields
	 * package's types, and components are not field types at all.
	 */
	public function test_aliases_and_components_are_read_right(): void {
		$needs = $this->needs(
			[
				'files' => [ 'type' => 'file_manager' ],
				'stats' => [ 'type' => 'stats' ],
			]
		);

		$this->assertContains( 'media-views', $needs['scripts'] );
	}

	/**
	 * Draw one panel.
	 *
	 * @param array<string, mixed> $config The panel.
	 * @param object|null          $data   The record.
	 *
	 * @return string
	 */
	private function render( array $config, ?object $data = null ): string {
		$manager = new Manager( 'fip_render' );

		return $manager->build_flyout( $config + [ 'id' => 'panel' ], $data, 1 )->render();
	}

	/**
	 * Two tab fields divide the fields after them into a tab strip.
	 */
	public function test_tab_fields_draw_tabs(): void {
		$html = $this->render(
			[
				'fields' => [
					'lead'    => [ 'type' => 'text', 'label' => 'Lead' ],
					'tab_one' => [ 'type' => 'tab', 'label' => 'General' ],
					'name'    => [ 'type' => 'text', 'label' => 'Name' ],
					'tab_two' => [ 'type' => 'tab', 'label' => 'Advanced' ],
					'debug'   => [ 'type' => 'toggle', 'label' => 'Debug' ],
				],
			]
		);

		$this->assertSame( 2, substr_count( $html, 'role="tab"' ) );
		$this->assertMatchesRegularExpression( '/role="tab"[^>]*>(?:(?!<\/button>).)*General/s', $html );
		$this->assertMatchesRegularExpression( '/role="tab"[^>]*>(?:(?!<\/button>).)*Advanced/s', $html );

		// Every field is still drawn, the lead ahead of the strip, and the
		// markers themselves draw no field of their own.
		$this->assertLessThan( strpos( $html, 'role="tablist"' ), strpos( $html, 'name="lead"' ) );
		$this->assertStringContainsString( 'name="name"', $html );
		$this->assertStringContainsString( 'name="debug"', $html );
		$this->assertStringNotContainsString( 'field-type-tab', $html );

		// In the tab they follow.
		$this->assertLessThan( strpos( $html, 'name="debug"' ), strpos( $html, 'name="name"' ) );
		$this->assertGreaterThan( strpos( $html, '>Advanced' ), strpos( $html, 'name="debug"' ) );
	}

	/**
	 * Two accordion fields draw core's accordion, the second folded when
	 * it says so.
	 */
	public function test_accordion_fields_draw_an_accordion(): void {
		$html = $this->render(
			[
				'fields' => [
					'acc_one' => [ 'type' => 'accordion', 'label' => 'Billing' ],
					'vat'     => [ 'type' => 'text', 'label' => 'VAT' ],
					'acc_two' => [ 'type' => 'accordion', 'label' => 'Invoices', 'open' => false ],
					'to'      => [ 'type' => 'email', 'label' => 'To' ],
				],
			]
		);

		$this->assertSame( 2, substr_count( $html, 'privacy-settings-accordion-trigger' ) );
		$this->assertStringContainsString( 'aria-expanded="true"', $html );
		$this->assertStringContainsString( 'aria-expanded="false"', $html );
		$this->assertStringContainsString( 'name="vat"', $html );
		$this->assertStringContainsString( 'name="to"', $html );
	}

	/**
	 * Two panel tabs, each divided the same way, draw ids of their own.
	 */
	public function test_two_divided_panel_tabs_draw_unique_ids(): void {
		$html = $this->render(
			[
				'tabs'     => [
					'one' => 'One',
					'two' => 'Two',
				],
				'fields'   => [
					'a1' => [ 'type' => 'tab', 'label' => 'A1', 'tab' => 'one' ],
					'x'  => [ 'type' => 'text', 'label' => 'X', 'tab' => 'one' ],
					'a2' => [ 'type' => 'tab', 'label' => 'A2', 'tab' => 'one' ],
					'y'  => [ 'type' => 'text', 'label' => 'Y', 'tab' => 'one' ],
					'b1' => [ 'type' => 'tab', 'label' => 'B1', 'tab' => 'two' ],
					'z'  => [ 'type' => 'text', 'label' => 'Z', 'tab' => 'two' ],
					'b2' => [ 'type' => 'tab', 'label' => 'B2', 'tab' => 'two' ],
					'w'  => [ 'type' => 'text', 'label' => 'W', 'tab' => 'two' ],
				],
			]
		);

		preg_match_all( '/\sid="([^"]+)"/', $html, $ids );

		$this->assertSame( count( $ids[1] ), count( array_unique( $ids[1] ) ), 'Duplicate ids: ' . implode( ', ', array_diff_assoc( $ids[1], array_unique( $ids[1] ) ) ) );
		$this->assertSame( 4, substr_count( $html, 'field-kit__panel-tab"' ) + substr_count( $html, 'field-kit__panel-tab ' ) );
	}

	/**
	 * One marker alone divides nothing and draws nothing.
	 */
	public function test_one_marker_alone_draws_nothing(): void {
		$html = $this->render(
			[
				'fields' => [
					'only' => [ 'type' => 'tab', 'label' => 'Only' ],
					'name' => [ 'type' => 'text', 'label' => 'Name' ],
				],
			]
		);

		$this->assertStringNotContainsString( 'role="tablist"', $html );
		$this->assertStringContainsString( 'name="name"', $html );
	}

	/**
	 * An amount's unit is handed to `save` beside the amount, when it is
	 * one the field offers.
	 */
	public function test_an_amounts_unit_is_saved_with_it(): void {
		$manager = new Manager( 'fip_amount' );
		$fields  = [
			'discount' => [
				'type'         => 'amount_type',
				'name'         => 'discount',
				'type_options' => [
					'percent' => '%',
					'flat'    => '£',
				],
			],
			'fee'      => [
				'type'          => 'unit_input',
				'name'          => 'fee',
				'units'         => [
					'pence'  => 'p',
					'pounds' => '£',
				],
				'unit_name'     => 'fee_unit',
			],
		];

		$values = $manager->sanitize(
			$fields,
			[
				'discount'      => '5',
				'discount_type' => 'flat',
				'fee'           => '2',
				'fee_unit'      => 'pounds',
			]
		);

		$this->assertSame( 'flat', $values['discount_type'] );
		$this->assertSame( 'pounds', $values['fee_unit'] );

		// One not on offer is not stored.
		$refused = $manager->sanitize( $fields, [ 'discount' => '5', 'discount_type' => 'bitcoin' ] );

		$this->assertArrayNotHasKey( 'discount_type', $refused );
	}

	/**
	 * On a panel with cards, the markers divide the card they are on, and
	 * the next card is drawn after the division, not inside it.
	 */
	public function test_markers_divide_their_own_card(): void {
		$html = $this->render(
			[
				'sections' => [
					'first'  => 'First',
					'second' => 'Second',
				],
				'fields'   => [
					'a1'   => [ 'type' => 'tab', 'label' => 'A1', 'section' => 'first' ],
					'x'    => [ 'type' => 'text', 'label' => 'X', 'section' => 'first' ],
					'a2'   => [ 'type' => 'tab', 'label' => 'A2', 'section' => 'first' ],
					'y'    => [ 'type' => 'text', 'label' => 'Y', 'section' => 'first' ],
					'last' => [ 'type' => 'text', 'label' => 'Last', 'section' => 'second' ],
				],
			]
		);

		$tabs   = strpos( $html, 'role="tablist"' );
		$second = strpos( $html, '>Second<' );

		$this->assertNotFalse( $tabs );
		$this->assertNotFalse( $second );
		$this->assertLessThan( $second, $tabs );
		$this->assertLessThan( $second, strpos( $html, 'name="y"' ) );
		$this->assertGreaterThan( $second, strpos( $html, 'name="last"' ) );
	}

	/**
	 * An accordion whose items are the record's is the component still.
	 */
	public function test_an_accordion_with_the_records_items_is_the_component(): void {
		$html = $this->render(
			[
				'fields' => [
					'faq' => [ 'type' => 'accordion', 'label' => 'FAQ' ],
				],
			],
			(object) [
				'faq' => [
					[
						'title'   => 'Shipping',
						'content' => 'Two days.',
					],
				],
			]
		);

		$this->assertStringContainsString( 'wp-flyout-accordion', $html );
		$this->assertStringContainsString( 'Shipping', $html );
	}

	/**
	 * Line items say what they come to as a subtotal: under them a price
	 * summary says the total, and two rows both called Total disagreed.
	 */
	public function test_line_items_come_to_a_subtotal(): void {
		$html = Components::create(
			'line_items',
			[
				'name'  => 'items',
				'items' => [
					[
						'id'       => 1,
						'name'     => 'Loops',
						'price'    => 10,
						'quantity' => 2,
					],
				],
			]
		)->render();

		$this->assertStringContainsString( '>Subtotal<', $html );
		$this->assertStringNotContainsString( 'Total:', $html );
	}
}
