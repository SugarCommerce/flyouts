/**
 * The controls the script adds to a panel's header: the close button's
 * name, previous and next, the J and K hint, and a copy button beside a
 * title that is something to copy.
 */

import { icon, strings } from '../lib/config';
import { make, one, text } from '../lib/dom';
import { neighbours, position } from './steps';

/**
 * Give the header the controls this script adds to it.
 *
 * The close button gets its name, and sits in a group at the end of the
 * header with whatever else is there -- the menu the server rendered, and
 * previous and next when the record has neighbours. A header rendered
 * without the group is given one, so a panel built by an older server, or
 * by hand, comes out the same.
 *
 * @param {Element} flyout The panel.
 * @param {Object}  config The trigger's.
 */
export function dressHeader( flyout, config ) {
	const header = one( '.wp-flyout-header', flyout );

	if ( ! header ) {
		return;
	}

	const close = one( '.wp-flyout-close', header );
	let actions = Array.from( header.children ).find( ( child ) =>
		child.classList.contains( 'wp-flyout-header-actions' )
	);

	if ( ! actions ) {
		actions = make( 'div', { class: 'wp-flyout-header-actions' } );
		header.append( actions );

		if ( close ) {
			actions.append( close );
		}
	}

	if ( close && ! close.getAttribute( 'aria-label' ) ) {
		close.setAttribute( 'aria-label', strings().close );
	}

	if ( close && ! close.getAttribute( 'title' ) ) {
		close.setAttribute( 'title', close.getAttribute( 'aria-label' ) );
	}

	addSteps( actions, config, flyout );
	addCopy( flyout );
}

/**
 * Put a copy button beside a title that is something to copy.
 *
 * A licence key, a code, a reference: a title somebody is going to paste
 * into an email. The panel says so with `copy_title`, which arrives here
 * as a class on the panel, and the button copies the title as it is shown.
 *
 * @param {Element} flyout The panel.
 */
export function addCopy( flyout ) {
	const title = one( '.wp-flyout-header .wp-flyout-title', flyout );

	if (
		! flyout.classList.contains( 'wp-flyout-copy-title' ) ||
		! title ||
		! text( title )
	) {
		return;
	}

	const there = Array.from( title.parentNode.children ).some(
		( sibling ) =>
			sibling !== title && sibling.classList.contains( 'wp-flyout-copy' )
	);

	if ( there ) {
		return;
	}

	const button = make( 'button', {
		type: 'button',
		class: 'wp-flyout-copy',
		'aria-label': strings().copy,
		title: strings().copy,
	} );

	button.innerHTML = icon( 'copy', 'admin-page' );
	title.after( button );
}

/**
 * Add previous and next to the header, when there is either.
 *
 * @param {Element}      actions The header's group of controls.
 * @param {Object}       config  The trigger's.
 * @param {Element|null} flyout  The panel.
 */
export function addSteps( actions, config, flyout ) {
	const list = neighbours( config );
	const at = position( config, list );
	const has = ( name ) => !! flyout && flyout.classList.contains( name );
	const identity =
		'identity' === config.header || has( 'wp-flyout-identity' );

	// Nothing to step to: a new record, or the only one listed.
	if ( -1 === at || list.length < 2 ) {
		return;
	}

	// The keys in place of the arrows, or beside them: said once, quietly,
	// at the foot of the menu, where somebody looking for what can be done
	// will find it.
	if ( config.stepKeys || has( 'wp-flyout-step-keys' ) ) {
		addStepHint( flyout );
	}

	// A panel that asked for none. The panel says so with a class and its
	// trigger with an attribute, because the placeholder is drawn before
	// the panel has arrived to say anything.
	if ( false === config.steps || has( 'wp-flyout-no-steps' ) ) {
		return;
	}

	const button = ( direction, label, glyph, disabled ) => {
		const arrow = make( 'button', {
			type: 'button',
			class: 'wp-flyout-step wp-flyout-' + direction,
			'aria-label': label,
			title: label,
		} );

		arrow.disabled = disabled;
		arrow.innerHTML = glyph;

		return arrow;
	};

	const steps = make( 'div', { class: 'wp-flyout-steps', role: 'group' } );

	steps.append(
		button(
			'prev',
			strings().previous,
			icon( 'previous', 'arrow-left-alt2' ),
			0 === at
		),
		button(
			'next',
			strings().next,
			icon( 'next', 'arrow-right-alt2' ),
			at === list.length - 1
		)
	);

	// In a header with an identity the menu comes first: what can be done
	// to this record, then the way to another, then out.
	if ( identity ) {
		steps.classList.add( 'wp-flyout-steps--quiet' );

		const close = Array.from( actions.children ).find( ( child ) =>
			child.classList.contains( 'wp-flyout-close' )
		);

		if ( close ) {
			close.before( steps );
		}
	} else {
		actions.prepend( steps );
	}
}

/**
 * Say which keys step through the list, at the foot of the menu.
 *
 * Not a menu item: it cannot be chosen and the arrow keys pass over it.
 * Left out where there is no menu, and hidden by the stylesheet on a
 * screen too narrow to have a keyboard beside it.
 *
 * @param {Element|null} flyout The panel.
 */
export function addStepHint( flyout ) {
	const list = flyout ? one( '.wp-flyout-menu-list', flyout ) : null;

	if (
		! list ||
		Array.from( list.children ).some( ( child ) =>
			child.classList.contains( 'wp-flyout-menu-hint' )
		)
	) {
		return;
	}

	const hint = make( 'div', {
		class: 'wp-flyout-menu-hint',
		role: 'presentation',
	} );

	hint.innerHTML = '<kbd>J</kbd><kbd>K</kbd>';
	hint.append( make( 'span', {}, strings().stepKeysHint ) );
	list.append( hint );
}
