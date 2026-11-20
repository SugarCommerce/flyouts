<?php
/**
 * WP Flyout Manager
 *
 * Manages flyout registration, building, trigger generation, and asset management.
 * Each of those jobs is a trait in src/Traits/Manager/; this class holds the
 * state they share. Requests are answered by the REST API (RestApi.php).
 *
 * @package     SugarCommerce\Flyouts
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts;

use SugarCommerce\Fields\Field;
use SugarCommerce\Flyouts\Traits\Manager\Building;
use SugarCommerce\Flyouts\Traits\Manager\Fields;
use SugarCommerce\Flyouts\Traits\Manager\Header;
use SugarCommerce\Flyouts\Traits\Manager\Registration;
use SugarCommerce\Flyouts\Traits\Manager\Rendering;
use SugarCommerce\Flyouts\Traits\Manager\Scripts;
use SugarCommerce\Flyouts\Traits\Manager\Triggers;

/**
 * Class Manager
 *
 * Orchestrates flyout registration, building, and asset management.
 * All data transport is handled by RestApi via the REST API.
 *
 * @since 1.0.0
 */
class Manager {

	use Registration;
	use Building;
	use Header;
	use Fields;
	use Rendering;
	use Scripts;
	use Triggers;

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

	/**
	 * How many runs of fields have been divided into tabs or accordions,
	 * for ids no two of them share.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private int $divisions = 0;

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
