/**
 * Saying that something happened, to listeners of either kind.
 *
 * The panels' events were jQuery's, and code outside this library listens
 * for them the jQuery way, with the payload as extra arguments: SugarCart's
 * collections screen takes `( event, response, action )` from
 * `wpflyout:row-action`, the fields package's guard `( event, dirty )` from
 * `wpflyout:dirty`. Those listeners keep working as they are.
 *
 * So each event is a native, bubbling CustomEvent whose `detail` is the
 * payload -- cancelable where the jQuery one was cancelled -- and, when
 * jQuery is on the page, the same event is delivered to jQuery's listeners
 * with the old arguments. Once each: jQuery listens through
 * addEventListener, so the native dispatch would reach its listeners too,
 * without the arguments. jQuery's own guard against that,
 * `jQuery.event.triggered`, is set while the native event is out, which is
 * what jQuery does itself when it calls a native method like click().
 *
 * The one module that reaches for jQuery, and only if the page has it.
 */

/**
 * Fire an event on an element, for native and jQuery listeners alike.
 *
 * @param {EventTarget} target               The element, or the document.
 * @param {string}      type                 The event's name.
 * @param {Object}      [options]
 * @param {*}           [options.detail]     What a native listener reads as `event.detail`.
 * @param {Array}       [options.args]       What a jQuery listener is handed after the event;
 *                                           `[ detail ]` when there is a detail, nothing when not.
 * @param {boolean}     [options.cancelable] Whether a listener may say no.
 * @return {boolean} False when a listener of either kind called preventDefault().
 */
export function emit( target, type, options = {} ) {
	const { detail = null, cancelable = false } = options;
	const args = options.args || ( null === detail ? [] : [ detail ] );
	const jQuery = window.jQuery;
	let refused = false;

	if ( jQuery && jQuery.Event && jQuery.event ) {
		const event = jQuery.Event( type );

		jQuery( target ).trigger( event, args );
		refused = cancelable && event.isDefaultPrevented();
	}

	const event = new window.CustomEvent( type, {
		bubbles: true,
		cancelable,
		detail,
	} );

	// Said no to already: a native listener sees it said.
	if ( refused ) {
		event.preventDefault();
	}

	const triggered = jQuery && jQuery.event ? jQuery.event.triggered : null;

	if ( jQuery && jQuery.event ) {
		jQuery.event.triggered = type;
	}

	try {
		target.dispatchEvent( event );
	} finally {
		if ( jQuery && jQuery.event ) {
			jQuery.event.triggered = triggered;
		}
	}

	return ! refused && ! event.defaultPrevented;
}
