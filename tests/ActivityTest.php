<?php
/**
 * The activity feed.
 *
 * @package SugarCommerce\Flyouts
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts\Tests;

use SugarCommerce\Dates\Clock;
use SugarCommerce\Flyouts\Components\Activity;
use SugarCommerce\Flyouts\Manager;
use SugarCommerce\Flyouts\RestApi;
use SugarCommerce\Flyouts\Utils\Icon;
use JsonSerializable;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

/**
 * One record's history, and the notes written about it, in one list.
 *
 * What it draws -- a picture for the kind, a sentence with its links, the
 * quiet words after it, how long ago -- and the three requests it makes,
 * each answered with the feed drawn again.
 */
final class ActivityTest extends TestCase {

	/**
	 * What the callbacks were handed.
	 *
	 * @var array<string, mixed>
	 */
	public static array $ran = [];

	/**
	 * A clean slate: no filters, every capability, nothing run.
	 */
	protected function setUp(): void {
		$GLOBALS['fk_filters'] = [];
		$GLOBALS['wf_caps']    = null;
		self::$ran             = [];
	}

	/**
	 * Render a feed.
	 *
	 * @param array<string, mixed> $config The field.
	 *
	 * @return string
	 */
	private function render( array $config ): string {
		return ( new Activity( $config + [ 'id' => 'feed' ] ) )->render();
	}

	// =========================================================================
	// DRAWING
	// =========================================================================

	/**
	 * An event is its picture, its sentence and its kind.
	 */
	public function test_an_event_is_drawn_with_its_icon_kind_and_tone(): void {
		$html = $this->render(
			[
				'events' => [
					[
						'type' => 'refund',
						'icon' => 'undo',
						'tone' => 'danger',
						'text' => 'Refunded <£5>',
					],
				],
			]
		);

		$this->assertStringContainsString( '<li class="wp-flyout-activity-item is-refund is-danger">', $html );
		$this->assertStringContainsString( 'dashicons dashicons-undo sugarcommerce-icon', $html );

		// Text, escaped: a sentence is never markup.
		$this->assertStringContainsString( 'Refunded &lt;£5&gt;', $html );
	}

	/**
	 * A tone that is not one is no tone at all, and no icon is the library's own.
	 */
	public function test_an_unknown_tone_is_dropped_and_a_missing_icon_is_the_default(): void {
		$html = $this->render( [ 'events' => [ [ 'type' => 'x', 'tone' => 'purple" onclick="', 'text' => 'Hi' ] ] ] );

		$this->assertStringContainsString( '<li class="wp-flyout-activity-item is-x">', $html );
		$this->assertStringContainsString( '<span class="wp-flyout-activity-icon" aria-hidden="true">' . Icon::get( 'event', [ 'size' => 15 ] ) . '</span>', $html );
		$this->assertStringNotContainsString( 'dashicons', $html );
		$this->assertStringNotContainsString( 'onclick', $html );
	}

	/**
	 * A kind declared once is drawn the same everywhere, and an event
	 * that says for itself wins.
	 */
	public function test_types_give_an_event_its_icon_and_tone(): void {
		$html = $this->render(
			[
				'types'  => [
					'purchase' => [
						'icon' => 'cart',
						'tone' => 'accent',
					],
				],
				'events' => [
					[ 'type' => 'purchase', 'text' => 'Bought' ],
					[ 'type' => 'purchase', 'icon' => 'star-filled', 'text' => 'Bought again' ],
				],
			]
		);

		$this->assertStringContainsString( 'is-purchase is-accent', $html );
		$this->assertStringContainsString( 'dashicons-cart', $html );
		$this->assertStringContainsString( 'dashicons-star-filled', $html );
	}

	/**
	 * An add-on declares its kinds for every feed of a manager.
	 */
	public function test_an_add_on_declares_types_through_the_managers_filter(): void {
		add_filter(
			'wp_flyout_shop_activity_types',
			static fn( array $types ): array => $types + [
				'renewal' => [
					'icon' => 'update',
					'tone' => 'success',
				],
			]
		);

		$html = $this->render(
			[
				'manager' => 'shop',
				'events'  => [ [ 'type' => 'renewal', 'text' => 'Renewed' ] ],
			]
		);

		$this->assertStringContainsString( 'is-renewal is-success', $html );
		$this->assertStringContainsString( 'dashicons-update', $html );

		// Scoped: another manager's feed is not told.
		$other = $this->render(
			[
				'manager' => 'other',
				'events'  => [ [ 'type' => 'renewal', 'text' => 'Renewed' ] ],
			]
		);

		$this->assertStringNotContainsString( 'is-success', $other );
	}

	/**
	 * A part that names something is a link to it; a sentence that is one
	 * link from end to end says so.
	 */
	public function test_parts_link_and_a_whole_link_is_marked(): void {
		$html = $this->render(
			[
				'events' => [
					[
						'type'  => 'order',
						'parts' => [
							[ 'text' => 'Bought ' ],
							[
								'text' => 'Kit',
								'url'  => 'https://example.test/kit',
							],
						],
					],
					[
						'type'  => 'licence',
						'parts' => [
							[
								'text' => 'Licence issued',
								'url'  => 'https://example.test/l',
							],
						],
					],
				],
			]
		);

		$this->assertStringContainsString( 'Bought <a href="https://example.test/kit">Kit</a>', $html );
		$this->assertStringContainsString( '<li class="wp-flyout-activity-item is-licence is-link">', $html );
		$this->assertSame( 1, substr_count( $html, 'is-link' ) );
	}

	/**
	 * A part naming another of this manager's panels opens it in place --
	 * and is plain words for somebody who may not open it.
	 */
	public function test_a_panel_part_is_a_trigger_or_plain_words(): void {
		$manager = new Manager( 'act_links' );
		$manager->register_flyout(
			'order',
			[
				'title'      => 'Order',
				'capability' => 'edit_orders',
			]
		);

		$config = [
			'manager' => 'act_links',
			'events'  => [
				[
					'type'  => 'x',
					'parts' => [
						[
							'text'  => 'order #51',
							'panel' => 'order',
							'id'    => 51,
						],
					],
				],
			],
		];

		$html = $this->render( $config );

		$this->assertStringContainsString( 'data-flyout="order"', $html );
		$this->assertStringContainsString( 'data-id="51"', $html );

		$GLOBALS['wf_caps'] = [ 'read' ];

		$plain = $this->render( $config );

		$this->assertStringNotContainsString( 'data-flyout=', $plain );
		$this->assertStringContainsString( 'order #51', $plain );
	}

	/**
	 * `link` draws them the plugin's own way.
	 */
	public function test_a_link_callback_draws_the_parts(): void {
		$html = $this->render(
			[
				'link'   => static fn( array $part ): string => '<b>' . $part['panel'] . ':' . $part['id'] . '</b>',
				'events' => [
					[
						'type'  => 'x',
						'parts' => [
							[
								'text'  => 'A',
								'panel' => 'order',
								'id'    => '7',
							],
						],
					],
				],
			]
		);

		$this->assertStringContainsString( '<b>order:7</b>', $html );
	}

	/**
	 * The quiet words after a sentence, with something before one if the
	 * plugin wants it there.
	 */
	public function test_meta_is_said_quietly_with_something_before_it(): void {
		$html = $this->render(
			[
				'meta_before' => static fn( array $event, int $index ): string => 0 === $index && 'joined' === $event['type'] ? '<img src="g.svg">' : '',
				'events'      => [
					[
						'type' => 'joined',
						'text' => 'Became a customer',
						'meta' => [ 'Google', '', [ 'not text' ], 'Spring sale' ],
					],
				],
			]
		);

		$this->assertSame( 2, substr_count( $html, 'wp-flyout-activity-meta' ) );
		$this->assertStringContainsString( '&middot;</span><img src="g.svg">Google</span>', $html );
		$this->assertStringContainsString( '&middot;</span>Spring sale</span>', $html );
	}

	/**
	 * How long ago, in WordPress's words, with the moment on hover.
	 */
	public function test_time_is_relative_with_the_moment_on_hover(): void {
		$two_hours = gmdate( 'Y-m-d H:i:s', time() - 7200 );

		$html = $this->render( [ 'events' => [ [ 'type' => 'x', 'text' => 'Hi', 'time' => $two_hours ] ] ] );

		$this->assertMatchesRegularExpression( '/<span class="wp-flyout-activity-time"><time datetime="[^"]+" title="[^"]+">2 hours ago<\/time><\/span>/', $html );

		// The future faces the other way.
		$this->assertStringContainsString( 'in 3 days', Activity::time_ago( time() + 3 * 86400 ) );

		// And nothing is not a moment.
		$this->assertSame( '', Activity::time_ago( '0000-00-00 00:00:00' ) );
		$this->assertSame( '', Activity::time_ago( 'not a date' ) );
		$this->assertNull( Activity::timestamp( '' ) );
		$this->assertSame( 86400, Activity::timestamp( '1970-01-02 00:00:00' ) );
	}

	/**
	 * How long ago is measured on the shared clock, so a frozen one holds the feed still.
	 */
	public function test_time_ago_reads_the_shared_clock(): void {
		Clock::freeze( '2026-01-10 12:00:00' );

		try {
			$this->assertStringContainsString( '>2 hours ago<', Activity::time_ago( '2026-01-10 10:00:00' ) );
			$this->assertStringContainsString( '>in 1 day<', Activity::time_ago( '2026-01-11 12:00:00' ) );

			// Counted as WordPress counts: to the nearest unit, seconds
			// under a minute, and never a date however long ago.
			$this->assertStringContainsString( '>2 weeks ago<', Activity::time_ago( '2025-12-28 12:00:00' ) );
			$this->assertStringContainsString( '>12 seconds ago<', Activity::time_ago( '2026-01-10 11:59:48' ) );
			$this->assertStringContainsString( '>2 years ago<', Activity::time_ago( '2024-01-10 12:00:00' ) );
		} finally {
			Clock::reset();
		}
	}

	/**
	 * `time_format` says it the plugin's own way.
	 */
	public function test_a_time_format_callback_is_used(): void {
		$html = $this->render(
			[
				'time_format' => static fn( $time ): string => '<em>' . $time . '</em>',
				'events'      => [ [ 'type' => 'x', 'text' => 'Hi', 'time' => 'yesterday-ish' ] ],
			]
		);

		$this->assertStringContainsString( '<span class="wp-flyout-activity-time"><em>yesterday-ish</em></span>', $html );
	}

	/**
	 * A note is somebody's words, signed, with a way to remove it -- only
	 * where the feed can remove one and the note may be removed.
	 */
	public function test_a_note_is_signed_and_deletable(): void {
		$note = [
			'type' => 'note',
			'note' => [
				'id'      => 9,
				'content' => "Two lines,\nand &lt;tags&gt;",
				'author'  => 'Sam',
			],
		];

		$html = $this->render(
			[
				'events'      => [ $note ],
				'delete_note' => static fn(): bool => true,
			]
		);

		$this->assertStringContainsString( 'is-note', $html );
		$this->assertStringContainsString( '<div class="wp-flyout-activity-note">Two lines,<br />' . "\n" . 'and &lt;tags&gt;</div>', $html );
		$this->assertStringContainsString( 'Sam', $html );
		$this->assertStringContainsString( 'data-action="delete_activity_note" data-note-id="9" data-confirm="Delete this note?"', $html );

		// No handler, no button.
		$this->assertStringNotContainsString( 'data-note-id', $this->render( [ 'events' => [ $note ] ] ) );

		// A note that says it may not be deleted keeps it.
		$note['note']['can_delete'] = false;

		$this->assertStringNotContainsString(
			'data-note-id',
			$this->render(
				[
					'events'      => [ $note ],
					'delete_note' => static fn(): bool => true,
				]
			)
		);
	}

	/**
	 * A `note` with nothing to say is not a note: an event of another type
	 * carrying one is drawn as the event it is.
	 */
	public function test_only_a_note_event_is_drawn_as_a_note(): void {
		$html = $this->render(
			[
				'events' => [
					[
						'type' => 'refund',
						'text' => 'Refunded',
						'note' => [ 'content' => 'x' ],
					],
				],
			]
		);

		$this->assertStringNotContainsString( 'wp-flyout-activity-note', $html );
		$this->assertStringContainsString( 'Refunded', $html );
	}

	/**
	 * Nothing yet is said; a feed of only its opening events says what
	 * will join them.
	 */
	public function test_empty_and_hint(): void {
		$this->assertStringContainsString(
			'<p class="wp-flyout-activity-empty">Nothing here.</p>',
			$this->render(
				[
					'events'     => [],
					'empty_text' => 'Nothing here.',
				]
			)
		);

		$config = [
			'empty_text' => 'More to come.',
			'hint_types' => [ 'joined' ],
			'events'     => [ [ 'type' => 'joined', 'text' => 'Joined' ] ],
		];

		$this->assertStringContainsString( 'wp-flyout-activity-hint', $this->render( $config ) );

		$config['events'][] = [ 'type' => 'purchase', 'text' => 'Bought' ];

		$this->assertStringNotContainsString( 'wp-flyout-activity-hint', $this->render( $config ) );
	}

	/**
	 * "Show Earlier Activity" asks for what is shown and a page more, and
	 * only where there is more and a source to ask.
	 */
	public function test_more_asks_for_another_page(): void {
		$events = [ [ 'type' => 'x', 'text' => 'A' ], [ 'type' => 'x', 'text' => 'B' ] ];

		$html = $this->render(
			[
				'events' => $events,
				'more'   => true,
				'number' => 5,
				'source' => static fn(): array => [],
			]
		);

		$this->assertStringContainsString( 'class="button wp-flyout-row-action" data-action="more_activity" data-number="7"', $html );

		// More, but nowhere to ask.
		$this->assertStringNotContainsString(
			'wp-flyout-activity-more',
			$this->render(
				[
					'events' => $events,
					'more'   => true,
				]
			)
		);
	}

	/**
	 * Events can be objects that serialise to one, and anything else in the
	 * list is dropped rather than losing the feed.
	 */
	public function test_serialisable_events_are_taken_and_junk_is_dropped(): void {
		$event = new class() implements JsonSerializable {
			/**
			 * @return array<string, string>
			 */
			public function jsonSerialize(): array {
				return [
					'type' => 'serialised',
					'text' => 'From an object',
				];
			}
		};

		$feed = Activity::events_from( [ 'events' => [ $event, 'junk', 42 ], 'more' => 1 ], [] );

		$this->assertCount( 1, $feed['events'] );
		$this->assertSame( 'From an object', $feed['events'][0]['text'] );
		$this->assertTrue( $feed['more'] );

		// A plain list is a list of events, with no more.
		$this->assertCount( 1, Activity::events_from( [ [ 'type' => 'a' ] ], [] )['events'] );
		$this->assertFalse( Activity::events_from( [ [ 'type' => 'a' ] ], [] )['more'] );
	}

	/**
	 * The note box is there for a record that exists and a feed that can
	 * take a note, and is not part of the panel's form.
	 */
	public function test_the_note_box(): void {
		$config = [
			'events'      => [],
			'add_note'    => static fn(): bool => true,
			'placeholder' => 'Write…',
			'record_id'   => 12,
		];

		$html = $this->render( $config );

		$this->assertStringContainsString( '<textarea id="feed-note" class="wp-flyout-activity-input" rows="1" placeholder="Write…"></textarea>', $html );
		$this->assertStringContainsString( 'data-action="add_activity_note"', $html );

		// Not a field: no name, so Save does not send it.
		$this->assertDoesNotMatchRegularExpression( '/<textarea[^>]*name=/', $html );

		// A record not made yet has nothing to attach a note to.
		$this->assertStringNotContainsString( 'textarea', $this->render( [ 'record_id' => 0 ] + $config ) );

		// And a feed with nowhere to send one has no box.
		unset( $config['add_note'] );
		$this->assertStringNotContainsString( 'textarea', $this->render( $config ) );
	}

	// =========================================================================
	// IN A PANEL
	// =========================================================================

	/**
	 * A source for the panel's feed, which records what it was asked.
	 *
	 * @param mixed                $id   The record.
	 * @param array<string, mixed> $args What was asked.
	 *
	 * @return array<string, mixed>
	 */
	public static function source( $id, array $args ): array {
		self::$ran['source'][] = [ $id, $args ];

		return [
			'events' => [
				[
					'type' => 'note',
					'note' => [
						'id'      => 4,
						'content' => 'Stored',
						'author'  => 'Sam',
					],
				],
			],
			'more'   => true,
		];
	}

	/**
	 * A manager with a panel that has a feed.
	 *
	 * @param string               $prefix The manager.
	 * @param array<string, mixed> $field  More for the field.
	 * @param array<string, mixed> $panel  More for the panel.
	 *
	 * @return Manager
	 */
	private function panel( string $prefix, array $field = [], array $panel = [] ): Manager {
		$manager = new Manager( $prefix );

		$manager->register_flyout(
			'order',
			$panel + [
				'title'  => 'Order',
				'fields' => [
					'history' => $field + [
						'type'        => 'activity',
						'source'      => [ self::class, 'source' ],
						'number'      => 10,
						'add_note'    => static function ( array $request ) {
							self::$ran['add'] = $request;

							return [ 'note' => [ 'id' => 5 ] ];
						},
						'delete_note' => static function ( array $request ) {
							self::$ran['delete'] = $request;

							return true;
						},
					],
				],
			]
		);

		return $manager;
	}

	/**
	 * Send one request to the action route.
	 *
	 * @param string               $prefix The manager.
	 * @param string               $action The action key.
	 * @param array<string, mixed> $params What else it sends.
	 *
	 * @return mixed
	 */
	private function press( string $prefix, string $action, array $params = [] ) {
		$request = new WP_REST_Request( 'POST', '/action' );

		foreach (
			[
				'manager'    => $prefix,
				'flyout'     => 'order',
				'action_key' => $action,
				'item_id'    => 12,
			] + $params as $key => $value
		) {
			$request->set_param( $key, $value );
		}

		return RestApi::handle_action( $request );
	}

	/**
	 * In a panel, the feed is read from its source for the record shown.
	 */
	public function test_a_panel_reads_its_feed_from_the_source(): void {
		$manager = $this->panel( 'act_panel' );
		$html    = $manager->build_flyout( $manager->get_flyout( 'order' ), (object) [], 12 )->render();

		$this->assertSame( [ [ 12, [ 'number' => 10 ] ] ], self::$ran['source'] );
		$this->assertStringContainsString( 'data-activity-feed="history"', $html );
		$this->assertStringContainsString( 'Stored', $html );
		$this->assertStringContainsString( 'data-number="11"', $html );
		$this->assertStringContainsString( 'wp-flyout-activity-input', $html );
	}

	/**
	 * A note written is handed over cleaned, and the feed comes back drawn
	 * again for the panel to put in place.
	 */
	public function test_adding_a_note_redraws_the_feed(): void {
		$this->panel( 'act_add' );

		$result = $this->press( 'act_add', 'add_activity_note', [ 'content' => '  <script>x</script>Hello  ' ] );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame( 'xHello', self::$ran['add']['content'] );
		$this->assertSame( 12, self::$ran['add']['id'] );

		$data = $result->get_data();

		$this->assertSame( 5, $data['note']['id'] ?? null );
		$this->assertSame( 'Note added.', $data['message'] ?? null );
		$this->assertArrayHasKey( '[data-activity-feed="history"]', $data['updates'] );
		$this->assertStringContainsString( 'Stored', $data['updates']['[data-activity-feed="history"]'] );
		$this->assertSame( [ 12, [ 'number' => 10 ] ], end( self::$ran['source'] ) );
	}

	/**
	 * An empty note is refused before the plugin is asked.
	 */
	public function test_an_empty_note_is_refused(): void {
		$this->panel( 'act_empty' );

		$result = $this->press( 'act_empty', 'add_activity_note', [ 'content' => '   ' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wp_flyout_empty_note', $result->get_error_code() );
		$this->assertArrayNotHasKey( 'add', self::$ran );
	}

	/**
	 * A refusal from the plugin is the answer.
	 */
	public function test_a_refused_note_is_the_answer(): void {
		$this->panel(
			'act_refused',
			[ 'add_note' => static fn(): \WP_Error => new \WP_Error( 'nope', 'Not here.' ) ]
		);

		$result = $this->press( 'act_refused', 'add_activity_note', [ 'content' => 'Hi' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nope', $result->get_error_code() );
	}

	/**
	 * Deleting hands over the note's id as a number, and redraws.
	 */
	public function test_deleting_a_note_redraws_the_feed(): void {
		$this->panel( 'act_delete' );

		$result = $this->press( 'act_delete', 'delete_activity_note', [ 'note_id' => '4' ] );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame( 4, self::$ran['delete']['note_id'] );
		$this->assertSame( 'Note deleted.', $result->get_data()['message'] ?? null );

		// No note named, nothing asked.
		self::$ran = [];

		$this->assertInstanceOf( \WP_Error::class, $this->press( 'act_delete', 'delete_activity_note', [ 'note_id' => 'x' ] ) );
		$this->assertArrayNotHasKey( 'delete', self::$ran );
	}

	/**
	 * Earlier events: what was asked for, never more than the most.
	 */
	public function test_more_is_capped(): void {
		$this->panel( 'act_more' );

		$this->press( 'act_more', 'more_activity', [ 'number' => 40 ] );
		$this->press( 'act_more', 'more_activity', [ 'number' => 100000 ] );
		$this->press( 'act_more', 'more_activity' );

		$this->assertSame( 40, self::$ran['source'][0][1]['number'] );
		$this->assertSame( Activity::MAX, self::$ran['source'][1][1]['number'] );
		$this->assertSame( 10, self::$ran['source'][2][1]['number'] );
	}

	/**
	 * The keys are the panel's to choose, so a store can keep the ones its
	 * add-ons already send.
	 */
	public function test_action_keys_are_configurable(): void {
		$this->panel(
			'act_keys',
			[
				'add_action'    => 'add_feed_note',
				'delete_action' => 'delete_feed_note',
				'more_action'   => 'more_feed',
			]
		);

		$this->assertInstanceOf( \WP_REST_Response::class, $this->press( 'act_keys', 'add_feed_note', [ 'content' => 'Hi' ] ) );
		$this->assertInstanceOf( \WP_REST_Response::class, $this->press( 'act_keys', 'more_feed' ) );
		$this->assertSame( 'flyout_action_not_found', $this->press( 'act_keys', 'add_activity_note', [ 'content' => 'Hi' ] )->get_error_code() );
	}

	/**
	 * Under the panel's capability unless the field asks for more.
	 */
	public function test_requests_ask_for_the_panels_capability(): void {
		$this->panel( 'act_caps', [ 'action_capability' => 'moderate_notes' ], [ 'capability' => 'edit_orders' ] );

		$GLOBALS['wf_caps'] = [ 'edit_orders' ];

		$this->assertSame( 'rest_forbidden', $this->press( 'act_caps', 'add_activity_note', [ 'content' => 'Hi' ] )->get_error_code() );
		$this->assertArrayNotHasKey( 'add', self::$ran );

		$GLOBALS['wf_caps'] = [ 'edit_orders', 'moderate_notes' ];

		$this->assertInstanceOf( \WP_REST_Response::class, $this->press( 'act_caps', 'add_activity_note', [ 'content' => 'Hi' ] ) );
	}

	/**
	 * A plugin's own action can redraw a feed the way the feed's requests do.
	 */
	public function test_a_feed_can_be_drawn_for_an_action_of_the_plugins_own(): void {
		$field = [
			'source' => [ self::class, 'source' ],
			'number' => 10,
		];

		$this->assertSame( '[data-activity-feed="history"]', Activity::selector( 'history' ) );
		$this->assertStringContainsString( 'Stored', Activity::feed( $field, 12 ) );
		$this->assertSame( [ 12, [ 'number' => 10 ] ], self::$ran['source'][0] );

		Activity::feed( $field, 12, 100000 );

		$this->assertSame( Activity::MAX, self::$ran['source'][1][1]['number'] );

		// No source, nothing to draw but the empty line.
		$this->assertStringContainsString( 'wp-flyout-activity-empty', Activity::feed( [], 12 ) );
	}

	/**
	 * Without a source the feed is drawn again from the panel's own load --
	 * through its data filter, as when it opened.
	 */
	public function test_without_a_source_the_feed_redraws_from_the_load(): void {
		$manager = new Manager( 'act_load' );

		$manager->register_flyout(
			'order',
			[
				'title'  => 'Order',
				'load'   => static fn( $id ): object => (object) [ 'history' => [ [ 'type' => 'x', 'text' => 'Loaded ' . $id ] ] ],
				'fields' => [
					'history' => [
						'type'     => 'activity',
						'add_note' => static fn(): bool => true,
					],
				],
			]
		);

		add_filter(
			'wp_flyout_act_load_order_data',
			static function ( $data ) {
				$data->history[] = [ 'type' => 'x', 'text' => 'Filtered in' ];

				return $data;
			}
		);

		$updates = $this->press( 'act_load', 'add_activity_note', [ 'content' => 'Hi' ] )->get_data()['updates'];
		$feed    = $updates['[data-activity-feed="history"]'];

		$this->assertStringContainsString( 'Loaded 12', $feed );
		$this->assertStringContainsString( 'Filtered in', $feed );
	}
}
