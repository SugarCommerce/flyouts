/**
 * Events reach both kinds of listener, once each.
 *
 * Outside code listens the jQuery way with the payload as extra arguments
 * -- SugarCart's `( event, response, action )` from `wpflyout:row-action`,
 * the fields package's `( event, dirty )` from `wpflyout:dirty` -- and new
 * code natively, reading `event.detail`. Against the real jQuery core ships.
 */

import jQuery from 'jquery';
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';

import { emit } from '../../src/js/lib/events';

let panel;

beforeEach( () => {
	document.body.innerHTML =
		'<div class="wp-flyout" id="p"><form></form></div>';
	panel = document.getElementById( 'p' );
	window.jQuery = jQuery;
} );

afterEach( () => {
	jQuery( document ).off();
	jQuery( panel ).off();
	delete window.jQuery;
} );

describe( 'a jQuery listener', () => {
	test( 'is handed the old extra arguments, exactly once', () => {
		const heard = vi.fn();
		const response = { success: true, message: 'Done' };

		jQuery( document ).on( 'wpflyout:row-action', ( event, ...args ) =>
			heard( event.target, args )
		);

		emit( panel, 'wpflyout:row-action', {
			detail: { response, action: 'refund' },
			args: [ response, 'refund' ],
		} );

		expect( heard ).toHaveBeenCalledTimes( 1 );
		expect( heard ).toHaveBeenCalledWith( panel, [ response, 'refund' ] );
	} );

	test( 'reads a single payload as its one argument', () => {
		const heard = vi.fn();
		const detail = { id: 'p', element: panel };

		jQuery( document ).on( 'wpflyout:opened', function ( event, data ) {
			heard( data, arguments.length );
		} );

		emit( document, 'wpflyout:opened', { detail } );

		expect( heard.mock.calls ).toEqual( [ [ detail, 2 ] ] );
	} );

	test( 'hears `false` from wpflyout:dirty, as the fields guard reads it', () => {
		const said = [];

		jQuery( document ).on( 'wpflyout:dirty', ( event, dirty ) => {
			said.push( [
				dirty,
				event.target.querySelector( 'form' ) !== null,
			] );
		} );

		emit( panel, 'wpflyout:dirty', {
			detail: { dirty: false },
			args: [ false ],
		} );

		expect( said ).toEqual( [ [ false, true ] ] );
	} );

	test( 'with no payload is handed the event alone', () => {
		const heard = vi.fn();

		jQuery( panel ).on( 'flyout:ready', function () {
			heard( arguments.length );
		} );

		emit( panel, 'flyout:ready' );

		expect( heard.mock.calls ).toEqual( [ [ 1 ] ] );
	} );
} );

describe( 'a native listener', () => {
	test( 'reads the payload as detail, once, as the event bubbles', () => {
		const heard = vi.fn();

		document.addEventListener( 'wpflyout:dirty', heard );
		emit( panel, 'wpflyout:dirty', {
			detail: { dirty: true },
			args: [ true ],
		} );
		document.removeEventListener( 'wpflyout:dirty', heard );

		expect( heard ).toHaveBeenCalledTimes( 1 );
		expect( heard.mock.calls[ 0 ][ 0 ].detail ).toEqual( { dirty: true } );
		expect( heard.mock.calls[ 0 ][ 0 ].target ).toBe( panel );
	} );

	test( 'hears it with no jQuery on the page at all', () => {
		const heard = vi.fn();

		delete window.jQuery;
		document.addEventListener( 'wpflyout:closed', heard );
		emit( document, 'wpflyout:closed', { detail: { id: 'p' } } );
		document.removeEventListener( 'wpflyout:closed', heard );

		expect( heard.mock.calls[ 0 ][ 0 ].detail ).toEqual( { id: 'p' } );
	} );
} );

describe( 'cancelling', () => {
	const closing = () =>
		emit( document, 'wpflyout:closing', {
			detail: { id: 'p', element: panel, force: false },
			cancelable: true,
		} );

	test( 'nobody says no: it goes ahead', () => {
		expect( closing() ).toBe( true );
	} );

	test( 'a jQuery listener says no with preventDefault()', () => {
		const native = vi.fn( ( event ) => event.defaultPrevented );

		jQuery( document ).on( 'wpflyout:closing', ( event ) =>
			event.preventDefault()
		);
		document.addEventListener( 'wpflyout:closing', native );

		expect( closing() ).toBe( false );
		// And a native listener after it sees that said.
		expect( native.mock.results[ 0 ].value ).toBe( true );

		document.removeEventListener( 'wpflyout:closing', native );
	} );

	test( 'a native listener says no with preventDefault()', () => {
		const say = ( event ) => event.preventDefault();

		document.addEventListener( 'wpflyout:closing', say );

		expect( closing() ).toBe( false );

		document.removeEventListener( 'wpflyout:closing', say );
	} );

	test( 'with no jQuery, a native no still holds', () => {
		const say = ( event ) => event.preventDefault();

		delete window.jQuery;
		document.addEventListener( 'wpflyout:closing', say );

		expect( closing() ).toBe( false );

		document.removeEventListener( 'wpflyout:closing', say );
	} );

	test( 'an event that is not cancelable cannot be refused', () => {
		jQuery( document ).on( 'wpflyout:closed', ( event ) =>
			event.preventDefault()
		);

		expect(
			emit( document, 'wpflyout:closed', { detail: { id: 'p' } } )
		).toBe( true );
	} );
} );

test( "jQuery's own guard is put back once the event is out", () => {
	jQuery.event.triggered = undefined;

	emit( panel, 'wpflyout:dirty', {
		detail: { dirty: true },
		args: [ true ],
	} );

	expect( jQuery.event.triggered ).toBeUndefined();
} );

test( 'a jQuery listener bound on the element itself hears it once too', () => {
	const heard = vi.fn();

	jQuery( panel ).on( 'wpflyout:note-added', ( event, response ) =>
		heard( response )
	);

	emit( panel, 'wpflyout:note-added', {
		detail: { response: 1 },
		args: [ 1 ],
	} );

	expect( heard.mock.calls ).toEqual( [ [ 1 ] ] );
} );
