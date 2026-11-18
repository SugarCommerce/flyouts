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

use SugarCommerce\Flyouts\Utils\Runtime;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class RestApi
 *
 * Handles all REST API routes for flyout load, save, delete, search, and action operations.
 * Routes are registered once globally. Each request resolves the correct Manager and flyout
 * configuration via the Registry singleton.
 */
class RestApi {

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

	// =========================================================================
	// ENDPOINT HANDLERS
	// =========================================================================

	/**
	 * Handle flyout load request.
	 *
	 * Calls the flyout's load callback, builds the flyout HTML, and returns it.
	 *
	 * @param WP_REST_Request $request Full request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_load( WP_REST_Request $request ) {
		$manager = self::resolve_manager( $request );
		if ( is_wp_error( $manager ) ) {
			return $manager;
		}

		$config = self::resolve_flyout( $manager, $request );
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$item_id = $request->get_param( 'item_id' );

		// The load callback, and the panel's `_data` filter over what it
		// returned -- see Manager::load_record().
		$data = self::run( [ $manager, 'load_record' ], 'load', (string) $request->get_param( 'flyout' ), $item_id );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( $data === false ) {
			return new WP_Error(
				'flyout_load_failed',
				__( 'Record not found.', 'sugarcommerce' ),
				[ 'status' => 404 ]
			);
		}

		// Build the flyout HTML via the Manager. Inside the same guard as
		// the other callbacks, because it runs some: a title, a badge or a
		// menu may each be a function of the record, and one that throws
		// should be a message in the panel like any other.
		$html = self::run(
			static fn(): string => $manager->build_flyout( $config, $data, $item_id )->render(),
			'render'
		);

		if ( is_wp_error( $html ) ) {
			return $html;
		}

		return new WP_REST_Response( [
			'success' => true,
			'html'    => $html,
		] );
	}

	/**
	 * Handle flyout save request.
	 *
	 * Sanitizes form data and calls the flyout's save callback.
	 *
	 * @param WP_REST_Request $request Full request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_save( WP_REST_Request $request ) {
		$manager = self::resolve_manager( $request );
		if ( is_wp_error( $manager ) ) {
			return $manager;
		}

		$config = self::resolve_flyout( $manager, $request );
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		if ( empty( $config['save'] ) || ! is_callable( $config['save'] ) ) {
			return new WP_Error(
				'flyout_save_not_configured',
				__( 'Save not configured for this flyout.', 'sugarcommerce' ),
				[ 'status' => 500 ]
			);
		}

		$item_id   = $request->get_param( 'item_id' );
		$form_data = $request->get_param( 'form_data' );

		// Sanitized by the field set, so every value is coerced by its own
		// type — the same coercion the same field gets on a settings page or
		// in a metabox — and a key the flyout does not declare is dropped.
		$sanitized = $manager->sanitize( $config['fields'], (array) $form_data );

		$sanitized = apply_filters( 'wp_flyout_before_save_' . $manager->get_prefix(), $sanitized, $config );

		// Run validation callback if provided.
		if ( ! empty( $config['validate'] ) && is_callable( $config['validate'] ) ) {
			$validation = self::run( $config['validate'], 'validate', $sanitized );

			if ( is_wp_error( $validation ) ) {
				return self::with_fields( $validation );
			}

			if ( $validation === false ) {
				return new WP_Error(
					'flyout_validation_failed',
					__( 'Validation failed.', 'sugarcommerce' ),
					[ 'status' => 422 ]
				);
			}
		}

		// Resolve the ID — may come from form data or request param.
		$id = $sanitized['id'] ?? $item_id;

		$result = self::run( $config['save'], 'save', $id, $sanitized );

		do_action( 'wp_flyout_after_save_' . $manager->get_prefix(), $result, $id, $sanitized, $config );

		if ( is_wp_error( $result ) ) {
			return self::with_fields( $result );
		}

		if ( $result === false ) {
			return new WP_Error(
				'flyout_save_failed',
				__( 'Save failed.', 'sugarcommerce' ),
				[ 'status' => 500 ]
			);
		}

		$prefix    = $manager->get_prefix();
		$flyout_id = (string) $request->get_param( 'flyout' );

		// A new record's id is what the save handed back; an existing one's
		// is the one it was asked to save.
		$saved = is_int( $result ) && $result > 0 ? $result : $id;

		/**
		 * Fires once one panel has saved, and only when it has.
		 *
		 * Named for the panel, so an add-on that put a field on it writes
		 * that field here and is not called for every other panel the
		 * manager has. A save that was refused wrote nothing, so there is
		 * nothing to write beside it and this does not fire.
		 *
		 * The values have been through each field's own sanitising, an
		 * added field's included.
		 *
		 * @param int|string           $saved  The record's id.
		 * @param array<string, mixed> $values What was submitted, sanitized.
		 * @param array<string, mixed> $config The panel.
		 *
		 * @since 1.0.0
		 */
		do_action( "wp_flyout_{$prefix}_{$flyout_id}_saved", $saved, $sanitized, $config );

		return new WP_REST_Response( [
			'success' => true,
			'message' => __( 'Saved successfully.', 'sugarcommerce' ),
		] );
	}

	/**
	 * Handle flyout delete request.
	 *
	 * @param WP_REST_Request $request Full request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_delete( WP_REST_Request $request ) {
		$manager = self::resolve_manager( $request );
		if ( is_wp_error( $manager ) ) {
			return $manager;
		}

		$config = self::resolve_flyout( $manager, $request );
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		if ( empty( $config['delete'] ) || ! is_callable( $config['delete'] ) ) {
			return new WP_Error(
				'flyout_delete_not_configured',
				__( 'Delete not configured for this flyout.', 'sugarcommerce' ),
				[ 'status' => 500 ]
			);
		}

		$item_id = $request->get_param( 'item_id' );

		$item_id = apply_filters( 'wp_flyout_before_delete_' . $manager->get_prefix(), $item_id, $config );

		$result = self::run( $config['delete'], 'delete', $item_id );

		do_action( 'wp_flyout_after_delete_' . $manager->get_prefix(), $result, $item_id, $config );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( $result === false ) {
			return new WP_Error(
				'flyout_delete_failed',
				__( 'Delete failed.', 'sugarcommerce' ),
				[ 'status' => 500 ]
			);
		}

		return new WP_REST_Response( [
			'success' => true,
			'message' => __( 'Deleted successfully.', 'sugarcommerce' ),
		] );
	}

	/**
	 * Handle action button/menu callback.
	 *
	 * @param WP_REST_Request $request Full request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_action( WP_REST_Request $request ) {
		$manager = self::resolve_manager( $request );
		if ( is_wp_error( $manager ) ) {
			return $manager;
		}

		$config = self::resolve_flyout( $manager, $request );
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$action_key = (string) $request->get_param( 'action_key' );
		$item_id    = $request->get_param( 'item_id' );

		// Only an action a field declares, by the key it declared it under
		// -- or one the header menu offers for this record.
		$action = self::find_action( $config, $action_key )
			?? self::find_menu_action( $manager, (string) $request->get_param( 'flyout' ), $config, $action_key, $item_id );

		if ( is_wp_error( $action ) ) {
			return $action;
		}

		if ( null === $action ) {
			return new WP_Error(
				'flyout_action_not_found',
				/* translators: %s: action key */
				sprintf( __( 'Action "%s" not found.', 'sugarcommerce' ), $action_key ),
				[ 'status' => 404 ]
			);
		}

		// The action's own capability, on top of the flyout's. The
		// permission callback has already asked whether this person may open
		// the panel; a refund button on it may reasonably ask for more.
		if ( ! current_user_can( $action['capability'] ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to perform this action.', 'sugarcommerce' ),
				[ 'status' => 403 ]
			);
		}

		// Action callbacks receive all request params.
		$params               = (array) $request->get_json_params();
		$params['id']         = $item_id;
		$params['action_key'] = $action_key;

		$result = self::run( $action['callback'], $action_key, $params );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Allow callbacks to return custom response data.
		if ( is_array( $result ) ) {
			return new WP_REST_Response( array_merge( [ 'success' => true ], $result ) );
		}

		return new WP_REST_Response( [
			'success' => true,
			'message' => __( 'Action completed successfully.', 'sugarcommerce' ),
		] );
	}

	/**
	 * Call a consumer's callback without letting it take the admin down.
	 *
	 * These are somebody else's functions, reached from a REST route, and a
	 * TypeError in one is a white screen on the whole page rather than a
	 * failed request — which is what a handler declared as `save( $data )`
	 * did, since every save callback here is called as `( $id, $data )`.
	 *
	 * Caught and returned as an error instead: the panel shows what went
	 * wrong and stays open. Only under WP_DEBUG is the message passed on,
	 * because a message from an exception is as likely to name a file path as
	 * to be useful to anybody.
	 *
	 * @param callable $callback The consumer's callback.
	 * @param string   $what     What it was for, for the message.
	 * @param mixed    ...$args  Arguments to pass.
	 *
	 * @return mixed|WP_Error
	 */
	private static function run( callable $callback, string $what, ...$args ) {
		try {
			return $callback( ...$args );
		} catch ( \Throwable $error ) {
			return new WP_Error(
				'flyout_callback_failed',
				defined( 'WP_DEBUG' ) && WP_DEBUG
					? sprintf(
						/* translators: 1: callback name, 2: error message */
						__( 'The "%1$s" callback failed: %2$s', 'sugarcommerce' ),
						$what,
						$error->getMessage()
					)
					: sprintf(
						/* translators: %s: callback name */
						__( 'The "%s" callback failed.', 'sugarcommerce' ),
						$what
					),
				[ 'status' => 500 ]
			);
		}
	}

	/**
	 * Say which fields an error is about, in one shape.
	 *
	 * A `validate` or `save` callback that knows which field is wrong says
	 * so in the error's data, and the panel shows the message under that
	 * field instead of at the top:
	 *
	 *     new WP_Error( 'too_low', 'A price cannot be negative.', [ 'field' => 'price' ] );
	 *     new WP_Error( 'invalid', 'Two things need fixing.', [
	 *         'fields' => [ 'price' => 'Cannot be negative.', 'sku' => 'Already in use.' ],
	 *     ] );
	 *
	 * `field` is the field's key and takes the error's own message;
	 * `fields` is a map of key to message. Both arrive at the script as
	 * `fields`, so it has one thing to read.
	 *
	 * An error that names a field and no status is a 422. WordPress makes
	 * an error with no status a 500, and "the server failed" is the wrong
	 * thing to tell a monitoring tool about a price somebody mistyped.
	 *
	 * @param WP_Error $error What the callback returned.
	 *
	 * @return WP_Error The same error, with its data filled in.
	 * @since 1.0.0
	 */
	private static function with_fields( WP_Error $error ): WP_Error {
		$data = $error->get_error_data();

		if ( ! is_array( $data ) ) {
			return $error;
		}

		$fields = [];

		foreach ( (array) ( $data['fields'] ?? [] ) as $key => $message ) {
			if ( is_scalar( $message ) && '' !== (string) $message ) {
				$fields[ (string) $key ] = (string) $message;
			}
		}

		$field = $data['field'] ?? '';

		if ( is_string( $field ) && '' !== $field && ! isset( $fields[ $field ] ) ) {
			$fields[ $field ] = $error->get_error_message();
		}

		if ( [] === $fields ) {
			return $error;
		}

		$data['fields'] = $fields;
		$data['status'] = $data['status'] ?? 422;

		$error->add_data( $data );

		return $error;
	}

	// =========================================================================
	// FIELD & ACTION RESOLUTION
	// =========================================================================

	/**
	 * Resolve an action the header menu offers, for this record.
	 *
	 * The menu may be a function of the record, so the record is loaded and
	 * the menu built for it before the key is looked up. That costs a load,
	 * and buys the thing worth having: an action the menu does not show for
	 * this record cannot be run on it. A menu that leaves Delete off a
	 * record that must not be deleted has actually protected it, rather
	 * than only not drawn the button.
	 *
	 * @param Manager              $manager    The manager.
	 * @param string               $flyout_id  The panel.
	 * @param array<string, mixed> $config     Flyout configuration.
	 * @param string               $action_key Action key to find.
	 * @param mixed                $item_id    The record's id.
	 *
	 * @return array{callback: callable, capability: string}|WP_Error|null Null when the menu has no such action.
	 * @since 1.0.0
	 */
	private static function find_menu_action( Manager $manager, string $flyout_id, array $config, string $action_key, $item_id ) {
		if ( '' === $action_key || empty( $config['menu'] ) ) {
			return null;
		}

		$data = null;

		// A list is the same for every record and needs none loaded.
		if ( Manager::is_callback( $config['menu'] ) && is_callable( $config['load'] ?? null ) ) {
			$data = self::run( [ $manager, 'load_record' ], 'load', $flyout_id, $item_id );

			if ( is_wp_error( $data ) ) {
				return $data;
			}

			if ( false === $data ) {
				return null;
			}
		}

		$items = self::run( [ $manager, 'menu_items' ], 'menu', $config, $data, $item_id );

		if ( is_wp_error( $items ) ) {
			return $items;
		}

		foreach ( $items as $item ) {
			if ( $action_key !== ( $item['action'] ?? '' ) || ! is_callable( $item['callback'] ?? null ) ) {
				continue;
			}

			return [
				'callback'   => $item['callback'],
				'capability' => (string) $item['capability'],
			];
		}

		return null;
	}

	/**
	 * Resolve an action a flyout declares, with the capability it demands.
	 *
	 * @param array<string, mixed> $config     Flyout configuration.
	 * @param string               $action_key Action key to find.
	 *
	 * @return array{callback: callable, capability: string}|null Null when no field declares it.
	 */
	private static function find_action( array $config, string $action_key ): ?array {
		// An empty key matches nothing. `required` on the route argument only
		// rejects a *missing* parameter, so an empty string arrives here.
		if ( '' === $action_key ) {
			return null;
		}

		foreach ( (array) ( $config['fields'] ?? [] ) as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$actions = self::field_actions( $field );

			if ( ! isset( $actions[ $action_key ] ) ) {
				continue;
			}

			return [
				'callback'   => $actions[ $action_key ],

				// The same chain the fields package's registration uses, ending at the
				// flyout's own capability rather than at a fixed one, so a
				// field that names nothing asks for what the panel asks for.
				'capability' => (string) (
					$field['action_capability']
					?? $field['capability']
					?? $config['capability']
					?? 'manage_options'
				),
			];
		}

		return null;
	}

	/**
	 * The actions one field declares, keyed as a request names them.
	 *
	 * Declared ones only. This used to accept `{action_key}_callback` on any
	 * field, which made every callable a field carries into an action:
	 * `action_key=search` ran a picker's `search_callback` with the raw
	 * request body as its term, and `sanitize` ran a `sanitize_callback` the
	 * same way. A field says which keys are actions -- a refund form's
	 * `action`, a line items component's `details_key`, an activity feed's
	 * three, each of an action button's `action`, and the named `actions`
	 * it registers with the fields package -- and a key it did
	 * not declare is not one, whatever callable happens to share its name.
	 *
	 * @param array<string, mixed> $field Field configuration.
	 *
	 * @return array<string, callable>
	 */
	private static function field_actions( array $field ): array {
		$type    = (string) ( $field['type'] ?? '' );
		$actions = [];

		// One action with one handler: the refund form. Its handler is the
		// field's action_callback -- the same key a plain action button uses
		// -- with `callback` accepted for the older spelling.
		if ( ! empty( $field['action'] ) ) {
			$actions[ (string) $field['action'] ] = $field['action_callback'] ?? $field['callback'] ?? null;
		}

		// A component that names its action key and carries the handler
		// under `{key}_callback`: line items and its product details.
		$key = (string) ( $field['details_key'] ?? '' );

		if ( '' !== $key ) {
			$actions[ $key ] = $field[ $key . '_callback' ] ?? null;
		}

		// The named handlers a field registers with the fields package.
		foreach ( (array) ( $field['actions'] ?? [] ) as $name => $callback ) {
			$actions[ (string) $name ] = $callback;
		}

		if ( isset( $field['action_callback'] ) ) {
			$actions['run'] = $field['action_callback'];
		}

		// The handlers a component built for itself when the panel was
		// registered -- an activity feed's three. The manager wrote them
		// here, under a key the fields package does not read, so they answer this
		// route and no other.
		foreach ( (array) ( $field['flyout_actions'] ?? [] ) as $name => $callback ) {
			$actions[ (string) $name ] = $callback;
		}

		// Action buttons, each with an action of its own.
		if ( 'action_buttons' === $type ) {
			foreach ( (array) ( $field['buttons'] ?? [] ) as $button ) {
				if ( ! is_array( $button ) || 'separator' === ( $button['type'] ?? '' ) || empty( $button['action'] ) ) {
					continue;
				}

				$actions[ (string) $button['action'] ] = $button['callback'] ?? null;
			}
		}

		return array_filter( $actions, 'is_callable' );
	}
}
