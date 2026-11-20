/**
 * Drawing a panel again after a component's action asked for it.
 */

import { all } from '../lib/dom';
import { settings } from '../lib/config';
import { reload } from '../lib/page';

/**
 * Open the panel again from the trigger that opened it, or load the page
 * again when there is no such trigger.
 *
 * @param {Element} flyout The panel.
 */
export function reloadFlyout( flyout ) {
	const trigger = all( '[data-flyout-instance]' ).find(
		( element ) => element.dataset.flyoutInstance === flyout.id
	);
	const panel = settings().panel;

	if ( ! trigger || ! panel ) {
		reload();
		return;
	}

	panel.close( flyout.id );
	setTimeout( () => trigger.click(), 300 );
}
