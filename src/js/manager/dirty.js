/**
 * Whether a panel holds changes that have not been saved.
 *
 * Fires `wpflyout:dirty` on the panel when that changes; a jQuery listener
 * is handed `( event, dirty )`, a native one reads `event.detail.dirty`.
 */

import { all, one } from '../lib/dom';
import { emit } from '../lib/events';
import { collect } from '../lib/form';
import { panelConfig, stash } from '../lib/store';

/**
 * What the form would submit, as one string.
 *
 * @param {Element} flyout The panel.
 * @return {string} The form's data as JSON, or '' with no form.
 */
export function snapshot( flyout ) {
	const form = one( 'form', flyout );

	return form ? JSON.stringify( collect( form ) ) : '';
}

/**
 * Start watching a panel's form for changes.
 *
 * Dirty means the form would now submit something other than what it
 * would have submitted when it opened. That is asked of the form itself
 * rather than counted up from events, for three reasons: a repeater's row
 * is added and removed by a click and a media field is filled by a
 * script, neither of which is an `input` event; a search box typed into
 * changes nothing that would be saved; and somebody who types a letter
 * and deletes it has not changed anything, and should not be asked
 * whether to discard it.
 *
 * The events and the observer only say when to look again.
 *
 * @param {Element} flyout The panel.
 */
export function watch( flyout ) {
	const form = one( 'form', flyout );

	if ( ! form ) {
		return;
	}

	const kept = stash( flyout );
	let timer = null;

	const look = () => {
		clearTimeout( timer );
		timer = setTimeout( () => refresh( flyout ), 60 );
	};

	markClean( flyout );

	[ 'input', 'change', 'field-kit:change' ].forEach( ( type ) =>
		flyout.addEventListener( type, look )
	);

	if ( window.MutationObserver ) {
		kept.observer = new window.MutationObserver( look );
		kept.observer.observe( form, { childList: true, subtree: true } );
	}

	// A field that finishes setting itself up a moment after it is drawn
	// has not been changed by anybody. Until a key or a pointer has been
	// put to the panel, whatever the form holds once it is ready is how it
	// started.
	const touched = () => {
		kept.touched = true;
	};

	flyout.addEventListener( 'keydown', touched, { once: true } );
	flyout.addEventListener( 'pointerdown', touched, { once: true } );

	flyout.addEventListener(
		'flyout:ready',
		() => {
			if ( ! kept.touched ) {
				markClean( flyout );
			}
		},
		{ once: true }
	);
}

/**
 * Whether the panel holds changes that have not been saved.
 *
 * @param {Element} flyout The panel.
 * @return {boolean} Dirty.
 */
export function isDirty( flyout ) {
	const kept = stash( flyout );

	if ( true === kept.dirty ) {
		return true;
	}

	return 'string' === typeof kept.clean && kept.clean !== snapshot( flyout );
}

/**
 * Say a panel has changed, for a change the form cannot show: a component
 * that keeps something outside the form's inputs. It stays said until the
 * panel is saved.
 *
 * @param {Element} flyout The panel.
 */
export function markDirty( flyout ) {
	stash( flyout ).dirty = true;
	refresh( flyout );
}

/**
 * Take the form as it is now to be the saved one.
 *
 * @param {Element} flyout The panel.
 * @param {string}  [sent] What was saved, when that is not what the form
 *                         holds now: anything typed while a save was on
 *                         its way is still a change.
 */
export function markClean( flyout, sent ) {
	const kept = stash( flyout );

	kept.dirty = false;
	kept.clean = undefined === sent ? snapshot( flyout ) : sent;
	refresh( flyout );
}

/**
 * Whether the panel is for a record that does not exist yet.
 *
 * @param {Element} flyout The panel.
 * @return {boolean} New.
 */
export function isNew( flyout ) {
	const config = panelConfig( flyout );
	const input = one( 'input[name="id"]', flyout );
	const id =
		( input && input.value ) || ( config.data && config.data.id ) || '';

	return '' === String( id ) || '0' === String( id );
}

/**
 * Bring the panel into line with whether it has changes.
 *
 * Save is for saving something. On a record that already exists it is
 * only live once there is a change to save; on a new one it is live from
 * the start, because an empty form is a record nobody has created yet and
 * creating it is the point.
 *
 * A button marked data-always-enabled is left alone, for a panel whose
 * Save does something other than store the form.
 *
 * @param {Element} flyout The panel.
 */
export function refresh( flyout ) {
	const dirty = isDirty( flyout );
	const was = flyout.classList.contains( 'is-dirty' );

	flyout.classList.toggle( 'is-dirty', dirty );

	all( '.wp-flyout-save', flyout )
		.filter(
			( button ) =>
				! button.hasAttribute( 'data-always-enabled' ) &&
				! button.classList.contains( 'is-busy' )
		)
		.forEach( ( button ) => {
			button.disabled = ! dirty && ! isNew( flyout );
		} );

	if ( dirty !== was ) {
		emit( flyout, 'wpflyout:dirty', {
			detail: { dirty },
			args: [ dirty ],
		} );
	}
}
