/**
 * The panels: opening and closing them, loading and saving a record,
 * the header's menu and arrows, the questions a panel asks, the form an
 * action asks for, and alerts.
 *
 * Registered as `{prefix}-wp-flyout`, with `-manager`, `-alert` and
 * `-prompt` kept as handles that bring it (Assets::register_assets()).
 * Publishes the panel API (panel/panel.js) in its build's own entry,
 * `window.SugarCommerceFlyouts[ prefix ].panel` -- never as a page global
 * another plugin's copy would overwrite.
 */

import domReady from '@wordpress/dom-ready';

import { bindAlerts } from './lib/alert';
import { own } from './lib/config';
import { init as initManager } from './manager/init';
import { watchEscape } from './panel/escape';
import panel from './panel/panel';

// Asked on the way down, before any widget in a panel has handled it.
watchEscape();

own().panel = panel;

domReady( () => {
	panel.init();
	initManager();
	bindAlerts();
} );
