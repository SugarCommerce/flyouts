<?php
/**
 * How the panels' scripts reach the REST API.
 *
 * @package SugarCommerce\Flyouts
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts\Tests;

use SugarCommerce\Flyouts\Assets;
use SugarCommerce\Flyouts\Utils\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Every request goes through wp.apiFetch, which renews a nonce that ran out
 * while the screen was open. How it is read -- a refusal's message, code and
 * data; an answer that is not JSON -- is tests/js/request.test.js's.
 */
final class RequestScriptTest extends TestCase {

	/**
	 * The build imports apiFetch, so its manifest names it and the core
	 * script is registered with it.
	 */
	public function test_the_core_scripts_need_api_fetch(): void {
		$GLOBALS['flyouts_test_scripts'] = [];

		Assets::register_assets();

		$this->assertContains( 'wp-api-fetch', $GLOBALS['flyouts_test_scripts'][ Runtime::handle( 'wp-flyout' ) ] );
	}

	/**
	 * One module makes the requests; nothing else calls fetch() itself.
	 */
	public function test_no_component_calls_fetch_itself(): void {
		$sources = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__ ) . '/src/js', \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $sources as $file ) {
			if ( 'request.js' === $file->getFilename() ) {
				continue;
			}

			$this->assertDoesNotMatchRegularExpression( '/(?<![.\w])fetch\(|window\.fetch\(|apiFetch\(/', (string) file_get_contents( $file->getPathname() ), $file->getFilename() );
		}
	}
}
