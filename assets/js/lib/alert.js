/**
 * Alerts in a panel: one shown by a script, and the dismiss button on
 * every one, whether a script drew it or the server did.
 *
 * Fires `alert:shown` and `alert:dismissed` on the alert, and before it
 * goes the cancelable `alert:beforedismiss`.
 */

import { icon } from './config';
import { make, on } from './dom';
import { emit } from './events';
import { slideUp } from './motion';

// Each kind's Dashicon, on a page that published none of the icons.
const KINDS = {
	success: 'yes-alt',
	info: 'info',
	warning: 'warning',
	error: 'dismiss',
};

/**
 * Take an alert away, unless a listener says not to.
 *
 * @param {Element} alert The alert.
 */
export function dismiss( alert ) {
	if ( ! emit( alert, 'alert:beforedismiss', { cancelable: true } ) ) {
		return;
	}

	alert.classList.add( 'is-dismissing' );

	setTimeout( () => {
		slideUp( alert, 200 ).then( () => {
			emit( alert, 'alert:dismissed' );
			alert.remove();
		} );
	}, 300 );
}

/**
 * Show an alert.
 *
 * The message goes in as text, not markup: it is whatever the server sent
 * back, and an error that quotes what somebody typed must not run it.
 *
 * @param {string}  message               What to say.
 * @param {string}  [type]                success, info, warning or error.
 * @param {Object}  [options]
 * @param {Element} [options.target]      Where; the first panel body otherwise.
 * @param {boolean} [options.prepend]     At the top of it.
 * @param {number}  [options.timeout]     Milliseconds before it goes by itself.
 * @param {boolean} [options.dismissible] Whether it has a close button (it does).
 * @param {string}  [options.icon]        A Dashicons name in place of the kind's mark.
 * @return {Element} The alert.
 */
export function show( message, type = 'info', options = {} ) {
	const kind = KINDS[ type ] ? type : 'info';
	const dismissible = options.dismissible !== false;
	const glyph = options.icon
		? icon( '', String( options.icon ).replace( /^dashicons-/, '' ) )
		: icon( kind, KINDS[ kind ] );

	const alert = make( 'div', {
		class: [
			'wp-flyout-alert',
			'alert-' + String( type || 'info' ).replace( /[^a-z0-9_-]/gi, '' ),
			dismissible ? 'is-dismissible' : '',
		]
			.filter( Boolean )
			.join( ' ' ),
		role: 'alert',
	} );

	const wrapper = make( 'div', { class: 'alert-content-wrapper' } );
	const mark = make( 'div', { class: 'alert-icon' } );
	const content = make( 'div', { class: 'alert-content' } );

	mark.innerHTML = glyph;
	content.append(
		make(
			'div',
			{ class: 'alert-message' },
			message === null || message === undefined ? '' : String( message )
		)
	);
	wrapper.append( mark, content );

	if ( dismissible ) {
		const close = make( 'button', {
			type: 'button',
			class: 'alert-dismiss',
			'data-action': 'dismiss-alert',
		} );

		close.innerHTML = icon( 'close', 'no-alt' );
		wrapper.append( close );
	}

	alert.append( wrapper );

	const target =
		( 'string' === typeof options.target
			? document.querySelector( options.target )
			: options.target ) || document.querySelector( '.wp-flyout-body' );

	if ( target ) {
		if ( options.prepend ) {
			target.prepend( alert );
		} else {
			target.append( alert );
		}
	}

	emit( alert, 'alert:shown' );

	if ( options.timeout ) {
		setTimeout( () => dismiss( alert ), options.timeout );
	}

	return alert;
}

/**
 * Make every alert's close button work, the server's included.
 */
export function bindAlerts() {
	on(
		document,
		'click',
		'.wp-flyout-alert [data-action="dismiss-alert"]',
		( event, button ) => {
			event.preventDefault();
			dismiss( button.closest( '.wp-flyout-alert' ) );
		}
	);
}
