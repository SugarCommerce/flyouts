/**
 * The accordion: one section open at a time, unless it allows several.
 */

import jQuery from 'jquery';
import { afterEach, beforeAll, beforeEach, expect, test, vi } from 'vitest';

import { bindAccordions } from '../../src/js/components/accordion';

beforeAll( bindAccordions );

const section = ( open ) =>
	`<div class="accordion-section${ open ? ' is-open' : '' }"><button class="accordion-header" aria-expanded="${ open }">H</button><div class="accordion-content" ${ open ? '' : 'style="display:none"' }>C</div></div>`;

const draw = ( multiple ) => {
	document.body.innerHTML = `<div class="wp-flyout-accordion" data-allow-multiple="${ multiple }">${ section( false ) }${ section( true ) }</div>`;

	return Array.from( document.querySelectorAll( '.accordion-section' ) );
};

let heard;

beforeEach( () => {
	window.jQuery = jQuery;
	heard = [];
	jQuery( document ).on(
		'accordion:toggled accordion:opened accordion:closed',
		( event, data ) => heard.push( [ event.type, data ] )
	);
} );

afterEach( () => {
	jQuery( document ).off();
	delete window.jQuery;
} );

test( 'opening one closes the other', async () => {
	const [ first, second ] = draw( false );

	first.querySelector( '.accordion-header' ).click();
	await vi.waitFor( () => expect( heard ).toHaveLength( 3 ) );

	expect( first.classList.contains( 'is-open' ) ).toBe( true );
	expect(
		first
			.querySelector( '.accordion-header' )
			.getAttribute( 'aria-expanded' )
	).toBe( 'true' );
	expect(
		first.querySelector( '.accordion-content' ).style.display
	).not.toBe( 'none' );
	expect( second.classList.contains( 'is-open' ) ).toBe( false );
	expect(
		second
			.querySelector( '.accordion-header' )
			.getAttribute( 'aria-expanded' )
	).toBe( 'false' );
	expect( second.querySelector( '.accordion-content' ).style.display ).toBe(
		'none'
	);
	expect( heard ).toEqual( [
		[ 'accordion:toggled', { isOpen: true } ],
		[ 'accordion:closed', undefined ],
		[ 'accordion:opened', undefined ],
	] );
} );

test( 'with several allowed, the other stays open; pressed again, one closes', async () => {
	const [ first, second ] = draw( true );

	first.querySelector( '.accordion-header' ).click();
	await vi.waitFor( () => expect( heard ).toHaveLength( 2 ) );
	expect( second.classList.contains( 'is-open' ) ).toBe( true );

	first.querySelector( '.accordion-header' ).click();
	await vi.waitFor( () => expect( heard ).toHaveLength( 4 ) );
	expect( first.classList.contains( 'is-open' ) ).toBe( false );
	expect( heard[ 2 ] ).toEqual( [ 'accordion:toggled', { isOpen: false } ] );
} );
