/**
 * The refund form's script (Assets, `{prefix}-refund-form`).
 */

import domReady from '@wordpress/dom-ready';

import { bindRefundForms } from './components/refund-form';

domReady( bindRefundForms );
