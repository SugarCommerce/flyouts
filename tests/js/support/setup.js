/**
 * The page every suite starts on: the core script running under its handle,
 * as WordPress prints it, so the scripts find their build's entry in
 * `window.SugarCommerceFlyouts` (lib/config.js) the way they do on a page.
 */

Object.defineProperty( document, 'currentScript', {
	value: { id: 'sugarcommerce-flyouts-wp-flyout-js' },
	configurable: true,
} );
