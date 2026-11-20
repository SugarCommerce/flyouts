/**
 * The activity feed's note box (Assets, `{prefix}-activity`).
 */

import domReady from '@wordpress/dom-ready';

import { bindActivity } from './components/activity';

domReady( bindActivity );
