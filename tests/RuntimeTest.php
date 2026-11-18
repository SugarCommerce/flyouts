<?php
/**
 * Runtime key derivation tests.
 *
 * @package SugarCommerce\Flyouts
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts\Tests;

use SugarCommerce\Flyouts\Utils\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Every runtime string this library registers has to differ between two
 * Strauss-prefixed builds. Strauss rewrites namespaces and nothing else, so
 * these tests load namespace-rewritten copies of Runtime — which is exactly
 * what a prefixed build is — and assert the keys come out distinct.
 */
final class RuntimeTest extends TestCase {

	/**
	 * Class names of the simulated builds, keyed by prefix.
	 *
	 * @var array<string, string>
	 */
	private static array $builds = [];

	/**
	 * Write and load a namespace-rewritten copy per prefix.
	 */
	public static function setUpBeforeClass(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/src/Utils/Runtime.php' );
		$dir    = sys_get_temp_dir() . '/sugarcommerce-flyouts-runtime-' . getmypid();

		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0o777, true );
		}

		foreach ( [ 'EDDFF', 'WCR2' ] as $prefix ) {
			$file = $dir . '/' . $prefix . '.php';

			file_put_contents(
				$file,
				str_replace(
					'namespace SugarCommerce\Flyouts\Utils;',
					'namespace ' . $prefix . '\SugarCommerce\Flyouts\Utils;',
					$source
				)
			);

			require_once $file;

			self::$builds[ $prefix ] = $prefix . '\SugarCommerce\Flyouts\Utils\Runtime';
		}
	}

	/**
	 * An unprefixed install keeps the library's own identifiers.
	 */
	public function test_unprefixed_build_uses_the_library_name(): void {
		$this->assertSame( 'sugarcommerce-flyouts', Runtime::prefix() );
		$this->assertSame( 'sugarcommerce-flyouts/v1', Runtime::rest_namespace() );
		$this->assertSame( 'sugarcommerce-flyouts', Runtime::handle() );
		$this->assertSame( 'sugarcommerce_flyouts_export', Runtime::key( 'export' ) );
		$this->assertSame( 'SugarcommerceFlyouts', Runtime::js_object() );
	}

	/**
	 * A prefixed build derives its keys from the consumer's prefix.
	 */
	public function test_prefixed_build_derives_from_the_consumer_prefix(): void {
		$eddff = self::$builds['EDDFF'];

		$this->assertSame( 'eddff-sugarcommerce-flyouts', $eddff::prefix() );
		$this->assertSame( 'eddff-sugarcommerce-flyouts/v1', $eddff::rest_namespace() );
		$this->assertSame( 'eddff_sugarcommerce_flyouts_export', $eddff::key( 'export' ) );
		$this->assertSame( 'EddffSugarcommerceFlyouts', $eddff::js_object() );
	}

	/**
	 * Two prefixed builds must not share a single runtime key.
	 *
	 * This is the property that matters: an EDD plugin and a WooCommerce
	 * plugin bundling this library on one site register nothing in common.
	 */
	public function test_two_builds_share_no_runtime_key(): void {
		$a = self::$builds['EDDFF'];
		$b = self::$builds['WCR2'];

		$methods = [
			'rest_namespace' => [],
			'prefix'         => [],
			'handle'         => [ 'chartjs' ],
			'key'            => [ 'export' ],
			'js_object'      => [],
		];

		// Hooks are deliberately absent. They used to be derived here too,
		// which meant a bundled copy fired `acme_wp_flyout_after_save` — safe
		// from collision and impossible for anyone outside that copy to name,
		// so the extension point existed only in theory. They are literal now
		// and carry the manager's prefix, the flyout's id or the component's
		// type instead, which is the consumer's own namespace.
		foreach ( $methods as $method => $args ) {
			$this->assertNotSame(
				$a::$method( ...$args ),
				$b::$method( ...$args ),
				sprintf( 'Runtime::%s() collides between two prefixed builds.', $method )
			);
		}
	}

	/**
	 * A JS object name is written as `var <name> =`, so it has to be a legal
	 * identifier — the hyphen in a derived handle would be a syntax error.
	 */
	public function test_js_object_is_a_valid_identifier(): void {
		foreach ( self::$builds as $prefix => $class ) {
			$this->assertMatchesRegularExpression(
				'/^[A-Za-z_$][A-Za-z0-9_$]*$/',
				$class::js_object(),
				sprintf( '%s produced an invalid JS identifier.', $prefix )
			);
		}
	}

	/**
	 * A transient key has to fit: WordPress stores it as an option named
	 * `_transient_<key>` in a column capped at 191 characters.
	 */
	public function test_transient_key_leaves_room_for_a_token(): void {
		foreach ( self::$builds as $class ) {
			$key = '_transient_' . $class::key( 'export' ) . '_';

			$this->assertLessThan( 100, strlen( $key ) );
		}
	}


	/**
	 * A plugin of the family prefixes with a namespace that also starts
	 * "SugarCommerce", and its build is still its own; a plugin outside the
	 * family keeps the name its first segment always gave it.
	 */
	public function test_a_family_plugin_build_is_not_taken_for_the_library(): void {
		$this->assertSame(
			'sugarcommerce-playground-vendor-sugarcommerce-flyouts',
			Runtime::prefix_for( 'SugarCommerce\\Playground\\Vendor\\SugarCommerce\\Flyouts\\Utils' )
		);
		$this->assertNotSame(
			Runtime::prefix_for( 'SugarCommerce\\Playground\\Vendor\\SugarCommerce\\Flyouts\\Utils' ),
			Runtime::prefix_for( 'SugarCommerce\\Members\\Vendor\\SugarCommerce\\Flyouts\\Utils' ),
			'Two family plugins share a name.'
		);
		$this->assertSame( 'sugarcart-sugarcommerce-flyouts', Runtime::prefix_for( 'SugarCart\\Vendor\\SugarCommerce\\Flyouts\\Utils' ) );
		$this->assertSame( 'sugarcommerce-flyouts', Runtime::prefix_for( '\\SugarCommerce\\Flyouts\\Utils\\' ) );
	}
}
