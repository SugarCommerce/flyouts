/**
 * Putting a record in the panel: from a trigger's attributes, through the
 * load route, with a placeholder on screen while it comes.
 */

import { strings } from '../lib/config';
import { all, insert, make, one } from '../lib/dom';
import { showAlert, toastRegion } from '../lib/feedback';
import { api } from '../lib/request';
import { stash } from '../lib/store';
import panel from '../panel/panel';
import { confirmDiscard } from './confirm';
import { isDirty, watch } from './dirty';
import { dressHeader } from './header';
import { bindHandlers } from './save';
import { announceStep, step } from './steps';
import { skeleton } from './skeleton';

// The attributes a trigger has for the manager; the rest go with the load.
const OWN = [
	'flyout',
	'flyoutManager',
	'flyoutSize',
	'flyoutHeader',
	'flyoutSteps',
];

// Counts loads, so an answer that arrives after a newer question was
// asked can be told apart and dropped.
let loads = 0;

/**
 * The panel on top, if one is open.
 *
 * @return {Element|null} The panel.
 */
export function current() {
	const id = panel.getLastId();

	return id ? document.getElementById( id ) : null;
}

/**
 * What a trigger asks for.
 *
 * @param {Element} trigger A `.wp-flyout-trigger`.
 * @return {Object} `flyout`, `manager`, `size`, `header`, `steps`,
 *                  `stepKeys`, `trigger` and `data` (its other data-*).
 */
export function extractConfig( trigger ) {
	const steps = String( trigger.getAttribute( 'data-flyout-steps' ) );
	const config = {
		flyout: trigger.dataset.flyout,
		manager: trigger.dataset.flyoutManager,
		size: trigger.dataset.flyoutSize || '',

		// What the panel's header will be, so the placeholder shown while
		// it loads can be dressed the same way.
		header: trigger.getAttribute( 'data-flyout-header' ) || '',

		// The arrows, unless the panel asked for none or for the keyboard
		// in their place; and whether it asked for the keyboard at all.
		steps: ! [ '0', 'keys' ].includes( steps ),
		stepKeys: [ 'keys', 'both' ].includes( steps ),
		trigger,
		data: {},
	};

	Object.keys( trigger.dataset ).forEach( ( key ) => {
		if ( ! OWN.includes( key ) ) {
			config.data[ key ] = trigger.dataset[ key ];
		}
	} );

	return config;
}

/**
 * Open a record, asking first if that would lose something.
 *
 * Every way of putting a record in the panel comes through here, so none
 * of them can replace a half-edited form without saying so.
 *
 * @param {Object}  config          What extractConfig() made of a trigger.
 * @param {Object}  [options]
 * @param {boolean} [options.force] Replace what is open without asking.
 * @param {string}  [options.focus] A selector to focus once it is open.
 */
export function request( config, options = {} ) {
	const open = current();

	if ( open && ! options.force && isDirty( open ) ) {
		confirmDiscard( open ).then( ( discard ) => {
			if ( discard ) {
				loadFlyout( config, options );
			}
		} );

		return;
	}

	loadFlyout( config, options );
}

/**
 * Load a record into the panel.
 *
 * @param {Object} config  What extractConfig() made of a trigger.
 * @param {Object} options As request()'s.
 */
export function loadFlyout( config, options = {} ) {
	const load = ++loads;
	const started = Date.now();

	// The panel is on screen before the record is. A click answered by
	// nothing for half a second gets clicked again.
	const placeholder = showSkeleton( config, options );

	// Whether that placeholder is still what this load is for: not closed
	// in the meantime, and not overtaken by another record.
	const wanted = () =>
		load === loads && panel.active.includes( placeholder.id );

	// Not before the placeholder has finished sliding in. Swapped halfway,
	// the record appears at the end of a journey the eye was still
	// following.
	const settled = ( then ) => {
		const wait = placeholder.instant
			? 0
			: panel.config.animationDuration + 20 - ( Date.now() - started );

		setTimeout(
			() => {
				if ( wanted() ) {
					then();
				}
			},
			Math.max( 0, wait )
		);
	};

	api( '/load', {
		manager: config.manager,
		flyout: config.flyout,
		item_id: config.data.id || 0,
	} ).then(
		( response ) =>
			settled( () => {
				if ( response.success ) {
					displayFlyout( response.html, config, options );
				} else {
					showLoadFailure( placeholder.id, response.message );
				}
			} ),
		( error ) =>
			settled( () => showLoadFailure( placeholder.id, error.message ) )
	);
}

/**
 * Put a placeholder panel where the record is about to be.
 *
 * Grey blocks in the shape of a card of fields, in a panel of the size the
 * real one will be -- the trigger says which, and failing that the panel
 * already open does. It stands in for the record completely: it has a
 * close button, it traps focus, and when the record has neighbours it has
 * their arrows too, so somebody stepping through a list does not wait for
 * each one to load before asking for the next.
 *
 * @param {Object} config  The trigger's.
 * @param {Object} options request()'s.
 * @return {{id: string, instant: boolean}} The placeholder's id, and
 *                                          whether it appeared in place.
 */
export function showSkeleton( config, options = {} ) {
	const open = current();
	const instant = !! open;
	const id = 'wp-flyout-loading-' + loads;
	let shape = [];

	// The size and side of what it replaces, so nothing moves.
	if ( open ) {
		shape = Array.from( open.classList ).filter( ( name ) =>
			/^wp-flyout-(small|medium|large|full|left|right)$/.test( name )
		);
	}

	if ( ! shape.length ) {
		shape = [
			'wp-flyout-' +
				( [ 'small', 'medium', 'large', 'full' ].includes( config.size )
					? config.size
					: 'medium' ),
			'wp-flyout-right',
		];
	}

	clear();

	const flyout = skeleton( id, shape, strings().loading );

	document.body.append( flyout );
	stash( flyout ).config = config;

	dressHeader( flyout, config );

	// Only what a placeholder can do: close, and step to a neighbour.
	flyout.addEventListener( 'click', ( event ) => {
		const arrow =
			event.target instanceof window.Element
				? event.target.closest( '.wp-flyout-prev, .wp-flyout-next' )
				: null;

		if ( arrow && flyout.contains( arrow ) ) {
			event.preventDefault();
			step(
				config,
				arrow.classList.contains( 'wp-flyout-next' ) ? 'next' : 'prev'
			);
		}
	} );

	panel.open( id, {
		instant,
		opener: config.trigger,
		focus: options.focus,
	} );

	return { id, instant };
}

/**
 * Say in the placeholder that the record did not arrive: in the panel,
 * where the person is looking, and not in a browser alert that has to be
 * dismissed before anything else can happen.
 *
 * @param {string} id      The placeholder's id.
 * @param {string} message What went wrong.
 */
export function showLoadFailure( id, message ) {
	const flyout = document.getElementById( id );

	if ( ! flyout ) {
		return;
	}

	flyout.classList.remove( 'wp-flyout-loading' );
	flyout.setAttribute( 'aria-busy', 'false' );
	all( '.wp-flyout-title', flyout ).forEach( ( title ) => {
		title.textContent = strings().loadFailed;
	} );
	all( '.wp-flyout-body', flyout ).forEach( ( body ) =>
		body.replaceChildren()
	);

	showAlert( flyout, message || strings().requestFailed, 'error' );

	const close = one( '.wp-flyout-close', flyout );

	if ( close ) {
		close.focus();
	}
}

/**
 * Stop watching a panel that is going away.
 *
 * @param {Element} flyout The panel.
 */
export function teardown( flyout ) {
	const kept = stash( flyout );

	if ( kept.observer ) {
		kept.observer.disconnect();
		delete kept.observer;
	}

	// A question nobody is going to answer now.
	if ( 'function' === typeof kept.settle ) {
		kept.settle( false );
	}

	// What the fields package started on this markup and keeps outside it.
	// A visual editor is held by id in TinyMCE's own registry, so the same
	// panel opened again found the old editor still there and the new one
	// never started.
	const fields = window.SugarCommerceFieldsModules;

	if ( fields && 'function' === typeof fields.destroy ) {
		fields.destroy( flyout );
	}
}

/**
 * Take every panel off the page, open or not.
 *
 * A closed panel is left in the document until the next one is loaded,
 * and an open one is about to be replaced. Either way it goes without
 * ceremony, and without being left on the list of what is open -- which
 * is what kept the overlay up after a panel had been replaced and then
 * closed.
 */
export function clear() {
	all( '.wp-flyout' ).forEach( ( flyout ) => {
		teardown( flyout );
		panel.discard( flyout.id );
	} );
}

/**
 * Wrap a panel's body in a form, if the server drew none.
 *
 * @param {Element} flyout The panel.
 */
export function ensureForm( flyout ) {
	if ( one( 'form', flyout ) ) {
		return;
	}

	const body = one( '.wp-flyout-body', flyout );

	if ( ! body ) {
		return;
	}

	const form = make( 'form', { class: 'wp-flyout-form', novalidate: '' } );

	form.append( ...body.childNodes );
	body.append( form );
}

/**
 * Put the record the server drew in the page and open it.
 *
 * @param {string} html    The panel's markup.
 * @param {Object} config  The trigger's.
 * @param {Object} options request()'s, with `announce` for a step.
 */
export function displayFlyout( html, config, options = {} ) {
	clear();
	insert( document.body, html );

	const flyouts = all( '.wp-flyout' );
	const flyout = flyouts[ flyouts.length - 1 ];

	if ( ! flyout ) {
		return;
	}

	stash( flyout ).config = config;

	ensureForm( flyout );
	dressHeader( flyout, config );
	toastRegion( flyout );
	bindHandlers( flyout, flyout.id, config );

	// In place: the placeholder it replaces has already slid in.
	panel.open( flyout.id, {
		instant: true,
		opener: config.trigger,
		focus: options.focus,
	} );

	announceStep( flyout, options.announce );

	// Every field in here is the fields package's, and it binds its modules
	// on DOMContentLoaded -- long past by the time this markup arrives. Its
	// init() is safe to call again for exactly this, and each module marks
	// what it has bound.
	const fields = window.SugarCommerceFieldsModules;

	if ( fields && 'function' === typeof fields.init ) {
		fields.init( flyout );
	}

	// After the fields package, so what it does to a field while setting it
	// up is part of how the form started and not a change to it.
	watch( flyout );
}
