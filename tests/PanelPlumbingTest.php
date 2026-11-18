<?php
/**
 * What every panel goes through: its hooks, its cards, its header tags,
 * and the two places it takes markup.
 *
 * @package SugarCommerce\Flyouts
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts\Tests;

use SugarCommerce\Flyouts\Components\InfoGrid;
use SugarCommerce\Flyouts\Components\Markup;
use SugarCommerce\Flyouts\Manager;
use SugarCommerce\Flyouts\RestApi;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

/**
 * The plumbing a store built for itself around this library, now in it.
 *
 * An add-on that puts a field on somebody else's panel needs three things:
 * to add the field (the `_config` filter, which there was), to give it its
 * value (`_data`), and to write it (`_saved`). And the field has to land on
 * the card it named, not in a second card of the same name at the foot.
 */
final class PanelPlumbingTest extends TestCase {

	/**
	 * What fired.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private static array $saved = [];

	/**
	 * A clean slate.
	 */
	protected function setUp(): void {
		$GLOBALS['fk_filters'] = [];
		$GLOBALS['fk_actions'] = [];
		$GLOBALS['wf_caps']    = null;
		self::$saved           = [];
	}

	/**
	 * A REST request.
	 *
	 * @param string               $prefix The manager.
	 * @param array<string, mixed> $params What else it carries.
	 *
	 * @return WP_REST_Request
	 */
	private function request( string $prefix, array $params = [] ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/load' );

		foreach (
			$params + [
				'manager' => $prefix,
				'flyout'  => 'product',
				'item_id' => 7,
			] as $key => $value
		) {
			$request->set_param( $key, $value );
		}

		return $request;
	}

	/**
	 * A product panel.
	 *
	 * @param string               $prefix The manager.
	 * @param array<string, mixed> $config More configuration.
	 *
	 * @return Manager
	 */
	private function product( string $prefix, array $config = [] ): Manager {
		$manager = new Manager( $prefix );

		$manager->register_flyout(
			'product',
			$config + [
				'title'  => 'Product',
				'fields' => [
					'name'      => [
						'type'  => 'text',
						'label' => 'Name',
					],
					'gift_wrap' => [
						'type'  => 'text',
						'label' => 'Gift wrap',
					],
				],
				'load'   => static fn( $id ): object => (object) [ 'name' => 'Kit ' . $id ],
				'save'   => static fn( $id, array $data ) => 99,
			]
		);

		return $manager;
	}

	// =========================================================================
	// DATA AND SAVED
	// =========================================================================

	/**
	 * What the panel loaded can be added to, for that panel alone.
	 */
	public function test_the_data_filter_gives_an_added_field_its_value(): void {
		$this->product( 'pp_data' );

		add_filter(
			'wp_flyout_pp_data_product_data',
			static function ( $product, $id ) {
				$product->gift_wrap = 'Ribbon for ' . $id;

				return $product;
			}
		);

		$html = RestApi::handle_load( $this->request( 'pp_data' ) )->get_data()['html'];

		$this->assertStringContainsString( 'value="Kit 7"', $html );
		$this->assertStringContainsString( 'value="Ribbon for 7"', $html );
	}

	/**
	 * A refusal is not filtered, and a filter cannot turn a record into
	 * something else.
	 */
	public function test_the_data_filter_leaves_refusals_and_types_alone(): void {
		$manager = $this->product( 'pp_refusal', [ 'load' => static fn() => false ] );
		$ran     = false;

		add_filter(
			'wp_flyout_pp_refusal_product_data',
			static function ( $data ) use ( &$ran ) {
				$ran = true;

				return $data;
			}
		);

		$this->assertFalse( $manager->load_record( 'product', 7 ) );
		$this->assertFalse( $ran );

		$manager = $this->product( 'pp_type' );

		add_filter( 'wp_flyout_pp_type_product_data', static fn() => 'a string' );

		$this->assertIsObject( $manager->load_record( 'product', 7 ) );

		// No load, nothing to filter.
		$this->assertNull( ( new Manager( 'pp_none' ) )->load_record( 'missing', 1 ) );
	}

	/**
	 * Once a panel has saved, its own action fires -- with the new record's
	 * id when the save handed one back.
	 */
	public function test_the_saved_action_fires_for_that_panel(): void {
		$this->product( 'pp_saved' );

		add_action(
			'wp_flyout_pp_saved_product_saved',
			static function ( $id, $values ) {
				self::$saved[] = [ $id, $values ];
			}
		);

		RestApi::handle_save( $this->request( 'pp_saved', [ 'form_data' => [ 'name' => 'New' ] ] ) );

		$this->assertCount( 1, self::$saved );
		$this->assertSame( 99, self::$saved[0][0] );
		$this->assertSame( 'New', self::$saved[0][1]['name'] ?? null );
	}

	/**
	 * A save that answered `true` keeps the id it was asked to save.
	 */
	public function test_the_saved_action_keeps_the_record_id_for_an_update(): void {
		$this->product( 'pp_update', [ 'save' => static fn(): bool => true ] );

		add_action(
			'wp_flyout_pp_update_product_saved',
			static function ( $id ) {
				self::$saved[] = [ $id ];
			}
		);

		RestApi::handle_save( $this->request( 'pp_update', [ 'form_data' => [ 'name' => 'Same' ] ] ) );

		$this->assertSame( 7, self::$saved[0][0] ?? null );
	}

	/**
	 * A refused save wrote nothing, so nothing fires beside it.
	 */
	public function test_the_saved_action_does_not_fire_for_a_refused_save(): void {
		$this->product( 'pp_refused', [ 'save' => static fn() => new \WP_Error( 'no', 'No.' ) ] );
		$this->product( 'pp_false', [ 'save' => static fn(): bool => false ] );

		foreach ( [ 'pp_refused', 'pp_false' ] as $prefix ) {
			add_action(
				"wp_flyout_{$prefix}_product_saved",
				static function ( $id ) {
					self::$saved[] = [ $id ];
				}
			);

			RestApi::handle_save( $this->request( $prefix, [ 'form_data' => [ 'name' => 'X' ] ] ) );
		}

		$this->assertSame( [], self::$saved );
	}

	// =========================================================================
	// CARDS
	// =========================================================================

	/**
	 * An appended field joins the card it named.
	 */
	public function test_a_field_joins_the_card_it_names(): void {
		$manager = new Manager( 'pp_cards' );
		$html    = $manager->build_flyout(
			[
				'id'       => 'panel',
				'title'    => 'Product',
				'sections' => [
					'details' => 'Details',
					'pricing' => 'Pricing',
				],
				'fields'   => [
					'name'  => [
						'type'    => 'text',
						'label'   => 'Name',
						'section' => 'details',
					],
					'price' => [
						'type'    => 'text',
						'label'   => 'Price',
						'section' => 'pricing',
					],
					'wrap'  => [
						'type'    => 'text',
						'label'   => 'Wrap',
						'section' => 'details',
					],
				],
			],
			null
		)->render();

		$this->assertSame( 1, substr_count( $html, '>Details</h3>' ) );
		$this->assertLessThan( strpos( $html, '>Pricing</h3>' ), strpos( $html, 'Wrap' ) );
	}

	/**
	 * Gathering keeps a field on no card in its place, and every other
	 * field in the order it came.
	 */
	public function test_gathering_keeps_the_order_otherwise(): void {
		$order = array_keys(
			Manager::gather(
				[
					'a' => [ 'section' => 'one' ],
					'b' => [],
					'c' => [ 'section' => 'two' ],
					'd' => [ 'section' => 'one' ],
					'e' => [],
					'f' => [ 'section' => 'two' ],
				]
			)
		);

		$this->assertSame( [ 'a', 'd', 'b', 'c', 'f', 'e' ], $order );
	}

	// =========================================================================
	// HEADER TAGS
	// =========================================================================

	/**
	 * Tags stand beside the badge, in the title row.
	 */
	public function test_tags_stand_beside_the_badge(): void {
		$manager = new Manager( 'pp_tags' );
		$html    = $manager->build_flyout(
			[
				'id'     => 'panel',
				'title'  => 'Ada',
				'badge'  => 'Active',
				'tags'   => static fn( $customer ) => $customer->tags,
				'fields' => [],
			],
			(object) [
				'tags' => [
					'VIP',
					[
						'label' => 'Wholesale',
						'tone'  => 'info',
					],
					[
						'label'      => '<b>Custom</b>',
						'background' => '#ffeecc',
						'color'      => '#553300',
						'title'      => 'Chosen colour',
					],
					[ 'label' => '' ],
				],
			]
		)->render();

		$row = substr( $html, strpos( $html, 'wp-flyout-title-row' ) );
		$row = substr( $row, 0, strpos( $row, '</div>' ) );

		$this->assertMatchesRegularExpression( '/Active<\/span>\s*<span class="wp-flyout-tags">/', $row );
		$this->assertStringContainsString( '<span class="wp-flyout-badge wp-flyout-badge--neutral wp-flyout-tag">VIP</span>', $row );
		$this->assertStringContainsString( 'wp-flyout-badge--info wp-flyout-tag">Wholesale', $row );
		$this->assertStringContainsString( 'style="background:#ffeecc;color:#553300" title="Chosen colour">&lt;b&gt;Custom&lt;/b&gt;', $row );
		// The one with no label is left out.
		$this->assertSame( 3, substr_count( $row, 'wp-flyout-tag"' ) );
	}

	/**
	 * Markup the plugin drew is put there as it is; no tags, no box.
	 */
	public function test_tags_may_be_the_plugins_own_markup(): void {
		$manager = new Manager( 'pp_tags_html' );
		$config  = [
			'id'     => 'panel',
			'title'  => 'Ada',
			'fields' => [],
		];

		$html = $manager->build_flyout( $config + [ 'tags' => '<span class="my-tag">VIP</span>' ], null )->render();

		$this->assertStringContainsString( '<span class="wp-flyout-tags"><span class="my-tag">VIP</span></span>', $html );
		$this->assertStringNotContainsString( 'wp-flyout-tags', $manager->build_flyout( $config, null )->render() );

		// A tone that is not one is neutral, and a single colour is ignored.
		$this->assertSame(
			'<span class="wp-flyout-badge wp-flyout-badge--neutral wp-flyout-tag">X</span>',
			Manager::tags(
				[
					[
						'label'      => 'X',
						'tone'       => 'loud',
						'background' => '#000000',
					],
				]
			)
		);
	}

	// =========================================================================
	// MARKUP
	// =========================================================================

	/**
	 * The markup component prints what the panel loaded, in a box an action
	 * can redraw by its class.
	 */
	public function test_markup_prints_what_the_panel_loaded(): void {
		$manager = new Manager( 'pp_markup' );
		$html    = $manager->build_flyout(
			[
				'id'     => 'panel',
				'title'  => 'Product',
				'fields' => [
					'chart' => [
						'type'  => 'markup',
						'class' => 'my-chart',
					],
				],
			],
			(object) [ 'chart' => '<svg data-x="1"><path d="M0 0"/></svg>' ]
		)->render();

		$this->assertStringContainsString( '<div class="wp-flyout-markup my-chart"><svg data-x="1"><path d="M0 0"/></svg></div>', $html );

		// Nothing to draw and nowhere to draw it later is nothing at all.
		$this->assertSame( '', ( new Markup( [] ) )->render() );
		$this->assertSame( '<div class="wp-flyout-markup slot"></div>', ( new Markup( [ 'class' => 'slot' ] ) )->render() );
	}

	// =========================================================================
	// FACTS
	// =========================================================================

	/**
	 * An info grid value that says it is markup is printed as markup, and
	 * one that does not is escaped as before.
	 */
	public function test_an_info_grid_value_may_be_markup(): void {
		$html = ( new InfoGrid(
			[
				'items' => [
					[
						'label' => 'Country',
						'value' => '<img src="flag.svg"> France',
						'html'  => true,
						'title' => 'FR',
					],
					[
						'label' => 'Note',
						'value' => '<b>plain</b>',
					],
				],
			]
		) )->render();

		$this->assertStringContainsString( '<img src="flag.svg"> France', $html );
		$this->assertStringContainsString( 'title="FR"', $html );
		$this->assertStringContainsString( '&lt;b&gt;plain&lt;/b&gt;', $html );
	}

	/**
	 * The grid as markup, to end a card a plugin drew: the component's own
	 * classes, rows with nothing to say left out.
	 */
	public function test_the_facts_grid(): void {
		$html = InfoGrid::grid(
			[
				[
					'label' => 'Paid',
					'value' => '<strong>£5</strong>',
				],
				[
					'label' => 'Browser',
					'value' => 'Chrome',
					'title' => 'Mozilla/5.0 "quoted"',
				],
				[
					'label' => 'Empty',
					'value' => '',
				],
				[ 'value' => 'No label' ],
				'junk',
			],
			[ 'class' => 'my-facts' ]
		);

		$this->assertSame(
			'<div class="wp-flyout-info-grid columns-2 my-facts" data-columns="2">'
			. '<div class="wp-flyout-info-item"><div class="wp-flyout-info-label">Paid</div><div class="wp-flyout-info-value"><strong>£5</strong></div></div>'
			. '<div class="wp-flyout-info-item"><div class="wp-flyout-info-label">Browser</div><div class="wp-flyout-info-value" title="Mozilla/5.0 &quot;quoted&quot;">Chrome</div></div>'
			. '</div>',
			$html
		);

		$this->assertSame( '', InfoGrid::grid( [ [ 'label' => 'Empty' ] ] ) );
	}
}
