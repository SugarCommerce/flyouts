/**
 * The line items: totals in the component's unit and the currency's
 * decimals, lines added from the template and taken away.
 */

import {
	afterEach,
	beforeAll,
	beforeEach,
	describe,
	expect,
	test,
	vi,
} from 'vitest';

import {
	bindLineItems,
	fillTemplate,
	findExistingItem,
	moneyFor,
	rowMinorUnits,
} from '../../assets/js/components/line-items';
import { formatter, say, toMinor } from '../../assets/js/lib/money';
import { emit } from '../../assets/js/lib/events';
import { flush, publish, server } from './support/server';

const rest = vi.hoisted( () => ( { fetch: null } ) );

vi.mock( '@wordpress/api-fetch', () => ( {
	default: ( options ) => rest.fetch( options ),
} ) );

const TEMPLATE = `
	<tr class="line-item" data-item-id="{{item_id}}">
		<td>{{thumbnail_html}}<span>{{name}}</span>
			<input type="hidden" name="items[{{index}}][id]" value="{{item_id}}">
			<input type="hidden" name="items[{{index}}][name]" value="{{name}}"></td>
		<td><input type="number" name="items[{{index}}][quantity]" value="1" class="quantity-input" data-action="update-quantity"></td>
		<td><span data-price="{{price}}">{{price_formatted}}</span><input type="hidden" name="items[{{index}}][price]" value="{{price}}"></td>
		<td><span class="item-total">{{total_formatted}}</span></td>
		<td><button type="button" data-action="remove-item">x</button>{{unknown}}</td>
	</tr>`;

const row = ( index, id, price, quantity = 1 ) =>
	`<tr class="line-item" data-item-id="${ id }"><td><input type="hidden" name="items[${ index }][id]" value="${ id }"></td><td><input type="number" name="items[${ index }][quantity]" value="${ quantity }" class="quantity-input" data-action="update-quantity"></td><td><span data-price="${ price }"></span></td><td><span class="item-total"></span></td><td><button type="button" data-action="remove-item">x</button></td></tr>`;

const draw = ( { money = 'major', currency = 'USD', rows = '' } = {} ) => {
	document.body.innerHTML = `<div class="wp-flyout-line-items" data-currency="${ currency }" data-locale="en-US" data-money="${ money }" data-show-quantity="1" data-manager="shop" data-flyout="order" data-details-key="details" data-empty-text="Nothing yet">
		<select class="product-ajax-select"><option value="">Pick</option><option value="5">Five</option><option value="9">Nine</option></select>
		<button type="button" data-action="add-item">Add</button>
		<div class="line-items-table">${ rows ? `<table><tbody class="line-items-list">${ rows }</tbody></table>` : '<div class="line-items-empty"></div>' }</div>
		<span class="total-amount" data-value="0"></span>
		<script type="text/template" class="line-item-template">${ TEMPLATE }</script>
	</div>`;

	return document.querySelector( '.wp-flyout-line-items' );
};

let routes;

beforeAll( bindLineItems );

beforeEach( () => {
	vi.useFakeTimers();
	publish();
	routes = server();
	rest.fetch = routes.fetch;
} );

afterEach( () => vi.useRealTimers() );

describe( 'money', () => {
	test.each( [
		[ 'USD', false, 99.99, 9999, '$99.99' ],
		[ 'USD', true, 9999, 9999, '$99.99' ],
		[ 'JPY', false, 5000, 5000, '¥5,000' ],
		[ 'KWD', false, 1.5, 1500, 'KWD 1.500' ],
	] )(
		'%s, minor %s: %s is %s minor units, said %s',
		( currency, minor, amount, units, said ) => {
			const money = formatter( currency, 'en-US', minor );

			expect( toMinor( amount, money ) ).toBe( units );
			expect( say( units, money ).replace( /\s/g, ' ' ) ).toBe( said );
		}
	);

	test( 'a currency it does not know is still a number, and does not throw', () => {
		const money = formatter( 'NOPE!', 'en-US' );

		// A plain number keeps three places.
		expect( money.factor ).toBe( 1000 );
		expect( say( 1500, money ) ).toBe( '1.5' );
	} );
} );

describe( 'totals', () => {
	test( 'a line’s price is in the component’s unit, not cents divided again', () => {
		const component = draw( { rows: row( 0, 1, 99, 2 ) } );

		expect(
			rowMinorUnits(
				component.querySelector( '.line-item' ),
				moneyFor( component )
			)
		).toBe( 19800 );
	} );

	test( 'are drawn when the page is ready, and when a panel opens', () => {
		const component = draw( {
			money: 'minor',
			rows: row( 0, 1, 1250, 2 ) + row( 1, 2, 100 ),
		} );

		emit( document, 'wpflyout:opened', {
			detail: { id: 'p', element: document.body },
		} );

		expect( component.querySelector( '.total-amount' ).textContent ).toBe(
			'$26.00'
		);
		expect(
			component
				.querySelector( '.total-amount' )
				.getAttribute( 'data-value' )
		).toBe( '26' );
	} );

	test( 'follow a quantity, which is at least one', () => {
		const component = draw( { rows: row( 0, 1, 10 ) } );
		const quantity = component.querySelector( '.quantity-input' );

		quantity.value = '3';
		quantity.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		expect( component.querySelector( '.item-total' ).textContent ).toBe(
			'$30.00'
		);
		expect( component.querySelector( '.total-amount' ).textContent ).toBe(
			'$30.00'
		);

		quantity.value = '-4';
		quantity.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		expect( quantity.value ).toBe( '1' );
		expect( component.querySelector( '.total-amount' ).textContent ).toBe(
			'$10.00'
		);
	} );
} );

describe( 'the template', () => {
	test( 'is filled in one pass, every value escaped', () => {
		const money = formatter( 'USD', 'en-US' );
		const markup = fillTemplate(
			TEMPLATE,
			{
				id: '7"x',
				name: '$& Co <b>',
				price: '9.5',
				thumbnail: 'a.jpg" onerror="x',
			},
			3,
			money
		);

		expect( markup ).toContain( 'name="items[3][id]" value="7&quot;x"' );
		expect( markup ).toContain( '<span>$&amp; Co &lt;b&gt;</span>' );
		expect( markup ).toContain(
			'<img src="a.jpg&quot; onerror=&quot;x" alt="$&amp; Co &lt;b&gt;" class="item-thumbnail">'
		);
		expect( markup ).toContain( 'data-price="9.5">$9.50</span>' );
		expect( markup ).toContain( '{{unknown}}' );
	} );

	test( 'a product with no picture gets the placeholder', () => {
		expect(
			fillTemplate(
				'{{thumbnail_html}}',
				{ id: 1 },
				0,
				formatter( 'USD' )
			)
		).toContain( 'item-thumbnail-placeholder' );
	} );
} );

describe( 'adding', () => {
	test( 'nothing chosen asks for a product', () => {
		const alert = vi
			.spyOn( window, 'alert' )
			.mockImplementation( () => {} );

		draw().querySelector( '[data-action="add-item"]' ).click();

		expect( alert ).toHaveBeenCalledWith( 'Please select a product first' );
		expect( routes.calls ).toEqual( [] );
		alert.mockRestore();
	} );

	test( 'a product already a line is one more of it', () => {
		const component = draw( { rows: row( 0, 5, 10 ) } );

		component.querySelector( 'select' ).value = '5';
		component.querySelector( '[data-action="add-item"]' ).click();

		expect( component.querySelector( '.quantity-input' ).value ).toBe(
			'2'
		);
		expect( component.querySelector( '.total-amount' ).textContent ).toBe(
			'$20.00'
		);
		expect( component.querySelector( 'select' ).value ).toBe( '' );
		expect( routes.calls ).toEqual( [] );
	} );

	test( 'a new product is looked up and drawn as the next line, the table made around the first', async () => {
		routes.answer( '/action', {
			success: true,
			product: { id: 9, name: 'Nine', price: 4.5 },
		} );
		const component = draw();

		component.querySelector( 'select' ).value = '9';
		component.querySelector( '[data-action="add-item"]' ).click();
		expect(
			component.querySelector( '[data-action="add-item"]' ).disabled
		).toBe( true );
		await flush( vi, 200 );

		expect( routes.calls[ 0 ].body ).toEqual( {
			manager: 'shop',
			flyout: 'order',
			action_key: 'details',
			item_id: '9',
		} );
		expect(
			Array.from( component.querySelectorAll( 'thead th' ) ).map(
				( th ) => th.textContent
			)
		).toEqual( [ 'Item', 'Qty', 'Price', 'Total', '' ] );
		expect( component.querySelector( '.line-item input' ).name ).toBe(
			'items[0][id]'
		);
		expect( component.querySelector( '.total-amount' ).textContent ).toBe(
			'$4.50'
		);
		expect(
			component.querySelector( '[data-action="add-item"]' ).disabled
		).toBe( false );
		expect(
			component.querySelector( '[data-action="add-item"]' ).textContent
		).toBe( 'Add' );
		expect( component.querySelector( 'select' ).value ).toBe( '' );
		expect( findExistingItem( component, '9' ) ).toBe(
			component.querySelector( '.line-item' )
		);
	} );

	test( 'a component that shows no quantity draws no quantity columns', async () => {
		routes.answer( '/action', {
			success: true,
			product: { id: 9, name: 'Nine', price: 1 },
		} );
		const component = draw();

		component.setAttribute( 'data-show-quantity', '0' );
		component.querySelector( 'select' ).value = '9';
		component.querySelector( '[data-action="add-item"]' ).click();
		await flush( vi, 200 );

		expect(
			Array.from( component.querySelectorAll( 'thead th' ) ).map(
				( th ) => th.className
			)
		).toEqual( [ 'column-item', 'column-price', 'column-actions' ] );
	} );
} );

describe( 'removing', () => {
	test( 'numbers the lines again, and the last out leaves the empty state', async () => {
		const component = draw( { rows: row( 0, 1, 10 ) + row( 1, 2, 5 ) } );

		component
			.querySelector( '.line-item [data-action="remove-item"]' )
			.click();
		await flush( vi, 400 );

		expect( component.querySelector( '.line-item input' ).name ).toBe(
			'items[0][id]'
		);
		expect(
			component.querySelector( '.line-item' ).getAttribute( 'data-index' )
		).toBe( '0' );
		expect( component.querySelector( '.total-amount' ).textContent ).toBe(
			'$5.00'
		);

		component
			.querySelector( '.line-item [data-action="remove-item"]' )
			.click();
		await flush( vi, 400 );

		expect( component.querySelector( '.line-item' ) ).toBeNull();
		expect(
			component.querySelector( '.line-items-empty p' ).textContent
		).toBe( 'Nothing yet' );
		expect( component.querySelector( '.total-amount' ).textContent ).toBe(
			'$0.00'
		);
	} );
} );
