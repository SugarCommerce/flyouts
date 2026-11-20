<?php
/**
 * Rendering Fields
 *
 * @package SugarCommerce\Flyouts
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts\Traits\Manager;

use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\Support\Sections;
use SugarCommerce\Flyouts\Components;
use SugarCommerce\Flyouts\Components\FormField;

/**
 * Drawing a flyout's fields: the runs between dividers, and the titled,
 * collapsible and aside sections around them.
 */
trait Rendering {

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

		// What has been drawn for the card that is open, held until it
		// closes, so a tab or accordion field among its fields can divide
		// them. Each is markup, or the marker Field itself.
		$run = [];

		foreach ( $normalized_fields as $field_key => $field ) {
			$type = $field['type'] ?? 'text';

			if ( [] !== $this->sections ) {
				$section = (string) ( $field['section'] ?? '' );

				if ( $section !== $open ) {
					$columns[ $column ] .= $this->divide( $run, (string) $open );
					$run                 = [];
					$columns[ $column ] .= null === $open ? '' : $this->close_section( $open );

					$column              = $this->section_is_aside( $section ) ? 'aside' : 'main';
					$columns[ $column ] .= $this->open_section( $section );
					$open                = $section;
				}
			}

			// Use field name for data lookup (field name may differ from field key).
			$data_key = $field['name'] ?? $field_key;

			// Render field based on type.
			if ( $this->is_component( $field, (string) $field_key, $data ) ) {
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

				// A tab or an accordion is not a control: it divides the
				// fields after it, and is kept for divide() to draw them by.
				if ( null !== $rendered && Sections::is_marker( $rendered ) ) {
					$run[] = $rendered;

					continue;
				}

				$field_output = null === $rendered
					? ''
					: FormField::wrap( $field, $set->render_field( $rendered ) );
			}

			$run[] = $field_output;
		}

		$columns[ $column ] .= $this->divide( $run, (string) $open );

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
	 * Draw a run of fields, divided into tabs or accordions where a `tab`
	 * or `accordion` field says so.
	 *
	 * The fields package draws a set's markers itself, but a panel asks it
	 * for one field at a time, so they reached the panel as fields that
	 * render nothing: a panel declared with two tabs came out as one flat
	 * list and nothing said why. They divide the fields of the card they
	 * are in, or of the page when the panel has no cards -- the same rule
	 * a field set has, drawn by the same code.
	 *
	 * @param array<int, string|Field> $run   Markup drawn so far, with the markers in place.
	 * @param string                   $scope The card, for ids unique to it.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	private function divide( array $run, string $scope ): string {
		$draw   = static fn( array $parts ): string => implode( '', array_filter( $parts, 'is_string' ) );
		$layout = Sections::split( $run );

		if ( [] === $layout ) {
			return $draw( $run );
		}

		// Numbered, because two tabs of one panel each without cards are
		// both scope '' and would otherwise draw the same ids.
		return Sections::render( $layout, $draw, $this->prefix . '-' . $scope . '-' . ( ++$this->divisions ) );
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
}
