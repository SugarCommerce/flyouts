/**
 * The panels' requests to their REST routes.
 *
 * Through wp.apiFetch, because the nonce printed with the page runs out
 * after a day: a screen left open overnight then got a 403 from every
 * panel. apiFetch notices the stale nonce, asks for a fresh one and sends
 * the request again.
 */

import apiFetch from '@wordpress/api-fetch';

import { settings, strings } from './config';

/**
 * A request, answered with something shaped like a fetch Response.
 *
 * `ok`, `status` and `json()`, so a caller reads a refusal the way it read
 * one from fetch(); a body that was not JSON makes json() reject, as it
 * did. A network failure rejects the request itself.
 *
 * @param {string}   url     The route's full URL.
 * @param {Object}   init    As fetch's: method, headers, body.
 * @param {Function} [fetch] apiFetch, or a stand-in for it.
 * @return {Promise<Object>} The answer.
 */
export function request( url, init = {}, fetch = apiFetch ) {
	if ( 'function' !== typeof fetch ) {
		return window.fetch( url, init );
	}

	const answer = ( ok, status, json, unreadable ) => ( {
		ok,
		status,
		json: () =>
			unreadable
				? Promise.reject(
						new SyntaxError( ( json && json.message ) || '' )
					)
				: Promise.resolve( json ),
	} );

	return fetch( {
		url,
		method: init.method || 'GET',
		headers: init.headers || {},
		body: init.body,
		parse: true,
	} ).then(
		( json ) => answer( true, 200, json, false ),
		( error ) => {
			if (
				! error ||
				'object' !== typeof error ||
				'AbortError' === error.name ||
				'fetch_error' === error.code ||
				'offline_error' === error.code
			) {
				throw error instanceof Error
					? error
					: new Error( ( error && error.message ) || '' );
			}

			return answer(
				false,
				( error.data && error.data.status ) || 400,
				error,
				'invalid_json' === error.code
			);
		}
	);
}

/**
 * Ask one of this build's routes something.
 *
 * A refusal rejects with an Error carrying the answer's `code` and
 * `data`, which is where a save says which field was wrong. An answer
 * that is not JSON at all -- a fatal error's page, a proxy's -- rejects
 * with "Something went wrong": what the parser thought of it is of no use
 * to the person looking at the panel.
 *
 * @param {string}   route    '/load', '/save', '/delete' or '/action'.
 * @param {Object}   data     What to send.
 * @param {string}   [method] POST, or GET to send it as the query.
 * @param {Function} [fetch]  apiFetch, or a stand-in for it.
 * @return {Promise<Object>} The answer's JSON.
 */
export function api( route, data, method = 'POST', fetch = apiFetch ) {
	const build = settings();
	const init = {
		method,
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': build.restNonce,
		},
		credentials: 'same-origin',
	};

	let url = build.restUrl + route;

	if ( 'GET' === method ) {
		url += '?' + new URLSearchParams( data ).toString();
	} else {
		init.body = JSON.stringify( data );
	}

	return request( url, init, fetch ).then( ( response ) =>
		response.json().then(
			( json ) => {
				if ( ! response.ok ) {
					const error = new Error(
						( json && json.message ) || strings().requestFailed
					);

					error.code = json && json.code;
					error.data = ( json && json.data ) || {};

					throw error;
				}

				return json;
			},
			() => {
				throw new Error( strings().requestFailed );
			}
		)
	);
}
