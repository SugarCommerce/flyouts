/**
 * Previous and next: the record before or after this one in the list
 * behind the panel, by the arrows in the header or by J and K.
 */

import { strings } from '../lib/config';
import { all, isShown, make, text } from '../lib/dom';
import { toast } from '../lib/feedback';
import { panelConfig } from '../lib/store';
import { extractConfig, request } from './load';

/**
 * The triggers for other records of the same kind on this page.
 *
 * Each row of a list table has one, and that is the whole of what "the
 * next record" means: the next row. One per record, however many links a
 * row has to it, and only records -- the Add button opens the same flyout
 * and is nobody's neighbour.
 *
 * @param {Object} config The open record's.
 * @return {Element[]} The triggers, in page order.
 */
export function neighbours( config ) {
	const list = [];
	const seen = {};

	all( '.wp-flyout-trigger' ).forEach( ( trigger ) => {
		const id = String( trigger.getAttribute( 'data-id' ) || '' );

		if (
			// A link inside a panel names a record; it is not a row of the
			// list behind it, and is gone when that panel is.
			! trigger.closest( '.wp-flyout' ) &&
			String( trigger.getAttribute( 'data-flyout' ) ) ===
				String( config.flyout ) &&
			String( trigger.getAttribute( 'data-flyout-manager' ) ) ===
				String( config.manager ) &&
			'' !== id &&
			'0' !== id &&
			! seen[ id ]
		) {
			seen[ id ] = true;
			list.push( trigger );
		}
	} );

	return list;
}

/**
 * Where a record is among its neighbours.
 *
 * @param {Object}    config The record's.
 * @param {Element[]} list   neighbours().
 * @return {number} Its place, or -1.
 */
export function position( config, list ) {
	const id = String( ( config.data && config.data.id ) || '' );
	let at = -1;

	list.forEach( ( trigger, index ) => {
		if ( String( trigger.getAttribute( 'data-id' ) ) === id ) {
			at = index;
		}
	} );

	return at;
}

/**
 * Whether a key pressed here is somebody typing, or using a control that
 * has its own use for the key.
 *
 * A letter belongs to whatever takes text. An arrow key belongs to far
 * more: a menu, a row of tabs, a slider, a chart that can be read with
 * them -- so an arrow steps only from the panel itself or from a plain
 * button or link.
 *
 * @param {Element|null} target What had focus.
 * @param {boolean}      arrow  Whether the key was an arrow key.
 * @return {boolean} Taken.
 */
export function keyIsTaken( target, arrow ) {
	if (
		! target ||
		target === document.body ||
		! target.closest ||
		target.classList.contains( 'wp-flyout' )
	) {
		return false;
	}

	if (
		target.isContentEditable ||
		target.closest(
			'textarea, select, [contenteditable=""], [contenteditable="true"], ' +
				'[role="textbox"], [role="searchbox"], [role="combobox"], [role="listbox"], [role="menu"], .wp-flyout-menu'
		)
	) {
		return true;
	}

	if ( 'INPUT' === target.nodeName ) {
		// A tick box takes no letters; everything else might.
		return (
			arrow ||
			! [ 'checkbox', 'radio', 'button', 'submit' ].includes(
				String( target.type )
			)
		);
	}

	if ( ! arrow ) {
		return false;
	}

	return (
		! target.matches( 'a, button, summary' ) ||
		!! target.closest( '[role]:not(.wp-flyout)' )
	);
}

// J or right for the next row, K or left for the one before.
const DIRECTIONS = {
	j: 'next',
	J: 'next',
	ArrowRight: 'next',
	k: 'prev',
	K: 'prev',
	ArrowLeft: 'prev',
};

/**
 * Step with the keyboard.
 *
 * Only for a panel that asked for it, only when the key is not somebody
 * typing, and never over a question the panel is asking. A form with
 * changes in it is asked about first, by request(), as it is for every
 * other way of leaving a record.
 *
 * @param {KeyboardEvent} event The key.
 */
export function stepKey( event ) {
	const key = String( event.key );
	const arrow = 'ArrowRight' === key || 'ArrowLeft' === key;
	const direction = DIRECTIONS[ key ];

	if (
		! direction ||
		event.defaultPrevented ||
		event.metaKey ||
		event.ctrlKey ||
		event.altKey ||
		event.repeat
	) {
		return;
	}

	const open = all( '.wp-flyout.active' );
	const flyout = open[ open.length - 1 ];

	if ( ! flyout ) {
		return;
	}

	const config = panelConfig( flyout );
	const has = ( name ) => flyout.classList.contains( name );

	if (
		! ( config.stepKeys || has( 'wp-flyout-step-keys' ) ) ||
		has( 'has-prompt' ) ||
		has( 'has-confirm' ) ||
		keyIsTaken( event.target, arrow )
	) {
		return;
	}

	// Something open over the panel -- the media library -- has the
	// keyboard, whatever has focus.
	if (
		all( '[role="dialog"]' ).some(
			( dialog ) =>
				! dialog.classList.contains( 'wp-flyout' ) && isShown( dialog )
		)
	) {
		return;
	}

	const list = neighbours( config );
	const at = position( config, list );

	if ( -1 === at || list.length < 2 ) {
		return;
	}

	event.preventDefault();

	if ( ! list[ at + ( 'next' === direction ? 1 : -1 ) ] ) {
		toast( flyout, strings().stepEnd );
		return;
	}

	step( config, direction, true );
}

/**
 * Load the record before or after this one, in place.
 *
 * @param {Object}  config    The open record's.
 * @param {string}  direction 'next' or 'prev'.
 * @param {boolean} [byKey]   Whether a key asked, and not an arrow.
 */
export function step( config, direction, byKey ) {
	const list = neighbours( config );
	const at = position( config, list );
	const to = list[ at + ( 'next' === direction ? 1 : -1 ) ];

	if ( -1 === at || ! to ) {
		return;
	}

	const next = extractConfig( to );
	const place = position( next, list );
	const last = place === ( 'next' === direction ? list.length - 1 : 0 );

	// Focus stays on the arrow that was pressed, so it can be pressed again
	// -- or on the other one at the end of the list, where the one that was
	// pressed has gone dead. A key leaves it on the panel, where the next
	// key is heard.
	let focus = last ? '.wp-flyout-step' : '.wp-flyout-' + direction;

	if ( byKey ) {
		focus = ':panel';
	}

	request( next, {
		focus,

		// Said once the record is there: which one, and where in the list.
		announce: { at: place + 1, of: list.length },
	} );
}

/**
 * Tell a screen reader which record a step arrived at.
 *
 * In a region of its own inside the new panel, filled a moment after it
 * exists: a live region that arrives already full is not read.
 *
 * @param {Element} flyout The new panel.
 * @param {Object}  place  `at` and `of`.
 */
export function announceStep( flyout, place ) {
	const name = text( flyout.querySelector( '.wp-flyout-title' ) );

	if ( ! place || ! name ) {
		return;
	}

	const region = make( 'div', {
		class: 'screen-reader-text wp-flyout-step-status',
		role: 'status',
		'aria-live': 'polite',
	} );

	flyout.append( region );

	setTimeout( () => {
		// As functions, so a name with a `$&` in it is said as it is.
		region.textContent = strings()
			.stepped.replace( '%1$s', () => name )
			.replace( '%2$s', () => place.at )
			.replace( '%3$s', () => place.of );
	}, 150 );
}
