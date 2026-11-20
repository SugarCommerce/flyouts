/**
 * The form an action asks for: drawn from what the server sent, sent back
 * as `fields`, and never thrown away without asking.
 */

import { beforeAll, beforeEach, describe, expect, test, vi } from 'vitest';

import { close, field, open } from '../../assets/js/prompt/prompt';
import { emit } from '../../assets/js/lib/events';
import { layout } from './support/layout';

beforeAll( layout );

let flyout;
let opener;

const FORM = {
	title: 'Email the customer',
	description: 'They will see this.',
	fields: [
		{ name: 'to', type: 'static', label: 'To', value: 'ada@example.com' },
		{
			name: 'subject',
			type: 'text',
			label: 'Subject',
			required: true,
			maxlength: 200,
			placeholder: 'Hi',
		},
		{
			name: 'tone',
			type: 'select',
			label: 'Tone',
			options: { warm: 'Warm', formal: 'Formal' },
		},
		{
			name: 'count',
			type: 'number',
			label: 'Count',
			min: 1,
			max: '',
			step: 0,
			value: 2,
		},
		{
			name: 'message',
			type: 'textarea',
			label: 'Message',
			required: true,
			description: 'Plain text.',
		},
	],
};

beforeEach( () => {
	document.body.innerHTML =
		'<div class="wp-flyout" id="p"><button id="opener">Email</button><div class="wp-flyout-body"></div></div>';
	flyout = document.getElementById( 'p' );
	opener = document.getElementById( 'opener' );
	opener.focus();
} );

const dialog = () => flyout.querySelector( '.wp-flyout-prompt' );
const control = ( name ) => dialog().querySelector( `[name="${ name }"]` );
const type = ( name, value ) => {
	control( name ).value = value;
	control( name ).dispatchEvent( new Event( 'input', { bubbles: true } ) );
};
const key = ( name, extra = {} ) => {
	const event = new KeyboardEvent( 'keydown', {
		key: name,
		bubbles: true,
		cancelable: true,
		...extra,
	} );

	flyout.ownerDocument.activeElement.dispatchEvent( event );

	return event;
};
const settle = () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

describe( 'the form', () => {
	test( 'is a labelled dialog over the panel, focus on its first field', () => {
		open( flyout, FORM, { submit: vi.fn() } );

		expect( flyout.classList.contains( 'has-prompt' ) ).toBe( true );
		expect( dialog().getAttribute( 'role' ) ).toBe( 'dialog' );
		expect(
			document.getElementById(
				dialog().getAttribute( 'aria-labelledby' )
			).textContent
		).toBe( 'Email the customer' );
		expect(
			document.getElementById(
				dialog().getAttribute( 'aria-describedby' )
			).textContent
		).toBe( 'They will see this.' );
		expect( document.activeElement ).toBe( control( 'subject' ) );
	} );

	test( 'draws each kind of field', () => {
		open( flyout, FORM, { submit: vi.fn() } );

		expect(
			dialog().querySelector( '.wp-flyout-prompt-static' ).textContent
		).toBe( 'ada@example.com' );
		expect( control( 'to' ) ).toBeNull();
		expect( control( 'subject' ).required ).toBe( true );
		expect( control( 'subject' ).getAttribute( 'maxlength' ) ).toBe(
			'200'
		);
		expect( control( 'subject' ).getAttribute( 'placeholder' ) ).toBe(
			'Hi'
		);
		expect(
			Array.from( control( 'tone' ).options ).map( ( o ) => [
				o.value,
				o.text,
			] )
		).toEqual( [
			[ 'warm', 'Warm' ],
			[ 'formal', 'Formal' ],
		] );
		expect( control( 'count' ).type ).toBe( 'number' );
		expect( control( 'count' ).getAttribute( 'min' ) ).toBe( '1' );
		expect( control( 'count' ).hasAttribute( 'max' ) ).toBe( false );
		expect( control( 'count' ).getAttribute( 'step' ) ).toBe( '0' );
		expect( control( 'count' ).value ).toBe( '2' );
		expect( control( 'message' ).getAttribute( 'rows' ) ).toBe( '6' );
		expect(
			document.getElementById(
				control( 'message' ).getAttribute( 'aria-describedby' )
			).textContent
		).toBe( 'Plain text.' );
	} );

	test( 'a select starts on its value', () => {
		const select = field(
			{
				name: 't',
				type: 'select',
				options: { a: 'A', b: 'B' },
				value: 'b',
			},
			'f'
		).querySelector( 'select' );

		expect( select.value ).toBe( 'b' );
	} );

	test( 'a type it does not know is a line of text; a label is text, not markup', () => {
		const wrap = field(
			{ name: 'x', type: 'colour', label: '<b>X</b>' },
			'f'
		);

		expect( wrap.querySelector( 'input' ).type ).toBe( 'text' );
		expect( wrap.querySelector( 'label' ).textContent ).toBe( '<b>X</b>' );
		expect( wrap.querySelector( 'label' ).htmlFor ).toBe( 'f' );
	} );
} );

describe( 'sending', () => {
	test( 'a required field left empty says so and sends nothing', () => {
		const submit = vi.fn();

		open( flyout, FORM, { submit, strings: { promptEmpty: 'Fill it.' } } );
		dialog().querySelector( '.wp-flyout-prompt-submit' ).click();

		expect( submit ).not.toHaveBeenCalled();
		expect(
			dialog().querySelectorAll( '.wp-flyout-prompt-field-error' )
		).toHaveLength( 2 );
		expect( control( 'subject' ).getAttribute( 'aria-invalid' ) ).toBe(
			'true'
		);
		expect( document.activeElement ).toBe( control( 'subject' ) );

		type( 'subject', 'H' );
		expect( control( 'subject' ).hasAttribute( 'aria-invalid' ) ).toBe(
			false
		);
		expect(
			dialog().querySelectorAll( '.wp-flyout-prompt-field-error' )
		).toHaveLength( 1 );
	} );

	test( 'Cmd and Enter sends every field but the static one, then it closes and focus goes back', async () => {
		const submit = vi.fn( () => Promise.resolve( { success: true } ) );
		const done = vi.fn();

		open( flyout, FORM, { submit, done } );
		type( 'subject', 'Hello' );
		type( 'message', 'Body' );
		key( 'Enter', { metaKey: true } );

		// The select was given no value, so nothing is chosen in it.
		expect( submit ).toHaveBeenCalledWith( {
			subject: 'Hello',
			tone: null,
			count: '2',
			message: 'Body',
		} );
		expect( dialog().getAttribute( 'aria-busy' ) ).toBe( 'true' );
		// Pressed again while it is out: nothing.
		key( 'Enter', { ctrlKey: true } );
		expect( submit ).toHaveBeenCalledTimes( 1 );

		await settle();

		expect( dialog() ).toBeNull();
		expect( flyout.classList.contains( 'has-prompt' ) ).toBe( false );
		expect( done ).toHaveBeenCalledWith( { success: true } );
		expect( document.activeElement ).toBe( opener );
	} );

	test( 'a refusal that names a field is said under it; one that does not, at the top', async () => {
		const answers = [
			{
				success: false,
				message: 'Not that word.',
				data: { field: 'subject' },
			},
			{ success: false, message: 'Mail is down.' },
		];

		open( flyout, FORM, {
			submit: () => Promise.resolve( answers.shift() ),
		} );
		type( 'subject', 'spam' );
		type( 'message', 'Body' );
		key( 'Enter', { metaKey: true } );
		await settle();

		expect(
			dialog().querySelector( '.wp-flyout-prompt-field-error' )
				.textContent
		).toBe( 'Not that word.' );
		expect( document.activeElement ).toBe( control( 'subject' ) );
		expect(
			dialog().querySelector( '.wp-flyout-prompt-error' ).hidden
		).toBe( true );

		key( 'Enter', { metaKey: true } );
		await settle();

		expect(
			dialog().querySelector( '.wp-flyout-prompt-field-error' )
		).toBeNull();
		expect(
			dialog().querySelector( '.wp-flyout-prompt-error' ).hidden
		).toBe( false );
		expect(
			dialog().querySelector( '.wp-flyout-prompt-error' ).textContent
		).toBe( 'Mail is down.' );
		expect(
			dialog()
				.querySelector( '.wp-flyout-prompt-submit' )
				.classList.contains( 'is-busy' )
		).toBe( false );
	} );

	test( 'a request that fails outright is said at the top', async () => {
		open( flyout, FORM, {
			submit: () => Promise.reject( new Error( 'Offline.' ) ),
		} );
		type( 'subject', 'A' );
		type( 'message', 'B' );
		key( 'Enter', { metaKey: true } );
		await settle();

		expect(
			dialog().querySelector( '.wp-flyout-prompt-error' ).textContent
		).toBe( 'Offline.' );
	} );
} );

describe( 'leaving', () => {
	test( 'with nothing typed, Escape closes it, and only it', () => {
		open( flyout, FORM, { submit: vi.fn() } );

		const event = key( 'Escape' );

		expect( event.defaultPrevented ).toBe( true );
		expect( dialog() ).toBeNull();
	} );

	test( 'with something typed, it asks; Keep Writing keeps it, Discard drops it', () => {
		const submit = vi.fn( () => Promise.resolve( { success: true } ) );

		open( flyout, FORM, {
			submit,
			strings: { keepWriting: 'Keep', promptDrop: 'Drop' },
		} );
		type( 'subject', 'Half a' );
		dialog().querySelector( '.wp-flyout-prompt-cancel' ).click();

		const ask = dialog().querySelector( '.wp-flyout-prompt-ask' );

		expect( ask.hidden ).toBe( false );
		expect(
			dialog().querySelector( '.wp-flyout-prompt-actions' ).hidden
		).toBe( true );
		expect( document.activeElement.textContent ).toBe( 'Keep' );

		// Escape while it asks is Keep Writing.
		key( 'Escape' );
		expect( ask.hidden ).toBe( true );
		expect( document.activeElement ).toBe( control( 'subject' ) );

		// Sending is not possible while it asks.
		type( 'message', 'Body' );
		dialog().querySelector( '.wp-flyout-prompt-cancel' ).click();
		dialog()
			.querySelector( 'form' )
			.dispatchEvent( new Event( 'submit', { cancelable: true } ) );
		expect( submit ).not.toHaveBeenCalled();
		ask.querySelectorAll( 'button' )[ 1 ].click();

		expect( dialog() ).toBeNull();
	} );

	test( 'a press on the dimmed panel around it is a way out', () => {
		open( flyout, FORM, { submit: vi.fn() } );
		flyout
			.querySelector( '.wp-flyout-prompt-layer' )
			.dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true } ) );

		expect( dialog() ).toBeNull();
	} );

	test( 'closing the panel under a half-written form asks instead', () => {
		open( flyout, FORM, { submit: vi.fn() } );
		type( 'subject', 'Half' );

		const closing = ( force ) =>
			emit( document, 'wpflyout:closing', {
				detail: { id: 'p', element: flyout, force },
				cancelable: true,
			} );

		expect( closing( false ) ).toBe( false );
		expect( dialog().querySelector( '.wp-flyout-prompt-ask' ).hidden ).toBe(
			false
		);
		expect( closing( true ) ).toBe( true );
	} );

	test( 'once it has gone, it no longer stands in the way', () => {
		open( flyout, FORM, { submit: vi.fn() } );
		type( 'subject', 'Half' );
		close( flyout );

		expect( flyout.classList.contains( 'has-prompt' ) ).toBe( false );
		expect(
			emit( document, 'wpflyout:closing', {
				detail: { id: 'p', element: flyout },
				cancelable: true,
			} )
		).toBe( true );
	} );
} );

describe( 'focus', () => {
	test( 'Tab goes round inside it and is not the panel’s', () => {
		const outside = vi.fn();

		open( flyout, FORM, { submit: vi.fn() } );
		document.addEventListener( 'keydown', outside );

		const submit = dialog().querySelector( '.wp-flyout-prompt-submit' );

		submit.focus();
		expect( key( 'Tab' ).defaultPrevented ).toBe( true );
		expect( document.activeElement ).toBe( control( 'subject' ) );
		expect( key( 'Tab', { shiftKey: true } ).defaultPrevented ).toBe(
			true
		);
		expect( document.activeElement ).toBe( submit );
		expect( outside ).not.toHaveBeenCalled();
		document.removeEventListener( 'keydown', outside );
	} );

	test( 'focus that gets out into the panel is brought back', () => {
		open( flyout, FORM, { submit: vi.fn() } );
		opener.focus();

		expect( document.activeElement ).toBe( control( 'subject' ) );
	} );

	test( 'a form with no fields to fill starts on Cancel', () => {
		open(
			flyout,
			{
				title: 'Sure?',
				fields: [ { name: 'x', type: 'static', value: 'y' } ],
			},
			{ submit: vi.fn() }
		);

		expect(
			document.activeElement.classList.contains(
				'wp-flyout-prompt-cancel'
			)
		).toBe( true );
	} );
} );
