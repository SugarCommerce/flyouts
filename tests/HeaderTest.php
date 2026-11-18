<?php
/**
 * The header that names the record.
 *
 * @package SugarCommerce\Flyouts
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts\Tests;

use SugarCommerce\Flyouts\Flyout;
use SugarCommerce\Flyouts\Manager;
use PHPUnit\Framework\TestCase;

/**
 * What the top of the panel says about the thing in it.
 *
 * A panel titled "Edit product" is the same panel for every product. The
 * title, the line under it, the line over it and the badge beside it can
 * each be worked out from the record once it has been loaded -- and the one
 * way that goes wrong quietly is a title that is a word PHP also knows as a
 * function.
 */
final class HeaderTest extends TestCase {

	/**
	 * A clean hook registry, and a user who may do anything.
	 */
	protected function setUp(): void {
		$GLOBALS['fk_filters'] = [];
		$GLOBALS['wf_caps']    = null;
	}

	/**
	 * Build a panel and render it.
	 *
	 * @param array<string, mixed> $config Flyout configuration.
	 * @param mixed                $data   What `load` would have returned.
	 * @param mixed                $id     The record's id.
	 *
	 * @return string
	 */
	private function render( array $config, $data = null, $id = null ): string {
		return $this->build( $config, $data, $id )->render();
	}

	/**
	 * Build a panel.
	 *
	 * @param array<string, mixed> $config Flyout configuration.
	 * @param mixed                $data   What `load` would have returned.
	 * @param mixed                $id     The record's id.
	 *
	 * @return Flyout
	 */
	private function build( array $config, $data = null, $id = null ): Flyout {
		$manager = new Manager( 'head' );

		return $manager->build_flyout( $config + [ 'id' => 'panel', 'fields' => [] ], $data, $id );
	}

	/**
	 * A plain title is still a plain title.
	 */
	public function test_a_string_title_is_the_title(): void {
		$html = $this->render( [ 'title' => 'Edit product', 'subtitle' => 'What it costs.' ] );

		$this->assertStringContainsString( '>Edit product</h2>', $html );
		$this->assertStringContainsString( '<p class="wp-flyout-subtitle">What it costs.</p>', $html );
	}

	/**
	 * A callback is handed the record and the id, and its answer is the title.
	 */
	public function test_a_callable_title_is_made_from_the_record(): void {
		$seen = [];

		$html = $this->render(
			[
				'title'    => static function ( $data, $id ) use ( &$seen ): string {
					$seen = [ $data, $id ];

					return $id ? $data->name : 'New product';
				},
				'subtitle' => static fn( $data ): string => 'SKU ' . $data->sku,
			],
			(object) [ 'name' => 'Deep House Kit', 'sku' => 'DHK-1' ],
			12
		);

		$this->assertStringContainsString( '>Deep House Kit</h2>', $html );
		$this->assertStringContainsString( '>SKU DHK-1</p>', $html );
		$this->assertSame( 12, $seen[1] );
		$this->assertSame( 'Deep House Kit', $seen[0]->name );
	}

	/**
	 * The same callback tells a new record from an existing one by its id.
	 */
	public function test_a_callable_title_can_name_a_new_record(): void {
		$title = static fn( $data, $id ): string => $id ? (string) $data->name : 'New product';

		$this->assertStringContainsString( '>New product</h2>', $this->render( [ 'title' => $title ], null, 0 ) );
	}

	/**
	 * A title that is also the name of a function is not called.
	 *
	 * `is_callable( 'Link' )` is true. So are 'Key', 'Count', 'Date' and
	 * 'Exit', and every one of them is a perfectly good title.
	 */
	public function test_a_title_that_names_a_function_is_text(): void {
		foreach ( [ 'Link', 'Key', 'Count', 'Date', 'Current' ] as $word ) {
			$this->assertTrue( is_callable( $word ), $word . ' is not a function name; the test proves nothing.' );

			$this->assertStringContainsString(
				'>' . $word . '</h2>',
				$this->render( [ 'title' => $word, 'eyebrow' => $word, 'badge' => $word ] )
			);
		}

		$this->assertFalse( Manager::is_callback( 'exit' ) );
		$this->assertTrue( Manager::is_callback( static fn() => '' ) );
		$this->assertTrue( Manager::is_callback( [ $this, 'test_a_string_title_is_the_title' ] ) );
	}

	/**
	 * A callback that returns something with no text gives no text.
	 *
	 * Rather than a TypeError in a string-typed setter, which reaches the
	 * browser as an HTML error page where the script expects JSON.
	 */
	public function test_a_callback_returning_nothing_is_an_empty_title(): void {
		$html = $this->render(
			[
				'title'    => static fn() => null,
				'subtitle' => static fn() => [ 'not', 'text' ],
				'eyebrow'  => static fn() => 42,
			]
		);

		$this->assertStringContainsString( '-title"></h2>', $html );
		$this->assertStringNotContainsString( 'wp-flyout-subtitle', $html );
		$this->assertStringContainsString( '<p class="wp-flyout-eyebrow">42</p>', $html );
	}

	/**
	 * The eyebrow is drawn over the title, and only when there is one.
	 */
	public function test_the_eyebrow_sits_above_the_title(): void {
		$html = $this->render( [ 'title' => 'Deep House Kit', 'eyebrow' => 'Product' ] );

		$this->assertStringContainsString( '<p class="wp-flyout-eyebrow">Product</p>', $html );
		$this->assertLessThan( strpos( $html, 'wp-flyout-title"' ), strpos( $html, 'wp-flyout-eyebrow' ) );

		$this->assertStringNotContainsString( 'wp-flyout-eyebrow', $this->render( [ 'title' => 'Deep House Kit' ] ) );
	}

	/**
	 * A badge is a label and a tone.
	 */
	public function test_a_badge_is_drawn_in_its_tone(): void {
		$html = $this->render( [ 'title' => 'Order', 'badge' => [ 'label' => 'Paid', 'tone' => 'success' ] ] );

		$this->assertStringContainsString( '<span class="wp-flyout-badge wp-flyout-badge--success">Paid</span>', $html );
	}

	/**
	 * A word on its own is a neutral badge, and an unknown tone is neutral.
	 */
	public function test_a_badge_without_a_known_tone_is_neutral(): void {
		$this->assertStringContainsString(
			'wp-flyout-badge--neutral">Draft</span>',
			$this->render( [ 'title' => 'Order', 'badge' => 'Draft' ] )
		);

		$html = $this->render( [ 'title' => 'Order', 'badge' => [ 'label' => 'Odd', 'tone' => 'purple" onclick="x' ] ] );

		$this->assertStringContainsString( 'wp-flyout-badge--neutral">Odd</span>', $html );
		$this->assertStringNotContainsString( 'purple', $html );
	}

	/**
	 * A badge can follow the record, and a record with no state has none.
	 */
	public function test_a_callable_badge_follows_the_record(): void {
		$badge = static fn( $data ) => 'refunded' === $data->status
			? [ 'label' => 'Refunded', 'tone' => 'danger' ]
			: '';

		$this->assertStringContainsString(
			'wp-flyout-badge--danger">Refunded</span>',
			$this->render( [ 'title' => 'Order', 'badge' => $badge ], (object) [ 'status' => 'refunded' ] )
		);

		$this->assertStringNotContainsString(
			'wp-flyout-badge',
			$this->render( [ 'title' => 'Order', 'badge' => $badge ], (object) [ 'status' => 'paid' ] )
		);
	}

	/**
	 * What a record says about itself is text, wherever it is drawn.
	 */
	public function test_the_header_escapes_what_the_record_says(): void {
		$evil = '<script>alert(1)</script>';
		$html = $this->render(
			[
				'title'   => static fn() => $evil,
				'eyebrow' => static fn() => $evil,
				'badge'   => static fn() => [ 'label' => $evil, 'tone' => 'info' ],
			]
		);

		$this->assertStringNotContainsString( '<script>', $html );
	}

	/**
	 * The build filter still has the last word, and sees what was resolved.
	 */
	public function test_the_build_filter_still_runs_and_can_change_the_header(): void {
		$seen = '';

		$GLOBALS['fk_filters']['wp_flyout_build_flyout_head'][] = static function ( Flyout $flyout, array $config, $data ) use ( &$seen ): Flyout {
			$seen = $flyout->get_title() . '|' . $flyout->get_eyebrow() . '|' . $flyout->get_badge()['label'];

			return $flyout->set_title( 'Changed by the filter' );
		};

		$html = $this->render(
			[
				'title'   => static fn( $data ): string => $data->name,
				'eyebrow' => 'Product',
				'badge'   => 'Draft',
			],
			(object) [ 'name' => 'Deep House Kit' ]
		);

		$this->assertSame( 'Deep House Kit|Product|Draft', $seen );
		$this->assertStringContainsString( '>Changed by the filter</h2>', $html );
	}

	/**
	 * A title worth copying says so on the panel, where the script reads it.
	 */
	public function test_a_title_to_copy_marks_the_panel(): void {
		$html = $this->render( [ 'title' => 'ABCD-EFGH', 'copy_title' => true ] );

		$this->assertMatchesRegularExpression( '/<div id="panel"\s+class="[^"]*\bwp-flyout-copy-title\b/', $html );
	}

	/**
	 * And no other panel is marked.
	 */
	public function test_a_plain_title_is_not_marked_for_copying(): void {
		$this->assertStringNotContainsString( 'wp-flyout-copy-title', $this->render( [ 'title' => 'Edit product' ] ) );
	}
}
