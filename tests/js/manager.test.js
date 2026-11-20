/**
 * The manager: a trigger loads a record into a panel, which asks before it
 * throws a change away, saves, runs its actions and steps to its
 * neighbours. Against a stand-in for the REST routes (support/server.js),
 * with jQuery on the page as it is in the admin, listening the way the
 * plugins that use the panels do.
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

import { init } from '../../assets/js/manager/init';
import {
	keyIsTaken,
	neighbours,
	position,
} from '../../assets/js/manager/steps';
import { bindAlerts } from '../../assets/js/lib/alert';
import { reload, go } from '../../assets/js/lib/page';
import { watchEscape } from '../../assets/js/panel/escape';
import panel from '../../assets/js/panel/panel';
import { layout } from './support/layout';
import { panel as drawn, trigger } from './support/panels';
import { flush, publish, server } from './support/server';

const rest = vi.hoisted( () => ( { fetch: null } ) );

vi.mock( '@wordpress/api-fetch', () => ( {
	default: ( options ) => rest.fetch( options ),
} ) );

vi.mock( '../../assets/js/lib/page', () => ( {
	reload: vi.fn(),
	go: vi.fn(),
} ) );

let routes;
let heard;

beforeAll( () => {
	layout();
	watchEscape();
	panel.init();
	init();
	bindAlerts();
	window.jQuery = jQuery;
} );

beforeEach( () => {
	vi.useFakeTimers();
	publish();
	routes = server();
	rest.fetch = routes.fetch;
	routes.answer( '/load', ( body ) => ( {
		success: true,
		html: drawn( { id: String( body.item_id ) } ),
	} ) );
	panel.active = [];
	panel.openers = {};
	document.body.innerHTML = `<table>${ [ 7, 8, 9 ]
		.map( ( id ) => `<tr><td>${ trigger( id ) }</td></tr>` )
		.join( '' ) }</table>${ trigger( '' ) }`;

	// Listening the way SugarCart and the fields package do.
	heard = [];
	jQuery( document ).on(
		'wpflyout:opened wpflyout:closed wpflyout:dirty wpflyout:row-action wpflyout:menu-action wpflyout:prompt-action',
		function ( event, ...args ) {
			heard.push( [ event.type, event.target, ...args ] );
		}
	);
	reload.mockClear();
	go.mockClear();
} );

afterEach( () => {
	jQuery( document ).off();
	vi.runOnlyPendingTimers();
	vi.useRealTimers();
} );

const open = async ( id = 7 ) => {
	document.querySelector( `.wp-flyout-trigger[data-id="${ id }"]` ).click();
	await flush( vi );

	return document.getElementById( `wp-flyout-order-${ id }` );
};

const type = ( input, value ) => {
	input.value = value;
	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
};

const heardOf = ( wanted ) => heard.filter( ( [ name ] ) => name === wanted );

describe( 'opening', () => {
	test( 'a trigger shows a placeholder at once, then the record in its place', async () => {
		document.querySelector( '.wp-flyout-trigger[data-id="8"]' ).click();

		const placeholder = document.querySelector( '.wp-flyout-loading' );

		expect( placeholder.classList.contains( 'wp-flyout-large' ) ).toBe(
			true
		);
		expect(
			placeholder.querySelector( '.wp-flyout-loading-text' ).textContent
		).toBe( 'Loading…' );
		expect(
			placeholder.querySelectorAll( '.wp-flyout-step' )
		).toHaveLength( 2 );

		await flush( vi );

		const flyout = document.getElementById( 'wp-flyout-order-8' );

		expect( document.querySelector( '.wp-flyout-loading' ) ).toBeNull();
		expect( flyout.classList.contains( 'active' ) ).toBe( true );
		expect( routes.calls[ 0 ].body ).toEqual( {
			manager: 'shop',
			flyout: 'order',
			item_id: '8',
		} );
		expect(
			flyout
				.querySelector( '.wp-flyout-close' )
				.getAttribute( 'aria-label' )
		).toBe( 'Close' );
		expect( flyout.querySelector( '.wp-flyout-prev' ).disabled ).toBe(
			false
		);
		expect( flyout.querySelector( '.wp-flyout-next' ).disabled ).toBe(
			false
		);
		expect(
			flyout.querySelector( ':scope > .wp-flyout-toast[role="status"]' )
		).not.toBeNull();
		// Nothing changed yet, on a record that exists.
		expect( flyout.querySelector( '.wp-flyout-save' ).disabled ).toBe(
			true
		);
		expect(
			heardOf( 'wpflyout:opened' ).map( ( [ , , detail ] ) => detail.id )
		).toContain( 'wp-flyout-order-8' );
	} );

	test( 'a new record’s Save is live from the start, and it has no arrows', async () => {
		routes.answer( '/load', { success: true, html: drawn( { id: '' } ) } );
		document.querySelector( '.wp-flyout-trigger:not([data-id])' ).click();
		await flush( vi );

		const flyout = document.getElementById( 'wp-flyout-order-' );

		expect( flyout.querySelector( '.wp-flyout-save' ).disabled ).toBe(
			false
		);
		expect( flyout.querySelector( '.wp-flyout-step' ) ).toBeNull();
		expect( routes.calls[ 0 ].body.item_id ).toBe( 0 );
	} );

	test( 'a record that will not load says so in the panel', async () => {
		routes.refuse( '/load', {
			code: 'nope',
			message: 'Not yours.',
			data: { status: 403 },
		} );
		document.querySelector( '.wp-flyout-trigger[data-id="7"]' ).click();
		await flush( vi );

		const flyout = document.querySelector( '.wp-flyout' );

		expect( flyout.querySelector( '.wp-flyout-title' ).textContent ).toBe(
			'This could not be opened'
		);
		expect(
			flyout.querySelector( '.wp-flyout-alert .alert-message' )
				.textContent
		).toBe( 'Not yours.' );
		expect( document.activeElement ).toBe(
			flyout.querySelector( '.wp-flyout-close' )
		);
	} );

	test( 'an answer overtaken by a newer question is dropped', async () => {
		let first;

		rest.fetch = ( options ) =>
			JSON.parse( options.body ).item_id === '7'
				? new Promise( ( resolve ) => ( first = resolve ) )
				: routes.fetch( options );

		document.querySelector( '.wp-flyout-trigger[data-id="7"]' ).click();
		document.querySelector( '.wp-flyout-trigger[data-id="9"]' ).click();
		await flush( vi );
		first( { success: true, html: drawn( { id: '7' } ) } );
		await flush( vi );

		expect( document.getElementById( 'wp-flyout-order-7' ) ).toBeNull();
		expect( document.getElementById( 'wp-flyout-order-9' ) ).not.toBeNull();
	} );

	test( 'the panel’s scripts are made again so they run, and its templates are left alone', async () => {
		routes.answer( '/load', {
			success: true,
			html: drawn( {
				extra: '<script class="run" data-x="1">window.ranByPanel = 1;</script><script type="text/template" class="tpl">{{name}}</script>',
			} ),
		} );

		const made = vi.spyOn( document, 'createElement' );

		await open();

		const scripts = made.mock.results
			.map( ( result ) => result.value )
			.filter( ( element ) => 'SCRIPT' === element.tagName );

		made.mockRestore();

		// jsdom runs no scripts; a browser runs one that is made, not parsed.
		expect( scripts ).toHaveLength( 1 );
		expect( scripts[ 0 ].isConnected ).toBe( true );
		expect( scripts[ 0 ].className ).toBe( 'run' );
		expect( scripts[ 0 ].dataset.x ).toBe( '1' );
		expect( scripts[ 0 ].textContent ).toBe( 'window.ranByPanel = 1;' );
		expect( document.querySelector( '.tpl' ).textContent ).toBe(
			'{{name}}'
		);
	} );

	test( 'the fields package starts on the markup, and stops when it closes', async () => {
		const fields = { init: vi.fn(), destroy: vi.fn() };

		window.SugarCommerceFields = {
			'sugarcommerce-fields': { modules: fields },
		};
		const flyout = await open();

		expect( fields.init ).toHaveBeenCalledWith( flyout );
		panel.close( flyout.id );
		expect( fields.destroy ).toHaveBeenCalledWith( flyout );
		delete window.SugarCommerceFields;
	} );
} );

describe( 'unsaved changes', () => {
	test( 'a change marks the panel dirty once, and the fields guard hears it', async () => {
		const flyout = await open();

		type( flyout.querySelector( '[name="name"]' ), 'Grace' );
		await flush( vi );
		type( flyout.querySelector( '[name="name"]' ), 'Grace Hopper' );
		await flush( vi );

		expect( flyout.classList.contains( 'is-dirty' ) ).toBe( true );
		expect( flyout.querySelector( '.wp-flyout-save' ).disabled ).toBe(
			false
		);
		expect( heardOf( 'wpflyout:dirty' ) ).toEqual( [
			[ 'wpflyout:dirty', flyout, true ],
		] );

		// Put back as it was: nothing to lose, and the guard is told.
		type( flyout.querySelector( '[name="name"]' ), 'Ada' );
		await flush( vi );

		expect( flyout.classList.contains( 'is-dirty' ) ).toBe( false );
		expect(
			heardOf( 'wpflyout:dirty' ).map( ( [ , , dirty ] ) => dirty )
		).toEqual( [ true, false ] );
	} );

	test( 'closing a changed panel asks; Keep editing keeps it, Discard closes it', async () => {
		const flyout = await open();

		type( flyout.querySelector( '[name="name"]' ), 'Grace' );
		await flush( vi );
		flyout.querySelector( '.wp-flyout-close' ).click();

		const bar = flyout.querySelector( '.wp-flyout-confirm' );

		expect( bar.getAttribute( 'role' ) ).toBe( 'alertdialog' );
		expect(
			bar.nextElementSibling.classList.contains( 'wp-flyout-footer' )
		).toBe( true );
		expect( document.activeElement ).toBe(
			bar.querySelector( '.wp-flyout-confirm-cancel' )
		);
		expect(
			bar
				.querySelector( '.wp-flyout-confirm-accept' )
				.classList.contains( 'field-kit__button--delete' )
		).toBe( true );
		expect( panel.active ).toEqual( [ flyout.id ] );

		bar.querySelector( '.wp-flyout-confirm-cancel' ).click();
		await flush( vi );
		expect( flyout.querySelector( '.wp-flyout-confirm' ) ).toBeNull();
		expect( panel.active ).toEqual( [ flyout.id ] );

		flyout.querySelector( '.wp-flyout-cancel' ).click();
		flyout.querySelector( '.wp-flyout-confirm-accept' ).click();
		await flush( vi );

		expect( panel.active ).toEqual( [] );
		expect( heardOf( 'wpflyout:closed' ) ).toHaveLength( 1 );
	} );

	test( 'Escape answers the question no, and does not close the panel too', async () => {
		const flyout = await open();

		type( flyout.querySelector( '[name="name"]' ), 'Grace' );
		await flush( vi );
		document.dispatchEvent(
			new KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } )
		);

		const cancel = flyout.querySelector( '.wp-flyout-confirm-cancel' );

		cancel.dispatchEvent(
			new KeyboardEvent( 'keydown', {
				key: 'Escape',
				bubbles: true,
				cancelable: true,
			} )
		);
		await flush( vi );

		expect( flyout.querySelector( '.wp-flyout-confirm' ) ).toBeNull();
		expect( panel.active ).toEqual( [ flyout.id ] );
	} );

	test( 'stepping away from a change asks first', async () => {
		const flyout = await open();

		type( flyout.querySelector( '[name="name"]' ), 'Grace' );
		await flush( vi );
		flyout.querySelector( '.wp-flyout-next' ).click();
		await flush( vi );

		expect( routes.calls ).toHaveLength( 1 );
		flyout.querySelector( '.wp-flyout-confirm-accept' ).click();
		await flush( vi );
		expect( document.getElementById( 'wp-flyout-order-8' ) ).not.toBeNull();
	} );

	test( 'a forced close does not ask, and lets go of the panel', async () => {
		const fields = { init: vi.fn(), destroy: vi.fn() };

		window.SugarCommerceFields = {
			'sugarcommerce-fields': { modules: fields },
		};
		const flyout = await open();

		type( flyout.querySelector( '[name="name"]' ), 'Grace' );
		await flush( vi );
		panel.close( flyout.id, { force: true } );

		expect( flyout.querySelector( '.wp-flyout-confirm' ) ).toBeNull();
		expect( fields.destroy ).toHaveBeenCalledWith( flyout );
		delete window.SugarCommerceFields;
	} );

	test( 'an action that asks for the panel again asks first, when there is a change', async () => {
		routes.answer( '/action', { success: true, refresh_flyout: true } );
		const flyout = await open();

		type( flyout.querySelector( '[name="name"]' ), 'Grace' );
		await flush( vi );
		flyout
			.querySelector( 'form' )
			.insertAdjacentHTML(
				'beforeend',
				'<button type="button" class="wp-flyout-row-action" data-action="x">X</button>'
			);
		flyout.querySelector( '.wp-flyout-row-action' ).click();
		await flush( vi );

		expect( routes.calls.map( ( c ) => c.route ) ).toEqual( [
			'/load',
			'/action',
		] );
		expect( flyout.querySelector( '.wp-flyout-confirm' ) ).not.toBeNull();
	} );

	test( 'a link in the menu asks before it leaves a change behind', async () => {
		routes.answer( '/load', {
			success: true,
			html: drawn( {
				menu: '<a class="wp-flyout-menu-item" role="menuitem" href="/elsewhere">Elsewhere</a>',
			} ),
		} );
		const flyout = await open();

		type( flyout.querySelector( '[name="name"]' ), 'Grace' );
		await flush( vi );
		flyout.querySelector( '.wp-flyout-menu-item' ).click();
		flyout.querySelector( '.wp-flyout-confirm-accept' ).click();
		await flush( vi );

		expect( go ).toHaveBeenCalledWith( '/elsewhere' );
	} );
} );

describe( 'saving', () => {
	test( 'a required field left empty is marked and focused, and nothing is sent', async () => {
		const flyout = await open();

		type( flyout.querySelector( '[name="name"]' ), '' );
		await flush( vi );
		flyout.querySelector( '.wp-flyout-save' ).click();

		expect(
			flyout
				.querySelector( '[name="name"]' )
				.classList.contains( 'error' )
		).toBe( true );
		expect( document.activeElement ).toBe(
			flyout.querySelector( '[name="name"]' )
		);
		expect(
			flyout.querySelector( '.wp-flyout-alert .alert-message' )
				.textContent
		).toBe( 'Please fill in all required fields.' );
		expect( routes.calls.map( ( c ) => c.route ) ).toEqual( [ '/load' ] );

		type( flyout.querySelector( '[name="name"]' ), 'G' );
		expect(
			flyout
				.querySelector( '[name="name"]' )
				.classList.contains( 'error' )
		).toBe( false );
	} );

	test( 'sends the form, says Saved, closes and loads the page again', async () => {
		routes.answer( '/save', { success: true } );
		const flyout = await open();

		type( flyout.querySelector( '[name="total"]' ), '12' );
		await flush( vi );
		document.dispatchEvent(
			new KeyboardEvent( 'keydown', {
				key: 's',
				metaKey: true,
				bubbles: true,
				cancelable: true,
			} )
		);

		expect(
			flyout
				.querySelector( '.wp-flyout-save' )
				.classList.contains( 'is-busy' )
		).toBe( true );
		await flush( vi );

		expect( routes.calls[ 1 ] ).toMatchObject( {
			route: '/save',
			body: {
				manager: 'shop',
				flyout: 'order',
				item_id: '7',
				form_data: { id: '7', name: 'Ada', total: '12' },
			},
		} );
		expect( flyout.querySelector( '.wp-flyout-toast' ).textContent ).toBe(
			'Saved'
		);
		expect( reload ).toHaveBeenCalledTimes( 1 );
		// Closed whatever was asked: it was saved.
		expect( panel.active ).toEqual( [] );
		expect(
			heardOf( 'wpflyout:dirty' ).map( ( [ , , dirty ] ) => dirty )
		).toEqual( [ true, false ] );
	} );

	test( 'an error that names a field is said under it, in its tab', async () => {
		routes.refuse( '/save', {
			code: 'too_low',
			message: 'A total cannot be negative.',
			data: { status: 400, field: 'total' },
		} );
		const flyout = await open();
		const total = flyout.querySelector( '[name="total"]' );

		total.setAttribute( 'aria-describedby', 'note' );
		type( total, '-1' );
		await flush( vi );
		flyout.querySelector( '.wp-flyout-save' ).click();
		await flush( vi );

		const error = flyout.querySelector( '.wp-flyout-field-error' );

		expect( error.textContent ).toBe( 'A total cannot be negative.' );
		expect( error.getAttribute( 'role' ) ).toBe( 'alert' );
		expect( total.getAttribute( 'aria-invalid' ) ).toBe( 'true' );
		expect( total.getAttribute( 'aria-describedby' ) ).toBe(
			'note ' + error.id
		);
		expect( document.activeElement ).toBe( total );
		expect( flyout.querySelector( '.wp-flyout-alert' ) ).toBeNull();
		expect( reload ).not.toHaveBeenCalled();

		type( total, '1' );
		expect( flyout.querySelector( '.wp-flyout-field-error' ) ).toBeNull();
		expect( total.getAttribute( 'aria-describedby' ) ).toBe( 'note' );
		expect( total.hasAttribute( 'aria-invalid' ) ).toBe( false );
	} );

	test( 'an error inside the fields package’s wrapper goes inside it; an unknown field goes to the top', async () => {
		routes.answer( '/save', {
			success: false,
			message: 'No.',
			data: { fields: { name: 'Who?', nowhere: 'Lost.' } },
		} );
		const flyout = await open();

		type( flyout.querySelector( '[name="total"]' ), '3' );
		await flush( vi );
		flyout.querySelector( '.wp-flyout-save' ).click();
		await flush( vi );

		expect(
			flyout.querySelector( '.field-kit__field > .wp-flyout-field-error' )
				.textContent
		).toBe( 'Who?' );
		expect(
			flyout.querySelector( '.wp-flyout-alert .alert-message' )
				.textContent
		).toBe( 'Lost.' );
	} );

	test( 'what was typed while a save was on its way is still a change', async () => {
		let answer;

		rest.fetch = ( options ) =>
			options.url.endsWith( '/save' )
				? new Promise( ( resolve ) => ( answer = resolve ) )
				: routes.fetch( options );
		const flyout = await open();

		type( flyout.querySelector( '[name="total"]' ), '12' );
		await flush( vi );
		flyout.querySelector( '.wp-flyout-save' ).click();
		type( flyout.querySelector( '[name="total"]' ), '13' );
		answer( { success: true } );
		await vi.advanceTimersByTimeAsync( 200 );

		expect( flyout.classList.contains( 'is-dirty' ) ).toBe( true );
	} );

	test( 'a named field’s own message wins over the error’s', async () => {
		routes.refuse( '/save', {
			code: 'bad',
			message: 'Something is wrong.',
			data: {
				status: 400,
				field: 'total',
				fields: { total: 'Too high.' },
			},
		} );
		const flyout = await open();

		type( flyout.querySelector( '[name="total"]' ), '99' );
		await flush( vi );
		flyout.querySelector( '.wp-flyout-save' ).click();
		await flush( vi );

		expect(
			flyout.querySelector( '.wp-flyout-field-error' ).textContent
		).toBe( 'Too high.' );
	} );

	test( 'Delete asks, then deletes and loads the page again', async () => {
		routes.answer( '/delete', { success: true, message: 'Gone.' } );
		const flyout = await open();

		flyout.querySelector( '.wp-flyout-delete' ).click();
		expect(
			flyout.querySelector( '.wp-flyout-confirm-title' ).textContent
		).toBe( 'Are you sure you want to delete this item?' );
		flyout.querySelector( '.wp-flyout-confirm-accept' ).click();
		await flush( vi );

		expect( routes.calls[ 1 ] ).toMatchObject( {
			route: '/delete',
			body: { item_id: '7' },
		} );
		expect( reload ).toHaveBeenCalledTimes( 1 );
	} );
} );

describe( 'actions', () => {
	test( 'a row action sends its data-*, redraws what it names, and SugarCart hears ( event, response, action )', async () => {
		routes.answer( '/action', ( body ) => ( {
			success: true,
			message: 'Removed.',
			updates: { '.cell': '<b>new</b>' },
			echo: body,
		} ) );
		const flyout = await open();

		flyout
			.querySelector( 'form' )
			.insertAdjacentHTML(
				'beforeend',
				'<p class="cell">old</p><button type="button" class="wp-flyout-row-action" data-action="remove_product" data-product-id="12" data-confirm="Sure?" data-manager="evil">Remove</button>'
			);
		flyout.querySelector( '.wp-flyout-row-action' ).click();
		flyout.querySelector( '.wp-flyout-confirm-accept' ).click();
		await flush( vi );

		expect( routes.calls[ 1 ].body ).toEqual( {
			product_id: '12',
			manager: 'shop',
			flyout: 'order',
			action_key: 'remove_product',
			item_id: '7',
		} );
		expect( flyout.querySelector( '.cell' ).innerHTML ).toBe(
			'<b>new</b>'
		);

		const [ [ , target, response, action ] ] = heardOf(
			'wpflyout:row-action'
		);

		expect( target ).toBe( flyout );
		expect( response.message ).toBe( 'Removed.' );
		expect( action ).toBe( 'remove_product' );
	} );

	test( 'a native listener reads the response and the action from detail', async () => {
		const native = vi.fn();

		routes.answer( '/action', { success: true } );
		const flyout = await open();

		document.addEventListener( 'wpflyout:row-action', native );
		flyout
			.querySelector( 'form' )
			.insertAdjacentHTML(
				'beforeend',
				'<button type="button" class="wp-flyout-row-action" data-action="more">More</button>'
			);
		flyout.querySelector( '.wp-flyout-row-action' ).click();
		await flush( vi );
		document.removeEventListener( 'wpflyout:row-action', native );

		expect( native ).toHaveBeenCalledTimes( 1 );
		expect( native.mock.calls[ 0 ][ 0 ].detail ).toEqual( {
			response: { success: true },
			action: 'more',
		} );
	} );

	test( 'a refused action says why and fires nothing', async () => {
		routes.answer( '/action', { success: false, message: 'Not today.' } );
		const flyout = await open();

		flyout
			.querySelector( 'form' )
			.insertAdjacentHTML(
				'beforeend',
				'<button type="button" class="wp-flyout-row-action" data-action="x">X</button>'
			);
		flyout.querySelector( '.wp-flyout-row-action' ).click();
		await flush( vi );

		expect(
			flyout.querySelector( '.wp-flyout-alert .alert-message' )
				.textContent
		).toBe( 'Not today.' );
		expect( heardOf( 'wpflyout:row-action' ) ).toEqual( [] );
		expect(
			flyout
				.querySelector( '.wp-flyout-row-action' )
				.classList.contains( 'is-busy' )
		).toBe( false );
	} );

	test( 'a menu action that asks for a form opens it, and sends what is typed', async () => {
		routes.answer( '/action', ( body ) =>
			body.fields
				? { success: true, message: 'Sent.', reload: true }
				: {
						success: true,
						form: {
							title: 'Email',
							fields: [
								{
									name: 'subject',
									type: 'text',
									label: 'Subject',
									required: true,
								},
							],
						},
					}
		);
		routes.answer( '/load', {
			success: true,
			html: drawn( {
				menu: '<button type="button" class="wp-flyout-menu-item" role="menuitem" data-action="email">Email</button>',
			} ),
		} );
		const flyout = await open();

		flyout.querySelector( '.wp-flyout-menu-toggle' ).click();
		expect( document.activeElement ).toBe(
			flyout.querySelector( '.wp-flyout-menu-item' )
		);
		flyout.querySelector( '.wp-flyout-menu-item' ).click();
		await flush( vi );

		const prompt = flyout.querySelector( '.wp-flyout-prompt' );

		expect( flyout.classList.contains( 'has-prompt' ) ).toBe( true );
		expect( document.activeElement.name ).toBe( 'subject' );
		type( prompt.querySelector( '[name="subject"]' ), 'Hello' );
		prompt
			.querySelector( 'form' )
			.dispatchEvent( new Event( 'submit', { cancelable: true } ) );
		await flush( vi );

		expect( routes.calls[ 2 ].body ).toEqual( {
			manager: 'shop',
			flyout: 'order',
			action_key: 'email',
			item_id: '7',
			fields: { subject: 'Hello' },
		} );
		expect( flyout.querySelector( '.wp-flyout-prompt' ) ).toBeNull();
		expect(
			heardOf( 'wpflyout:prompt-action' ).map( ( [ , , r, a ] ) => [
				r.message,
				a,
			] )
		).toEqual( [ [ 'Sent.', 'email' ] ] );
		expect( heardOf( 'wpflyout:menu-action' ) ).toEqual( [] );
		expect( reload ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'a menu item that confirms asks in red, and runs on yes', async () => {
		routes.answer( '/action', { success: true, message: 'Refunded.' } );
		routes.answer( '/load', {
			success: true,
			html: drawn( {
				menu: '<button type="button" class="wp-flyout-menu-item is-danger" data-action="refund" data-confirm="Refund it?">Refund</button>',
			} ),
		} );
		const flyout = await open();

		flyout.querySelector( '.wp-flyout-menu-item' ).click();
		expect(
			flyout.querySelector( '.wp-flyout-confirm-accept' ).textContent
		).toBe( 'Refund' );
		flyout.querySelector( '.wp-flyout-confirm-accept' ).click();
		await flush( vi );

		expect(
			heardOf( 'wpflyout:menu-action' ).map( ( [ , , r, a ] ) => [
				r.message,
				a,
			] )
		).toEqual( [ [ 'Refunded.', 'refund' ] ] );
		expect( flyout.querySelector( '.wp-flyout-toast' ).textContent ).toBe(
			'Refunded.'
		);
	} );

	test( 'refresh_flyout loads the panel again', async () => {
		routes.answer( '/action', { success: true, refresh_flyout: true } );
		const flyout = await open();

		flyout
			.querySelector( 'form' )
			.insertAdjacentHTML(
				'beforeend',
				'<button type="button" class="wp-flyout-row-action" data-action="x">X</button>'
			);
		flyout.querySelector( '.wp-flyout-row-action' ).click();
		await flush( vi );

		expect( routes.calls.map( ( c ) => c.route ) ).toEqual( [
			'/load',
			'/action',
			'/load',
		] );
	} );
} );

describe( 'the menu', () => {
	const menu =
		'<button type="button" class="wp-flyout-menu-item" data-action="a">A</button><button type="button" class="wp-flyout-menu-item" data-action="b">B</button>';

	test( 'arrows, Home, End and Escape, which is the menu’s and not the panel’s', async () => {
		routes.answer( '/load', { success: true, html: drawn( { menu } ) } );
		const flyout = await open();
		const toggle = flyout.querySelector( '.wp-flyout-menu-toggle' );
		const items = flyout.querySelectorAll( '.wp-flyout-menu-item' );
		const key = ( name ) =>
			document.activeElement.dispatchEvent(
				new KeyboardEvent( 'keydown', {
					key: name,
					bubbles: true,
					cancelable: true,
				} )
			);

		toggle.focus();
		key( 'ArrowUp' );
		expect( document.activeElement ).toBe( items[ 1 ] );
		expect( toggle.getAttribute( 'aria-expanded' ) ).toBe( 'true' );
		key( 'ArrowDown' );
		expect( document.activeElement ).toBe( items[ 0 ] );
		key( 'End' );
		expect( document.activeElement ).toBe( items[ 1 ] );
		key( 'Home' );
		expect( document.activeElement ).toBe( items[ 0 ] );
		key( 'Escape' );
		expect( flyout.querySelector( '.wp-flyout-menu-list' ).hidden ).toBe(
			true
		);
		expect( document.activeElement ).toBe( toggle );
		expect( panel.active ).toEqual( [ flyout.id ] );

		toggle.click();
		document.body.click();
		expect( flyout.querySelector( '.wp-flyout-menu-list' ).hidden ).toBe(
			true
		);
	} );
} );

describe( 'previous and next', () => {
	test( 'the arrow loads the next row in place, keeps focus on itself and says where it is', async () => {
		const flyout = await open( 7 );

		flyout.querySelector( '.wp-flyout-next' ).click();
		await flush( vi );

		const next = document.getElementById( 'wp-flyout-order-8' );

		expect( next.classList.contains( 'active' ) ).toBe( true );
		expect( document.activeElement ).toBe(
			next.querySelector( '.wp-flyout-next' )
		);
		expect(
			next.querySelector( '.wp-flyout-step-status' ).textContent
		).toBe( 'Order 8, 2 of 3' );
	} );

	test( 'at the end, focus is on the arrow that still goes somewhere', async () => {
		const flyout = await open( 8 );

		flyout.querySelector( '.wp-flyout-next' ).click();
		await flush( vi );

		const last = document.getElementById( 'wp-flyout-order-9' );

		expect( last.querySelector( '.wp-flyout-next' ).disabled ).toBe( true );
		expect( document.activeElement ).toBe(
			last.querySelector( '.wp-flyout-prev' )
		);
	} );

	test( 'J and K step, from the panel, for a panel that asked; past the end it says so', async () => {
		const flyout = await open( 8 );
		const press = ( key, at = document.activeElement ) =>
			at.dispatchEvent(
				new KeyboardEvent( 'keydown', {
					key,
					bubbles: true,
					cancelable: true,
				} )
			);

		flyout.focus();
		press( 'k' );
		await flush( vi );
		expect( document.getElementById( 'wp-flyout-order-7' ) ).not.toBeNull();
		expect( document.activeElement.id ).toBe( 'wp-flyout-order-7' );

		press( 'K' );
		await flush( vi );
		expect( document.querySelector( '.wp-flyout-toast' ).textContent ).toBe(
			'That is the end of the list.'
		);

		// Typing a J is a J.
		const name = document.querySelector(
			'#wp-flyout-order-7 [name="name"]'
		);

		name.focus();
		press( 'j' );
		await flush( vi );
		expect( document.getElementById( 'wp-flyout-order-7' ) ).not.toBeNull();
	} );

	test( 'neighbours are one trigger per record, not in a panel, not the Add button', () => {
		document.body.insertAdjacentHTML(
			'beforeend',
			trigger( 7 ) +
				trigger( 10 ).replace(
					'data-flyout="order"',
					'data-flyout="other"'
				) +
				`<div class="wp-flyout">${ trigger( 11 ) }</div>` +
				trigger( 0 )
		);

		const config = { flyout: 'order', manager: 'shop', data: { id: '9' } };
		const list = neighbours( config );

		expect( list.map( ( t ) => t.dataset.id ) ).toEqual( [
			'7',
			'8',
			'9',
		] );
		expect( position( config, list ) ).toBe( 2 );
		expect( position( { ...config, data: {} }, list ) ).toBe( -1 );
	} );

	test.each( [
		[ '<textarea></textarea>', false, true ],
		[ '<input type="text">', false, true ],
		[ '<input type="checkbox">', false, false ],
		[ '<input type="checkbox">', true, true ],
		[ '<button>b</button>', true, false ],
		[ '<a href="#">a</a>', true, false ],
		[ '<div role="tablist"><button>t</button></div>', true, true ],
		[ '<div role="menu"><button>m</button></div>', false, true ],
		[ '<span tabindex="0">s</span>', true, true ],
		[ '<span tabindex="0">s</span>', false, false ],
		[ '<div contenteditable="true">e</div>', false, true ],
	] )( 'keyIsTaken( %s, arrow %s ) is %s', ( markup, arrow, taken ) => {
		document.body.innerHTML = `<div class="wp-flyout">${ markup }</div>`;

		const target =
			document
				.querySelector( '.wp-flyout' )
				.firstElementChild.querySelector( 'button' ) ||
			document.querySelector( '.wp-flyout' ).firstElementChild;

		expect( keyIsTaken( target, arrow ) ).toBe( taken );
		expect(
			keyIsTaken( document.querySelector( '.wp-flyout' ), arrow )
		).toBe( false );
	} );
} );

test( 'the copy button copies what it carries and says Copied', async () => {
	window.isSecureContext = true;
	const writeText = vi.fn( () => Promise.resolve() );

	Object.defineProperty( window.navigator, 'clipboard', {
		value: { writeText },
		configurable: true,
	} );
	routes.answer( '/load', {
		success: true,
		html: drawn().replace(
			'</h2>',
			'</h2><button type="button" class="wp-flyout-copy" data-copy="ada@example.com">Copy</button>'
		),
	} );
	const flyout = await open();

	flyout.querySelector( '.wp-flyout-copy' ).click();
	await vi.advanceTimersByTimeAsync( 0 );

	expect( writeText ).toHaveBeenCalledWith( 'ada@example.com' );
	expect( flyout.querySelector( '.wp-flyout-toast' ).textContent ).toBe(
		'Copied'
	);
	delete window.navigator.clipboard;
} );
