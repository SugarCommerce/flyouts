<?php
/**
 * Registering Flyouts
 *
 * @package SugarCommerce\Flyouts
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts\Traits\Manager;

use SugarCommerce\Fields\Actions\Actions;
use SugarCommerce\Fields\Actions\CallbackAction;
use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\Search\CallbackSource;
use SugarCommerce\Fields\Search\Sources;
use SugarCommerce\Fields\Support\Lookups;
use SugarCommerce\Flyouts\Components\Activity;
use SugarCommerce\Flyouts\Flyout;

/**
 * Registering a flyout, and the search sources and AJAX actions its fields
 * and components ask for.
 */
trait Registration {

	/**
	 * Register a flyout with declarative configuration.
	 *
	 * @param string $id     Unique flyout identifier.
	 * @param array  $config Flyout configuration array.
	 *
	 * @return self Returns instance for method chaining.
	 * @since 1.0.0
	 */
	public function register_flyout( string $id, array $config ): self {
		$defaults = [
			'title'       => '',
			'subtitle'    => '',
			'eyebrow'     => '',
			'badge'       => '',
			'tags'        => '',
			'menu'        => [],
			'header'      => null,
			'footer_when' => '',
			'size'        => 'medium',
			'layout'      => '',
			'money'       => '',
			'tabs'        => [],
			'sections'    => [],
			'fields'      => [],
			'actions'     => [],
			'capability'  => 'manage_options',
			'admin_pages' => [],
			'load'        => null,
			'validate'    => null,
			'save'        => null,
			'delete'      => null,
		];

		$config = wp_parse_args( $config, $defaults );

		// Apply filters for extensibility.
		$config = apply_filters( "wp_flyout_register_config_{$this->prefix}", $config, $id );
		$config = apply_filters( "wp_flyout_{$this->prefix}_{$id}_config", $config );

		// Auto-detect required components.
		$this->detect_components( $config );

		// Track admin pages for asset loading.
		if ( ! empty( $config['admin_pages'] ) ) {
			$this->admin_pages = array_unique(
				array_merge( $this->admin_pages, $config['admin_pages'] )
			);
		}

		// Every field that searches, named and registered here rather than
		// when the panel renders. The request that searches is not the
		// request that rendered — a combobox asks the endpoint on its own —
		// so a source registered while drawing the panel does not exist by
		// the time anyone types into it.
		$config['fields'] = $this->register_search_sources( $id, (array) $config['fields'], (string) $config['capability'] );
		$config['fields'] = $this->register_field_actions( $id, (array) $config['fields'] );
		$config['fields'] = $this->register_component_actions( $id, (array) $config['fields'] );

		// A slug's check and a location's geocoder are asked on a request
		// that never draws the panel, like a search, so they are registered
		// here, under names that are this panel's own.
		$config['fields'] = Lookups::register_configs( $this->prefix . '-' . $id, (array) $config['fields'] );

		// Store flyout configuration.
		$this->flyouts[ $id ] = $config;

		return $this;
	}

	/**
	 * Whether a field's `callback` is a search.
	 *
	 * It is not always: this library has spelled several things `callback`,
	 * and an action field's is a handler that runs when a button is pressed.
	 * Registering one as a search source would put it behind a GET endpoint
	 * anyone with `edit_posts` could call with any term — so a bare
	 * `callback` counts only on a field that searches by definition.
	 *
	 * @param array<string, mixed> $field Field configuration.
	 *
	 * @return bool
	 */
	private static function searches( array $field ): bool {
		// Named for what it is, so any field type can have one.
		if ( isset( $field['search_callback'] ) ) {
			return true;
		}

		// An action's handler, which is not one.
		if ( ! empty( $field['action'] ) ) {
			return false;
		}

		// A component with a search embedded in it — line items and its
		// product picker.
		if ( ! empty( $field['search_key'] ) ) {
			return true;
		}

		// And the field type whose whole purpose is a callback-backed search,
		// under both spellings.
		return in_array( (string) ( $field['type'] ?? '' ), [ 'ajax_select', 'ajax' ], true );
	}

	/**
	 * Register a search source for every field that has one.
	 *
	 * The name is written back onto the field, which is what both halves
	 * read: a component renders it into its own picker, and the field set
	 * hands it to the fields package rather than deriving a name of its own.
	 *
	 * @param string                              $flyout_id  The flyout.
	 * @param array<string, array<string, mixed>> $fields     Field configuration.
	 * @param string                              $capability The flyout's capability.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function register_search_sources( string $flyout_id, array $fields, string $capability ): array {
		foreach ( $fields as $key => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$name = $this->register_search_source(
				$flyout_id,
				(string) ( $field['search_key'] ?? $field['name'] ?? $key ),
				$field,
				$capability
			);

			if ( '' !== $name ) {
				$fields[ $key ]['search_source'] = $name;
			}
		}

		return $fields;
	}

	/**
	 * Register a fields search source for a field that searches.
	 *
	 * The name is the manager's, the flyout's and the field's. The field set
	 * would otherwise derive one from the field key alone — this library
	 * gives it no input prefix, because the prefix is also what the form
	 * submits under — and two flyouts each with a `customer` field would name
	 * the same source, the second registration answering the first's
	 * searches.
	 *
	 * The capability defaults to the flyout's own. It was a fixed
	 * `edit_posts`, which meant a panel only a shop manager could open had a
	 * product search any author could query — the source is a GET endpoint
	 * returning rows from somebody's database, and the panel's capability
	 * is the one thing already known to be right for it.
	 *
	 * @param string               $flyout_id  The flyout.
	 * @param string               $field_key  The field.
	 * @param array<string, mixed> $field      Field configuration.
	 * @param string               $capability The flyout's capability, when it is not yet registered.
	 *
	 * @return string The source's name, or empty when it has no callback.
	 */
	public function register_search_source( string $flyout_id, string $field_key, array $field, string $capability = '' ): string {
		if ( ! self::searches( $field ) ) {
			return '';
		}

		$callback = $field['search_callback'] ?? $field['callback'] ?? null;

		if ( ! is_callable( $callback ) ) {
			return '';
		}

		if ( '' === $capability ) {
			$capability = (string) ( $this->flyouts[ $flyout_id ]['capability'] ?? 'manage_options' );
		}

		$name = sprintf( '%s-%s-%s', $this->prefix, $flyout_id, $field_key );

		Sources::shared()->register(
			new CallbackSource(
				$name,
				$callback,
				(string) ( $field['search_capability'] ?? $capability ),
				// The keys the field declared under `search_args`. A source
				// hands its callback only the arguments it was told to
				// expect, so one registered without them was handed none: a
				// panel could set `search_args`, the page sent them, and the
				// callback never saw which record the picker was sitting in.
				array_keys( (array) ( $field['search_args'] ?? [] ) )
			)
		);

		return $name;
	}

	/**
	 * Register the action handlers every field declares.
	 *
	 * The same arrangement as the search sources, for the same reason: the
	 * request that presses a button is not the request that drew it, so a
	 * handler registered while rendering does not exist by the time anyone
	 * clicks. An action button in a panel came back "Unknown action." every
	 * time.
	 *
	 * The names are written back onto the field, and are the manager's, the
	 * flyout's and the field's. A field set given no input prefix would
	 * derive them from the field key alone, and two panels each with an
	 * `activate` button would name the same handler.
	 *
	 * @param string                              $flyout_id The flyout.
	 * @param array<string, array<string, mixed>> $fields    Field configuration.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function register_field_actions( string $flyout_id, array $fields ): array {
		$actions = Actions::shared();

		foreach ( $fields as $key => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$handlers = (array) ( $field['actions'] ?? [] );

			if ( isset( $field['action_callback'] ) ) {
				$handlers['run'] = $field['action_callback'];
			}

			$names = [];

			foreach ( $handlers as $name => $callback ) {
				if ( ! is_callable( $callback ) ) {
					continue;
				}

				$names[ $name ] = sprintf(
					'%s-%s-%s-%s',
					$this->prefix,
					$flyout_id,
					(string) ( $field['name'] ?? $key ),
					(string) $name
				);

				$actions->register(
					new CallbackAction(
						$names[ $name ],
						$callback,
						(string) ( $field['action_capability'] ?? $field['capability'] ?? 'manage_options' )
					)
				);
			}

			if ( [] !== $names ) {
				$fields[ $key ]['action_names'] = $names;
			}
		}

		return $fields;
	}

	/**
	 * Build the handlers a component makes requests to, and keep them on its
	 * field.
	 *
	 * An activity feed adds a note, deletes one and loads earlier events,
	 * and each answers with the feed drawn again -- which takes this
	 * manager, this panel and this field, all known here and nowhere later.
	 * They are kept under `flyout_actions`, which the REST endpoint reads
	 * and the fields package does not, so they answer this library's action route and
	 * are not also registered on the fields package's.
	 *
	 * @param string                              $flyout_id The flyout.
	 * @param array<string, array<string, mixed>> $fields    Field configuration.
	 *
	 * @return array<string, array<string, mixed>>
	 * @since 1.0.0
	 */
	private function register_component_actions( string $flyout_id, array $fields ): array {
		foreach ( $fields as $key => $field ) {
			if ( ! is_array( $field ) || 'activity' !== ( $field['type'] ?? '' ) ) {
				continue;
			}

			$handlers = Activity::handlers( $this, $flyout_id, (string) $key, $field );

			if ( [] !== $handlers ) {
				$fields[ $key ]['flyout_actions'] = $handlers;
			}
		}

		return $fields;
	}
}
