/**
 * What the manager listens for on every screen: triggers, the question
 * before a panel closes, Cmd or Ctrl and S, J and K, a click away from an
 * open menu, and a trigger that opens itself.
 */

import { all, on, one } from '../lib/dom';
import panel from '../panel/panel';
import { confirmDiscard } from './confirm';
import { isDirty, refresh } from './dirty';
import { current, extractConfig, request, teardown } from './load';
import { closeMenu } from './menu';
import { stepKey } from './steps';

/**
 * The one place a panel is asked whether it may close: the X, Escape and
 * the overlay all end up at panel.close(), which asks this before it does
 * anything.
 *
 * @param {CustomEvent} event `wpflyout:closing`.
 */
function guardClosing( event ) {
	const detail = event.detail || {};
	const flyout = detail.element;

	if ( ! flyout ) {
		return;
	}

	if ( ! detail.force && isDirty( flyout ) ) {
		event.preventDefault();

		confirmDiscard( flyout ).then( ( discard ) => {
			if ( discard ) {
				panel.close( detail.id, { force: true } );
			}
		} );

		return;
	}

	teardown( flyout );
}

/**
 * Cmd or Ctrl and S saves, because that is what the hand does after
 * changing something whether or not anyone said it could. The browser's
 * own Save Page dialog is never what was meant while a panel is open, so
 * the key is taken either way.
 *
 * @param {KeyboardEvent} event The key.
 */
function saveKey( event ) {
	if (
		! ( event.metaKey || event.ctrlKey ) ||
		event.altKey ||
		's' !== String( event.key ).toLowerCase()
	) {
		return;
	}

	const flyout = current();

	if ( ! flyout ) {
		return;
	}

	event.preventDefault();

	// Asked now rather than read off the button: the button catches up a
	// moment after a keystroke, and this is the keystroke after it.
	refresh( flyout );

	const save = all( '.wp-flyout-save', flyout ).find(
		( button ) => ! button.disabled
	);

	if ( save ) {
		save.click();
	}
}

/**
 * Start listening.
 */
export function init() {
	on( document, 'click', '.wp-flyout-trigger', ( event, trigger ) => {
		event.preventDefault();
		request( extractConfig( trigger ) );
	} );

	document.addEventListener( 'wpflyout:closing', guardClosing );
	document.addEventListener( 'keydown', saveKey );
	document.addEventListener( 'keydown', stepKey );

	// A click anywhere else puts the header menu away.
	document.addEventListener( 'click', ( event ) => {
		const inside =
			event.target instanceof window.Element &&
			event.target.closest( '.wp-flyout-menu' );

		if ( ! inside ) {
			all( '.wp-flyout-menu' ).forEach( ( menu ) => closeMenu( menu ) );
		}
	} );

	// A trigger marked data-flyout-auto opens itself once the page is
	// ready: how a panel opens after a round trip through the server -- a
	// bulk action that redirects back with a selection, where there is
	// nothing for the reader to click that they have not already clicked.
	// Only the first, and only once: two panels opening over each other is
	// not a thing anybody asked for.
	const auto = one( '.wp-flyout-trigger[data-flyout-auto]' );

	if ( auto ) {
		auto.click();
	}
}
