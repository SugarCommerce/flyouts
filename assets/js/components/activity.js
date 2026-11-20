/**
 * The note box over an activity feed.
 *
 * Everything else in the feed -- "Show Earlier Activity", a note's Delete
 * -- is one of the panel's row actions and is run by the manager; this
 * sends what was written, and puts the feed the server draws back in place
 * of the old one. Fires `wpflyout:note-added` on the feed with the answer,
 * for a panel that shows the same history somewhere else as well and has
 * to redraw it; a jQuery listener is handed `( event, response )`.
 */

import { strings } from '../lib/config';
import { all, on, replaceHtml } from '../lib/dom';
import { emit } from '../lib/events';
import { setButtonState, showAlert, toast } from '../lib/feedback';
import { api } from '../lib/request';
import { itemId, panelConfig } from '../lib/store';

/**
 * Send the note, and draw the feed the server answers with.
 *
 * @param {Element} button The feed's Add button.
 */
export function add( button ) {
	const activity = button.closest( '.wp-flyout-activity' );
	const flyout = activity ? activity.closest( '.wp-flyout' ) : null;
	const input = activity
		? activity.querySelector( '.wp-flyout-activity-input' )
		: null;
	const content = input ? input.value.trim() : '';

	if ( ! flyout || ! input ) {
		return;
	}

	if ( ! content || button.classList.contains( 'is-busy' ) ) {
		input.focus();
		return;
	}

	const config = panelConfig( flyout );

	setButtonState( button, true );

	api( '/action', {
		manager: config.manager,
		flyout: config.flyout,
		action_key: button.getAttribute( 'data-action' ),
		item_id: itemId( flyout ),
		content,
	} )
		.then( ( response ) => {
			if ( ! response.success ) {
				throw new Error( response.message || '' );
			}

			Object.keys( response.updates || {} ).forEach( ( selector ) =>
				all( selector, flyout ).forEach( ( element ) =>
					replaceHtml( element, response.updates[ selector ] )
				)
			);

			input.value = '';
			input.focus();
			toast( flyout, response.message || '' );

			emit( activity, 'wpflyout:note-added', {
				detail: { response },
				args: [ response ],
			} );
		} )
		.catch( ( error ) =>
			showAlert(
				flyout,
				error.message || strings().requestFailed,
				'error'
			)
		)
		.finally( () => setButtonState( button, false ) );
}

/**
 * Make every note box work, the ones in panels yet to load included.
 */
export function bindActivity() {
	on( document, 'click', '.wp-flyout-activity-add', ( event, button ) => {
		event.preventDefault();
		add( button );
	} );

	on( document, 'keydown', '.wp-flyout-activity-input', ( event, input ) => {
		// Command or Ctrl with Enter, as every comment box takes it; Shift
		// with Enter is kept for whoever learned that first. Enter alone is
		// still a new line in the note.
		if (
			'Enter' !== event.key ||
			! ( event.metaKey || event.ctrlKey || event.shiftKey )
		) {
			return;
		}

		event.preventDefault();

		const activity = input.closest( '.wp-flyout-activity' );
		const button = activity
			? activity.querySelector( '.wp-flyout-activity-add' )
			: null;

		if ( button ) {
			add( button );
		}
	} );
}
