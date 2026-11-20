<?php
/**
 * Activity Component
 *
 * @package     SugarCommerce\Flyouts\Components
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts\Components;

use SugarCommerce\Flyouts\Renderable;
use SugarCommerce\Flyouts\Traits\Components\Activity\Events;
use SugarCommerce\Flyouts\Traits\Components\Activity\Feed;
use SugarCommerce\Flyouts\Traits\Components\Activity\Requests;

/**
 * What happened to a record, newest first, with somewhere to write a note.
 *
 * A list of events as a panel shows them: a small picture for the kind of
 * thing it was, a sentence, and how long ago. A note somebody wrote is drawn
 * apart from what happened -- on a tinted ground, in their words, with their
 * name and a way to remove it -- because it is somebody's own words and not
 * something the system recorded. A box over the list takes a new note, and
 * a button under it loads earlier events.
 *
 * It replaces the `timeline` and `notes` components, which drew the same
 * record's history in two places that did not know about each other: a
 * note added in one never appeared in the other, and each had its own idea
 * of a date.
 *
 * An event is an array -- or an object that serialises to one, so a store's
 * own event class can be handed over as it is:
 *
 *     [
 *         'id'    => 'order-51',            // unique within the feed
 *         'type'  => 'refund',              // the kind: drawn as is-refund
 *         'time'  => '2026-11-17 10:04:00', // UTC, or a timestamp
 *         'icon'  => 'undo',                // a dashicon, or a fields icon (set:name)
 *         'tone'  => 'danger',              // accent, success, warning, danger, info
 *         'text'  => 'Refunded £5.00',      // the sentence, as plain text
 *         'parts' => [                      // or the sentence in pieces, some links
 *             [ 'text' => 'Refunded ' ],
 *             [ 'text' => 'order #51', 'flyout' => 'order', 'id' => 51 ],
 *         ],
 *         'meta'  => [ 'Requested by customer' ], // said quietly after it
 *         'note'  => [ 'id' => 7, 'content' => '…', 'author' => 'Sam' ], // type note only
 *     ]
 *
 * A kind of event can be declared once rather than on every event: `types`
 * on the field, and the `wp_flyout_{prefix}_activity_types` filter for an
 * add-on, map a type to its `icon` and `tone`.
 *
 * Three requests, each answered with the feed drawn again in place: a note
 * added (`add_note`), a note deleted (`delete_note`), earlier events loaded
 * (`source`). The keys they are sent under are `add_action`,
 * `delete_action` and `more_action`, and the manager registers them on the
 * field (see self::handlers()), so a panel declares the callbacks and the
 * rest is done.
 *
 * Everything a callback returns as markup -- `time_format`, `link`,
 * `meta_before` -- is printed as it is: it comes from the plugin's own code,
 * never from a request.
 *
 * @since 1.0.0
 */
class Activity implements Renderable {

	use Feed;
	use Events;
	use Requests;

	/**
	 * How many events a feed shows at first, and how many more each press
	 * of "Show Earlier Activity" adds.
	 *
	 * @since 1.0.0
	 */
	public const int PER_PAGE = 30;

	/**
	 * The most a request for earlier events may ask for.
	 *
	 * @since 1.0.0
	 */
	public const int MAX = 200;

	/**
	 * The tones an event can take: what it means, not what colour it is.
	 *
	 * @since 1.0.0
	 */
	public const array TONES = [ 'accent', 'success', 'warning', 'danger', 'info' ];

	/**
	 * Component configuration.
	 *
	 * @var array<string, mixed>
	 */
	private array $config;

	/**
	 * Set it up.
	 *
	 * @param array<string, mixed> $config Configuration options; see self::defaults().
	 */
	public function __construct( array $config = [] ) {
		$this->config = self::configure( $config );
	}

	/**
	 * A field's configuration with every option filled in.
	 *
	 * @param array<string, mixed> $config The field.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function configure( array $config ): array {
		$config = wp_parse_args( $config, self::defaults() );

		if ( empty( $config['id'] ) ) {
			$config['id'] = 'activity-' . wp_generate_uuid4();
		}

		return $config;
	}

	/**
	 * What every option is when the field does not say.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function defaults(): array {
		return [
			'id'            => '',
			'name'          => 'activity',
			'class'         => '',

			// The events, and whether there are earlier ones. Read from what
			// the panel's `load` returned at the field's key when they are
			// not given -- either `[ 'events' => [], 'more' => bool ]` there,
			// or a plain list of events.
			'events'        => null,
			'more'          => false,

			// fn( $record_id, [ 'number' => n ] ): what a feed is read from
			// when it is drawn again, and how earlier events are loaded.
			// Answers like the load does: `events` and `more`, or a list.
			'source'        => null,
			'number'        => self::PER_PAGE,

			// fn( array $request ): a note written, and a note removed. Each
			// is handed the request -- the record's `id`, and `content` or
			// `note_id` -- and answers with a WP_Error to refuse.
			'add_note'      => null,
			'delete_note'   => null,

			// The keys the three requests are sent under.
			'more_action'   => 'more_activity',
			'add_action'    => 'add_activity_note',
			'delete_action' => 'delete_activity_note',

			// type => [ 'icon' => …, 'tone' => … ], for events that do not
			// say for themselves.
			'types'         => [],

			// Callbacks that return markup: how long ago a moment was
			// (fn( $time )), a part of a sentence that names a record
			// (fn( array $part )), and anything to put before one of the
			// quiet words after it (fn( array $event, int $index )).
			'time_format'   => null,
			'link'          => null,
			'meta_before'   => null,

			// What it says.
			'empty_text'    => __( 'Nothing yet.', 'sugarcommerce' ),
			'hint_types'    => [],
			'placeholder'   => __( 'Write a note…', 'sugarcommerce' ),
			'add_text'      => __( 'Add Note', 'sugarcommerce' ),
			'more_text'     => __( 'Show Earlier Activity', 'sugarcommerce' ),
			'delete_text'   => __( 'Delete', 'sugarcommerce' ),
			'confirm_text'  => __( 'Delete this note?', 'sugarcommerce' ),

			// Set by the manager as the panel is drawn.
			'manager'       => '',
			'flyout'        => '',
			'record_id'     => null,
		];
	}

	// =========================================================================
	// RENDERING
	// =========================================================================

	/**
	 * Draw the feed, with the note box over it.
	 *
	 * The box is left out for a record that does not exist yet -- there is
	 * nothing to attach a note to -- and for a feed with nowhere to send one.
	 *
	 * @return string
	 */
	#[\Override]
	public function render(): string {
		$config = $this->config;
		$feed   = self::feed_data( $config );
		$key    = sanitize_key( (string) $config['name'] );

		$compose = '';

		if ( is_callable( $config['add_note'] ) && self::has_record( $config['record_id'] ) ) {
			$compose = $this->render_compose();
		}

		return sprintf(
			'<div id="%1$s" class="%2$s" data-activity="%3$s">%4$s<div class="wp-flyout-activity-feed" data-activity-feed="%3$s">%5$s</div></div>',
			esc_attr( (string) $config['id'] ),
			esc_attr( trim( 'wp-flyout-activity ' . (string) $config['class'] ) ),
			esc_attr( $key ),
			$compose,
			self::render_feed( $config, $feed['events'], $feed['more'] )
		);
	}

	/**
	 * The box a note is written in.
	 *
	 * One line until something is written in it, and the button with it:
	 * there is nothing to add until then. The textarea has no name, so it
	 * is not part of the panel's form -- writing a note is not an unsaved
	 * change to the record, and Save does not send it.
	 *
	 * @return string
	 */
	private function render_compose(): string {
		$id = (string) $this->config['id'] . '-note';

		return sprintf(
			'<div class="wp-flyout-activity-compose">'
				. '<label class="screen-reader-text" for="%1$s">%2$s</label>'
				. '<textarea id="%1$s" class="wp-flyout-activity-input" rows="1" placeholder="%3$s"></textarea>'
				. '<p class="wp-flyout-activity-compose-actions"><button type="button" class="button button-primary wp-flyout-activity-add" data-action="%4$s">%5$s</button></p>'
				. '</div>',
			esc_attr( $id ),
			esc_html( (string) $this->config['placeholder'] ),
			esc_attr( (string) $this->config['placeholder'] ),
			esc_attr( sanitize_key( (string) $this->config['add_action'] ) ),
			esc_html( (string) $this->config['add_text'] )
		);
	}

	// =========================================================================
	// HELPERS
	// =========================================================================

	/**
	 * A value as text: a number is its digits, anything else nothing.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private static function text( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
