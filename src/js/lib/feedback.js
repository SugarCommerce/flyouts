/**
 * What a panel says back: a toast, an alert at the top of its body, and a
 * button that is busy while a request is out.
 *
 * Shared by the core script and the components', which is why the timers
 * are kept on the elements (lib/store.js) and not in this module: each
 * script has its own copy of it.
 */

import { show } from './alert';
import { icon } from './config';
import { make } from './dom';
import { stash } from './store';

// How long a toast stays.
export const TOAST_FOR = 2500;

/**
 * The panel's quiet status region, made when it has none.
 *
 * A status region has to be in the document before anything is put in it,
 * or a screen reader that was not listening when it arrived says nothing.
 * So it is there from the start, empty.
 *
 * @param {Element} flyout The panel.
 * @return {Element} The region.
 */
export function toastRegion( flyout ) {
	let region = Array.from( flyout.children ).find( ( child ) =>
		child.classList.contains( 'wp-flyout-toast' )
	);

	if ( ! region ) {
		region = make( 'div', {
			class: 'wp-flyout-toast',
			role: 'status',
			'aria-live': 'polite',
		} );
		flyout.append( region );
	}

	return region;
}

/**
 * Say something briefly, and take it away again.
 *
 * @param {Element} flyout  The panel.
 * @param {*}       message What to say.
 */
export function toast( flyout, message ) {
	const region = toastRegion( flyout );
	const kept = stash( region );

	clearTimeout( kept.timer );

	region.innerHTML = icon( 'copied', 'yes' );
	region.append(
		make(
			'span',
			{},
			message === null || message === undefined ? '' : String( message )
		)
	);
	region.classList.add( 'is-visible' );

	kept.timer = setTimeout( () => {
		region.classList.remove( 'is-visible' );
		region.replaceChildren();
	}, TOAST_FOR );
}

/**
 * Show an alert at the top of a panel's body, in place of any there was.
 *
 * A success goes by itself after three seconds; anything else stays until
 * it is dismissed.
 *
 * @param {Element} flyout  The panel.
 * @param {string}  message What to say.
 * @param {string}  type    success, info, warning or error.
 * @return {Element} The alert.
 */
export function showAlert( flyout, message, type ) {
	flyout
		.querySelectorAll( '.wp-flyout-alert' )
		.forEach( ( alert ) => alert.remove() );

	return show( message, type, {
		target: flyout.querySelector( '.wp-flyout-body' ),
		prepend: true,
		timeout: 'success' === type ? 3000 : 0,
		dismissible: true,
	} );
}

/**
 * Mark a button busy, or not.
 *
 * Busy is drawn by the stylesheet, as a spinner over a label that keeps
 * its place: a button that changes its words changes its width, and the
 * buttons beside it move under the pointer.
 *
 * And it is disabled with aria-disabled rather than the attribute. A
 * button given `disabled` while it has focus drops the focus on the floor
 * -- the page's <body> has it, and the next Tab starts somewhere else --
 * and core paints a disabled primary button grey, which reads as the press
 * having been refused. This way it keeps its place, its colour and the
 * keyboard, says it is unavailable to anything that asks, and ignores
 * being pressed.
 *
 * @param {Element|Element[]|null} button The button, or several.
 * @param {boolean}                busy   Busy or not.
 * @param {string}                 [text] Words to show while busy.
 */
export function setButtonState( button, busy, text ) {
	if ( ! button ) {
		return;
	}

	if ( ! ( button instanceof window.Element ) ) {
		Array.from( button ).forEach( ( one ) =>
			setButtonState( one, busy, text )
		);

		return;
	}

	const kept = stash( button );

	if ( busy ) {
		button.classList.add( 'is-busy' );
		button.setAttribute( 'aria-busy', 'true' );
		button.setAttribute( 'aria-disabled', 'true' );

		if ( text ) {
			kept.label = button.textContent;
			button.textContent = text;
		}

		return;
	}

	button.classList.remove( 'is-busy' );
	button.removeAttribute( 'aria-busy' );
	button.removeAttribute( 'aria-disabled' );

	if ( kept.label ) {
		button.textContent = kept.label;
		delete kept.label;
	}
}
