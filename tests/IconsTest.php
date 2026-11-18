<?php
/**
 * Every icon a panel draws, in either form.
 *
 * @package SugarCommerce\Flyouts
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts\Tests;

use SugarCommerce\Flyouts\Components\Accordion;
use SugarCommerce\Flyouts\Components\Activity;
use SugarCommerce\Flyouts\Components\Alert;
use SugarCommerce\Flyouts\Components\EmptyState;
use SugarCommerce\Flyouts\Components\Header;
use SugarCommerce\Flyouts\Components\LineItems;
use SugarCommerce\Flyouts\Components\Stats;
use SugarCommerce\Flyouts\Manager;
use SugarCommerce\Flyouts\Utils\Icon;
use PHPUnit\Framework\TestCase;

/**
 * An `icon` may be a fields icon (`heroicons-outline:shopping-cart`), drawn
 * as SVG, or a Dashicons name (`cart`, `dashicons-cart`), drawn as the
 * font's span it always was. The library's own glyphs are Heroicons.
 *
 * Each place that takes an icon is checked in both forms, and each of the
 * library's own glyphs is checked to be SVG -- a Dashicon creeping back
 * into a default is the regression this exists to catch.
 */
final class IconsTest extends TestCase {

	/**
	 * The start of a fields icon's SVG, with the classes it should carry.
	 *
	 * @param string $classes Extra classes after the shared one.
	 *
	 * @return string
	 */
	private static function svg( string $classes = '' ): string {
		return '<svg width="20" height="20" class="' . trim( 'sugarcommerce-icon ' . $classes ) . '" aria-hidden="true"';
	}

	/**
	 * The span a Dashicons name draws.
	 *
	 * @param string $name    The icon's name.
	 * @param string $classes Extra classes after the shared one.
	 *
	 * @return string
	 */
	private static function span( string $name, string $classes = '' ): string {
		return '<span class="' . trim( 'dashicons dashicons-' . $name . ' sugarcommerce-icon ' . $classes ) . '" aria-hidden="true"></span>';
	}

	/**
	 * A panel's whole markup.
	 *
	 * @param array<string, mixed> $config The flyout.
	 *
	 * @return string
	 */
	private function panel( array $config ): string {
		return ( new Manager( 'icons' ) )->build_flyout( $config + [ 'id' => 'panel', 'fields' => [] ], null, 7 )->render();
	}

	// =========================================================================
	// THE HELPER
	// =========================================================================

	/**
	 * Both forms, and nothing for what is neither.
	 */
	public function test_the_helper_reads_both_forms(): void {
		$this->assertStringStartsWith( self::svg( 'x' ), Icon::render( 'heroicons-outline:chart-bar', [ 'class' => 'x' ] ) );
		$this->assertSame( self::span( 'cart' ), Icon::render( 'cart' ) );
		$this->assertSame( self::span( 'cart' ), Icon::render( 'dashicons-cart' ) );
		$this->assertSame( '', Icon::render( 'heroicons-outline:not-an-icon' ) );
		$this->assertSame( '', Icon::render( '<img src=x>' ) );
		$this->assertSame( '', Icon::get( 'not-a-key' ) );
	}

	/**
	 * Every default is a Heroicon that draws, and the script is handed them all.
	 */
	public function test_every_default_is_a_drawn_heroicon(): void {
		$markup = Icon::markup();

		$this->assertSame( array_keys( Icon::DEFAULTS ), array_keys( $markup ) );

		foreach ( Icon::DEFAULTS as $key => $value ) {
			$this->assertStringStartsWith( 'heroicons-outline:', $value, $key );
			$this->assertStringStartsWith( self::svg(), $markup[ $key ], $key );
			$this->assertStringNotContainsString( 'dashicons', $markup[ $key ], $key );
		}
	}

	// =========================================================================
	// THE PANEL'S OWN BUTTONS
	// =========================================================================

	/**
	 * Close and the menu's "more" are Heroicons.
	 */
	public function test_close_and_more_are_heroicons(): void {
		$html = $this->panel(
			[
				'title' => 'Order',
				'menu'  => [
					[
						'label' => 'View',
						'url'   => 'https://example.test/',
					],
				],
			]
		);

		$this->assertMatchesRegularExpression( '/class="wp-flyout-close"[^>]*>\s*' . preg_quote( Icon::get( 'close' ), '/' ) . '\s*<\/button>/', $html );
		$this->assertMatchesRegularExpression( '/class="wp-flyout-menu-toggle"[^>]*>\s*' . preg_quote( Icon::get( 'menu' ), '/' ) . '\s*<\/button>/', $html );
		$this->assertStringNotContainsString( 'dashicons', $html );
	}

	/**
	 * The avatar's icon, the meta's icons and the copy button.
	 */
	public function test_the_identity_takes_either_form(): void {
		$html = $this->panel(
			[
				'title'    => 'Ada',
				'subtitle' => 'ada@example.test',
				'header'   => [
					'avatar' => [ 'icon' => 'heroicons-outline:user' ],
					'meta'   => [
						[
							'value' => 'Ireland',
							'icon'  => 'heroicons-outline:map-pin',
						],
						[
							'value' => 'Since 2024',
							'icon'  => 'dashicons-calendar-alt',
						],
					],
					'copy'   => 'subtitle',
				],
			]
		);

		$this->assertStringContainsString( 'wp-flyout-avatar--icon" aria-hidden="true">' . self::svg(), $html );
		$this->assertStringContainsString( '<span class="wp-flyout-meta-item">' . self::svg(), $html );
		$this->assertStringContainsString( '<span class="wp-flyout-meta-item">' . self::span( 'calendar-alt' ), $html );
		$this->assertStringContainsString( 'class="wp-flyout-copy" data-copy="ada@example.test" aria-label="Copy" title="Copy">' . Icon::get( 'copy' ) . '</button>', $html );
	}

	// =========================================================================
	// COMPONENTS
	// =========================================================================

	/**
	 * An event's icon, either form, and the default when there is none.
	 */
	public function test_an_activity_event_takes_either_form(): void {
		$html = ( new Activity(
			[
				'id'     => 'feed',
				'events' => [
					[
						'type' => 'a',
						'icon' => 'heroicons-outline:receipt-refund',
						'text' => 'Set',
					],
					[
						'type' => 'b',
						'icon' => 'undo',
						'text' => 'Bare',
					],
					[
						'type' => 'c',
						'icon' => 'heroicons-outline:not-an-icon',
						'text' => 'Unknown',
					],
				],
			]
		) )->render();

		$wrap = '<span class="wp-flyout-activity-icon" aria-hidden="true">';

		$this->assertStringContainsString( $wrap . '<svg width="15" height="15" class="sugarcommerce-icon" aria-hidden="true"', $html );
		$this->assertStringContainsString( $wrap . '<span class="dashicons dashicons-undo sugarcommerce-icon" style="width:15px;height:15px;font-size:15px;" aria-hidden="true"></span></span>', $html );

		// An icon no set has is the default, not an empty circle.
		$this->assertSame( 3, substr_count( $html, $wrap . '<' ) );
		$this->assertStringContainsString( $wrap . Icon::get( 'event', [ 'size' => 15 ] ) . '</span>', $html );
	}

	/**
	 * The header component's icon, its meta's, and its picker's.
	 */
	public function test_the_header_component_takes_either_form(): void {
		$set = ( new Header(
			[
				'title' => 'Order',
				'icon'  => 'heroicons-outline:shopping-cart',
				'meta'  => [
					[
						'value' => '24 Aug',
						'icon'  => 'heroicons-outline:calendar',
					],
				],
			]
		) )->render();

		$this->assertMatchesRegularExpression( '/<span class="entity-header-icon" style="width:60px;height:60px;">\s*' . preg_quote( self::svg(), '/' ) . '/', $set );
		$this->assertMatchesRegularExpression( '/<span class="entity-header-meta-item">\s*' . preg_quote( self::svg(), '/' ) . '/', $set );

		// The whole class, which used to come out as dashicons-dashicons-cart.
		$bare = ( new Header(
			[
				'title' => 'Order',
				'icon'  => 'dashicons-cart',
				'meta'  => [
					[
						'value' => '24 Aug',
						'icon'  => 'calendar-alt',
					],
				],
			]
		) )->render();

		$this->assertMatchesRegularExpression( '/<span class="entity-header-icon" [^>]*>\s*' . preg_quote( self::span( 'cart' ), '/' ) . '/', $bare );
		$this->assertStringContainsString( self::span( 'calendar-alt' ), $bare );
		$this->assertStringNotContainsString( 'dashicons-dashicons', $bare );

		// An icon no set has is no tile at all.
		$this->assertStringNotContainsString(
			'entity-header-visual',
			( new Header(
				[
					'title' => 'Order',
					'icon'  => 'heroicons-outline:nope',
				]
			) )->render()
		);
	}

	/**
	 * An editable picture shows the header's icon, or a photo, and carries it for the script.
	 */
	public function test_the_picker_placeholder_is_drawn_and_carried(): void {
		$default = ( new Header(
			[
				'title'    => 'Order',
				'name'     => 'pic',
				'editable' => true,
			]
		) )->render();

		$this->assertMatchesRegularExpression( '/<div class="image-picker-placeholder">\s*' . preg_quote( Icon::get( 'image' ), '/' ) . '/', $default );
		$this->assertStringContainsString( 'data-placeholder="' . esc_attr( Icon::get( 'image' ) ) . '"', $default );
		$this->assertMatchesRegularExpression( '/data-action="select-image"[^>]*>\s*' . preg_quote( Icon::get( 'add' ), '/' ) . '/', $default );
		$this->assertStringNotContainsString( 'dashicons', $default );

		$given = ( new Header(
			[
				'title'    => 'Order',
				'name'     => 'pic',
				'editable' => true,
				'icon'     => 'heroicons-outline:user',
			]
		) )->render();

		$this->assertStringContainsString( 'data-placeholder="' . esc_attr( Icon::render( 'heroicons-outline:user' ) ) . '"', $given );
		$this->assertStringNotContainsString( esc_attr( Icon::get( 'image' ) ), $given );
	}

	/**
	 * An accordion row's icon, either form, and its chevron.
	 */
	public function test_an_accordion_row_takes_either_form(): void {
		$html = ( new Accordion(
			[
				'items' => [
					[
						'title'   => 'One',
						'content' => 'Body',
						'icon'    => 'heroicons-outline:cog-6-tooth',
					],
					[
						'title'   => 'Two',
						'content' => 'Body',
						'icon'    => 'admin-generic',
					],
				],
			]
		) )->render();

		$this->assertStringContainsString( self::svg( 'accordion-icon' ), $html );
		$this->assertStringContainsString( self::span( 'admin-generic', 'accordion-icon' ), $html );
		$this->assertSame( 2, substr_count( $html, Icon::get( 'chevron' ) ) );
		$this->assertStringNotContainsString( 'arrow-down-alt2', $html );
	}

	/**
	 * A stat's icon, either form, and the trend's arrow.
	 */
	public function test_a_stat_takes_either_form(): void {
		$html = ( new Stats(
			[
				'items' => [
					[
						'label'  => 'Orders',
						'value'  => '12',
						'icon'   => 'heroicons-outline:shopping-cart',
						'change' => '+2',
						'trend'  => 'up',
					],
					[
						'label'  => 'Refunds',
						'value'  => '1',
						'icon'   => 'undo',
						'change' => '-1',
						'trend'  => 'down',
					],
				],
			]
		) )->render();

		$this->assertStringContainsString( self::svg( 'stat-icon' ), $html );
		$this->assertStringContainsString( self::span( 'undo', 'stat-icon' ), $html );
		$this->assertStringContainsString( Icon::get( 'up' ), $html );
		$this->assertStringContainsString( Icon::get( 'down' ), $html );
		$this->assertStringNotContainsString( 'arrow-up-alt', $html );
	}

	/**
	 * An empty state's icon, either form; with none given, a Heroicon.
	 */
	public function test_an_empty_state_takes_either_form(): void {
		$wrap = '<span class="empty-state-icon" aria-hidden="true">';

		$this->assertStringContainsString( $wrap . Icon::get( 'empty' ) . '</span>', ( new EmptyState( [ 'title' => 'None' ] ) )->render() );
		$this->assertStringContainsString( $wrap . self::svg(), ( new EmptyState( [ 'icon' => 'heroicons-outline:inbox' ] ) )->render() );
		$this->assertStringContainsString( $wrap . self::span( 'portfolio' ) . '</span>', ( new EmptyState( [ 'icon' => 'dashicons-portfolio' ] ) )->render() );

		// One no set has is the default, not an empty circle; none at all is none.
		$this->assertStringContainsString( $wrap . Icon::get( 'empty' ) . '</span>', ( new EmptyState( [ 'icon' => 'heroicons-outline:nope' ] ) )->render() );
		$this->assertStringNotContainsString( 'empty-state-icon', ( new EmptyState( [ 'icon' => '' ] ) )->render() );
	}

	/**
	 * Each kind of alert has its Heroicon, and so does dismissing one.
	 */
	public function test_an_alert_is_marked_with_heroicons(): void {
		foreach ( [ 'success', 'error', 'warning', 'info' ] as $style ) {
			$html = ( new Alert(
				[
					'style'   => $style,
					'message' => 'Hi',
				]
			) )->render();

			$this->assertMatchesRegularExpression( '/<div class="alert-icon">\s*' . preg_quote( Icon::get( $style ), '/' ) . '/', $html, $style );
			$this->assertMatchesRegularExpression( '/data-action="dismiss-alert"[^>]*>\s*' . preg_quote( Icon::get( 'close' ), '/' ) . '/', $html, $style );
			$this->assertStringNotContainsString( 'dashicons', $html, $style );
		}

		$this->assertStringContainsString( Icon::get( 'info' ), ( new Alert( [ 'style' => 'purple', 'message' => 'Hi' ] ) )->render() );
	}

	/**
	 * A trigger button draws its `icon` before its text, in either form.
	 *
	 * The argument was accepted and silently dropped; a trigger with none,
	 * or one that draws nothing, is still just its text.
	 */
	public function test_a_trigger_button_draws_its_icon(): void {
		$manager = new Manager( 'icons_trigger' );
		$manager->register_flyout( 'edit', [ 'title' => 'Edit' ] );

		$set = $manager->get_button( 'edit', [], [ 'text' => 'Edit', 'icon' => 'heroicons-outline:pencil' ] );

		$this->assertStringContainsString(
			'>' . Icon::render( 'heroicons-outline:pencil', [ 'class' => 'wp-flyout-trigger-icon' ] ) . 'Edit</button>',
			$set
		);
		$this->assertStringContainsString( self::svg( 'wp-flyout-trigger-icon' ), $set );

		$this->assertStringContainsString(
			'>' . self::span( 'edit', 'wp-flyout-trigger-icon' ) . 'Edit</button>',
			$manager->get_button( 'edit', [], [ 'text' => 'Edit', 'icon' => 'dashicons-edit' ] )
		);

		foreach ( [ [], [ 'icon' => '' ], [ 'icon' => 'heroicons-outline:nope' ] ] as $args ) {
			$this->assertStringEndsWith( '">Edit</button>', $manager->get_button( 'edit', [], $args + [ 'text' => 'Edit' ] ) );
		}
	}

	/**
	 * A line item's placeholder and remove button, and the empty list's cart.
	 */
	public function test_line_items_draw_heroicons(): void {
		$rows = ( new LineItems(
			[
				'name'  => 'items',
				'items' => [
					[
						'id'       => 1,
						'name'     => 'A thing',
						'quantity' => 1,
						'price'    => 9.00,
					],
				],
			]
		) )->render();

		$this->assertStringContainsString( Icon::get( 'image' ), $rows );
		$this->assertMatchesRegularExpression( '/data-action="remove-item"[^>]*>\s*' . preg_quote( Icon::get( 'trash' ), '/' ) . '/', $rows );

		$empty = ( new LineItems(
			[
				'name'  => 'items',
				'items' => [],
			]
		) )->render();

		$this->assertMatchesRegularExpression( '/<div class="line-items-empty">\s*' . preg_quote( Icon::get( 'cart' ), '/' ) . '/', $empty );
	}
}
