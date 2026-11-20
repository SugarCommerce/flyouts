/**
 * A loaded panel's buttons: Save, Delete, Cancel, the arrows, the copy
 * button, its menu and its row actions.
 */

import { icon, strings } from '../lib/config';
import { all, isUsable, on, one, text } from '../lib/dom';
import { setButtonState, showAlert, toast, TOAST_FOR } from '../lib/feedback';
import { collect, missing } from '../lib/form';
import { scrollToTop } from '../lib/motion';
import { reload } from '../lib/page';
import { api } from '../lib/request';
import { itemId, stash } from '../lib/store';
import panel from '../panel/panel';
import { CLOSE_AFTER, runRowAction } from './actions';
import { copy } from './clipboard';
import { confirm } from './confirm';
import { markClean, refresh } from './dirty';
import { clearFieldError, clearFieldErrors, showFailure } from './errors';
import { bindMenu } from './menu';
import { step } from './steps';

/**
 * Make a loaded panel's buttons work.
 *
 * @param {Element} flyout   The panel.
 * @param {string}  flyoutId Its id.
 * @param {Object}  config   The trigger's.
 */
export function bindHandlers( flyout, flyoutId, config ) {
	on( flyout, 'click', '.wp-flyout-save', ( event ) => {
		event.preventDefault();
		handleSave( flyout, flyoutId, config );
	} );

	on( flyout, 'click', '.wp-flyout-delete', ( event, button ) => {
		event.preventDefault();

		if ( button.classList.contains( 'is-busy' ) ) {
			return;
		}

		confirm( flyout, {
			title: strings().deleteTitle,
			accept: strings().deleteConfirm,
			danger: true,
		} ).then( ( yes ) => {
			if ( yes ) {
				handleDelete( flyout, flyoutId, config );
			}
		} );
	} );

	// Cancel is the close button with a word on it, and asks the same
	// question the close button does. The close button itself is bound once
	// for every panel, by the panel (panel/panel.js).
	on( flyout, 'click', '.wp-flyout-cancel', ( event ) => {
		event.preventDefault();
		panel.close( flyoutId );
	} );

	on(
		flyout,
		'click',
		'.wp-flyout-prev, .wp-flyout-next',
		( event, arrow ) => {
			event.preventDefault();
			step(
				config,
				arrow.classList.contains( 'wp-flyout-next' ) ? 'next' : 'prev'
			);
		}
	);

	bindMenu( flyout, config );

	on( flyout, 'click', '.wp-flyout-copy', ( event, button ) => {
		event.preventDefault();

		// What the button itself carries, when the server gave it something
		// to copy; the title beside it otherwise.
		const said =
			button.getAttribute( 'data-copy' ) ||
			text( one( '.wp-flyout-header .wp-flyout-title', flyout ) );

		copy( said ).then( ( ok ) => {
			if ( ! ok ) {
				showAlert( flyout, strings().copyFailed, 'error' );
				return;
			}

			toast( flyout, strings().copied );

			// A tick where the pages were, for as long as the toast says so.
			const kept = stash( button );

			button.innerHTML = icon( 'copied', 'yes' );
			clearTimeout( kept.timer );
			kept.timer = setTimeout( () => {
				button.innerHTML = icon( 'copy', 'admin-page' );
			}, TOAST_FOR );
		} );
	} );

	on( flyout, 'click', '.wp-flyout-row-action', ( event, button ) => {
		event.preventDefault();

		if ( button.classList.contains( 'is-busy' ) ) {
			return;
		}

		const question = button.getAttribute( 'data-confirm' );

		if ( ! question ) {
			runRowAction( flyout, config, button );
			return;
		}

		confirm( flyout, {
			title: question,
			accept: text( button ) || strings().confirm,
			danger: button.classList.contains( 'is-danger' ),
		} ).then( ( yes ) => {
			if ( yes ) {
				runRowAction( flyout, config, button );
			}
		} );
	} );

	// A required field marked empty, once it is being filled in; and what a
	// save said was wrong with a field, once the field is being put right.
	[ 'input', 'change' ].forEach( ( type ) => {
		on( flyout, type, '.error', ( event, field ) =>
			field.classList.remove( 'error' )
		);
		on( flyout, type, '.wp-flyout-has-error', ( event, field ) =>
			clearFieldError( field )
		);
	} );
}

/**
 * Save the panel's form.
 *
 * Required fields left empty are marked and the first is focused, without
 * a request. What was sent is what is stored once the save answers, and
 * the page behind is loaded again so the list shows it.
 *
 * @param {Element} flyout   The panel.
 * @param {string}  flyoutId Its id.
 * @param {Object}  config   The trigger's.
 */
export function handleSave( flyout, flyoutId, config ) {
	const buttons = all( '.wp-flyout-save', flyout );

	// Already on its way.
	if (
		buttons.some( ( button ) => button.classList.contains( 'is-busy' ) )
	) {
		return;
	}

	clearFieldErrors( flyout );

	const form = one( 'form', flyout );
	const empty = form ? missing( form ) : [];

	if ( form ) {
		all( '[required].error', form )
			.filter(
				( control ) =>
					isUsable( control ) && ! empty.includes( control )
			)
			.forEach( ( control ) => control.classList.remove( 'error' ) );
	}

	if ( empty.length ) {
		empty.forEach( ( control ) => control.classList.add( 'error' ) );
		scrollToTop( one( '.wp-flyout-body', flyout ) );
		showAlert( flyout, strings().required, 'error' );
		empty[ 0 ].focus();
		return;
	}

	const data = form ? collect( form ) : {};
	const sent = JSON.stringify( data );

	all( '.wp-flyout-alert', flyout ).forEach( ( alert ) => alert.remove() );
	setButtonState( buttons, true );

	api( '/save', {
		manager: config.manager,
		flyout: config.flyout,
		item_id: config.data.id || data.id || 0,
		form_data: data,
	} )
		.then( ( response ) => {
			setButtonState( buttons, false );

			if ( ! response.success ) {
				refresh( flyout );
				showFailure( flyout, response.message, response.data );
				return;
			}

			// What was sent is what is saved now. Anything typed while it
			// was on its way is still a change.
			markClean( flyout, sent );
			toast( flyout, strings().saved );

			setTimeout( () => {
				panel.close( flyoutId, { force: true } );
				reload();
			}, CLOSE_AFTER );
		} )
		.catch( ( error ) => {
			setButtonState( buttons, false );
			refresh( flyout );
			showFailure( flyout, error.message, error.data );
		} );
}

/**
 * Delete the panel's record, once Delete has been confirmed.
 *
 * @param {Element} flyout   The panel.
 * @param {string}  flyoutId Its id.
 * @param {Object}  config   The trigger's.
 */
export function handleDelete( flyout, flyoutId, config ) {
	const buttons = all( '.wp-flyout-delete', flyout );
	const body = one( '.wp-flyout-body', flyout );
	const failed = ( message ) => {
		setButtonState( buttons, false );
		showAlert( flyout, message || strings().requestFailed, 'error' );
		scrollToTop( body );
	};

	setButtonState( buttons, true );

	api( '/delete', {
		manager: config.manager,
		flyout: config.flyout,
		item_id: itemId( flyout ) || config.data.id,
	} ).then(
		( response ) => {
			if ( ! response.success ) {
				failed( response.message );
				return;
			}

			toast( flyout, response.message || strings().deleted );

			setTimeout( () => {
				// Whatever was typed was about a record that is gone, so
				// nobody is asked about it.
				panel.close( flyoutId, { force: true } );
				reload();
			}, CLOSE_AFTER );
		},
		( error ) => failed( error.message )
	);
}
