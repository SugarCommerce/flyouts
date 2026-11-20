<?php
/**
 * Requests
 *
 * @package SugarCommerce\Flyouts\Components
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts\Traits\Components\Activity;

use SugarCommerce\Flyouts\Components;
use SugarCommerce\Flyouts\Components\Activity;
use SugarCommerce\Flyouts\Manager;
use WP_Error;

/**
 * The feed's requests: adding and deleting a note, the next page, and
 * redrawing the feed after either.
 */
trait Requests {

	/**
	 * The three requests a feed makes, as handlers the REST endpoint runs.
	 *
	 * Built when the panel is registered and kept on the field, keyed by the
	 * action each is sent under. Each answers with the feed drawn again,
	 * as `updates`, which the panel puts in place of the old one.
	 *
	 * @param Manager              $manager   The manager the panel belongs to.
	 * @param string               $flyout_id The panel.
	 * @param string               $key       The field's key.
	 * @param array<string, mixed> $field     The field.
	 *
	 * @return array<string, callable>
	 * @since 1.0.0
	 */
	public static function handlers( Manager $manager, string $flyout_id, string $key, array $field ): array {
		$field = self::configure(
			[
				'name'    => (string) ( $field['name'] ?? $key ),
				'manager' => $manager->get_prefix(),
				'flyout'  => $flyout_id,
			] + $field
		);

		$selector = self::selector( (string) $field['name'] );
		$redraw   = static fn( $id, int $number ): array => [
			$selector => self::redraw( $manager, $flyout_id, $key, $field, $id, $number ),
		];

		$handlers = [];

		if ( is_callable( $field['source'] ) ) {
			$handlers[ sanitize_key( (string) $field['more_action'] ) ] = static function ( array $params ) use ( $field, $redraw ): array {
				// What the button asked for -- what is shown and a page more
				// -- and never more than MAX, whatever a request says.
				$asked  = (int) ( $params['number'] ?? 0 );
				$number = $asked > 0 ? min( self::MAX, $asked ) : self::per_page( $field );

				return [
					'message' => __( 'Earlier activity loaded.', 'sugarcommerce' ),
					'updates' => $redraw( $params['id'] ?? 0, $number ),
				];
			};
		}

		if ( is_callable( $field['add_note'] ) ) {
			$handlers[ sanitize_key( (string) $field['add_action'] ) ] = static function ( array $params ) use ( $field, $redraw ) {
				$content = trim( wp_kses_post( (string) ( $params['content'] ?? '' ) ) );

				if ( '' === $content ) {
					return new WP_Error( 'wp_flyout_empty_note', __( 'A note needs something in it.', 'sugarcommerce' ), [ 'status' => 400 ] );
				}

				if ( ! self::has_record( $params['id'] ?? null ) ) {
					return new WP_Error( 'wp_flyout_no_record', __( 'There is nothing to attach a note to yet.', 'sugarcommerce' ), [ 'status' => 400 ] );
				}

				$params['content'] = $content;

				$added = call_user_func( $field['add_note'], $params );

				if ( is_wp_error( $added ) ) {
					return $added;
				}

				if ( false === $added ) {
					return new WP_Error( 'wp_flyout_note_failed', __( 'The note could not be saved.', 'sugarcommerce' ), [ 'status' => 500 ] );
				}

				return array_merge(
					[ 'message' => __( 'Note added.', 'sugarcommerce' ) ],
					is_array( $added ) ? $added : [],
					[ 'updates' => $redraw( $params['id'], self::per_page( $field ) ) ]
				);
			};
		}

		if ( is_callable( $field['delete_note'] ) ) {
			$handlers[ sanitize_key( (string) $field['delete_action'] ) ] = static function ( array $params ) use ( $field, $redraw ) {
				$note_id = absint( $params['note_id'] ?? 0 );

				if ( $note_id < 1 ) {
					return new WP_Error( 'wp_flyout_no_note', __( 'That note is already gone.', 'sugarcommerce' ), [ 'status' => 404 ] );
				}

				$params['note_id'] = $note_id;

				$deleted = call_user_func( $field['delete_note'], $params );

				if ( is_wp_error( $deleted ) ) {
					return $deleted;
				}

				if ( false === $deleted ) {
					return new WP_Error( 'wp_flyout_note_not_deleted', __( 'The note could not be deleted.', 'sugarcommerce' ), [ 'status' => 500 ] );
				}

				return [
					'message' => __( 'Note deleted.', 'sugarcommerce' ),
					'updates' => $redraw( $params['id'] ?? 0, self::per_page( $field ) ),
				];
			};
		}

		return $handlers;
	}

	/**
	 * The selector an action's `updates` names to redraw a feed in place.
	 *
	 * For an action of the plugin's own that changes a record's history --
	 * a menu item that blocks a customer, a licence deactivated -- and wants
	 * the feed to show it without the panel being loaded again:
	 *
	 *     return [ 'updates' => [ Activity::selector( 'history' ) => Activity::feed( $field, $id ) ] ];
	 *
	 * @param string $name The feed field's name (its key, unless it has a `name`).
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public static function selector( string $name ): string {
		return sprintf( '[data-activity-feed="%s"]', sanitize_key( $name ) );
	}

	/**
	 * One record's feed, drawn from the field's `source`: the markup that
	 * goes inside the feed, as self::selector() names it.
	 *
	 * @param array<string, mixed> $field  The feed field, as the panel declares it.
	 * @param mixed                $id     The record.
	 * @param int                  $number How many events; the field's `number` when 0.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public static function feed( array $field, $id, int $number = 0 ): string {
		$field = self::configure( $field );

		if ( ! is_callable( $field['source'] ) ) {
			return self::render_feed( $field, [], false );
		}

		$number = $number > 0 ? min( self::MAX, $number ) : self::per_page( $field );
		$feed   = self::events_from( call_user_func( $field['source'], $id, [ 'number' => $number ] ), $field );

		return self::render_feed( $field, $feed['events'], $feed['more'] );
	}

	/**
	 * The feed for one record, drawn again.
	 *
	 * From the field's `source` when it has one, and otherwise from the
	 * panel's own `load` -- the same place the panel read it from when it
	 * opened.
	 *
	 * @param Manager              $manager   The manager.
	 * @param string               $flyout_id The panel.
	 * @param string               $key       The field's key.
	 * @param array<string, mixed> $field     The field.
	 * @param mixed                $id        The record.
	 * @param int                  $number    How many events.
	 *
	 * @return string The markup that goes inside the feed.
	 * @since 1.0.0
	 */
	public static function redraw( Manager $manager, string $flyout_id, string $key, array $field, $id, int $number ): string {
		if ( is_callable( $field['source'] ) ) {
			$feed = self::events_from( call_user_func( $field['source'], $id, [ 'number' => $number ] ), $field );
		} else {
			$data = $manager->load_record( $flyout_id, $id );
			$data = is_wp_error( $data ) ? null : $data;

			$feed = self::events_from(
				Components::resolve_value( (string) ( $field['name'] ?? $key ), $data ),
				$field
			);
		}

		return self::render_feed( $field, $feed['events'], $feed['more'] );
	}

	/**
	 * How many a feed shows at first.
	 *
	 * @param array<string, mixed> $config The field.
	 *
	 * @return int
	 */
	private static function per_page( array $config ): int {
		return max( 1, min( self::MAX, (int) ( $config['number'] ?? self::PER_PAGE ) ) );
	}

	/**
	 * How many more each press of "Show Earlier Activity" adds.
	 *
	 * @param array<string, mixed> $config The field.
	 *
	 * @return int
	 */
	private static function step( array $config ): int {
		return max( 1, (int) ( $config['step'] ?? self::per_page( $config ) ) );
	}

	/**
	 * Whether an id names a record that exists.
	 *
	 * @param mixed $id The id.
	 *
	 * @return bool
	 */
	private static function has_record( $id ): bool {
		return is_scalar( $id ) && '' !== (string) $id && '0' !== (string) $id;
	}
}
