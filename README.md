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
them, and `background` with `color` -- both, as hex -- draws them
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
`background` and `color` of its own — both, as hex — and an
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

The fields package's own `tab` and `accordion` fields work here as they do
in a field set: two or more divide the fields after them, within the card
they are on, or within the page when the panel has no cards. An
`accordion` with `items` -- its own, or the record's under its key -- is
this library's accordion component instead.

```php
'fields' => [
	'tab_general'  => [ 'type' => 'tab', 'label' => __( 'General', 'my-plugin' ) ],
	'name'         => [ 'type' => 'text', 'label' => __( 'Name', 'my-plugin' ) ],
	'tab_advanced' => [ 'type' => 'tab', 'label' => __( 'Advanced', 'my-plugin' ) ],
	'debug'        => [ 'type' => 'toggle', 'label' => __( 'Debug', 'my-plugin' ) ],
],
```

## Fields that need a script

A panel is drawn over REST after the page has loaded, so whatever a field
type needs on the page is asked of the fields package for every panel on the
screen and enqueued up front: the code editor for each code field's
language, core's visual editor for a `wysiwyg` or an `email_editor`, the
media frame, core's colour picker -- for fields nested in groups and
repeaters too. The fields package starts each one on the panel's markup,
starts it again when the panel opens a second time, and stops it when the
panel closes. An amount's unit is handed to `save` beside it, under
`{key}_type` or its `type_meta_key`.

A field that asks the server as it is used -- a `slug` checking it is free, a
`location` asking its geocoder -- has its handler registered with the panel,
as a search is, under `{prefix}-{flyout}_{key}`, so the request that asks
finds it without the panel being drawn. Closing a panel with changes already
asks first; the fields package's unsaved-changes guard
(`Guard::enable( '.wp-flyout form' )`) adds a mark on each changed field and a
warning before leaving the page.

`tests/browser/fields-in-panels.js` opens every field type in a panel, fills
it through its own control, saves, opens it again, and does the same at a
phone's width; see the top of the file for how to run it.

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

Every request the panels make goes through `wp.apiFetch` (the scripts import
`@wordpress/api-fetch`), so a screen left open past its nonce's life renews
the nonce and tries again instead of answering 403.

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
is that field's alone and wins over the panel's. `major` is the default
(sugarcommerce/money's `Models\Unit` and `Money::minor()` read it).
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

## The scripts

Plain JavaScript in ES modules (`assets/js`), each with one job, built by
`@wordpress/scripts` into `assets/build` -- one file per script, with an
`.asset.php` naming the WordPress scripts it imports (`wp-api-fetch`,
`wp-dom-ready`, `wp-i18n`) and a version that changes with its contents.
`SugarCommerce\Plugin\Assets::register_built_script()` registers each from
that file, in the footer. No jQuery: it is no dependency of any of them.

| Entry | Handle, loaded by | Modules |
|---|---|---|
| `wp-flyout` | `{prefix}-wp-flyout`, on every screen with a panel (`Assets::enqueue()`) | `panel/panel` (open, close, overlay, tabs, focus; the build's `panel`), `panel/escape`, `manager/init`, `manager/load` (trigger, placeholder, load, the panel put in the page), `manager/header`, `manager/steps` (previous and next, J and K), `manager/menu`, `manager/actions` (menu, row and prompt actions), `manager/dirty` (unsaved changes), `manager/confirm`, `manager/save` (Save, Delete, the panel's buttons), `manager/errors` (a refused save under its field), `manager/clipboard`, `manager/skeleton`, `prompt/prompt` (the form an action asks for) |
| `accordion` | `{prefix}-accordion`, when a panel has one | `components/accordion` |
| `action-buttons` | `{prefix}-action-buttons` | `components/action-buttons`, `components/reload` |
| `activity` | `{prefix}-activity` | `components/activity` (the note box) |
| `image-picker` | `{prefix}-image-picker`, with core's media frame | `components/image-picker` |
| `line-items` | `{prefix}-line-items` | `components/line-items` |
| `refund-form` | `{prefix}-refund-form` | `components/refund-form`, `components/reload` |

The manager's, the alert's and the prompt's handles (`{prefix}-manager`,
`-alert`, `-prompt`) are still registered, with no file of their own: each
brings the one before it, so enqueuing or depending on any of them brings the
panels, as before. A component's script depends on `{prefix}-prompt`.

Shared by them all, in `lib/`: `config` (the one reader of what PHP
published: the build's entry in `window.SugarCommerceFlyouts`, found from the
script's own handle, with the English each string falls back to), `request`
(apiFetch, read the way a fetch Response was), `events`, `dom`, `form` (what
a panel's form sends -- jQuery's `serializeArray()` rules, then the object
the save route has always taken), `alert`, `feedback` (toast, the alert at the
top of a panel, a busy button), `motion` (the slides and fades jQuery did),
`money`, `store` and `page`. A component's script carries its own copy of
these; what they remember about an element is kept on the element, under
`Symbol.for( 'sugarcommerce-flyouts' )`, so every copy reads the same thing,
as they all read jQuery's data before.

### Events

Each is a native event that bubbles, its payload in `event.detail` -- and,
when the page has jQuery, the same event is handed to jQuery listeners with
the payload as extra arguments, exactly as before, once:

```js
// As SugarCart and the fields package listen, unchanged:
jQuery( document ).on( 'wpflyout:row-action', ( event, response, action ) => {} );
jQuery( document ).on( 'wpflyout:dirty', ( event, dirty ) => {} );

// Natively:
document.addEventListener( 'wpflyout:row-action', ( event ) => {
	const { response, action } = event.detail;
} );
```

| Event | On | `detail` | jQuery's arguments |
|---|---|---|---|
| `wpflyout:opened` | document | `{ id, element }` | `( event, { id, element } )` |
| `wpflyout:closing` (cancelable) | document | `{ id, element, force }` | `( event, { id, element, force } )` |
| `wpflyout:closed` | document | `{ id }` | `( event, { id } )` |
| `wpflyout:tab-changed` | document | `{ flyoutId, tabId }` | `( event, { flyoutId, tabId } )` |
| `flyout:ready` | the panel | `null` | `( event )` |
| `wpflyout:dirty` | the panel | `{ dirty }` | `( event, dirty )` |
| `wpflyout:menu-action`, `wpflyout:row-action`, `wpflyout:prompt-action` | the panel | `{ response, action }` | `( event, response, action )` |
| `wpflyout:note-added` | the activity feed | `{ response }` | `( event, response )` |
| `accordion:toggled` | the section | `{ isOpen }` | `( event, { isOpen } )` |
| `accordion:opened`, `accordion:closed` | the section | `null` | `( event )` |
| `actionbuttons:success` / `actionbuttons:error` | the button | the answer / the message | `( event, answer )` / `( event, message )` |
| `alert:shown`, `alert:dismissed`, `alert:beforedismiss` (cancelable) | the alert | `null` | `( event )` |
| `image-picker:changed` | the picker | `{ attachmentId, url }` | `( event, { attachmentId, url } )` |
| `image-picker:removed` | the picker | `null` | `( event )` |

`preventDefault()` from either kind of listener keeps a panel open on
`wpflyout:closing` (unless the close was forced) and an alert on
`alert:beforedismiss`. `lib/events.js` is the one place that reaches for
jQuery, and only when the page has it.

### Globals

One: `window.SugarCommerceFlyouts`, a registry keyed by each build's handle
(`Runtime::handle()`: `sugarcommerce-flyouts` in a plain install,
`{build}-sugarcommerce-flyouts` in a plugin's prefixed copy). Two plugins'
copies on one page each have their own entry, and nothing else of theirs is
on `window`. An entry is the settings `Manager::enqueue_assets()` publishes
-- `restUrl`, `restNonce`, `strings`, `icons`, and `fields`, the handle of
the same build's fields script, whose `window.SugarCommerceFields[ fields ].modules`
start and stop the fields in a panel -- and, once the core script has run:

* `panel` -- `open( id, options )`, `close( id, { force } )`, `closeAll()`,
  `getLastId()`, `discard( id )`, `active`. Its `describe()`,
  `focusInside()` and `initTabs()` take the panel's element (a jQuery object
  still works). It was `window.WPFlyout`.

A plugin's own script reaches its build's panel API by the handle, which its
PHP hands it -- read from the plugin's own copy of the class, so a prefixed
build names its prefixed handle:

```php
wp_add_inline_script( 'my-admin', sprintf( 'window.myAdmin = %s;', wp_json_encode( [
	'flyouts' => \SugarCommerce\Flyouts\Utils\Runtime::handle(),
] ) ), 'before' );
```

```js
const entry = ( window.SugarCommerceFlyouts || {} )[ window.myAdmin.flyouts ];
entry && entry.panel && entry.panel.close( 'wp-flyout-order-7' );
```

Every trigger and panel the PHP draws carries `data-flyout-build` with that
handle, and the scripts act only on what carries their own: another copy's
triggers are not answered, its panels not cleared, its overlay not reused.
Markup with no stamp -- a trigger written by hand -- is answered as before;
give it `data-flyout-build="<?php echo esc_attr( Runtime::handle() ); ?>"`
when another plugin's copy may be on the same page.

`WPFlyoutManager`, `WPFlyoutPrompt`, `WPFlyoutAlert` and the components'
`WPFlyout*` objects were how the old files reached each other; they are
modules now and no longer on `window`. Nothing outside this library read
them.

The image picker opens core's media frame through `wp.media`'s own API. A
panel's markup is put in the page with its scripts run, as jQuery's
`append()` did; a template (`type="text/template"`) is left alone.

```bash
npm ci
npm run build      # assets/build, committed
npm run start      # rebuild on change
npm run test:js    # Vitest, in jsdom (composer test:js runs it too)
npm run lint:js
```

The build's files are what ship. CI builds again and fails when the result
differs from what was committed.

## Security

* Opening, saving or deleting what you may not: every route asks the panel's
  capability (`wp_flyout_rest_capability_{prefix}` to change it), and an
  action or a header menu item its own on top.
* Forged requests: the routes are REST, so a signed-in browser's request
  needs core's `wp_rest` nonce.
* Running something a panel never offered: an action runs only when a field
  or the header menu declares it under that key.
* Values a panel does not declare: what is posted is sanitized by the field
  set, each value by its own type, and an undeclared key is dropped.
* Markup slipped into an attribute: a trigger's data keys are reduced to
  attribute-name characters and their values escaped.
* Leaking internals: a callback that throws answers "The save callback
  failed", with its message only under `WP_DEBUG`.
* Script in a note: a note's text goes through `wp_kses_post()` before the
  callback sees it.

## Layout

* `src/Manager.php`: one manager per prefix, holding the panels; its jobs are
  traits in `src/Traits/Manager/` (registering, building, the header,
  fields, rendering them, scripts, triggers).
* `src/Flyout.php`: one panel, with `src/Traits/Flyout/` (what its header
  says, and drawing it).
* `src/RestApi.php`: the routes and their permission check, with
  `src/Traits/RestApi/` (load, save and delete; actions).
* `src/Components/`: the components a panel can hold; the activity feed's
  jobs are in `src/Traits/Components/Activity/`.
* `src/Flyouts.php` (the static entry points), `Registry.php`, `Identity.php`,
  `Assets.php`, `Components.php`, `ActionBar.php`, and `src/Utils/` (icons,
  amounts, the runtime's names).
* `src/` is PHP only. The scripts' sources are in `assets/js/`, built to
  `assets/build/`; the styles are in `assets/css/`.

## Requirements

* PHP 8.3 or later
* WordPress 7.1 or later
* `sugarcommerce/dates`, `fields`, `money` and `text` (colours)

## License

GPL-2.0-or-later
