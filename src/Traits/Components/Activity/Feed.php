<?php
/**
 * Drawing the Feed
 *
 * @package SugarCommerce\Flyouts\Components
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts\Traits\Components\Activity;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use SugarCommerce\Dates\Site;
use SugarCommerce\Flyouts\Manager;
use SugarCommerce\Flyouts\Registry;
use SugarCommerce\Flyouts\Utils\Icon;
use Throwable;

/**
 * Drawing the feed: each event as a sentence, its icon, its links, its
 * meta and when it happened.
 */
trait Feed {

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
	 * Counted and worded as WordPress's human_time_diff() does, so a feed
	 * reads the same as the rest of the admin, by sugarcommerce/dates'
	 * Site::relative(), the one relative-time renderer. A moment in the
	 * future is "in 3 days".
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

		// On the shared clock (Site::relative reads it), so a test that
		// freezes it freezes the feed with it.
		$said = Site::relative_with_title( gmdate( 'Y-m-d H:i:s', $timestamp ), null, Site::LIKE_WORDPRESS );

		if ( '' === $said['text'] ) {
			return '';
		}

		return sprintf(
			'<time datetime="%1$s" title="%2$s">%3$s</time>',
			esc_attr( gmdate( 'c', $timestamp ) ),
			esc_attr( $said['title'] ),
			esc_html( $said['text'] )
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
}
