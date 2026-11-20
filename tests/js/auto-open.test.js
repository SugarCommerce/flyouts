/**
 * A trigger marked data-flyout-auto opens itself when the page is ready:
 * how a panel opens after a round trip through the server.
 */

import { beforeAll, expect, test, vi } from 'vitest';

import { init } from '../../assets/js/manager/init';
import panel from '../../assets/js/panel/panel';
import { layout } from './support/layout';
import { panel as drawn, trigger } from './support/panels';
import { flush, publish, server } from './support/server';

const rest = vi.hoisted( () => ( { fetch: null } ) );

vi.mock( '@wordpress/api-fetch', () => ( {
	default: ( options ) => rest.fetch( options ),
} ) );

beforeAll( layout );

test( 'the first of them, once', async () => {
	vi.useFakeTimers();
	publish();

	const routes = server();

	rest.fetch = routes.fetch;
	routes.answer( '/load', ( body ) => ( {
		success: true,
		html: drawn( { id: String( body.item_id ) } ),
	} ) );
	document.body.innerHTML =
		trigger( 7 ) +
		trigger( 8, 'data-flyout-auto' ) +
		trigger( 9, 'data-flyout-auto' );

	panel.init();
	init();
	await flush( vi );

	expect( routes.calls.map( ( call ) => call.body.item_id ) ).toEqual( [
		'8',
	] );
	expect( panel.active ).toEqual( [ 'wp-flyout-order-8' ] );
	vi.useRealTimers();
} );
