<?php
/**
 * Buttons and Links
 *
 * @package SugarCommerce\Flyouts
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts\Traits\Manager;

use SugarCommerce\Flyouts\Flyout;
use SugarCommerce\Flyouts\Utils\Icon;
use SugarCommerce\Flyouts\Utils\Runtime;

/**
 * The buttons and links that open a flyout.
 */
trait Triggers {

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

			// Whose scripts answer it: another plugin's copy of this
			// library on the same page leaves it alone.
			'data-flyout-build'   => esc_attr( Runtime::handle() ),

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

		// A key is an attribute's name, so it is reduced to what a name may
		// be: one taken from a record could otherwise close the attribute
		// and open another. A browser lowercases the name anyway.
		foreach ( $data as $key => $value ) {
			$key = sanitize_key( (string) $key );

			if ( '' !== $key ) {
				$attrs[ 'data-' . $key ] = esc_attr( (string) $value );
			}
		}

		return $attrs;
	}
}
