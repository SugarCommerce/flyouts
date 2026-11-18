<?php
/**
 * WP Flyout Manager
 *
 * Manages flyout registration, building, trigger generation, and asset management.
 * AJAX handling has been replaced by the REST API (RestApi.php).
 *
 * @package     SugarCommerce\Flyouts
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts;

use SugarCommerce\Fields\Context\ObjectContext;
use SugarCommerce\Fields\Actions\Actions;
use SugarCommerce\Fields\Actions\CallbackAction;
use SugarCommerce\Fields\Search\CallbackSource;
use SugarCommerce\Fields\Search\Sources;
use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\FieldSet;
use SugarCommerce\Fields\Assets as FieldAssets;
use SugarCommerce\Fields\Registry as FieldRegistry;
use SugarCommerce\Flyouts\Utils\Amount;
use SugarCommerce\Flyouts\Utils\Icon;
use SugarCommerce\Flyouts\Utils\Runtime;

use SugarCommerce\Flyouts\Components\Activity;
use SugarCommerce\Flyouts\Components\FormField;
use SugarCommerce\Flyouts\Flyout;
use SugarCommerce\Flyouts\ActionBar;

/**
 * Class Manager
 *
 * Orchestrates flyout registration, building, and asset management.
 * All data transport is handled by RestApi via the REST API.
 *
 * @since 1.0.0
 */
class Manager {

	// =========================================================================
	// PROPERTIES
	// =========================================================================

	/**
	 * Unique prefix for this manager instance.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $prefix;

	/**
	 * Registered flyout configurations.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private array $flyouts = [];

	/**
	 * The titled cards of the panel being built, keyed by section.
	 *
	 * @var array
	 */
	private array $sections = [];

	/**
	 * How the panel being built arranges its cards: '' or 'split'.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $layout = '';

	/**
	 * The unit the panel being built gives its amounts in: '' (each field
	 * says, or major units), or `major` or `minor`.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $money = '';

	/**
	 * The record the panel being built is showing, if it exists yet.
	 *
	 * @since 1.0.0
	 * @var mixed
	 */
	private $record_id = null;

	/**
	 * Admin pages where assets should load.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private array $admin_pages = [];

	/**
	 * Components required across all flyouts.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private array $components = [];

	/**
	 * Whether assets have been enqueued.
	 *
	 * @since 1.0.0
	 * @var bool
	 */
	private bool $assets_enqueued = false;

	// =========================================================================
	// CONSTRUCTOR & INITIALIZATION
	// =========================================================================

	/**
	 * Constructor.
	 *
	 * @param string $prefix Unique prefix for this manager instance.
	 *
	 * @since 1.0.0
	 */
	public function __construct( string $prefix ) {
		$this->prefix = sanitize_key( $prefix );

		// Two keys of this library's own: which tab a field belongs to, and
		// which titled card within it. The
		// fields package warns about configuration nothing reads, and it is right to —
		// a key nobody reads is a documented option that quietly does
		// nothing. These are read here, so they are declared here.
		Field::allow_config_keys( [ 'tab', 'section' ] );

		// Register this manager in the global registry.
		Registry::register( $this->prefix, $this );

		// Ensure REST routes are registered (safe to call multiple times).
		RestApi::register();

		// Auto-enqueue assets on admin pages.
		add_action( 'admin_enqueue_scripts', [ $this, 'maybe_enqueue_assets' ] );
	}

	// =========================================================================
	// FLYOUT REGISTRATION
	// =========================================================================

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

	// =========================================================================
	// FLYOUT BUILDING
	// =========================================================================

	/**
	 * Build flyout interface.
	 *
	 * @param array  $config Flyout configuration.
	 * @param mixed  $data   Data for field population.
	 * @param string $id     Record ID if editing.
	 *
	 * @return Flyout Configured flyout instance.
	 * @since 1.0.0
	 */
	public function build_flyout( array $config, $data, $id = null ): Flyout {
		$flyout_instance_id = $config['id'] ?? uniqid() . '_' . ( $id ?: 'new' );
		$flyout             = new Flyout( $flyout_instance_id );

		/*
		 * Defaulted rather than read straight out. title and size are required
		 * in practice but subtitle is not, and a flyout registered without one
		 * passed null into a string-typed setter -- a TypeError inside a REST
		 * handler, which reaches the browser as an HTML error page where the
		 * script expects JSON. The message it produces there says nothing
		 * about a missing subtitle.
		 *
		 * Each of the four header texts may be a callback handed the record,
		 * because the most useful title a panel can have is the name of the
		 * thing in it, and that is not known until it has been loaded.
		 */
		$flyout->set_title( self::text( $this->resolve( $config['title'] ?? '', $data, $id ) ) );
		$flyout->set_subtitle( self::text( $this->resolve( $config['subtitle'] ?? '', $data, $id ) ) );
		$flyout->set_eyebrow( self::text( $this->resolve( $config['eyebrow'] ?? '', $data, $id ) ) );
		$flyout->set_size( (string) ( $config['size'] ?? 'medium' ) );

		$badge = $this->resolve( $config['badge'] ?? '', $data, $id );

		// A word on its own is a neutral badge; a tone needs the array.
		$flyout->set_badge(
			self::text( is_array( $badge ) ? ( $badge['label'] ?? '' ) : $badge ),
			is_array( $badge ) ? self::text( $badge['tone'] ?? 'neutral' ) : 'neutral'
		);

		// Beside the badge, in the same row: the record's tags.
		$flyout->set_tags( self::tags( $this->resolve( $config['tags'] ?? '', $data, $id ) ) );

		// Only what this person may do. An item they would be refused is
		// not a choice, and a menu should not offer one.
		$flyout->set_menu(
			array_filter(
				$this->menu_items( $config, $data, $id ),
				static fn( array $item ): bool => current_user_can( $item['capability'] )
			)
		);

		$this->sections  = (array) ( $config['sections'] ?? [] );
		$this->layout    = 'split' === ( $config['layout'] ?? '' ) ? 'split' : '';
		$this->money     = in_array( $config['money'] ?? '', [ Amount::MAJOR, Amount::MINOR ], true ) ? (string) $config['money'] : '';
		$this->record_id = $id;

		if ( 'split' === $this->layout ) {
			$flyout->add_class( 'wp-flyout-layout-split' );
		}

		// A title that is something to copy -- a licence key, a code. The
		// script puts the button beside it; this is how it knows to.
		if ( ! empty( $config['copy_title'] ) ) {
			$flyout->add_class( 'wp-flyout-copy-title' );
		}

		$this->dress_header( $flyout, $config, $data, $id );

		// After everything above, so a filter has the last word on the
		// header as it always had, and can now read what was resolved.
		$flyout = apply_filters( "wp_flyout_build_flyout_{$this->prefix}", $flyout, $config, $data );

		if ( ! empty( $config['tabs'] ) ) {
			$this->build_tab_interface( $flyout, $config['tabs'], $config['fields'], $data );
		} else {
			$content = $this->render_fields( $config['fields'], $data );
			$flyout->add_content( '', $content );
		}

		if ( $id ) {
			$tab_key = ! empty( $config['tabs'] ) ? array_key_first( $config['tabs'] ) : '';
			$flyout->add_content( $tab_key, sprintf(
				'<input type="hidden" name="id" value="%s">',
				esc_attr( $id )
			) );
		}

		$actions = ! empty( $config['actions'] )
			? $config['actions']
			: $this->get_default_actions( $config );

		if ( ! empty( $actions ) ) {
			$flyout->set_footer( $this->render_actions( $actions ) );
		}

		return $flyout;
	}

	/**
	 * Give a panel the header that says who or what it is about.
	 *
	 * `header` is the opt-in, and a panel without one is left exactly as it
	 * was. With one -- even an empty array -- the title and badge keep to
	 * one line, the menu comes before previous and next, and those two are
	 * drawn quieter than the menu and the close button. Its keys:
	 *
	 * - `avatar`: a picture before the title. A URL, or `image`, `name` (its
	 *   initials stand in for a missing picture) and `icon` (a dashicon's
	 *   name or a fields icon such as `heroicons-outline:user`, when there is
	 *   no name either). `initials` gives the letters outright, and
	 *   `background` with `color` (six-digit hex) draws them as a monogram;
	 * - `meta`: a list of quiet facts after the subtitle, each a `value` with
	 *   an optional `image`, `icon` and `title`;
	 * - `copy`: `subtitle` for a copy button beside the subtitle;
	 * - `steps`: how the record before and the record after are reached.
	 *   True, the default, is the two arrows; false is nothing at all;
	 *   `keys` is no arrows and the keyboard instead (J and K, and the left
	 *   and right arrow keys when focus is not somewhere that uses them);
	 *   `both` is the arrows and the keys.
	 *
	 * `avatar`, `meta` and `copy` may each be a callback handed the record,
	 * as the title may -- so a record with nothing worth copying under its
	 * name has no button offering to.
	 *
	 * And one thing about the foot of the panel, for a record that is read
	 * far more often than it is changed: `footer_when => 'editing'` keeps
	 * Save and Cancel out of sight until the form has a change in it or a
	 * folded card has been opened.
	 *
	 * @param Flyout               $flyout The panel being built.
	 * @param array<string, mixed> $config Flyout configuration.
	 * @param mixed                $data   Whatever `load` returned.
	 * @param mixed                $id     The record's id, if it has one.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function dress_header( Flyout $flyout, array $config, $data, $id ): void {
		if ( 'editing' === ( $config['footer_when'] ?? '' ) ) {
			$flyout->add_class( 'wp-flyout-footer-when-editing' );
		}

		$header = $config['header'] ?? null;

		if ( ! is_array( $header ) ) {
			return;
		}

		$flyout->set_identity(
			[
				'avatar' => $this->resolve( $header['avatar'] ?? [], $data, $id ),
				'meta'   => $this->resolve( $header['meta'] ?? [], $data, $id ),
				'copy'   => $this->resolve( $header['copy'] ?? '', $data, $id ),
			]
		);

		$steps = self::steps( $header );

		if ( ! $steps['buttons'] ) {
			$flyout->add_class( 'wp-flyout-no-steps' );
		}

		if ( $steps['keys'] ) {
			$flyout->add_class( 'wp-flyout-step-keys' );
		}
	}

	/**
	 * What a header's `steps` asks for: the arrows, the keys, both or neither.
	 *
	 * Anything this does not know is read as the default, the arrows alone,
	 * which is what every panel had before there was a choice.
	 *
	 * @param array<string, mixed> $header A panel's `header`.
	 *
	 * @return array{buttons: bool, keys: bool, mode: string} `mode` is what a
	 *         trigger carries so the placeholder can do the same: empty for
	 *         the default, or `0`, `keys` or `both`.
	 * @since 1.0.0
	 */
	public static function steps( array $header ): array {
		$steps = $header['steps'] ?? true;

		if ( false === $steps ) {
			return [
				'buttons' => false,
				'keys'    => false,
				'mode'    => '0',
			];
		}

		if ( 'keys' === $steps || 'both' === $steps ) {
			return [
				'buttons' => 'both' === $steps,
				'keys'    => true,
				'mode'    => $steps,
			];
		}

		return [
			'buttons' => true,
			'keys'    => false,
			'mode'    => '',
		];
	}

	/**
	 * Whether a configuration value is a callback rather than the value.
	 *
	 * Not `is_callable()`, which says yes to any string that happens to be
	 * the name of a function -- and a panel titled "Link", "Key", "Count"
	 * or "Date" is titled with one. Called, each of those is a warning or
	 * a wrong title; `title => 'Exit'` would end the request. So a string
	 * is always the text it is, and a callback is a closure, an invokable
	 * object, or an `[ object-or-class, 'method' ]` pair.
	 *
	 * @param mixed $value A configuration value.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public static function is_callback( $value ): bool {
		if ( is_string( $value ) ) {
			return false;
		}

		return is_callable( $value );
	}

	/**
	 * A configuration value, or what its callback makes of the record.
	 *
	 * The callback is handed whatever `load` returned and the id that was
	 * asked for -- which is empty for a record that does not exist yet, and
	 * is how a title callback tells "New product" from a product's name.
	 *
	 * @param mixed $value A value, or a callback returning one.
	 * @param mixed $data  Whatever `load` returned.
	 * @param mixed $id    The record's id, if it has one.
	 *
	 * @return mixed
	 * @since 1.0.0
	 */
	private function resolve( $value, $data, $id ) {
		return self::is_callback( $value ) ? $value( $data, $id ) : $value;
	}

	/**
	 * A resolved value as the text it will be shown as.
	 *
	 * A callback can return anything. A number is its digits and null is
	 * nothing; an array or an object has no text, and is nothing too rather
	 * than a TypeError in a string-typed setter.
	 *
	 * @param mixed $value A resolved configuration value.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	private static function text( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * A panel's `tags`, as the markup that stands beside its badge.
	 *
	 * Markup a plugin drew itself -- its own tag chips, each with the colour
	 * somebody chose -- is printed as it is; it comes from the panel's
	 * configuration or its `load`, never from a request. A list is drawn
	 * here, each item a label, or a `label` with a `tone` (as the badge
	 * takes) or a `background` and `color` of its own (six-digit hex) and
	 * an optional `title` for hover.
	 *
	 * @param mixed $tags A string of markup, or a list.
	 *
	 * @return string Markup; empty for none.
	 * @since 1.0.0
	 */
	public static function tags( $tags ): string {
		if ( is_string( $tags ) ) {
			return trim( $tags );
		}

		if ( ! is_array( $tags ) ) {
			return '';
		}

		$html = '';

		foreach ( $tags as $tag ) {
			$tag   = is_array( $tag ) ? $tag : [ 'label' => $tag ];
			$label = trim( self::text( $tag['label'] ?? '' ) );

			if ( '' === $label ) {
				continue;
			}

			$tone       = self::text( $tag['tone'] ?? 'neutral' );
			$tone       = in_array( $tone, Flyout::VALID_TONES, true ) ? $tone : 'neutral';
			$background = Identity::hex( $tag['background'] ?? '' );
			$color      = Identity::hex( $tag['color'] ?? '' );
			$title      = trim( self::text( $tag['title'] ?? '' ) );

			// Both or neither, as an avatar's monogram takes them: a
			// background with the badge's own text colour may not be read.
			$style = '' !== $background && '' !== $color
				? sprintf( ' style="background:%s;color:%s"', esc_attr( $background ), esc_attr( $color ) )
				: '';

			$html .= sprintf(
				'<span class="wp-flyout-badge wp-flyout-badge--%1$s wp-flyout-tag"%2$s%3$s>%4$s</span>',
				esc_attr( $tone ),
				$style,
				'' === $title ? '' : ' title="' . esc_attr( $title ) . '"',
				esc_html( $label )
			);
		}

		return $html;
	}

	/**
	 * The header menu for one record.
	 *
	 * `menu` is a list of items, or a callback handed the record and
	 * returning one -- so a record that cannot be deleted has no Delete,
	 * and a new one has no "View on site". Each item is a `label` and one
	 * of:
	 *
	 * - `url`, with an optional `target`: a link;
	 * - `action` and `callback`: a key the REST endpoint runs the callback
	 *   for, the same way it runs a component's action;
	 * - `flyout` and `id`: another of this manager's panels, opened where
	 *   this one is -- "View order" on a licence. It asks before throwing
	 *   away anything typed, as every other way of opening a record does.
	 *
	 * And optionally `danger`, a `confirm` question asked before an action
	 * runs, and a `capability` -- the flyout's own when it names none.
	 *
	 * Anything else is dropped here, once, so the header that draws the
	 * menu and the endpoint that runs it are reading the same list. The
	 * capability is filled in but not enforced: drawing leaves out what
	 * the person may not do, and the endpoint refuses it, and those are
	 * different answers to give.
	 *
	 * @param array<string, mixed> $config Flyout configuration.
	 * @param mixed                $data   Whatever `load` returned.
	 * @param mixed                $id     The record's id, if it has one.
	 *
	 * @return array<int, array<string, mixed>>
	 * @since 1.0.0
	 */
	public function menu_items( array $config, $data, $id = null ): array {
		$menu  = $this->resolve( $config['menu'] ?? [], $data, $id );
		$items = [];

		foreach ( is_array( $menu ) ? $menu : [] as $item ) {
			if ( ! is_array( $item ) || '' === self::text( $item['label'] ?? '' ) ) {
				continue;
			}

			$is_link   = '' !== self::text( $item['url'] ?? '' );
			$is_action = '' !== self::text( $item['action'] ?? '' ) && is_callable( $item['callback'] ?? null );
			$opens     = self::text( $item['flyout'] ?? '' );
			$is_panel  = ! $is_link && ! $is_action && '' !== $opens && isset( $this->flyouts[ $opens ] );

			if ( ! $is_link && ! $is_action && ! $is_panel ) {
				continue;
			}

			// Drawn only for somebody who may open that panel, and as the
			// same trigger a row of a list is.
			if ( $is_panel ) {
				if ( ! $this->can_access( $opens ) ) {
					continue;
				}

				$item['trigger'] = $this->build_trigger_attributes( $opens, [ 'id' => self::text( $item['id'] ?? '' ) ], 'wp-flyout-menu-item' );
			}

			$item['capability'] = (string) ( $item['capability'] ?? $config['capability'] ?? 'manage_options' );

			// The key as the route will receive it, so the two can be
			// compared: the route's argument is run through sanitize_key().
			if ( $is_action ) {
				$item['action'] = sanitize_key( (string) $item['action'] );
			}

			$items[] = $item;
		}

		return $items;
	}

	/**
	 * Build tab interface for flyout.
	 *
	 * @param Flyout $flyout Flyout instance.
	 * @param array  $tabs   Tab configurations.
	 * @param array  $fields All field configurations.
	 * @param mixed  $data   Data for field population.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function build_tab_interface( Flyout $flyout, array $tabs, array $fields, $data ): void {
		$fields_by_tab = [];
		foreach ( $fields as $key => $field ) {
			$tab = $field['tab'] ?? 'default';
			if ( ! isset( $fields_by_tab[ $tab ] ) ) {
				$fields_by_tab[ $tab ] = [];
			}
			$fields_by_tab[ $tab ][ $key ] = $field;
		}

		foreach ( $tabs as $tab_id => $tab_config ) {
			$label    = is_array( $tab_config ) ? $tab_config['label'] : $tab_config;
			$is_first = array_key_first( $tabs ) === $tab_id;

			$flyout->add_tab( $tab_id, $label, $is_first );

			$tab_fields = $fields_by_tab[ $tab_id ] ?? [];
			$content    = $this->render_fields( $tab_fields, $data );
			$flyout->set_tab_content( $tab_id, $content );
		}
	}

	/**
	 * Render action buttons for footer.
	 *
	 * @param array $actions Action button configurations.
	 *
	 * @return string Generated HTML.
	 * @since 1.0.0
	 */
	private function render_actions( array $actions ): string {
		$action_bar = new ActionBar( [ 'actions' => $actions ] );

		return $action_bar->render();
	}

	/**
	 * Get default action buttons based on configuration.
	 *
	 * @param array $config Flyout configuration.
	 *
	 * @return array Default action buttons.
	 * @since 1.0.0
	 */
	private function get_default_actions( array $config ): array {
		$actions = [];

		/*
		 * In the order they are read and tabbed through: Delete on the far
		 * side, away from the hand, then the way out, then Save last and
		 * nearest the corner -- where the button that finishes a dialog is
		 * on every screen WordPress has.
		 */
		if ( ! empty( $config['delete'] ) ) {
			$actions[] = [
				'text'  => __( 'Delete', 'sugarcommerce' ),
				'style' => 'link-delete',
				// The fields package's, which is core's Site Icon treatment: red text, red
				// border, filling red on hover. .button-link-delete is core's
				// red *link* and gives red text with no border — combined with
				// .button it produced a blue label inside a red outline.
				'class' => 'wp-flyout-delete field-kit__button--delete',
			];
		}

		if ( ! empty( $config['save'] ) ) {
			// Beside Save, and only where there is a Save: a panel that is
			// only read has the close button and needs no second one. It is
			// a link in weight because it is not the thing to press.
			$actions[] = [
				'text'  => __( 'Cancel', 'sugarcommerce' ),
				'style' => 'link',
				'class' => 'wp-flyout-cancel',
			];

			$actions[] = [
				'text'  => __( 'Save', 'sugarcommerce' ),
				'style' => 'primary',
				'class' => 'wp-flyout-save',
			];
		}

		return $actions;
	}

	// =========================================================================
	// FIELD RENDERING
	// =========================================================================

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

	/**
	 * Load one panel's record, as the panel will see it.
	 *
	 * The panel's `load`, handed the id, and then the panel's own filter
	 * over what it returned:
	 *
	 *     add_filter( 'wp_flyout_shop_product_data', function ( $product, $id ) {
	 *         $product->gift_wrap = get_post_meta( $id, 'gift_wrap', true );
	 *         return $product;
	 *     }, 10, 2 );
	 *
	 * which is where a field an add-on put on the panel is given its value.
	 * Only a record that loaded is filtered: a refusal -- false, null, an
	 * error -- is the panel's answer, not something to add a value to. A
	 * filter that hands back something of another kind is ignored, so one
	 * careless add-on cannot turn a record into a string.
	 *
	 * Every load goes through here -- opening the panel, running a menu
	 * item that depends on the record, drawing a feed again -- so the
	 * filter cannot be missed by one of them.
	 *
	 * @param string $flyout_id The flyout.
	 * @param mixed  $item_id   The record's id; empty for one that does not exist yet.
	 *
	 * @return mixed What `load` returned, filtered; null when the panel has no `load`.
	 * @since 1.0.0
	 */
	public function load_record( string $flyout_id, $item_id ) {
		$load = $this->flyouts[ $flyout_id ]['load'] ?? null;

		if ( ! is_callable( $load ) ) {
			return null;
		}

		$data = $load( $item_id );

		if ( null === $data || false === $data || is_wp_error( $data ) ) {
			return $data;
		}

		/**
		 * Filter what one panel loaded.
		 *
		 * @param mixed $data    What the panel's `load` returned: usually an
		 *                       object with a property per field. When adding,
		 *                       the id is empty and whatever is set here is the
		 *                       field's default.
		 * @param mixed $item_id The record's id.
		 *
		 * @since 1.0.0
		 */
		$filtered = apply_filters( "wp_flyout_{$this->prefix}_{$flyout_id}_data", $data, $item_id );

		return gettype( $filtered ) === gettype( $data ) ? $filtered : $data;
	}

	/**
	 * Sanitize a submission against this flyout's own fields.
	 *
	 * Through the field set, which means each value is coerced by its own
	 * type — the same coercion the same field gets on a settings page, a
	 * metabox or a term screen. This library used to carry a Sanitizer of
	 * its own, seven hundred lines deciding for a second time what a number
	 * or a checkbox is, and the two had already drifted.
	 *
	 * A key the flyout does not declare is dropped rather than passed on,
	 * which is what makes a submission from a crafted form no more powerful
	 * than one from the panel.
	 *
	 * @param array<string, mixed> $fields Field configuration.
	 * @param array<string, mixed> $input  Submitted values.
	 *
	 * @return array<string, mixed>
	 */
	public function sanitize( array $fields, array $input ): array {
		[ $set, $context ] = $this->field_set( $fields, null );

		// Re-slashed because the REST layer has already unslashed and the
		// field set unslashes at its own boundary; without it every
		// backslash someone typed is eaten by the save that stores it.
		$set->save( wp_slash( $input ) );

		return $context->values();
	}

	/**
	 * A field set over whatever the load callback handed back.
	 *
	 * This is the change that makes a flyout the same thing as every other
	 * surface in this codebase: a set of fields over a context. `load`
	 * returning a record and `save` taking an array *is* a context — reading
	 * and writing — so it is expressed as one, and the fields package then does the
	 * building, the value reading, the sanitizing and the accessibility that
	 * this library used to do itself in four separate places.
	 *
	 * Components are left out of the set. They display rather than collect,
	 * have no value to read and nothing to sanitize, and each is rendered in
	 * its place by the walk that calls this.
	 *
	 * @param array<string, mixed> $fields Field configuration.
	 * @param mixed                $data   Whatever `load` returned.
	 *
	 * @return array{0: FieldSet, 1: ObjectContext}
	 */
	private function field_set( array $fields, $data ): array {
		$context = new ObjectContext( is_object( $data ) ? $data : null );
		$configs = [];

		foreach ( $this->normalize_fields( $fields ) as $key => $field ) {
			if ( Components::is_component( (string) ( $field['type'] ?? 'text' ) ) ) {
				continue;
			}

			// Keyed by the posted name where one is given: the field set
			// derives input_name from the key, and the name is what the form
			// actually submits.
			$config = FormField::to_field( $field );

			// An amount with a unit beside it -- "5" and whether that is
			// percent or pounds -- keeps the unit under a key of its own.
			// The fields package draws whichever unit `current_type` names, and nothing
			// told it what the record holds, so every amount opened showing
			// the default unit: a five pound discount read "5 %", and saving
			// the form as shown made it five percent.
			if ( 'amount_type' === ( $config['type'] ?? '' ) && ! isset( $config['current_type'] ) && is_object( $data ) ) {
				$unit_key = (string) ( $config['type_meta_key'] ?? ( (string) ( $field['name'] ?? $key ) . '_type' ) );

				if ( isset( $data->{$unit_key} ) && is_scalar( $data->{$unit_key} ) && '' !== (string) $data->{$unit_key} ) {
					$config['current_type'] = (string) $data->{$unit_key};
				}
			}

			// Which leaves nothing for `name` to say -- it is the key now --
			// and `tab` was spent grouping the fields before this ran. The
			// fields package reads neither, and reports configuration nothing reads
			// under WP_DEBUG once per field per render: a product panel
			// emitted a hundred and fifty-eight notices, which is enough to
			// bury any that meant something.
			unset( $config['name'], $config['tab'], $config['section'] );

			$configs[ (string) ( $field['name'] ?? $key ) ] = $config;
		}

		return [ new FieldSet( $configs, $context, '' ), $context ];
	}

	private function render_fields( array $fields, $data ): string {
		$fields = apply_filters( "wp_flyout_before_render_fields_{$this->prefix}", $fields, $data );

		/*
		 * Everything that can change a field, before the set is built from
		 * them.
		 *
		 * The set used to be built first, from the untouched configuration,
		 * and the filters ran inside the render loop below. Since the control
		 * itself comes out of the set and only its label and description come
		 * from `$field`, every one of these filters could restyle the chrome
		 * around a field and not the field -- so a filter adding, say, search
		 * arguments to a picker changed nothing at all, silently, and looked
		 * right in any test that inspected the filtered array rather than the
		 * rendered page.
		 */
		$normalized_fields = $this->normalize_fields( $fields );

		foreach ( $normalized_fields as $field_key => $field ) {
			$field = apply_filters( "wp_flyout_render_field_{$this->prefix}", $field, $field_key, $data );
			$field = apply_filters( "wp_flyout_render_field_{$this->prefix}_{$field_key}", $field, $data );

			// Normalize AJAX fields (search URL, hydration).
			$field = $this->normalize_ajax_fields( $field, $field_key, $data );

			$normalized_fields[ $field_key ] = $field;
		}

		// A card is a run of neighbours, so the fields of one card are put
		// together first -- one an add-on appended would otherwise open a
		// second card of the same name at the foot of the panel.
		if ( [] !== $this->sections ) {
			$normalized_fields = self::gather( $normalized_fields );
		}

		[ $set ] = $this->field_set( $normalized_fields, $data );

		// Which card is open, when the panel has any. Null is none yet.
		$open = null;

		// What has been drawn for each column of a split panel. Everything
		// goes in `main` until a section says it stands aside.
		$columns = [
			'main'  => '',
			'aside' => '',
		];
		$column  = 'main';

		foreach ( $normalized_fields as $field_key => $field ) {
			$type = $field['type'] ?? 'text';

			if ( [] !== $this->sections ) {
				$section = (string) ( $field['section'] ?? '' );

				if ( $section !== $open ) {
					$columns[ $column ] .= null === $open ? '' : $this->close_section( $open );

					$column              = $this->section_is_aside( $section ) ? 'aside' : 'main';
					$columns[ $column ] .= $this->open_section( $section );
					$open                = $section;
				}
			}

			// Use field name for data lookup (field name may differ from field key).
			$data_key = $field['name'] ?? $field_key;

			// Render field based on type.
			if ( Components::is_component( $type ) ) {
				$resolved_data = Components::resolve_data( $type, $data_key, $data );

				foreach ( $resolved_data as $key => $value ) {
					if ( ! isset( $field[ $key ] ) && $value !== null ) {
						$field[ $key ] = $value;
					}
				}

				$field        = $this->prepare_component( $type, $field );
				$component    = Components::create( $type, $field, $this->prefix );
				$field_output = $component ? $component->render() : '';
			} else {
				// Through the set, which reads the value from the context and
				// renders with the fields package's labelling, descriptions, required
				// state and conditional logic already correct.
				$rendered = $set->field( $data_key );

				$field_output = null === $rendered
					? ''
					: FormField::wrap( $field, $set->render_field( $rendered ) );
			}

			$columns[ $column ] .= $field_output;
		}

		if ( null !== $open ) {
			$columns[ $column ] .= $this->close_section( $open );
		}

		// Two columns only when something stands in the second. A split
		// panel whose page has no aside section -- one tab of several, say
		// -- is drawn as the single column it is.
		$output = '' === $columns['aside']
			? $columns['main']
			: sprintf(
				'<div class="wp-flyout-split"><div class="wp-flyout-split-main">%s</div><div class="wp-flyout-split-aside">%s</div></div>',
				$columns['main'],
				$columns['aside']
			);

		return apply_filters( "wp_flyout_after_render_fields_{$this->prefix}", $output, $fields, $data );
	}

	/**
	 * Put each card's fields next to each other.
	 *
	 * A card is drawn as a run of neighbours: a new one opens whenever the
	 * next field names a different section. So a field an add-on appended
	 * with `section => details` would open a second Details card at the
	 * foot of the panel. Gathered here, it lands at the end of the card it
	 * named, and a section nobody has used yet becomes a card where its
	 * first field was put.
	 *
	 * A field on no card keeps its own place; the order is otherwise left
	 * alone.
	 *
	 * @param array<string, array<string, mixed>> $fields Normalized fields of one page.
	 *
	 * @return array<string, array<string, mixed>>
	 * @since 1.0.0
	 */
	public static function gather( array $fields ): array {
		$runs = [];

		foreach ( $fields as $key => $field ) {
			$section = is_array( $field ) ? (string) ( $field['section'] ?? '' ) : '';
			$run     = '' === $section ? 'field:' . $key : 'card:' . $section;

			$runs[ $run ][ $key ] = $field;
		}

		return [] === $runs ? [] : array_merge( ...array_values( $runs ) );
	}

	/**
	 * What a component needs to know about the panel it is drawn in.
	 *
	 * A component that shows money takes the panel's `money` unit unless it
	 * names its own; an activity feed is told whose feed it is, so it can
	 * read its events from its `source`, link to this manager's panels and
	 * leave out the note box for a record that does not exist yet.
	 *
	 * @param string               $type  The component type.
	 * @param array<string, mixed> $field Its field.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	private function prepare_component( string $type, array $field ): array {
		if ( '' !== $this->money && ! isset( $field['money'] ) && in_array( $type, Components::MONEY, true ) ) {
			$field['money'] = $this->money;
		}

		if ( 'activity' === $type ) {
			$field['manager']   = $this->prefix;
			$field['record_id'] = $this->record_id;
		}

		return $field;
	}

	/**
	 * Whether a section starts folded away.
	 *
	 * @param string $section The section's key.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	private function section_is_collapsed( string $section ): bool {
		$config = $this->sections[ $section ] ?? null;

		return is_array( $config ) && ! empty( $config['collapsed'] );
	}

	/**
	 * Whether a section stands in the second column.
	 *
	 * Only on a panel laid out as `split`. The flag on its own does nothing,
	 * so a section can carry it and the panel can change its mind.
	 *
	 * @param string $section The section's key.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	private function section_is_aside( string $section ): bool {
		$config = $this->sections[ $section ] ?? null;

		return 'split' === $this->layout && is_array( $config ) && ! empty( $config['aside'] );
	}

	/**
	 * Open the card a run of fields sits on.
	 *
	 * A form is easier to fill in as a page of titled cards than as tabs:
	 * nothing is out of sight, and the title says what the fields under it
	 * are for. `sections` names them -- a label, or `label`, `description`,
	 * `collapsed` and `aside` -- and a field says which it belongs to with
	 * `section`. Fields are grouped as they come, so a section is a run of
	 * neighbours; a field that names none gets a card with no title.
	 *
	 * A collapsed section is a <details>, which is the browser's own
	 * disclosure: it opens with the keyboard, and it opens itself when the
	 * browser needs to show a field inside it that failed validation.
	 *
	 * @param string $section The section's key, or '' for none.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	private function open_section( string $section ): string {
		$config = $this->sections[ $section ] ?? '';
		$label  = is_array( $config ) ? (string) ( $config['label'] ?? '' ) : (string) $config;
		$about  = is_array( $config ) ? (string) ( $config['description'] ?? '' ) : '';
		$class  = 'wp-flyout-section' . ( '' === $section ? '' : ' wp-flyout-section--' . sanitize_html_class( $section ) );

		$about = '' === $about ? '' : sprintf( '<p class="wp-flyout-section-description">%s</p>', esc_html( $about ) );

		if ( $this->section_is_collapsed( $section ) ) {
			return sprintf(
				'<details class="%1$s wp-flyout-section--collapsible"><summary class="wp-flyout-section-title">%2$s</summary><div class="wp-flyout-section-fields">%3$s',
				esc_attr( $class ),
				esc_html( $label ),
				$about
			);
		}

		return sprintf(
			'<section class="%1$s">%2$s%3$s<div class="wp-flyout-section-fields">',
			esc_attr( $class ),
			'' === $label ? '' : sprintf( '<h3 class="wp-flyout-section-title">%s</h3>', esc_html( $label ) ),
			$about
		);
	}

	/**
	 * Close a card.
	 *
	 * @param string $section The section's key, or '' for none.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	private function close_section( string $section ): string {
		return $this->section_is_collapsed( $section ) ? '</div></details>' : '</div></section>';
	}

	/**
	 * Normalize field configurations.
	 *
	 * Ensures every field has a 'name' key and uses string keys.
	 *
	 * @param array $fields Field configurations.
	 *
	 * @return array Normalized fields.
	 * @since 1.0.0
	 */
	public function normalize_fields( array $fields ): array {
		$fields = apply_filters( "wp_flyout_before_normalize_fields_{$this->prefix}", $fields );

		$normalized = [];

		foreach ( $fields as $field_key => $field ) {
			if ( is_numeric( $field_key ) ) {
				$field_key = $field['name'] ?? 'field_' . $field_key;
			}

			if ( ! isset( $field['name'] ) ) {
				$field['name'] = $field_key;
			}

			$normalized[ $field_key ] = $field;
		}

		return apply_filters( "wp_flyout_after_normalize_fields_{$this->prefix}", $normalized );
	}

	/**
	 * Normalize AJAX field configurations for REST API.
	 *
	 * Sets the REST search URL and handles hydration for ajax_select fields.
	 *
	 * @param array  $field     Field configuration.
	 * @param string $field_key Field identifier.
	 * @param mixed  $data      Data source for value resolution.
	 *
	 * @return array Normalized field configuration.
	 * @since 1.0.0
	 */
	private function normalize_ajax_fields( array $field, string $field_key, $data ): array {
		// `post`, `taxonomy` and `user` used to be converted here into this
		// library's own ajax_select, with a hand-written search callback, so
		// they never reached the fields package's types of the same names at all — which
		// is why a relational field in a flyout came out as a plain <select>
		// reading "— Select —" while the same field on a settings page was a
		// searchable combobox. They go straight through now, and the fields package's
		// search endpoint answers for them.
		//
		// What is left here is the context two components cannot work out for
		// themselves: which manager and which flyout they belong to.
		if ( 'line_items' === ( $field['type'] ?? '' ) ) {
			$flyout_id = $this->get_flyout_id_for_field( $field_key );

			$field['manager'] = $this->prefix;
			$field['flyout']  = $flyout_id;
		}

		return $field;
	}

	// =========================================================================
	// COMPONENT DETECTION
	// =========================================================================

	/**
	 * Detect and register required components from configuration.
	 *
	 * @param array $config Flyout configuration.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function detect_components( array $config ): void {
		foreach ( $config['fields'] as $field ) {
			$asset = Components::get_asset( $field['type'] ?? 'text', $field );

			if ( $asset ) {
				$this->components[] = $asset;
			}
		}

		$this->components = array_unique( $this->components );
	}

	// =========================================================================
	// ASSET MANAGEMENT
	// =========================================================================

	/**
	 * Maybe enqueue assets based on current admin page.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function maybe_enqueue_assets( string $hook_suffix ): void {
		if ( $this->assets_enqueued || empty( $this->flyouts ) ) {
			return;
		}

		if ( $this->should_enqueue( $hook_suffix ) ) {
			$this->enqueue_assets();
		}
	}

	/**
	 * Determine if assets should be enqueued.
	 *
	 * Only loads on explicitly declared admin pages. No fallback list.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 *
	 * @return bool True if assets should load.
	 * @since 1.0.0
	 */
	private function should_enqueue( string $hook_suffix ): bool {
		if ( empty( $this->admin_pages ) ) {
			return false;
		}

		if ( in_array( $hook_suffix, $this->admin_pages, true ) ) {
			return true;
		}

		// A page slug, which is what a consumer thinks they are giving. The
		// hook suffix of a submenu page is built by WordPress out of the
		// parent's slug and is not something anyone knows without printing
		// it — 'apfd_demo_page_apfd-table' for a page registered as
		// 'apfd-table' under a post type. Matching against the slug too means
		// the obvious thing works, instead of failing silently: an unmatched
		// page renders no flyout at all, so the trigger button is there and
		// pressing it does nothing.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- identifying the screen, not acting.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return '' !== $page && in_array( $page, $this->admin_pages, true );
	}

	/**
	 * What the field types across every flyout on this screen need.
	 *
	 * The media frame, the colour picker, the code editor: each is a
	 * dependency of a particular type rather than of the fields package, and each has to
	 * be enqueued before the fields package's own script so it is there when the fields package
	 * looks for it.
	 *
	 * Asked of the types rather than of a field set, because a flyout has no
	 * field set — it renders one FormField at a time.
	 *
	 * @return array{scripts: string[], styles: string[], code_editors: string[]}
	 */
	private function field_dependencies(): array {
		$registry = new FieldRegistry();
		$scripts  = [];
		$styles   = [];

		foreach ( $this->flyouts as $config ) {
			foreach ( (array) ( $config['fields'] ?? [] ) as $field ) {
				$id = (string) ( $field['type'] ?? 'text' );

				if ( ! $registry->has( $id ) ) {
					continue;
				}

				$type = $registry->get( $id );

				// Per field where the type can say: a colour field wants
				// core's picker only when it asked for it, and the fields package's
				// FieldSet asks the same way.
				$needs   = method_exists( $type, 'dependencies_for' ) ? $type->dependencies_for( (array) $field ) : $type->dependencies();
				$scripts = array_merge( $scripts, $needs['scripts'] ?? [] );
				$styles  = array_merge( $styles, $needs['styles'] ?? [] );
			}
		}

		return [
			'scripts'      => array_values( array_unique( $scripts ) ),
			'styles'       => array_values( array_unique( $styles ) ),
			'code_editors' => [],
		];
	}

	/**
	 * Enqueue required assets.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function enqueue_assets(): void {
		// The fields package's, first. Every field in a flyout is rendered by
		// the fields package, and its stylesheet and script were never enqueued
		// here — so the markup arrived with none of the rules or behaviour
		// that make it work. A button group came out as stacked radios, a
		// toggle as a plain checkbox, a sortable as a numbered list, a
		// tooltip as a bare question mark in a box, and every searchable
		// select stayed a plain <select> because the combobox never ran.
		( new FieldAssets() )->enqueue( $this->field_dependencies() );

		Assets::enqueue();

		foreach ( $this->components as $component ) {
			Assets::enqueue_component( $component );
		}

		// Published into a registry keyed by this build's core handle rather
		// than to a bare `wpFlyout` global. Two Strauss-prefixed copies each
		// enqueue their own scripts, and a shared global would leave whichever
		// localized last owning the REST URL and nonce for both. Each script
		// resolves its own entry from the id WordPress stamps on its element.
		$handle = Runtime::handle();

		wp_add_inline_script(
			Runtime::handle( 'wp-flyout' ),
			sprintf(
				'window.SugarCommerceFlyouts=window.SugarCommerceFlyouts||{};window.SugarCommerceFlyouts[%s]=%s;',
				wp_json_encode( $handle ),
				wp_json_encode( [
					'restUrl'   => rest_url( RestApi::rest_namespace() ),
					'restNonce' => wp_create_nonce( 'wp_rest' ),
					'strings'   => self::strings(),
					// The library's own icons, drawn here so the script
					// draws the same ones without a copy of their paths.
					'icons'     => Icon::markup(),
				] )
			),
			'before'
		);

		$this->assets_enqueued = true;
	}

	/**
	 * What the panel's script says to a person, translated.
	 *
	 * The script carries the same sentences in English and uses those when
	 * a page publishes none, so the keys here are the keys there. They
	 * travel with the REST URL because that is the one thing every copy of
	 * the script already reads, and reads from its own build.
	 *
	 * @return array<string, string>
	 * @since 1.0.0
	 */
	private static function strings(): array {
		return [
			'saved'         => __( 'Saved', 'sugarcommerce' ),
			'deleted'       => __( 'Deleted', 'sugarcommerce' ),
			'done'          => __( 'Done', 'sugarcommerce' ),
			'loading'       => __( 'Loading…', 'sugarcommerce' ),
			'loadFailed'    => __( 'This could not be opened', 'sugarcommerce' ),
			'requestFailed' => __( 'Something went wrong. Please try again.', 'sugarcommerce' ),
			'required'      => __( 'Please fill in all required fields.', 'sugarcommerce' ),
			'unsavedTitle'  => __( 'Discard unsaved changes?', 'sugarcommerce' ),
			'unsavedText'   => __( 'What you have changed here has not been saved.', 'sugarcommerce' ),
			'keepEditing'   => __( 'Keep editing', 'sugarcommerce' ),
			'discard'       => __( 'Discard changes', 'sugarcommerce' ),
			'deleteTitle'   => __( 'Are you sure you want to delete this item?', 'sugarcommerce' ),
			'deleteConfirm' => __( 'Delete', 'sugarcommerce' ),
			'cancel'        => __( 'Cancel', 'sugarcommerce' ),
			'confirm'       => __( 'Continue', 'sugarcommerce' ),
			'close'         => __( 'Close', 'sugarcommerce' ),
			'previous'      => __( 'Previous', 'sugarcommerce' ),
			'next'          => __( 'Next', 'sugarcommerce' ),
			/* translators: 1: a record's name, 2: its place in the list, 3: how many the list has. */
			'stepped'       => __( '%1$s, %2$s of %3$s', 'sugarcommerce' ),
			'stepKeysHint'  => __( 'Next and previous', 'sugarcommerce' ),
			'stepEnd'       => __( 'That is the end of the list.', 'sugarcommerce' ),
			'copy'          => __( 'Copy', 'sugarcommerce' ),
			'copied'        => __( 'Copied', 'sugarcommerce' ),
			'copyFailed'    => __( 'That could not be copied.', 'sugarcommerce' ),
			'send'          => __( 'Send', 'sugarcommerce' ),
			'promptDiscard' => __( 'Discard what you have written?', 'sugarcommerce' ),
			'keepWriting'   => __( 'Keep Writing', 'sugarcommerce' ),
			'promptDrop'    => __( 'Discard', 'sugarcommerce' ),
			'promptEmpty'   => __( 'This needs filling in.', 'sugarcommerce' ),
		];
	}

	// =========================================================================
	// TRIGGER GENERATION (BUTTONS & LINKS)
	// =========================================================================

	/**
	 * Render a trigger button.
	 *
	 * @param string $flyout_id Flyout identifier.
	 * @param array  $data      Data attributes to pass.
	 * @param array  $args      Button configuration.
	 *
	 * @return void Outputs HTML.
	 * @since 1.0.0
	 */
	public function button( string $flyout_id, array $data = [], array $args = [] ): void {
		// Returns markup this library assembled and escaped as it built it.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $this->get_button( $flyout_id, $data, $args );
	}

	/**
	 * Get trigger button HTML.
	 *
	 * @param string $flyout_id Flyout identifier.
	 * @param array  $data      Data attributes to pass.
	 * @param array  $args      Button configuration: `text`, `class`, and
	 *                          `icon` (a `set:name` fields icon or a Dashicons
	 *                          name, drawn before the text).
	 *
	 * @return string Button HTML or empty string if unauthorized.
	 * @since 1.0.0
	 */
	public function get_button( string $flyout_id, array $data = [], array $args = [] ): string {
		if ( ! $this->can_access( $flyout_id ) ) {
			return '';
		}

		$text = $args['text'] ?? __( 'Open', 'sugarcommerce' );

		/*
		 * The caller's class stands on its own rather than being added to
		 * `button`. core's `.page-title-action` is a complete button style --
		 * its own padding, min-height, line-height and a -3px offset -- so a
		 * trigger carrying both got two sets of box metrics and came out half
		 * again too tall beside the heading.
		 *
		 * `button` is still the default, because a trigger anywhere else on a
		 * screen is an ordinary admin button.
		 */
		$class = $args['class'] ?? 'button';

		$attrs = $this->build_trigger_attributes( $flyout_id, $data, $class );

		$html = '<button type="button"';
		foreach ( $attrs as $key => $value ) {
			$html .= sprintf( ' %s="%s"', $key, $value );
		}
		$html .= '>';

		// A glyph only when the caller asks for one: a trigger always has
		// text -- it defaults to "Open" -- and core puts no picture in front
		// of its own button text. Given, `icon` is a fields icon
		// (`heroicons-outline:pencil`) or a Dashicons name (`edit`), drawn
		// before the text; one that draws nothing leaves the text alone.
		$icon = $args['icon'] ?? '';

		if ( is_string( $icon ) && '' !== $icon ) {
			$html .= Icon::render( $icon, [ 'class' => 'wp-flyout-trigger-icon' ] );
		}

		$html .= esc_html( $text );
		$html .= '</button>';

		return $html;
	}

	/**
	 * Create a trigger link.
	 *
	 * @param string $flyout_id Flyout identifier.
	 * @param string $text      Link text.
	 * @param array  $data      Data attributes to pass.
	 * @param array  $args      Additional link arguments.
	 *
	 * @return string Link HTML or empty string if unauthorized.
	 * @since 1.0.0
	 */
	public function link( string $flyout_id, string $text, array $data = [], array $args = [] ): string {
		if ( ! $this->can_access( $flyout_id ) ) {
			return '';
		}

		$class         = $args['class'] ?? '';
		$attrs         = $this->build_trigger_attributes( $flyout_id, $data, $class );
		$attrs['href'] = '#';

		$html = '<a';
		foreach ( $attrs as $key => $value ) {
			$html .= sprintf( ' %s="%s"', $key, $value );
		}
		$html .= '>' . esc_html( $text ) . '</a>';

		return $html;
	}

	/**
	 * Build trigger element attributes.
	 *
	 * REST nonce is global (via wp_localize_script), so no per-flyout nonce needed.
	 *
	 * @param string $flyout_id Flyout identifier.
	 * @param array  $data      Data attributes.
	 * @param string $class     Additional CSS classes.
	 *
	 * @return array Attributes array.
	 * @since 1.0.0
	 */
	private function build_trigger_attributes( string $flyout_id, array $data, string $class_name = '' ): array {
		// Escaped here, because get_button() and link() write these straight
		// into the tag. The data attributes below already were; the class and
		// the flyout id were not, and both come from the caller.
		$attrs = [
			'class'               => esc_attr( trim( 'wp-flyout-trigger ' . $class_name ) ),
			'data-flyout-manager' => esc_attr( $this->prefix ),
			'data-flyout'         => esc_attr( $flyout_id ),

			// So the placeholder shown while the record loads is the width
			// the record's panel will be, and nothing jumps when it arrives.
			'data-flyout-size'    => esc_attr( (string) ( $this->flyouts[ $flyout_id ]['size'] ?? 'medium' ) ),
		];

		// And so that placeholder has its arrows where the panel will have
		// them, or has none when the panel will have none.
		$header = $this->flyouts[ $flyout_id ]['header'] ?? null;

		if ( is_array( $header ) ) {
			$attrs['data-flyout-header'] = 'identity';

			$steps = self::steps( $header );

			if ( '' !== $steps['mode'] ) {
				$attrs['data-flyout-steps'] = $steps['mode'];
			}
		}

		foreach ( $data as $key => $value ) {
			$attrs[ 'data-' . $key ] = esc_attr( (string) $value );
		}

		return $attrs;
	}

	// =========================================================================
	// PUBLIC ACCESSOR METHODS
	// =========================================================================

	/**
	 * Get a specific flyout configuration.
	 *
	 * @param string $flyout_id Flyout identifier.
	 *
	 * @return array|null Flyout config or null if not found.
	 * @since 1.0.0
	 */
	public function get_flyout( string $flyout_id ): ?array {
		return $this->flyouts[ $flyout_id ] ?? null;
	}

	/**
	 * Get all registered flyouts.
	 *
	 * @return array Flyout configurations.
	 * @since 1.0.0
	 */
	public function get_flyouts(): array {
		return $this->flyouts;
	}

	/**
	 * Check if flyout is registered.
	 *
	 * @param string $flyout_id Flyout identifier.
	 *
	 * @return bool True if flyout exists.
	 * @since 1.0.0
	 */
	public function has_flyout( string $flyout_id ): bool {
		return isset( $this->flyouts[ $flyout_id ] );
	}

	/**
	 * Get manager prefix.
	 *
	 * @return string Manager prefix.
	 * @since 1.0.0
	 */
	public function get_prefix(): string {
		return $this->prefix;
	}

	// =========================================================================
	// PRIVATE UTILITY METHODS
	// =========================================================================

	/**
	 * Check if current user can access flyout.
	 *
	 * @param string $flyout_id Flyout identifier.
	 *
	 * @return bool True if user has required capability.
	 * @since 1.0.0
	 */
	private function can_access( string $flyout_id ): bool {
		if ( ! isset( $this->flyouts[ $flyout_id ] ) ) {
			return false;
		}

		$config = $this->flyouts[ $flyout_id ];

		return current_user_can( $config['capability'] );
	}

	/**
	 * Find which flyout a field belongs to.
	 *
	 * Used by normalize_ajax_fields to set the flyout param on search URLs.
	 *
	 * @param string $field_key Field key to look up.
	 *
	 * @return string Flyout ID or empty string.
	 * @since 1.0.0
	 */
	private function get_flyout_id_for_field( string $field_key ): string {
		foreach ( $this->flyouts as $flyout_id => $config ) {
			if ( isset( $config['fields'][ $field_key ] ) ) {
				return $flyout_id;
			}

			// Check by name attribute.
			foreach ( $config['fields'] as $key => $field ) {
				if ( ( $field['name'] ?? $key ) === $field_key ) {
					return $flyout_id;
				}
			}
		}

		return '';
	}
}
