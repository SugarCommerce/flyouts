/**
 * Drawing a panel again after a component's action asked for it.
 */

import { reload } from '../lib/page';

/**
 * Open the panel again from the trigger that opened it, or load the page
 * again when there is no such trigger.
 *
 * @param {Element} flyout The panel.
 */
export function reloadFlyout( flyout ) {
	const trigger = Array.from(
		document.querySelectorAll( '[data-flyout-instance]' )
	).find( ( element ) => element.dataset.flyoutInstance === flyout.id );

	if ( ! trigger || ! window.WPFlyout ) {
		reload();
		return;
	}

	window.WPFlyout.close( flyout.id );
	setTimeout( () => trigger.click(), 300 );
}
