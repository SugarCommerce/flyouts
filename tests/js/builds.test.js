/**
 * Two plugins' copies of the scripts on one page: each answers its own
 * triggers through its own REST URL, opens its own panels, starts the fields
 * of its own build, and publishes its panel API in its own entry -- with no
 * page global either could overwrite.
 */

import { afterAll, beforeAll, expect, test, vi } from 'vitest';

import { layout } from './support/layout';
import { panel as drawn, trigger } from './support/panels';
import { flush } from './support/server';

const rest = vi.hoisted( () => ( { calls: [] } ) );

vi.mock( '@wordpress/api-fetch', () => ( {
	default: ( options ) => {
		const url = String( options.url );
		const body = options.body ? JSON.parse( options.body ) : {};
		const build = url.startsWith( 'https://shop.test/' ) ? 'shop' : 'blog';

		rest.calls.push( { build, url } );

		return Promise.resolve( {
			success: true,
			html: drawn( { id: String( body.item_id ) } ).replace(
				'class="wp-flyout ',
				`data-flyout-build="${ build }-sugarcommerce-flyouts" class="wp-flyout `
			),
		} );
	},
} ) );

/**
 * Run the core script as WordPress prints one build's copy of it.
 *
 * @param {string} handle The build's core handle.
 * @return {Promise<void>} Once it has run.
 */
async function loadBuild( handle ) {
	vi.resetModules();
	Object.defineProperty( document, 'currentScript', {
		value: { id: handle + '-wp-flyout-js' },
		configurable: true,
	} );

	await import( '../../assets/js/wp-flyout.js' );
}

/**
 * A trigger the way one build's Manager prints it.
 *
 * @param {string} build The build: shop or blog.
 * @param {number} id    The record.
 * @return {string} Markup.
 */
const stamped = ( build, id ) =>
	trigger( id, `data-flyout-build="${ build }-sugarcommerce-flyouts"` );

const fields = {
	shop: { init: vi.fn(), destroy: vi.fn() },
	blog: { init: vi.fn(), destroy: vi.fn() },
};

let before;

beforeAll( async () => {
	layout();
	vi.useFakeTimers();

	before = new Set( Object.keys( window ) );

	window.SugarCommerceFields = {
		'shop-sugarcommerce-fields': { modules: fields.shop },
		'blog-sugarcommerce-fields': { modules: fields.blog },
	};
	window.SugarCommerceFlyouts = {
		'shop-sugarcommerce-flyouts': {
			restUrl: 'https://shop.test/wp-json/shop/v1',
			fields: 'shop-sugarcommerce-fields',
		},
		'blog-sugarcommerce-flyouts': {
			restUrl: 'https://blog.test/wp-json/blog/v1',
			fields: 'blog-sugarcommerce-fields',
		},
	};

	await loadBuild( 'shop-sugarcommerce-flyouts' );
	await loadBuild( 'blog-sugarcommerce-flyouts' );
} );

afterAll( () => {
	vi.useRealTimers();
	delete window.SugarCommerceFields;
	delete window.SugarCommerceFlyouts;
	document.body.innerHTML = '';
} );

test( 'each build publishes its panel API in its own entry, and no page global', () => {
	const shop = window.SugarCommerceFlyouts[ 'shop-sugarcommerce-flyouts' ];
	const blog = window.SugarCommerceFlyouts[ 'blog-sugarcommerce-flyouts' ];

	expect( typeof shop.panel.open ).toBe( 'function' );
	expect( typeof blog.panel.open ).toBe( 'function' );
	expect( shop.panel ).not.toBe( blog.panel );

	const added = Object.keys( window ).filter(
		( key ) =>
			! before.has( key ) &&
			! [ 'SugarCommerceFields', 'SugarCommerceFlyouts' ].includes( key )
	);

	expect( added ).toEqual( [] );
	expect( window.WPFlyout ).toBeUndefined();
	expect( window.wpFlyout ).toBeUndefined();
} );

test( 'a trigger is answered by its own build alone, which starts its own fields', async () => {
	const shop = window.SugarCommerceFlyouts[ 'shop-sugarcommerce-flyouts' ];
	const blog = window.SugarCommerceFlyouts[ 'blog-sugarcommerce-flyouts' ];

	document.body.innerHTML = stamped( 'shop', 7 ) + stamped( 'blog', 8 );

	document.querySelector( '[data-id="7"]' ).click();
	await flush( vi );

	expect( rest.calls.map( ( call ) => call.build ) ).toEqual( [ 'shop' ] );
	expect( shop.panel.active ).toEqual( [ 'wp-flyout-order-7' ] );
	expect( blog.panel.active ).toEqual( [] );

	const flyout = document.getElementById( 'wp-flyout-order-7' );

	expect( fields.shop.init ).toHaveBeenCalledWith( flyout );
	expect( fields.blog.init ).not.toHaveBeenCalled();

	// The other build's trigger: its own request, and the shop's panel is
	// not the blog's to clear.
	document.querySelector( '[data-id="8"]' ).click();
	await flush( vi );

	expect( rest.calls.map( ( call ) => call.build ) ).toEqual( [
		'shop',
		'blog',
	] );
	expect( blog.panel.active ).toEqual( [ 'wp-flyout-order-8' ] );
	expect( document.getElementById( 'wp-flyout-order-7' ) ).toBe( flyout );
	expect( fields.blog.init ).toHaveBeenCalledWith(
		document.getElementById( 'wp-flyout-order-8' )
	);

	// Closing the shop's panel is the shop's question and the shop's
	// fields' to let go of.
	shop.panel.close( 'wp-flyout-order-7', { force: true } );

	expect( fields.shop.destroy ).toHaveBeenCalledWith( flyout );
	expect( fields.blog.destroy ).not.toHaveBeenCalledWith( flyout );

	// Each build only ever let go of its own: its placeholders and panels.
	[ 'shop', 'blog' ].forEach( ( build ) =>
		fields[ build ].destroy.mock.calls.forEach( ( [ element ] ) =>
			expect( element.getAttribute( 'data-flyout-build' ) ).toBe(
				build + '-sugarcommerce-flyouts'
			)
		)
	);
} );
