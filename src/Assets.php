<?php
/**
 * Assets Manager for WP Flyout
 *
 * Handles registration and enqueuing of CSS and JavaScript assets for the flyout system.
 * Manages both core assets and component-specific assets with dependency resolution.
 *
 * @package     SugarCommerce\Flyouts
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts;

use SugarCommerce\Flyouts\Utils\Runtime;

/**
 * Assets Manager Class
 *
 * Manages all CSS and JavaScript assets for the WP Flyout system,
 * including core files and optional component assets.
 *
 * @since 1.0.0
 */
class Assets {

	/**
	 * Core CSS file
	 *
	 * Single consolidated stylesheet covering flyout shell, layout,
	 * form fields, tabs, footer, and responsive breakpoints.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	private static string $core_style = 'css/wp-flyout.css';

	/**
	 * Core JavaScript files
	 *
	 * @var array<string>
	 * @since 1.0.0
	 */
	// No conditional-fields.js: the fields package's Conditions module reads the same
	// data-conditions attribute, understands the same operators, and now runs
	// over a flyout when it opens. Two implementations of that would disagree
	// eventually, and the fields package's is the one the settings and metabox screens
	// already use.
	private static array $core_scripts = [
		'js/wp-flyout.js',
		'js/core/manager.js',
		'js/core/alert.js',

		// The form an action can ask for before it runs. Last, because it
		// leans on the manager for the request and the toast.
		'js/core/prompt.js',
	];

	/**
	 * Available components and their assets
	 *
	 * @var array<string, array{script: string, style: string, deps: array}>
	 * @since 1.0.0
	 */
	private static array $components = [
		'image-picker'   => [
			'script' => 'js/components/image-picker.js',
			'style'  => '',
			'deps'   => [ 'wp-mediaelement' ],
		],
		'activity'       => [
			'script' => 'js/components/activity.js',
			'style'  => 'css/components/activity.css',
			'deps'   => [],
		],
		'line-items'     => [
			'script' => 'js/components/line-items.js',
			'style'  => 'css/components/line-items.css',
			'deps'   => [],
		],
		'accordion'      => [
			'script' => 'js/components/accordion.js',
			'style'  => 'css/components/accordion.css',
			'deps'   => [],
		],
		'price-summary'  => [
			'script' => '',
			'style'  => 'css/components/price-summary.css',
			'deps'   => [],
		],
		'action-buttons' => [
			'script' => 'js/components/action-buttons.js',
			'style'  => 'css/components/action-buttons.css',
			'deps'   => [],
		],
		'stats'          => [
			'script' => '',
			'style'  => 'css/components/stats.css',
			'deps'   => [],
		],
		'refund-form' => [
			'script' => 'js/components/refund-form.js',
			'style'  => 'css/components/refund-form.css',
			'deps'   => [],
		],
	];

	/**
	 * Track last registered handles
	 *
	 * @var array{style: string, script: string}
	 * @since 1.0.0
	 */
	private static array $last_handles = [
		'style'  => '',
		'script' => '',
	];

	/**
	 * Register all flyout assets
	 *
	 * Registers core CSS/JS files and component assets for later enqueuing.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function register_assets(): void {
		// Register core CSS (single consolidated file)
		wp_register_style(
			Runtime::handle(),
			self::url( self::$core_style ),
			[ 'dashicons' ],
			self::version( self::$core_style )
		);
		self::$last_handles['style'] = Runtime::handle();

		// Register core JavaScript files
		$js_deps = [ 'jquery' ];
		foreach ( self::$core_scripts as $js_file ) {
			$handle = Runtime::handle( basename( $js_file, '.js' ) );

			wp_register_script(
				$handle,
				self::url( $js_file ),
				$js_deps,
				self::version( $js_file ),
				true
			);

			// Add global object to first script
			if ( $js_file === self::$core_scripts[0] ) {
				wp_add_inline_script(
					$handle,
					'window.WPFlyout = window.WPFlyout || {};',
					'before'
				);
			}

			$js_deps                      = [ $handle ];
			self::$last_handles['script'] = $handle;
		}

		// Register component assets
		self::register_components();
	}

	/**
	 * Register component assets
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private static function register_components(): void {
		foreach ( self::$components as $name => $config ) {
			$handle = Runtime::handle( $name );

			// Register component script if exists
			if ( ! empty( $config['script'] ) ) {
				$deps = array_merge(
					[ 'jquery', self::$last_handles['script'] ],
					$config['deps'] ?? []
				);
				wp_register_script(
					$handle,
					self::url( $config['script'] ),
					$deps,
					self::version( $config['script'] ),
					true
				);
			}

			// Register component style if exists
			if ( ! empty( $config['style'] ) ) {
				$style_deps = [ self::$last_handles['style'] ];

				wp_register_style(
					$handle,
					self::url( $config['style'] ),
					$style_deps,
					self::version( $config['style'] )
				);
			}
		}
	}

	/**
	 * Enqueue core flyout assets
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function enqueue(): void {
		// Ensure assets are registered
		if ( ! wp_style_is( self::$last_handles['style'], 'registered' ) ) {
			self::register_assets();
		}

		// Enqueue core style
		wp_enqueue_style( self::$last_handles['style'] );

		// Enqueue all core scripts
		wp_enqueue_script( self::$last_handles['script'] );
	}

	/**
	 * Enqueue specific component assets
	 *
	 * @param string $component Component name to enqueue
	 *
	 * @return bool True if component was enqueued, false if not found
	 * @since 1.0.0
	 */
	public static function enqueue_component( string $component ): bool {
		if ( ! isset( self::$components[ $component ] ) ) {
			return false;
		}

		// Ensure core is loaded first
		self::enqueue();

		$handle = Runtime::handle( $component );
		$config = self::$components[ $component ];

		// Handle dependencies
		if ( ! empty( $config['deps'] ) ) {
			foreach ( $config['deps'] as $dep ) {
				// Check if it's another component dependency
				if ( str_starts_with( $dep, Runtime::handle() . '-' ) ) {
					$dep_component = str_replace( Runtime::handle() . '-', '', $dep );
					if ( isset( self::$components[ $dep_component ] ) ) {
						self::enqueue_component( $dep_component );
					}
				} else {
					// Enqueue WordPress and external dependencies
					wp_enqueue_script( $dep );
					// Also enqueue a matching style where one is registered
					if ( wp_style_is( $dep, 'registered' ) ) {
						wp_enqueue_style( $dep );
					}
				}
			}
		}

		// Enqueue component assets
		if ( wp_style_is( $handle, 'registered' ) ) {
			wp_enqueue_style( $handle );
		}

		if ( wp_script_is( $handle, 'registered' ) ) {
			wp_enqueue_script( $handle );
		}

		// Handle special requirements (media library)
		if ( in_array( $component, [ 'image-picker' ], true ) ) {
			wp_enqueue_media();
		}

		return true;
	}

	/**
	 * The URL of a file under this library's assets directory.
	 *
	 * A Composer library has no plugin file to hand plugins_url(), and it
	 * cannot know whether it sits in a plugin's vendor directory, a theme's,
	 * or Strauss's vendor-prefixed copy. Its own path under the content
	 * directory is the one thing all of those have in common.
	 *
	 * @param string $relative Path under the assets directory.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public static function url( string $relative ): string {
		$path = wp_normalize_path( dirname( __DIR__ ) . '/assets' );
		$root = wp_normalize_path( WP_CONTENT_DIR );

		if ( str_starts_with( $path, $root . '/' ) ) {
			return content_url( substr( $path, strlen( $root ) ) . '/' . $relative );
		}

		return plugins_url( 'assets/' . $relative, dirname( __DIR__ ) . '/placeholder.php' );
	}

	/**
	 * A cache-busting version for one asset.
	 *
	 * The file's own modification time, per file: an edit during
	 * development is picked up without bumping anything by hand, and a
	 * stylesheet-only change does not keep the version a script gave it.
	 *
	 * @param string $relative Path under the assets directory.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	private static function version( string $relative ): string {
		$file = dirname( __DIR__ ) . '/assets/' . $relative;

		return file_exists( $file ) ? (string) filemtime( $file ) : '1.0.0';
	}
}
