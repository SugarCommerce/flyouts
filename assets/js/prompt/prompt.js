/**
 * The form an action asks for before it runs: a small dialog over the
 * panel with a few fields, a button that sends them and a way out.
 *
 * An action answers with `form` when it needs something written first:
 *
 *     {
 *         title: 'Send an email',
 *         description: '',
 *         submit: 'Send',
 *         fields: [
 *             { name: 'to', label: 'To', type: 'static', value: 'ada@example.com' },
 *             { name: 'subject', label: 'Subject', type: 'text', required: true, maxlength: 200 },
 *             { name: 'message', label: 'Message', type: 'textarea', required: true, rows: 8 }
 *         ]
 *     }
 *
 * `static` is shown and not sent: the server already knows it, and a value
 * that came back from the browser is not one it should trust. Everything
 * else goes back to the same action as `fields`.
 *
 * It is a dialog in its own right. Focus goes to the first field and stays
 * inside; Escape and Cancel close it, asking first when something has been
 * typed; Command or Ctrl with Enter sends; and focus goes back to whatever
 * opened it.
 */

import { all, isShown, make, on } from '../lib/dom';

let count = 0;

const FOCUSABLE =
	'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), ' +
	'select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * One field of the form.
 *
 * @param {Object} spec `name`, `label`, `type`, and what its type takes.
 * @param {string} id   An id for its control.
 * @return {Element} The field.
 */
export function field( spec, id ) {
	const wrap = make( 'div', { class: 'wp-flyout-prompt-field' } );
	const value =
		null === spec.value || undefined === spec.value
			? ''
			: String( spec.value );
	let control;

	if ( 'static' === spec.type ) {
		wrap.classList.add( 'wp-flyout-prompt-field--static' );
		wrap.append(
			make(
				'span',
				{ class: 'wp-flyout-prompt-label' },
				spec.label || ''
			),
			make( 'span', { class: 'wp-flyout-prompt-static' }, value )
		);

		return wrap;
	}

	wrap.append(
		make(
			'label',
			{ class: 'wp-flyout-prompt-label', for: id },
			spec.label || ''
		)
	);

	if ( 'textarea' === spec.type ) {
		control = make( 'textarea', { rows: spec.rows || 6 } );
	} else if ( 'select' === spec.type ) {
		// `options` is value to label, in the order to offer them.
		control = make( 'select' );

		Object.keys( spec.options || {} ).forEach( ( key ) =>
			control.append(
				make( 'option', { value: key }, spec.options[ key ] )
			)
		);
	} else {
		// The browser's own control for an address, a day or a number; a
		// line of text for anything else.
		control = make( 'input', {
			type: [ 'email', 'date', 'number' ].includes( spec.type )
				? spec.type
				: 'text',
		} );

		[ 'min', 'max', 'step' ].forEach( ( name ) => {
			if (
				null !== spec[ name ] &&
				undefined !== spec[ name ] &&
				'' !== spec[ name ]
			) {
				control.setAttribute( name, spec[ name ] );
			}
		} );
	}

	control.id = id;
	control.setAttribute( 'name', spec.name || '' );
	control.setAttribute( 'data-initial', value );
	control.classList.add( 'wp-flyout-prompt-control' );
	control.value = value;

	if ( spec.required ) {
		control.required = true;
		control.setAttribute( 'aria-required', 'true' );
	}

	if ( spec.maxlength ) {
		control.setAttribute( 'maxlength', spec.maxlength );
	}

	if ( spec.placeholder ) {
		control.setAttribute( 'placeholder', spec.placeholder );
	}

	wrap.append( control );

	if ( spec.description ) {
		wrap.append(
			make(
				'p',
				{ class: 'wp-flyout-prompt-note', id: id + '-note' },
				spec.description
			)
		);
		control.setAttribute( 'aria-describedby', id + '-note' );
	}

	return wrap;
}

/**
 * Take any form off a panel, without asking.
 *
 * @param {Element} flyout The panel.
 */
export function close( flyout ) {
	all( '.wp-flyout-prompt-layer', flyout ).forEach( ( layer ) =>
		layer.remove()
	);
	flyout.classList.remove( 'has-prompt' );
}

/**
 * Open a form over a panel.
 *
 * @param {Element}  flyout            The panel.
 * @param {Object}   form              What the action asked for.
 * @param {Object}   options
 * @param {Function} options.submit    Given the fields; returns a promise of the answer.
 * @param {Function} [options.done]    Given the answer, once it is a success.
 * @param {Element}  [options.opener]  What to hand focus back to.
 * @param {Object}   [options.strings] The sentences, translated.
 * @return {Element} The dialog.
 */
export function open( flyout, form, options ) {
	const id = 'wp-flyout-prompt-' + ++count;
	const strings = options.strings || {};
	const back = options.opener || flyout.ownerDocument.activeElement;
	let busy = false;

	close( flyout );

	const layer = make( 'div', { class: 'wp-flyout-prompt-layer' } );
	const dialog = make( 'div', {
		class: 'wp-flyout-prompt',
		role: 'dialog',
		'aria-modal': 'true',
		id,
		'aria-labelledby': id + '-title',
	} );
	const sheet = make( 'form', {
		class: 'wp-flyout-prompt-form',
		novalidate: '',
	} );

	layer.append( dialog );
	dialog.append( sheet );

	sheet.append(
		make(
			'h3',
			{ class: 'wp-flyout-prompt-title', id: id + '-title' },
			form.title || ''
		)
	);

	if ( form.description ) {
		sheet.append(
			make(
				'p',
				{ class: 'wp-flyout-prompt-description', id: id + '-about' },
				form.description
			)
		);
		dialog.setAttribute( 'aria-describedby', id + '-about' );
	}

	const error = make( 'div', {
		class: 'wp-flyout-prompt-error',
		role: 'alert',
		hidden: '',
	} );
	const fields = make( 'div', { class: 'wp-flyout-prompt-fields' } );

	sheet.append( error, fields );

	( form.fields || [] ).forEach( ( one, index ) =>
		fields.append( field( one, id + '-field-' + index ) )
	);

	const ask = make( 'div', { class: 'wp-flyout-prompt-ask', hidden: '' } );
	const actions = make( 'div', { class: 'wp-flyout-prompt-actions' } );
	const cancel = make(
		'button',
		{
			type: 'button',
			class: 'button button-link wp-flyout-prompt-cancel',
		},
		form.cancel || strings.cancel || 'Cancel'
	);
	const submit = make(
		'button',
		{
			type: 'submit',
			class: 'button button-primary wp-flyout-prompt-submit',
		},
		form.submit || strings.send || 'Send'
	);

	actions.append( cancel, submit );
	sheet.append( ask, actions );

	const controls = () => all( 'input, textarea, select', fields );
	const first = () => controls().find( isShown );

	const typed = () =>
		controls().some(
			( control ) =>
				'' !== ( control.value || '' ).trim() &&
				control.value !== control.getAttribute( 'data-initial' )
		);

	// Every listener this dialog put outside itself, to take away with it.
	const away = [];

	const shut = () => {
		away.forEach( ( stop ) => stop() );
		layer.remove();
		flyout.classList.remove( 'has-prompt' );

		if (
			back &&
			document.contains( back ) &&
			'function' === typeof back.focus
		) {
			back.focus();
		}
	};

	// Asked in the dialog itself, where the buttons already are.
	const leave = () => {
		if ( busy ) {
			return;
		}

		if ( ! typed() ) {
			shut();
			return;
		}

		ask.replaceChildren();
		ask.hidden = false;
		actions.hidden = true;

		const keep = make(
			'button',
			{ type: 'button', class: 'button' },
			strings.keepWriting || 'Keep Writing'
		);
		const drop = make(
			'button',
			{ type: 'button', class: 'button field-kit__button--delete' },
			strings.promptDrop || 'Discard'
		);

		ask.append(
			make(
				'strong',
				{ class: 'wp-flyout-prompt-ask-text' },
				form.discard ||
					strings.promptDiscard ||
					'Discard what you have written?'
			),
			keep,
			drop
		);

		drop.addEventListener( 'click', shut );
		keep.addEventListener( 'click', () => {
			ask.hidden = true;
			ask.replaceChildren();
			actions.hidden = false;

			const start = first();

			if ( start ) {
				start.focus();
			}
		} );

		keep.focus();
	};

	const fail = ( message, data ) => {
		const names = ( data && data.fields ) || {};
		let focus = null;

		if (
			data &&
			'string' === typeof data.field &&
			data.field &&
			undefined === names[ data.field ]
		) {
			names[ data.field ] = message;
		}

		all( '.wp-flyout-prompt-field', fields ).forEach( ( wrap ) => {
			const control = wrap.querySelector( 'input, textarea, select' );
			const said = control
				? names[ control.getAttribute( 'name' ) ]
				: undefined;

			all( '.wp-flyout-prompt-field-error', wrap ).forEach( ( note ) =>
				note.remove()
			);

			if ( ! control ) {
				return;
			}

			control.removeAttribute( 'aria-invalid' );

			if ( undefined === said ) {
				return;
			}

			control.setAttribute( 'aria-invalid', 'true' );
			wrap.append(
				make(
					'p',
					{ class: 'wp-flyout-prompt-field-error', role: 'alert' },
					said
				)
			);
			focus = focus || control;
		} );

		if ( focus ) {
			error.hidden = true;
			error.replaceChildren();
			focus.focus();
		} else {
			error.textContent =
				message ||
				strings.requestFailed ||
				'Something went wrong. Please try again.';
			error.hidden = false;
		}
	};

	const send = () => {
		const values = {};
		const empty = {};

		if ( busy || ! ask.hidden ) {
			return;
		}

		controls().forEach( ( control ) => {
			// A select the server gave no value to starts with nothing
			// chosen, and sends null until something is.
			values[ control.name ] =
				'SELECT' === control.nodeName && control.selectedIndex < 0
					? null
					: control.value;

			if ( control.required && '' === ( control.value || '' ).trim() ) {
				empty[ control.name ] =
					strings.promptEmpty || 'This needs filling in.';
			}
		} );

		if ( Object.keys( empty ).length ) {
			fail( '', { fields: empty } );
			return;
		}

		busy = true;
		error.hidden = true;
		error.replaceChildren();
		submit.classList.add( 'is-busy' );
		submit.setAttribute( 'aria-busy', 'true' );
		submit.setAttribute( 'aria-disabled', 'true' );
		dialog.setAttribute( 'aria-busy', 'true' );

		const settle = () => {
			busy = false;
			submit.classList.remove( 'is-busy' );
			submit.removeAttribute( 'aria-busy' );
			submit.removeAttribute( 'aria-disabled' );
			dialog.removeAttribute( 'aria-busy' );
		};

		options.submit( values ).then(
			( answer ) => {
				settle();

				if ( ! answer || ! answer.success ) {
					fail( answer && answer.message, answer && answer.data );
					return;
				}

				shut();

				if ( 'function' === typeof options.done ) {
					options.done( answer );
				}
			},
			( problem ) => {
				settle();
				fail( problem && problem.message, problem && problem.data );
			}
		);
	};

	sheet.addEventListener( 'submit', ( event ) => {
		event.preventDefault();
		send();
	} );

	cancel.addEventListener( 'click', leave );

	// What was wrong with a field stops being said once the field is being
	// put right.
	on( fields, 'input', 'input, textarea, select', ( event, control ) => {
		control.removeAttribute( 'aria-invalid' );

		const wrap = control.closest( '.wp-flyout-prompt-field' );

		if ( wrap ) {
			all( '.wp-flyout-prompt-field-error', wrap ).forEach( ( note ) =>
				note.remove()
			);
		}
	} );

	// A press on the dimmed panel around the dialog is a way out too.
	layer.addEventListener( 'mousedown', ( event ) => {
		if ( event.target === layer ) {
			leave();
		}
	} );

	layer.addEventListener( 'keydown', ( event ) => {
		if ( 'Escape' === event.key ) {
			// The dialog's, and not the panel's under it as well.
			event.preventDefault();
			event.stopPropagation();

			if ( ! ask.hidden ) {
				const keep = ask.querySelector( 'button' );

				if ( keep ) {
					keep.click();
				}
			} else {
				leave();
			}

			return;
		}

		if ( 'Enter' === event.key && ( event.metaKey || event.ctrlKey ) ) {
			event.preventDefault();
			event.stopPropagation();
			send();
			return;
		}

		if ( 'Tab' !== event.key ) {
			return;
		}

		// Tab stays in the dialog, and the panel's own trap, which would
		// walk the header behind it, never hears of it.
		event.stopPropagation();

		const stops = all( FOCUSABLE, dialog ).filter( isShown );
		const head = stops[ 0 ];
		const tail = stops[ stops.length - 1 ];

		const focused = dialog.ownerDocument.activeElement;

		if ( event.shiftKey && focused === head ) {
			event.preventDefault();
			tail.focus();
		} else if ( ! event.shiftKey && focused === tail ) {
			event.preventDefault();
			head.focus();
		}
	} );

	// Focus that gets out some other way is brought back.
	const refocus = ( event ) => {
		if (
			document.contains( layer ) &&
			! layer.contains( event.target ) &&
			flyout.contains( event.target )
		) {
			const stop = all( FOCUSABLE, dialog ).find( isShown );

			if ( stop ) {
				stop.focus();
			}
		}
	};

	// Closing the panel under a half-written form asks the same question
	// Cancel does, instead of throwing the form away.
	const guard = ( event ) => {
		const detail = event.detail || {};

		if (
			document.contains( layer ) &&
			detail.element === flyout &&
			! detail.force &&
			typed()
		) {
			event.preventDefault();
			leave();
		}
	};

	document.addEventListener( 'focusin', refocus );
	document.addEventListener( 'wpflyout:closing', guard );
	away.push(
		() => document.removeEventListener( 'focusin', refocus ),
		() => document.removeEventListener( 'wpflyout:closing', guard )
	);

	flyout.classList.add( 'has-prompt' );
	flyout.append( layer );

	( first() || cancel ).focus();

	return dialog;
}
