/**
 * The image picker's script (Assets, `{prefix}-image-picker`), beside
 * core's media frame.
 */

import domReady from '@wordpress/dom-ready';

import { bindImagePickers } from './components/image-picker';

domReady( () => bindImagePickers() );
