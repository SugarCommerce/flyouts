/**
 * The image picker: a header's editable picture, chosen from the media
 * library.
 *
 * The media frame is core's `wp.media`, reached through its own API, and
 * made once per picker. Fires `image-picker:changed` on the picker,
 * `{ attachmentId, url }`, and `image-picker:removed`.
 */

import { __ } from '@wordpress/i18n';

import { icon } from '../lib/config';
import { make, on } from '../lib/dom';
import { emit } from '../lib/events';
import { stash } from '../lib/store';

/**
 * The URL of an attachment at the picker's size, else its thumbnail,
 * else the full image.
 *
 * @param {Object} attachment The media library's attachment.
 * @param {string} size       The size the picker asks for.
 * @return {string} The URL.
 */
export function urlFor( attachment, size ) {
	const sizes = attachment.sizes || {};

	if ( sizes[ size ] ) {
		return sizes[ size ].url;
	}

	return sizes.thumbnail ? sizes.thumbnail.url : attachment.url;
}

/**
 * Show an image in the picker and hold its id.
 *
 * @param {Element} picker     The picker.
 * @param {Object}  attachment The attachment chosen.
 */
export function setImage( picker, attachment ) {
	const url = urlFor( attachment, picker.dataset.size || 'thumbnail' );
	const value = picker.querySelector( '.image-picker-value' );
	const preview = picker.querySelector( '.image-picker-preview' );
	const overlay = picker.querySelector( '.image-picker-overlay' );

	if ( value ) {
		value.value = attachment.id;
	}

	if ( preview ) {
		preview.replaceChildren(
			make( 'img', { src: url, alt: '', class: 'image-picker-img' } )
		);
	}

	picker.classList.add( 'has-image' );

	if ( overlay ) {
		if ( ! overlay.querySelector( '[data-action="remove-image"]' ) ) {
			const remove = make( 'button', {
				type: 'button',
				class: 'image-picker-btn image-picker-remove',
				'data-action': 'remove-image',
				title: __( 'Remove image', 'sugarcommerce' ),
			} );

			remove.innerHTML = icon( 'trash', 'trash' );
			overlay.append( remove );
		}

		const select = overlay.querySelector( '[data-action="select-image"]' );

		if ( select ) {
			select.innerHTML = icon( 'change', 'update' );
		}
	}

	emit( picker, 'image-picker:changed', {
		detail: { attachmentId: attachment.id, url },
	} );
}

/**
 * Take the image away: back to the fallback image, or the placeholder.
 *
 * @param {Element} picker The picker.
 */
export function clearImage( picker ) {
	const fallback = picker.dataset.fallbackImage || '';
	const value = picker.querySelector( '.image-picker-value' );
	const preview = picker.querySelector( '.image-picker-preview' );

	// Nothing chosen, which is 0 and not the fallback's id.
	if ( value ) {
		value.value = '0';
	}

	if ( fallback ) {
		if ( preview ) {
			preview.replaceChildren(
				make( 'img', {
					src: fallback,
					alt: '',
					class: 'image-picker-img image-picker-fallback',
				} )
			);
		}

		// Still a picture, but marked as the fallback.
		picker.classList.add( 'has-image', 'is-fallback' );
	} else {
		if ( preview ) {
			const placeholder = make( 'div', {
				class: 'image-picker-placeholder',
			} );

			// The icon the server drew for this picker, carried on it, or
			// the library's own.
			placeholder.innerHTML =
				picker.getAttribute( 'data-placeholder' ) ||
				icon( 'image', 'format-image' );
			preview.replaceChildren( placeholder );
		}

		picker.classList.remove( 'has-image', 'is-fallback' );
	}

	picker
		.querySelectorAll( '[data-action="remove-image"]' )
		.forEach( ( button ) => button.remove() );
	picker
		.querySelectorAll( '[data-action="select-image"]' )
		.forEach( ( button ) => {
			button.innerHTML = icon( 'add', 'plus-alt2' );
		} );

	emit( picker, 'image-picker:removed' );
}

/**
 * Open the media library for a picker: its own frame, made the first time.
 *
 * @param {Element} picker The picker.
 * @param {Object}  media  `wp.media`.
 */
export function openMediaLibrary( picker, media ) {
	const kept = stash( picker );

	if ( ! kept.frame ) {
		const frame = media( {
			title: __( 'Select Image', 'sugarcommerce' ),
			button: { text: __( 'Use This Image', 'sugarcommerce' ) },
			library: { type: 'image' },
			multiple: false,
		} );

		frame.on( 'select', () => {
			setImage(
				picker,
				frame.state().get( 'selection' ).first().toJSON()
			);
			frame.close();
		} );

		kept.frame = frame;
	}

	kept.frame.open();
}

/**
 * Make every picker work, the ones in panels yet to load included.
 *
 * @param {Function} [media] Gives `wp.media`; asked when a picker is pressed,
 *                           because core's media scripts may load after this.
 */
export function bindImagePickers( media = () => window.wp && window.wp.media ) {
	on(
		document,
		'click',
		'.image-picker [data-action="select-image"]',
		( event, button ) => {
			event.preventDefault();
			event.stopPropagation();

			const frame = media();

			if ( frame ) {
				openMediaLibrary( button.closest( '.image-picker' ), frame );
			}
		}
	);

	on(
		document,
		'click',
		'.image-picker [data-action="remove-image"]',
		( event, button ) => {
			event.preventDefault();
			event.stopPropagation();
			clearImage( button.closest( '.image-picker' ) );
		}
	);
}
