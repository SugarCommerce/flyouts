/**
 * Markup for the panel tests: a trigger the way Manager::get_button()
 * prints one, and a panel the way the load route draws it.
 */

/**
 * A trigger for a record.
 *
 * @param {string|number} id      The record's id; '' for a new one.
 * @param {Object}        [extra] More attributes.
 * @return {string} Markup.
 */
export const trigger = ( id, extra = '' ) =>
	`<button type="button" class="wp-flyout-trigger button" data-flyout-manager="shop" data-flyout="order" data-flyout-size="large" data-flyout-steps="both"${
		'' === id ? '' : ` data-id="${ id }"`
	} ${ extra }>Open ${ id }</button>`;

/**
 * A panel as the load route draws it.
 *
 * @param {Object} [options]
 * @param {string} [options.id]     The record's id.
 * @param {string} [options.fields] The form's fields.
 * @param {string} [options.extra]  More of the panel's body.
 * @param {string} [options.menu]   The header menu's items.
 * @return {string} Markup.
 */
export function panel( options = {} ) {
	const id = options.id ?? '7';

	return `<div id="wp-flyout-order-${ id }" class="wp-flyout wp-flyout-large wp-flyout-right">
	<div class="wp-flyout-header">
		<div class="wp-flyout-header-content"><h2 class="wp-flyout-title">Order ${ id }</h2></div>
		<div class="wp-flyout-header-actions">
			${
				options.menu
					? `<div class="wp-flyout-menu"><button type="button" class="wp-flyout-menu-toggle" aria-expanded="false">More</button><div class="wp-flyout-menu-list" role="menu" hidden>${ options.menu }</div></div>`
					: ''
			}
			<button type="button" class="wp-flyout-close"></button>
		</div>
	</div>
	<div class="wp-flyout-body">
		<form class="wp-flyout-form" novalidate>
			<input type="hidden" name="id" value="${ id }">
			${
				options.fields ??
				`<div class="wp-flyout-field"><div class="field-kit__field"><input type="text" name="name" value="Ada" required></div></div>
			<div class="wp-flyout-field"><input type="text" name="total" value="10"></div>`
			}
			${ options.extra ?? '' }
		</form>
	</div>
	<div class="wp-flyout-footer">
		<button type="button" class="button wp-flyout-delete">Delete</button>
		<button type="button" class="button wp-flyout-cancel">Cancel</button>
		<button type="button" class="button button-primary wp-flyout-save">Save</button>
	</div>
</div>`;
}
