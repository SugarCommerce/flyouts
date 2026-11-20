<?php
/**
 * Events
 *
 * @package SugarCommerce\Flyouts\Components
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts\Traits\Components\Activity;

use JsonSerializable;
use SugarCommerce\Flyouts\Utils\Icon;

/**
 * Turning a record's history into events the feed can draw: the types,
 * the parts of each sentence and the notes.
 */
trait Events {

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
}
