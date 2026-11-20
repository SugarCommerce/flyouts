<?php
/**
 * Actions
 *
 * @package SugarCommerce\Flyouts
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts\Traits\RestApi;

use SugarCommerce\Flyouts\Manager;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The action route: a footer, menu or field action run against a record,
 * found among the flyout's own and its fields'.
 */
trait Actions {

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
