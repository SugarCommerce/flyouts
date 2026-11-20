<?php
/**
 * Fields
 *
 * @package SugarCommerce\Flyouts
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts\Traits\Manager;

use SugarCommerce\Fields\Context\ObjectContext;
use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\FieldSet;
use SugarCommerce\Fields\Types\AmountType;
use SugarCommerce\Flyouts\Components;
use SugarCommerce\Flyouts\Components\FormField;
use SugarCommerce\Flyouts\Flyout;

/**
 * A flyout's fields: loading a record into them, sanitising what comes
 * back, and telling a component from a field.
 */
trait Fields {

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

		$values = $context->values();

		// An amount's unit is a second key beside it, which the field set
		// leaves to whoever stores the record -- and a panel stores what
		// `save` is handed. Handed nothing, a five pound discount saved as
		// five percent: the panel reads the unit back from the record, and
		// the record never got it.
		foreach ( $set->fields() as $field ) {
			$type = $field->type();

			if ( $type instanceof AmountType ) {
				$unit = $type->posted_unit( $field, wp_slash( $input ) );

				if ( '' !== $unit ) {
					$values[ $type->unit_key( $field ) ] = $unit;
				}
			}
		}

		return $values;
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
			if ( $this->is_component( $field, (string) $key, $data ) ) {
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
	 * Whether a field is one of this library's components rather than a
	 * field of the fields package.
	 *
	 * One name means both. `accordion` is this library's component of
	 * folding items, and the fields package's marker that folds the fields
	 * after it. An accordion with items -- its own, or the record's under
	 * its key -- is the component; one with none would draw nothing as a
	 * component, and is the marker. Read as the component, every accordion
	 * marker in a panel vanished, and its fields with it into one list.
	 *
	 * @param array<string, mixed> $field The field.
	 * @param string               $key   Its key.
	 * @param mixed                $data  The record.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	private function is_component( array $field, string $key, $data ): bool {
		$type = (string) ( $field['type'] ?? 'text' );

		if ( ! Components::is_component( $type ) ) {
			return false;
		}

		if ( 'accordion' === $type && empty( $field['items'] ) ) {
			$resolved = Components::resolve_data( 'accordion', (string) ( $field['name'] ?? $key ), $data );

			return ! empty( $resolved['items'] );
		}

		return true;
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
}
