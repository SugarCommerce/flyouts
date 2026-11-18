<?php
/**
 * The two-column layout.
 *
 * @package SugarCommerce\Flyouts
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts\Tests;

use SugarCommerce\Flyouts\Manager;
use PHPUnit\Framework\TestCase;

/**
 * Where the cards of a split panel are put.
 *
 * Sections are drawn in runs, in the order their fields come. A second
 * column cannot be made of that by a stylesheet alone -- a grid ties the
 * height of each row across both columns -- so the cards that stand aside
 * are gathered into a column of their own in the markup, and everything
 * that follows from that is checked here: every card is still drawn once,
 * every card that opens closes, and a panel that did not ask for any of it
 * is byte for byte what it was.
 */
final class SplitLayoutTest extends TestCase {

	/**
	 * A clean hook registry, and a user who may do anything.
	 */
	protected function setUp(): void {
		$GLOBALS['fk_filters'] = [];
		$GLOBALS['wf_caps']    = null;
	}

	/**
	 * Four sections, two of them marked to stand aside.
	 *
	 * @return array<string, mixed>
	 */
	private static function config(): array {
		return [
			'id'       => 'panel',
			'title'    => 'Product',
			'size'     => 'large',
			'sections' => [
				'details' => 'Details',
				'status'  => [ 'label' => 'Status', 'aside' => true ],
				'prices'  => 'Prices',
				'meta'    => [ 'label' => 'Organise', 'aside' => true, 'collapsed' => true ],
			],
			'fields'   => [
				'name'     => [ 'type' => 'text', 'label' => 'Name', 'section' => 'details' ],
				'state'    => [ 'type' => 'text', 'label' => 'State', 'section' => 'status' ],
				'price'    => [ 'type' => 'text', 'label' => 'Price', 'section' => 'prices' ],
				'category' => [ 'type' => 'text', 'label' => 'Category', 'section' => 'meta' ],
			],
		];
	}

	/**
	 * Render a panel.
	 *
	 * @param array<string, mixed> $config Flyout configuration.
	 *
	 * @return string
	 */
	private function render( array $config ): string {
		return ( new Manager( 'split' ) )->build_flyout( $config, (object) [], null )->render();
	}

	/**
	 * The part of a rendered panel inside one column.
	 *
	 * @param string $html   The panel.
	 * @param string $column 'main' or 'aside'.
	 *
	 * @return string
	 */
	private static function column( string $html, string $column ): string {
		$start = strpos( $html, '<div class="wp-flyout-split-' . $column . '">' );
		$end   = 'main' === $column
			? strpos( $html, '<div class="wp-flyout-split-aside">' )
			: strlen( $html );

		return false === $start ? '' : substr( $html, $start, $end - $start );
	}

	/**
	 * The aside sections are gathered into the second column, in order.
	 */
	public function test_aside_sections_form_a_second_column(): void {
		$html = $this->render( self::config() + [ 'layout' => 'split' ] );

		$this->assertStringContainsString( 'wp-flyout-layout-split', $html );
		$this->assertSame( 1, substr_count( $html, '<div class="wp-flyout-split">' ) );

		$main  = self::column( $html, 'main' );
		$aside = self::column( $html, 'aside' );

		$this->assertStringContainsString( 'wp-flyout-section--details', $main );
		$this->assertStringContainsString( 'wp-flyout-section--prices', $main );
		$this->assertStringNotContainsString( 'wp-flyout-section--status', $main );
		$this->assertStringNotContainsString( 'wp-flyout-section--meta', $main );

		$this->assertStringContainsString( 'wp-flyout-section--status', $aside );
		$this->assertStringContainsString( 'wp-flyout-section--meta', $aside );
		$this->assertLessThan( strpos( $aside, '--meta' ), strpos( $aside, '--status' ) );

		// The fields went with their cards.
		$this->assertStringContainsString( 'name="price"', $main );
		$this->assertStringContainsString( 'name="category"', $aside );
	}

	/**
	 * Every card is drawn once, and every card that opens closes.
	 */
	public function test_every_card_is_drawn_once_and_closed(): void {
		$html = $this->render( self::config() + [ 'layout' => 'split' ] );

		$this->assertSame( 3, substr_count( $html, '<section class="wp-flyout-section' ) );
		$this->assertSame( 1, substr_count( $html, '<details class="wp-flyout-section' ) );
		$this->assertSame( substr_count( $html, '<section' ), substr_count( $html, '</section>' ) );
		$this->assertSame( substr_count( $html, '<details' ), substr_count( $html, '</details>' ) );

		foreach ( [ 'main', 'aside' ] as $column ) {
			$part = self::column( $html, $column );

			$this->assertSame(
				substr_count( $part, '<section' ) + substr_count( $part, '<details' ),
				substr_count( $part, '</section>' ) + substr_count( $part, '</details>' ),
				'A card in the ' . $column . ' column is left open.'
			);
		}
	}

	/**
	 * The flag on its own does nothing: no layout, no columns.
	 */
	public function test_aside_without_the_layout_is_one_column(): void {
		$html = $this->render( self::config() );

		$this->assertStringNotContainsString( 'wp-flyout-split', $html );
		$this->assertStringNotContainsString( 'wp-flyout-layout-split', $html );

		// In the order the fields came, as it always was.
		$this->assertLessThan( strpos( $html, '--prices' ), strpos( $html, '--status' ) );
	}

	/**
	 * The layout on its own does nothing either: nothing stands aside.
	 */
	public function test_the_layout_without_an_aside_is_one_column(): void {
		$config = self::config() + [ 'layout' => 'split' ];

		$config['sections']['status'] = 'Status';
		$config['sections']['meta']   = 'Organise';

		$this->assertStringNotContainsString( '<div class="wp-flyout-split', $this->render( $config ) );
	}

	/**
	 * A panel that asked for neither is exactly the panel it was.
	 */
	public function test_an_unsplit_panel_is_unchanged_by_an_unknown_layout(): void {
		$this->assertSame(
			$this->render( self::config() ),
			$this->render( self::config() + [ 'layout' => 'sideways' ] )
		);
	}

	/**
	 * Under tabs, each page is split on its own.
	 */
	public function test_each_tab_is_split_separately(): void {
		$config = self::config() + [
			'layout' => 'split',
			'tabs'   => [
				'one' => 'One',
				'two' => 'Two',
			],
		];

		$config['fields']['name']['tab']     = 'one';
		$config['fields']['state']['tab']    = 'one';
		$config['fields']['price']['tab']    = 'two';
		$config['fields']['category']['tab'] = 'one';

		$html = $this->render( $config );

		// The first page has cards on both sides; the second has none
		// aside and is a single column.
		$this->assertSame( 1, substr_count( $html, '<div class="wp-flyout-split">' ) );

		$second = substr( $html, (int) strpos( $html, 'id="tab-two"' ) );

		$this->assertStringContainsString( 'wp-flyout-section--prices', $second );
		$this->assertStringNotContainsString( 'wp-flyout-split', $second );
	}
}
