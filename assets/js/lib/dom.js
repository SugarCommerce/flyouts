/**
 * The DOM helpers the scripts share, so none of them needs jQuery.
 *
 * show() and hide() do what jQuery's did, because the markup PHP prints
 * was written against them: hide() puts `display: none` on the element,
 * show() takes it off again and, if a stylesheet or the inline style PHP
 * printed still hides it, gives it the display its tag has.
 *
 * one(), all() and on() see only this build's markup (config.js, mine()):
 * a trigger or panel another plugin's copy of the library drew is never
 * found, opened, cleared or answered here.
 */

import { mine } from './config';

/**
 * Every element matching a selector that is this build's, as an array.
 *
 * @param {string}           selector CSS selector.
 * @param {Document|Element} root     Where to look.
 * @return {Element[]} The elements.
 */
export const all = ( selector, root = document ) =>
	Array.from( root.querySelectorAll( selector ) ).filter( mine );

/**
 * The first element matching a selector that is this build's.
 *
 * @param {string}           selector CSS selector.
 * @param {Document|Element} root     Where to look.
 * @return {Element|null} The element.
 */
export const one = ( selector, root = document ) =>
	all( selector, root )[ 0 ] || null;

/**
 * Listen for an event on whatever matches a selector, now or later.
 *
 * Delegated, because a panel arrives over REST long after the page loaded
 * and is replaced by the next one.
 *
 * @param {Document|Element} root     Where to listen.
 * @param {string}           type     Event type.
 * @param {string}           selector What the event must come from.
 * @param {Function}         handler  Called with ( event, matched element ).
 * @return {Function} Stops listening.
 */
export function on( root, type, selector, handler ) {
	const listener = ( event ) => {
		const target =
			event.target instanceof window.Element
				? event.target.closest( selector )
				: null;

		if ( target && root.contains( target ) && mine( target ) ) {
			handler( event, target );
		}
	};

	root.addEventListener( type, listener );

	return () => root.removeEventListener( type, listener );
}

/**
 * Whether an element takes up room on the page: jQuery's `:visible`.
 *
 * @param {Element|null} element The element.
 * @return {boolean} Shown.
 */
export const isShown = ( element ) =>
	!! element &&
	!! (
		element.offsetWidth ||
		element.offsetHeight ||
		element.getClientRects().length
	);

/**
 * Whether a control can be used: shown and not disabled, jQuery's
 * `:visible:enabled`.
 *
 * @param {Element} element The control.
 * @return {boolean} Usable.
 */
export const isUsable = ( element ) =>
	isShown( element ) && element.matches( ':enabled' );

/**
 * Hide an element.
 *
 * @param {Element|null} element The element.
 */
export function hide( element ) {
	if ( element ) {
		element.style.display = 'none';
	}
}

/**
 * The display an element's tag has when nothing hides it.
 *
 * @param {Element} element The element.
 * @return {string} table-row for a row, and so on.
 */
function naturalDisplay( element ) {
	return (
		{
			TR: 'table-row',
			TD: 'table-cell',
			TH: 'table-cell',
			TBODY: 'table-row-group',
			LI: 'list-item',
			SPAN: 'inline',
			A: 'inline',
			STRONG: 'inline',
		}[ element.tagName ] || 'block'
	);
}

/**
 * Show an element.
 *
 * @param {Element|null} element The element.
 */
export function show( element ) {
	if ( ! element ) {
		return;
	}

	element.style.display = '';

	if ( 'none' === window.getComputedStyle( element ).display ) {
		element.style.display = naturalDisplay( element );
	}
}

/**
 * An element's text, trimmed: jQuery's `$.trim( $el.text() )`.
 *
 * @param {Element|null} element The element.
 * @return {string} The text.
 */
export const text = ( element ) =>
	element ? element.textContent.trim() : '';

/**
 * Make an element.
 *
 * @param {string} tag        The tag.
 * @param {Object} attributes Attribute => value; `false`, `null` and
 *                            `undefined` are left off.
 * @param {string} [content]  Its text.
 * @return {Element} The element.
 */
export function make( tag, attributes = {}, content ) {
	const element = document.createElement( tag );

	Object.keys( attributes ).forEach( ( name ) => {
		const value = attributes[ name ];

		if ( value !== false && value !== null && value !== undefined ) {
			element.setAttribute( name, String( value ) );
		}
	} );

	if ( content !== undefined && content !== null ) {
		element.textContent = String( content );
	}

	return element;
}

/**
 * Escape a value for HTML text or a quoted attribute.
 *
 * Quotes too: text-node escaping leaves them alone, and most of what the
 * line items' template fills sits inside an attribute.
 *
 * @param {*} value Anything.
 * @return {string} Escaped text.
 */
export function escapeHtml( value ) {
	return String( value ?? '' )
		.replace( /&/g, '&amp;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' )
		.replace( /"/g, '&quot;' )
		.replace( /'/g, '&#039;' );
}

// What a <script> may be for the browser to run it, as jQuery decides.
const RUNNABLE = /^$|^module$|\/(?:java|ecma)script/i;

/**
 * Run the scripts in markup that has just gone into the page.
 *
 * Markup put in with innerHTML never runs its <script>s. jQuery's append()
 * and html() ran them, and a panel's markup is the server's own -- a field
 * may print the script that starts it -- so they still run: each is made
 * again, which the browser runs as it goes in. A template
 * (`type="text/template"`) is not a script and is left alone.
 *
 * @param {Element[]} nodes What went in.
 */
function runScripts( nodes ) {
	nodes.forEach( ( node ) => {
		if ( ! ( node instanceof window.Element ) ) {
			return;
		}

		const scripts =
			'SCRIPT' === node.tagName
				? [ node ]
				: Array.from( node.querySelectorAll( 'script' ) );

		scripts.forEach( ( old ) => {
			if ( ! RUNNABLE.test( old.type || '' ) ) {
				return;
			}

			const script = document.createElement( 'script' );

			Array.from( old.attributes ).forEach( ( attribute ) =>
				script.setAttribute( attribute.name, attribute.value )
			);

			// In order, as they were written.
			script.async = false;
			script.textContent = old.textContent;
			old.replaceWith( script );
		} );
	} );
}

/**
 * Elements from a string of markup, parsed as a <template> parses it, so
 * a lone <tr> is a row and not dropped.
 *
 * @param {string} markup Markup.
 * @return {Node[]} The top-level nodes.
 */
export function parse( markup ) {
	const template = document.createElement( 'template' );

	template.innerHTML = String( markup ?? '' );

	return Array.from( template.content.childNodes );
}

/**
 * Put markup at the end of an element (or its start), scripts and all:
 * jQuery's append() and prepend().
 *
 * @param {Element} parent    Where.
 * @param {string}  markup    Markup.
 * @param {boolean} [atStart] Before what is there, not after.
 * @return {Node[]} What went in.
 */
export function insert( parent, markup, atStart = false ) {
	const nodes = parse( markup );

	if ( atStart ) {
		parent.prepend( ...nodes );
	} else {
		parent.append( ...nodes );
	}

	runScripts( nodes );

	return nodes;
}

/**
 * Replace what an element holds with markup, scripts and all: jQuery's
 * html().
 *
 * @param {Element} element The element.
 * @param {string}  markup  Markup.
 */
export function replaceHtml( element, markup ) {
	element.replaceChildren();
	insert( element, markup );
}
