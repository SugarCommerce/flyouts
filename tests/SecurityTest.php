<?php
/**
 * Security tests.
 *
 * @package SugarCommerce\Flyouts
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Flyouts\Manager;

/**
 * What an attacker would try against the markup a flyout writes.
 */
final class SecurityTest extends TestCase {

	/**
	 * A trigger's data keys become attribute names, so they are keys.
	 *
	 * The values were escaped and the names were not: a key a plugin took
	 * from a record -- `[ $field => $value ]` -- could close the attribute
	 * and open one of its own, an `onmouseover` on every Edit button.
	 */
	public function test_a_data_key_cannot_add_an_attribute(): void {
		$manager = new Manager( 'security_keys' );
		$manager->register_flyout( 'order', [ 'title' => 'Order' ] );

		$evil = [ 'id" onmouseover="alert(1)' => 7, 'Order_ID' => 8 ];

		foreach ( [ $manager->get_button( 'order', $evil ), $manager->link( 'order', 'Open', $evil ) ] as $html ) {
			$this->assertStringNotContainsString( 'onmouseover="', $html );
			$this->assertStringNotContainsString( '" onmouseover', $html );
			$this->assertStringContainsString( 'data-idonmouseoveralert1="7"', $html );
			$this->assertStringContainsString( 'data-order_id="8"', $html );
		}
	}
}
