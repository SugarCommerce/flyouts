<?php
/**
 * The header that says who or what a panel is about.
 *
 * @package SugarCommerce\Flyouts
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts\Tests;

use SugarCommerce\Flyouts\Identity;
use SugarCommerce\Flyouts\Manager;
use SugarCommerce\Flyouts\RestApi;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

/**
 * A panel may put a picture and a few quiet facts in its header, and only
 * a panel that asks is changed.
 *
 * The opt-in is `header`. What matters most here is the other half: a
 * panel that does not declare one is drawn exactly as it was, because this
 * arrived in a library a dozen panels already use.
 */
final class IdentityTest extends TestCase {

	/**
	 * A clean hook registry, and a user who may do anything.
	 */
	protected function setUp(): void {
		$GLOBALS['fk_filters'] = [];
		$GLOBALS['wf_caps']    = null;
	}

	/**
	 * Build a panel for a record and render it.
	 *
	 * @param array<string, mixed> $config Flyout configuration.
	 * @param mixed                $data   The record.
	 *
	 * @return string
	 */
	private function render( array $config, $data = null ): string {
		$manager = new Manager( 'identity' );

		return $manager->build_flyout( $config + [ 'id' => 'panel', 'fields' => [] ], $data, 7 )->render();
	}

	/**
	 * A customer, as a panel would describe one.
	 *
	 * @return array<string, mixed>
	 */
	private static function customer(): array {
		return [
			'title'    => 'Ada Lovelace',
			'subtitle' => 'ada@example.test',
			'badge'    => [ 'label' => 'Active', 'tone' => 'success' ],
			'header'   => [
				'avatar' => [ 'image' => 'https://example.test/ada.png', 'name' => 'Ada Lovelace' ],
				'meta'   => [ [ 'value' => 'United Kingdom', 'image' => 'https://example.test/gb.svg' ] ],
				'copy'   => 'subtitle',
			],
		];
	}

	/**
	 * A panel that declares no header is drawn as it always was.
	 */
	public function test_a_panel_that_does_not_opt_in_is_unchanged(): void {
		$html = $this->render( [ 'title' => 'Order #12', 'subtitle' => 'Placed today' ] );

		$this->assertStringContainsString( '<div class="wp-flyout-header">', $html );
		$this->assertStringContainsString( '<p class="wp-flyout-subtitle">Placed today</p>', $html );

		foreach ( [ 'wp-flyout-identity', 'wp-flyout-header--identity', 'wp-flyout-avatar', 'wp-flyout-meta', 'wp-flyout-subtitle-text', 'wp-flyout-no-steps', 'wp-flyout-footer-when-editing', 'data-copy' ] as $new ) {
			$this->assertStringNotContainsString( $new, $html, $new );
		}
	}

	/**
	 * The picture comes before the name, and says nothing aloud.
	 */
	public function test_the_avatar_is_drawn_before_the_title(): void {
		$html = $this->render( self::customer() );

		$this->assertStringContainsString( 'class="wp-flyout wp-flyout-medium wp-flyout-right wp-flyout-identity"', $html );
		$this->assertStringContainsString( '<div class="wp-flyout-header wp-flyout-header--identity">', $html );
		$this->assertStringContainsString(
			'<span class="wp-flyout-avatar"><img src="https://example.test/ada.png" alt="" width="40" height="40" /></span>',
			$html
		);
		$this->assertLessThan( strpos( $html, 'wp-flyout-title-row' ), strpos( $html, 'wp-flyout-avatar' ) );

		// The name and its state are still the title row's.
		$this->assertMatchesRegularExpression( '/<h2 class="wp-flyout-title" id="panel-title">Ada Lovelace<\/h2>\s*<span class="wp-flyout-badge wp-flyout-badge--success">Active<\/span>/', $html );
	}

	/**
	 * With no picture, the name's initials; with no name, an icon; with
	 * nothing, nothing.
	 */
	public function test_initials_then_an_icon_stand_in_for_a_picture(): void {
		$this->assertStringContainsString(
			'<span class="wp-flyout-avatar wp-flyout-avatar--initials" aria-hidden="true">AL</span>',
			$this->render( [ 'title' => 'Ada', 'header' => [ 'avatar' => [ 'name' => 'Ada King Lovelace' ] ] ] )
		);

		$this->assertStringContainsString(
			'wp-flyout-avatar--icon" aria-hidden="true"><span class="dashicons dashicons-admin-users sugarcommerce-icon" aria-hidden="true"></span></span>',
			$this->render( [ 'title' => 'Ada', 'header' => [ 'avatar' => [ 'icon' => 'admin-users' ] ] ] )
		);

		// The layout without a picture is still the layout.
		$bare = $this->render( [ 'title' => 'Ada', 'header' => [] ] );

		$this->assertStringContainsString( 'wp-flyout-header--identity', $bare );
		$this->assertStringNotContainsString( 'wp-flyout-avatar', $bare );

		$this->assertSame( 'A', Identity::initials( 'ada@example.test' ) );
		$this->assertSame( 'ÉD', Identity::initials( 'élodie durand' ) );
		$this->assertSame( '', Identity::initials( '  ' ) );
	}

	/**
	 * A panel may give the letters and a colour for each: a monogram.
	 *
	 * The letters given are the ones drawn, whatever the name would have
	 * come to, and the two colours are the tile and the letters.
	 */
	public function test_initials_and_a_pair_of_colours_draw_a_monogram(): void {
		$html = $this->render(
			[
				'title'  => 'Ada',
				'header' => [
					'avatar' => [
						'name'       => 'Ada King Lovelace',
						'initials'   => 'K',
						'background' => '#D7DFFB',
						'color'      => '#2f43a3',
					],
				],
			]
		);

		$this->assertStringContainsString(
			'<span class="wp-flyout-avatar wp-flyout-avatar--initials wp-flyout-avatar--tinted" style="background:#d7dffb;color:#2f43a3" aria-hidden="true">K</span>',
			$html
		);
	}

	/**
	 * A colour is read the way it is pasted -- three digits, no hash, upper
	 * case -- and written one way.
	 */
	public function test_a_colour_is_read_leniently_and_written_one_way(): void {
		$html = $this->render( [ 'title' => 'Ada', 'header' => [ 'avatar' => [ 'initials' => 'A', 'background' => '#FDE', 'color' => '2F43A3' ] ] ] );

		$this->assertStringContainsString( 'style="background:#ffddee;color:#2f43a3"', $html );
	}

	/**
	 * An icon from the fields package's sets is drawn as its SVG, on the colours
	 * given -- a collection's icon on the collection's colour -- and one
	 * that is not an icon draws nothing rather than a broken picture.
	 */
	public function test_a_fields_icon_is_drawn_on_its_colour(): void {
		$html = $this->render(
			[
				'title'  => 'Drum kits',
				'header' => [
					'avatar' => [
						'icon'       => 'heroicons-outline:shopping-cart',
						'background' => '#e8a5c4',
						'color'      => '#000000',
					],
				],
			]
		);

		$this->assertStringContainsString(
			'<span class="wp-flyout-avatar wp-flyout-avatar--icon wp-flyout-avatar--tinted" style="background:#e8a5c4;color:#000000" aria-hidden="true"><svg width="20" height="20" class="sugarcommerce-icon" aria-hidden="true"',
			$html
		);
		$this->assertStringContainsString( 'stroke="currentColor"', $html );

		$this->assertStringNotContainsString(
			'wp-flyout-avatar',
			$this->render( [ 'title' => 'Drum kits', 'header' => [ 'avatar' => [ 'icon' => 'heroicons-outline:not-an-icon' ] ] ] )
		);
	}

	/**
	 * A colour is hex or it is nothing, and one without the other is nothing.
	 *
	 * It is written into a style attribute, so nothing that is not a
	 * colour may reach it; and half a pair would be set against the grey
	 * it was never chosen for.
	 */
	public function test_a_colour_that_is_not_a_colour_is_left_out(): void {
		$plain = '<span class="wp-flyout-avatar wp-flyout-avatar--initials" aria-hidden="true">AL</span>';

		foreach (
			[
				[ 'background' => '#d7dffb' ],
				[ 'color' => '#2f43a3' ],
				[ 'background' => 'red;position:fixed', 'color' => '#2f43a3' ],
				[ 'background' => '#d7dffb', 'color' => 'url(https://example.test/x)' ],
				[ 'background' => '#ffddeg', 'color' => '#2f43a3' ],
				[ 'background' => [ '#d7dffb' ], 'color' => '#2f43a3' ],
			] as $colours
		) {
			$html = $this->render( [ 'title' => 'Ada', 'header' => [ 'avatar' => [ 'name' => 'Ada Lovelace' ] + $colours ] ] );

			$this->assertStringContainsString( $plain, $html, (string) wp_json_encode( $colours ) );
			$this->assertStringNotContainsString( 'style="background', $html );
		}
	}

	/**
	 * Letters given are escaped like any other text.
	 */
	public function test_given_initials_are_escaped(): void {
		$html = $this->render( [ 'title' => 'Ada', 'header' => [ 'avatar' => [ 'initials' => '<b>' ] ] ] );

		$this->assertStringContainsString( 'aria-hidden="true">&lt;b&gt;</span>', $html );
	}

	/**
	 * A picture given as a URL on its own is a picture.
	 */
	public function test_an_avatar_may_be_a_url(): void {
		$this->assertStringContainsString(
			'<img src="https://example.test/a.png" alt="" width="40" height="40" />',
			$this->render( [ 'title' => 'Ada', 'header' => [ 'avatar' => 'https://example.test/a.png' ] ] )
		);
	}

	/**
	 * The meta follows the subtitle, each item with its mark, escaped.
	 */
	public function test_meta_follows_the_subtitle(): void {
		$html = $this->render( self::customer() );

		$this->assertStringContainsString(
			'<span class="wp-flyout-meta"><span class="wp-flyout-meta-item"><img class="wp-flyout-meta-image" src="https://example.test/gb.svg" alt="" width="16" height="16" /><span class="wp-flyout-meta-value">United Kingdom</span></span></span>',
			$html
		);
		$this->assertLessThan( strpos( $html, 'wp-flyout-meta' ), strpos( $html, 'wp-flyout-subtitle-text' ) );

		$odd = $this->render(
			[
				'title'  => 'Ada',
				'header' => [
					'meta' => [
						[ 'value' => '<b>Bold</b>', 'icon' => 'location', 'title' => 'Where "they" are' ],
						[ 'value' => '' ],
						'not an item',
					],
				],
			]
		);

		$this->assertStringContainsString( '&lt;b&gt;Bold&lt;/b&gt;', $odd );
		$this->assertStringContainsString( 'title="Where &quot;they&quot; are"', $odd );
		$this->assertStringContainsString( '<span class="dashicons dashicons-location sugarcommerce-icon" aria-hidden="true"></span>', $odd );
		$this->assertSame( 1, substr_count( $odd, 'wp-flyout-meta-item' ) );
	}

	/**
	 * The subtitle can be copied, and the button carries what to copy.
	 */
	public function test_the_subtitle_has_a_copy_button_when_asked(): void {
		$html = $this->render( self::customer() );

		$this->assertStringContainsString(
			'<span class="wp-flyout-subtitle-text">ada@example.test</span><button type="button" class="wp-flyout-copy" data-copy="ada@example.test" aria-label="Copy" title="Copy">',
			$html
		);

		$without = self::customer();
		unset( $without['header']['copy'] );

		$this->assertStringNotContainsString( 'wp-flyout-copy', $this->render( $without ) );
	}

	/**
	 * The avatar and the meta may each be worked out from the record.
	 */
	public function test_the_avatar_and_meta_may_be_callbacks(): void {
		$html = $this->render(
			[
				'title'  => static fn( $data ) => $data->name,
				'header' => [
					'avatar' => static fn( $data, $id ) => [ 'name' => $data->name . ' ' . $id ],
					'meta'   => static fn( $data ) => [ [ 'value' => $data->country ] ],
				],
			],
			(object) [ 'name' => 'Grace Hopper', 'country' => 'United States' ]
		);

		$this->assertStringContainsString( 'wp-flyout-avatar--initials" aria-hidden="true">G7</span>', $html );
		$this->assertStringContainsString( '<span class="wp-flyout-meta-value">United States</span>', $html );
	}

	/**
	 * The menu still sits with the close button, with what a menu button
	 * needs to be one.
	 */
	public function test_the_menu_is_in_the_header_with_its_attributes(): void {
		$html = $this->render( self::customer() + [ 'menu' => [ [ 'label' => 'View Orders', 'url' => 'https://example.test/orders' ] ] ] );

		$this->assertMatchesRegularExpression(
			'/<button type="button"\s+class="wp-flyout-menu-toggle"\s+aria-haspopup="true"\s+aria-expanded="false"\s+aria-controls="panel-menu"\s+aria-label="More actions"/',
			$html
		);
		$this->assertMatchesRegularExpression( '/<div class="wp-flyout-menu-list" id="panel-menu" role="menu" hidden>/', $html );
		$this->assertMatchesRegularExpression( '/role="menuitem"\s+tabindex="-1"\s+href="https:\/\/example.test\/orders"/', $html );
		$this->assertLessThan( strpos( $html, 'wp-flyout-close' ), strpos( $html, 'wp-flyout-menu-toggle' ) );

		$this->assertStringNotContainsString( 'wp-flyout-menu', $this->render( self::customer() ) );
	}

	/**
	 * Previous and next can be switched off, and the trigger says so too.
	 *
	 * The placeholder shown while a record loads is drawn from the trigger,
	 * before the panel has arrived to say anything about itself.
	 */
	public function test_steps_can_be_switched_off(): void {
		$off           = self::customer();
		$off['header'] = [ 'steps' => false ] + $off['header'];

		$this->assertStringContainsString( 'wp-flyout-no-steps', $this->render( $off ) );
		$this->assertStringNotContainsString( 'wp-flyout-no-steps', $this->render( self::customer() ) );

		$manager = new Manager( 'identity_steps' );
		$manager->register_flyout( 'with', self::customer() );
		$manager->register_flyout( 'without', $off );
		$manager->register_flyout( 'plain', [ 'title' => 'Plain' ] );

		$with    = $manager->link( 'with', 'Open', [ 'id' => 1 ] );
		$without = $manager->link( 'without', 'Open', [ 'id' => 1 ] );
		$plain   = $manager->link( 'plain', 'Open', [ 'id' => 1 ] );

		$this->assertStringContainsString( 'data-flyout-header="identity"', $with );
		$this->assertStringNotContainsString( 'data-flyout-steps', $with );
		$this->assertStringContainsString( 'data-flyout-steps="0"', $without );
		$this->assertStringNotContainsString( 'data-flyout-header', $plain );
	}

	/**
	 * The keyboard can stand in for the arrows, or join them.
	 *
	 * `keys` is no arrows and the keys; `both` is the arrows and the keys.
	 * The panel says which with classes and its trigger with an attribute,
	 * for the placeholder. Anything else is the arrows alone, as before.
	 */
	public function test_steps_can_be_the_keyboard(): void {
		$keys           = self::customer();
		$keys['header'] = [ 'steps' => 'keys' ] + $keys['header'];
		$both           = self::customer();
		$both['header'] = [ 'steps' => 'both' ] + $both['header'];
		$odd            = self::customer();
		$odd['header']  = [ 'steps' => 'sideways' ] + $odd['header'];

		$html = $this->render( $keys );

		$this->assertStringContainsString( 'wp-flyout-no-steps', $html );
		$this->assertStringContainsString( 'wp-flyout-step-keys', $html );

		$html = $this->render( $both );

		$this->assertStringNotContainsString( 'wp-flyout-no-steps', $html );
		$this->assertStringContainsString( 'wp-flyout-step-keys', $html );

		foreach ( [ self::customer(), $odd ] as $default ) {
			$html = $this->render( $default );

			$this->assertStringNotContainsString( 'wp-flyout-no-steps', $html );
			$this->assertStringNotContainsString( 'wp-flyout-step-keys', $html );
		}

		$manager = new Manager( 'identity_keys' );
		$manager->register_flyout( 'keys', $keys );
		$manager->register_flyout( 'both', $both );
		$manager->register_flyout( 'odd', $odd );

		$this->assertStringContainsString( 'data-flyout-steps="keys"', $manager->link( 'keys', 'Open', [ 'id' => 1 ] ) );
		$this->assertStringContainsString( 'data-flyout-steps="both"', $manager->link( 'both', 'Open', [ 'id' => 1 ] ) );
		$this->assertStringNotContainsString( 'data-flyout-steps', $manager->link( 'odd', 'Open', [ 'id' => 1 ] ) );

		$this->assertSame( [ 'buttons' => false, 'keys' => true, 'mode' => 'keys' ], Manager::steps( [ 'steps' => 'keys' ] ) );
		$this->assertSame( [ 'buttons' => true, 'keys' => false, 'mode' => '' ], Manager::steps( [] ) );
		$this->assertSame( [ 'buttons' => false, 'keys' => false, 'mode' => '0' ], Manager::steps( [ 'steps' => false ] ) );
	}

	/**
	 * Whether there is a copy button can depend on the record.
	 */
	public function test_copy_can_be_a_function_of_the_record(): void {
		$config                   = self::customer();
		$config['header']['copy'] = static fn( $data ) => 'subtitle';

		$this->assertStringContainsString( 'data-copy=', $this->render( $config ) );

		$config['header']['copy'] = static fn( $data ) => '';

		$this->assertStringNotContainsString( 'data-copy=', $this->render( $config ) );
	}

	/**
	 * A panel that is mostly read can keep its footer until it is edited.
	 */
	public function test_the_footer_can_wait_for_an_edit(): void {
		$this->assertStringContainsString(
			'wp-flyout-footer-when-editing',
			$this->render( [ 'title' => 'Ada', 'footer_when' => 'editing', 'save' => static fn() => true ] )
		);
		$this->assertStringNotContainsString(
			'wp-flyout-footer-when-editing',
			$this->render( [ 'title' => 'Ada', 'save' => static fn() => true ] )
		);
	}

	/**
	 * An action that needs something typed answers with a form, and the
	 * route hands the form on as it is.
	 *
	 * The script shows it and sends what is typed back to the same action
	 * as `fields`, which reach the callback with the rest of the request.
	 */
	public function test_an_action_may_answer_with_a_form(): void {
		$seen    = [];
		$manager = new Manager( 'identity_form' );

		$manager->register_flyout(
			'customer',
			[
				'title' => 'Customer',
				'menu'  => [
					[
						'label'    => 'Send an Email…',
						'action'   => 'email',
						'callback' => static function ( array $params ) use ( &$seen ) {
							$seen[] = $params;

							return isset( $params['fields'] )
								? [ 'message' => 'Sent.' ]
								: [ 'form' => [ 'title' => 'Send an Email', 'fields' => [ [ 'name' => 'subject', 'type' => 'text' ] ] ] ];
						},
					],
				],
			]
		);

		$ask = static function () {
			$request = new WP_REST_Request( 'POST', '/action' );
			$request->set_param( 'manager', 'identity_form' );
			$request->set_param( 'flyout', 'customer' );
			$request->set_param( 'action_key', 'email' );
			$request->set_param( 'item_id', 4 );

			return RestApi::handle_action( $request )->get_data();
		};

		$first = $ask();

		$this->assertTrue( $first['success'] );
		$this->assertSame( 'Send an Email', $first['form']['title'] );
		$this->assertSame( 'subject', $first['form']['fields'][0]['name'] );
		$this->assertSame( 4, $seen[0]['id'] );
	}
}
