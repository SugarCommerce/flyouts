/**
 * The few movements the panels make: a section sliding open and shut, a
 * row fading out, a body scrolling back to its top.
 *
 * jQuery's slideDown() and slideUp() grew and shrank the height, padding
 * and margins with the overflow hidden, and left the element shown or
 * `display: none`; these do the same with the Web Animations API. A second
 * movement of the same element stops the first where it is, as jQuery's
 * queue would have run it after. Where the browser cannot animate (a test's
 * page) the element simply ends where it would have ended.
 */

import { hide, isShown, show } from './dom';

const running = new WeakMap();

const BOX = [
	'height',
	'paddingTop',
	'paddingBottom',
	'marginTop',
	'marginBottom',
];

/**
 * The box as it is drawn now, for the open end of a slide.
 *
 * @param {Element} element The element.
 * @return {Object} Property => pixels.
 */
function measure( element ) {
	const style = window.getComputedStyle( element );
	const box = {};

	BOX.forEach( ( name ) => {
		box[ name ] =
			'height' === name
				? element.getBoundingClientRect().height + 'px'
				: style[ name ];
	} );

	return box;
}

/**
 * The box shut.
 *
 * @return {Object} Property => nothing.
 */
function shut() {
	const box = {};

	BOX.forEach( ( name ) => {
		box[ name ] = '0px';
	} );

	return box;
}

/**
 * Stop whatever an element is doing, where it is.
 *
 * @param {Element} element The element.
 * @return {boolean} Whether it was moving.
 */
function stop( element ) {
	const before = running.get( element );

	if ( before ) {
		before.cancel();
		running.delete( element );
	}

	return !! before;
}

/**
 * Run one movement on an element.
 *
 * @param {Element}  element  The element.
 * @param {Object[]} frames   Keyframes.
 * @param {number}   duration Milliseconds.
 * @param {Function} [after]  What to do at the end, before resolving.
 * @return {Promise<void>} Settles when it has finished, or been stopped.
 */
function move( element, frames, duration, after ) {
	return new Promise( ( resolve ) => {
		const finish = () => {
			running.delete( element );
			element.style.overflow = '';

			if ( after ) {
				after();
			}

			resolve();
		};

		if ( 'function' !== typeof element.animate || ! duration ) {
			finish();
			return;
		}

		element.style.overflow = 'hidden';

		const animation = element.animate( frames, {
			duration,
			easing: 'ease-in-out',
		} );

		running.set( element, animation );
		animation.onfinish = finish;

		// Stopped by the next movement: that one takes over from here.
		animation.oncancel = () => {
			element.style.overflow = '';
			resolve();
		};
	} );
}

/**
 * Slide an element open: jQuery's slideDown().
 *
 * @param {Element|null} element  The element.
 * @param {number}       duration Milliseconds.
 * @return {Promise<void>} Settles when it is open.
 */
export function slideDown( element, duration = 400 ) {
	if ( ! element ) {
		return Promise.resolve();
	}

	// Already open, and not on its way shut: nothing to do.
	if ( isShown( element ) && ! running.has( element ) ) {
		return Promise.resolve();
	}

	// From wherever a slide shut had got to.
	const from = running.has( element ) ? measure( element ) : shut();

	stop( element );
	show( element );

	return move( element, [ from, measure( element ) ], duration );
}

/**
 * Slide an element shut and hide it: jQuery's slideUp().
 *
 * @param {Element|null} element  The element.
 * @param {number}       duration Milliseconds.
 * @return {Promise<void>} Settles when it is hidden.
 */
export function slideUp( element, duration = 400 ) {
	if ( ! element ) {
		return Promise.resolve();
	}

	if ( ! isShown( element ) && ! running.has( element ) ) {
		hide( element );

		return Promise.resolve();
	}

	const from = measure( element );

	stop( element );

	return move( element, [ from, shut() ], duration, () => hide( element ) );
}

/**
 * Fade an element out and hide it: jQuery's fadeOut().
 *
 * @param {Element} element  The element.
 * @param {number}  duration Milliseconds.
 * @return {Promise<void>} Settles when it is hidden.
 */
export function fadeOut( element, duration = 400 ) {
	const from = window.getComputedStyle( element ).opacity;

	stop( element );

	return move( element, [ { opacity: from }, { opacity: 0 } ], duration, () =>
		hide( element )
	);
}

/**
 * Scroll an element back to its top, smoothly: what
 * `$body.animate( { scrollTop: 0 }, 300 )` did.
 *
 * @param {Element|null} element The element.
 */
export function scrollToTop( element ) {
	if ( ! element ) {
		return;
	}

	if ( 'function' === typeof element.scrollTo ) {
		element.scrollTo( { top: 0, behavior: 'smooth' } );
	} else {
		element.scrollTop = 0;
	}
}
