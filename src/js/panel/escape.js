/**
 * Whether an Escape belongs to something inside the panel rather than to
 * the panel.
 *
 * A dropdown that is open closes on Escape, and so did the panel under it,
 * in the same keypress: the widget's own handler runs first, and by the
 * time the key reaches the document there is nothing left to say it had
 * been open. So the question is asked on the way down, before anything has
 * handled it.
 */

let taken = false;

/**
 * Whether the Escape now on its way was meant for something in the panel.
 *
 * @param {Document} root Where the key was pressed.
 * @return {boolean} Taken.
 */
function ownedBelow( root ) {
	return (
		// A dialog over this one: the media library, a tag picker.
		Array.from(
			root.querySelectorAll( '[role="dialog"]:not(.wp-flyout)' )
		).some(
			( dialog ) => ! dialog.hidden && dialog.getClientRects().length > 0
		)
	);
}

/**
 * Start listening, on the way down, for the Escape key.
 */
export function watchEscape() {
	document.addEventListener(
		'keydown',
		( event ) => {
			if ( 'Escape' !== event.key ) {
				return;
			}

			const target =
				event.target && event.target.closest ? event.target : null;

			taken = !! (
				// An open combobox or menu the key was typed in. Not anything
				// that is merely expanded: an open accordion row is, and
				// Escape on one of those is still meant for the panel.
				( target &&
					target.closest(
						'[role="combobox"][aria-expanded="true"], [aria-haspopup][aria-expanded="true"], [role="menu"], [role="listbox"]'
					) ) ||
				ownedBelow( document )
			);
		},
		true
	);
}

/**
 * Whether the last Escape belonged to something in the panel.
 *
 * @return {boolean} Taken.
 */
export const escapeWasTaken = () => taken;
