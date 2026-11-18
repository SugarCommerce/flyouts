<?php
/**
 * The small facts beside a header's title.
 *
 * @package SugarCommerce\Flyouts
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts\Tests;

use SugarCommerce\Flyouts\Components\Header;
use PHPUnit\Framework\TestCase;

/**
 * A meta item is a value with a mark before it, and the mark can be a
 * picture.
 *
 * The value is escaped, as it should be, and `icon` is the name of a
 * dashicon -- so a country could be named in a header and never flagged.
 * `image` is the way in: a URL, escaped as one, drawn at the icon's size.
 */
final class HeaderMetaTest extends TestCase {

	/**
	 * Render a header with the given meta items.
	 *
	 * @param array<int, array<string, mixed>> $meta The items.
	 *
	 * @return string
	 */
	private function render( array $meta ): string {
		return ( new Header(
			[
				'title' => 'Ada Lovelace',
				'meta'  => $meta,
			]
		) )->render();
	}

	/**
	 * A picture is drawn before the value, at sixteen pixels, saying nothing.
	 */
	public function test_an_image_is_drawn_before_the_value(): void {
		$html = $this->render(
			[
				[
					'value' => 'United Kingdom',
					'image' => 'https://example.test/flags/gb.svg',
				],
			]
		);

		$this->assertStringContainsString(
			'<img class="entity-header-meta-image" src="https://example.test/flags/gb.svg" alt="" width="16" height="16" loading="lazy" />',
			$html
		);
		$this->assertLessThan(
			strpos( $html, 'United Kingdom' ),
			strpos( $html, 'entity-header-meta-image' )
		);

		// In place of an icon, not as well as one.
		$this->assertStringNotContainsString( 'dashicons-', $html );
	}

	/**
	 * The URL is escaped as a URL.
	 *
	 * It comes from configuration, and configuration is sometimes built
	 * from a record. What is pinned here is that it goes through esc_url()
	 * and cannot close its own attribute; refusing a scheme that is not a
	 * web address is esc_url()'s job in WordPress, and the stub these tests
	 * run against does not do it.
	 */
	public function test_the_image_url_is_escaped(): void {
		$html = $this->render(
			[
				[
					'value' => 'Elsewhere',
					'image' => 'https://example.test/a.svg" onerror="alert(1)',
				],
			]
		);

		$this->assertStringNotContainsString( '" onerror="', $html );
		$this->assertSame( 1, substr_count( $html, '<img' ) );
	}

	/**
	 * A dashicon is still a dashicon, and an item with neither has neither.
	 */
	public function test_an_icon_is_still_drawn_as_one(): void {
		$html = $this->render(
			[
				[
					'value' => 'Shipped',
					'icon'  => 'yes',
				],
				[ 'value' => 'Plain' ],
			]
		);

		$this->assertStringContainsString( '<span class="dashicons dashicons-yes sugarcommerce-icon" aria-hidden="true"></span>', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringContainsString( '<span class="meta-value">Plain</span>', $html );
	}

	/**
	 * Given both, the picture wins; given something that is not a URL, nothing breaks.
	 */
	public function test_an_image_is_preferred_to_an_icon(): void {
		$html = $this->render(
			[
				[
					'value' => 'France',
					'icon'  => 'admin-site',
					'image' => 'https://example.test/flags/fr.svg',
				],
				[
					'value' => 'Unknown',
					'icon'  => 'admin-site',
					'image' => [ 'not', 'a', 'url' ],
				],
			]
		);

		$this->assertSame( 1, substr_count( $html, '<img' ) );
		$this->assertSame( 1, substr_count( $html, 'dashicons-admin-site' ) );
	}

	/**
	 * The value is still escaped: the picture did not arrive by loosening that.
	 */
	public function test_the_value_is_still_escaped(): void {
		$html = $this->render( [ [ 'value' => '<img src=x onerror=alert(1)> Spain' ] ] );

		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringContainsString( '&lt;img', $html );
	}
}
