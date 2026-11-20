<?php
/**
 * Markup Component
 *
 * @package     SugarCommerce\Flyouts\Components
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts\Components;

use SugarCommerce\Flyouts\Renderable;

/**
 * A place on a panel for markup the plugin drew itself: a chart, a list of
 * what somebody owns, a card that is one piece of markup.
 *
 * It prints what it is given, unescaped. That is safe for one reason only,
 * and it is a contract: what it is given comes from the panel's `load` (or
 * the field's own `html`), built by the plugin's code, which escapes as it
 * goes. Nothing a person typed is handed to it raw, and nothing in a request
 * reaches it at all -- a load is not handed the request, only the record's
 * id. Markup that has to pass through a sanitiser is the fields package's `html` field
 * type, which runs wp_kses_post() over it and so cannot carry an SVG or a
 * button.
 *
 *     'chart' => [ 'type' => 'markup', 'section' => 'sales', 'class' => 'my-chart-slot' ],
 *
 * and the panel's `load` sets `$record->chart` to the markup. The wrapper
 * carries the field's `class`, which is what an action's `updates` names to
 * draw the piece again in place.
 *
 * @since 1.0.0
 */
class Markup implements Renderable {

	/**
	 * What the field was configured with.
	 *
	 * @var array<string, mixed>
	 */
	private array $config;

	/**
	 * Set it up.
	 *
	 * @param array<string, mixed> $config `html`, and optionally `class`.
	 */
	public function __construct( array $config = [] ) {
		$this->config = $config;
	}

	/**
	 * Draw it.
	 *
	 * @return string Empty when there is nothing to draw and no class to
	 *                draw it into later.
	 */
	#[\Override]
	public function render(): string {
		$html  = is_string( $this->config['html'] ?? null ) ? $this->config['html'] : '';
		$extra = trim( is_string( $this->config['class'] ?? null ) ? $this->config['class'] : '' );

		if ( '' === $html && '' === $extra ) {
			return '';
		}

		return sprintf( '<div class="%s">%s</div>', esc_attr( trim( 'wp-flyout-markup ' . $extra ) ), $html );
	}
}
