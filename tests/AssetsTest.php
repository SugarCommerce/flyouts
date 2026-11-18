<?php
/**
 * Where the library's own files are served from.
 *
 * @package SugarCommerce\Flyouts
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts\Tests;

use SugarCommerce\Flyouts\Assets;
use PHPUnit\Framework\TestCase;

/**
 * A Composer library has no plugin file to hand plugins_url(), so it finds
 * its URL from its own path under the content directory.
 */
final class AssetsTest extends TestCase {

	/**
	 * The path under wp-content becomes the path under content_url().
	 */
	public function test_an_asset_is_served_from_its_path_under_the_content_directory(): void {
		$under = substr( wp_normalize_path( dirname( __DIR__ ) ), strlen( wp_normalize_path( WP_CONTENT_DIR ) ) );

		$this->assertSame(
			'https://example.test/wp-content' . $under . '/assets/css/wp-flyout.css',
			Assets::url( 'css/wp-flyout.css' )
		);
	}

	/**
	 * Every file the library registers is there to be served.
	 */
	public function test_every_registered_file_exists(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/src/Assets.php' );

		preg_match_all( "/'((?:css|js)\/[a-z\/-]+\.(?:css|js))'/", $source, $found );

		$this->assertNotEmpty( $found[1] );

		foreach ( $found[1] as $relative ) {
			$this->assertFileExists( dirname( __DIR__ ) . '/assets/' . $relative );
		}
	}
}
