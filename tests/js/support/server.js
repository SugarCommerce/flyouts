/**
 * The panels' REST routes, as apiFetch answers them.
 *
 * Stands in for `@wordpress/api-fetch` (`vi.mock` in each suite that loads a
 * panel): every request is kept, and each route answers with whatever the
 * test said -- an object, a function of the request's body, or an error
 * the way apiFetch rejects with a WP_Error (`{ code, message, data }`).
 */

export const REST = 'https://example.test/wp-json/shop-flyouts/v1';

/**
 * A fresh server.
 *
 * @return {{fetch: Function, calls: Object[], answer: Function, refuse: Function}} The server.
 */
export function server() {
	const routes = {};
	const calls = [];

	const fetch = ( options ) => {
		const route = String( options.url )
			.replace( REST, '' )
			.split( '?' )[ 0 ];
		const body = options.body ? JSON.parse( options.body ) : null;
		const handler = routes[ route ];

		calls.push( { route, body, options } );

		if ( ! handler ) {
			return Promise.reject( {
				code: 'rest_no_route',
				message: 'No route.',
				data: { status: 404 },
			} );
		}

		return new Promise( ( resolve, reject ) => {
			const reply =
				'function' === typeof handler.reply
					? handler.reply( body )
					: handler.reply;

			( handler.refused ? reject : resolve )( reply );
		} );
	};

	return {
		fetch,
		calls,
		answer( route, reply ) {
			routes[ route ] = { reply };
		},
		refuse( route, error ) {
			routes[ route ] = { reply: error, refused: true };
		},
	};
}

/**
 * Publish a build's settings the way Manager::enqueue_assets() does.
 *
 * With no <script> to read an id from, the scripts find them under
 * `window.wpFlyout`, the oldest place they were ever published.
 *
 * @param {Object} [strings] Translated sentences.
 * @param {Object} [icons]   Icon markup by key.
 */
export function publish( strings = {}, icons = {} ) {
	window.wpFlyout = {
		restUrl: REST,
		restNonce: 'nonce',
		strings,
		icons,
	};
}

/**
 * Let a second go by on the test's fake clock, a tenth at a time, with
 * every promise settled along the way: long enough for a panel to load
 * and slide in, or a save to close it, and not for a toast to go.
 *
 * @param {Object} vi   Vitest.
 * @param {number} [ms] How long.
 * @return {Promise<void>} Settles after.
 */
export async function flush( vi, ms = 1000 ) {
	for ( let at = 0; at < ms; at += 100 ) {
		await vi.advanceTimersByTimeAsync( 100 );
	}
}
