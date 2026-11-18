# Flyouts

Edit a record in a panel that slides in, instead of navigating away to a form
and back.

## What it does

Editing one field on one row should not cost a page load, a form, and a
redirect that loses your place in the table. A flyout keeps the list on
screen and puts the record beside it.

Building one means a panel, a REST endpoint, loading, saving and validation.
This is that, from a description of the fields — the same field types the
rest of the admin uses.

## Features

* Edit a record in a panel, without leaving the list it came from
* Load and save through two callbacks, and nothing else
* Use any field type the fields package renders, including repeaters and media
* Add components a record screen needs — an activity feed with notes, line
  items, a refund form
* Show amounts in major units (148.00) or the minor units a store keeps (14800)
* Let an add-on put a field on any panel, give it its value and save it
* Split a long panel into tabs, or into titled cards
* Open one from a button, a row action or a link
* Reload the page after a save, so the table underneath stays true
* Name the record in the header — its kind, its own title, its state
* Put the things that are not Save in a menu beside the close button
* Step to the previous or next row without closing the panel
* Ask before throwing away a change, and say which field a save rejected

## Installation

```bash
composer require sugarcommerce/flyouts
```

## Quick start

```php
use SugarCommerce\Flyouts\Flyouts;

Flyouts::register( 'shop_edit_product', [
	'title'  => __( 'Edit product', 'my-plugin' ),
	'fields' => [
		'name'  => [ 'type' => 'text', 'label' => __( 'Name', 'my-plugin' ) ],
		'price' => [ 'type' => 'number', 'label' => __( 'Price', 'my-plugin' ), 'min' => 0 ],
	],
	'load'   => fn( $id ) => get_post( $id ),
	'save'   => fn( $id, $data ) => wp_update_post( [
		'ID'         => $id,
		'post_title' => $data['name'],
	] ),
] );
```

Then wherever the record appears:

```php
Flyouts::render_button( 'shop_edit_product', [
	'id'   => $product_id,
	'text' => __( 'Edit', 'my-plugin' ),
	'icon' => 'heroicons-outline:pencil-square', // optional; or a Dashicon: 'edit'
] );
```

`text`, `class` and `icon` style the button; every other key is handed to
the panel as data (here, the record's `id`). `class` defaults to `button`.
An `icon` is drawn before the text, as an icon from the fields package's sets
(`set:name`, inline SVG) or a Dashicons name; without one the button is just
its text, as core's are.

## The header

A panel titled "Edit product" is the same panel for every product. `title`,
`subtitle`, `eyebrow` and `badge` may each be the value, or a function of the
record — it is handed whatever `load` returned, and the id, which is empty
for a record that does not exist yet.

```php
Flyouts::register( 'shop_edit_product', [
	'eyebrow'  => __( 'Product', 'my-plugin' ),
	'title'    => fn( $product, $id ) => $id ? $product->post_title : __( 'New product', 'my-plugin' ),
	'subtitle' => fn( $product, $id ) => $id ? $product->post_name : '',
	'badge'    => fn( $product, $id ) => $id && 'draft' === $product->post_status
		? [ 'label' => __( 'Draft', 'my-plugin' ), 'tone' => 'warning' ]
		: '',
	'fields'   => [
		'name' => [ 'type' => 'text', 'label' => __( 'Name', 'my-plugin' ) ],
	],
	'load'     => fn( $id ) => get_post( $id ),
] );
```

`eyebrow` is the small line over the title: what kind of thing this is, once
the title has become its name. `badge` is a label and a `tone` — `success`,
`warning`, `danger`, `info` or `neutral` — or just the label, which is
neutral.

A function is a closure or an `[ $object, 'method' ]` pair. A string is
always the text it is, so a panel can be titled "Date" or "Link" without
PHP being asked to run either.

## A header that says who it is about

A panel about a person, or anything with a face, can say so in the header
itself rather than in a card under it. `header` is the opt-in, and a panel
without one is drawn exactly as it always was:

```php
Flyouts::register( 'shop_customer', [
	'title'       => fn( $customer ) => $customer->name,
	'subtitle'    => fn( $customer ) => $customer->email,
	'badge'       => fn( $customer ) => [ 'label' => __( 'Active', 'my-plugin' ), 'tone' => 'success' ],
	'header'      => [
		'avatar' => fn( $customer ) => [
			'image' => get_avatar_url( $customer->email, [ 'size' => 80 ] ),
			'name'  => $customer->name,
		],
		'meta'   => fn( $customer ) => [
			[ 'value' => $customer->country_name, 'image' => $customer->flag_url ],
		],
		'copy'   => 'subtitle',
		'steps'  => true,
	],
	'footer_when' => 'editing',
	'fields'      => [
		'name' => [ 'type' => 'text', 'label' => __( 'Name', 'my-plugin' ) ],
	],
	'load'        => fn( $id ) => my_plugin_get_customer( $id ),
] );
```

`avatar` is a URL, or an `image`, a `name` whose initials stand in when
there is no image, and an `icon` for when there is neither -- an icon from the fields package
(`heroicons-outline:user`) or a dashicon's name (`admin-users`); see
"Icons". It
is drawn at forty pixels with the corner an avatar has in a list table.
`initials` gives the letters outright, for a panel with its own idea of
them, and `background` with `color` -- both, as six-digit hex -- draws them
as a monogram: `[ 'initials' => 'AL', 'background' => '#d7dffb', 'color' =>
'#2f43a3' ]`. Choose a pair that can be read; the library does not check.
The `icon` may also be any icon from the fields package's sets
(`heroicons-outline:shopping-cart`), drawn as its SVG, and takes the same
`background` and `color` -- a collection's icon on the collection's colour.
`meta` is a list of quiet facts after the subtitle, each a `value` with an
optional `image`, `icon` (`heroicons-outline:map-pin`, or `location`) and
`title`. Both may be a function of the record.
`copy => 'subtitle'` puts a copy button beside the subtitle.

In this header the title and badge keep to one line, the menu comes before
previous and next, and those two are drawn quieter than the menu and the
close button; under 600 pixels they are left out, so the name is not.
`steps => false` leaves them out everywhere. `steps => 'keys'` leaves the
arrows out and steps with the keyboard instead, and `steps => 'both'` has the
arrows and the keys; see "Previous and next". `copy` may be a function of the
record too, for a subtitle that is only sometimes worth copying.

`footer_when => 'editing'` is for a panel that is read far more often than
it is changed: Save and Cancel stay out of sight until the form has a change
in it or a folded section has been opened, and the panel does not open with
the caret in its first field.

## Tags beside the badge

`tags` puts a record's own labels in the title row, after its badge — where
a reader looks for what kind of record this is. Like the badge it may be a
function of the record, and it returns a list:

```php
Flyouts::register( 'shop_customer', [
	'title' => fn( $customer ) => $customer->name,
	'badge' => [ 'label' => __( 'Active', 'my-plugin' ), 'tone' => 'success' ],
	'tags'  => fn( $customer ) => [
		__( 'VIP', 'my-plugin' ),
		[ 'label' => __( 'Wholesale', 'my-plugin' ), 'tone' => 'info' ],
		[ 'label' => $customer->custom_tag, 'background' => '#ffeecc', 'color' => '#553300' ],
	],
	'load'  => fn( $id ) => my_plugin_get_customer( $id ),
] );
```

Each tag is a label, or a `label` with a `tone` (as the badge takes) or a
`background` and `color` of its own — both, as six-digit hex — and an
optional `title` for hover. A plugin with tag chips of its own can return
their markup as a string instead; it is printed as it is, so it must come
from the plugin's code, escaped. The tags give way before the title does and
never wrap the row.

## The header menu

`menu` puts a "more" button beside the close button, for the things a record
can have done to it that are not saving it. Each item is a `label` and a
`url`, an `action` with its `callback`, or a `flyout` and `id` — another
panel of the same manager, opened where this one is
(`[ 'label' => 'View Order', 'flyout' => 'order', 'id' => 51 ]`):

```php
Flyouts::register( 'shop_edit_product', [
	'title' => __( 'Product', 'my-plugin' ),
	'menu'  => fn( $product, $id ) => $id ? [
		[
			'label'  => __( 'View on site', 'my-plugin' ),
			'url'    => get_permalink( $id ),
			'target' => '_blank',
		],
		[
			'label'    => __( 'Duplicate', 'my-plugin' ),
			'action'   => 'duplicate',
			'callback' => function ( $request ) {
				my_plugin_duplicate( $request['id'] );

				return [
					'message' => __( 'Duplicated.', 'my-plugin' ),
					'reload'  => true,
				];
			},
		],
		[
			'label'      => __( 'Move to trash', 'my-plugin' ),
			'action'     => 'trash',
			'callback'   => fn( $request ) => wp_trash_post( $request['id'] )
				? [ 'reload' => true ]
				: new WP_Error( 'not_trashed', __( 'That could not be moved to the trash.', 'my-plugin' ) ),
			'danger'     => true,
			'confirm'    => __( 'Move this product to the trash?', 'my-plugin' ),
			'capability' => 'delete_posts',
		],
	] : [],
	'load'  => fn( $id ) => get_post( $id ),
] );
```

An action's callback is handed the request, with the record's `id` in it,
and answers the way a component's action does: a `WP_Error` for failure, or
an array whose `message` is shown and whose `reload` or `refresh_flyout`
says what has to be drawn again. `confirm` is asked in the
panel before the action runs, and `danger` draws the item in red.

`menu` may be a list, or — as here — a function of the record, so a new
record has no "View on site". That is enforced and not only drawn: the
endpoint builds the menu for the record it is asked about, and an action
that menu does not offer is refused. An item's `capability` defaults to the
flyout's; an item the current user may not use is left off their menu.

### An action that needs something typed first

An action answers its first request with a `form` instead of doing anything,
and the panel shows that form in a dialog over itself. What is typed comes
back to the same action as `fields`:

```php
'callback' => function ( $request ) {
	if ( ! isset( $request['fields'] ) ) {
		return [
			'form' => [
				'title'  => __( 'Send an Email', 'my-plugin' ),
				'submit' => __( 'Send', 'my-plugin' ),
				'fields' => [
					[ 'name' => 'to', 'type' => 'static', 'label' => __( 'To', 'my-plugin' ), 'value' => $email ],
					[ 'name' => 'subject', 'type' => 'text', 'label' => __( 'Subject', 'my-plugin' ), 'required' => true, 'maxlength' => 200 ],
					[ 'name' => 'message', 'type' => 'textarea', 'label' => __( 'Message', 'my-plugin' ), 'required' => true ],
				],
			],
		];
	}

	return my_plugin_send( $request['id'], $request['fields']['subject'], $request['fields']['message'] );
},
```

A field is `text`, `email`, `date`, `number` (with optional `min`, `max` and
`step`), `select` (with `options`, value to label), `textarea` or `static`.
A `static` field is
shown and never sent, for what the server already knows — it should not
trust a recipient that came back from the browser. A `WP_Error` whose data
names a `field` is shown under that field. The dialog traps focus, sends on
Command or Ctrl with Enter, and asks before it throws away anything typed.
A row action (`wp-flyout-row-action`) may answer with a form in the same
way, and both may answer with `updates`, a map of selector to markup to
redraw inside the panel.

## Saving

Save is live once something has changed, or from the start on a new record.
Closing a panel that holds a change — with the close button, Escape, Cancel
or a click outside it — asks first, and so does stepping to another row.
Cmd or Ctrl and S saves.

When a save fails, say which field was wrong and the message goes under
that field, with its tab or section opened and the field focused:

```php
Flyouts::register( 'shop_edit_product', [
	'title'  => __( 'Product', 'my-plugin' ),
	'fields' => [
		'price' => [ 'type' => 'number', 'label' => __( 'Price', 'my-plugin' ) ],
		'sku'   => [ 'type' => 'text', 'label' => __( 'SKU', 'my-plugin' ) ],
	],
	'save'   => function ( $id, $data ) {
		if ( $data['price'] < 0 ) {
			return new WP_Error(
				'price_too_low',
				__( 'A price cannot be negative.', 'my-plugin' ),
				[ 'field' => 'price' ]
			);
		}

		return update_post_meta( $id, 'price', $data['price'] );
	},
] );
```

`field` is the field's key and takes the error's message. For more than one,
`fields` is a map of key to message:
`[ 'fields' => [ 'price' => '…', 'sku' => '…' ] ]`. The same goes for an
error returned by `validate`. An error that names no field — or a field the
panel does not have — is one message at the top, as before.

The default footer is Delete, Cancel and Save. A footer of your own
(`actions`) is left exactly as you wrote it; give a button the class
`wp-flyout-cancel` to make it one, and the attribute `data-always-enabled`
to a `wp-flyout-save` button that should be live whether or not the form
has changed.

## Previous and next

Nothing to configure. When a panel is opened from a trigger that carries an
`id`, and the page has triggers for other records of the same flyout — each
row of a list table — the header gains arrows that load the row before and
the row after in place. A link to a record inside a panel is not a row of
the list, and is not counted.

A panel with a `header` can ask for the keyboard in place of the arrows, or
as well as them:

```php
'header' => [ 'steps' => 'keys' ],   // no arrows; J and K step
'header' => [ 'steps' => 'both' ],   // the arrows, and J and K
```

J loads the next row and K the one before, and so do the right and left
arrow keys when focus is on the panel itself or on a plain button or link —
not in a field, a menu, a row of tabs or anything else with a use for them.
Nothing happens while somebody is typing, while the panel is asking a
question, or over a dialog such as the media library. A form with unsaved
changes asks before it is left, as it does for the arrows. The record
arrived at is announced with its place in the list ("Order #51, 3 of 20"),
the end of the list says so, and the foot of the header menu says which
keys there are. A panel that steps by key opens with focus on the panel and
not in its first field, so the first J is not a letter.

## Tabs, figures and tables of records

Tabs are a row of pills, the current one filled, in the same language as
the sidebar of the reports and settings libraries. They never wrap: tabs
that do not fit scroll sideways.

A `stats` component on a card is three figures across and one to a row
under 600 pixels, each with an optional `description` under it.

A `data_table` lists records with a few classes: `class =>
'wp-flyout-records'` on the table keeps every row one line high, and on a
column `wp-flyout-col-text` is words that start at the left and shorten
with an ellipsis, `wp-flyout-col-left` starts at the left,
`wp-flyout-col-quiet` is grey, and `wp-flyout-col-wide-only` is left out
on a phone. A column with none of them is a figure, set right. A paragraph
with the class `wp-flyout-more` under the table is the link to the rest.

## Cards

A form is easier to fill in as a page of titled cards than as tabs: nothing
is out of sight. `sections` names the cards — a label, or a `label`,
`description`, `collapsed` and `aside` — and a field says which card it is on
with `section`:

```php
Flyouts::register( 'shop_edit_product', [
	'title'    => __( 'Product', 'my-plugin' ),
	'sections' => [
		'details'  => __( 'Details', 'my-plugin' ),
		'advanced' => [ 'label' => __( 'Advanced', 'my-plugin' ), 'collapsed' => true ],
	],
	'fields'   => [
		'name' => [ 'type' => 'text', 'label' => __( 'Name', 'my-plugin' ), 'section' => 'details' ],
		'sku'  => [ 'type' => 'text', 'label' => __( 'SKU', 'my-plugin' ), 'section' => 'advanced' ],
	],
] );
```

A card's fields are gathered together before it is drawn, so a field added
later — by an add-on, at the end of `fields` — lands at the end of the card
it names rather than opening a second card of the same name at the foot of
the panel. A field on no card keeps its own place. A collapsed card is a
`<details>`, which opens itself when a field inside it fails validation.

## Two columns

A `large` or `full` panel can keep its settings beside its content, the way
the editor does. `layout => 'split'` turns it on, and a section marked
`aside` stands in the right-hand column:

```php
Flyouts::register( 'shop_edit_product', [
	'title'    => __( 'Product', 'my-plugin' ),
	'size'     => 'large',
	'layout'   => 'split',
	'sections' => [
		'details' => __( 'Details', 'my-plugin' ),
		'status'  => [ 'label' => __( 'Status', 'my-plugin' ), 'aside' => true ],
	],
	'fields'   => [
		'name'   => [ 'type' => 'text', 'label' => __( 'Name', 'my-plugin' ), 'section' => 'details' ],
		'status' => [ 'type' => 'text', 'label' => __( 'Status', 'my-plugin' ), 'section' => 'status' ],
	],
] );
```

On a smaller panel, or a narrow screen, the aside sections go underneath.

## Letting an add-on into a panel

Three hooks, each named for one panel — `{prefix}` is the manager's,
`{id}` the panel's — so an add-on that puts a field on the product panel
is not called for every other panel the manager has:

```php
// Add the field: to `fields`, with the `section` of the card it belongs on.
add_filter( 'wp_flyout_shop_edit_product_config', function ( $config ) {
	$config['fields']['gift_wrap'] = [
		'type'    => 'toggle',
		'label'   => __( 'Gift wrap', 'my-addon' ),
		'section' => 'details',
	];

	return $config;
} );

// Give it its value: filter what the panel loaded.
add_filter( 'wp_flyout_shop_edit_product_data', function ( $product, $id ) {
	$product->gift_wrap = (bool) get_post_meta( $id, 'gift_wrap', true );

	return $product;
}, 10, 2 );

// Write it, once the panel has saved.
add_action( 'wp_flyout_shop_edit_product_saved', function ( $id, $values ) {
	update_post_meta( $id, 'gift_wrap', ! empty( $values['gift_wrap'] ) );
}, 10, 2 );
```

`_data` runs on every load of the panel — opening it, running a menu item,
drawing a feed again — and only for a record that loaded: a `false`, `null`
or `WP_Error` is passed through untouched, and a filter that returns
something of a different type is ignored. When adding, the id is empty and
whatever is set is the field's default. `_saved` fires only after a save that
succeeded, with the new record's id when `save` returned one (an integer) and
the id it was asked to save otherwise; the values have been through each
field's own sanitising, the added field's included. `_config` runs as the
panel is registered, so add the filter before that.

`wp_flyout_register_config_{prefix}` filters every panel of a manager at once.

## An activity feed

What happened to a record, newest first, and the notes written about it, in
one list: a picture for the kind of event, a sentence, how long ago. A note
is drawn apart, on a tint, signed, with a Delete; a box over the list takes a
new one; a button under it loads earlier events. Each of the three answers
with the feed drawn again in place.

```php
Flyouts::register( 'shop_order', [
	'title'  => __( 'Order', 'my-plugin' ),
	'fields' => [
		'history' => [
			'type'        => 'activity',

			// What the feed is read from: [ 'events' => [...], 'more' => bool ].
			'source'      => fn( $id, $args ) => my_plugin_order_events( $id, $args['number'] ),
			'number'      => 30,

			// A note written, and a note removed. Each is handed the request:
			// the record's `id`, and `content` or `note_id`.
			'add_note'    => fn( $request ) => my_plugin_add_note( $request['id'], $request['content'] ),
			'delete_note' => fn( $request ) => my_plugin_delete_note( $request['id'], $request['note_id'] ),

			// How each kind of event is drawn, unless it says for itself.
			'types'       => [
				'purchase' => [ 'icon' => 'heroicons-outline:shopping-cart', 'tone' => 'accent' ],
				'refund'   => [ 'icon' => 'undo', 'tone' => 'danger' ], // a dashicon still works
			],
			'empty_text'  => __( 'Nothing yet.', 'my-plugin' ),
		],
	],
] );
```

An event is an array — or an object that serialises to one
(`JsonSerializable`), so a plugin's own event class can be handed over as it
is:

```php
[
	'id'    => 'refund-51',
	'type'  => 'refund',                 // drawn as is-refund
	'time'  => '2026-11-17 10:04:00',    // UTC as stored, or a timestamp
	'icon'  => 'heroicons-outline:receipt-refund', // or a dashicon: 'undo'
	'tone'  => 'danger',                 // accent, success, warning, danger or info
	'text'  => 'Refunded £5.00',         // the sentence, as plain text
	'parts' => [                         // or the sentence in pieces
		[ 'text' => 'Refunded on ' ],
		[ 'text' => 'order #51', 'flyout' => 'order', 'id' => 51 ],
	],
	'meta'  => [ 'Requested by customer' ], // said quietly after it
]
```

An event with no `icon`, and no `icon` for its kind, is a clock
(`heroicons-outline:clock`); one whose icon no set has is drawn the same.

A part with a `url` is a link; one with a `flyout` and `id` opens that panel
of the same manager in place (`panel` is read as `flyout`), and is plain words
for somebody who may not open it. A note is an event of type `note` with a
`note` of its own — `id`, `content`, `author`, and `can_delete => false` for
one nobody may remove. A sentence that is one link from end to end is drawn
as words, not as a line of blue.

Without a `source` the feed is read from what `load` returned at the field's
key, either shape, and drawn again from `load` after a note — so a panel can
keep its notes on the record and need nothing else. The box is left out for
a record that does not exist yet and for a feed with no `add_note`; the
Delete for one with no `delete_note`. The content of a note is trimmed and
run through `wp_kses_post()` before `add_note` sees it, and an empty one is
refused. Whether a note belongs to the record the panel is showing is
`delete_note`'s check to make — it is handed both ids.

The rest is optional. `more_action`, `add_action` and `delete_action` are the
keys the three requests are sent under (`more_activity`, `add_activity_note`,
`delete_activity_note`), for a plugin whose add-ons already send others.
`time_format` (handed the event's `time`), `link` (handed a part) and
`meta_before` (handed the event and the index of a quiet word) each return
markup of the plugin's own, printed as it is. `hint_types` names the kinds
of event that open a record's history — `[ 'joined' ]` — and a feed holding
only those still says `empty_text` under them. `placeholder`, `add_text`,
`more_text`, `delete_text` and `confirm_text` are its words. The requests
run under the field's `action_capability`, else the panel's capability.

An action of your own that changes the record's history — a menu item that
blocks a customer — can redraw the feed in its answer:

```php
use SugarCommerce\Flyouts\Components\Activity;

return [
	'message' => __( 'Blocked.', 'my-plugin' ),
	'updates' => [ Activity::selector( 'history' ) => Activity::feed( $history_field, $request['id'] ) ],
];
```

An add-on that records events of its own declares how its kinds are drawn
for every feed of a manager:

```php
add_filter( 'wp_flyout_shop_activity_types', function ( $types ) {
	$types['membership_renewed'] = [ 'icon' => 'heroicons-outline:arrow-path', 'tone' => 'success' ];

	return $types;
} );
```

## Markup a plugin drew

`markup` puts markup the plugin built itself on a panel — a chart, a card
that is one piece of HTML — read from what `load` returned at the field's
key (or the field's own `html`):

```php
Flyouts::register( 'shop_product', [
	'title'  => __( 'Product', 'my-plugin' ),
	'fields' => [
		'sales_chart' => [ 'type' => 'markup', 'class' => 'my-sales-chart' ],
	],
	'load'   => fn( $id ) => (object) [ 'sales_chart' => my_plugin_sales_chart_svg( $id ) ],
] );
```

It is printed **as it is**: that is the whole point, and it is a contract.
Build it in your own code and escape as you go; never put anything a request
carried into it. Markup that should be sanitised is the fields package's `html`
type, which runs `wp_kses_post()` and so cannot carry an SVG or a button.
The box carries `class`, which is what an action's `updates` names to draw
the piece again in place.

An `info_grid` item may hold markup the same way, with `html => true` — a
flag beside a country, a copy button — and `InfoGrid::grid()` draws the grid
as markup, for the end of a card a plugin drew:

```php
use SugarCommerce\Flyouts\Components\InfoGrid;

echo InfoGrid::grid( [
	[ 'label' => __( 'Country', 'my-plugin' ), 'value' => $flag_img . esc_html( $country ) ],
	[ 'label' => __( 'Browser', 'my-plugin' ), 'value' => esc_html( $browser ), 'title' => $user_agent ],
], [ 'class' => 'my-facts' ] );
```

Values are markup and the caller escapes them; labels and titles are escaped
here; a row with no label or no value is left out. Every `info_grid` item
takes a `title` too, shown on hover over its value.

## Who may edit what

A flyout's `capability` decides who may open it, save it and press its
buttons; a button may ask for more with `action_capability`. What it does
not decide is *which* record: the `item_id` a request carries reaches your
`load`, `save`, `delete` and action callbacks as sent, and this library
never sees the object it names. Whether the current user may touch that
particular record is your check — in the callbacks, or through the
`wp_flyout_rest_capability_{prefix}` filter, which receives the capability,
the flyout id and the request and can return a different capability for a
different record.

## Money: major or minor units

Any component that shows money — line items, the price summary, the refund
form — takes 148.00 to mean one hundred and forty-eight, and reads the number
of decimal places from the currency. A store keeps 14800 instead, and saying
so once is better than dividing every amount on the way in for the library to
multiply it straight back:

```php
Flyouts::register( 'shop_order', [
	'title'  => __( 'Order', 'my-plugin' ),
	'money'  => 'minor',                      // every money component on the panel
	'fields' => [
		'summary' => [ 'type' => 'price_summary', 'total' => 14800, 'currency' => 'GBP' ],
		'refund'  => [ 'type' => 'refund_form', 'amount_paid' => 14800, 'currency' => 'GBP' ],
	],
] );
```

`money` on the panel reaches every money component on it; `money` on a field
is that field's alone and wins over the panel's. `major` is the default.
Minor units are whole numbers in the currency's smallest unit — 5000 is five
thousand yen, 1500 is one and a half Kuwaiti dinars. Line items post their
prices back in the unit they were given, and a picked product's price from
the `details_key` callback is in the same unit. The refund form always sends
the refund itself in minor units, whichever unit it was given.

## Icons

Everywhere a panel takes an `icon`, it takes either form:

- an icon from the fields package, `set:name` -- `heroicons-outline:shopping-cart`,
  `heroicons-solid:star`, or any set registered with the fields package --
  drawn as inline SVG in the text's colour;
- a Dashicons name, `cart` or the whole class `dashicons-cart`, drawn as
  the font's span exactly as before.

| Where | Key | Example |
| --- | --- | --- |
| `header` (identity) | `avatar.icon`, `meta[].icon` | `heroicons-outline:user` |
| `activity` | an event's `icon`, `types[kind].icon` | `heroicons-outline:receipt-refund` |
| `header` component | `icon`, `meta[].icon` | `heroicons-outline:shopping-cart` |
| `stats` | `items[].icon` | `heroicons-outline:banknotes` |
| `accordion` | `items[].icon` | `heroicons-outline:cog-6-tooth` |
| `empty_state` | `icon` | `heroicons-outline:inbox` |
| `actions` | `icon` | `heroicons-outline:arrow-path` |

Either way the element carries the class `sugarcommerce-icon`, which is what
the stylesheet sizes, so a Heroicon sits where the Dashicon did at the same
size. A value that is neither form, or names an icon no set has, draws
nothing -- or, where there has to be something, the default.

The library's own glyphs are Heroicons (outline): close (`x-mark`), the
menu (`ellipsis-horizontal`), previous and next (`chevron-left`,
`chevron-right`), the accordion's `chevron-down`, copy
(`document-duplicate`, then `check`), each alert's mark (`check-circle`,
`x-circle`, `exclamation-triangle`, `information-circle`), a stat's trend
(`arrow-up`, `arrow-down`), an empty state with no `icon` (`inbox`), an
event with none (`clock`), `card` (`credit-card`) for a payment line, the line
items' `shopping-cart`, `photo` and `trash`, and the image picker's `plus`,
`arrow-path` and `photo`. The script draws the ones it adds -- the arrows,
copy, an alert, the toast's tick -- from markup the server publishes beside
the REST URL, so they are the same SVG. The spinners are still Dashicons.

## Commerce components

`line_items`, `price_summary` and `refund_form` are for any record that is
bought, billed or paid for — an order, an invoice, a booking, a membership —
and stay in this library as an optional group; nothing else here depends on
them. `refund_form` defaults its reasons to the codes card processors use,
and takes `reasons` of your own. There is no payment-method component: how
something was paid is one payment provider's shape (its risk fields, its
card brands and their pictures), so the store that uses it draws its own,
and registers it with `Components::register()` if it wants a field type.
`Icon::get( 'card' )` (`heroicons-outline:credit-card`) is there for its
fallback.

## Moving from arraypress/wp-*

`arraypress/wp-register-flyouts` is this package. `ArrayPress\RegisterFlyouts\X`
is now `SugarCommerce\Flyouts\X`, and every class, method, component, panel
and field configuration key keeps its name and meaning. The global functions
are gone — a library several plugins bundle must not declare any — and each
is a static method of `Flyouts`. Text domains `arraypress` and `wp-flyout` are
now `sugarcommerce`.

| Old | New |
|---|---|
| `ArrayPress\RegisterFlyouts\*` | `SugarCommerce\Flyouts\*` |
| `register_flyout( $id, $config )` | `Flyouts::register( $id, $config )` |
| `get_flyout_button( $id, $args )` | `Flyouts::button( $id, $args )` |
| `render_flyout_button( $id, $args )` | `Flyouts::render_button( $id, $args )` |
| `get_flyout_link( $id, $args )` | `Flyouts::link( $id, $args )` |
| `render_flyout_link( $id, $args )` | `Flyouts::render_link( $id, $args )` |
| `Registry::instance()->get( $prefix )` | the same, or `Flyouts::manager( $prefix )` |
| `FormField::to_kit( $config )` | `FormField::to_field( $config )` |
| `arraypress/wp-field-kit` | `sugarcommerce/fields`: a `custom` field's `render_callback` gets `SugarCommerce\Fields\Field` and `Attributes` |
| `arraypress/wp-money` (`format_money()`) | `sugarcommerce/money` (`Money::format()`) |
| `arraypress/wp-composer-assets` | none: `Assets::url( $relative )` finds the library's own files |
| `time()` behind an activity feed's "2 hours ago" | `SugarCommerce\Dates\Clock::time()`, so a test that freezes the clock freezes the feed |
| REST `wp-flyout/v1/…` (prefixed builds `{prefix}-wp-flyout/v1`) | `sugarcommerce-flyouts/v1/…` (`{prefix}-sugarcommerce-flyouts/v1`) |
| script and style handles `wp-flyout`, `wp-flyout-{component}`, `wp-flyout-wp-flyout` | `sugarcommerce-flyouts`, `sugarcommerce-flyouts-{component}`, `sugarcommerce-flyouts-wp-flyout` |
| `window.ArrayPressFlyouts` (the per-build config registry) | `window.SugarCommerceFlyouts` |
| icon class `arraypress-icon` | `sugarcommerce-icon` (the fields package's `Icons::ICON_CLASS`) |
| asset versions `time()` under `WP_DEBUG`, otherwise `1.0.0` | each file's modification time |
| the fields package's REST search URL `field-kit/v1/search` on `line_items` | `sugarcommerce-fields/v1/search` |

Kept as they were, because each is already named from the manager's prefix
or the panel's id, and renaming them would only churn every plugin and
stylesheet that targets them: every `wp_flyout_*` hook
(`wp_flyout_{prefix}_{panel}_data`, `wp_flyout_{prefix}_{panel}_saved`,
`wp_flyout_render_field_{prefix}_{field}`, `wp_flyout_before_render_fields_{prefix}`,
`wp_flyout_build_flyout_{prefix}`, `wp_flyout_rest_capability_{prefix}` and the
rest), the `wp-flyout-*` CSS classes, the `wpflyout:*` jQuery events
(`opened`, `closed`, `row-action`, `menu-action`, `note-added` and the rest),
`window.WPFlyout`, the asset file names and the `field-kit__*` classes the
fields package draws.

## Requirements

* PHP 8.3 or later
* WordPress 7.1 or later

## License

GPL-2.0-or-later
