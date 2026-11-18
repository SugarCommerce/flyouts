<?php
/**
 * Save errors that name a field.
 *
 * @package SugarCommerce\Flyouts
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts\Tests;

use SugarCommerce\Flyouts\Manager;
use SugarCommerce\Flyouts\RestApi;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;

/**
 * What the save route passes on about which field was wrong.
 *
 * A callback that knows the price is the problem can say so, and the panel
 * puts the message under the price. That depends on the error's data
 * reaching the script in a shape it can read -- one shape, whichever of the
 * two a callback used -- and on an error about somebody's typing not being
 * reported as the server having failed.
 */
final class SaveErrorsTest extends TestCase {

	/**
	 * A clean hook registry, and a user who may do anything.
	 */
	protected function setUp(): void {
		$GLOBALS['fk_filters'] = [];
		$GLOBALS['fk_actions'] = [];
		$GLOBALS['wf_caps']    = null;
	}

	/**
	 * Save a product through the route.
	 *
	 * @param string               $prefix    The manager's prefix.
	 * @param array<string, mixed> $callbacks `save` and, optionally, `validate`.
	 *
	 * @return mixed A response or an error.
	 */
	private function save( string $prefix, array $callbacks ) {
		$manager = new Manager( $prefix );

		$manager->register_flyout(
			'product',
			$callbacks + [
				'title'  => 'Product',
				'fields' => [
					'name'  => [ 'type' => 'text', 'label' => 'Name' ],
					'price' => [ 'type' => 'number', 'label' => 'Price' ],
				],
			]
		);

		$request = new WP_REST_Request( 'POST', '/save' );
		$request->set_param( 'manager', $prefix );
		$request->set_param( 'flyout', 'product' );
		$request->set_param( 'item_id', 12 );
		$request->set_param( 'form_data', [ 'name' => 'Deep House Kit', 'price' => '-1' ] );

		return RestApi::handle_save( $request );
	}

	/**
	 * `field` names one field, and the error's message is that field's.
	 */
	public function test_a_field_key_becomes_a_one_entry_map(): void {
		$result = $this->save(
			'err_field',
			[ 'save' => static fn() => new WP_Error( 'too_low', 'A price cannot be negative.', [ 'field' => 'price' ] ) ]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'too_low', $result->get_error_code() );
		$this->assertSame( 'A price cannot be negative.', $result->get_error_message() );

		$data = $result->get_error_data();

		$this->assertSame( [ 'price' => 'A price cannot be negative.' ], $data['fields'] );
		$this->assertSame( 'price', $data['field'], 'The callback\'s own key was dropped.' );
	}

	/**
	 * `fields` is passed through, each key with its own message.
	 */
	public function test_a_fields_map_is_passed_through(): void {
		$result = $this->save(
			'err_fields',
			[
				'save' => static fn() => new WP_Error(
					'invalid',
					'Two things need fixing.',
					[
						'fields' => [
							'price' => 'Cannot be negative.',
							'name'  => 'Already in use.',
						],
					]
				),
			]
		);

		$this->assertSame(
			[
				'price' => 'Cannot be negative.',
				'name'  => 'Already in use.',
			],
			$result->get_error_data()['fields']
		);
	}

	/**
	 * Both at once: the map wins for a key it has, and `field` adds one it has not.
	 */
	public function test_field_and_fields_are_merged(): void {
		$result = $this->save(
			'err_both',
			[
				'save' => static fn() => new WP_Error(
					'invalid',
					'The name is taken.',
					[
						'field'  => 'name',
						'fields' => [ 'price' => 'Cannot be negative.' ],
					]
				),
			]
		);

		$this->assertSame(
			[
				'price' => 'Cannot be negative.',
				'name'  => 'The name is taken.',
			],
			$result->get_error_data()['fields']
		);
	}

	/**
	 * An error about a field is a 422 unless the callback said otherwise.
	 */
	public function test_a_field_error_is_unprocessable_not_a_server_failure(): void {
		$default = $this->save(
			'err_status',
			[ 'save' => static fn() => new WP_Error( 'too_low', 'Too low.', [ 'field' => 'price' ] ) ]
		);

		$this->assertSame( 422, $default->get_error_data()['status'] );

		$chosen = $this->save(
			'err_status_own',
			[ 'save' => static fn() => new WP_Error( 'taken', 'Taken.', [ 'field' => 'name', 'status' => 409 ] ) ]
		);

		$this->assertSame( 409, $chosen->get_error_data()['status'] );
	}

	/**
	 * The same for an error the validate callback returns.
	 */
	public function test_a_validation_error_names_its_field_too(): void {
		$saved = false;

		$result = $this->save(
			'err_validate',
			[
				'validate' => static fn( array $data ) => (float) $data['price'] < 0
					? new WP_Error( 'too_low', 'A price cannot be negative.', [ 'field' => 'price' ] )
					: true,
				'save'     => static function () use ( &$saved ): bool {
					$saved = true;

					return true;
				},
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( [ 'price' => 'A price cannot be negative.' ], $result->get_error_data()['fields'] );
		$this->assertSame( 422, $result->get_error_data()['status'] );
		$this->assertFalse( $saved, 'A submission that failed validation was saved.' );
	}

	/**
	 * An error that names no field is left exactly as it was.
	 */
	public function test_an_error_without_a_field_is_untouched(): void {
		$result = $this->save(
			'err_plain',
			[ 'save' => static fn() => new WP_Error( 'db_down', 'Could not reach the database.', [ 'status' => 503 ] ) ]
		);

		$this->assertSame( [ 'status' => 503 ], $result->get_error_data() );

		$bare = $this->save( 'err_bare', [ 'save' => static fn() => new WP_Error( 'nope', 'No.' ) ] );

		$this->assertSame( [], $bare->get_error_data() );
	}

	/**
	 * Nonsense in the data is not passed on as a field.
	 */
	public function test_malformed_field_data_is_ignored(): void {
		$result = $this->save(
			'err_odd',
			[
				'save' => static fn() => new WP_Error(
					'odd',
					'Odd.',
					[
						'field'  => [ 'not', 'a', 'key' ],
						'fields' => [
							'price' => [ 'not a message' ],
							'name'  => '',
						],
					]
				),
			]
		);

		$this->assertArrayNotHasKey( 'status', $result->get_error_data() );
		$this->assertSame( [ 'price' => [ 'not a message' ], 'name' => '' ], $result->get_error_data()['fields'] );
	}

	/**
	 * A save that works still says so.
	 */
	public function test_a_successful_save_is_unchanged(): void {
		$result = $this->save( 'err_none', [ 'save' => static fn() => true ] );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertTrue( $result->get_data()['success'] );
	}
}
