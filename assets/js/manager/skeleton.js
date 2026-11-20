/**
 * The placeholder a panel shows while its record loads: grey blocks in the
 * shape of a title and two cards of fields.
 */

import { build, icon } from '../lib/config';
import { parse } from '../lib/dom';

const FIELD =
	'<span class="wp-flyout-skeleton wp-flyout-skeleton-label"></span>' +
	'<span class="wp-flyout-skeleton wp-flyout-skeleton-input"></span>';

/**
 * The placeholder panel, not yet in the page.
 *
 * @param {string}   id      Its id.
 * @param {string[]} shape   Its size and side classes.
 * @param {string}   loading What a screen reader is told.
 * @return {Element} The panel.
 */
export function skeleton( id, shape, loading ) {
	const [ flyout ] = parse(
		'<div class="wp-flyout wp-flyout-loading wp-flyout-dynamic" aria-busy="true">' +
			'<div class="wp-flyout-header">' +
			'<div class="wp-flyout-header-content">' +
			'<h2 class="wp-flyout-title">' +
			'<span class="wp-flyout-skeleton wp-flyout-skeleton-title" aria-hidden="true"></span>' +
			'<span class="screen-reader-text wp-flyout-loading-text" role="status"></span>' +
			'</h2>' +
			'</div>' +
			'<button type="button" class="wp-flyout-close">' +
			icon( 'close', 'no-alt' ) +
			'</button>' +
			'</div>' +
			'<div class="wp-flyout-body">' +
			'<div class="wp-flyout-skeleton-card" aria-hidden="true">' +
			'<span class="wp-flyout-skeleton wp-flyout-skeleton-heading"></span>' +
			FIELD +
			'<span class="wp-flyout-skeleton wp-flyout-skeleton-label"></span>' +
			'<span class="wp-flyout-skeleton wp-flyout-skeleton-input wp-flyout-skeleton-input--tall"></span>' +
			FIELD +
			'</div>' +
			'<div class="wp-flyout-skeleton-card" aria-hidden="true">' +
			'<span class="wp-flyout-skeleton wp-flyout-skeleton-heading"></span>' +
			FIELD +
			'</div>' +
			'</div>' +
			'</div>'
	);

	flyout.id = id;
	flyout.classList.add( ...shape );

	// This build's, as a panel the server drew is: another plugin's copy of
	// the scripts on the page leaves it alone.
	if ( build() ) {
		flyout.setAttribute( 'data-flyout-build', build() );
	}
	flyout.querySelector( '.wp-flyout-loading-text' ).textContent = loading;

	return flyout;
}
