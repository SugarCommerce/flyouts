/**
 * A panel on screen: window.WPFlyout's open, close, overlay, tabs, focus.
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

import { watchEscape } from '../../src/js/panel/escape';
import panel from '../../src/js/panel/panel';
import { layout } from './support/layout';

beforeAll( () => {
	layout();
	watchEscape();
	panel.init();
} );

beforeEach( () => {
	vi.useFakeTimers();
	panel.active = [];
	panel.openers = {};
	document.body.className = '';
	document.body.innerHTML = `
		<button id="opener">Open</button>
		<div id="p" class="wp-flyout wp-flyout-dynamic">
			<div class="wp-flyout-header"><h2 class="wp-flyout-title">Order</h2><button type="button" class="wp-flyout-close">x</button></div>
			<div class="wp-flyout-tabs"><a href="#" class="wp-flyout-tab active" data-tab="one">One</a><a href="#" class="wp-flyout-tab" data-tab="two">Two</a><a href="#" class="wp-flyout-tab disabled" data-tab="three">Three</a></div>
			<div class="wp-flyout-body">
				<div id="tab-one" class="wp-flyout-tab-content active"><input name="a" disabled><input name="b"></div>
				<div id="tab-two" class="wp-flyout-tab-content"><select name="c"></select></div>
			</div>
			<div class="wp-flyout-footer"><button class="wp-flyout-save">Save</button></div>
		</div>`;
} );

afterEach( () => {
	vi.runOnlyPendingTimers();
	vi.useRealTimers();
	delete window.jQuery;
	jQuery( document ).off();
} );

const flyout = () => document.getElementById( 'p' );

describe( 'open()', () => {
	test( 'slides in over an overlay, as a labelled dialog, focus on the first field', () => {
		const opened = vi.fn();
		const ready = vi.fn();

		document.getElementById( 'opener' ).focus();
		document.addEventListener( 'wpflyout:opened', opened, { once: true } );
		flyout().addEventListener( 'flyout:ready', ready );

		expect( panel.open( 'p' ) ).toBe( flyout() );
		expect( document.body.classList.contains( 'wp-flyout-open' ) ).toBe(
			true
		);
		expect( flyout().getAttribute( 'role' ) ).toBe( 'dialog' );
		expect( flyout().getAttribute( 'aria-modal' ) ).toBe( 'true' );
		expect( flyout().getAttribute( 'tabindex' ) ).toBe( '-1' );
		expect( flyout().getAttribute( 'aria-labelledby' ) ).toBe( 'p-title' );
		expect( document.getElementById( 'p-title' ).textContent ).toBe(
			'Order'
		);
		expect( panel.active ).toEqual( [ 'p' ] );

		vi.advanceTimersByTime( 10 );
		expect( flyout().classList.contains( 'active' ) ).toBe( true );
		expect(
			document.querySelector( '.wp-flyout-overlay.active' )
		).not.toBeNull();
		expect( opened ).not.toHaveBeenCalled();

		vi.advanceTimersByTime( 340 );
		expect( document.activeElement.name ).toBe( 'b' );
		expect( opened.mock.calls[ 0 ][ 0 ].detail ).toEqual( {
			id: 'p',
			element: flyout(),
		} );
		expect( ready ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'in place, at once, when it takes over from another', () => {
		panel.open( 'p', { instant: true } );

		expect( flyout().classList.contains( 'active' ) ).toBe( true );
		expect( flyout().classList.contains( 'wp-flyout-instant' ) ).toBe(
			true
		);
		vi.advanceTimersByTime( 50 );
		expect( flyout().classList.contains( 'wp-flyout-instant' ) ).toBe(
			false
		);
	} );

	test( 'a panel with the keys, or nothing to save, takes focus itself', () => {
		flyout().classList.add( 'wp-flyout-step-keys' );
		panel.open( 'p' );
		vi.advanceTimersByTime( 350 );

		expect( document.activeElement ).toBe( flyout() );
	} );

	test( 'a selector to focus wins, if it can take it', () => {
		panel.open( 'p', { focus: '.wp-flyout-save' } );
		vi.advanceTimersByTime( 350 );

		expect( document.activeElement.className ).toBe( 'wp-flyout-save' );
	} );

	test( 'nothing there is false', () => {
		const warn = vi.spyOn( console, 'warn' ).mockImplementation( () => {} );

		expect( panel.open( 'nope' ) ).toBe( false );
		warn.mockRestore();
	} );

	test( 'accepts a jQuery object where the old one took one', () => {
		panel.describe( jQuery( flyout() ) );

		expect( flyout().getAttribute( 'role' ) ).toBe( 'dialog' );
	} );
} );

describe( 'close()', () => {
	const open = () => {
		document.getElementById( 'opener' ).focus();
		panel.open( 'p' );
		vi.advanceTimersByTime( 350 );
	};

	test( 'slides out, gives focus back, takes the overlay and itself away', () => {
		const closed = vi.fn();

		open();
		document.addEventListener( 'wpflyout:closed', closed, { once: true } );

		expect( panel.close( 'p' ) ).toBe( true );
		expect( flyout().classList.contains( 'active' ) ).toBe( false );
		expect( document.activeElement.id ).toBe( 'opener' );
		expect( panel.active ).toEqual( [] );

		vi.advanceTimersByTime( 300 );
		expect( document.getElementById( 'p' ) ).toBeNull();
		expect( document.body.classList.contains( 'wp-flyout-open' ) ).toBe(
			false
		);
		expect( closed.mock.calls[ 0 ][ 0 ].detail ).toEqual( { id: 'p' } );

		vi.advanceTimersByTime( 300 );
		expect( document.querySelector( '.wp-flyout-overlay' ) ).toBeNull();
	} );

	test( 'a jQuery listener that says no keeps it open, unless forced', () => {
		const asked = [];

		window.jQuery = jQuery;
		jQuery( document ).on( 'wpflyout:closing', ( event, detail ) => {
			asked.push( detail.force );
			event.preventDefault();
		} );
		open();

		expect( panel.close( 'p' ) ).toBe( false );
		expect( panel.active ).toEqual( [ 'p' ] );
		expect( panel.close( 'p', { force: true } ) ).toBe( true );
		expect( asked ).toEqual( [ false, true ] );
	} );

	test( 'a native listener that says no keeps it open', () => {
		const no = ( event ) => event.preventDefault();

		open();
		document.addEventListener( 'wpflyout:closing', no );

		expect( panel.close( 'p' ) ).toBe( false );
		document.removeEventListener( 'wpflyout:closing', no );
	} );

	test( 'asked once: a second close of the same panel is nothing', () => {
		const asked = vi.fn();

		open();
		document.addEventListener( 'wpflyout:closing', asked );
		panel.close( 'p' );

		expect( panel.close( 'p' ) ).toBe( false );
		expect( asked ).toHaveBeenCalledTimes( 1 );
		document.removeEventListener( 'wpflyout:closing', asked );
	} );

	test( 'the close button, the overlay and Escape all close it', () => {
		open();
		flyout().querySelector( '.wp-flyout-close' ).click();
		expect( panel.active ).toEqual( [] );
		vi.advanceTimersByTime( 600 );

		document.body.insertAdjacentHTML(
			'beforeend',
			'<div id="q" class="wp-flyout"><input></div>'
		);
		panel.open( 'q' );
		vi.advanceTimersByTime( 350 );
		document.querySelector( '.wp-flyout-overlay' ).click();
		expect( panel.active ).toEqual( [] );
		vi.advanceTimersByTime( 600 );

		panel.open( 'q' );
		vi.advanceTimersByTime( 350 );
		document.dispatchEvent(
			new KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } )
		);
		expect( panel.active ).toEqual( [] );
	} );

	test( 'an Escape an open list inside used is not the panel’s', () => {
		open();
		flyout().insertAdjacentHTML(
			'beforeend',
			'<input id="combo" role="combobox" aria-expanded="true">'
		);
		document
			.getElementById( 'combo' )
			.dispatchEvent(
				new KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } )
			);

		expect( panel.active ).toEqual( [ 'p' ] );
	} );

	test( 'an Escape a dialog over it used is not the panel’s', () => {
		open();
		document.body.insertAdjacentHTML(
			'beforeend',
			'<div role="dialog" class="media-modal">m</div>'
		);
		document.dispatchEvent(
			new KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } )
		);

		expect( panel.active ).toEqual( [ 'p' ] );
	} );

	test( 'an Escape something stopped is not the panel’s', () => {
		open();

		const key = new KeyboardEvent( 'keydown', {
			key: 'Escape',
			bubbles: true,
			cancelable: true,
		} );

		key.preventDefault();
		document.dispatchEvent( key );

		expect( panel.active ).toEqual( [ 'p' ] );
	} );
} );

describe( 'tabs', () => {
	test( 'a tab shows its page and says so', () => {
		const changed = vi.fn();

		panel.open( 'p' );
		document.addEventListener( 'wpflyout:tab-changed', changed, {
			once: true,
		} );
		flyout().querySelector( '[data-tab="two"]' ).click();

		expect(
			document.getElementById( 'tab-two' ).classList.contains( 'active' )
		).toBe( true );
		expect(
			document.getElementById( 'tab-one' ).classList.contains( 'active' )
		).toBe( false );
		expect(
			flyout()
				.querySelector( '[data-tab="two"]' )
				.getAttribute( 'aria-selected' )
		).toBe( 'true' );
		expect(
			flyout()
				.querySelector( '[data-tab="one"]' )
				.getAttribute( 'aria-selected' )
		).toBe( 'false' );
		expect( changed.mock.calls[ 0 ][ 0 ].detail ).toEqual( {
			flyoutId: 'p',
			tabId: 'two',
		} );
	} );

	test( 'a disabled tab does nothing, and opening again binds once', () => {
		const changed = vi.fn();

		panel.open( 'p' );
		panel.open( 'p' );
		document.addEventListener( 'wpflyout:tab-changed', changed );
		flyout().querySelector( '[data-tab="three"]' ).click();
		flyout().querySelector( '[data-tab="two"]' ).click();

		expect( changed ).toHaveBeenCalledTimes( 1 );
		document.removeEventListener( 'wpflyout:tab-changed', changed );
	} );
} );

describe( 'Tab', () => {
	const tab = ( shiftKey = false ) => {
		const key = new KeyboardEvent( 'keydown', {
			key: 'Tab',
			shiftKey,
			bubbles: true,
			cancelable: true,
		} );

		document.activeElement.dispatchEvent( key );

		return key.defaultPrevented;
	};

	test( 'goes round inside the panel', () => {
		panel.open( 'p' );
		vi.advanceTimersByTime( 350 );
		flyout().querySelector( '.wp-flyout-save' ).focus();

		expect( tab() ).toBe( true );
		expect( document.activeElement.className ).toBe( 'wp-flyout-close' );

		expect( tab( true ) ).toBe( true );
		expect( document.activeElement.className ).toBe( 'wp-flyout-save' );
	} );

	test( 'is left alone when focus is in something open over the panel', () => {
		panel.open( 'p' );
		vi.advanceTimersByTime( 350 );
		document.getElementById( 'opener' ).focus();

		expect( tab() ).toBe( false );
	} );
} );

test( 'discard() takes a panel away without a word', () => {
	const closing = vi.fn();

	panel.open( 'p' );
	document.addEventListener( 'wpflyout:closing', closing );
	panel.discard( 'p' );

	expect( document.getElementById( 'p' ) ).toBeNull();
	expect( panel.active ).toEqual( [] );
	expect( closing ).not.toHaveBeenCalled();
	document.removeEventListener( 'wpflyout:closing', closing );
} );

test( 'getLastId() and closeAll()', () => {
	document.body.insertAdjacentHTML(
		'beforeend',
		'<div id="q" class="wp-flyout"></div>'
	);
	panel.open( 'p' );
	panel.open( 'q' );

	expect( panel.getLastId() ).toBe( 'q' );
	panel.closeAll();
	expect( panel.getLastId() ).toBeNull();
} );
