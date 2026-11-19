/**
 * Copying text for the copy button beside a panel's title.
 */

/**
 * Copy text, and say whether it was.
 *
 * The asynchronous clipboard needs a secure context, which an admin on
 * plain http is not, so there is the older way behind it.
 *
 * @param {string} text What to copy.
 * @return {Promise<boolean>} Whether it was copied.
 */
export function copy( text ) {
	if ( window.navigator.clipboard && window.isSecureContext ) {
		return window.navigator.clipboard.writeText( text ).then(
			() => true,
			() => false
		);
	}

	const area = document.createElement( 'textarea' );
	let ok = false;

	area.value = text;
	area.setAttribute( 'readonly', '' );
	area.style.position = 'fixed';
	area.style.opacity = '0';
	document.body.append( area );
	area.select();

	try {
		ok = document.execCommand( 'copy' );
	} catch {
		ok = false;
	}

	area.remove();

	return Promise.resolve( ok );
}
