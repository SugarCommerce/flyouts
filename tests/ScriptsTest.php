<?php
/**
 * Built script tests.
 *
 * @package SugarCommerce\Flyouts
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Flyouts\Assets;
use SugarCommerce\Flyouts\Utils\Runtime;
use SugarCommerce\Plugin\Assets as Files;

/**
 * The scripts are built from assets/js into assets/build, and the build is
 * committed: Composer and Strauss copy a package as it is, with nothing run
 * at install. These keep the build and the PHP that loads it in step.
 */
final class ScriptsTest extends TestCase {

	/**
	 * Every entry the PHP loads.
	 */
	private const ENTRIES = [ 'wp-flyout', 'accordion', 'action-buttons', 'activity', 'image-picker', 'line-items', 'refund-form' ];

	/**
	 * A file of the package, for the asset helper to find it from.
	 */
	private const FILE = __DIR__ . '/../src/Assets.php';

	/**
	 * A clean slate.
	 */
	protected function setUp(): void {
		$GLOBALS['flyouts_test_scripts']      = [];
		$GLOBALS['flyouts_test_script_files'] = [];
		$GLOBALS['flyouts_test_translations'] = [];
	}

	/**
	 * Every entry is built, with the manifest @wordpress/scripts writes
	 * beside it, and none of them needs jQuery any more.
	 */
	public function test_every_built_entry_has_its_manifest(): void {
		foreach ( self::ENTRIES as $entry ) {
			$manifest = Files::built_manifest( self::FILE, $entry );

			$this->assertNotNull( $manifest, $entry );
			$this->assertFileExists( dirname( __DIR__ ) . '/assets/build/' . $entry . '.js', $entry );
			$this->assertSame( [], preg_grep( '/^jquery/', $manifest['dependencies'] ), $entry );
			$this->assertNotContains( 'wp-util', $manifest['dependencies'], $entry );
			$this->assertContains( 'wp-dom-ready', $manifest['dependencies'], $entry );
			$this->assertMatchesRegularExpression( '/^[a-f0-9]{20}$/', (string) $manifest['version'], $entry );
		}

		$this->assertNull( Files::built_manifest( self::FILE, 'nothing' ) );
	}

	/**
	 * The core script is the build, under its handle, in the footer, with
	 * the global it has always had made before it runs.
	 */
	public function test_the_core_script_is_the_build(): void {
		Assets::register_assets();

		$handle = Runtime::handle( 'wp-flyout' );

		[ $src, $ver, $args ] = $GLOBALS['flyouts_test_script_files'][ $handle ];

		$this->assertStringEndsWith( '/assets/build/wp-flyout.js', (string) $src );
		$this->assertSame( Files::built_manifest( self::FILE, 'wp-flyout' )['version'], $ver );
		$this->assertTrue( $args['in_footer'] ?? $args );
		$this->assertSame( [ 'wp-api-fetch', 'wp-dom-ready' ], $GLOBALS['flyouts_test_scripts'][ $handle ] );
	}

	/**
	 * The manager's, the alert's and the prompt's handles are still there,
	 * each bringing the one before it, so the last brings the build.
	 */
	public function test_the_old_core_handles_bring_the_build(): void {
		Assets::register_assets();

		$this->assertSame( [ Runtime::handle( 'wp-flyout' ) ], $GLOBALS['flyouts_test_scripts'][ Runtime::handle( 'manager' ) ] );
		$this->assertSame( [ Runtime::handle( 'manager' ) ], $GLOBALS['flyouts_test_scripts'][ Runtime::handle( 'alert' ) ] );
		$this->assertSame( [ Runtime::handle( 'alert' ) ], $GLOBALS['flyouts_test_scripts'][ Runtime::handle( 'prompt' ) ] );

		foreach ( [ 'manager', 'alert', 'prompt' ] as $suffix ) {
			$this->assertFalse( $GLOBALS['flyouts_test_script_files'][ Runtime::handle( $suffix ) ][0], $suffix );
		}
	}

	/**
	 * Each component's script is its build, after the core script, with
	 * what it needs and does not import; those that say something are
	 * translated under the family's text domain.
	 */
	public function test_each_component_is_its_build(): void {
		Assets::register_assets();

		foreach ( [ 'accordion', 'action-buttons', 'activity', 'image-picker', 'line-items', 'refund-form' ] as $component ) {
			$handle = Runtime::handle( $component );
			$deps   = $GLOBALS['flyouts_test_scripts'][ $handle ] ?? null;

			$this->assertIsArray( $deps, $component );
			$this->assertContains( Runtime::handle( 'prompt' ), $deps, $component );
			$this->assertStringEndsWith( '/assets/build/' . $component . '.js', (string) $GLOBALS['flyouts_test_script_files'][ $handle ][0] );
			$this->assertSame(
				in_array( 'wp-i18n', Files::built_manifest( self::FILE, $component )['dependencies'], true ) ? 'sugarcommerce' : null,
				$GLOBALS['flyouts_test_translations'][ $handle ] ?? null,
				$component
			);
		}

		$this->assertContains( 'wp-mediaelement', $GLOBALS['flyouts_test_scripts'][ Runtime::handle( 'image-picker' ) ] );
		$this->assertContains( 'wp-i18n', Files::built_manifest( self::FILE, 'image-picker' )['dependencies'] );
	}

	/**
	 * The sources ship in assets/js beside the build, and nothing loads one
	 * of them directly: only the build is enqueued.
	 */
	public function test_nothing_loads_the_sources(): void {
		$this->assertFileExists( dirname( __DIR__ ) . '/assets/js/wp-flyout.js' );

		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__ ) . '/src', \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $files as $file ) {
			$this->assertStringNotContainsString( "'js/", (string) file_get_contents( $file->getPathname() ), $file->getFilename() );
		}
	}
}
