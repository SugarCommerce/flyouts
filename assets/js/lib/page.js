/**
 * Leaving the page: loading it again after a save, or following a link.
 *
 * In one place so a test can stand in for the browser, which cannot be
 * made to navigate.
 */

/**
 * Load the page again, so what is behind the panel shows the change.
 */
export function reload() {
	window.location.reload();
}

/**
 * Go to an address.
 *
 * @param {string} href Where.
 */
export function go( href ) {
	window.location.href = href;
}
