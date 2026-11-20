/**
 * A panel on screen: opening and closing it, the overlay under it, its
 * tabs, and keeping focus inside it.
 *
 * Published as its build's `window.SugarCommerceFlyouts[ prefix ].panel`,
 * the documented way to open and close a panel by its id from other code. Its events:
 *
 * - `wpflyout:opened` on the document, `{ id, element }`, once the panel
 *   has slid in and taken focus; `flyout:ready` on the panel at the same
 *   moment.
 * - `wpflyout:closing` on the document, `{ id, element, force }`,
 *   cancelable: a listener that calls preventDefault() keeps it open,
 *   unless the close was forced.
 * - `wpflyout:closed` on the document, `{ id }`, once it has slid out.
 * - `wpflyout:tab-changed` on the document, `{ flyoutId, tabId }`.
 */

import { build } from '../lib/config';
import { all, isShown, isUsable, on, one } from '../lib/dom';
import { emit } from '../lib/events';
import { stash } from '../lib/store';
import { escapeWasTaken } from './escape';

/**
 * An element from an element or a jQuery object, which the old panel
 * script took.
 *
 * @param {Element|Object|null} element The panel, or a jQuery object of it.
 * @return {Element|null} The element.
 */
const node = ( element ) =>
	element && element.jquery ? element[ 0 ] || null : element || null;

const panel = {
	config: {
		animationDuration: 300,
		animationDelay: 10,
		focusDelay: 350,
	},

	// The ids of the panels that are open, oldest first.
	active: [],

	// What had focus when each panel opened, by its id.
	openers: {},

	// What can take focus inside a panel.
	focusable:
		'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), ' +
		'select:not([disabled]), textarea:not([disabled]), summary, [tabindex]:not([tabindex="-1"])',

	/**
	 * Open a panel that is already in the page.
	 *
	 * @param {string}  id                The panel's id.
	 * @param {Object}  [options]
	 * @param {boolean} [options.instant] Appear in place, without sliding in.
	 * @param {Element} [options.opener]  What to hand focus back to on close.
	 * @param {string}  [options.focus]   A selector to focus in place of the first
	 *                                    field, or ':panel' for the panel itself.
	 * @return {Element|false} The panel, or false when there is none.
	 */
	open( id, options = {} ) {
		const flyout = document.getElementById( id );

		if ( ! flyout ) {
			// eslint-disable-next-line no-console
			console.warn( 'WP Flyout: Element not found with ID:', id );
			return false;
		}

		// Where focus goes back to. Remembered before anything in the panel
		// takes it, and never the page's <body>, which is what activeElement
		// is when nothing has focus.
		const opener = options.opener || flyout.ownerDocument.activeElement;

		if (
			opener &&
			opener !== document.body &&
			! flyout.contains( opener )
		) {
			this.openers[ id ] = opener;
		}

		this.describe( flyout );
		this.showOverlay();
		document.body.classList.add( 'wp-flyout-open' );

		if ( options.instant ) {
			// Taking over from a panel that is already on screen: sliding in
			// again would read as a second panel arriving.
			flyout.classList.add( 'wp-flyout-instant', 'active' );
			setTimeout(
				() => flyout.classList.remove( 'wp-flyout-instant' ),
				50
			);
		} else {
			setTimeout(
				() => flyout.classList.add( 'active' ),
				this.config.animationDelay
			);
		}

		if ( ! this.active.includes( id ) ) {
			this.active.push( id );
		}

		this.initTabs( flyout );

		// Once it has arrived.
		setTimeout(
			() => {
				// Unless something in the panel has taken focus already, and
				// would lose it to this.
				const holder = flyout.ownerDocument.activeElement;

				if (
					! holder ||
					holder === flyout ||
					! flyout.contains( holder )
				) {
					this.focusInside( flyout, options.focus );
				}

				emit( document, 'wpflyout:opened', {
					detail: { id, element: flyout },
				} );
				emit( flyout, 'flyout:ready' );
			},
			options.instant
				? this.config.animationDelay
				: this.config.focusDelay
		);

		return flyout;
	},

	/**
	 * Say what the panel is to anything that reads the page aloud: a
	 * dialog, named by its own title. Set here as well as in the markup so
	 * a panel built by hand is one too.
	 *
	 * @param {Element} flyout The panel.
	 */
	describe( flyout ) {
		flyout = node( flyout );

		const title = one( '.wp-flyout-title', flyout );

		if ( ! flyout.getAttribute( 'role' ) ) {
			flyout.setAttribute( 'role', 'dialog' );
			flyout.setAttribute( 'aria-modal', 'true' );
		}

		if ( ! flyout.getAttribute( 'tabindex' ) ) {
			flyout.setAttribute( 'tabindex', '-1' );
		}

		if ( title && ! flyout.getAttribute( 'aria-labelledby' ) ) {
			if ( ! title.id ) {
				title.id = flyout.id + '-title';
			}

			flyout.setAttribute( 'aria-labelledby', title.id );
		}
	},

	/**
	 * Put focus in the panel.
	 *
	 * The first field, as it always was. A panel with no fields still
	 * takes focus itself: left on the page behind, Tab would walk the list
	 * table under the overlay.
	 *
	 * @param {Element} flyout     The panel.
	 * @param {string}  [selector] What to focus, or ':panel'.
	 */
	focusInside( flyout, selector ) {
		flyout = node( flyout );

		let target =
			selector && ':panel' !== selector
				? all( selector, flyout ).find( isUsable ) || null
				: null;

		// A panel that is read far more often than it is changed does not
		// open with the caret in a box: on a phone that is the keyboard over
		// the record somebody came to look at. Nor does one whose list is
		// stepped through with J and K, where a caret in a box would make
		// the next J a letter. And one with nothing to save is only read: a
		// receipt opened with the caret in its quantity box, spinner and all.
		if (
			! target &&
			( ':panel' === selector ||
				flyout.classList.contains( 'wp-flyout-footer-when-editing' ) ||
				flyout.classList.contains( 'wp-flyout-step-keys' ) ||
				! one( '.wp-flyout-save', flyout ) )
		) {
			target = flyout;
		}

		if ( ! target ) {
			const body = one( '.wp-flyout-body', flyout );

			target = body
				? all( 'input, select, textarea', body ).find( isUsable ) ||
					null
				: null;
		}

		( target || flyout ).focus();
	},

	/**
	 * Close a panel, unless a listener to `wpflyout:closing` says not to.
	 *
	 * @param {string}  id              The panel's id.
	 * @param {Object}  [options]
	 * @param {boolean} [options.force] Close whatever a listener thinks of it.
	 * @return {boolean} Whether it is closing.
	 */
	close( id, options = {} ) {
		const flyout = id ? document.getElementById( id ) : null;

		if ( ! flyout ) {
			return false;
		}

		// Already on its way out. A second request for the same thing -- a
		// double click, a second listener on the same button -- must not
		// ask every listener the same question again.
		if ( ! this.active.includes( id ) ) {
			return false;
		}

		const allowed = emit( document, 'wpflyout:closing', {
			detail: { id, element: flyout, force: !! options.force },
			cancelable: true,
		} );

		if ( ! allowed && ! options.force ) {
			return false;
		}

		flyout.classList.remove( 'active' );
		this.active = this.active.filter( ( open ) => open !== id );

		// Focus goes back where it came from, or it is left on a panel that
		// is no longer there and the next Tab starts the page again.
		const opener = this.openers[ id ];

		delete this.openers[ id ];

		if (
			opener &&
			document.contains( opener ) &&
			'function' === typeof opener.focus
		) {
			opener.focus();
		}

		setTimeout( () => {
			if ( flyout.classList.contains( 'wp-flyout-dynamic' ) ) {
				flyout.remove();
			}

			if ( ! this.active.length ) {
				this.hideOverlay();
			}

			emit( document, 'wpflyout:closed', { detail: { id } } );
		}, this.config.animationDuration );

		return true;
	},

	/**
	 * Take a panel away without closing it: for one being replaced by
	 * another in the same place. No animation, no events, and the overlay
	 * stays.
	 *
	 * @param {string} id The panel's id.
	 */
	discard( id ) {
		delete this.openers[ id ];
		this.active = this.active.filter( ( open ) => open !== id );

		const flyout = id ? document.getElementById( id ) : null;

		if ( flyout ) {
			flyout.remove();
		}
	},

	/**
	 * Close every open panel.
	 */
	closeAll() {
		[ ...this.active ].forEach( ( id ) => this.close( id ) );
	},

	/**
	 * The id of the panel opened last.
	 *
	 * @return {string|null} The id.
	 */
	getLastId() {
		return this.active[ this.active.length - 1 ] || null;
	},

	/**
	 * Put the overlay down, or keep the one that is there.
	 */
	showOverlay() {
		let overlay = one( '.wp-flyout-overlay' );

		if ( ! overlay ) {
			overlay = document.createElement( 'div' );
			overlay.className = 'wp-flyout-overlay';

			// This build's: another copy's scripts neither reuse it nor
			// take it away.
			if ( build() ) {
				overlay.setAttribute( 'data-flyout-build', build() );
			}
			document.body.append( overlay );
		}

		setTimeout( () => overlay.classList.add( 'active' ), 10 );
	},

	/**
	 * Fade the overlay and take it away.
	 */
	hideOverlay() {
		const overlay = one( '.wp-flyout-overlay' );

		if ( overlay ) {
			overlay.classList.remove( 'active' );
		}

		document.body.classList.remove( 'wp-flyout-open' );

		setTimeout( () => {
			// Unless a panel opened again in the meantime, in which case the
			// overlay it is standing on is this one.
			if ( ! this.active.length && overlay ) {
				overlay.remove();
			}
		}, this.config.animationDuration );
	},

	/**
	 * Switch tabs when one is pressed.
	 *
	 * @param {Element} flyout The panel.
	 */
	initTabs( flyout ) {
		flyout = node( flyout );

		const kept = stash( flyout );

		if ( kept.tabs ) {
			kept.tabs();
		}

		kept.tabs = on( flyout, 'click', '.wp-flyout-tab', ( event, tab ) => {
			event.preventDefault();

			if ( tab.classList.contains( 'disabled' ) ) {
				return;
			}

			const tabId = tab.dataset.tab;

			all( '.wp-flyout-tab', flyout ).forEach( ( other ) => {
				other.classList.remove( 'active' );
				other.setAttribute( 'aria-selected', 'false' );
			} );

			tab.classList.add( 'active' );
			tab.setAttribute( 'aria-selected', 'true' );

			all( '.wp-flyout-tab-content', flyout ).forEach( ( content ) =>
				content.classList.remove( 'active' )
			);

			const content = all( '[id]', flyout ).find(
				( element ) => element.id === 'tab-' + tabId
			);

			if ( content ) {
				content.classList.add( 'active' );
			}

			emit( document, 'wpflyout:tab-changed', {
				detail: { flyoutId: flyout.id, tabId },
			} );
		} );
	},

	/**
	 * Keep Tab inside the open panel.
	 *
	 * The page behind is under an overlay and cannot be clicked, but it
	 * could still be tabbed into -- focus left the panel after its last
	 * button and went on down a list table nobody could see.
	 *
	 * Only when focus is in the panel to begin with. The media library and
	 * the colour picker open over it and have their own idea of where Tab
	 * goes.
	 *
	 * @param {KeyboardEvent} event The Tab.
	 */
	trapFocus( event ) {
		const id = this.getLastId();
		const flyout = id ? document.getElementById( id ) : null;

		if ( ! flyout ) {
			return;
		}

		const current = flyout.ownerDocument.activeElement;

		if (
			current &&
			current !== document.body &&
			! flyout.contains( current )
		) {
			return;
		}

		const stops = all( this.focusable, flyout ).filter( isShown );

		if ( ! stops.length ) {
			event.preventDefault();
			flyout.focus();
			return;
		}

		const first = stops[ 0 ];
		const last = stops[ stops.length - 1 ];

		if (
			event.shiftKey &&
			( current === first ||
				current === flyout ||
				current === document.body )
		) {
			event.preventDefault();
			last.focus();
		} else if (
			! event.shiftKey &&
			( current === last || current === document.body )
		) {
			event.preventDefault();
			first.focus();
		}
	},

	/**
	 * The close button, the overlay, Escape and Tab, for every panel.
	 */
	init() {
		on( document, 'click', '.wp-flyout-close', ( event, button ) => {
			const flyout = button.closest( '.wp-flyout' );

			this.close( flyout ? flyout.id : '' );
		} );

		on( document, 'click', '.wp-flyout-overlay', () => {
			const id = this.getLastId();

			if ( id ) {
				this.close( id );
			}
		} );

		document.addEventListener( 'keydown', ( event ) => {
			if ( ! this.active.length ) {
				return;
			}

			// Escape closes, unless something in the panel has used it.
			if (
				'Escape' === event.key &&
				! escapeWasTaken() &&
				! event.defaultPrevented
			) {
				this.close( this.getLastId() );
			}

			if ( 'Tab' === event.key ) {
				this.trapFocus( event );
			}
		} );
	},
};

export default panel;
