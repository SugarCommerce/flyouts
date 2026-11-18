<?php
/**
 * The way in.
 *
 * @package SugarCommerce\Flyouts
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts\Tests;

use SugarCommerce\Flyouts\Flyouts;
use SugarCommerce\Flyouts\Manager;
use SugarCommerce\Flyouts\Registry;
use PHPUnit\Framework\TestCase;

/**
 * Registering a panel by its `{prefix}_{name}`, and the button and link that open it.
 *
 * These replaced global functions, so what is checked is that each still
 * reaches the right manager and splits its arguments the way they did.
 */
final class FlyoutsTest extends TestCase {

	/**
	 * Everybody can do everything.
	 */
	protected function setUp(): void {
		$GLOBALS['fk_filters'] = [];
		$GLOBALS['wf_caps']    = null;
	}

	/**
	 * The prefix picks the manager, created the first time, and the rest is the panel.
	 */
	public function test_register_joins_the_manager_its_prefix_names(): void {
		$manager = Flyouts::register( 'facadeshop_edit_product', [ 'title' => 'Edit product' ] );

		$this->assertInstanceOf( Manager::class, $manager );
		$this->assertSame( 'facadeshop', $manager->get_prefix() );
		$this->assertTrue( $manager->has_flyout( 'edit_product' ) );
		$this->assertSame( $manager, Flyouts::manager( 'facadeshop' ) );

		// A second panel joins the same manager rather than starting another.
		$this->assertSame( $manager, Flyouts::register( 'facadeshop_customer', [ 'title' => 'Customer' ] ) );
		$this->assertTrue( $manager->has_flyout( 'customer' ) );
	}

	/**
	 * An id with no prefix is not a panel.
	 */
	public function test_an_id_without_a_prefix_registers_nothing(): void {
		$this->assertNull( Flyouts::register( 'product', [] ) );
		$this->assertNull( Flyouts::register( '_product', [] ) );
		$this->assertNull( Flyouts::manager( 'nobody' ) );
	}

	/**
	 * Text, class and icon style the button; everything else is data for the panel.
	 */
	public function test_button_splits_its_style_from_its_data(): void {
		Flyouts::register( 'facadebtn_order', [ 'title' => 'Order' ] );

		$html = Flyouts::button(
			'facadebtn_order',
			[
				'id'    => 42,
				'text'  => 'View',
				'class' => 'button-primary',
				'icon'  => 'edit',
			]
		);

		$this->assertStringStartsWith( '<button type="button"', $html );
		$this->assertStringContainsString( 'class="wp-flyout-trigger button-primary"', $html );
		$this->assertStringContainsString( 'data-flyout-manager="facadebtn"', $html );
		$this->assertStringContainsString( 'data-flyout="order"', $html );
		$this->assertStringContainsString( 'data-id="42"', $html );
		$this->assertStringContainsString( 'dashicons-edit', $html );
		$this->assertStringContainsString( '>View</button>', $html );
		$this->assertStringNotContainsString( 'data-text', $html );
		$this->assertStringNotContainsString( 'data-class', $html );
		$this->assertStringNotContainsString( 'data-icon', $html );

		ob_start();
		Flyouts::render_button( 'facadebtn_order', [ 'id' => 42, 'text' => 'View' ] );
		$this->assertStringContainsString( 'data-id="42"', (string) ob_get_clean() );
	}

	/**
	 * A link needs its text, and target is style rather than data.
	 */
	public function test_link_needs_text_and_keeps_its_style_out_of_the_data(): void {
		Flyouts::register( 'facadelink_customer', [ 'title' => 'Customer' ] );

		$this->assertSame( '', Flyouts::link( 'facadelink_customer', [ 'id' => 7 ] ) );

		$html = Flyouts::link(
			'facadelink_customer',
			[
				'id'     => 7,
				'text'   => 'Ada Lovelace',
				'class'  => 'row-title',
				'target' => '_blank',
			]
		);

		$this->assertStringStartsWith( '<a class="wp-flyout-trigger row-title"', $html );
		$this->assertStringContainsString( 'data-id="7"', $html );
		$this->assertStringContainsString( 'href="#"', $html );
		$this->assertStringContainsString( '>Ada Lovelace</a>', $html );
		$this->assertStringNotContainsString( 'data-target', $html );
		$this->assertStringNotContainsString( 'data-text', $html );

		ob_start();
		Flyouts::render_link( 'facadelink_customer', [ 'id' => 7, 'text' => 'Ada' ] );
		$this->assertStringContainsString( '>Ada</a>', (string) ob_get_clean() );
	}

	/**
	 * A panel nobody registered opens from nothing.
	 */
	public function test_an_unknown_panel_draws_nothing(): void {
		$this->assertSame( '', Flyouts::button( 'facadenone_order', [ 'text' => 'View' ] ) );
		$this->assertSame( '', Flyouts::link( 'facadenone_order', [ 'text' => 'View' ] ) );
		$this->assertFalse( Registry::instance()->has( 'facadenone' ) );
	}
}
