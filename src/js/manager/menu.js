/**
 * The header menu: the "more" button beside the close button, and what
 * its items do.
 *
 * A menu button, as the authoring practices describe one: the button
 * opens it and says so, the arrows move through it, Home and End go to its
 * ends, Escape puts it away and gives focus back, and Tab leaves it closed
 * behind.
 */

import { strings } from '../lib/config';
import { all, on, one, text } from '../lib/dom';
import { go } from '../lib/page';
import { runMenuAction } from './actions';
import { confirm, confirmDiscard } from './confirm';
import { isDirty } from './dirty';

/**
 * Open a header menu.
 *
 * @param {Element} menu    The menu's wrapper.
 * @param {string}  [which] 'last' to land on the last item.
 */
export function openMenu( menu, which ) {
	const items = all( '.wp-flyout-menu-item', menu );
	const target = 'last' === which ? items[ items.length - 1 ] : items[ 0 ];

	all( '.wp-flyout-menu-list', menu ).forEach( ( list ) => {
		list.hidden = false;
	} );
	all( '.wp-flyout-menu-toggle', menu ).forEach( ( toggle ) =>
		toggle.setAttribute( 'aria-expanded', 'true' )
	);

	if ( target ) {
		target.focus();
	}
}

/**
 * Put a header menu away.
 *
 * @param {Element} menu      The menu's wrapper.
 * @param {boolean} [refocus] Hand focus back to its button.
 */
export function closeMenu( menu, refocus ) {
	all( '.wp-flyout-menu-list', menu ).forEach( ( list ) => {
		list.hidden = true;
	} );
	all( '.wp-flyout-menu-toggle', menu ).forEach( ( toggle ) =>
		toggle.setAttribute( 'aria-expanded', 'false' )
	);

	const toggle = one( '.wp-flyout-menu-toggle', menu );

	if ( refocus && toggle ) {
		toggle.focus();
	}
}

/**
 * Move through an open menu, or open it, from the keyboard.
 *
 * @param {KeyboardEvent} event The key.
 * @param {Element}       menu  The menu's wrapper.
 */
function steer( event, menu ) {
	const items = all( '.wp-flyout-menu-item', menu );
	const toggle = one( '.wp-flyout-menu-toggle', menu );
	const open = !! toggle && 'true' === toggle.getAttribute( 'aria-expanded' );
	const at = items.indexOf( menu.ownerDocument.activeElement );
	const focus = ( item ) => item && item.focus();

	switch ( event.key ) {
		case 'ArrowDown':
			event.preventDefault();

			if ( ! open ) {
				openMenu( menu );
			} else {
				focus( items[ ( at + 1 ) % items.length ] );
			}
			break;

		case 'ArrowUp':
			event.preventDefault();

			if ( ! open ) {
				openMenu( menu, 'last' );
			} else {
				focus( items[ at <= 0 ? items.length - 1 : at - 1 ] );
			}
			break;

		case 'Home':
			if ( open ) {
				event.preventDefault();
				focus( items[ 0 ] );
			}
			break;

		case 'End':
			if ( open ) {
				event.preventDefault();
				focus( items[ items.length - 1 ] );
			}
			break;

		case 'Escape':
			if ( open ) {
				// The menu's, and not the panel's as well.
				event.preventDefault();
				event.stopPropagation();
				closeMenu( menu, true );
			}
			break;

		case 'Tab':
			closeMenu( menu );
			break;
	}
}

/**
 * Do what a menu item is for.
 *
 * @param {Event}   event  The click.
 * @param {Element} item   The item.
 * @param {Element} flyout The panel.
 * @param {Object}  config The panel's.
 */
function choose( event, item, flyout, config ) {
	// Focus goes back to the button, so it is somewhere when the list it
	// was in has gone.
	closeMenu( item.closest( '.wp-flyout-menu' ), true );

	// Another record's panel, opened where this one is: the item is a
	// trigger, and the handler every trigger has asks about anything typed
	// and then loads it.
	if ( item.classList.contains( 'wp-flyout-trigger' ) ) {
		return;
	}

	// A link is the browser's to follow -- unless following it in this
	// window would throw away what has been typed.
	if ( 'A' === item.nodeName ) {
		const href = item.getAttribute( 'href' );

		if ( ! item.getAttribute( 'target' ) && isDirty( flyout ) ) {
			event.preventDefault();

			confirmDiscard( flyout ).then( ( discard ) => {
				if ( discard ) {
					go( href );
				}
			} );
		}

		return;
	}

	event.preventDefault();

	const question = item.getAttribute( 'data-confirm' );

	if ( ! question ) {
		runMenuAction( flyout, config, item );
		return;
	}

	confirm( flyout, {
		title: question,
		accept: text( item ) || strings().confirm,
		danger: item.classList.contains( 'is-danger' ),
	} ).then( ( yes ) => {
		if ( yes ) {
			runMenuAction( flyout, config, item );
		}
	} );
}

/**
 * Make a panel's header menu work.
 *
 * @param {Element} flyout The panel.
 * @param {Object}  config The panel's.
 */
export function bindMenu( flyout, config ) {
	on( flyout, 'click', '.wp-flyout-menu-toggle', ( event, toggle ) => {
		event.preventDefault();

		if ( toggle.classList.contains( 'is-busy' ) ) {
			return;
		}

		const menu = toggle.closest( '.wp-flyout-menu' );

		if ( 'true' === toggle.getAttribute( 'aria-expanded' ) ) {
			closeMenu( menu );
		} else {
			openMenu( menu );
		}
	} );

	on( flyout, 'keydown', '.wp-flyout-menu', steer );

	on( flyout, 'click', '.wp-flyout-menu-item', ( event, item ) =>
		choose( event, item, flyout, config )
	);
}
