<?php
/**
 * The panel's own markup.
 *
 * @package SugarCommerce\Flyouts
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts\Tests;

use SugarCommerce\Flyouts\Manager;
use PHPUnit\Framework\TestCase;

/**
 * What the shell says about itself, whatever is in it.
 *
 * The parts of the markup the script and a screen reader both depend on and
 * neither can check: that the panel is announced as a dialog and by name,
 * that the button that closes it says so, and that a trigger knows how wide
 * the panel it opens will be.
 */
final class PanelTest extends TestCase {

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
		$manager = new Manager( 'shell' );

		return $manager->build_flyout( $config + [ 'id' => 'panel', 'fields' => [] ], null, null )->render();
	}

	/**
	 * The panel is a dialog, named by its own title.
	 */
	public function test_the_panel_is_a_dialog_named_by_its_title(): void {
		$html = $this->render( [ 'title' => 'Order' ] );

		$this->assertStringContainsString( 'role="dialog"', $html );
		$this->assertStringContainsString( 'aria-modal="true"', $html );
		$this->assertStringContainsString( 'aria-labelledby="panel-title"', $html );
		$this->assertMatchesRegularExpression( '/<h2 class="wp-flyout-title" id="panel-title">Order<\/h2>/', $html );

		// It can be given focus itself, for a panel with no field to give
		// it to -- but is not a stop on the way through with Tab.
		$this->assertStringContainsString( 'tabindex="-1"', $html );
	}

	/**
	 * The close button says what it does, to a pointer and to a screen reader.
	 */
	public function test_the_close_button_is_named(): void {
		$html = $this->render( [ 'title' => 'Order' ] );

		$this->assertMatchesRegularExpression(
			'/<button type="button" class="wp-flyout-close" aria-label="Close" title="Close">/',
			$html
		);

		// The glyph is decoration; the name is the label. A Heroicon now,
		// where it was a Dashicon.
		$this->assertMatchesRegularExpression(
			'/class="wp-flyout-close"[^>]*>\s*<svg width="20" height="20" class="sugarcommerce-icon" aria-hidden="true"[^>]*>.*?<\/svg>\s*<\/button>/s',
			$html
		);
		$this->assertStringNotContainsString( 'dashicons', $html );

		// And it sits in the group the script adds the other buttons to.
		$this->assertLessThan(
			strpos( $html, 'wp-flyout-close' ),
			strpos( $html, '<div class="wp-flyout-header-actions">' )
		);
	}

	/**
	 * A trigger says how wide its panel will be.
	 *
	 * So the placeholder shown while the record loads is that width, and
	 * nothing jumps when the record arrives.
	 */
	public function test_a_trigger_carries_the_panels_size(): void {
		$manager = new Manager( 'shell_size' );
		$manager->register_flyout( 'wide', [ 'title' => 'Wide', 'size' => 'large' ] );
		$manager->register_flyout( 'plain', [ 'title' => 'Plain' ] );

		$this->assertStringContainsString( 'data-flyout-size="large"', $manager->get_button( 'wide', [ 'id' => 3 ] ) );
		$this->assertStringContainsString( 'data-flyout-size="medium"', $manager->link( 'plain', 'Open' ) );
	}

	/**
	 * An amount with a unit opens showing the unit the record holds, not
	 * the default one. Drawn as the default, a five pound discount read
	 * "5 %" and was saved as five percent by anybody who pressed Save.
	 */
	public function test_an_amount_opens_with_the_records_own_unit(): void {
		$manager = new Manager( 'shell' );
		$config  = [
			'id'     => 'panel',
			'title'  => 'Discount',
			'fields' => [
				'amount' => [
					'type'          => 'amount_type',
					'label'         => 'Value',
					'type_options'  => [
						'percent' => '%',
						'flat'    => '£',
					],
					'type_meta_key' => 'type',
					'type_default'  => 'percent',
				],
			],
		];

		$flat = $manager->build_flyout(
			$config,
			(object) [
				'amount' => '5.00',
				'type'   => 'flat',
			],
			1
		)->render();

		$this->assertMatchesRegularExpression( '/<option value="flat" selected>/', $flat );
		$this->assertDoesNotMatchRegularExpression( '/<option value="percent" selected>/', $flat );

		// With nothing stored, the default still stands.
		$new = $manager->build_flyout( $config, (object) [], null )->render();

		$this->assertMatchesRegularExpression( '/<option value="percent" selected>/', $new );
	}
}
