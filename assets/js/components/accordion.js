/**
 * The accordion component: sections that fold open and shut.
 *
 * Fires `accordion:toggled` on the section, `{ isOpen }`, as it is pressed,
 * then `accordion:opened` or `accordion:closed` once it has finished
 * moving.
 */

import { all, on } from '../lib/dom';
import { emit } from '../lib/events';
import { slideDown, slideUp } from '../lib/motion';

/**
 * Open a section.
 *
 * @param {Element} section The section.
 */
export function openSection( section ) {
	const header = section.querySelector( '.accordion-header' );

	section.classList.add( 'is-open' );
	slideDown( section.querySelector( '.accordion-content' ), 300 ).then( () =>
		emit( section, 'accordion:opened' )
	);

	if ( header ) {
		header.setAttribute( 'aria-expanded', 'true' );
	}
}

/**
 * Close a section.
 *
 * @param {Element} section The section.
 */
export function closeSection( section ) {
	const header = section.querySelector( '.accordion-header' );

	section.classList.remove( 'is-open' );
	slideUp( section.querySelector( '.accordion-content' ), 300 ).then( () =>
		emit( section, 'accordion:closed' )
	);

	if ( header ) {
		header.setAttribute( 'aria-expanded', 'false' );
	}
}

/**
 * Open or close the section a header belongs to, closing the others
 * unless the accordion allows several open at once.
 *
 * @param {Element} header The pressed header.
 */
export function toggle( header ) {
	const accordion = header.closest( '.wp-flyout-accordion' );
	const section = header.closest( '.accordion-section' );

	if ( ! accordion || ! section ) {
		return;
	}

	const isOpen = section.classList.contains( 'is-open' );

	if (
		! isOpen &&
		'true' !== accordion.getAttribute( 'data-allow-multiple' )
	) {
		all( '.accordion-section.is-open', accordion )
			.filter( ( other ) => other !== section )
			.forEach( closeSection );
	}

	if ( isOpen ) {
		closeSection( section );
	} else {
		openSection( section );
	}

	emit( section, 'accordion:toggled', { detail: { isOpen: ! isOpen } } );
}

/**
 * Make every accordion work, the ones in panels yet to load included.
 */
export function bindAccordions() {
	on(
		document,
		'click',
		'.wp-flyout-accordion .accordion-header',
		( event, header ) => {
			event.preventDefault();
			toggle( header );
		}
	);
}
