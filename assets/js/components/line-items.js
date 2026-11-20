/**
 * The line items component: the lines of an order, a product added from
 * the fields package's combobox, quantities and the total.
 *
 * A product is looked up through the panel's `details_key` action over
 * REST. Prices are in the component's unit (`data-money`): 99.99 by
 * default, 9999 with `money => 'minor'` -- the same thing `data-price`
 * holds on a server-drawn line and the details callback returns.
 */

import { __ } from '@wordpress/i18n';

import { icon, mine, strings } from '../lib/config';
import { all, escapeHtml, make, on, parse } from '../lib/dom';
import { formatter, say, toMinor } from '../lib/money';
import { fadeOut } from '../lib/motion';
import { api } from '../lib/request';
import { stash } from '../lib/store';

/**
 * The component's money: its currency, the page's locale, its unit.
 *
 * @param {Element} component The `.wp-flyout-line-items`.
 * @return {Object} formatter()'s.
 */
export function moneyFor( component ) {
	return formatter(
		component.dataset.currency || 'USD',
		component.dataset.locale,
		'minor' === component.dataset.money
	);
}

/**
 * One line's total, in whole minor units: its price times its quantity.
 *
 * @param {Element} row   The `.line-item`.
 * @param {Object}  money moneyFor()'s.
 * @return {number} Minor units.
 */
export function rowMinorUnits( row, money ) {
	const priced = row.querySelector( '[data-price]' );
	const quantity = row.querySelector( '.quantity-input' );
	const price = parseFloat( priced ? priced.dataset.price : '' ) || 0;

	return (
		toMinor( price, money ) *
		( parseInt( quantity ? quantity.value : '', 10 ) || 1 )
	);
}

/**
 * Write one line's total.
 *
 * @param {Element} row The `.line-item`.
 */
export function updateRowTotal( row ) {
	const money = moneyFor( row.closest( '.wp-flyout-line-items' ) );

	all( '.item-total', row ).forEach( ( total ) => {
		total.textContent = say( rowMinorUnits( row, money ), money );
	} );
}

/**
 * Write the component's total.
 *
 * @param {Element} component The `.wp-flyout-line-items`.
 */
export function recalculateTotals( component ) {
	const money = moneyFor( component );
	const total = all( '.line-item', component ).reduce(
		( sum, row ) => sum + rowMinorUnits( row, money ),
		0
	);

	all( '.total-amount', component ).forEach( ( amount ) => {
		amount.textContent = say( total, money );
		amount.setAttribute( 'data-value', String( total / money.factor ) );
	} );
}

/**
 * Number the lines' inputs from nought again, after one has gone.
 *
 * @param {Element} component The `.wp-flyout-line-items`.
 */
export function reindexItems( component ) {
	all( '.line-item', component ).forEach( ( row, index ) => {
		row.setAttribute( 'data-index', String( index ) );

		all( 'input', row ).forEach( ( input ) => {
			const name = input.getAttribute( 'name' );

			if ( name ) {
				input.setAttribute(
					'name',
					name.replace( /\[\d+\]/, '[' + index + ']' )
				);
			}
		} );
	} );
}

/**
 * The line already holding a product, if there is one.
 *
 * @param {Element} component The `.wp-flyout-line-items`.
 * @param {string}  id        The product's id.
 * @return {Element|null} The line.
 */
export function findExistingItem( component, id ) {
	return (
		all( '.line-item', component ).find( ( row ) => {
			const field = row.querySelector( '[name*="[id]"]' );
			const held =
				row.getAttribute( 'data-item-id' ) || ( field && field.value );

			return String( held ) === String( id );
		} ) || null
	);
}

/**
 * Fill the line template with a product.
 *
 * Every value the server sent is escaped for the attribute or the text it
 * lands in, except the thumbnail's markup, which is built here from
 * escaped parts. One pass with a function, not a chain of replacements: a
 * string given to replace() is read for $-patterns, so a product named
 * "$& Co" came out corrupted, and a value holding a later placeholder
 * would have been replaced twice.
 *
 * @param {string} template The template's markup.
 * @param {Object} product  `id`, `name`, `price`, `thumbnail`.
 * @param {number} index    The line's place.
 * @param {Object} money    moneyFor()'s.
 * @return {string} The line's markup.
 */
export function fillTemplate( template, product, index, money ) {
	const price = parseFloat( product.price ) || 0;
	const formatted = escapeHtml( say( toMinor( price, money ), money ) );
	const thumbnail = product.thumbnail
		? '<img src="' +
			escapeHtml( product.thumbnail ) +
			'" alt="' +
			escapeHtml( product.name ) +
			'" class="item-thumbnail">'
		: '<div class="item-thumbnail-placeholder">' +
			icon( 'image', 'format-image' ) +
			'</div>';

	const values = {
		index: String( index ),
		item_id: escapeHtml( product.id ?? '' ),
		name: escapeHtml( product.name || '' ),
		price: escapeHtml( price ),
		price_formatted: formatted,
		total_formatted: formatted,
		thumbnail: escapeHtml( product.thumbnail || '' ),
		thumbnail_html: thumbnail,
	};

	return template.replace( /{{(\w+)}}/g, ( match, key ) =>
		Object.prototype.hasOwnProperty.call( values, key )
			? values[ key ]
			: match
	);
}

/**
 * Add a product as a new line.
 *
 * @param {Element} component The `.wp-flyout-line-items`.
 * @param {Object}  product   The details callback's product.
 */
export function addItemToTable( component, product ) {
	let body = component.querySelector( '.line-items-list' );

	// The first line: the table is drawn around it.
	if ( ! body ) {
		const quantity = ! [ '0', 'false' ].includes(
			component.dataset.showQuantity
		);
		const table = component.querySelector( '.line-items-table' );
		const head = ( name, label ) =>
			'<th class="column-' + name + '">' + escapeHtml( label ) + '</th>';

		if ( ! table ) {
			return;
		}

		table.innerHTML =
			'<table><thead><tr>' +
			head( 'item', __( 'Item', 'sugarcommerce' ) ) +
			( quantity
				? head( 'quantity', __( 'Qty', 'sugarcommerce' ) )
				: '' ) +
			head( 'price', __( 'Price', 'sugarcommerce' ) ) +
			( quantity
				? head( 'total', __( 'Total', 'sugarcommerce' ) )
				: '' ) +
			'<th class="column-actions"></th>' +
			'</tr></thead><tbody class="line-items-list"></tbody></table>';
		body = component.querySelector( '.line-items-list' );
	}

	const template = component.querySelector( '.line-item-template' );

	if ( ! template || ! template.textContent.trim() ) {
		// eslint-disable-next-line no-console
		console.error( 'Line Items: Template not found' );
		return;
	}

	const markup = fillTemplate(
		template.textContent,
		product,
		all( '.line-item', body ).length,
		moneyFor( component )
	);

	parse( markup.trim() )
		.filter( ( node ) => node instanceof window.Element )
		.forEach( ( row ) => {
			body.append( row );
			row.style.background = '#ffffcc';
			setTimeout( () => {
				row.style.background = '';
			}, 1000 );
		} );

	recalculateTotals( component );
}

/**
 * Put the product search back to holding nothing.
 *
 * The fields package's combobox keeps the chosen label in an input of its
 * own and the value on the select underneath, so emptying the select is
 * only half of it -- the previous product's name would stay in the box and
 * read as though it were still chosen.
 *
 * @param {Element|null} select The product select.
 */
export function clearSelect( select ) {
	if ( ! select ) {
		return;
	}

	const wrap = select.closest( '.field-kit__combobox' );
	const clear = wrap && wrap.querySelector( '.field-kit__combobox-clear' );

	if ( clear ) {
		clear.click();
		return;
	}

	// No combobox -- the script never reached it -- so the plain select is
	// all there is to clear.
	select.value = '';
	select.dispatchEvent( new window.Event( 'change', { bubbles: true } ) );
}

/**
 * Look a product up and add it.
 *
 * @param {Element} component The `.wp-flyout-line-items`.
 * @param {string}  id        The product's id.
 */
export function fetchProductDetails( component, id ) {
	const button = component.querySelector( '[data-action="add-item"]' );
	const label = button ? button.innerHTML : '';

	if ( button ) {
		button.disabled = true;
		button.innerHTML =
			'<span class="dashicons dashicons-update spin"></span> ';
		button.append( __( 'Loading…', 'sugarcommerce' ) );
	}

	api( '/action', {
		manager: component.dataset.manager,
		flyout: component.dataset.flyout,
		action_key: component.dataset.detailsKey,
		item_id: String( id ),
	} )
		.then( ( response ) => {
			if ( response.success && response.product ) {
				addItemToTable( component, response.product );
				clearSelect(
					component.querySelector( '.product-ajax-select' )
				);
			} else {
				// eslint-disable-next-line no-alert
				window.alert(
					response.message ||
						__( 'Product details not found', 'sugarcommerce' )
				);
			}
		} )
		.catch( ( error ) =>
			// eslint-disable-next-line no-alert
			window.alert( error.message || strings().requestFailed )
		)
		.finally( () => {
			if ( button ) {
				button.disabled = false;
				button.innerHTML = label;
			}
		} );
}

/**
 * Add the chosen product, or one more of it when it is already a line.
 *
 * @param {Element} component The `.wp-flyout-line-items`.
 */
export function handleAdd( component ) {
	const select = component.querySelector( '.product-ajax-select' );
	const id = select ? select.value : '';

	if ( ! id ) {
		// eslint-disable-next-line no-alert
		window.alert( __( 'Please select a product first', 'sugarcommerce' ) );
		return;
	}

	const existing = findExistingItem( component, id );

	if ( existing ) {
		const quantity = existing.querySelector( '.quantity-input' );

		if ( quantity ) {
			quantity.value = ( parseInt( quantity.value, 10 ) || 1 ) + 1;
			quantity.dispatchEvent(
				new window.Event( 'change', { bubbles: true } )
			);
		}

		clearSelect( select );
		return;
	}

	fetchProductDetails( component, id );
}

/**
 * Take a line away, and say so when it was the last.
 *
 * @param {Element} row The `.line-item`.
 */
export function handleRemove( row ) {
	const component = row.closest( '.wp-flyout-line-items' );
	const body = row.closest( '.line-items-list' );

	fadeOut( row, 300 ).then( () => {
		row.remove();
		reindexItems( component );

		if ( body && ! body.querySelector( '.line-item' ) ) {
			const empty = make( 'div', { class: 'line-items-empty' } );

			empty.innerHTML = icon( 'cart', 'cart' );
			empty.append( make( 'p', {}, component.dataset.emptyText || '' ) );

			all( '.line-items-table', component ).forEach( ( table ) =>
				table.replaceChildren( empty.cloneNode( true ) )
			);
		}

		recalculateTotals( component );
	} );
}

/**
 * A quantity changed: at least one, and the totals after it.
 *
 * @param {Element} input The quantity box.
 */
export function handleQuantityChange( input ) {
	const component = input.closest( '.wp-flyout-line-items' );
	const row = input.closest( '.line-item' );

	input.value = Math.max( 1, parseInt( input.value, 10 ) || 1 );

	if ( row ) {
		updateRowTotal( row );
	}

	recalculateTotals( component );
}

/**
 * Total every component inside an element, once.
 *
 * @param {Element} container Where to look.
 */
export function initComponents( container ) {
	all( '.wp-flyout-line-items', container ).forEach( ( component ) => {
		const kept = stash( component );

		if ( kept.ready ) {
			return;
		}

		// The fields package's combobox upgrades the select; it is bound
		// when the panel opens, with every other field in it.
		kept.ready = true;
		recalculateTotals( component );
	} );
}

/**
 * Make every line items component work, the ones in panels yet to load
 * included.
 */
export function bindLineItems() {
	on(
		document,
		'click',
		'.wp-flyout-line-items [data-action="add-item"]',
		( event, button ) => {
			event.preventDefault();
			handleAdd( button.closest( '.wp-flyout-line-items' ) );
		}
	);

	on(
		document,
		'click',
		'.wp-flyout-line-items [data-action="remove-item"]',
		( event, button ) => {
			event.preventDefault();

			const row = button.closest( '.line-item' );

			if ( row ) {
				handleRemove( row );
			}
		}
	);

	on(
		document,
		'change',
		'.wp-flyout-line-items [data-action="update-quantity"]',
		( event, input ) => handleQuantityChange( input )
	);

	document.addEventListener( 'wpflyout:opened', ( event ) => {
		if (
			event.detail &&
			event.detail.element &&
			mine( event.detail.element )
		) {
			initComponents( event.detail.element );
		}
	} );

	initComponents( document );
}
