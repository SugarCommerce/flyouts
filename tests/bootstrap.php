<?php
/**
 * PHPUnit bootstrap.
 *
 * @package SugarCommerce\Flyouts
 */

declare( strict_types=1 );

/*
 * Dependencies guard their files-autoloaded entrypoints with an ABSPATH
 * check. Composer runs those on require of the autoloader, so the constant
 * has to exist before it or their helpers are never declared.
 */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/**
 * Where the fields package's test helpers are.
 *
 * @return string
 */
function sugarcommerce_flyouts_fields_tests(): string {
	foreach ( [ dirname( __DIR__ ) . '/vendor/sugarcommerce/fields/tests', dirname( __DIR__, 2 ) . '/fields/tests' ] as $dir ) {
		if ( is_file( $dir . '/stubs.php' ) ) {
			return $dir;
		}
	}

	throw new RuntimeException( 'The fields package\'s tests/stubs.php was not found: check sugarcommerce/fields out beside this package.' );
}

/*
 * Declared before the fields package's stubs, whose `current_user_can` always says yes
 * and whose `is_wp_error` always says no. Fine for a field library; useless
 * for testing that a REST endpoint refuses somebody, which is the one thing
 * about it worth testing.
 *
 * $GLOBALS['wf_caps'] is the list of capabilities the current user has.
 * Null means all of them, which keeps every other test from having to care.
 */
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability, ...$args ) {
		$allowed = $GLOBALS['wf_caps'] ?? null;

		return null === $allowed || in_array( $capability, (array) $allowed, true );
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

/*
 * The REST classes, to the extent the endpoints use them: a request is a bag
 * of parameters, a response carries data, and an error is a code, a message
 * and a status.
 */
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {

		/**
		 * @var array<string, mixed>
		 */
		private array $params = [];

		/**
		 * @param string $method The method.
		 * @param string $route  The route.
		 */
		public function __construct( string $method = 'POST', string $route = '' ) {
		}

		/**
		 * @param string $key   Parameter name.
		 * @param mixed  $value Parameter value.
		 *
		 * @return void
		 */
		public function set_param( string $key, $value ): void {
			$this->params[ $key ] = $value;
		}

		/**
		 * @param string $key Parameter name.
		 *
		 * @return mixed
		 */
		public function get_param( string $key ) {
			return $this->params[ $key ] ?? null;
		}

		/**
		 * @return array<string, mixed>
		 */
		public function get_params(): array {
			return $this->params;
		}

		/**
		 * The body, which in these tests is the parameters.
		 *
		 * @return array<string, mixed>
		 */
		public function get_json_params(): array {
			return $this->params;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {

		/**
		 * @var mixed
		 */
		private $data;

		/**
		 * @param mixed $data   The payload.
		 * @param int   $status HTTP status.
		 */
		public function __construct( $data = null, int $status = 200 ) {
			$this->data = $data;
		}

		/**
		 * @return mixed
		 */
		public function get_data() {
			return $this->data;
		}
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {

		/**
		 * @var string
		 */
		private string $code;

		/**
		 * @var string
		 */
		private string $message;

		/**
		 * @var array<string, mixed>
		 */
		private array $data;

		/**
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 * @param mixed  $data    Error data.
		 */
		public function __construct( string $code = '', string $message = '', $data = [] ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = (array) $data;
		}

		/**
		 * @return string
		 */
		public function get_error_code(): string {
			return $this->code;
		}

		/**
		 * @return string
		 */
		public function get_error_message(): string {
			return $this->message;
		}

		/**
		 * @return array<string, mixed>
		 */
		public function get_error_data(): array {
			return $this->data;
		}

		/**
		 * @param mixed  $data Error data.
		 * @param string $code Error code.
		 */
		public function add_data( $data, string $code = '' ): void {
			$this->data = (array) $data;
		}
	}
}

/*
 * The fields package's WordPress stubs, so the components that render
 * through it have the escaping and sanitizing helpers they call. Required
 * before the autoloader for the same reason ABSPATH is.
 *
 * They are export-ignored, so an install from an archive or a path
 * repository does not carry them; the checkout beside this one does, and a
 * source install does too.
 */
require_once sugarcommerce_flyouts_fields_tests() . '/stubs.php';

/*
 * Filters, which the fields package's stubs record but never run — this library's
 * sanitizer applies its own per-type ones and expects them back.
 */
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		foreach ( $GLOBALS['fk_filters'][ $hook ] ?? [] as $callback ) {
			$value = $callback( $value, ...$args );
		}

		return $value;
	}
}

if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( $hook, $callback = false ) {
		return ! empty( $GLOBALS['fk_filters'][ $hook ] );
	}
}

/*
 * The handful core provides that the fields package's stubs have no reason to: the fields package
 * renders fields, and these are what the *components* around them call.
 */
if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = [] ) {
		return array_merge( $defaults, (array) $args );
	}
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4() {
		// Not random — a test that renders twice wants the same markup twice,
		// and nothing here depends on uniqueness across a process.
		return '00000000-0000-4000-8000-000000000000';
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return esc_url_raw( $url );
	}
}

/*
 * An asset's URL is its path relative to the content directory, so both of
 * these have to exist when a component's stylesheet or script is registered.
 */
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', dirname( __DIR__, 3 ) );
}

if ( ! function_exists( 'content_url' ) ) {
	function content_url( $path = '' ) {
		return 'https://example.test/wp-content' . ( '' === $path ? '' : '/' . ltrim( (string) $path, '/' ) );
	}
}

/*
 * An asset's URL is resolved through this, so without it registering a
 * component's stylesheet is fatal here.
 */
if ( ! function_exists( 'wp_normalize_path' ) ) {
	function wp_normalize_path( $path ) {
		$path = str_replace( '\\', '/', (string) $path );
		$path = preg_replace( '|(?<=.)/+|', '/', $path );

		return ':' === substr( $path, 1, 1 ) ? ucfirst( $path ) : $path;
	}
}

/*
 * What an activity feed says about time: the site in UTC, and wp_date() in
 * it. The words are sugarcommerce/dates'.
 */
if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone() {
		return new DateTimeZone( 'UTC' );
	}
}

if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( $format, $timestamp = null, $timezone = null ) {
		return gmdate( (string) $format, (int) ( $timestamp ?? time() ) );
	}
}

if ( ! function_exists( 'wp_specialchars_decode' ) ) {
	function wp_specialchars_decode( $text, $quote_style = ENT_NOQUOTES ) {
		return htmlspecialchars_decode( (string) $text, (int) $quote_style );
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $show = '', $filter = 'raw' ) {
		return 'language' === $show ? 'en-US' : '';
	}
}

if ( ! function_exists( 'wp_register_script' ) ) {
	function wp_register_script( $handle, $src = '', $deps = [], $ver = false, $args = [] ) {
		$GLOBALS['flyouts_test_scripts'][ $handle ]           = $deps;
		$GLOBALS['flyouts_test_script_files'][ $handle ]      = [ $src, $ver, $args ];

		return true;
	}
}

/*
 * A script built with @wordpress/scripts that imports @wordpress/i18n is
 * registered for translation under the family's text domain.
 */
if ( ! function_exists( 'wp_set_script_translations' ) ) {
	function wp_set_script_translations( $handle, $domain = 'default', $path = '' ) {
		$GLOBALS['flyouts_test_translations'][ $handle ] = $domain;

		return true;
	}
}

if ( ! function_exists( 'wp_register_style' ) ) {
	function wp_register_style( $handle, $src = '', $deps = [], $ver = false, $media = 'all' ) {
		return true;
	}
}

if ( ! function_exists( 'wp_add_inline_script' ) ) {
	function wp_add_inline_script( $handle, $data, $position = 'after' ) {
		$GLOBALS['flyouts_test_inline'][ $handle ][] = [ $position, $data ];

		return true;
	}
}

// What a manager's enqueue_assets() calls, so a test can see what it
// publishes: nothing is enqueued, and nothing is there yet.
if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $handle ) {
	}
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( $handle ) {
	}
}

if ( ! function_exists( 'wp_style_is' ) ) {
	function wp_style_is( $handle, $status = 'enqueued' ) {
		return false;
	}
}

if ( ! function_exists( 'wp_script_is' ) ) {
	function wp_script_is( $handle, $status = 'enqueued' ) {
		return false;
	}
}

// Enough of WP_Scripts for the fields package's enqueue() to ask whether its
// own script is registered (it is not, here) and move on.
if ( ! function_exists( 'wp_scripts' ) ) {
	function wp_scripts() {
		return new class() {
			public function query( $handle, $list = 'registered' ) {
				return false;
			}
		};
	}
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
