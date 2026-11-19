/**
 * The refund form: a panel that opens under its button, the amount and a
 * reason, a confirm button that says the amount, and the refund sent over
 * REST in the currency's minor units.
 */

import { __ } from '@wordpress/i18n';

import { strings } from '../lib/config';
import { all, hide, isShown, on, one, show } from '../lib/dom';
import { showAlert } from '../lib/feedback';
import { scrollToTop, slideDown, slideUp } from '../lib/motion';
import { reload } from '../lib/page';
import { api } from '../lib/request';
import { itemId, panelConfig } from '../lib/store';
import { reloadFlyout } from './reload';

// How long the answer is on screen before what it asked for happens.
const AFTER = 1500;

/**
 * How many minor units make one major unit of the form's currency.
 *
 * Printed by PHP from the currency's own ISO 4217 exponent, so this is 100
 * for dollars, 1 for yen and 1000 for Kuwaiti dinar.
 *
 * @param {Element} form The `.wp-flyout-refund-form`.
 * @return {number} The factor.
 */
export function factor( form ) {
	return Math.pow( 10, parseInt( form.dataset.decimals, 10 ) || 0 );
}

/**
 * A major-unit amount, said in the page's locale.
 *
 * @param {Element} form   The form.
 * @param {number}  amount The amount.
 * @return {string} The money.
 */
export function money( form, amount ) {
	const currency = form.dataset.currency || 'USD';

	try {
		return new Intl.NumberFormat( form.dataset.locale || undefined, {
			style: 'currency',
			currency,
		} ).format( amount );
	} catch {
		// An unrecognised currency code throws rather than degrading.
		return (
			currency +
			' ' +
			amount.toFixed( parseInt( form.dataset.decimals, 10 ) || 0 )
		);
	}
}

/**
 * What the form would send, or what is wrong with it.
 *
 * The input is in major units and the route takes minor ones. The scale
 * comes from the currency rather than being a hard 100: yen has no minor
 * unit, so multiplying by a hundred there asked for a refund a hundred
 * times the size of the payment.
 *
 * @param {Element} form The form.
 * @return {{error: string}|{amount: number, currency: string, reason: string, custom_reason: string}} The refund.
 */
export function refund( form ) {
	const input = one( '.refund-amount-input', form );
	const amount = Math.round(
		( parseFloat( input ? input.value : '' ) || 0 ) * factor( form )
	);
	const refundable = parseInt( form.dataset.refundable, 10 ) || 0;

	if ( amount <= 0 ) {
		return { error: form.dataset.errorEmpty };
	}

	if ( amount > refundable ) {
		return { error: form.dataset.errorExceeds };
	}

	const select = one( '.refund-reason-select', form );
	const custom = one( '.refund-custom-input', form );
	let reason = select ? select.value : '';
	let other = '';

	if ( 'other' === reason ) {
		other = ( custom && custom.value ) || '';
		reason = '';
	}

	return {
		amount,
		currency: form.dataset.currency || 'USD',
		reason,
		custom_reason: other,
	};
}

/**
 * Busy, or not: the label hidden behind a spinner.
 *
 * @param {Element} button  The submit button.
 * @param {boolean} loading Busy or not.
 */
function setLoading( button, loading ) {
	button.disabled = loading;
	button.classList.toggle( 'loading', loading );

	all( '.button-text', button ).forEach( loading ? hide : show );
	all( '.button-spinner', button ).forEach( loading ? show : hide );
}

/**
 * Show what the refund answered.
 *
 * @param {Object}  response The answer.
 * @param {Element} form     The form.
 * @param {Element} flyout   The panel.
 */
export function handleResponse( response, form, flyout ) {
	const body = one( '.wp-flyout-body', flyout );

	if ( ! response.success ) {
		showAlert(
			flyout,
			response.message || __( 'Refund failed.', 'sugarcommerce' ),
			'error'
		);
		scrollToTop( body );
		return;
	}

	showAlert(
		flyout,
		response.message ||
			__( 'Refund processed successfully.', 'sugarcommerce' ),
		'success'
	);
	scrollToTop( body );
	slideUp( one( '.refund-panel', form ), 150 );

	if ( true === response.reload ) {
		setTimeout( reload, AFTER );
	} else if ( true === response.refresh_flyout ) {
		setTimeout( () => reloadFlyout( flyout ), AFTER );
	}
}

/**
 * Send the refund.
 *
 * @param {Element} button The submit button.
 */
export function submit( button ) {
	const form = button.closest( '.wp-flyout-refund-form' );
	const flyout = form ? form.closest( '.wp-flyout' ) : null;

	if ( ! flyout ) {
		return;
	}

	const asked = refund( form );

	if ( asked.error !== undefined ) {
		showAlert( flyout, asked.error, 'error' );
		return;
	}

	const config = panelConfig( flyout );

	setLoading( button, true );

	api( '/action', {
		manager: config.manager,
		flyout: config.flyout,
		action_key: form.dataset.action,
		item_id: itemId( flyout ),
		...asked,
	} )
		.then(
			( response ) => handleResponse( response, form, flyout ),
			( error ) =>
				showAlert(
					flyout,
					error.message || strings().requestFailed,
					'error'
				)
		)
		.finally( () => setLoading( button, false ) );
}

/**
 * Make every refund form work, the ones in panels yet to load included.
 */
export function bindRefundForms() {
	const form = ( element ) => element.closest( '.wp-flyout-refund-form' );

	on( document, 'click', '.refund-trigger', ( event, trigger ) => {
		const panel = one( '.refund-panel', form( trigger ) );

		if ( ! panel ) {
			return;
		}

		if ( isShown( panel ) ) {
			slideUp( panel, 150 );
			return;
		}

		slideDown( panel, 150 );

		const amount = one( '.refund-amount-input', panel );

		if ( amount ) {
			amount.focus();
		}
	} );

	on( document, 'click', '.refund-cancel', ( event, cancel ) =>
		slideUp( one( '.refund-panel', form( cancel ) ), 150 )
	);

	on( document, 'click', '.refund-submit', ( event, button ) => {
		event.preventDefault();
		submit( button );
	} );

	// The confirm button says how much.
	on( document, 'input', '.refund-amount-input', ( event, input ) => {
		const refundForm = form( input );
		const button = one( '.refund-submit', refundForm );
		const words = button ? one( '.button-text', button ) : null;

		if ( words ) {
			words.textContent = String(
				button.getAttribute( 'data-template' ) || ''
			).replace( '%s', () =>
				money( refundForm, parseFloat( input.value ) || 0 )
			);
		}
	} );

	// Other asks for a reason of its own.
	on( document, 'change', '.refund-reason-select', ( event, select ) => {
		const custom = one( '.refund-custom-reason', form( select ) );

		if ( 'other' !== select.value ) {
			slideUp( custom, 150 );
			return;
		}

		slideDown( custom, 150 );

		const box = custom ? one( 'input', custom ) : null;

		if ( box ) {
			box.focus();
		}
	} );
}
