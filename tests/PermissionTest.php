<?php
/**
 * Who may open, save and act on a panel.
 *
 * @package SugarCommerce\Flyouts
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts\Tests;

use SugarCommerce\Flyouts\Manager;
use SugarCommerce\Flyouts\RestApi;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;

/**
 * Every endpoint asks the panel's `capability`, `manage_options` when it
 * names none, and a plugin can ask for something else per record.
 */
final class PermissionTest extends TestCase {

	/**
	 * A clean slate.
	 */
	protected function setUp(): void {
		$GLOBALS['fk_filters'] = [];
		$GLOBALS['wf_caps']    = null;
	}

	/**
	 * Restore the all-powerful user.
	 */
	protected function tearDown(): void {
		$GLOBALS['wf_caps'] = null;
	}

	/**
	 * A request to one panel.
	 *
	 * @param string $prefix The manager.
	 * @param string $flyout The panel.
	 *
	 * @return WP_REST_Request
	 */
	private function request( string $prefix, string $flyout ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/load' );
		$request->set_param( 'manager', $prefix );
		$request->set_param( 'flyout', $flyout );
		$request->set_param( 'item_id', 7 );

		return $request;
	}

	/**
	 * The panel's own capability is the one asked for.
	 */
	public function test_the_panel_capability_decides(): void {
		( new Manager( 'permshop' ) )->register_flyout( 'order', [ 'title' => 'Order', 'capability' => 'edit_shop_orders' ] );

		$GLOBALS['wf_caps'] = [ 'read' ];
		$refused            = RestApi::check_permission( $this->request( 'permshop', 'order' ) );

		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'rest_forbidden', $refused->get_error_code() );
		$this->assertSame( 403, $refused->get_error_data()['status'] );

		$GLOBALS['wf_caps'] = [ 'edit_shop_orders' ];
		$this->assertTrue( RestApi::check_permission( $this->request( 'permshop', 'order' ) ) );
	}

	/**
	 * A panel that names no capability, or one nobody registered, needs manage_options.
	 */
	public function test_no_capability_means_manage_options(): void {
		( new Manager( 'permplain' ) )->register_flyout( 'note', [ 'title' => 'Note' ] );

		$GLOBALS['wf_caps'] = [ 'edit_posts' ];
		$this->assertInstanceOf( WP_Error::class, RestApi::check_permission( $this->request( 'permplain', 'note' ) ) );
		$this->assertInstanceOf( WP_Error::class, RestApi::check_permission( $this->request( 'permnobody', 'note' ) ) );

		$GLOBALS['wf_caps'] = [ 'manage_options' ];
		$this->assertTrue( RestApi::check_permission( $this->request( 'permplain', 'note' ) ) );
	}

	/**
	 * The filter can ask for more on a particular record.
	 */
	public function test_the_capability_filter_can_ask_for_more(): void {
		( new Manager( 'permfilter' ) )->register_flyout( 'order', [ 'title' => 'Order', 'capability' => 'edit_shop_orders' ] );

		add_filter(
			'wp_flyout_rest_capability_permfilter',
			static fn( $capability, $flyout, $request ) => 7 === $request->get_param( 'item_id' ) ? 'edit_others_shop_orders' : $capability
		);

		$GLOBALS['wf_caps'] = [ 'edit_shop_orders' ];
		$this->assertInstanceOf( WP_Error::class, RestApi::check_permission( $this->request( 'permfilter', 'order' ) ) );

		$GLOBALS['wf_caps'] = [ 'edit_shop_orders', 'edit_others_shop_orders' ];
		$this->assertTrue( RestApi::check_permission( $this->request( 'permfilter', 'order' ) ) );
	}
}
