<?php
/**
 * Two plugins' copies on one page: each build's own names.
 *
 * @package SugarCommerce\Flyouts
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Fields\Utils\Runtime as FieldsRuntime;
use SugarCommerce\Flyouts\Flyout;
use SugarCommerce\Flyouts\Manager;
use SugarCommerce\Flyouts\Utils\Runtime;

/**
 * Several plugins each bundle their own copy of the library, and two of them
 * can be on one admin page. Each copy's scripts act only on what its own PHP
 * drew -- a trigger or a panel stamped `data-flyout-build` with the build's
 * handle -- and publish nothing but their own entry of the keyed registry:
 * the panel API is the entry's `panel`, and the fields modules a panel starts
 * are those of the fields script the entry names.
 *
 * tests/js/builds.test.js loads two copies on one page and clicks; this
 * holds the PHP and the scripts' sources to the same names.
 */
final class BuildStampTest extends TestCase {

	/**
	 * The page globals the scripts may write: the registry, keyed by build.
	 */
	private const WRITABLE = [ 'SugarCommerceFlyouts' ];

	/**
	 * A clean slate.
	 */
	protected function setUp(): void {
		$GLOBALS['flyouts_test_inline'] = [];
	}

	/**
	 * A manager with one panel anybody may open.
	 *
	 * @return Manager
	 */
	private function manager(): Manager {
		$manager = new Manager( 'stamp' );

		$manager->register_flyout( 'order', [ 'title' => 'Order' ] );

		return $manager;
	}

	/**
	 * A trigger, as a button or a link, carries the build that answers it.
	 */
	public function test_a_trigger_names_its_build(): void {
		$stamp = 'data-flyout-build="' . Runtime::handle() . '"';

		$this->assertStringContainsString( $stamp, $this->manager()->get_button( 'order', [ 'id' => 7 ] ) );
		$this->assertStringContainsString( $stamp, $this->manager()->link( 'order', 'Open', [ 'id' => 7 ] ) );
	}

	/**
	 * So does the panel the load route draws.
	 */
	public function test_a_panel_names_its_build(): void {
		$html = ( new Flyout( 'order' ) )->render();

		$this->assertMatchesRegularExpression( '/<div id="[^"]*"[^>]*\sdata-flyout-build="' . preg_quote( Runtime::handle(), '/' ) . '"/', $html );
	}

	/**
	 * The settings go into the build's own entry, naming the build's own
	 * fields script, and no inline script makes a bare global.
	 */
	public function test_the_settings_are_the_builds_own_entry(): void {
		$this->manager()->enqueue_assets();

		$inline = $GLOBALS['flyouts_test_inline'][ Runtime::handle( 'wp-flyout' ) ] ?? [];

		$this->assertCount( 1, $inline );
		$this->assertSame( 'before', $inline[0][0] );

		$prefix = 'window.SugarCommerceFlyouts=window.SugarCommerceFlyouts||{};window.SugarCommerceFlyouts[' . wp_json_encode( Runtime::handle() ) . ']=';

		$this->assertStringStartsWith( $prefix, $inline[0][1] );

		$settings = json_decode( substr( rtrim( $inline[0][1], ';' ), strlen( $prefix ) ), true );

		$this->assertSame( FieldsRuntime::handle(), $settings['fields'] );

		// Asked of the build's own copy of the fields package, not written:
		// unprefixed, the two read the same, and only one is right in a
		// prefixed build.
		$this->assertMatchesRegularExpression(
			"/'fields'\s*=>\s*FieldsRuntime::handle\(\)/",
			(string) file_get_contents( dirname( __DIR__ ) . '/src/Traits/Manager/Scripts.php' )
		);
		$this->assertArrayHasKey( 'restUrl', $settings );

		foreach ( $GLOBALS['flyouts_test_inline'] as $handle => $scripts ) {
			foreach ( $scripts as [ , $code ] ) {
				$this->assertDoesNotMatchRegularExpression( '/\bwindow\.(?:WPFlyout|wpFlyout)\b/', $code, $handle );
			}
		}
	}

	/**
	 * The scripts write no page global but the registry, and read none of
	 * the bare ones the old copies used -- in the sources or the build.
	 */
	public function test_the_scripts_publish_into_the_registry_only(): void {
		$found = [];

		foreach ( [ 'assets/js', 'assets/build' ] as $folder ) {
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__ ) . '/' . $folder, \FilesystemIterator::SKIP_DOTS ) );

			foreach ( $iterator as $file ) {
				if ( 'js' !== $file->getExtension() ) {
					continue;
				}

				$code = (string) preg_replace( '#/\*.*?\*/|(?<![:"\'])//[^\n]*#s', '', (string) file_get_contents( $file->getPathname() ) );
				$path = $folder . '/' . $file->getFilename();

				preg_match_all( '/\bwindow\.([A-Za-z_$][\w$]*)\s*=(?!=)/', $code, $matches );

				foreach ( array_diff( $matches[1], self::WRITABLE ) as $name ) {
					$found[] = $path . ': window.' . $name;
				}

				if ( preg_match( '/\bWPFlyout\b|\bwpFlyout\b|\bSugarCommerceFieldsModules\b/', $code, $match ) ) {
					$found[] = $path . ': ' . $match[0];
				}
			}
		}

		$this->assertSame( [], $found, "A page global another copy would share:\n" . implode( "\n", $found ) );
	}
}
