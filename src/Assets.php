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
use SugarCommerce\Plugin\Assets as Files;

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
	 * The core script's other handles.
	 *
	 * The panel, the manager, alerts and the prompt were four files and are
	 * one build now (assets/build/wp-flyout.js, from src/js). Each kept its
	 * handle, registered with no file of its own and the one before it as
	 * its dependency, so enqueuing or depending on any of them still brings
	 * the panels -- and the last, which a component depends on, brings all.
	 *
	 * No conditional-fields script: the fields package's Conditions module
	 * reads the same data-conditions attribute, understands the same
	 * operators, and runs over a flyout when it opens. Two implementations
	 * of that would disagree eventually, and the fields package's is the
	 * one the settings and metabox screens already use.
	 *
	 * @var array<string>
	 * @since 1.0.0
	 */
	private static array $core_handles = [ 'manager', 'alert', 'prompt' ];

	/**
	 * Available components and their assets
	 *
	 * `script` is the component's entry in assets/build, `style` a file
	 * under assets, and `deps` what its script needs that it does not import.
	 *
	 * @var array<string, array{script: string, style: string, deps: array}>
	 * @since 1.0.0
	 */
	private static array $components = [
		'image-picker'   => [
			'script' => 'image-picker',
			'style'  => '',
			'deps'   => [ 'wp-mediaelement' ],
		],
		'activity'       => [
			'script' => 'activity',
			'style'  => 'css/components/activity.css',
			'deps'   => [],
		],
		'line-items'     => [
			'script' => 'line-items',
			'style'  => 'css/components/line-items.css',
			'deps'   => [],
		],
		'accordion'      => [
			'script' => 'accordion',
			'style'  => 'css/components/accordion.css',
			'deps'   => [],
		],
		'price-summary'  => [
			'script' => '',
			'style'  => 'css/components/price-summary.css',
			'deps'   => [],
		],
		'action-buttons' => [
			'script' => 'action-buttons',
			'style'  => 'css/components/action-buttons.css',
			'deps'   => [],
		],
		'stats'          => [
			'script' => '',
			'style'  => 'css/components/stats.css',
			'deps'   => [],
		],
		'refund-form' => [
			'script' => 'refund-form',
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

		// The core script, from its build: its manifest names what it
		// imports -- wp-api-fetch, which carries the REST requests, so a
		// nonce that expired while the screen was open is renewed rather
		// than answered with a 403, and wp-dom-ready. In the footer.
		$handle = Runtime::handle( 'wp-flyout' );

		Files::register_built_script( $handle, __FILE__, 'wp-flyout' );

		wp_add_inline_script(
			$handle,
			'window.WPFlyout = window.WPFlyout || {};',
			'before'
		);

		self::$last_handles['script'] = $handle;

		foreach ( self::$core_handles as $suffix ) {
			wp_register_script( Runtime::handle( $suffix ), false, [ self::$last_handles['script'] ], false, true );

			self::$last_handles['script'] = Runtime::handle( $suffix );
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

			// Register component script if exists: its build, after the
			// core script, in the footer.
			if ( ! empty( $config['script'] ) ) {
				Files::register_built_script(
					$handle,
					__FILE__,
					$config['script'],
					array_merge( [ self::$last_handles['script'] ], $config['deps'] ?? [] )
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
