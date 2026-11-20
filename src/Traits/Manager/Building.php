<?php
/**
 * Building a Flyout
 *
 * @package SugarCommerce\Flyouts
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts\Traits\Manager;

use SugarCommerce\Flyouts\ActionBar;
use SugarCommerce\Flyouts\Flyout;
use SugarCommerce\Flyouts\Utils\Amount;
use SugarCommerce\Flyouts\Utils\Icon;

/**
 * Building a flyout for one record: its tabs, its fields and its footer
 * actions.
 */
trait Building {

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
			: $this->get_default_actions( $config, $id );

		if ( ! empty( $actions ) ) {
			$flyout->set_footer( $this->render_actions( $actions ) );
		}

		return $flyout;
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
	 * Whether a panel is for a record that does not exist yet.
	 *
	 * @param mixed $id The record's id, as the panel was opened with it.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	private static function is_new( $id ): bool {
		return null === $id || '' === $id || 0 === $id || '0' === $id;
	}

	/**
	 * Get default action buttons based on configuration.
	 *
	 * @param array $config Flyout configuration.
	 * @param mixed $id     The record, or nothing for a new one.
	 *
	 * @return array Default action buttons.
	 * @since 1.0.0
	 */
	private function get_default_actions( array $config, $id = null ): array {
		$actions = [];

		/*
		 * In the order they are read and tabbed through: Delete on the far
		 * side, away from the hand, then the way out, then Save last and
		 * nearest the corner -- where the button that finishes a dialog is
		 * on every screen WordPress has.
		 */
		// A record that does not exist yet has nothing to delete: "New
		// order" opened with a red Delete beside Save.
		if ( ! empty( $config['delete'] ) && ! self::is_new( $id ) ) {
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
}
