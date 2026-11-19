/**
 * What a panel's form sends to the save route.
 *
 * The pairs are jQuery's serializeArray()'s, which the old script used, and
 * the object is the one the save route has always been sent.
 */

import { beforeAll, describe, expect, test } from 'vitest';

import { collect, missing, pairs, toObject } from '../../src/js/lib/form';
import { layout } from './support/layout';

beforeAll( layout );

const form = ( markup ) => {
	document.body.innerHTML = `<form>${ markup }</form>`;

	return document.querySelector( 'form' );
};

describe( 'pairs()', () => {
	test( 'a control needs a name and must not be disabled', () => {
		const sent = pairs(
			form(
				'<input name="a" value="1"><input value="no name"><input name="b" value="2" disabled>' +
					'<fieldset disabled><input name="c" value="3"></fieldset>'
			)
		);

		expect( sent ).toEqual( [ { name: 'a', value: '1' } ] );
	} );

	test( 'buttons, files and images are not sent', () => {
		const sent = pairs(
			form(
				'<input type="submit" name="s" value="x"><input type="button" name="b" value="x">' +
					'<input type="reset" name="r" value="x"><input type="file" name="f"><button name="q" value="x">Q</button>' +
					'<input type="hidden" name="h" value="kept">'
			)
		);

		expect( sent ).toEqual( [ { name: 'h', value: 'kept' } ] );
	} );

	test( 'a box or a radio only when it is ticked', () => {
		const sent = pairs(
			form(
				'<input type="checkbox" name="on" value="1" checked><input type="checkbox" name="off" value="1">' +
					'<input type="radio" name="r" value="a"><input type="radio" name="r" value="b" checked>' +
					'<input type="checkbox" name="bare" checked>'
			)
		);

		expect( sent ).toEqual( [
			{ name: 'on', value: '1' },
			{ name: 'r', value: 'b' },
			{ name: 'bare', value: 'on' },
		] );
	} );

	test( 'each selected option, none from a disabled option or group', () => {
		const sent = pairs(
			form(
				'<select name="m" multiple><option selected>a</option><option>b</option><option selected disabled>c</option>' +
					'<optgroup disabled><option selected>d</option></optgroup><option selected value="e">E</option></select>' +
					'<select name="empty"></select>'
			)
		);

		expect( sent ).toEqual( [
			{ name: 'm', value: 'a' },
			{ name: 'm', value: 'e' },
		] );
	} );

	test( 'line breaks are sent as CRLF', () => {
		const area = form( '<textarea name="t"></textarea>' ).querySelector(
			'textarea'
		);

		area.value = 'one\ntwo\r\nthree';

		expect( pairs( area.form ) ).toEqual( [
			{ name: 't', value: 'one\r\ntwo\r\nthree' },
		] );
	} );

	test( 'outside a form, the controls inside an element', () => {
		document.body.innerHTML = '<div><input name="a" value="1"></div>';

		expect( pairs( document.querySelector( 'div' ) ) ).toEqual( [
			{ name: 'a', value: '1' },
		] );
	} );
} );

describe( 'toObject()', () => {
	const pair = ( name, value ) => ( { name, value } );

	test( 'nested names nest, numbers and [] as lists', () => {
		expect(
			toObject( [
				pair( 'files[0][name]', 'a.zip' ),
				pair( 'files[0][size]', '3' ),
				pair( 'files[1][name]', 'b.zip' ),
				pair( 'meta[colour]', 'red' ),
				pair( 'tags[]', 'x' ),
				pair( 'tags[]', 'y' ),
			] )
		).toEqual( {
			files: [ { name: 'a.zip', size: '3' }, { name: 'b.zip' } ],
			meta: { colour: 'red' },
			tags: [ 'x', 'y' ],
		} );
	} );

	test( 'a plain name posted twice is a list of both', () => {
		expect(
			toObject( [ pair( 'a', '1' ), pair( 'a', '2' ), pair( 'a', '3' ) ] )
		).toEqual( {
			a: [ '1', '2', '3' ],
		} );
	} );

	test( 'a switch is the box after its hidden nought, not a list', () => {
		expect(
			toObject( [ pair( 'on', '0' ), pair( 'on', '1' ) ], [ 'on' ] )
		).toEqual( {
			on: '1',
		} );
	} );

	test( 'a box that is not ticked is told "0", unless something else sent it', () => {
		expect(
			toObject( [ pair( 'kept', '0' ) ], [], [ 'off', 'kept', '' ] )
		).toEqual( {
			kept: '0',
			off: '0',
		} );
	} );

	test( '[] after an object key does not push', () => {
		expect(
			toObject( [ pair( 'a[b]', '1' ), pair( 'a[]', '2' ) ] )
		).toEqual( { a: { b: '1' } } );
	} );
} );

describe( 'collect()', () => {
	test( 'a switch that is on stays on; one that is off is off', () => {
		const data = collect(
			form(
				'<input type="hidden" name="featured" value="0"><input type="checkbox" name="featured" value="1" checked>' +
					'<input type="hidden" name="gift" value="0"><input type="checkbox" name="gift" value="1">' +
					'<input type="checkbox" name="news" value="yes">' +
					'<input type="hidden" name="list[]" value="0"><input type="checkbox" name="list[]" value="1" checked>'
			)
		);

		expect( data ).toEqual( {
			featured: '1',
			gift: '0',
			news: '0',
			list: [ '0', '1' ],
		} );
	} );
} );

describe( 'missing()', () => {
	test( 'required, shown, enabled and empty', () => {
		const sheet = form(
			'<input name="a" required><input name="b" required value="x">' +
				'<input name="c" required disabled><div hidden><input name="d" required></div>' +
				'<input name="e" required value="0">' +
				'<select name="f" required multiple><option>o</option></select>' +
				'<select name="g" required><option value="">None</option></select>'
		);

		expect( missing( sheet ).map( ( c ) => c.name ) ).toEqual( [
			'a',
			'f',
			'g',
		] );
	} );
} );
