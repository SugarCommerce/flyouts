/**
 * A panel's actions: a header menu item's, a row's, and the form either
 * may ask for first.
 *
 * Each fires an event on the panel once it has run, for code that has to
 * redraw something of its own: `wpflyout:menu-action`,
 * `wpflyout:row-action` and `wpflyout:prompt-action`. A jQuery listener is
 * handed `( event, response, action )`; a native one reads
 * `event.detail.response` and `event.detail.action`.
 */

import { strings } from '../lib/config';
import { all, replaceHtml } from '../lib/dom';
import { emit } from '../lib/events';
import { setButtonState, showAlert, toast } from '../lib/feedback';
import { reload } from '../lib/page';
import { api } from '../lib/request';
import { itemId } from '../lib/store';
import { open as openPrompt } from '../prompt/prompt';
import { isDirty, markClean } from './dirty';
import { request } from './load';

// How long a panel stays as it is under the toast before it is drawn again.
export const CLOSE_AFTER = 900;

/**
 * Say an action has run.
 *
 * @param {Element} flyout   The panel.
 * @param {string}  type     The event.
 * @param {Object}  response The action's answer.
 * @param {string}  action   The action's key.
 */
function ran( flyout, type, response, action ) {
	emit( flyout, type, {
		detail: { response, action },
		args: [ response, action ],
	} );
}

/**
 * Send an action, and answer it the way every action is answered.
 *
 * @param {Element} flyout The panel.
 * @param {Object}  config The panel's.
 * @param {Object}  body   What to send.
 * @param {Element} busy   The button that is busy while it is out.
 * @param {string}  type   The event to fire once it has run.
 */
function send( flyout, config, body, busy, type ) {
	setButtonState( busy, true );

	api( '/action', body )
		.then( ( response ) => {
			setButtonState( busy, false );

			if ( ! response.success ) {
				showAlert(
					flyout,
					response.message || strings().requestFailed,
					'error'
				);
				return;
			}

			if ( askFor( flyout, config, response, body, busy ) ) {
				return;
			}

			afterAction( flyout, config, response );
			ran( flyout, type, response, body.action_key );
		} )
		.catch( ( error ) => {
			setButtonState( busy, false );
			showAlert(
				flyout,
				error.message || strings().requestFailed,
				'error'
			);
		} );
}

/**
 * Run a header menu item's action.
 *
 * Through the same route a component's button uses, and answered the same
 * way: a message, and `reload` or `refresh_flyout` when what the action
 * did has to be seen.
 *
 * @param {Element} flyout The panel.
 * @param {Object}  config The panel's.
 * @param {Element} item   The menu item.
 */
export function runMenuAction( flyout, config, item ) {
	send(
		flyout,
		config,
		{
			manager: config.manager,
			flyout: config.flyout,
			action_key: item.getAttribute( 'data-action' ),
			item_id: itemId( flyout ),
		},
		flyout.querySelector( '.wp-flyout-menu-toggle' ),
		'wpflyout:menu-action'
	);
}

/**
 * The request a row action's button makes: whatever else the button
 * carries as `data-*` goes with it, which is how the callback knows which
 * row -- `data-activation-id="12"` arrives as `activation_id`. The
 * panel's own keys go last, so none of the button's can be one of them.
 *
 * @param {Element} flyout The panel.
 * @param {Object}  config The panel's.
 * @param {Element} button The button.
 * @return {Object} The body.
 */
export function rowActionBody( flyout, config, button ) {
	const body = {};

	Array.from( button.attributes ).forEach( ( attribute ) => {
		if (
			0 !== attribute.name.indexOf( 'data-' ) ||
			'data-action' === attribute.name ||
			'data-confirm' === attribute.name
		) {
			return;
		}

		body[ attribute.name.slice( 5 ).replace( /-/g, '_' ) ] =
			attribute.value;
	} );

	body.manager = config.manager;
	body.flyout = config.flyout;
	body.action_key = button.getAttribute( 'data-action' );
	body.item_id = itemId( flyout );

	return body;
}

/**
 * Run an action that belongs to one row of the panel.
 *
 * A button with the class `wp-flyout-row-action` and a `data-action`,
 * wherever a consumer's markup put it -- a cell of a data table, most
 * often. Answered like a menu action.
 *
 * @param {Element} flyout The panel.
 * @param {Object}  config The panel's.
 * @param {Element} button The button.
 */
export function runRowAction( flyout, config, button ) {
	if ( ! button.getAttribute( 'data-action' ) ) {
		return;
	}

	send(
		flyout,
		config,
		rowActionBody( flyout, config, button ),
		button,
		'wpflyout:row-action'
	);
}

/**
 * Open the form an action asked for, if it asked for one.
 *
 * An action that needs something written before it can run -- a subject
 * and a message, a reason -- answers its first request with a `form` and
 * does nothing. The form is shown over the panel, and what is typed into
 * it goes back to the same action as `fields`. So the server decides what
 * to ask and with what already filled in, and a request made without the
 * form is as harmless as one made with an empty one.
 *
 * @param {Element} flyout   The panel.
 * @param {Object}  config   The panel's.
 * @param {Object}  response What the action answered.
 * @param {Object}  body     What it was sent, to send again.
 * @param {Element} opener   What to hand focus back to.
 * @return {boolean} Whether there was a form to open.
 */
export function askFor( flyout, config, response, body, opener ) {
	if ( ! response.form ) {
		return false;
	}

	openPrompt( flyout, response.form, {
		opener,
		strings: strings(),
		submit: ( fields ) => api( '/action', { ...body, fields } ),
		done: ( answer ) => {
			afterAction( flyout, config, answer );
			ran( flyout, 'wpflyout:prompt-action', answer, body.action_key );
		},
	} );

	return true;
}

/**
 * Show what an action did.
 *
 * A message, briefly. `updates` is a map of selector to markup, each
 * looked for inside this panel, so what changed is redrawn where it is;
 * `refresh_flyout` loads the whole panel again, and `reload` the page
 * behind it.
 *
 * @param {Element} flyout   The panel.
 * @param {Object}  config   The panel's.
 * @param {Object}  response The action's answer.
 */
export function afterAction( flyout, config, response ) {
	toast( flyout, response.message || strings().done );

	Object.keys( response.updates || {} ).forEach( ( selector ) => {
		all( selector, flyout ).forEach( ( element ) =>
			replaceHtml( element, response.updates[ selector ] )
		);
	} );

	if ( true === response.reload ) {
		// The record is not what it was, so there is nothing here left to
		// lose.
		markClean( flyout );

		setTimeout( reload, CLOSE_AFTER );
	} else if ( true === response.refresh_flyout ) {
		// Drawn again from the server -- unless there is something typed
		// into its form, which a fresh copy would throw away. Then it is
		// asked about, as it is for every other way of replacing what is in
		// the panel; kept, the panel stays as it is until it is next opened.
		setTimeout(
			() => request( config, { force: ! isDirty( flyout ) } ),
			CLOSE_AFTER
		);
	}
}
