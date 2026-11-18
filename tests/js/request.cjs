/**
 * Loads the flyout scripts against a pretend page and reports, as JSON, how
 * WPFlyoutManager.api() reached the server. Called by RequestScriptTest.
 *
 * Usage: node request.cjs <assets/js folder> <ok|refused|not-json|no-api-fetch>
 */

'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const vm = require( 'vm' );

const [ , , folder, scenario ] = process.argv;
const report = { apiFetch: [], fetch: [] };

// A jQuery that accepts anything and does nothing, so the scripts load.
const chain = new Proxy( function () {}, {
	get: ( target, key ) => ( 'length' === key ? 0 : 'then' === key ? undefined : chain ),
	apply: () => chain,
} );
const jQuery = new Proxy( function () {}, {
	get: ( target, key ) => {
		if ( 'extend' === key ) {
			return ( ...objects ) => Object.assign( ...objects.filter( ( o ) => o && 'object' === typeof o ) );
		}
		if ( 'each' === key ) {
			return ( list, fn ) => Object.keys( list || {} ).forEach( ( k ) => fn( k, list[ k ] ) );
		}

		return chain;
	},
	apply: () => chain,
} );

const window = {
	wpFlyout: { restUrl: 'https://example.test/wp-json/flyouts/v1', restNonce: 'stale', strings: {} },
	fetch( url, init ) {
		report.fetch.push( url );

		return Promise.resolve( { ok: true, status: 200, json: () => Promise.resolve( { from: 'fetch' } ) } );
	},
	wp: 'no-api-fetch' === scenario ? {} : {
		apiFetch( options ) {
			report.apiFetch.push( { url: options.url, method: options.method, body: options.body, parse: options.parse } );

			if ( 'refused' === scenario ) {
				return Promise.reject( { code: 'invalid_field', message: 'Pick a name.', data: { status: 400, field: 'name' } } );
			}
			if ( 'not-json' === scenario ) {
				return Promise.reject( { code: 'invalid_json', message: 'The response is not a valid JSON response.' } );
			}

			return Promise.resolve( { success: true, html: '<p>ok</p>' } );
		},
	},
};
window.window = window;

const context = vm.createContext( {
	window,
	document: { currentScript: null, addEventListener() {}, querySelector: () => null, querySelectorAll: () => [] },
	jQuery,
	Promise,
	Error,
	SyntaxError,
	JSON,
	URLSearchParams,
	setTimeout,
	clearTimeout,
	console,
} );

for ( const file of [ 'wp-flyout.js', 'core/manager.js' ] ) {
	vm.runInContext( fs.readFileSync( path.join( folder, file ), 'utf8' ), context, { filename: file } );
}

window.WPFlyoutManager.api( '/save', { name: '' } ).then(
	( json ) => {
		report.resolved = json;
	},
	( error ) => {
		report.rejected = { message: error.message, code: error.code || null, data: error.data || null };
	}
).then( () => process.stdout.write( JSON.stringify( report ) ) );
