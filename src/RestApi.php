<?php
/**
 * Flyout REST API
 *
 * Registers and handles REST API routes for flyout operations.
 *
 * @package     SugarCommerce\Flyouts
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts;

use SugarCommerce\Flyouts\Traits\RestApi\Actions;
use SugarCommerce\Flyouts\Traits\RestApi\Records;
use SugarCommerce\Flyouts\Utils\Runtime;
use WP_Error;
use WP_REST_Request;

/**
 * Class RestApi
 *
 * Handles all REST API routes for flyout load, save, delete, search, and action operations.
 * Routes are registered once globally. Each request resolves the correct Manager and flyout
 * configuration via the Registry singleton.
 */
class RestApi {

	use Records;
	use Actions;

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	public static function rest_namespace(): string {
		return Runtime::rest_namespace();
	}

	/**
	 * Whether routes have been registered.
	 *
	 * @var bool
	 */
	private static bool $routes_registered = false;

	/**
	 * Register REST API routes. Safe to call multiple times — only registers once.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( self::$routes_registered ) {
			return;
		}

		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );

		self::$routes_registered = true;
	}

	/**
	 * Register all REST routes.
	 *
	 * @return void
	 */
	public static function register_routes(): void {

		// Load flyout HTML.
		register_rest_route( self::rest_namespace(), '/load', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_load' ],
			'permission_callback' => [ __CLASS__, 'check_permission' ],
			'args'                => self::get_common_args(),
		] );

		// Save flyout form data.
		register_rest_route( self::rest_namespace(), '/save', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_save' ],
			'permission_callback' => [ __CLASS__, 'check_permission' ],
			'args'                => array_merge( self::get_common_args(), [
				'form_data' => [
					'required' => true,
					'type'     => 'object',
				],
			] ),
		] );

		// Delete record.
		register_rest_route( self::rest_namespace(), '/delete', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_delete' ],
			'permission_callback' => [ __CLASS__, 'check_permission' ],
			'args'                => self::get_common_args(),
		] );

		// Action button/menu callbacks.
		register_rest_route( self::rest_namespace(), '/action', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_action' ],
			'permission_callback' => [ __CLASS__, 'check_permission' ],
			'args'                => array_merge( self::get_common_args(), [
				'action_key' => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				],
			] ),
		] );
	}

	/**
	 * Common args shared by all endpoints.
	 *
	 * @return array
	 */
	private static function get_common_args(): array {
		return [
			'manager' => [
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
			],
			'flyout'  => [
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
			],
			'item_id' => [
				'required'          => false,
				'type'              => [ 'integer', 'string' ],
				'default'           => 0,
				'sanitize_callback' => [ __CLASS__, 'sanitize_item_id' ],
			],
		];
	}

	/**
	 * Clean an item id without deciding what shape it is.
	 *
	 * Ids here are whatever the consumer's `load` and `save` deal in: a post
	 * id is an integer, a licence key or a UUID is a string, and the route
	 * accepts both. An integer is kept as one, so a callback comparing
	 * strictly against what it stored still matches; anything else is
	 * reduced to a line of text, which is the most a string id can be.
	 *
	 * What this does not do is decide whether the current user may touch
	 * the object the id names. It cannot: the library never sees the object.
	 * That is the consumer's check, in the callbacks or through the
	 * `wp_flyout_rest_capability_{prefix}` filter.
	 *
	 * @param mixed $value The submitted id.
	 *
	 * @return int|string
	 */
	public static function sanitize_item_id( $value ) {
		// A string of digits is an integer id that travelled as text, which
		// is what every id in a query string is. Passed on as text it fails
		// a `int $id` parameter under strict types -- and that is the shape
		// of every load callback a consumer writes. Core's own coercion for
		// the integer|string schema made it an int before this sanitizer
		// existed, so this keeps that.
		if ( is_int( $value ) || ( is_string( $value ) && '' !== $value && ctype_digit( $value ) ) ) {
			return (int) $value;
		}

		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	/**
	 * Permission check for all endpoints.
	 *
	 * Resolves the flyout's declared capability, falling back to manage_options.
	 *
	 * @param WP_REST_Request $request Full request object.
	 *
	 * @return bool|WP_Error
	 */
	public static function check_permission( WP_REST_Request $request ) {
		$manager_prefix = $request->get_param( 'manager' );
		$flyout_id      = $request->get_param( 'flyout' );

		// Try to resolve the flyout's specific capability.
		$capability = 'manage_options';
		$manager    = Registry::instance()->get( $manager_prefix );

		if ( $manager ) {
			$config = $manager->get_flyout( $flyout_id );
			if ( $config && ! empty( $config['capability'] ) ) {
				$capability = $config['capability'];
			}
		}

		/**
		 * Filter the required capability for flyout REST endpoints.
		 *
		 * @param string          $capability     Required capability.
		 * @param string          $manager_prefix The manager prefix.
		 * @param string          $flyout_id      The flyout identifier.
		 * @param WP_REST_Request $request        Full request object.
		 */
		$capability = apply_filters( "wp_flyout_rest_capability_{$manager_prefix}", $capability, $flyout_id, $request );

		if ( ! current_user_can( $capability ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to perform this action.', 'sugarcommerce' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	// =========================================================================
	// RESOLUTION HELPERS
	// =========================================================================

	/**
	 * Resolve a Manager instance from the request.
	 *
	 * @param WP_REST_Request $request Full request object.
	 *
	 * @return Manager|WP_Error
	 */
	private static function resolve_manager( WP_REST_Request $request ) {
		$prefix  = $request->get_param( 'manager' );
		$manager = Registry::instance()->get( $prefix );

		if ( ! $manager ) {
			return new WP_Error(
				'flyout_manager_not_found',
				/* translators: %s: flyout manager prefix */
				sprintf( __( 'Flyout manager "%s" not found.', 'sugarcommerce' ), $prefix ),
				[ 'status' => 404 ]
			);
		}

		return $manager;
	}

	/**
	 * Resolve a flyout configuration from the request.
	 *
	 * @param Manager         $manager Manager instance.
	 * @param WP_REST_Request $request Full request object.
	 *
	 * @return array|WP_Error Flyout config array or error.
	 */
	private static function resolve_flyout( Manager $manager, WP_REST_Request $request ) {
		$flyout_id = $request->get_param( 'flyout' );
		$config    = $manager->get_flyout( $flyout_id );

		if ( ! $config ) {
			return new WP_Error(
				'flyout_not_found',
				/* translators: %s: flyout id */
				sprintf( __( 'Flyout "%s" not found.', 'sugarcommerce' ), $flyout_id ),
				[ 'status' => 404 ]
			);
		}

		return $config;
	}
}
