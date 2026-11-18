<?php
/**
 * The default footer.
 *
 * @package SugarCommerce\Flyouts
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts\Tests;

use SugarCommerce\Flyouts\Manager;
use PHPUnit\Framework\TestCase;

/**
 * Which buttons a panel is given when it does not bring its own.
 *
 * The order is the thing. It is the order they are tabbed through and the
 * order they are read in, and the stylesheet only moves Delete to the far
 * side -- so if the markup has Save first, the keyboard reaches the buttons
 * in a different order from the one they are drawn in.
 */
final class FooterTest extends TestCase {

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
	 *
	 * @return string
	 */
	private function render( array $config ): string {
		$manager = new Manager( 'foot' );

		return $manager->build_flyout( $config + [ 'id' => 'panel', 'fields' => [] ], null, null )->render();
	}

	/**
	 * A panel that saves has Cancel beside Save; Delete stands apart.
	 */
	public function test_the_default_footer_is_delete_cancel_save(): void {
		$html = $this->render(
			[
				'title'  => 'Order',
				'save'   => static fn() => true,
				'delete' => static fn() => true,
			]
		);

		$delete = strpos( $html, 'wp-flyout-delete' );
		$cancel = strpos( $html, 'wp-flyout-cancel' );
		$save   = strpos( $html, 'wp-flyout-save' );

		$this->assertIsInt( $delete );
		$this->assertIsInt( $cancel );
		$this->assertIsInt( $save );
		$this->assertLessThan( $cancel, $delete );
		$this->assertLessThan( $save, $cancel );

		// A word, not a second button.
		$this->assertMatchesRegularExpression( '/class="button-link wp-flyout-cancel"[^>]*>Cancel</', $html );
	}

	/**
	 * A panel with nothing to save has no Cancel.
	 *
	 * It has the close button, and a second way out beside no way forward
	 * is a footer with one grey word in it.
	 */
	public function test_cancel_is_only_beside_a_save(): void {
		$html = $this->render( [ 'title' => 'Order', 'delete' => static fn() => true ] );

		$this->assertStringContainsString( 'wp-flyout-delete', $html );
		$this->assertStringNotContainsString( 'wp-flyout-cancel', $html );

		$this->assertStringNotContainsString( 'wp-flyout-footer', $this->render( [ 'title' => 'Order' ] ) );
	}

	/**
	 * A footer the consumer wrote is left exactly as they wrote it.
	 */
	public function test_custom_actions_are_left_alone(): void {
		$html = $this->render(
			[
				'title'   => 'Order',
				'save'    => static fn() => true,
				'actions' => [
					[
						'text'  => 'Send',
						'style' => 'primary',
						'class' => 'wp-flyout-save',
					],
				],
			]
		);

		$this->assertStringNotContainsString( 'wp-flyout-cancel', $html );
		$this->assertStringContainsString( '>Send</button>', $html );
		$this->assertSame( 1, substr_count( $html, '<button class="button' ) );
	}
}
