<?php
/**
 * Scripts and Styles
 *
 * @package SugarCommerce\Flyouts
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts\Traits\Manager;

use SugarCommerce\Fields\Assets as FieldAssets;
use SugarCommerce\Fields\Registry as FieldRegistry;
use SugarCommerce\Fields\Support\Dependencies;
use SugarCommerce\Fields\Utils\Runtime as FieldsRuntime;
use SugarCommerce\Flyouts\Assets;
use SugarCommerce\Flyouts\Components\FormField;
use SugarCommerce\Flyouts\RestApi;
use SugarCommerce\Flyouts\Utils\Icon;
use SugarCommerce\Flyouts\Utils\Runtime;

/**
 * Loading the scripts and styles on the admin pages that open a flyout, and
 * only there.
 */
trait Scripts {

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
	 * The media frame, the colour picker, the code editor, the visual
	 * editor: each is a dependency of a particular type rather than of the
	 * fields package, and each has to be on the page before a panel opens.
	 * A panel's fields are rendered over REST, in a request that prints
	 * nothing, so nothing a type would have enqueued while rendering ever
	 * reaches the page -- a code field opened as a bare textarea and a
	 * visual editor as a textarea with an empty toolbar.
	 *
	 * Asked of the fields package's own walk, which reads fields nested in
	 * groups and repeaters as well, after each field is put in the fields
	 * package's terms.
	 *
	 * @return array{scripts: string[], styles: string[], code_editors: string[], editor: bool}
	 */
	private function field_dependencies(): array {
		$fields = [];

		foreach ( $this->flyouts as $config ) {
			foreach ( (array) ( $config['fields'] ?? [] ) as $field ) {
				if ( is_array( $field ) ) {
					$fields[] = FormField::to_field( $field );
				}
			}
		}

		return Dependencies::of( $fields, new FieldRegistry() );
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
		// than to a bare global. Two Strauss-prefixed copies each enqueue
		// their own scripts, and a shared global would leave whichever
		// localized last owning the REST URL and nonce for both. Each script
		// resolves its own entry from the id WordPress stamps on its element,
		// and the core script adds `panel` to it -- the API that opens and
		// closes a panel.
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
					// This build's own fields script, whose modules start
					// the fields in a panel: window.SugarCommerceFields[ fields ].modules.
					'fields'    => FieldsRuntime::handle(),
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
}
