<?php
/**
 * The header menu.
 *
 * @package SugarCommerce\Flyouts
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts\Tests;

use SugarCommerce\Flyouts\Manager;
use SugarCommerce\Flyouts\RestApi;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

/**
 * What the "more" menu offers, and what the endpoint will run for it.
 *
 * Two halves that have to agree. The header draws a list of things that can
 * be done to this record; the `/action` route runs one of them. If the
 * route resolved against the configuration rather than against the menu
 * this record was actually shown, leaving Delete off a record would hide a
 * button and protect nothing.
 */
final class MenuTest extends TestCase {

	/**
	 * What the callbacks were handed, if anything.
	 *
	 * @var array<string, mixed>
	 */
	public static array $ran = [];

	/**
	 * Everybody can do everything, and nothing has run.
	 */
	protected function setUp(): void {
		$GLOBALS['fk_filters'] = [];
		$GLOBALS['wf_caps']    = null;
		self::$ran             = [];
	}

	/**
	 * A menu action's handler.
	 *
	 * @param array<string, mixed> $params The request.
	 *
	 * @return array<string, mixed>
	 */
	public static function duplicate( array $params ): array {
		self::$ran['duplicate'] = $params;

		return [ 'message' => 'Duplicated.', 'reload' => true ];
	}

	/**
	 * Another, which destroys something.
	 *
	 * @param array<string, mixed> $params The request.
	 *
	 * @return bool
	 */
	public static function trash( array $params ): bool {
		self::$ran['trash'] = $params;

		return true;
	}

	/**
	 * A static menu: a link, a link in a new tab, and two actions.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function items(): array {
		return [
			[ 'label' => 'View on site', 'url' => 'https://example.com/p/12', 'target' => '_blank' ],
			[ 'label' => 'Edit in full', 'url' => 'https://example.com/wp-admin/post.php?post=12' ],
			[ 'label' => 'Duplicate', 'action' => 'duplicate', 'callback' => [ self::class, 'duplicate' ] ],
			[
				'label'      => 'Move to trash',
				'action'     => 'trash',
				'callback'   => [ self::class, 'trash' ],
				'danger'     => true,
				'confirm'    => 'Move "this" to the trash?',
				'capability' => 'delete_products',
			],
		];
	}

	/**
	 * A manager with one flyout whose menu is `$menu`.
	 *
	 * @param string $prefix The manager's prefix.
	 * @param mixed  $menu   A list, or a callback returning one.
	 *
	 * @return Manager
	 */
	private function manager( string $prefix, $menu ): Manager {
		$manager = new Manager( $prefix );

		$manager->register_flyout(
			'product',
			[
				'title'      => 'Product',
				'capability' => 'edit_products',
				'menu'       => $menu,
				'load'       => static fn( $id ) => $id ? (object) [ 'id' => $id, 'locked' => 99 === $id ] : null,
			]
		);

		return $manager;
	}

	/**
	 * The panel as the load route would render it for one record.
	 *
	 * @param Manager $manager The manager.
	 * @param int     $id      The record.
	 *
	 * @return string
	 */
	private function render( Manager $manager, int $id ): string {
		$config = (array) $manager->get_flyout( 'product' );
		$data   = $config['load']( $id );

		return $manager->build_flyout( $config + [ 'id' => 'panel' ], $data, $id )->render();
	}

	/**
	 * Choose one menu action.
	 *
	 * @param string $prefix     The manager.
	 * @param string $action_key The action.
	 * @param int    $id         The record.
	 *
	 * @return mixed A response or an error.
	 */
	private function choose( string $prefix, string $action_key, int $id = 12 ) {
		$request = new WP_REST_Request( 'POST', '/action' );
		$request->set_param( 'manager', $prefix );
		$request->set_param( 'flyout', 'product' );
		$request->set_param( 'action_key', $action_key );
		$request->set_param( 'item_id', $id );

		return RestApi::handle_action( $request );
	}

	/**
	 * A panel with no menu has no menu button.
	 */
	public function test_no_menu_means_no_button(): void {
		$this->assertStringNotContainsString( 'wp-flyout-menu', $this->render( $this->manager( 'menu_none', [] ), 12 ) );
	}

	/**
	 * The button says what it is and that its list is closed.
	 */
	public function test_the_menu_button_describes_itself(): void {
		$html = $this->render( $this->manager( 'menu_button', self::items() ), 12 );

		$this->assertMatchesRegularExpression(
			'/<button type="button"\s+class="wp-flyout-menu-toggle"\s+aria-haspopup="true"\s+aria-expanded="false"\s+aria-controls="panel-menu"\s+aria-label="More actions"/',
			$html
		);
		$this->assertMatchesRegularExpression( '/<div class="wp-flyout-menu-list" id="panel-menu" role="menu" hidden>/', $html );

		// Beside the close button, and before it.
		$this->assertLessThan( strpos( $html, 'wp-flyout-close' ), strpos( $html, 'wp-flyout-menu-toggle' ) );
	}

	/**
	 * A link is a link; one with a target opens there, safely.
	 */
	public function test_links_are_rendered_as_links(): void {
		$html = $this->render( $this->manager( 'menu_links', self::items() ), 12 );

		$this->assertMatchesRegularExpression(
			'/<a class="wp-flyout-menu-item"\s+role="menuitem"\s+tabindex="-1"\s+href="https:\/\/example\.com\/p\/12"\s+target="_blank"\s+rel="noopener noreferrer"\s*>View on site<\/a>/',
			$html
		);

		preg_match( '/<a class="wp-flyout-menu-item"[^>]*post\.php[^>]*>/', $html, $plain );

		$this->assertNotEmpty( $plain );
		$this->assertStringNotContainsString( 'target=', $plain[0] );
	}

	/**
	 * An action is a button carrying its key, its question and its weight.
	 */
	public function test_actions_are_rendered_as_buttons(): void {
		$html = $this->render( $this->manager( 'menu_actions', self::items() ), 12 );

		$this->assertMatchesRegularExpression(
			'/<button type="button"\s+class="wp-flyout-menu-item"\s+role="menuitem"\s+tabindex="-1"\s+data-action="duplicate"\s*>Duplicate<\/button>/',
			$html
		);

		$this->assertMatchesRegularExpression(
			'/class="wp-flyout-menu-item is-danger"\s+role="menuitem"\s+tabindex="-1"\s+data-action="trash"\s+data-confirm="Move &quot;this&quot; to the trash\?"\s*>Move to trash</',
			$html
		);

		// A callback is not something a page is told about.
		$this->assertStringNotContainsString( 'callback', $html );
	}

	/**
	 * An item can open another of the manager's panels where this one is.
	 *
	 * Drawn as the trigger a row of a list is, so the script that opens
	 * one opens the other; and not drawn at all for a panel that is not
	 * registered or that this person may not open.
	 */
	public function test_an_item_can_open_another_panel(): void {
		$manager = $this->manager(
			'menu_panels',
			[
				[ 'label' => 'View Order', 'flyout' => 'order', 'id' => 51 ],
				[ 'label' => 'View Nothing', 'flyout' => 'missing', 'id' => 3 ],
				[ 'label' => 'View Secret', 'flyout' => 'secret', 'id' => 4 ],
			]
		);

		$manager->register_flyout( 'order', [ 'title' => 'Order', 'capability' => 'edit_products' ] );
		$manager->register_flyout( 'secret', [ 'title' => 'Secret', 'capability' => 'manage_secrets' ] );

		$GLOBALS['wf_caps'] = [ 'edit_products' ];

		$html = $this->render( $manager, 12 );

		$this->assertMatchesRegularExpression(
			'/<button type="button" role="menuitem" tabindex="-1"\s+class="wp-flyout-trigger wp-flyout-menu-item" data-flyout-manager="menu_panels" data-flyout="order"[^>]*data-id="51"\s*>View Order<\/button>/',
			$html
		);
		$this->assertStringNotContainsString( 'View Nothing', $html );
		$this->assertStringNotContainsString( 'View Secret', $html );
	}

	/**
	 * An item that could do nothing is not drawn.
	 */
	public function test_items_with_nothing_to_do_are_left_out(): void {
		$html = $this->render(
			$this->manager(
				'menu_empty',
				[
					[ 'label' => 'No destination' ],
					[ 'label' => 'No handler', 'action' => 'orphan' ],
					[ 'url' => 'https://example.com/unlabelled' ],
					'not an item',
				]
			),
			12
		);

		$this->assertStringNotContainsString( 'wp-flyout-menu', $html );
	}

	/**
	 * An action this person may not run is not offered to them.
	 */
	public function test_an_item_the_user_may_not_use_is_not_offered(): void {
		$GLOBALS['wf_caps'] = [ 'edit_products' ];

		$html = $this->render( $this->manager( 'menu_caps', self::items() ), 12 );

		$this->assertStringContainsString( 'data-action="duplicate"', $html );
		$this->assertStringNotContainsString( 'data-action="trash"', $html );
	}

	/**
	 * A menu can be a function of the record.
	 */
	public function test_a_callable_menu_is_per_record(): void {
		$seen = [];

		$manager = $this->manager(
			'menu_record',
			static function ( $data, $id ) use ( &$seen ): array {
				$seen[] = $id;

				// Nothing to do to a record that does not exist yet, and
				// a locked one cannot be thrown away.
				if ( ! $id ) {
					return [];
				}

				return array_values(
					array_filter(
						self::items(),
						static fn( array $item ): bool => ! $data->locked || 'trash' !== ( $item['action'] ?? '' )
					)
				);
			}
		);

		$this->assertStringNotContainsString( 'wp-flyout-menu', $this->render( $manager, 0 ) );
		$this->assertStringContainsString( 'data-action="trash"', $this->render( $manager, 12 ) );

		$locked = $this->render( $manager, 99 );

		$this->assertStringContainsString( 'data-action="duplicate"', $locked );
		$this->assertStringNotContainsString( 'data-action="trash"', $locked );
		$this->assertSame( [ 0, 12, 99 ], $seen );
	}

	/**
	 * Choosing an action runs its callback, like a component's action.
	 */
	public function test_a_menu_action_runs_through_the_action_route(): void {
		$this->manager( 'menu_runs', self::items() );

		$result = $this->choose( 'menu_runs', 'duplicate' );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame( 'Duplicated.', $result->get_data()['message'] ?? null );
		$this->assertTrue( $result->get_data()['reload'] ?? null );
		$this->assertSame( 12, self::$ran['duplicate']['id'] ?? null );
		$this->assertSame( 'duplicate', self::$ran['duplicate']['action_key'] ?? null );
	}

	/**
	 * A key no item declares is not an action.
	 */
	public function test_an_undeclared_menu_key_is_not_found(): void {
		$this->manager( 'menu_unknown', self::items() );

		foreach ( [ 'view', 'callback', 'load', '' ] as $key ) {
			$result = $this->choose( 'menu_unknown', $key );

			$this->assertInstanceOf( \WP_Error::class, $result, sprintf( '"%s" resolved to an action.', $key ) );
			$this->assertSame( 'flyout_action_not_found', $result->get_error_code() );
		}

		$this->assertSame( [], self::$ran );
	}

	/**
	 * An item's own capability is enforced by the route, not only hidden.
	 */
	public function test_a_menu_action_capability_is_enforced(): void {
		$this->manager( 'menu_forbidden', self::items() );

		$GLOBALS['wf_caps'] = [ 'edit_products' ];

		$result = $this->choose( 'menu_forbidden', 'trash' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
		$this->assertArrayNotHasKey( 'trash', self::$ran, 'The trash action ran for somebody who may not use it.' );

		// With no capability of its own, an item asks for the flyout's.
		$this->assertInstanceOf( \WP_REST_Response::class, $this->choose( 'menu_forbidden', 'duplicate' ) );

		$GLOBALS['wf_caps'] = [ 'manage_options' ];

		$this->assertSame( 'rest_forbidden', $this->choose( 'menu_forbidden', 'duplicate' )->get_error_code() );
	}

	/**
	 * An action the menu did not offer for this record cannot be run on it.
	 *
	 * The whole point of resolving against the record's own menu: a
	 * request for `trash` on the locked record is refused although `trash`
	 * exists, has a handler, and runs for every other record.
	 */
	public function test_an_action_not_offered_for_this_record_is_refused(): void {
		$this->manager(
			'menu_locked',
			static fn( $data ): array => $data && $data->locked
				? [ self::items()[2] ]
				: self::items()
		);

		$refused = $this->choose( 'menu_locked', 'trash', 99 );

		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'flyout_action_not_found', $refused->get_error_code() );
		$this->assertArrayNotHasKey( 'trash', self::$ran, 'Trash ran on a record whose menu does not offer it.' );

		$this->assertInstanceOf( \WP_REST_Response::class, $this->choose( 'menu_locked', 'trash', 12 ) );
		$this->assertSame( 12, self::$ran['trash']['id'] ?? null );
	}

	/**
	 * A menu callback that throws is an error in the panel, not a white screen.
	 */
	public function test_a_menu_callback_that_throws_is_reported(): void {
		$this->manager(
			'menu_throws',
			static function (): array {
				throw new \RuntimeException( 'No menu today.' );
			}
		);

		$result = $this->choose( 'menu_throws', 'duplicate' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'flyout_callback_failed', $result->get_error_code() );

		$request = new WP_REST_Request( 'POST', '/load' );
		$request->set_param( 'manager', 'menu_throws' );
		$request->set_param( 'flyout', 'product' );
		$request->set_param( 'item_id', 12 );

		$loaded = RestApi::handle_load( $request );

		$this->assertInstanceOf( \WP_Error::class, $loaded );
		$this->assertSame( 'flyout_callback_failed', $loaded->get_error_code() );
	}

	/**
	 * A field's action of the same name is still the field's.
	 */
	public function test_a_field_action_takes_precedence_over_the_menu(): void {
		$manager = new Manager( 'menu_clash' );

		$manager->register_flyout(
			'product',
			[
				'title'  => 'Product',
				'menu'   => [ [ 'label' => 'Duplicate', 'action' => 'duplicate', 'callback' => [ self::class, 'trash' ] ] ],
				'fields' => [
					'tools' => [
						'type'    => 'action_buttons',
						'buttons' => [ [ 'label' => 'Duplicate', 'action' => 'duplicate', 'callback' => [ self::class, 'duplicate' ] ] ],
					],
				],
			]
		);

		$this->choose( 'menu_clash', 'duplicate' );

		$this->assertArrayHasKey( 'duplicate', self::$ran );
		$this->assertArrayNotHasKey( 'trash', self::$ran );
	}
}
