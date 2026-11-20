/**
 * Saying what went wrong with a save, where it went wrong.
 */

import { strings } from '../lib/config';
import { all, isUsable, make, one } from '../lib/dom';
import { showAlert } from '../lib/feedback';
import { scrollToTop } from '../lib/motion';

/**
 * Show what a refused save said.
 *
 * An error that names a field is shown under that field, with the field
 * brought into view and given focus. A save callback names one by
 * returning a WP_Error whose data has `field`, the field's key, or
 * `fields`, a map of key to message. Anything else -- no field named, or a
 * field that is not in this panel -- is one message at the top.
 *
 * @param {Element} flyout  The panel.
 * @param {string}  message The error's own message.
 * @param {Object}  [data]  The error's data.
 */
export function showFailure( flyout, message, data = {} ) {
	const fields = {};
	const unplaced = [];
	let first = null;

	data = data || {};
	message = message || strings().requestFailed;

	if ( data.fields && 'object' === typeof data.fields ) {
		Object.assign( fields, data.fields );
	}

	if (
		'string' === typeof data.field &&
		'' !== data.field &&
		undefined === fields[ data.field ]
	) {
		fields[ data.field ] = message;
	}

	Object.keys( fields ).forEach( ( key ) => {
		const field = findField( flyout, key );
		const said = String( fields[ key ] || message );

		if ( ! field ) {
			unplaced.push( said );
			return;
		}

		const target = showFieldError( flyout, field, said );

		first = first || target;
	} );

	if ( first ) {
		first.focus();

		if ( first.scrollIntoView ) {
			first.scrollIntoView( { block: 'center' } );
		}
	}

	if ( unplaced.length || ! first ) {
		showAlert(
			flyout,
			unplaced.length ? unplaced.join( ' ' ) : message,
			'error'
		);

		if ( ! first ) {
			scrollToTop( one( '.wp-flyout-body', flyout ) );
		}
	}
}

/**
 * The wrapper of the field a key names, if it is in this panel: found by
 * the name its control submits under -- the key itself, the key as a
 * list, or the key as the start of something nested.
 *
 * @param {Element} flyout The panel.
 * @param {string}  key    The field's key.
 * @return {Element|null} The field.
 */
export function findField( flyout, key ) {
	key = String( key );

	const control = all( 'form [name]', flyout ).find( ( element ) => {
		const name = element.getAttribute( 'name' ) || '';

		return (
			name === key ||
			name === key + '[]' ||
			name.indexOf( key + '[' ) === 0
		);
	} );

	if ( ! control ) {
		return null;
	}

	return control.closest( '.wp-flyout-field' ) || control.parentElement;
}

/**
 * Show one message under one field.
 *
 * @param {Element} flyout The panel.
 * @param {Element} field  The field.
 * @param {string}  said   The message.
 * @return {Element} What to focus: the field's control, or the field.
 */
export function showFieldError( flyout, field, said ) {
	const id =
		flyout.id + '-error-' + all( '.wp-flyout-field-error', flyout ).length;

	clearFieldError( field );
	reveal( flyout, field );

	const control =
		all(
			'input:not([type="hidden"]), select, textarea, button',
			field
		).find( isUsable ) || null;

	// Inside the fields package's own wrapper where there is one, so it sits
	// directly under the control and its note, not under the gap that
	// separates this field from the next.
	const inner = Array.from( field.children ).find( ( child ) =>
		child.classList.contains( 'field-kit__field' )
	);

	( inner || field ).append(
		make( 'p', { class: 'wp-flyout-field-error', role: 'alert', id }, said )
	);

	field.classList.add( 'wp-flyout-has-error', 'has-error' );

	if ( ! control ) {
		field.setAttribute( 'tabindex', '-1' );

		return field;
	}

	control.setAttribute( 'aria-invalid', 'true' );
	control.setAttribute( 'data-flyout-error', id );

	// Added to whatever already describes the control, so the note under a
	// field is still read out with the complaint about it.
	control.setAttribute(
		'aria-describedby',
		(
			( control.getAttribute( 'aria-describedby' ) || '' ) +
			' ' +
			id
		).trim()
	);

	return control;
}

/**
 * Bring a field out from wherever it is put away: its tab, if that is not
 * showing, and any folded section it is inside.
 *
 * @param {Element} flyout The panel.
 * @param {Element} field  The field.
 */
export function reveal( flyout, field ) {
	const page = field.closest( '.wp-flyout-tab-content' );

	if ( page && ! page.classList.contains( 'active' ) ) {
		all( '.wp-flyout-tab', flyout )
			.filter( ( tab ) => 'tab-' + tab.dataset.tab === page.id )
			.forEach( ( tab ) => tab.click() );
	}

	for (
		let parent = field.parentElement;
		parent;
		parent = parent.parentElement
	) {
		if ( 'DETAILS' === parent.nodeName ) {
			parent.open = true;
		}
	}
}

/**
 * Take a field's error away.
 *
 * @param {Element} field The field.
 */
export function clearFieldError( field ) {
	all( '[data-flyout-error]', field ).forEach( ( control ) => {
		const id = control.getAttribute( 'data-flyout-error' );
		const rest = (
			' ' +
			( control.getAttribute( 'aria-describedby' ) || '' ) +
			' '
		)
			.replace( ' ' + id + ' ', ' ' )
			.trim();

		control.removeAttribute( 'aria-invalid' );
		control.removeAttribute( 'data-flyout-error' );

		if ( rest ) {
			control.setAttribute( 'aria-describedby', rest );
		} else {
			control.removeAttribute( 'aria-describedby' );
		}
	} );

	field.classList.remove( 'wp-flyout-has-error', 'has-error' );
	all( '.wp-flyout-field-error', field ).forEach( ( note ) => note.remove() );
}

/**
 * Take every field's error away.
 *
 * @param {Element} flyout The panel.
 */
export function clearFieldErrors( flyout ) {
	all( '.wp-flyout-has-error', flyout ).forEach( clearFieldError );
}
