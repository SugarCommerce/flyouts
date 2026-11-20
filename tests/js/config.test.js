/**
 * Which build's settings a script reads, and the words and icons it uses.
 */

import { afterEach, describe, expect, test } from 'vitest';

import {
	build,
	fieldsModules,
	icon,
	mine,
	own,
	resolve,
	settings,
	strings,
} from '../../assets/js/lib/config';

afterEach( () => {
	delete window.wpFlyout;
	delete window.WPFlyout;
	delete window.SugarCommerceFlyouts;
	delete window.SugarCommerceFields;
	document.body.innerHTML = '';
} );

/**
 * Publish settings under the build the suites run as.
 *
 * @param {Object} entry The settings.
 */
const published = ( entry ) => {
	window.SugarCommerceFlyouts = { 'sugarcommerce-flyouts': entry };
};

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

test( 'a bare page global is never read: only the build’s own entry', () => {
	window.wpFlyout = { restUrl: 'old' };
	window.WPFlyout = { open() {} };

	expect( settings() ).toEqual( {} );
	expect( settings().panel ).toBeUndefined();

	published( { restUrl: 'mine' } );

	expect( settings().restUrl ).toBe( 'mine' );
} );

test( 'the core script’s entry is made when the page published none', () => {
	const entry = own();

	expect( window.SugarCommerceFlyouts[ 'sugarcommerce-flyouts' ] ).toBe(
		entry
	);
	expect( build() ).toBe( 'sugarcommerce-flyouts' );
} );

test( 'the fields modules are those of the fields script the entry names', () => {
	const plain = { init() {} };
	const other = { init() {} };

	window.SugarCommerceFields = {
		'sugarcommerce-fields': { modules: other },
		'shop-sugarcommerce-fields': { modules: plain },
	};

	expect( fieldsModules() ).toBeNull();

	published( { fields: 'shop-sugarcommerce-fields' } );

	expect( fieldsModules() ).toBe( plain );
} );

test( 'markup is this build’s when nothing, or this build, stamped it', () => {
	published( {} );
	document.body.innerHTML =
		'<p id="none"><b></b></p>' +
		'<div data-flyout-build="sugarcommerce-flyouts"><b id="ours"></b></div>' +
		'<div data-flyout-build="shop-sugarcommerce-flyouts"><b id="theirs"></b></div>';

	expect( mine( document.querySelector( '#none b' ) ) ).toBe( true );
	expect( mine( document.getElementById( 'ours' ) ) ).toBe( true );
	expect( mine( document.getElementById( 'theirs' ) ) ).toBe( false );
	expect( mine( null ) ).toBe( true );
} );

test( 'the page’s translations replace the English, key by key', () => {
	published( { strings: { saved: 'Gespeichert' } } );

	expect( strings().saved ).toBe( 'Gespeichert' );
	expect( strings().deleted ).toBe( 'Deleted' );
	expect( strings().stepped ).toBe( '%1$s, %2$s of %3$s' );
} );

test( 'an icon is the server’s markup, or the Dashicon it used to be', () => {
	published( { icons: { close: '<svg class="x"></svg>' } } );

	expect( icon( 'close', 'no-alt' ) ).toBe( '<svg class="x"></svg>' );
	expect( icon( 'copy', 'admin-page' ) ).toBe(
		'<span class="dashicons dashicons-admin-page sugarcommerce-icon" aria-hidden="true"></span>'
	);
	expect( icon( '', 'x"><script>' ) ).toBe(
		'<span class="dashicons dashicons-xscript sugarcommerce-icon" aria-hidden="true"></span>'
	);
} );
