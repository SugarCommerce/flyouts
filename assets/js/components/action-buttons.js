/**
 * The action buttons component: a button that runs an action over REST
 * and says how it went.
 *
 * Fires `actionbuttons:success` on the button with the answer, or
 * `actionbuttons:error` with the message.
 */

import { __ } from '@wordpress/i18n';

import { strings } from '../lib/config';
import { all, on, one, replaceHtml } from '../lib/dom';
import { emit } from '../lib/events';
import { showAlert } from '../lib/feedback';
import { scrollToTop } from '../lib/motion';
import { reload } from '../lib/page';
import { api } from '../lib/request';
import { itemId, panelConfig } from '../lib/store';
import { reloadFlyout } from './reload';

// How long the answer is on screen before what it asked for happens.
const AFTER = 1500;

/**
 * Mark a button busy: core's convention, the spinner beside the label
 * switched on with .is-active, the way Save Changes does it.
 *
 * @param {Element} button  The button.
 * @param {boolean} loading Busy or not.
 */
function busy( button, loading ) {
	button.disabled = loading;
	button.classList.toggle( 'is-busy', loading );
	all( '.spinner', button ).forEach( ( spinner ) =>
		spinner.classList.toggle( 'is-active', loading )
	);
}

/**
 * Say an action failed, in the panel and to whoever listens.
 *
 * @param {Element}      button  The button.
 * @param {Element|null} flyout  The panel.
 * @param {string}       message What went wrong.
 */
function failed( button, flyout, message ) {
	if ( flyout ) {
		showAlert( flyout, message, 'error' );
		scrollToTop( one( '.wp-flyout-body', flyout ) );
	}

	emit( button, 'actionbuttons:error', { detail: message } );
}

/**
 * Show what an action answered.
 *
 * @param {Object}       response The answer.
 * @param {Element}      button   The button.
 * @param {Element|null} flyout   The panel.
 */
export function handleResponse( response, button, flyout ) {
	if ( ! response.success ) {
		failed(
			button,
			flyout,
			response.message || __( 'An error occurred', 'sugarcommerce' )
		);
		return;
	}

	if ( flyout ) {
		showAlert(
			flyout,
			response.message ||
				__( 'Action completed successfully', 'sugarcommerce' ),
			'success'
		);
		scrollToTop( one( '.wp-flyout-body', flyout ) );

		if ( true === response.reload ) {
			setTimeout( reload, AFTER );
		} else if ( true === response.refresh_flyout ) {
			setTimeout( () => reloadFlyout( flyout ), AFTER );
		}
	}

	// Anywhere on the page: the selector is the action's to choose.
	Object.keys( response.updates || {} ).forEach( ( selector ) =>
		all( selector ).forEach( ( element ) =>
			replaceHtml( element, response.updates[ selector ] )
		)
	);

	emit( button, 'actionbuttons:success', { detail: response } );
}

/**
 * Run a button's action, asking first when it has a question.
 *
 * @param {Element} button The button.
 */
export function run( button ) {
	const question = button.getAttribute( 'data-confirm' );

	// eslint-disable-next-line no-alert
	if ( question && ! window.confirm( question ) ) {
		return;
	}

	const action = button.getAttribute( 'data-action' );

	if ( ! action ) {
		// eslint-disable-next-line no-console
		console.error( 'ActionButtons: No action specified' );
		return;
	}

	const flyout = button.closest( '.wp-flyout' );
	const config = panelConfig( flyout );

	busy( button, true );

	api( '/action', {
		manager: config.manager,
		flyout: config.flyout,
		action_key: action,
		item_id: flyout ? itemId( flyout ) : 0,
	} )
		.then(
			( response ) => handleResponse( response, button, flyout ),
			( error ) =>
				failed(
					button,
					flyout,
					error.message || strings().requestFailed
				)
		)
		.finally( () => busy( button, false ) );
}

/**
 * Make every action button work, the ones in panels yet to load included.
 */
export function bindActionButtons() {
	on( document, 'click', '.wp-flyout-action-btn', ( event, button ) => {
		event.preventDefault();
		run( button );
	} );
}
