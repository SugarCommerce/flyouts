/**
 * Which build's settings a script reads, and the words and icons it uses.
 */

import { afterEach, describe, expect, test } from 'vitest';

import { icon, resolve, settings, strings } from '../../assets/js/lib/config';

afterEach( () => {
	delete window.wpFlyout;
	delete window.SugarCommerceFlyouts;
} );

const script = ( id ) => ( { id } );

describe( 'resolve()', () => {
	const registry = {
		'sugarcommerce-flyouts': { restUrl: 'plain' },
		'shop-sugarcommerce-flyouts': { restUrl: 'shop' },
		resolve() {},
	};

	test( 'its own handle first', () => {
		expect(
			resolve( script( 'shop-sugarcommerce-flyouts-js' ), registry )
				.restUrl
		).toBe( 'shop' );
	} );

	test( 'a component finds its build by the longest key its handle starts with', () => {
		expect(
			resolve(
				script( 'shop-sugarcommerce-flyouts-accordion-js' ),
				registry
			).restUrl
		).toBe( 'shop' );
		expect(
			resolve( script( 'sugarcommerce-flyouts-line-items-js' ), registry )
				.restUrl
		).toBe( 'plain' );
	} );

	test( 'the longest key wins, whatever order they were published in', () => {
		const nested = {
			shop: { restUrl: 'shop' },
			'shop-sugarcommerce-flyouts': { restUrl: 'library' },
		};
		const reversed = {
			'shop-sugarcommerce-flyouts': { restUrl: 'library' },
			shop: { restUrl: 'shop' },
		};
		const id = script( 'shop-sugarcommerce-flyouts-accordion-js' );

		expect( resolve( id, nested ).restUrl ).toBe( 'library' );
		expect( resolve( id, reversed ).restUrl ).toBe( 'library' );
	} );

	test( 'the core script, whose handle ends -wp-flyout, finds its build', () => {
		expect(
			resolve( script( 'sugarcommerce-flyouts-wp-flyout-js' ), registry )
				.restUrl
		).toBe( 'plain' );
	} );

	test( 'what is not settings is passed over', () => {
		expect(
			resolve( script( 'resolve-js' ), registry, { none: 1 } )
		).toEqual( {
			none: 1,
		} );
	} );

	test( 'no script and nothing published is the fallback', () => {
		expect( resolve( null, registry, { fallback: true } ) ).toEqual( {
			fallback: true,
		} );
		expect( resolve( script( 'x-js' ), undefined ) ).toEqual( {} );
	} );
} );

test( 'with no script of its own, a page’s bare wpFlyout is read', () => {
	window.wpFlyout = { restUrl: 'old' };

	expect( settings().restUrl ).toBe( 'old' );
} );

test( 'the page’s translations replace the English, key by key', () => {
	window.wpFlyout = { strings: { saved: 'Gespeichert' } };

	expect( strings().saved ).toBe( 'Gespeichert' );
	expect( strings().deleted ).toBe( 'Deleted' );
	expect( strings().stepped ).toBe( '%1$s, %2$s of %3$s' );
} );

test( 'an icon is the server’s markup, or the Dashicon it used to be', () => {
	window.wpFlyout = { icons: { close: '<svg class="x"></svg>' } };

	expect( icon( 'close', 'no-alt' ) ).toBe( '<svg class="x"></svg>' );
	expect( icon( 'copy', 'admin-page' ) ).toBe(
		'<span class="dashicons dashicons-admin-page sugarcommerce-icon" aria-hidden="true"></span>'
	);
	expect( icon( '', 'x"><script>' ) ).toBe(
		'<span class="dashicons dashicons-xscript sugarcommerce-icon" aria-hidden="true"></span>'
	);
} );
