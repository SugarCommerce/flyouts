/**
 * Every field type in a flyout panel, in a real browser.
 *
 * The PHP suite renders a panel's markup and never runs a line of its
 * script, so what breaks only once the markup is in a page -- a code
 * editor that never starts, a visual editor with an empty toolbar, a list
 * clipped by the panel, a second open that finds the first one's editor --
 * is invisible to it. This opens the SugarCommerce Playground's "Fields in
 * panels" page (three panels: one of every type, one of nesting with tabs,
 * accordions and conditions, one of the configurations that change a
 * control) and, for every field:
 *
 * - renders: its script added what it adds outside a panel, on the
 *   playground's Fields page, and filling it through its own control works
 *   (typing, the combobox list, the media modal, the icon dialog, the
 *   colour presets, the move buttons), with nothing clipped or covered;
 * - saves: Save reloads the page and the option holds a value for it;
 * - reloads: the panel opened again shows what was sent;
 * - re-init: the same panel opened again without a reload, after another
 *   panel, takes a second value, saves it and shows it;
 * - mobile: at 390 pixels it draws the same, fits the panel and fills.
 *
 * Usage, with the playground active and puppeteer-core installed:
 *
 *   node tests/browser/fields-in-panels.js <cookies.json> [every,nested,variants]
 *
 * cookies.json is {"admin": "https://site/wp-admin/", "cookies": [{"name",
 * "value"}]} for an administrator (the auth and logged-in cookies). Set
 * CHROME to the browser to drive. It clears the panels' stored values
 * first, writes results.json and screenshots under shots/ beside the
 * cookie file, prints a type-by-column table, and exits non-zero when any
 * cell fails or the page logged an error.
 */

'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const puppeteer = require( 'puppeteer-core' );

const [ , , cookieFile, only ] = process.argv;
const info = JSON.parse( fs.readFileSync( cookieFile, 'utf8' ) );
const out = path.dirname( path.resolve( cookieFile ) );
const shots = path.join( out, 'shots' );
fs.mkdirSync( shots, { recursive: true } );

const PAGE = 'admin.php?page=playground-field-panels';
const PANELS = only ? only.split( ',' ) : [ 'every', 'nested', 'variants' ];
const FLY = '.wp-flyout.active';
const wait = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );
const SHOWN = 'const shown = ( e ) => !! e && e.getClientRects().length > 0 && "hidden" !== getComputedStyle( e ).visibility;';

// Types that show something and hold nothing to save.
const DISPLAY = [ 'heading', 'separator', 'message', 'html', 'action_button', 'clipboard', 'tab', 'accordion' ];
// Types whose parts depend on what they hold.
const COMPOUND = [ 'repeater', 'flexible', 'gallery', 'group', 'files', 'list', 'key_value', 'tags' ];

// The layout fields: they draw no wrapper of their own, so each is looked
// for by what it should draw -- a tab strip or an accordion, with its label.
const MARKERS = {
	nested: [
		{ key: 'tab_mode', type: 'tab', label: 'Mode' },
		{ key: 'tab_brand', type: 'tab', label: 'Brand' },
		{ key: 'acc_address', type: 'accordion', label: 'Address' },
		{ key: 'acc_team', type: 'accordion', label: 'Team' },
	],
};

async function markers( page, panel, column ) {
	for ( const m of MARKERS[ panel ] || [] ) {
		const found = await page.evaluate( ( fly, type, label ) => {
			const selector = 'tab' === type ? '.wp-flyout-body [role="tab"]:not(.wp-flyout-tab)' : '.wp-flyout-body .privacy-settings-accordion-trigger';
			return [ ...document.querySelectorAll( fly + ' ' + selector ) ].filter( ( e ) => e.textContent.trim() === label ).length;
		}, FLY, m.type, m.label );
		const c = cell( panel, m.key, m.type );
		mark( c, column, 1 === found, 1 === found ? '' : 'drew ' + found + ' ' + m.type + ' called ' + m.label );
		if ( 'renders' === column ) {
			mark( c, 'saves', 'n/a' );
			mark( c, 'reloads', 'n/a' );
		}
	}
}

// Types whose stored value is never shown back.
const SECRET = [ 'password' ];

const errors = [];
process.on( 'unhandledRejection', ( e ) => errors.push( 'unhandled: ' + ( e && e.message ) ) );
let stage = 'start';

/* ---------------------------------------------------------------------------
 * Page helpers
 * ------------------------------------------------------------------------- */

async function launch() {
	const browser = await puppeteer.launch( {
		executablePath: process.env.CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
		headless: 'new',
		acceptInsecureCerts: true,
		args: [ '--ignore-certificate-errors' ],
	} );
	const page = await browser.newPage();
	const host = new URL( info.admin ).hostname;
	await browser.setCookie( ...info.cookies.map( ( c ) => ( { ...c, domain: host, path: '/', secure: true, httpOnly: true } ) ) );
	page.on( 'console', ( m ) => {
		// The Fields page is only read for comparison; what it logs is not
		// about panels.
		// Chrome's permissions policy refuses the `unload` listener TinyMCE
		// adds, and says so; core's own editor on any screen logs the same.
		if ( 'error' === m.type() && 'reference' !== stage && ! /favicon|unload is not allowed/.test( m.text() ) ) {
			errors.push( stage + ' | console: ' + m.text() );
		}
	} );
	page.on( 'pageerror', ( e ) => errors.push( stage + ' | pageerror: ' + e.message ) );
	page.on( 'response', ( r ) => {
		if ( r.status() >= 400 && ! /favicon/.test( r.url() ) ) {
			errors.push( stage + ' | http ' + r.status() + ' ' + r.url() );
		}
	} );
	page.on( 'dialog', ( d ) => d.accept() );
	page.on( 'response', async ( r ) => {
		if ( /flyouts\/v1\/save/.test( r.url() ) && process.env.VERBOSE ) {
			console.error( 'save answered', r.status(), ( await r.text().catch( () => '' ) ).slice( 0, 300 ) );
		}
	} );
	return { browser, page };
}

async function load( page ) {
	await page.goto( info.admin + PAGE, { waitUntil: 'networkidle2', timeout: 90000 } );
}

async function open( page, panel ) {
	await page.click( `.wp-flyout-trigger[data-flyout="${ panel }"]` );
	await page.waitForSelector( `${ FLY } .wp-flyout-field`, { timeout: 30000 } );
	await page.waitForFunction( ( fly ) => ! document.querySelector( fly + ' .wp-flyout-skeleton' ), { timeout: 30000 }, FLY );
	await wait( 1200 );
}

async function close( page ) {
	await page.click( `${ FLY } .wp-flyout-close` );
	await wait( 400 );
	// A panel with changes asks first; discarding is the answer here.
	const discard = await page.$( `.wp-flyout .wp-flyout-prompt button.field-kit__button--delete, .wp-flyout [data-prompt-confirm], .wp-flyout .wp-flyout-confirm-yes` );
	if ( discard ) {
		await discard.click();
	}
	await page.waitForFunction( () => ! document.querySelector( '.wp-flyout.active' ), { timeout: 10000 } ).catch( async () => {
		await page.evaluate( () => document.querySelectorAll( '.wp-flyout' ).forEach( ( f ) => window.WPFlyout && window.WPFlyout.close( f.id, { force: true } ) ) );
	} );
	await wait( 600 );
}

async function save( page ) {
	const nav = page.waitForNavigation( { waitUntil: 'networkidle2', timeout: 60000 } ).then( () => 'saved', ( e ) => 'no reload: ' + e.message );
	await page.click( `${ FLY } .wp-flyout-save` );
	const result = await Promise.race( [
		nav,
		page.waitForSelector( `${ FLY } .wp-flyout-alert, ${ FLY } .field-kit__error, ${ FLY } .wp-flyout-field-error`, { timeout: 30000 } ).then( async () => 'refused: ' + await page.evaluate( ( fly ) => [ ...document.querySelectorAll( fly + ' .wp-flyout-alert, ' + fly + ' .wp-flyout-field-error, ' + fly + ' .field-kit__error' ) ].map( ( e ) => e.textContent.trim() ).join( ' / ' ), FLY ) ).catch( () => 'neither saved nor refused' ),
	] );
	if ( 'saved' !== result ) {
		await nav;
	}
	return result;
}

async function stored( page, panel ) {
	return page.evaluate( ( id ) => {
		const pre = document.getElementById( 'sc-playground-field-panels-stored' );
		const all = pre ? JSON.parse( pre.textContent || '{}' ) : {};
		return ( all && all[ 'scpgf_' + id ] ) || {};
	}, panel );
}

/**
 * The top-level fields of the open panel, in order: key and type.
 */
async function fields( page ) {
	return page.evaluate( ( fly ) => [ ...document.querySelectorAll( fly + ' [data-field-key]' ) ]
		.filter( ( e ) => ! e.parentElement.closest( '[data-field-key]' ) )
		.map( ( e ) => ( {
			key: e.dataset.fieldKey,
			type: ( [ ...e.classList ].find( ( c ) => /^field-kit__field--(?!conditional)/.test( c ) ) || '' ).replace( 'field-kit__field--', '' ),
		} ) ), FLY );
}

async function handle( page, key ) {
	const all = await page.$$( `${ FLY } [data-field-key="${ key }"]` );
	for ( const el of all ) {
		if ( await el.evaluate( ( e ) => ! e.parentElement.closest( '[data-field-key]' ) ) ) {
			return el;
		}
	}
	return null;
}

/**
 * Bring a field into view: its panel tab, its field-kit tab, its accordion,
 * its card.
 */
async function reveal( el ) {
	await el.evaluate( ( e ) => {
		let node = e;
		const steps = [];
		while ( node && node !== document.body ) {
			if ( node.getAttribute && 'tabpanel' === node.getAttribute( 'role' ) && node.id ) {
				steps.unshift( () => {
					const tab = document.querySelector( '[aria-controls="' + node.id + '"]' );
					if ( tab && 'true' !== tab.getAttribute( 'aria-selected' ) ) tab.click();
				} );
			}
			if ( node.classList && node.classList.contains( 'wp-flyout-tab-content' ) && ! node.classList.contains( 'active' ) ) {
				const id = node.id.replace( /^tab-/, '' );
				const n = node;
				steps.unshift( () => {
					const tab = document.querySelector( '.wp-flyout-tab[data-tab="' + id + '"]' ) || document.querySelector( '[aria-controls="' + n.id + '"]' );
					if ( tab ) tab.click();
				} );
			}
			if ( node.hidden && node.id ) {
				const n = node;
				steps.unshift( () => {
					const button = document.querySelector( 'button[aria-controls="' + n.id + '"]' );
					if ( button && n.hidden ) button.click();
				} );
			}
			if ( node.tagName === 'DETAILS' && ! node.open ) {
				const n = node;
				steps.unshift( () => { n.open = true; } );
			}
			node = node.parentElement;
		}
		steps.forEach( ( s ) => s() );
	} );
	await wait( 150 );
	await el.evaluate( ( e ) => e.scrollIntoView( { block: 'center' } ) );
	await wait( 100 );
}

/**
 * What a field would send, as name=value lines.
 */
function snapshot( el ) {
	return el.evaluate( ( e ) => {
		const lines = [];
		e.querySelectorAll( 'input[name], select[name], textarea[name]' ).forEach( ( i ) => {
			if ( i.disabled ) return;
			if ( ( 'checkbox' === i.type || 'radio' === i.type ) && ! i.checked ) return;
			if ( 'SELECT' === i.tagName ) {
				[ ...i.selectedOptions ].forEach( ( o ) => lines.push( i.name + '=' + o.value ) );
				return;
			}
			let value = i.value;
			if ( 'TEXTAREA' === i.tagName && window.tinymce && i.id && window.tinymce.get( i.id ) && ! window.tinymce.get( i.id ).isHidden() ) {
				value = window.tinymce.get( i.id ).getContent();
			}
			if ( 'TEXTAREA' === i.tagName && i.nextElementSibling && i.nextElementSibling.CodeMirror ) {
				value = i.nextElementSibling.CodeMirror.getValue();
			}
			lines.push( i.name + '=' + value.replace( /<\/?p>/g, '' ).replace( /\s+/g, ' ' ).trim() );
		} );
		return lines;
	} );
}

/**
 * The parts of a field its script adds, counted: a second copy of any of
 * them on a second open is a script that ran twice, none is one that did not
 * run.
 */
function signature( el ) {
	return el.evaluate( ( e ) => {
		const count = ( s ) => e.querySelectorAll( s ).length;
		return {
			combobox: count( '[role="combobox"]' ),
			codemirror: count( '.CodeMirror' ),
			tinymce: count( '.mce-tinymce, .tox-tinymce' ),
			quicktags: count( '.quicktags-toolbar .ed_button, .quicktags-toolbar input' ),
			swatch: count( '.field-kit__color-swatch, .wp-picker-container' ),
			move: count( '[data-direction]' ),
			tabs: count( '[role="tab"]' ),
			accordion: count( '.privacy-settings-accordion-trigger' ),
			toggles: count( '[role="switch"]' ),
		};
	} );
}

/* ---------------------------------------------------------------------------
 * Filling, by type. Each takes the field's wrapper and which round it is
 * (0 or 1), so the second round sends something different.
 * ------------------------------------------------------------------------- */

async function typeInto( el, selector, text ) {
	const input = await el.$( selector );
	if ( ! input ) throw new Error( 'no ' + selector );
	await input.click( { clickCount: 3 } );
	await input.evaluate( ( i ) => { i.value = ''; } );
	await input.type( text, { delay: 5 } );
	await input.evaluate( ( i ) => i.dispatchEvent( new Event( 'change', { bubbles: true } ) ) );
}

async function setValue( el, selector, value ) {
	const ok = await el.evaluate( ( e, s, v ) => {
		const i = e.querySelector( s );
		if ( ! i ) return false;
		i.value = v;
		i.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		i.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		return true;
	}, selector, value );
	if ( ! ok ) throw new Error( 'no ' + selector );
}

async function clickLabel( el, value ) {
	const ok = await el.evaluate( ( e, v ) => {
		const i = [ ...e.querySelectorAll( 'input' ) ].find( ( x ) => x.value === v && ( 'radio' === x.type || 'checkbox' === x.type ) );
		if ( ! i ) return false;
		const label = i.closest( 'label' ) || ( i.id && document.querySelector( 'label[for="' + i.id + '"]' ) ) || i;
		label.scrollIntoView( { block: 'center' } );
		return true;
	}, value );
	if ( ! ok ) throw new Error( 'no option ' + value );
	const input = await el.evaluateHandle( ( e, v ) => {
		const i = [ ...e.querySelectorAll( 'input' ) ].find( ( x ) => x.value === v && ( 'radio' === x.type || 'checkbox' === x.type ) );
		return i.closest( 'label' ) || ( i.id && document.querySelector( 'label[for="' + i.id + '"]' ) ) || i;
	}, value );
	await input.click();
}

/**
 * Pick from a combobox through its list: type, wait for the options, check
 * the list is on screen and not clipped by the panel, click one.
 */
async function combobox( page, el, query, nth = 0 ) {
	const input = await el.$( 'input[role="combobox"]' );
	if ( ! input ) throw new Error( 'no combobox' );
	await input.click();
	await input.evaluate( ( i ) => { i.value = ''; } );
	if ( query ) await input.type( query, { delay: 20 } );
	else await page.keyboard.press( 'ArrowDown' );
	const listId = await input.evaluate( ( i ) => i.getAttribute( 'aria-controls' ) );
	await page.waitForFunction( ( id ) => {
		const list = document.getElementById( id );
		return list && ! list.hidden && list.querySelectorAll( '[role="option"]:not([aria-disabled="true"])' ).length > 0;
	}, { timeout: 15000 }, listId );
	// A searched list answers after the typing: wait for the list that
	// matches what was typed, not the one that was there before.
	if ( query ) {
		await page.waitForFunction( ( id, q ) => {
			const options = [ ...document.getElementById( id ).querySelectorAll( '[role="option"]:not([aria-disabled="true"])' ) ];
			return options.length > 0 && options.every( ( o ) => o.textContent.toLowerCase().includes( q.toLowerCase() ) );
		}, { timeout: 4000 }, listId, query ).catch( () => {} );
	}
	const chosen = await el.evaluate( ( e ) => [ ...e.querySelectorAll( 'select option:checked' ) ].map( ( o ) => o.textContent.trim() ).filter( Boolean ) );
	const where = await page.evaluate( ( id, n, taken ) => {
		const all = [ ...document.getElementById( id ).querySelectorAll( '[role="option"]:not([aria-disabled="true"])' ) ].filter( ( o ) => ! /^Add “.*” as a new /.test( o.textContent.trim() ) );
		// What is already chosen is not chosen again, and an offer to
		// create what was typed is the last resort.
		// Never the row that makes a new post, term or user: a run must
		// not leave things behind in the site.
		const fresh = all.filter( ( o ) => ! taken.includes( o.textContent.trim() ) && 'true' !== o.getAttribute( 'aria-selected' ) && ! /^Add “.*” as a new /.test( o.textContent.trim() ) );
		const found = fresh.filter( ( o ) => ! /^Add\s/.test( o.textContent.trim() ) );
		const options = found.length ? found : fresh;
		const option = ( options.length ? options : all )[ Math.min( n, ( options.length ? options : all ).length - 1 ) ];
		option.scrollIntoView( { block: 'nearest' } );
		const r = option.getBoundingClientRect();
		const x = r.left + Math.min( 20, r.width / 2 );
		const y = r.top + r.height / 2;
		const top = document.elementFromPoint( x, y );
		return { x, y, onTop: !! top && ( top === option || option.contains( top ) ), inView: r.bottom <= window.innerHeight && r.top >= 0, text: option.textContent.trim() };
	}, listId, nth, chosen );
	if ( ! where.inView || ! where.onTop ) {
		throw new Error( 'list option not reachable (inView ' + where.inView + ', onTop ' + where.onTop + ')' );
	}
	await page.mouse.click( where.x, where.y );
	await wait( 250 );
	// A multiple select keeps its list open; Escape closes the list, and
	// only the list -- pressed with nothing open it would ask the panel to
	// close.
	if ( await page.evaluate( ( id ) => { const l = document.getElementById( id ); return !! l && ! l.hidden && !! l.offsetParent; }, listId ) ) {
		await input.press( 'Escape' );
	}
	return where.text;
}

/**
 * Choose an attachment in the media modal opened from a button.
 */
async function media( page, el, buttonSelector, nth ) {
	const button = await el.$( buttonSelector );
	if ( ! button ) throw new Error( 'no ' + buttonSelector );
	await button.click();
	await page.waitForFunction( () => [ ...document.querySelectorAll( '.media-modal' ) ].some( ( m ) => m.getClientRects().length > 0 ), { timeout: 15000 } );
	await wait( 600 );
	const onTop = await page.evaluate( () => {
		const modal = [ ...document.querySelectorAll( '.media-modal' ) ].find( ( m ) => m.getClientRects().length > 0 );
		const r = modal.getBoundingClientRect();
		const top = document.elementFromPoint( r.left + r.width / 2, r.top + 40 );
		return !! top && modal.contains( top );
	} );
	if ( ! onTop ) throw new Error( 'media modal is under the panel' );
	// The library, where a frame opened on its upload tab.
	for ( let tries = 0; tries < 20; tries++ ) {
		const ready = await page.evaluate( () => {
			const modal = [ ...document.querySelectorAll( '.media-modal' ) ].find( ( m ) => m.getClientRects().length > 0 );
			if ( [ ...modal.querySelectorAll( '.attachments .attachment' ) ].some( ( a ) => a.offsetParent ) ) return true;
			const tab = [ ...modal.querySelectorAll( '.media-router .media-menu-item, .media-menu-item' ) ].find( ( b ) => /library/i.test( b.textContent ) && b.offsetParent );
			if ( tab ) tab.click();
			return false;
		} );
		if ( ready ) break;
		await wait( 750 );
	}
	const items = await page.$$( '.media-modal .attachments .attachment' );
	const visible = [];
	for ( const i of items ) if ( await i.evaluate( ( e ) => !! e.offsetParent ) ) visible.push( i );
	await visible[ Math.min( nth, visible.length - 1 ) ].click();
	await wait( 300 );
	const select = await page.evaluateHandle( () => [ ...document.querySelectorAll( '.media-modal .media-button' ) ].find( ( b ) => b.offsetParent && ! b.disabled ) );
	await select.asElement().click();
	await page.waitForFunction( () => ! [ ...document.querySelectorAll( '.media-modal' ) ].some( ( m ) => m.getClientRects().length > 0 ), { timeout: 10000 } );
	await wait( 300 );
}

async function icon( page, el, nth ) {
	const button = await el.$( '.field-kit__icon-button' );
	await button.click();
	await page.waitForFunction( () => [ ...document.querySelectorAll( '.field-kit__icon-dialog [role="option"]' ) ].some( ( o ) => o.offsetParent ), { timeout: 15000 } );
	await wait( 300 );
	const where = await page.evaluate( ( n ) => {
		const options = [ ...document.querySelectorAll( '.field-kit__icon-dialog [role="option"]' ) ].filter( ( o ) => o.offsetParent && 'true' !== o.getAttribute( 'aria-selected' ) );
		const o = options[ Math.min( n, options.length - 1 ) ];
		o.scrollIntoView( { block: 'nearest' } );
		const r = o.getBoundingClientRect();
		const top = document.elementFromPoint( r.left + r.width / 2, r.top + r.height / 2 );
		return { x: r.left + r.width / 2, y: r.top + r.height / 2, onTop: !! top && o.contains( top ) };
	}, nth + 3 );
	if ( ! where.onTop ) throw new Error( 'icon dialog is under the panel' );
	await page.mouse.click( where.x, where.y );
	await wait( 300 );
	// A dialog that wants confirming.
	const confirm = await page.evaluateHandle( () => [ ...document.querySelectorAll( '.field-kit__icon-dialog button' ) ].find( ( b ) => b.offsetParent && /^(select|choose|use|done|insert)/i.test( b.textContent.trim() ) ) );
	if ( confirm.asElement() ) await confirm.asElement().click();
	await wait( 300 );
	if ( await page.evaluate( () => [ ...document.querySelectorAll( '.field-kit__icon-dialog' ) ].some( ( d ) => d.getClientRects().length > 0 ) ) ) {
		await page.keyboard.press( 'Escape' );
	}
}

async function editor( page, el, text ) {
	const frame = await el.$( 'iframe' );
	if ( frame && await frame.evaluate( ( f ) => !! f.offsetParent ) ) {
		const doc = await frame.contentFrame();
		await doc.click( 'body' );
		await doc.evaluate( () => { document.body.innerHTML = ''; } );
		await doc.type( 'body', text, { delay: 5 } );
		return 'visual';
	}
	const area = await el.$( 'textarea.wp-editor-area' );
	await area.click( { clickCount: 3 } );
	await area.evaluate( ( a ) => { a.value = ''; } );
	await area.type( text, { delay: 5 } );
	return 'textarea';
}

async function relational( page, el, round ) {
	// Search by a letter every sample title has; the second round takes the
	// second match.
	return combobox( page, el, round ? 'e' : 'a', round );
}

const FILL = {
	text: ( p, el, r ) => typeInto( el, 'input', r ? 'Second text' : 'Panel text' ),
	email: ( p, el, r ) => typeInto( el, 'input', r ? 'two@example.com' : 'panel@example.com' ),
	url: ( p, el, r ) => typeInto( el, 'input', r ? 'https://example.com/two' : 'https://example.com/panel' ),
	tel: ( p, el, r ) => typeInto( el, 'input', r ? '+44 113 496 0002' : '+44 113 496 0001' ),
	password: ( p, el, r ) => typeInto( el, 'input[type="password"], input[type="text"]', r ? 'second-secret' : 'panel-secret' ),
	hidden: async () => 'nothing to fill',
	number: ( p, el, r ) => typeInto( el, 'input', r ? '7' : '42' ),
	percentage: ( p, el, r ) => typeInto( el, 'input', r ? '30' : '15' ),
	range: ( p, el, r ) => setValue( el, 'input[type="range"]', r ? '25' : '75' ),
	custom: ( p, el, r ) => setValue( el, 'input[type="range"]', r ? '20' : '80' ),
	textarea: ( p, el, r ) => typeInto( el, 'textarea', r ? 'Second lines' : 'Panel lines' ),
	code_generator: async ( p, el ) => {
		await ( await el.$( 'button' ) ).click();
		await wait( 300 );
	},
	clipboard: async () => 'nothing to fill',
	code: async ( p, el, r ) => {
		const cm = await el.$( '.CodeMirror' );
		const text = r ? '.two { color: red; }' : '.panel { color: blue; }';
		if ( cm ) {
			await cm.click();
			await p.keyboard.down( 'Meta' );
			await p.keyboard.press( 'a' );
			await p.keyboard.up( 'Meta' );
			await p.keyboard.press( 'Backspace' );
			await p.keyboard.type( text, { delay: 5 } );
			return 'codemirror';
		}
		await typeInto( el, 'textarea', text );
		return 'textarea';
	},
	wysiwyg: ( p, el, r ) => editor( p, el, r ? 'Second rich words' : 'Panel rich words' ),
	email_editor: async ( p, el, r ) => {
		const panel = await el.$( '.field-kit__accordion-panel, [id$="__panel"]' );
		if ( panel && await panel.evaluate( ( x ) => x.hidden ) ) await ( await el.$( '.privacy-settings-accordion-trigger' ) ).click();
		await wait( 200 );
		for ( const part of [ 'recipient', 'subject', 'heading' ] ) {
			if ( await el.$( `input[name$="[${ part }]"]` ) ) await typeInto( el, `input[name$="[${ part }]"]`, ( r ? 'two ' : 'panel ' ) + part + ( 'recipient' === part ? '@example.com' : '' ) );
		}
		return editor( p, el, r ? 'Second email body' : 'Panel email body' );
	},
	date: ( p, el, r ) => setValue( el, 'input', r ? '2027-01-02' : '2026-12-25' ),
	time: ( p, el, r ) => setValue( el, 'input', r ? '08:15' : '13:45' ),
	datetime: ( p, el, r ) => setValue( el, 'input', r ? '2027-01-02T08:15' : '2026-12-25T13:45' ),
	date_range: async ( p, el, r ) => {
		await setValue( el, 'input[name$="[from]"]', r ? '2027-02-01' : '2026-12-01' );
		await setValue( el, 'input[name$="[to]"]', r ? '2027-02-28' : '2026-12-31' );
	},
	time_range: async ( p, el, r ) => {
		await setValue( el, 'input[name$="[from]"]', r ? '10:00' : '09:00' );
		await setValue( el, 'input[name$="[to]"]', r ? '18:00' : '17:00' );
	},
	color: async ( p, el, r ) => {
		const presets = await el.$$( '.field-kit__color-preset' );
		if ( presets.length > 2 ) {
			await presets[ r ? 1 : 2 ].click();
			return 'preset';
		}
		const iris = await el.$( '.wp-color-result' );
		if ( iris ) {
			await iris.click();
			await wait( 200 );
			await typeInto( el, 'input.wp-color-picker, input.field-kit__color', r ? '#224466' : '#336699' );
			await p.keyboard.press( 'Enter' );
			await wait( 200 );
			return 'iris';
		}
		await typeInto( el, 'input[type="text"]', r ? '#224466' : '#336699' );
		return 'hex';
	},
	select: async ( p, el, r ) => {
		const values = await el.$eval( 'select', ( s ) => [ ...s.options ].map( ( o ) => o.value ).filter( Boolean ) );
		await ( await el.$( 'select' ) ).select( values[ r ? 1 : Math.min( 2, values.length - 1 ) ] );
	},
	select_multiple: async ( p, el, r ) => {
		await ( await el.$( 'select' ) ).select( ...( r ? [ 'small' ] : [ 'medium', 'huge' ] ) );
	},
	enhanced_select: async ( p, el, r ) => {
		if ( await el.$( 'input[role="combobox"]' ) ) {
			// Typed as a person would: the start of the label they want.
			const labels = await el.$eval( 'select', ( s ) => [ ...s.options ].filter( ( o ) => o.value ).map( ( o ) => o.textContent.trim() ) );
			const want = labels[ Math.min( r ? 1 : 2, labels.length - 1 ) ] || '';
			return combobox( p, el, want.slice( 0, 3 ) );
		}
		return FILL.select( p, el, r );
	},
	country: ( p, el, r ) => combobox( p, el, r ? 'France' : 'Germany' ),
	currency: ( p, el, r ) => combobox( p, el, r ? 'Pound' : 'Yen' ),
	region: async ( p, el, r ) => {
		// The country beside it was just chosen, and its regions are asked
		// for over REST: the control settles when they arrive.
		await wait( 1500 );
		const select = await el.$( 'select:not([disabled])' );
		if ( select && await select.evaluate( ( s ) => !! s.offsetParent || !! s.closest( '.field-kit__combobox' ) ) ) {
			if ( await el.$( 'input[role="combobox"]' ) ) return combobox( p, el, '', r ? 1 : 2 );
			const values = await select.evaluate( ( s ) => [ ...s.options ].map( ( o ) => o.value ).filter( Boolean ) );
			if ( ! values.length ) throw new Error( 'region select has no options' );
			await select.select( values[ Math.min( r ? 1 : 2, values.length - 1 ) ] );
			return 'select';
		}
		await typeInto( el, 'input[type="text"]:not([disabled])', r ? 'Second region' : 'Panel region' );
		return 'text';
	},
	post: ( p, el, r ) => relational( p, el, r ),
	page: ( p, el, r ) => relational( p, el, r ),
	user: ( p, el, r ) => combobox( p, el, 'a', r ),
	taxonomy: ( p, el, r ) => relational( p, el, r ),
	ajax: ( p, el, r ) => combobox( p, el, r ? 'Sma' : 'Lar' ),
	checkbox: async ( p, el ) => { await ( await el.$( 'input[type="checkbox"]' ) ).click(); },
	toggle: async ( p, el ) => { await ( await el.$( 'input[type="checkbox"]' ) ).click(); },
	radio: ( p, el, r ) => pickChoice( el, r ),
	button_group: ( p, el, r ) => pickChoice( el, r ),
	card_choice: ( p, el, r ) => pickChoice( el, r ),
	checkbox_group: async ( p, el, r ) => {
		const values = await el.$$eval( 'input[type="checkbox"]', ( list ) => list.map( ( i ) => i.value ) );
		await clickLabel( el, values[ r ? 1 : 0 ] );
		await clickLabel( el, values[ values.length - 1 ] );
	},
	tags: ( p, el, r ) => typeInto( el, 'input[type="text"]', r ? 'one|two'.replace( '|', separatorOf( el ) ) : 'red, green' ),
	image: ( p, el, r ) => media( p, el, '.field-kit__media-choose', r ? 1 : 0 ),
	file: ( p, el, r ) => media( p, el, '.field-kit__media-choose', r ? 1 : 0 ),
	file_url: ( p, el, r ) => media( p, el, '.field-kit__media-choose', r ? 1 : 0 ),
	files: async ( p, el, r ) => {
		await addRow( p, el );
		const row = await lastRow( el );
		await fillWithin( p, row, r );
	},
	// A tile from the library, added to the grid: the first time the first
	// picture, the second time another beside it.
	gallery: ( p, el, r ) => media( p, el, '.field-kit__gallery-add', r ? 1 : 0 ),
	group: ( p, el, r ) => fillWithin( p, el, r ),
	repeater: async ( p, el, r ) => {
		await addRow( p, el );
		const row = await lastRow( el );
		await fillWithin( p, row, r );
	},
	// A row of the last layout offered, filled; the second time that row
	// is duplicated as well, and the copy must carry what was typed.
	flexible: async ( p, el, r ) => {
		await addRow( p, el );
		const row = await lastRow( el );
		await fillWithin( p, row, r );
		if ( r ) {
			const typed = await row.evaluate( ( e ) => [ ...e.querySelectorAll( '.field-kit__repeater-fields [name]' ) ].map( ( i ) => i.value ) );
			await ( await row.$( ':scope > .field-kit__repeater-header .field-kit__repeater-duplicate' ) ).click();
			await wait( 300 );
			const copy = await lastRow( el );
			const copied = await copy.evaluate( ( e ) => [ ...e.querySelectorAll( '.field-kit__repeater-fields [name]' ) ].map( ( i ) => i.value ) );
			if ( JSON.stringify( typed ) !== JSON.stringify( copied ) ) throw new Error( 'duplicate did not copy: ' + JSON.stringify( copied ) );
			return 'duplicated';
		}
	},
	list: async ( p, el, r ) => {
		await addRow( p, el );
		const inputs = await el.$$( 'input[type="text"]' );
		const last = inputs[ inputs.length - 1 ];
		await last.click();
		await last.type( r ? 'Second perk' : 'Panel perk', { delay: 5 } );
	},
	key_value: async ( p, el, r ) => {
		await addRow( p, el );
		const row = await lastRow( el );
		const inputs = await row.$$( 'input[type="text"]' );
		await inputs[ 0 ].type( r ? 'size' : 'colour', { delay: 5 } );
		await inputs[ 1 ].type( r ? 'large' : 'blue', { delay: 5 } );
	},
	sortable: async ( p, el, r ) => {
		const box = await el.$( 'input[type="checkbox"]:not(:checked)' );
		if ( box ) await box.click();
		const downs = await el.$$( '[data-direction="down"], [data-action="move-down"]' );
		const enabled = [];
		for ( const d of downs ) if ( await d.evaluate( ( b ) => ! b.disabled ) ) enabled.push( d );
		if ( ! enabled.length ) throw new Error( 'no move button' );
		await enabled[ r ? 1 : 0 ].focus();
		await p.keyboard.press( 'Enter' );
		await wait( 200 );
	},
	amount_type: async ( p, el, r ) => {
		await typeInto( el, 'input[type="number"], input[type="text"]', r ? '9' : '5' );
		const select = await el.$( 'select' );
		if ( select ) await select.select( r ? 'percent' : 'flat' );
	},
	money: ( p, el, r ) => typeInto( el, 'input[type="text"], input[type="number"]', r ? '7.25' : '12.50' ),
	icon: ( p, el, r ) => icon( p, el, r ),
	license: async ( p, el, r ) => {
		await typeInto( el, 'input[type="text"]', r ? 'PG-TWO-2222' : 'PG-ABCD-1234' );
		const button = await el.$( 'button' );
		if ( button ) {
			await button.click();
			await p.waitForFunction( ( e ) => /activated|refused|deactivated/i.test( e.textContent ), { timeout: 15000 }, el );
		}
	},
	action_button: async ( p, el ) => {
		await ( await el.$( 'button' ) ).click();
		await p.waitForFunction( ( e ) => /Ran with/i.test( e.textContent ), { timeout: 15000 }, el );
		return 'answered';
	},
	slug: ( p, el, r ) => typeInto( el, 'input[data-slug]', r ? 'second-slug' : 'panel-slug' ),
	rating: ( p, el, r ) => clickLabel( el, r ? '2' : '4.5' ),
	dimension: async ( p, el, r ) => {
		await typeInto( el, 'input[type="number"]', r ? '7' : '2.5' );
		const select = await el.$( 'select' );
		if ( select ) await select.select( r ? 'lb' : 'g' );
	},
	embed: async ( p, el, r ) => {
		await typeInto( el, 'input[type="url"]', r ? 'https://vimeo.com/76979871' : 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' );
		await p.waitForFunction( ( e ) => !! e.querySelector( '.field-kit__embed-preview iframe' ), { timeout: 10000 }, el );
		return 'preview';
	},
	location: async ( p, el, r ) => {
		await typeInto( el, '.field-kit__location-address', r ? 'Paris' : 'Leeds' );
		await ( await el.$( '.field-kit__location-find' ) ).click();
		await p.waitForFunction( ( e, lat ) => e.querySelector( '.field-kit__location-lat' ).value === lat, { timeout: 10000 }, el, r ? '48.8566' : '53.8008' );
		return 'found';
	},
	address: async ( p, el, r ) => {
		await el.$eval( 'select.field-kit__address-country', ( s, c ) => {
			s.value = c;
			s.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}, r ? 'US' : 'GB' );
		await p.waitForFunction( ( e, word ) => new RegExp( word ).test( e.querySelector( '[data-part="postcode"] label' ).textContent ), { timeout: 10000 }, el, r ? 'ZIP' : 'Postcode' );
		await typeInto( el, '[data-part="line1"] input', r ? '1 Infinite Loop' : '10 Park Row' );
		await typeInto( el, '[data-part="city"] input', r ? 'Cupertino' : 'Leeds' );
		if ( r ) {
			await el.$eval( '.field-kit__address-state-select', ( s ) => {
				s.value = 'CA';
				s.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			} );
		}
		await typeInto( el, '[data-part="postcode"] input', r ? '95014' : 'LS1 5JL' );
		return r ? 'US' : 'GB';
	},
	heading: async () => 'display',
	tab: async () => 'display',
	accordion: async () => 'display',
	separator: async () => 'display',
	message: async () => 'display',
	html: async () => 'display',
};

function separatorOf() {
	return '|';
}

async function pickChoice( el, r ) {
	const values = await el.$$eval( 'input[type="radio"]', ( list ) => list.filter( ( i ) => ! i.checked ).map( ( i ) => i.value ) );
	await clickLabel( el, values[ r ? 0 : values.length - 1 ] );
}

async function addRow( page, el ) {
	const before = await el.evaluate( ( e ) => e.querySelectorAll( '.field-kit__repeater-row, .field-kit__list-item, [data-row], .field-kit__repeater-rows > *' ).length );
	const button = await el.evaluateHandle( ( e ) => [ ...e.querySelectorAll( 'button' ) ].filter( ( b ) => b.offsetParent && /^(add|\+)/i.test( b.textContent.trim() ) && ! b.closest( '.field-kit__repeater-rows > *' ) ).pop() );
	if ( ! button.asElement() ) throw new Error( 'no add button' );
	await button.asElement().click();
	await wait( 300 );
	const after = await el.evaluate( ( e ) => e.querySelectorAll( '.field-kit__repeater-row, .field-kit__list-item, [data-row], .field-kit__repeater-rows > *' ).length );
	if ( after - before !== 1 && after > 0 ) {
		throw new Error( 'add made ' + ( after - before ) + ' rows' );
	}
}

async function lastRow( el ) {
	const rows = await el.$$( '.field-kit__repeater-rows > *' );
	if ( ! rows.length ) throw new Error( 'no rows' );
	return rows[ rows.length - 1 ];
}

/**
 * Fill every field inside a group, a row or a file entry, by its type.
 */
async function fillWithin( page, scope, round ) {
	const subs = await scope.$$( '[data-field-key]' );
	for ( const sub of subs ) {
		const direct = await sub.evaluate( ( s, scopeEl ) => {
			const parent = s.parentElement.closest( '[data-field-key]' );
			return ! parent || parent === scopeEl || ! scopeEl.contains( parent );
		}, scope );
		if ( ! direct ) continue;
		const meta = await sub.evaluate( ( s ) => ( {
			type: ( [ ...s.classList ].find( ( c ) => /^field-kit__field--(?!conditional)/.test( c ) ) || '' ).replace( 'field-kit__field--', '' ),
			shown: !! s.offsetParent && ! s.querySelector( ':scope input:disabled, :scope select:disabled' ) || ( !! s.offsetParent && !! s.querySelector( 'input:not(:disabled), select:not(:disabled), textarea:not(:disabled)' ) ),
		} ) );
		if ( ! meta.shown || ! FILL[ meta.type ] ) continue;
		await reveal( sub );
		await FILL[ meta.type ]( page, sub, round );
	}
	// Anything a condition revealed on the way.
	const hidden = await scope.$$( '[data-field-key].field-kit__field--conditional' );
	for ( const sub of hidden ) {
		const meta = await sub.evaluate( ( s ) => ( {
			type: ( [ ...s.classList ].find( ( c ) => /^field-kit__field--(?!conditional)/.test( c ) ) || '' ).replace( 'field-kit__field--', '' ),
			shown: !! s.offsetParent && !! s.querySelector( 'input:not(:disabled), select:not(:disabled), textarea:not(:disabled)' ),
			empty: ! [ ...s.querySelectorAll( 'input, textarea' ) ].some( ( i ) => i.value && 'hidden' !== i.type ),
		} ) );
		if ( meta.shown && meta.empty && FILL[ meta.type ] ) {
			await FILL[ meta.type ]( page, sub, round );
		}
	}
}

/* ---------------------------------------------------------------------------
 * One panel
 * ------------------------------------------------------------------------- */

const results = {};
const cell = ( panel, key, type ) => {
	const id = panel + ':' + key;
	results[ id ] = results[ id ] || { panel, key, type, renders: '', saves: '', reloads: '', reinit: '', mobile: '', notes: [] };
	return results[ id ];
};
const mark = ( c, column, ok, note ) => {
	if ( false === ok || 'fail' === c[ column ] ) {
		c[ column ] = 'fail';
	} else if ( 'n/a' === ok ) {
		c[ column ] = c[ column ] || 'n/a';
	} else {
		c[ column ] = 'ok';
	}
	if ( note ) c.notes.push( column + ': ' + note );
};

/**
 * Fill the whole open panel; returns per key: { before, after, note, error }.
 */
async function fillPanel( page, panel, round ) {
	const list = await fields( page );
	const done = {};
	for ( const { key, type } of list ) {
		const el = await handle( page, key );
		if ( ! el ) continue;
		const errorsBefore = errors.length;
		stage = panel + ' fill#' + round + ' ' + key;
		const entry = { type, before: await snapshot( el ) };
		const visible = await el.evaluate( ( e ) => !! e.offsetParent || !! e.closest( '[role="tabpanel"], .wp-flyout-tab-content, [hidden]' ) );
		const disabled = await el.evaluate( ( e ) => e.classList.contains( 'field-kit__field--conditional' ) && ! e.querySelector( 'input:not(:disabled), select:not(:disabled), textarea:not(:disabled)' ) );
		// A field a badge locks is not for changing, by design.
		const locked = await el.evaluate( ( e ) => !! e.querySelector( 'input, select, textarea' ) && ! e.querySelector( 'input:not(:disabled):not([type="hidden"]), select:not(:disabled), textarea:not(:disabled)' ) );
		if ( disabled || locked || ! visible ) {
			entry.note = disabled ? 'hidden by condition' : ( locked ? 'locked' : 'not visible' );
			entry.after = entry.before;
			done[ key ] = entry;
			continue;
		}
		try {
			await reveal( el );
			if ( ! FILL[ type ] ) throw new Error( 'no filler for ' + type );
			entry.note = await FILL[ type ]( page, el, round ) || '';
		} catch ( e ) {
			entry.error = e.message.split( '\n' )[ 0 ];
		}
		entry.after = await snapshot( el );
		entry.jsErrors = errors.slice( errorsBefore );
		if ( entry.error || process.env.VERBOSE ) console.error( stage, entry.note || '', entry.error || '', JSON.stringify( entry.after ).slice( 0, 200 ) );
		// Whatever a failed fill left open.
		if ( entry.error ) {
			await page.evaluate( () => document.querySelectorAll( '.media-modal-close' ).forEach( ( b ) => b.getClientRects().length && b.click() ) );
			if ( await page.evaluate( () => [ ...document.querySelectorAll( '.field-kit__icon-dialog' ) ].some( ( d ) => d.getClientRects().length > 0 ) ) ) {
				await page.keyboard.press( 'Escape' );
			}
		}
		done[ key ] = entry;
	}
	// Conditional fields revealed by fields after them.
	for ( const key of Object.keys( done ) ) {
		if ( 'hidden by condition' !== done[ key ].note ) continue;
		const el = await handle( page, key );
		const shown = await el.evaluate( ( e ) => !! e.querySelector( 'input:not(:disabled), select:not(:disabled), textarea:not(:disabled)' ) );
		if ( ! shown ) continue;
		try {
			await reveal( el );
			done[ key ].note = 'revealed: ' + ( await FILL[ done[ key ].type ]( page, el, round ) || '' );
		} catch ( e ) {
			done[ key ].error = e.message.split( '\n' )[ 0 ];
		}
		done[ key ].after = await snapshot( el );
	}
	return done;
}

async function readPanel( page ) {
	const list = await fields( page );
	const map = {};
	for ( const { key, type } of list ) {
		const el = await handle( page, key );
		map[ key ] = { type, snap: await snapshot( el ), sig: await signature( el ) };
	}
	return map;
}

const same = ( a, b ) => JSON.stringify( a ) === JSON.stringify( b );

function judgeRound( panel, filled, saveResult, store, reread, column, sigFirst ) {
	for ( const [ key, f ] of Object.entries( filled ) ) {
		const c = cell( panel, key, f.type );
		const display = DISPLAY.includes( f.type );
		if ( f.error ) {
			mark( c, column === 'saves' ? 'renders' : 'reinit', false, 'fill: ' + f.error );
		}
		if ( f.jsErrors && f.jsErrors.length ) {
			mark( c, column === 'saves' ? 'renders' : 'reinit', false, 'js: ' + f.jsErrors[ 0 ].split( '|' ).pop().trim().slice( 0, 160 ) );
		}
		if ( display ) {
			if ( 'saves' === column ) {
				mark( c, 'saves', 'n/a' );
				mark( c, 'reloads', 'n/a' );
			} else {
				mark( c, 'reinit', 'n/a' );
			}
			continue;
		}
		const changed = ! same( f.before, f.after );
		const hasValue = Object.prototype.hasOwnProperty.call( store, key ) && ! [ '', null, undefined ].includes( store[ key ] ) && ! ( Array.isArray( store[ key ] ) && ! store[ key ].length );
		const fillable = ! [ 'hidden', 'clipboard' ].includes( f.type ) && ! /hidden by condition|not visible|locked/.test( f.note || '' );
		const savedOk = 'saved' === saveResult && hasValue;
		const back = reread[ key ] ? reread[ key ].snap : null;
		const reloadOk = SECRET.includes( f.type ) ? hasValue : same( back, f.after );
		if ( 'saves' === column ) {
			if ( fillable && ! changed && ! f.error ) mark( c, 'renders', false, 'filling changed nothing' );
			mark( c, 'saves', savedOk || ( ! fillable && 'saved' === saveResult ), savedOk ? '' : 'stored: ' + JSON.stringify( store[ key ] ) + ( 'saved' !== saveResult ? ' (' + saveResult + ')' : '' ) );
			mark( c, 'reloads', reloadOk, reloadOk ? '' : 'sent ' + JSON.stringify( f.after ).slice( 0, 160 ) + ' / shown ' + JSON.stringify( back ).slice( 0, 160 ) );
		} else {
			// Second round, on a panel opened twice: it has to take the new
			// value and keep it.
			const ok = ( ! fillable || changed ) && ( savedOk || ! fillable ) && reloadOk;
			mark( c, 'reinit', ok, ok ? '' : 'second open: changed ' + changed + ', saved ' + savedOk + ', shown again ' + reloadOk + ( reloadOk ? '' : ' sent ' + JSON.stringify( f.after ).slice( 0, 120 ) + ' / shown ' + JSON.stringify( back ).slice( 0, 120 ) ) );
		}

	}
}

let reference = null;

async function referenceSignatures( page ) {
	await page.goto( info.admin + 'admin.php?page=playground-fields', { waitUntil: 'networkidle2', timeout: 90000 } );
	await wait( 1500 );
	const map = {};
	const keys = await page.evaluate( () => [ ...document.querySelectorAll( '[data-field-key]' ) ].filter( ( e ) => ! e.parentElement.closest( '[data-field-key]' ) ).map( ( e ) => e.dataset.fieldKey ) );
	for ( const key of keys ) {
		const all = await page.$$( `[data-field-key="${ key }"]` );
		for ( const el of all ) {
			if ( await el.evaluate( ( e ) => ! e.parentElement.closest( '[data-field-key]' ) ) ) {
				map[ key ] = map[ key ] || await signature( el );
				break;
			}
		}
	}
	return map;
}

async function runPanel( page, panel ) {
	// Round one: fresh page, first open.
	await load( page );
	stage = panel + ' open#1';
	await open( page, panel );
	const first = await readPanel( page );
	for ( const [ key, f ] of Object.entries( first ) ) {
		const c = cell( panel, key, f.type );
		const scripted = f.sig.combobox + f.sig.codemirror + f.sig.tinymce + f.sig.quicktags + f.sig.swatch + f.sig.move;
		mark( c, 'renders', true );
		c.sig = f.sig;
		void scripted;
		const ref = reference && reference[ key ];
		if ( ref && ! COMPOUND.includes( f.type ) && ! same( ref, f.sig ) ) {
			mark( c, 'renders', false, 'not what it is outside a panel: ' + JSON.stringify( ref ) + ' here ' + JSON.stringify( f.sig ) );
		}
	}
	await markers( page, panel, 'renders' );
	await page.screenshot( { path: path.join( shots, panel + '-1440-open.png' ) } );
	const filled = await fillPanel( page, panel, 0 );
	await page.screenshot( { path: path.join( shots, panel + '-1440-filled.png' ) } );
	stage = panel + ' save#1';
	const saved = await save( page );
	const store = await stored( page, panel );
	stage = panel + ' open#2';
	await open( page, panel );
	const reread = await readPanel( page );
	judgeRound( panel, filled, saved, store, reread, 'saves', first );
	await close( page );

	// Round two: the same panel opened again, without a reload.
	stage = panel + ' open#3';
	await open( page, panel );
	const again = await readPanel( page );
	await markers( page, panel, 'reinit' );
	for ( const [ key, f ] of Object.entries( again ) ) {
		if ( reread[ key ] && ! same( reread[ key ].sig, f.sig ) ) {
			mark( cell( panel, key, f.type ), 'reinit', false, 'opened twice: ' + JSON.stringify( reread[ key ].sig ) + ' -> ' + JSON.stringify( f.sig ) );
		}
	}
	const filled2 = await fillPanel( page, panel, 1 );
	stage = panel + ' save#2';
	const saved2 = await save( page );
	const store2 = await stored( page, panel );
	stage = panel + ' open#4';
	await open( page, panel );
	const reread2 = await readPanel( page );
	judgeRound( panel, filled2, saved2, store2, reread2, 'reinit', null );
	await page.screenshot( { path: path.join( shots, panel + '-1440-reopened.png' ) } );
	await close( page );
	return reread2;
}

async function rowOfPanels( page, firsts ) {
	await load( page );
	const order = PANELS.concat( PANELS[ 0 ] );
	for ( const panel of order ) {
		stage = 'in a row ' + panel;
		await open( page, panel );
		await markers( page, panel, 'reinit' );
		const now = await readPanel( page );
		for ( const [ key, f ] of Object.entries( now ) ) {
			if ( firsts[ panel ] && firsts[ panel ][ key ] && ! same( firsts[ panel ][ key ].sig, f.sig ) ) {
				mark( cell( panel, key, f.type ), 'reinit', false, 'after another panel: ' + JSON.stringify( firsts[ panel ][ key ].sig ) + ' -> ' + JSON.stringify( f.sig ) );
			}
		}
		await close( page );
	}
}

async function phone( page, firsts ) {
	await page.setViewport( { width: 390, height: 844, isMobile: true, hasTouch: false } );
	for ( const panel of PANELS ) {
		await load( page );
		stage = panel + ' 390';
		await open( page, panel );
		await page.screenshot( { path: path.join( shots, panel + '-390-open.png' ) } );
		await markers( page, panel, 'mobile' );
		const now = await readPanel( page );
		const list = await fields( page );
		for ( const { key, type } of list ) {
			const c = cell( panel, key, type );
			const el = await handle( page, key );
			if ( firsts[ panel ] && firsts[ panel ][ key ] && ! same( firsts[ panel ][ key ].sig, now[ key ].sig ) ) {
				mark( c, 'mobile', false, 'parts differ at 390: ' + JSON.stringify( firsts[ panel ][ key ].sig ) + ' -> ' + JSON.stringify( now[ key ].sig ) );
			}
			if ( await el.evaluate( ( e ) => !! e.offsetParent || !! e.closest( '[hidden], [role="tabpanel"]' ) ) ) {
				await reveal( el );
			}
			const fit = await el.evaluate( ( e ) => {
				if ( ! e.offsetParent ) return { shown: false };
				const body = e.closest( '.wp-flyout-body' ) || document.body;
				const b = body.getBoundingClientRect();
				const r = e.getBoundingClientRect();
				const wide = [ ...e.querySelectorAll( '*' ) ].filter( ( x ) => x.offsetParent && ! x.closest( '.media-modal, .mce-container, .CodeMirror-measure, .CodeMirror-sizer, .CodeMirror-gutters, .CodeMirror-scroll, .CodeMirror-hscrollbar, .screen-reader-text, [role="listbox"]' ) ).map( ( x ) => [ x, x.getBoundingClientRect() ] ).filter( ( [ , xr ] ) => xr.width > 0 && xr.right > b.right + 1 );
				return { shown: true, overflow: r.right > b.right + 1 || wide.length > 0, what: wide.slice( 0, 2 ).map( ( [ x ] ) => x.tagName.toLowerCase() + '.' + ( typeof x.className === 'string' ? x.className.split( ' ' )[ 0 ] : '' ) ).join( ',' ), bodyScroll: body.scrollWidth > body.clientWidth + 1 };
			} );
			if ( fit.shown && fit.overflow ) {
				mark( c, 'mobile', false, 'wider than the panel at 390: ' + fit.what );
			}
		}
		// Fill at phone width too: a list or a dialog that opens off screen
		// is a field that cannot be used there.
		const filled = await fillPanel( page, panel, 0 );
		for ( const [ key, f ] of Object.entries( filled ) ) {
			const c = cell( panel, key, f.type );
			if ( f.error ) mark( c, 'mobile', false, 'fill at 390: ' + f.error );
			mark( c, 'mobile', true );
		}
		await page.screenshot( { path: path.join( shots, panel + '-390-filled.png' ) } );
		const bodyScroll = await page.evaluate( ( fly ) => {
			const body = document.querySelector( fly + ' .wp-flyout-body' );
			return body ? body.scrollWidth - body.clientWidth : 0;
		}, FLY );
		if ( bodyScroll > 1 ) errors.push( panel + ' 390 | panel body scrolls sideways by ' + bodyScroll + 'px' );
		await close( page );
	}
	await page.setViewport( { width: 1440, height: 900 } );
}

( async () => {
	const { browser, page } = await launch();
	await page.setViewport( { width: 1440, height: 900 } );
	const firsts = {};
	try {
		stage = 'reference';
		reference = await referenceSignatures( page );

		// Every panel starts on its defaults, so whatever a run fills in is
		// a change.
		stage = 'clear';
		await load( page );
		await Promise.all( [ page.waitForNavigation( { waitUntil: 'networkidle2' } ), page.click( '#sc-playground-field-panels-clear' ) ] );
		for ( const panel of PANELS ) {
			firsts[ panel ] = await runPanel( page, panel );
		}
		await rowOfPanels( page, firsts );
		await phone( page, firsts );
	} catch ( e ) {
		errors.push( stage + ' | HARNESS: ' + e.message.split( '\n' )[ 0 ] );
		await page.screenshot( { path: path.join( shots, 'harness-failure.png' ) } ).catch( () => {} );
	}
	await browser.close();

	const rows = Object.values( results );
	for ( const r of rows ) for ( const col of [ 'renders', 'saves', 'reloads', 'reinit', 'mobile' ] ) r[ col ] = r[ col ] || 'untested';
	fs.writeFileSync( path.join( out, 'results.json' ), JSON.stringify( { rows, errors }, null, 2 ) );
	const failing = rows.filter( ( r ) => [ 'renders', 'saves', 'reloads', 'reinit', 'mobile' ].some( ( c ) => 'fail' === r[ c ] ) );
	for ( const r of rows ) {
		console.log( [ r.panel, r.key, r.type, r.renders, r.saves, r.reloads, r.reinit, r.mobile ].join( '\t' ) + ( r.notes.length ? '\t' + r.notes.join( ' ; ' ) : '' ) );
	}
	console.log( '\nJS errors: ' + ( errors.length ? '\n' + [ ...new Set( errors ) ].join( '\n' ) : 'none' ) );
	console.log( '\n' + failing.length + ' of ' + rows.length + ' fields failing' );
	process.exit( failing.length || errors.length ? 1 : 0 );
} )();
