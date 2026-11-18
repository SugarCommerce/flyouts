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

use SugarCommerce\Dates\Clock;
use SugarCommerce\Flyouts\Utils\Icon;
use SugarCommerce\Flyouts\Components;
use SugarCommerce\Flyouts\Manager;
use SugarCommerce\Flyouts\Registry;
use SugarCommerce\Flyouts\Renderable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use JsonSerializable;
use Throwable;
use WP_Error;

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

	/**
	 * How many events a feed shows at first, and how many more each press
	 * of "Show Earlier Activity" adds.
	 *
	 * @since 1.0.0
	 */
	public const PER_PAGE = 30;

	/**
	 * The most a request for earlier events may ask for.
	 *
	 * @since 1.0.0
	 */
	public const MAX = 200;

	/**
	 * The tones an event can take: what it means, not what colour it is.
	 *
	 * @since 1.0.0
	 */
	public const TONES = [ 'accent', 'success', 'warning', 'danger', 'info' ];

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

	/**
	 * The list itself: what is redrawn in place after each request.
	 *
	 * @param array<string, mixed>             $config The field.
	 * @param array<int, array<string, mixed>> $events Normalised events.
	 * @param bool                             $more   Whether there are earlier ones.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public static function render_feed( array $config, array $events, bool $more ): string {
		$config = self::configure( $config );
		$empty  = (string) $config['empty_text'];

		if ( [] === $events ) {
			return '' === $empty ? '' : '<p class="wp-flyout-activity-empty">' . esc_html( $empty ) . '</p>';
		}

		$items = '';

		foreach ( $events as $event ) {
			$items .= self::item( $config, $event );
		}

		// Only where it starts, so far -- every event so far is one of the
		// kinds that open a record's history: say what will join them.
		$hint       = '';
		$hint_types = array_map( 'strval', (array) $config['hint_types'] );

		if ( '' !== $empty && [] !== $hint_types && [] === array_diff( array_column( $events, 'type' ), $hint_types ) ) {
			$hint = '<p class="wp-flyout-activity-empty wp-flyout-activity-hint">' . esc_html( $empty ) . '</p>';
		}

		$button = '';

		if ( $more && is_callable( $config['source'] ) ) {
			$button = sprintf(
				'<p class="wp-flyout-activity-more"><button type="button" class="button wp-flyout-row-action" data-action="%1$s" data-number="%2$d">%3$s</button></p>',
				esc_attr( sanitize_key( (string) $config['more_action'] ) ),
				count( $events ) + self::step( $config ),
				esc_html( (string) $config['more_text'] )
			);
		}

		return '<ol class="wp-flyout-activity-list">' . $items . '</ol>' . $hint . $button;
	}

	/**
	 * One event.
	 *
	 * @param array<string, mixed> $config The field.
	 * @param array<string, mixed> $event  A normalised event.
	 *
	 * @return string
	 */
	private static function item( array $config, array $event ): string {
		$classes = [ 'wp-flyout-activity-item' ];

		if ( '' !== $event['type'] ) {
			$classes[] = 'is-' . sanitize_html_class( $event['type'] );
		}

		if ( '' !== $event['tone'] ) {
			$classes[] = 'is-' . $event['tone'];
		}

		$icon = self::icon( $event['icon'] );
		$time = self::time( $config, $event['time'] );

		if ( null !== $event['note'] ) {
			$classes[] = 'is-note';

			return sprintf(
				'<li class="%1$s">%2$s<div class="wp-flyout-activity-body"><div class="wp-flyout-activity-note">%3$s</div><p class="wp-flyout-activity-by">%4$s</p></div></li>',
				esc_attr( implode( ' ', array_unique( $classes ) ) ),
				$icon,
				nl2br( esc_html( wp_specialchars_decode( $event['note']['content'], ENT_QUOTES ) ) ),
				implode(
					'<span class="wp-flyout-activity-dot" aria-hidden="true">&middot;</span>',
					array_filter(
						[
							esc_html( $event['note']['author'] ),
							$time,
							self::delete_button( $config, $event['note'] ),
						]
					)
				)
			);
		}

		// A sentence that is one link from end to end is drawn as words
		// that can be pressed, not as a line of blue: a feed where every
		// row is a link would otherwise be a column of them.
		if ( 1 === count( $event['parts'] ) && self::is_link_part( $event['parts'][0] ) ) {
			$classes[] = 'is-link';
		}

		return sprintf(
			'<li class="%1$s">%2$s<div class="wp-flyout-activity-body"><p class="wp-flyout-activity-text">%3$s%4$s</p>%5$s</div></li>',
			esc_attr( implode( ' ', $classes ) ),
			$icon,
			self::sentence( $config, $event ),
			self::meta( $config, $event ),
			'' !== $time ? '<span class="wp-flyout-activity-time">' . $time . '</span>' : ''
		);
	}

	/**
	 * The picture for the kind of event: a dashicon, or a fields icon.
	 *
	 * @param string $icon `marker`, or `heroicons-outline:receipt-refund`.
	 *
	 * @return string
	 */
	private static function icon( string $icon ): string {
		$glyph = Icon::render( $icon, [ 'size' => 15 ] );

		return '<span class="wp-flyout-activity-icon" aria-hidden="true">' . ( '' !== $glyph ? $glyph : Icon::get( 'event', [ 'size' => 15 ] ) ) . '</span>';
	}

	/**
	 * A note's Delete, when the feed can delete and the note may be.
	 *
	 * A row action of the panel's, so it asks first in the panel's own
	 * words and answers with the feed drawn again.
	 *
	 * @param array<string, mixed>                                 $config The field.
	 * @param array{id: int, content: string, author: string, can_delete: bool} $note The note.
	 *
	 * @return string
	 */
	private static function delete_button( array $config, array $note ): string {
		if ( ! is_callable( $config['delete_note'] ) || $note['id'] < 1 || ! $note['can_delete'] ) {
			return '';
		}

		return sprintf(
			'<button type="button" class="button-link wp-flyout-row-action is-danger" data-action="%1$s" data-note-id="%2$d" data-confirm="%3$s">%4$s</button>',
			esc_attr( sanitize_key( (string) $config['delete_action'] ) ),
			$note['id'],
			esc_attr( (string) $config['confirm_text'] ),
			esc_html( (string) $config['delete_text'] )
		);
	}

	/**
	 * An event's sentence, with a link wherever a part names something.
	 *
	 * @param array<string, mixed> $config The field.
	 * @param array<string, mixed> $event  A normalised event.
	 *
	 * @return string
	 */
	private static function sentence( array $config, array $event ): string {
		if ( [] === $event['parts'] ) {
			return esc_html( $event['text'] );
		}

		$html = '';

		foreach ( $event['parts'] as $part ) {
			$html .= self::is_link_part( $part ) ? self::link( $config, $part ) : esc_html( $part['text'] );
		}

		return $html;
	}

	/**
	 * Whether a part of a sentence names something it can open.
	 *
	 * @param array<string, mixed> $part A normalised part.
	 *
	 * @return bool
	 */
	private static function is_link_part( array $part ): bool {
		return isset( $part['url'] ) || isset( $part['flyout'], $part['id'] );
	}

	/**
	 * A part of a sentence that names something, as a link to it.
	 *
	 * A `url` is a link. A `flyout` and `id` is that panel of this manager,
	 * opened where this one is -- and plain words for somebody who may not
	 * open it. `link` on the field draws them its own way instead.
	 *
	 * @param array<string, mixed> $config The field.
	 * @param array<string, mixed> $part   A normalised part.
	 *
	 * @return string Markup.
	 */
	private static function link( array $config, array $part ): string {
		if ( is_callable( $config['link'] ) ) {
			return (string) call_user_func( $config['link'], $part );
		}

		if ( isset( $part['url'] ) ) {
			return sprintf( '<a href="%s">%s</a>', esc_url( $part['url'] ), esc_html( $part['text'] ) );
		}

		$manager = Registry::instance()->get( (string) $config['manager'] );
		$link    = $manager instanceof Manager ? $manager->link( $part['flyout'], $part['text'], [ 'id' => $part['id'] ] ) : '';

		return '' !== $link ? $link : esc_html( $part['text'] );
	}

	/**
	 * The quiet words after the sentence: an amount, a source, who.
	 *
	 * @param array<string, mixed> $config The field.
	 * @param array<string, mixed> $event  A normalised event.
	 *
	 * @return string
	 */
	private static function meta( array $config, array $event ): string {
		$html = '';

		foreach ( $event['meta'] as $index => $meta ) {
			$before = is_callable( $config['meta_before'] ) ? (string) call_user_func( $config['meta_before'], $event, $index ) : '';

			$html .= '<span class="wp-flyout-activity-meta"><span class="wp-flyout-activity-dot" aria-hidden="true">&middot;</span>' . $before . esc_html( $meta ) . '</span>';
		}

		return $html;
	}

	/**
	 * How long ago, as markup.
	 *
	 * @param array<string, mixed> $config The field.
	 * @param mixed                $time   The moment, as the event gave it.
	 *
	 * @return string
	 */
	private static function time( array $config, $time ): string {
		if ( null === $time || '' === $time ) {
			return '';
		}

		if ( is_callable( $config['time_format'] ) ) {
			return (string) call_user_func( $config['time_format'], $time );
		}

		return self::time_ago( $time );
	}

	/**
	 * "2 weeks ago", with the moment itself on hover.
	 *
	 * In WordPress's own words, from human_time_diff(), so a feed reads the
	 * same as the rest of the admin. A moment in the future is "in 3 days".
	 *
	 * @param mixed $time A UTC datetime as stored, a timestamp, or a date object.
	 *
	 * @return string Markup. Empty for something that is not a moment.
	 * @since 1.0.0
	 */
	public static function time_ago( $time ): string {
		$timestamp = self::timestamp( $time );

		if ( null === $timestamp ) {
			return '';
		}

		// The shared clock rather than time(), so a test that freezes it
		// freezes the feed with it.
		$now  = Clock::time();
		$span = human_time_diff( $timestamp, $now );

		$text = $timestamp <= $now
			/* translators: %s: a length of time, such as "2 weeks". */
			? sprintf( __( '%s ago', 'sugarcommerce' ), $span )
			/* translators: %s: a length of time, such as "2 weeks". */
			: sprintf( __( 'in %s', 'sugarcommerce' ), $span );

		$exact = wp_date( trim( get_option( 'date_format', 'F j, Y' ) . ' ' . get_option( 'time_format', 'g:i a' ) ), $timestamp );

		return sprintf(
			'<time datetime="%1$s" title="%2$s">%3$s</time>',
			esc_attr( gmdate( 'c', $timestamp ) ),
			esc_attr( (string) $exact ),
			esc_html( $text )
		);
	}

	/**
	 * A moment as a Unix timestamp.
	 *
	 * A string is a UTC datetime as a database keeps it; digits are a
	 * timestamp already. MySQL's zero date is no date.
	 *
	 * @param mixed $time The moment.
	 *
	 * @return int|null Null when it is not one.
	 * @since 1.0.0
	 */
	public static function timestamp( $time ): ?int {
		if ( $time instanceof DateTimeInterface ) {
			return $time->getTimestamp();
		}

		if ( is_int( $time ) || ( is_string( $time ) && ctype_digit( $time ) ) ) {
			return (int) $time > 0 ? (int) $time : null;
		}

		if ( ! is_string( $time ) || '' === trim( $time ) || str_starts_with( $time, '0000-00-00' ) ) {
			return null;
		}

		try {
			return ( new DateTimeImmutable( trim( $time ), new DateTimeZone( 'UTC' ) ) )->getTimestamp();
		} catch ( Throwable $e ) {
			return null;
		}
	}

	// =========================================================================
	// EVENTS
	// =========================================================================

	/**
	 * The events a feed shows, and whether there are earlier ones.
	 *
	 * What the field was handed (from `load`, or written into the
	 * configuration) when it was handed anything, and otherwise what its
	 * `source` says for the record being shown.
	 *
	 * @param array<string, mixed> $config The field.
	 *
	 * @return array{events: array<int, array<string, mixed>>, more: bool}
	 * @since 1.0.0
	 */
	public static function feed_data( array $config ): array {
		$config = self::configure( $config );

		if ( null === $config['events'] && is_callable( $config['source'] ) && self::has_record( $config['record_id'] ) ) {
			$answer = call_user_func( $config['source'], $config['record_id'], [ 'number' => self::per_page( $config ) ] );

			return self::events_from( $answer, $config );
		}

		return self::events_from(
			[
				'events' => $config['events'],
				'more'   => $config['more'],
			],
			$config
		);
	}

	/**
	 * Events out of whatever a load or a source answered.
	 *
	 * `[ 'events' => [...], 'more' => bool ]`, or a plain list of events.
	 *
	 * @param mixed                $answer What came back.
	 * @param array<string, mixed> $config The field.
	 *
	 * @return array{events: array<int, array<string, mixed>>, more: bool}
	 * @since 1.0.0
	 */
	public static function events_from( $answer, array $config ): array {
		if ( $answer instanceof JsonSerializable ) {
			$answer = $answer->jsonSerialize();
		}

		$answer = is_array( $answer ) ? $answer : [];
		$listed = array_key_exists( 'events', $answer );
		$events = $listed ? $answer['events'] : $answer;
		$types  = self::types( $config );
		$all    = [];

		foreach ( is_array( $events ) ? $events : [] as $event ) {
			$normalised = self::event( $event, $types );

			if ( null !== $normalised ) {
				$all[] = $normalised;
			}
		}

		return [
			'events' => $all,
			'more'   => $listed && ! empty( $answer['more'] ),
		];
	}

	/**
	 * The kinds of event this feed knows: the field's own, and any an add-on
	 * declared for every feed of this manager.
	 *
	 * @param array<string, mixed> $config The field.
	 *
	 * @return array<string, array{icon?: string, tone?: string}>
	 * @since 1.0.0
	 */
	public static function types( array $config ): array {
		$prefix = sanitize_key( (string) ( $config['manager'] ?? '' ) );
		$types  = is_array( $config['types'] ?? null ) ? $config['types'] : [];

		/**
		 * Filter the kinds of event a manager's activity feeds know.
		 *
		 * An add-on that records events of its own declares how they are
		 * drawn once, here, rather than on every event it records.
		 *
		 * @param array<string, array{icon?: string, tone?: string}> $types  Type => its icon and tone.
		 * @param array<string, mixed>                                $config The field.
		 *
		 * @since 1.0.0
		 */
		$filtered = apply_filters( "wp_flyout_{$prefix}_activity_types", $types, $config );

		return is_array( $filtered ) ? $filtered : $types;
	}

	/**
	 * One event, in the shape this draws, or null for something that is not
	 * one.
	 *
	 * Anything that is neither an array nor an object that serialises to
	 * one is dropped: a filter that appends a string has made a mistake, and
	 * one bad entry is not a reason to lose the feed.
	 *
	 * @param mixed                                               $event An event.
	 * @param array<string, array{icon?: string, tone?: string}> $types The kinds this feed knows.
	 *
	 * @return array{id: string, type: string, time: mixed, icon: string, tone: string, text: string, parts: array<int, array<string, mixed>>, meta: array<int, string>, note: array{id: int, content: string, author: string, can_delete: bool}|null, data: array<string, mixed>}|null
	 * @since 1.0.0
	 */
	public static function event( $event, array $types = [] ): ?array {
		if ( $event instanceof JsonSerializable ) {
			$event = $event->jsonSerialize();
		}

		if ( ! is_array( $event ) ) {
			return null;
		}

		$type = self::text( $event['type'] ?? '' );
		$kind = is_array( $types[ $type ] ?? null ) ? $types[ $type ] : [];

		$icon = self::text( $event['icon'] ?? '' );
		$icon = '' !== $icon ? $icon : self::text( $kind['icon'] ?? '' );

		$tone = self::text( $event['tone'] ?? '' );
		$tone = '' !== $tone ? $tone : self::text( $kind['tone'] ?? '' );

		return [
			'id'    => self::text( $event['id'] ?? '' ),
			'type'  => $type,
			'time'  => $event['time'] ?? null,
			'icon'  => '' !== $icon ? $icon : Icon::DEFAULTS['event'],
			'tone'  => in_array( $tone, self::TONES, true ) ? $tone : '',
			'text'  => self::text( $event['text'] ?? '' ),
			'parts' => self::parts( $event['parts'] ?? [] ),
			'meta'  => self::meta_words( $event['meta'] ?? [] ),
			'note'  => 'note' === $type ? self::note( $event['note'] ?? null ) : null,

			// The event as it came, for a callback that wants something only
			// one kind of event carries.
			'data'  => $event,
		];
	}

	/**
	 * The sentence in pieces: each a `text`, and where it names something,
	 * a `url` or a `flyout` and `id` (`panel` is read as `flyout`).
	 *
	 * @param mixed $parts What the event gave.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function parts( $parts ): array {
		$clean = [];

		foreach ( is_array( $parts ) ? $parts : [] as $part ) {
			if ( ! is_array( $part ) || '' === self::text( $part['text'] ?? '' ) ) {
				continue;
			}

			$piece  = [ 'text' => self::text( $part['text'] ) ];
			$url    = self::text( $part['url'] ?? '' );
			$flyout = sanitize_key( self::text( $part['flyout'] ?? $part['panel'] ?? '' ) );
			$id     = $part['id'] ?? null;

			if ( '' !== $url ) {
				$piece['url'] = $url;
			} elseif ( '' !== $flyout && is_scalar( $id ) && '' !== (string) $id && '0' !== (string) $id ) {
				$piece['flyout'] = $flyout;
				$piece['id']     = is_int( $id ) || ctype_digit( (string) $id ) ? (int) $id : (string) $id;

				// Kept for a `link` callback written against the old name.
				$piece['panel'] = $flyout;
			}

			$clean[] = $piece;
		}

		return $clean;
	}

	/**
	 * The quiet words, without anything blank or anything that is not text.
	 *
	 * @param mixed $meta What the event gave.
	 *
	 * @return array<int, string>
	 */
	private static function meta_words( $meta ): array {
		$words = [];

		foreach ( (array) $meta as $said ) {
			if ( is_scalar( $said ) && '' !== trim( (string) $said ) ) {
				$words[] = (string) $said;
			}
		}

		return $words;
	}

	/**
	 * A note's `id`, `content`, `author`, and whether it may be deleted.
	 *
	 * @param mixed $note What the event gave.
	 *
	 * @return array{id: int, content: string, author: string, can_delete: bool}|null
	 */
	private static function note( $note ): ?array {
		if ( ! is_array( $note ) ) {
			return null;
		}

		return [
			'id'         => (int) ( $note['id'] ?? 0 ),
			'content'    => self::text( $note['content'] ?? '' ),
			'author'     => self::text( $note['author'] ?? '' ),

			// A note that says it may not be deleted -- one the system wrote
			// -- has no Delete. Anything else may be.
			'can_delete' => ! array_key_exists( 'can_delete', $note ) || ! empty( $note['can_delete'] ),
		];
	}

	// =========================================================================
	// REQUESTS
	// =========================================================================

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

	// =========================================================================
	// HELPERS
	// =========================================================================

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
