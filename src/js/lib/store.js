/**
 * What the scripts remember about an element: a panel's configuration,
 * whether its form has changed, a timer, a media frame.
 *
 * Kept on the element under a symbol from the global registry, so the
 * core script and each component's -- built separately, each with its own
 * copy of this module -- read and write the same place. That is what
 * jQuery's data store was for them before, and it goes with the element.
 */

const KEY = Symbol.for( 'sugarcommerce-flyouts' );

/**
 * The element's own record.
 *
 * @param {Element} element The element.
 * @return {Object} Its record, made when there was none.
 */
export function stash( element ) {
	if ( ! element[ KEY ] ) {
		element[ KEY ] = {};
	}

	return element[ KEY ];
}

/**
 * The configuration a panel was opened with: `manager`, `flyout`, `data`
 * (the trigger's other data-* attributes, `id` among them) and the rest of
 * what the manager read off its trigger.
 *
 * @param {Element|null} flyout The panel.
 * @return {Object} The configuration, or an empty one.
 */
export function panelConfig( flyout ) {
	return ( flyout && stash( flyout ).config ) || {};
}

/**
 * The record a request from inside a panel is about: the form's `id`,
 * else the trigger's.
 *
 * @param {Element} flyout The panel.
 * @return {string|number} The id, or 0 for a new record.
 */
export function itemId( flyout ) {
	const input = flyout.querySelector( 'input[name="id"]' );
	const config = panelConfig( flyout );

	return ( input && input.value ) || ( config.data && config.data.id ) || 0;
}
