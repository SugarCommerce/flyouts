/**
 * Enough layout for jsdom to say what is shown.
 *
 * jsdom draws nothing, so every element has no box and nothing is ever
 * `:visible`. Here an element has a box unless it, or anything it is in, is
 * `display: none` or `hidden`, or is out of the document -- which is all
 * the scripts ask of the page.
 */

/**
 * Whether an element would be drawn.
 *
 * @param {Element} element The element.
 * @return {boolean} Drawn.
 */
function drawn( element ) {
	if ( ! element.isConnected ) {
		return false;
	}

	for ( let at = element; at && at.nodeType === 1; at = at.parentElement ) {
		if ( at.hidden || 'none' === window.getComputedStyle( at ).display ) {
			return false;
		}
	}

	return true;
}

/**
 * Give every element the box it would have.
 */
export function layout() {
	window.Element.prototype.getClientRects = function () {
		return drawn( this ) ? [ { width: 10, height: 10 } ] : [];
	};
}
