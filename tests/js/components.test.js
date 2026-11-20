/**
 * The components' scripts: action buttons, the note box, the image
 * picker and the refund form, against a stand-in for the REST routes.
 */

import jQuery from 'jquery';
import {
	afterEach,
	beforeAll,
	beforeEach,
	describe,
	expect,
	test,
	vi,
} from 'vitest';

import { bindActionButtons } from '../../assets/js/components/action-buttons';
import { bindActivity } from '../../assets/js/components/activity';
import {
	bindImagePickers,
	clearImage,
	setImage,
	urlFor,
} from '../../assets/js/components/image-picker';
import {
	bindRefundForms,
	refund,
} from '../../assets/js/components/refund-form';
import { reload } from '../../assets/js/lib/page';
import { stash } from '../../assets/js/lib/store';
import { layout } from './support/layout';
import { flush, publish, server } from './support/server';

const rest = vi.hoisted( () => ( { fetch: null } ) );

vi.mock( '@wordpress/api-fetch', () => ( {
	default: ( options ) => rest.fetch( options ),
} ) );

vi.mock( '../../assets/js/lib/page', () => ( {
	reload: vi.fn(),
	go: vi.fn(),
} ) );

const media = vi.hoisted( () => ( { frames: [] } ) );

let routes;
let heard;

beforeAll( () => {
	layout();
	bindActionButtons();
	bindActivity();
	bindImagePickers( () => ( options ) => {
		const handlers = {};
		const frame = {
			options,
			opened: 0,
			on: ( name, handler ) => ( handlers[ name ] = handler ),
			open: () => frame.opened++,
			close: vi.fn(),
			state: () => ( {
				get: () => ( {
					first: () => ( { toJSON: () => frame.chosen } ),
				} ),
			} ),
			choose( attachment ) {
				frame.chosen = attachment;
				handlers.select();
			},
		};

		media.frames.push( frame );

		return frame;
	} );
	bindRefundForms();
} );

beforeEach( () => {
	vi.useFakeTimers();
	publish();
	routes = server();
	rest.fetch = routes.fetch;
	reload.mockClear();
	window.jQuery = jQuery;
	heard = [];
	jQuery( document ).on(
		'actionbuttons:success actionbuttons:error wpflyout:note-added image-picker:changed image-picker:removed',
		( event, ...args ) => heard.push( [ event.type, ...args ] )
	);
} );

afterEach( () => {
	jQuery( document ).off();
	delete window.jQuery;
	vi.useRealTimers();
} );

// A panel the manager opened: its configuration is where the manager keeps it.
const panel = ( body ) => {
	document.body.innerHTML = `<div class="wp-flyout" id="p"><div class="wp-flyout-body"><form><input type="hidden" name="id" value="7">${ body }</form></div></div>`;

	const flyout = document.getElementById( 'p' );

	stash( flyout ).config = {
		manager: 'shop',
		flyout: 'order',
		data: { id: '7' },
	};

	return flyout;
};

describe( 'action buttons', () => {
	test( 'run the action, say how it went, and redraw what it names', async () => {
		routes.answer( '/action', {
			success: true,
			message: 'Resent.',
			updates: { '.where': '<i>now</i>' },
		} );
		const flyout = panel(
			'<p class="where">then</p><button class="wp-flyout-action-btn" data-action="resend">Resend <span class="spinner"></span></button>'
		);
		const button = flyout.querySelector( '.wp-flyout-action-btn' );

		button.click();
		expect( button.disabled ).toBe( true );
		expect(
			button.querySelector( '.spinner' ).classList.contains( 'is-active' )
		).toBe( true );
		await flush( vi, 200 );

		expect( routes.calls[ 0 ].body ).toEqual( {
			manager: 'shop',
			flyout: 'order',
			action_key: 'resend',
			item_id: '7',
		} );
		expect(
			flyout.querySelector(
				'.wp-flyout-alert.alert-success .alert-message'
			).textContent
		).toBe( 'Resent.' );
		expect( flyout.querySelector( '.where' ).innerHTML ).toBe(
			'<i>now</i>'
		);
		expect( button.disabled ).toBe( false );
		expect( heard ).toEqual( [
			[
				'actionbuttons:success',
				{
					success: true,
					message: 'Resent.',
					updates: { '.where': '<i>now</i>' },
				},
			],
		] );
	} );

	test( 'ask first when they have a question, and do nothing on no', () => {
		const confirm = vi.spyOn( window, 'confirm' ).mockReturnValue( false );
		const flyout = panel(
			'<button class="wp-flyout-action-btn" data-action="complete" data-confirm="Sure?">Go</button>'
		);

		flyout.querySelector( 'button' ).click();

		expect( confirm ).toHaveBeenCalledWith( 'Sure?' );
		expect( routes.calls ).toEqual( [] );
		confirm.mockRestore();
	} );

	test( 'a refusal is said, and heard as an error', async () => {
		routes.answer( '/action', { success: false, message: 'Nope.' } );
		const flyout = panel(
			'<button class="wp-flyout-action-btn" data-action="x">Go</button>'
		);

		flyout.querySelector( 'button' ).click();
		await flush( vi, 200 );

		expect(
			flyout.querySelector( '.alert-error .alert-message' ).textContent
		).toBe( 'Nope.' );
		expect( heard ).toEqual( [ [ 'actionbuttons:error', 'Nope.' ] ] );
	} );

	test( 'reload loads the page again, after a moment to read the answer', async () => {
		routes.answer( '/action', { success: true, reload: true } );
		const flyout = panel(
			'<button class="wp-flyout-action-btn" data-action="x">Go</button>'
		);

		flyout.querySelector( 'button' ).click();
		await flush( vi, 1400 );
		expect( reload ).not.toHaveBeenCalled();
		await flush( vi, 200 );
		expect( reload ).toHaveBeenCalledTimes( 1 );
	} );
} );

describe( 'the note box', () => {
	const feed = () =>
		panel(
			'<div class="wp-flyout-activity"><textarea class="wp-flyout-activity-input"></textarea><button class="wp-flyout-activity-add" data-action="add_note">Add</button><div class="feed">old</div></div>'
		);

	test( 'sends the note, draws the feed it is answered with, and empties the box', async () => {
		routes.answer( '/action', {
			success: true,
			message: 'Noted.',
			updates: { '.feed': '<b>new</b>' },
		} );
		const flyout = feed();
		const input = flyout.querySelector( 'textarea' );

		input.value = '  Called them.  ';
		input.dispatchEvent(
			new KeyboardEvent( 'keydown', {
				key: 'Enter',
				metaKey: true,
				bubbles: true,
				cancelable: true,
			} )
		);
		await flush( vi, 200 );

		expect( routes.calls[ 0 ].body ).toEqual( {
			manager: 'shop',
			flyout: 'order',
			action_key: 'add_note',
			item_id: '7',
			content: 'Called them.',
		} );
		expect( flyout.querySelector( '.feed' ).innerHTML ).toBe(
			'<b>new</b>'
		);
		expect( input.value ).toBe( '' );
		expect( document.activeElement ).toBe( input );
		expect( flyout.querySelector( '.wp-flyout-toast' ).textContent ).toBe(
			'Noted.'
		);
		expect( heard ).toEqual( [
			[
				'wpflyout:note-added',
				{
					success: true,
					message: 'Noted.',
					updates: { '.feed': '<b>new</b>' },
				},
			],
		] );
	} );

	test( 'Enter alone is a new line, and an empty note sends nothing', () => {
		const flyout = feed();
		const input = flyout.querySelector( 'textarea' );

		input.value = 'x';
		input.dispatchEvent(
			new KeyboardEvent( 'keydown', {
				key: 'Enter',
				bubbles: true,
				cancelable: true,
			} )
		);
		input.value = '   ';
		flyout.querySelector( '.wp-flyout-activity-add' ).click();

		expect( routes.calls ).toEqual( [] );
	} );

	test( 'a refusal is said in the panel', async () => {
		routes.answer( '/action', { success: false, message: 'Too long.' } );
		const flyout = feed();

		flyout.querySelector( 'textarea' ).value = 'x';
		flyout.querySelector( '.wp-flyout-activity-add' ).click();
		await flush( vi, 200 );

		expect(
			flyout.querySelector( '.alert-error .alert-message' ).textContent
		).toBe( 'Too long.' );
		expect(
			flyout
				.querySelector( '.wp-flyout-activity-add' )
				.classList.contains( 'is-busy' )
		).toBe( false );
	} );
} );

describe( 'the image picker', () => {
	const picker = ( attributes = '' ) => {
		document.body.innerHTML = `<div class="image-picker" data-size="medium" ${ attributes }><div class="image-picker-preview"></div><div class="image-picker-overlay"><button data-action="select-image">+</button></div><input class="image-picker-value" value="0"></div>`;

		return document.querySelector( '.image-picker' );
	};

	test( 'its size, else the thumbnail, else the full image', () => {
		const sizes = { medium: { url: 'm' }, thumbnail: { url: 't' } };

		expect( urlFor( { url: 'f', sizes }, 'medium' ) ).toBe( 'm' );
		expect( urlFor( { url: 'f', sizes }, 'large' ) ).toBe( 't' );
		expect( urlFor( { url: 'f' }, 'medium' ) ).toBe( 'f' );
	} );

	test( 'opens one media frame per picker, and shows and holds what is chosen', () => {
		const element = picker();
		const select = element.querySelector( '[data-action="select-image"]' );

		select.click();
		select.click();

		expect( media.frames ).toHaveLength( 1 );
		expect( media.frames[ 0 ].opened ).toBe( 2 );
		expect( media.frames[ 0 ].options ).toMatchObject( {
			library: { type: 'image' },
			multiple: false,
			title: 'Select Image',
		} );

		media.frames[ 0 ].choose( {
			id: 42,
			url: 'full.jpg',
			sizes: { medium: { url: 'mid.jpg"onerror="x' } },
		} );

		expect( element.querySelector( '.image-picker-value' ).value ).toBe(
			'42'
		);
		expect( element.querySelector( 'img' ).getAttribute( 'src' ) ).toBe(
			'mid.jpg"onerror="x'
		);
		expect( element.querySelector( 'img' ).hasAttribute( 'onerror' ) ).toBe(
			false
		);
		expect( element.classList.contains( 'has-image' ) ).toBe( true );
		expect(
			element.querySelector( '[data-action="remove-image"]' )
		).not.toBeNull();
		expect( media.frames[ 0 ].close ).toHaveBeenCalled();
		expect( heard ).toEqual( [
			[
				'image-picker:changed',
				{ attachmentId: 42, url: 'mid.jpg"onerror="x' },
			],
		] );
	} );

	test( 'removing goes back to the placeholder the server drew', () => {
		const element = picker(
			'data-placeholder="<svg class=&quot;p&quot;></svg>"'
		);

		setImage( element, { id: 3, url: 'a.jpg' } );
		element.querySelector( '[data-action="remove-image"]' ).click();

		expect( element.querySelector( '.image-picker-value' ).value ).toBe(
			'0'
		);
		expect(
			element.querySelector( '.image-picker-placeholder svg.p' )
		).not.toBeNull();
		expect( element.classList.contains( 'has-image' ) ).toBe( false );
		expect(
			element.querySelector( '[data-action="remove-image"]' )
		).toBeNull();
		expect( heard.map( ( [ type ] ) => type ) ).toEqual( [
			'image-picker:changed',
			'image-picker:removed',
		] );
	} );

	test( 'or to the fallback image, marked as one', () => {
		const element = picker( 'data-fallback-image="fallback.jpg"' );

		setImage( element, { id: 3, url: 'a.jpg' } );
		clearImage( element );

		expect(
			element
				.querySelector( 'img.image-picker-fallback' )
				.getAttribute( 'src' )
		).toBe( 'fallback.jpg' );
		expect( element.classList.contains( 'is-fallback' ) ).toBe( true );
		expect( element.querySelector( '.image-picker-value' ).value ).toBe(
			'0'
		);
	} );
} );

describe( 'the refund form', () => {
	const form = ( decimals = 2, currency = 'USD', refundable = 10000 ) => {
		const flyout = panel(
			`<div class="wp-flyout-refund-form" data-action="refund" data-currency="${ currency }" data-locale="en-US" data-decimals="${ decimals }" data-refundable="${ refundable }" data-error-empty="Enter an amount." data-error-exceeds="Too much.">
				<button type="button" class="refund-trigger">Refund</button>
				<div class="refund-panel" style="display: none;">
					<input class="refund-amount-input">
					<select class="refund-reason-select"><option value="duplicate">Duplicate</option><option value="other">Other</option></select>
					<div class="refund-custom-reason" style="display: none;"><input class="refund-custom-input"></div>
					<button type="button" class="refund-cancel">Cancel</button>
					<button type="button" class="refund-submit" data-template="Refund %s"><span class="button-text">Refund</span><span class="button-spinner" style="display: none;">…</span></button>
				</div>
			</div>`
		);

		return flyout.querySelector( '.wp-flyout-refund-form' );
	};

	const amount = ( element, value ) => {
		const input = element.querySelector( '.refund-amount-input' );

		input.value = value;
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	};

	test.each( [
		[ 2, 'USD', '12.34', 1234 ],
		[ 0, 'JPY', '500', 500 ],
		[ 3, 'KWD', '1.5', 1500 ],
	] )(
		'%s decimals: %s %s is %s minor units',
		( decimals, currency, typed, minor ) => {
			const element = form( decimals, currency, 100000 );

			amount( element, typed );

			expect( refund( element ) ).toMatchObject( {
				amount: minor,
				currency,
			} );
		}
	);

	test( 'nothing, or more than can be refunded, is refused with the form’s words', () => {
		const element = form( 2, 'USD', 500 );

		amount( element, '0' );
		expect( refund( element ) ).toEqual( { error: 'Enter an amount.' } );
		amount( element, '5.01' );
		expect( refund( element ) ).toEqual( { error: 'Too much.' } );
		amount( element, '5' );
		expect( refund( element ).amount ).toBe( 500 );
	} );

	test( 'Other sends its own words as custom_reason, and no reason', () => {
		const element = form();

		amount( element, '1' );
		element.querySelector( '.refund-reason-select' ).value = 'other';
		element.querySelector( '.refund-custom-input' ).value = 'Changed mind';

		expect( refund( element ) ).toEqual( {
			amount: 100,
			currency: 'USD',
			reason: '',
			custom_reason: 'Changed mind',
		} );
	} );

	test( 'the panel opens onto the amount, the button says how much, Other shows its box', async () => {
		const element = form();
		const panelOf = element.querySelector( '.refund-panel' );

		element.querySelector( '.refund-trigger' ).click();
		await flush( vi, 200 );
		expect( panelOf.style.display ).not.toBe( 'none' );
		expect( document.activeElement ).toBe(
			element.querySelector( '.refund-amount-input' )
		);

		amount( element, '12.5' );
		expect( element.querySelector( '.button-text' ).textContent ).toBe(
			'Refund $12.50'
		);

		const reason = element.querySelector( '.refund-reason-select' );

		reason.value = 'other';
		reason.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		await flush( vi, 200 );
		expect(
			element.querySelector( '.refund-custom-reason' ).style.display
		).not.toBe( 'none' );

		element.querySelector( '.refund-cancel' ).click();
		await flush( vi, 200 );
		expect( panelOf.style.display ).toBe( 'none' );
	} );

	test( 'sends the refund, and says how it went', async () => {
		routes.answer( '/action', {
			success: true,
			message: 'Refunded.',
			reload: true,
		} );
		const element = form();
		const submit = element.querySelector( '.refund-submit' );

		amount( element, '2' );
		submit.click();
		expect( submit.disabled ).toBe( true );
		expect( submit.querySelector( '.button-text' ).style.display ).toBe(
			'none'
		);
		await flush( vi, 200 );

		expect( routes.calls[ 0 ].body ).toEqual( {
			manager: 'shop',
			flyout: 'order',
			action_key: 'refund',
			item_id: '7',
			amount: 200,
			currency: 'USD',
			reason: 'duplicate',
			custom_reason: '',
		} );
		expect(
			document.querySelector( '.alert-success .alert-message' )
				.textContent
		).toBe( 'Refunded.' );
		expect( submit.disabled ).toBe( false );
		expect( submit.querySelector( '.button-spinner' ).style.display ).toBe(
			'none'
		);
		await flush( vi, 1500 );
		expect( reload ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'a refusal before anything is sent is said in the panel', () => {
		const element = form();

		element.querySelector( '.refund-submit' ).click();

		expect( routes.calls ).toEqual( [] );
		expect(
			document.querySelector( '.alert-error .alert-message' ).textContent
		).toBe( 'Enter an amount.' );
	} );
} );
