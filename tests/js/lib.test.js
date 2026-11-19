/**
 * The shared modules: alerts, a panel's feedback, markup going in, the
 * movements, and what is kept on an element.
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

import {
	bindAlerts,
	dismiss,
	show as showAlertIn,
} from '../../src/js/lib/alert';
import {
	escapeHtml,
	hide,
	insert,
	isShown,
	make,
	parse,
	replaceHtml,
	show,
} from '../../src/js/lib/dom';
import {
	setButtonState,
	showAlert,
	toast,
	toastRegion,
} from '../../src/js/lib/feedback';
import {
	fadeOut,
	scrollToTop,
	slideDown,
	slideUp,
} from '../../src/js/lib/motion';
import { itemId, panelConfig, stash } from '../../src/js/lib/store';
import { layout } from './support/layout';
import { publish } from './support/server';

beforeAll( () => {
	layout();
	bindAlerts();
} );

beforeEach( () => {
	publish(
		{},
		{
			close: '<svg class="close"></svg>',
			error: '<svg class="error"></svg>',
		}
	);
	document.body.innerHTML =
		'<div class="wp-flyout" id="p"><div class="wp-flyout-body"><p>Body</p></div></div>';
} );

afterEach( () => {
	vi.useRealTimers();
	delete window.jQuery;
	jQuery( document ).off();
} );

const flyout = () => document.getElementById( 'p' );

describe( 'alerts', () => {
	test( 'say the message as text, with the kind’s icon and a close button', () => {
		const alert = showAlertIn( '<img src=x onerror=alert(1)>', 'error', {
			target: flyout().querySelector( '.wp-flyout-body' ),
			prepend: true,
		} );

		expect( alert.className ).toBe(
			'wp-flyout-alert alert-error is-dismissible'
		);
		expect( alert.getAttribute( 'role' ) ).toBe( 'alert' );
		expect( alert.querySelector( '.alert-message' ).textContent ).toBe(
			'<img src=x onerror=alert(1)>'
		);
		expect( alert.querySelector( 'img' ) ).toBeNull();
		expect( alert.querySelector( '.alert-icon svg.error' ) ).not.toBeNull();
		expect(
			alert.querySelector( '.alert-dismiss svg.close' )
		).not.toBeNull();
		expect(
			flyout().querySelector( '.wp-flyout-body' ).firstElementChild
		).toBe( alert );
	} );

	test( 'a Dashicon of the caller’s, an unknown kind as info, and none to close', () => {
		const alert = showAlertIn( 'Hi', 'odd', {
			icon: 'dashicons-star-filled',
			dismissible: false,
		} );

		expect(
			alert.querySelector( '.dashicons-star-filled' )
		).not.toBeNull();
		expect( alert.querySelector( '.alert-dismiss' ) ).toBeNull();
		expect(
			flyout().querySelector( '.wp-flyout-body' ).lastElementChild
		).toBe( alert );
	} );

	test( 'the close button takes it away, unless a listener says not to', () => {
		vi.useFakeTimers();
		window.jQuery = jQuery;

		const heard = [];

		jQuery( document ).on(
			'alert:shown alert:beforedismiss alert:dismissed',
			( event ) => heard.push( event.type )
		);

		const keep = showAlertIn( 'Keep', 'info' );
		const go = showAlertIn( 'Go', 'info' );

		keep.addEventListener( 'alert:beforedismiss', ( event ) =>
			event.preventDefault()
		);
		keep.querySelector( '.alert-dismiss' ).click();
		go.querySelector( '.alert-dismiss' ).click();
		vi.advanceTimersByTime( 300 );

		return Promise.resolve().then( () => {
			expect( keep.isConnected ).toBe( true );
			expect( go.isConnected ).toBe( false );
			expect( heard ).toEqual( [
				'alert:shown',
				'alert:shown',
				'alert:beforedismiss',
				'alert:beforedismiss',
				'alert:dismissed',
			] );
		} );
	} );

	test( 'a timeout takes it away by itself', async () => {
		vi.useFakeTimers();

		const alert = showAlertIn( 'Saved', 'success', { timeout: 3000 } );

		await vi.advanceTimersByTimeAsync( 3300 );

		expect( alert.isConnected ).toBe( false );
	} );

	test( 'dismiss() of one already gone does no harm', async () => {
		vi.useFakeTimers();

		const alert = showAlertIn( 'x' );

		alert.remove();
		dismiss( alert );
		await vi.advanceTimersByTimeAsync( 300 );

		expect( alert.isConnected ).toBe( false );
	} );
} );

describe( 'a panel’s feedback', () => {
	test( 'one alert at a time at the top of the body; a success goes by itself', async () => {
		vi.useFakeTimers();
		showAlert( flyout(), 'One', 'error' );
		const success = showAlert( flyout(), 'Two', 'success' );

		expect( flyout().querySelectorAll( '.wp-flyout-alert' ) ).toHaveLength(
			1
		);
		await vi.advanceTimersByTimeAsync( 3300 );
		expect( success.isConnected ).toBe( false );
	} );

	test( 'a toast says it, and goes', () => {
		vi.useFakeTimers();
		toast( flyout(), 'Saved' );
		vi.advanceTimersByTime( 2000 );
		toast( flyout(), 'Saved again' );

		const region = toastRegion( flyout() );

		expect( flyout().querySelectorAll( '.wp-flyout-toast' ) ).toHaveLength(
			1
		);
		expect( region.getAttribute( 'aria-live' ) ).toBe( 'polite' );
		expect( region.textContent ).toBe( 'Saved again' );
		expect( region.classList.contains( 'is-visible' ) ).toBe( true );

		// The first one's time is up, and it is not the first one's to end.
		vi.advanceTimersByTime( 2400 );
		expect( region.textContent ).toBe( 'Saved again' );
		vi.advanceTimersByTime( 100 );
		expect( region.textContent ).toBe( '' );
		expect( region.classList.contains( 'is-visible' ) ).toBe( false );
	} );

	test( 'a busy button keeps its place and focus, and may take other words', () => {
		const button = make( 'button', { class: 'b' }, 'Save' );

		document.body.append( button );
		button.focus();
		setButtonState( button, true, 'Saving' );

		expect( button.disabled ).toBe( false );
		expect( button.getAttribute( 'aria-disabled' ) ).toBe( 'true' );
		expect( button.getAttribute( 'aria-busy' ) ).toBe( 'true' );
		expect( button.textContent ).toBe( 'Saving' );
		expect( document.activeElement ).toBe( button );

		setButtonState( [ button ], false );
		expect( button.textContent ).toBe( 'Save' );
		expect( button.hasAttribute( 'aria-busy' ) ).toBe( false );
		expect( button.classList.contains( 'is-busy' ) ).toBe( false );
		setButtonState( null, true );
	} );
} );

describe( 'markup', () => {
	test( 'escapes for text and attributes', () => {
		expect( escapeHtml( `<a href="x">'&` ) ).toBe(
			'&lt;a href=&quot;x&quot;&gt;&#039;&amp;'
		);
		expect( escapeHtml( null ) ).toBe( '' );
	} );

	test( 'a lone row parses as a row', () => {
		expect( parse( '<tr><td>1</td></tr>' )[ 0 ].tagName ).toBe( 'TR' );
	} );

	test( 'goes in at the end or the start; html() replaces', () => {
		const body = flyout().querySelector( '.wp-flyout-body' );

		insert( body, '<i>end</i>' );
		insert( body, '<b>start</b>', true );
		expect( body.innerHTML ).toBe( '<b>start</b><p>Body</p><i>end</i>' );

		replaceHtml( body, '<u>only</u>' );
		expect( body.innerHTML ).toBe( '<u>only</u>' );
	} );

	test( 'make() leaves off false, null and undefined', () => {
		expect(
			make( 'div', { a: 1, b: false, c: null, d: undefined, e: '' }, 0 )
				.outerHTML
		).toBe( '<div a="1" e="">0</div>' );
	} );
} );

describe( 'show and hide', () => {
	test( 'show() takes off what hid it, and overrides a stylesheet that still does', () => {
		document.head.innerHTML = '<style>.gone { display: none; }</style>';
		document.body.innerHTML =
			'<p class="gone"></p><span style="display:none"></span>';

		const [ p, span ] = document.body.children;

		show( span );
		expect( span.style.display ).toBe( '' );
		show( p );
		expect( p.style.display ).toBe( 'block' );
		expect( isShown( p ) ).toBe( true );
		hide( p );
		expect( isShown( p ) ).toBe( false );
		document.head.innerHTML = '';
	} );

	test( 'with no way to animate, a slide ends where it would have', async () => {
		document.body.innerHTML = '<div style="display:none">x</div>';

		const div = document.body.firstElementChild;

		await slideDown( div );
		expect( isShown( div ) ).toBe( true );
		await slideDown( div );
		await slideUp( div );
		expect( div.style.display ).toBe( 'none' );
		await slideUp( div );
		await slideUp( null );
		await slideDown( null );

		show( div );
		await fadeOut( div );
		expect( div.style.display ).toBe( 'none' );
	} );

	test( 'a slide animates the box, then leaves it shown or hidden', async () => {
		document.body.innerHTML = '<div style="display:none">x</div>';

		const div = document.body.firstElementChild;
		const animations = [];

		div.animate = ( frames, options ) => {
			const animation = {
				frames,
				options,
				cancel: vi.fn(
					() => animation.oncancel && animation.oncancel()
				),
			};

			animations.push( animation );

			return animation;
		};

		const opened = slideDown( div, 300 );

		expect( div.style.overflow ).toBe( 'hidden' );
		expect( animations[ 0 ].frames[ 0 ].height ).toBe( '0px' );
		expect( animations[ 0 ].options.duration ).toBe( 300 );

		// Shut halfway: the opening stops where it is.
		const shut = slideUp( div, 300 );

		expect( animations[ 0 ].cancel ).toHaveBeenCalled();
		await opened;
		animations[ 1 ].onfinish();
		await shut;

		expect( div.style.display ).toBe( 'none' );
		expect( div.style.overflow ).toBe( '' );
	} );

	test( 'scrolling to the top, smoothly where it can', () => {
		const body = flyout().querySelector( '.wp-flyout-body' );

		body.scrollTo = vi.fn();
		scrollToTop( body );
		expect( body.scrollTo ).toHaveBeenCalledWith( {
			top: 0,
			behavior: 'smooth',
		} );

		delete body.scrollTo;
		body.scrollTo = undefined;
		body.scrollTop = 5;
		scrollToTop( body );
		expect( body.scrollTop ).toBe( 0 );
		scrollToTop( null );
	} );
} );

describe( 'what is kept on an element', () => {
	test( 'is shared by every copy of the scripts, as jQuery’s data was', () => {
		const element = flyout();

		stash( element ).config = { manager: 'shop', data: { id: '3' } };

		expect(
			element[ Symbol.for( 'sugarcommerce-flyouts' ) ].config.manager
		).toBe( 'shop' );
		expect( panelConfig( element ).manager ).toBe( 'shop' );
		expect( panelConfig( null ) ).toEqual( {} );
		expect( itemId( element ) ).toBe( '3' );

		element.insertAdjacentHTML(
			'beforeend',
			'<input name="id" value="9">'
		);
		expect( itemId( element ) ).toBe( '9' );

		delete element[ Symbol.for( 'sugarcommerce-flyouts' ) ];
		element.querySelector( 'input' ).remove();
		expect( itemId( element ) ).toBe( 0 );
	} );
} );
