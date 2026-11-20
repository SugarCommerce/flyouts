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
 *
 * That key is the build's name on the page too. PHP stamps it on every
 * trigger and panel it draws, as `data-flyout-build`, and the scripts act
 * only on what carries their own (mine()), so two plugins' copies on one
 * page each answer their own triggers and leave the other's panels alone.
 * Nothing is read from or written to a bare page global: the panel API is
 * the entry's `panel`, and the fields modules a panel starts are those of
 * the fields script the entry names (`fields`).
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
	const key = keyOf( element, registry );

	return key ? registry[ key ] : fallback;
}

/**
 * The key a script's element names in a registry.
 *
 * @param {Element|null} element  The <script> element, or null.
 * @param {Object}       registry window.SugarCommerceFlyouts.
 * @return {string} The key, or '' when nothing matches.
 */
export function keyOf( element, registry ) {
	const id = element && element.id ? element.id.replace( /-js$/, '' ) : '';
	const entries = registry || {};
	let best = '';

	if ( id && entries[ id ] && 'object' === typeof entries[ id ] ) {
		return id;
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

	return best;
}

/**
 * This build's settings: restUrl, restNonce, strings, icons, fields and,
 * once the core script has run, panel.
 *
 * @return {Object} The settings, or nothing.
 */
export function settings() {
	return resolve( script, window.SugarCommerceFlyouts );
}

/**
 * This build's name: its entry's key, which PHP stamps on what it draws as
 * `data-flyout-build`.
 *
 * @return {string} The key, or '' when the page published nothing.
 */
export function build() {
	return keyOf( script, window.SugarCommerceFlyouts );
}

/**
 * Whether an element is this build's to act on: inside nothing stamped
 * with a build, or inside something stamped with this one.
 *
 * Markup nobody stamped -- a trigger written by hand -- is anybody's, as it
 * always was; markup another copy drew is that copy's alone.
 *
 * @param {Element|null} element The element.
 * @return {boolean} Ours.
 */
export function mine( element ) {
	const marked =
		element && 'function' === typeof element.closest
			? element.closest( '[data-flyout-build]' )
			: null;

	return ! marked || marked.getAttribute( 'data-flyout-build' ) === build();
}

/**
 * This build's entry, made if the page published none, for the core script
 * to put the panel API in.
 *
 * Keyed as PHP keys it: the core script's handle without its "-wp-flyout".
 *
 * @return {Object} The entry.
 */
export function own() {
	const registry = ( window.SugarCommerceFlyouts =
		window.SugarCommerceFlyouts || {} );
	const key = keyOf( script, registry );

	if ( key ) {
		return registry[ key ];
	}

	const handle =
		script && script.id
			? script.id.replace( /-js$/, '' ).replace( /-wp-flyout$/, '' )
			: '';

	if ( ! handle ) {
		return {};
	}

	registry[ handle ] = {};

	return registry[ handle ];
}

/**
 * The fields package's modules: those of this build's own copy, which PHP
 * names in the settings (`fields`, its script's handle).
 *
 * Asked at the moment they are wanted: the fields script may run after
 * this one.
 *
 * @return {Object|null} The modules, or null when they are not there.
 */
export function fieldsModules() {
	const handle = settings().fields;
	const entry = handle
		? ( window.SugarCommerceFields || {} )[ handle ]
		: null;

	return ( entry && entry.modules ) || null;
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
