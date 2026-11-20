/**
 * Asking a yes-or-no question in a panel.
 *
 * A bar above the footer, where the buttons already are, rather than the
 * browser's confirm(): that one stops the page, cannot be styled or
 * translated by anything here, and is offered a "do not show again" by
 * some browsers that then answers no to every question for good.
 */

import { strings } from '../lib/config';
import { make } from '../lib/dom';
import { stash } from '../lib/store';

/**
 * Ask a question in the panel.
 *
 * Focus goes to the answer that loses nothing, Escape gives that answer,
 * and focus goes back where it was afterwards. One question at a time:
 * the one it replaces is answered no.
 *
 * @param {Element} flyout           The panel.
 * @param {Object}  options
 * @param {string}  options.title    The question.
 * @param {string}  [options.text]   What it means, if it needs saying.
 * @param {string}  [options.accept] The word for yes.
 * @param {string}  [options.cancel] The word for no.
 * @param {boolean} [options.danger] Yes destroys something.
 * @return {Promise<boolean>} The answer.
 */
export function confirm( flyout, options ) {
	const kept = stash( flyout );
	const base = flyout.id + '-confirm';

	if ( 'function' === typeof kept.settle ) {
		kept.settle( false );
	}

	return new Promise( ( resolve ) => {
		const back = flyout.ownerDocument.activeElement;
		const bar = make( 'div', {
			class: 'wp-flyout-confirm',
			role: 'alertdialog',
			'aria-labelledby': base + '-title',
		} );
		const words = make( 'div', { class: 'wp-flyout-confirm-text' } );
		const buttons = make( 'div', { class: 'wp-flyout-confirm-actions' } );

		words.append(
			make(
				'strong',
				{ class: 'wp-flyout-confirm-title', id: base + '-title' },
				options.title
			)
		);

		if ( options.text ) {
			words.append(
				make(
					'span',
					{
						class: 'wp-flyout-confirm-description',
						id: base + '-text',
					},
					options.text
				)
			);
			bar.setAttribute( 'aria-describedby', base + '-text' );
		}

		const no = make(
			'button',
			{ type: 'button', class: 'button wp-flyout-confirm-cancel' },
			options.cancel || strings().cancel
		);

		// Destructive is the fields package's treatment, which is core's: a
		// red outline, and not a red fill core does not have.
		const yes = make(
			'button',
			{
				type: 'button',
				class:
					'button wp-flyout-confirm-accept ' +
					( options.danger
						? 'field-kit__button--delete'
						: 'button-primary' ),
			},
			options.accept || strings().confirm
		);

		buttons.append( no, yes );
		bar.append( words, buttons );

		const settle = ( answer ) => {
			delete kept.settle;
			flyout.classList.remove( 'has-confirm' );
			bar.remove();

			// Whatever the answer, to where it was asked from: the bar that
			// had focus is gone, and focus left on nothing starts again from
			// the top of the page.
			if ( back && flyout.contains( back ) ) {
				back.focus();
			}

			resolve( answer );
		};

		no.addEventListener( 'click', () => settle( false ) );
		yes.addEventListener( 'click', () => settle( true ) );

		bar.addEventListener( 'keydown', ( event ) => {
			if ( 'Escape' === event.key ) {
				// Answers the question, and stops there: the same key must
				// not also be a second request to close.
				event.preventDefault();
				event.stopPropagation();
				settle( false );
			}
		} );

		kept.settle = settle;
		flyout.classList.add( 'has-confirm' );

		const footer = Array.from( flyout.children ).find( ( child ) =>
			child.classList.contains( 'wp-flyout-footer' )
		);

		if ( footer ) {
			footer.before( bar );
		} else {
			flyout.append( bar );
		}

		no.focus();
	} );
}

/**
 * Ask whether to throw away what has been changed.
 *
 * @param {Element} flyout The panel.
 * @return {Promise<boolean>} True to discard.
 */
export function confirmDiscard( flyout ) {
	return confirm( flyout, {
		title: strings().unsavedTitle,
		text: strings().unsavedText,
		cancel: strings().keepEditing,
		accept: strings().discard,
		danger: true,
	} );
}
