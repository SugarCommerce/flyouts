/**
 * How a panel's request reaches the server and how its answer is read.
 *
 * Ported from the node script RequestScriptTest ran: a request goes through
 * apiFetch, which renews a stale nonce; a refusal keeps its message, code
 * and data; an answer that is not JSON says something went wrong; a page
 * without apiFetch still uses fetch().
 */

import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';

import { api, request } from '../../assets/js/lib/request';
import { publish, REST } from './support/server';

beforeEach( () => publish() );

afterEach( () => {
	delete window.SugarCommerceFlyouts;
} );

describe( 'api()', () => {
	test( 'a request goes through apiFetch', async () => {
		const fetch = vi.fn( () =>
			Promise.resolve( { success: true, html: '<p>ok</p>' } )
		);
		const answer = await api( '/save', { name: '' }, 'POST', fetch );

		expect( fetch ).toHaveBeenCalledTimes( 1 );
		expect( fetch.mock.calls[ 0 ][ 0 ] ).toEqual( {
			url: REST + '/save',
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': 'nonce',
			},
			body: '{"name":""}',
			parse: true,
		} );
		expect( answer ).toEqual( { success: true, html: '<p>ok</p>' } );
	} );

	test( 'a refusal keeps its message, code and data', async () => {
		const fetch = () =>
			Promise.reject( {
				code: 'invalid_field',
				message: 'Pick a name.',
				data: { status: 400, field: 'name' },
			} );

		const error = await api( '/save', {}, 'POST', fetch ).catch(
			( e ) => e
		);

		expect( error.message ).toBe( 'Pick a name.' );
		expect( error.code ).toBe( 'invalid_field' );
		expect( error.data ).toEqual( { status: 400, field: 'name' } );
	} );

	test( 'a refusal with no message says something went wrong, in the page’s words', async () => {
		publish( { requestFailed: 'Quelque chose' } );

		const error = await api( '/save', {}, 'POST', () =>
			Promise.reject( { code: 'x', data: { status: 500 } } )
		).catch( ( e ) => e );

		expect( error.message ).toBe( 'Quelque chose' );
		expect( error.data ).toEqual( { status: 500 } );
	} );

	test( 'an answer that is not JSON says something went wrong', async () => {
		const fetch = () =>
			Promise.reject( {
				code: 'invalid_json',
				message: 'The response is not a valid JSON response.',
			} );

		const error = await api( '/save', {}, 'POST', fetch ).catch(
			( e ) => e
		);

		expect( error.message ).toBe(
			'Something went wrong. Please try again.'
		);
	} );

	test( 'a GET sends its data as the query and no body', async () => {
		const fetch = vi.fn( () => Promise.resolve( {} ) );

		await api( '/load', { flyout: 'order', item_id: 7 }, 'GET', fetch );

		expect( fetch.mock.calls[ 0 ][ 0 ].url ).toBe(
			REST + '/load?flyout=order&item_id=7'
		);
		expect( fetch.mock.calls[ 0 ][ 0 ].body ).toBeUndefined();
	} );
} );

describe( 'request()', () => {
	test( 'a page without apiFetch still uses fetch()', async () => {
		const browser = vi.fn( () =>
			Promise.resolve( {
				ok: true,
				json: () => Promise.resolve( { from: 'fetch' } ),
			} )
		);

		window.fetch = browser;

		const answer = await request(
			REST + '/save',
			{ method: 'POST' },
			null
		);

		expect( browser ).toHaveBeenCalledWith( REST + '/save', {
			method: 'POST',
		} );
		expect( await answer.json() ).toEqual( { from: 'fetch' } );
	} );

	test( 'a refusal answers like a Response that is not ok', async () => {
		const answer = await request( 'u', {}, () =>
			Promise.reject( {
				code: 'nope',
				message: 'No.',
				data: { status: 403 },
			} )
		);

		expect( answer.ok ).toBe( false );
		expect( answer.status ).toBe( 403 );
		expect( ( await answer.json() ).message ).toBe( 'No.' );
	} );

	test( 'a refusal without a status is a 400', async () => {
		const answer = await request( 'u', {}, () =>
			Promise.reject( { code: 'nope', message: 'No.' } )
		);

		expect( answer.status ).toBe( 400 );
	} );

	test.each( [
		[ 'offline', { code: 'offline_error', message: 'You are offline.' } ],
		[
			'a network failure',
			{ code: 'fetch_error', message: 'Could not reach.' },
		],
		[ 'an abort', { name: 'AbortError', message: 'Aborted.' } ],
	] )( '%s rejects the request itself', async ( name, failure ) => {
		const error = await request( 'u', {}, () =>
			Promise.reject( failure )
		).catch( ( e ) => e );

		expect( error ).toBeInstanceOf( Error );
		expect( error.message ).toBe( failure.message );
	} );

	test( 'an answer that is not JSON rejects when it is read', async () => {
		const answer = await request( 'u', {}, () =>
			Promise.reject( { code: 'invalid_json', message: 'Not JSON.' } )
		);

		await expect( answer.json() ).rejects.toBeInstanceOf( SyntaxError );
	} );
} );
