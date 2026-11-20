/**
 * The scripts' unit tests: Vitest, which `wp-scripts test-unit-js` runs,
 * in a jsdom page.
 */
import { defineConfig } from 'vitest/config';

export default defineConfig( {
	test: {
		environment: 'jsdom',
		include: [ 'tests/js/**/*.test.js' ],
		setupFiles: [ 'tests/js/support/setup.js' ],
		globals: false,
	},
} );
