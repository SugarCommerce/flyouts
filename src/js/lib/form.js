/**
 * What a panel's form would submit, as the object the save route takes.
 *
 * The pairs are jQuery's serializeArray()'s, which are the browser's for a
 * form submission: a control needs a name and must not be disabled;
 * buttons, files and images are not sent; a box or a radio only when it is
 * ticked; each selected option of a select, none from a disabled option or
 * group; line breaks as CRLF.
 */

import { isUsable } from './dom';

const SENT = /^(?:input|select|textarea|keygen)$/i;
const NOT_SENT = /^(?:submit|button|image|reset|file)$/i;

/**
 * A control's value or values, as jQuery's val() read them.
 *
 * @param {Element} control The control.
 * @return {string[]} Its values; none for a select with nothing chosen.
 */
function values( control ) {
	if ( 'SELECT' !== control.nodeName ) {
		return [ String( control.value ).replace( /\r/g, '' ) ];
	}

	return Array.from( control.options )
		.filter(
			( option ) =>
				option.selected &&
				! option.disabled &&
				! (
					'OPTGROUP' === option.parentNode.nodeName &&
					option.parentNode.disabled
				)
		)
		.map( ( option ) => option.value );
}

/**
 * Each name and value the form's controls would post.
 *
 * @param {HTMLFormElement|Element} root The form, or any element holding controls.
 * @return {Array<{name: string, value: string}>} In document order.
 */
export function pairs( root ) {
	const controls = root.elements
		? Array.from( root.elements )
		: Array.from( root.querySelectorAll( 'input, select, textarea' ) );
	const sent = [];

	controls.forEach( ( control ) => {
		if (
			! control.name ||
			control.matches( ':disabled' ) ||
			! SENT.test( control.nodeName ) ||
			NOT_SENT.test( control.type )
		) {
			return;
		}

		if (
			/^(?:checkbox|radio)$/i.test( control.type ) &&
			! control.checked
		) {
			return;
		}

		values( control ).forEach( ( value ) =>
			sent.push( {
				name: control.name,
				value: value.replace( /\r?\n/g, '\r\n' ),
			} )
		);
	} );

	return sent;
}

/**
 * Turn posted pairs into the object the save route takes.
 *
 * `files[0][name]` and `metadata[1][key]` nest; `tags[]` is a list; a
 * plain name posted twice is a list of both -- except a switch, which is a
 * hidden "0" and then a checkbox of the same name so that off is posted as
 * well as on. A browser keeps the last of those; collected as a list they
 * became ["0", "1"], which no toggle reads as on, and saving a panel
 * turned off every switch that was on. So where a ticked box follows a
 * hidden input of its name, the box is the value.
 *
 * @param {Array<{name: string, value: string}>} sent     What pairs() read.
 * @param {string[]}                             switches Names of ticked boxes behind a hidden input.
 * @param {string[]}                             unticked Names of boxes not ticked.
 * @return {Object} The data.
 */
export function toObject( sent, switches = [], unticked = [] ) {
	const data = {};

	sent.forEach( ( { name, value } ) => {
		if ( name.indexOf( '[' ) === -1 ) {
			if ( switches.includes( name ) ) {
				data[ name ] = value;
			} else if ( data[ name ] !== undefined ) {
				if ( ! Array.isArray( data[ name ] ) ) {
					data[ name ] = [ data[ name ] ];
				}

				data[ name ].push( value );
			} else {
				data[ name ] = value;
			}

			return;
		}

		const keys = name.replace( /\]/g, '' ).split( '[' );
		let current = data;

		for ( let i = 0; i < keys.length - 1; i++ ) {
			const key = keys[ i ];

			if ( ! current[ key ] ) {
				const next = keys[ i + 1 ];

				// An empty next key (name[]) or a number is a list; anything
				// else is an object.
				current[ key ] = next === '' || /^\d+$/.test( next ) ? [] : {};
			}

			current = current[ key ];
		}

		const last = keys[ keys.length - 1 ];

		if ( last === '' ) {
			if ( Array.isArray( current ) ) {
				current.push( value );
			}
		} else {
			current[ last ] = value;
		}
	} );

	// A box that is not ticked posts nothing; the save is told "0".
	unticked.forEach( ( name ) => {
		if ( name && data[ name ] === undefined ) {
			data[ name ] = '0';
		}
	} );

	return data;
}

/**
 * What a form would submit, as the save route's object.
 *
 * @param {HTMLFormElement} form The form.
 * @return {Object} The data.
 */
export function collect( form ) {
	const boxes = Array.from(
		form.querySelectorAll( 'input[type="checkbox"]' )
	);
	const hidden = Array.from(
		form.querySelectorAll( 'input[type="hidden"]' )
	).map( ( input ) => input.getAttribute( 'name' ) );

	const switches = boxes
		.filter( ( box ) => box.checked )
		.map( ( box ) => box.getAttribute( 'name' ) )
		.filter(
			( name ) =>
				name && name.indexOf( '[' ) === -1 && hidden.includes( name )
		);

	const unticked = boxes
		.filter( ( box ) => ! box.checked )
		.map( ( box ) => box.getAttribute( 'name' ) );

	return toObject( pairs( form ), switches, unticked );
}

/**
 * The required controls a form has left empty.
 *
 * Only those that can be seen and used: a field on a hidden branch of a
 * condition is not asked for. Empty is no value, or a list with nothing
 * in it; "0" is a value.
 *
 * @param {Element} form The form.
 * @return {Element[]} The empty ones, in order.
 */
export function missing( form ) {
	return Array.from( form.querySelectorAll( '[required]' ) )
		.filter( isUsable )
		.filter( ( control ) => {
			const value =
				'SELECT' === control.nodeName && control.multiple
					? values( control )
					: control.value;

			return ! value || ( Array.isArray( value ) && ! value.length );
		} );
}
