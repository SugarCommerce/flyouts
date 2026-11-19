/**
 * The line items component's script (Assets, `{prefix}-line-items`).
 */

import domReady from '@wordpress/dom-ready';

import { bindLineItems } from './components/line-items';

domReady( bindLineItems );
