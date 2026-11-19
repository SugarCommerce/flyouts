/**
 * What PHP handed the scripts.
 *
 * The only module that reads the page's configuration. Each build of this
 * library -- the plain one, or a Strauss-prefixed copy inside another plugin
 * -- publishes its REST URL, its nonce, the translated strings and the
 * library's icons into `window.SugarCommerceFlyouts`, keyed by its core
 * script's handle (Manager::enqueue_assets()). A shared bare global would
 * leave whichever build localized last owning the REST URL for both.
 *
 * A script finds its own build's entry from the id WordPress stamps on its
 * <script> element, "{handle}-js": the entry under that handle, or failing
 * that the longest key the handle starts with. A component's handle is
 * "{prefix}-{component}" and the entry is published once, under "{prefix}".
 */

// Read while the script runs. Once it has, document.currentScript is null.
const script = document.currentScript;

/*
 * Everything the scripts say to a person, in English.
 *
 * Replaced by what the page publishes beside the REST URL, which is where
 * the translated ones come from (Manager::strings(), the same keys). The
 * English stays as the fallback: a panel that says "undefined" on its
 * Discard button is worse than one that says it in English.
 */
const ENGLISH = {
	saved: 'Saved',
	deleted: 'Deleted',
	done: 'Done',
	loading: 'Loading…',
	loadFailed: 'This could not be opened',
	requestFailed: 'Something went wrong. Please try again.',
	required: 'Please fill in all required fields.',
	close: 'Close',
	previous: 'Previous',
	next: 'Next',
	stepped: '%1$s, %2$s of %3$s',
	stepKeysHint: 'Next and previous',
	stepEnd: 'That is the end of the list.',
	copy: 'Copy',
	copied: 'Copied',
	copyFailed: 'That could not be copied.',
	unsavedTitle: 'Discard unsaved changes?',
	unsavedText: 'What you have changed here has not been saved.',
	keepEditing: 'Keep editing',
	discard: 'Discard changes',
	deleteTitle: 'Are you sure you want to delete this item?',
	deleteConfirm: 'Delete',
	cancel: 'Cancel',
	confirm: 'Continue',
	send: 'Send',
	promptDiscard: 'Discard what you have written?',
	keepWriting: 'Keep Writing',
	promptDrop: 'Discard',
	promptEmpty: 'This needs filling in.',
};

/**
 * The entry a script's element names in a registry.
 *
 * @param {Element|null} element  The <script> element, or null.
 * @param {Object}       registry window.SugarCommerceFlyouts.
 * @param {Object}       fallback What to use when nothing matches.
 * @return {Object} The build's settings.
 */
export function resolve( element, registry, fallback = {} ) {
	const id = element && element.id ? element.id.replace( /-js$/, '' ) : '';
	const entries = registry || {};
	let best = '';

	if ( id && entries[ id ] && 'object' === typeof entries[ id ] ) {
		return entries[ id ];
	}

	Object.keys( entries ).forEach( ( key ) => {
		// Only settings: an older copy of the scripts kept two functions
		// in the same object.
		if ( 'object' !== typeof entries[ key ] || ! entries[ key ] ) {
			return;
		}

		if ( key && id.startsWith( key ) && key.length > best.length ) {
			best = key;
		}
	} );

	return best ? entries[ best ] : fallback;
}

/**
 * This build's settings: restUrl, restNonce, strings and icons.
 *
 * @return {Object} The settings, or a page's bare `wpFlyout`, or nothing.
 */
export function settings() {
	return resolve(
		script,
		window.SugarCommerceFlyouts,
		window.wpFlyout || {}
	);
}

/**
 * Every sentence the scripts say, translated where the page published one.
 *
 * @return {Object} Key => sentence.
 */
export function strings() {
	return { ...ENGLISH, ...( settings().strings || {} ) };
}

/**
 * One of the library's own icons, as the server drew it.
 *
 * They are the fields package's cleaned SVG and go in as they are. A page
 * that published none gets the Dashicon each one used to be.
 *
 * @param {string} key      The icon's key in Icon::markup(), or ''.
 * @param {string} dashicon The Dashicon to fall back to.
 * @return {string} Markup.
 */
export function icon( key, dashicon ) {
	const icons = settings().icons || {};

	return (
		( key && icons[ key ] ) ||
		'<span class="dashicons dashicons-' +
			String( dashicon ).replace( /[^a-z0-9_-]/gi, '' ) +
			' sugarcommerce-icon" aria-hidden="true"></span>'
	);
}
