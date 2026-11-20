<?php
/**
 * Loading, Saving and Deleting
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
 * The record routes: loading a flyout's markup, saving what it posts, and
 * deleting the record.
 */
trait Records {

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
}
