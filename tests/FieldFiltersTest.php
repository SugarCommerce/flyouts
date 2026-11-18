<?php
/**
 * What a field filter can actually change.
 *
 * @package SugarCommerce\Flyouts
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts\Tests;

use SugarCommerce\Flyouts\Manager;
use PHPUnit\Framework\TestCase;

/**
 * A filter that changes a field has to change the field.
 *
 * The set the controls are rendered from used to be built before any of
 * these filters ran, from the untouched configuration. Only the label and
 * description came from the filtered array -- so a filter could restyle the
 * chrome around a control and never the control, silently.
 *
 * It looked correct from every angle except the page: the filter fired, the
 * array it returned held the change, and the rendered input did not.
 */
final class FieldFiltersTest extends TestCase {

	/**
	 * A clean hook registry between tests.
	 */
	protected function setUp(): void {
		$GLOBALS['fk_filters'] = [];
	}

	/**
	 * Render a one-field panel through the manager.
	 *
	 * @param array<string, mixed> $field The field.
	 *
	 * @return string
	 */
	private function render( array $field ): string {
		$manager = new Manager( 'probe' );

		$method = new \ReflectionMethod( $manager, 'render_fields' );

		return (string) $method->invoke( $manager, [ 'thing' => $field ], (object) [ 'thing' => '' ] );
	}

	/**
	 * The per-field filter reaches the control.
	 */
	public function test_a_field_filter_changes_the_control(): void {
		$GLOBALS['fk_filters']['wp_flyout_render_field_probe_thing'][] = static function ( array $field ): array {
			$field['placeholder'] = 'Changed by the filter';

			return $field;
		};

		$this->assertStringContainsString(
			'Changed by the filter',
			$this->render( [ 'type' => 'text', 'label' => 'Thing' ] )
		);
	}

	/**
	 * So does the broader one.
	 */
	public function test_the_every_field_filter_changes_the_control(): void {
		$GLOBALS['fk_filters']['wp_flyout_render_field_probe'][] = static function ( array $field ): array {
			$field['placeholder'] = 'Changed for every field';

			return $field;
		};

		$this->assertStringContainsString(
			'Changed for every field',
			$this->render( [ 'type' => 'text', 'label' => 'Thing' ] )
		);
	}

	/**
	 * And the one that runs over the whole set.
	 */
	public function test_the_before_render_filter_changes_the_control(): void {
		$GLOBALS['fk_filters']['wp_flyout_before_render_fields_probe'][] = static function ( array $fields ): array {
			$fields['thing']['placeholder'] = 'Changed before rendering';

			return $fields;
		};

		$this->assertStringContainsString(
			'Changed before rendering',
			$this->render( [ 'type' => 'text', 'label' => 'Thing' ] )
		);
	}

	/**
	 * A field nobody filtered still renders.
	 */
	public function test_an_unfiltered_field_is_untouched(): void {
		$markup = $this->render( [ 'type' => 'text', 'label' => 'Thing', 'placeholder' => 'Original' ] );

		$this->assertStringContainsString( 'Original', $markup );
	}

	/**
	 * Fields that name a section are drawn on a titled card, in runs.
	 */
	public function test_sections_group_fields_on_titled_cards(): void {
		$manager = new Manager( 'probe' );

		( new \ReflectionProperty( $manager, 'sections' ) )->setValue(
			$manager,
			[
				'basics' => 'Basics',
				'extra'  => [ 'label' => 'Extra', 'collapsed' => true ],
			]
		);

		$html = (string) ( new \ReflectionMethod( $manager, 'render_fields' ) )->invoke(
			$manager,
			[
				'one'   => [ 'type' => 'text', 'label' => 'One', 'section' => 'basics' ],
				'two'   => [ 'type' => 'text', 'label' => 'Two', 'section' => 'basics' ],
				'three' => [ 'type' => 'text', 'label' => 'Three', 'section' => 'extra' ],
				'four'  => [ 'type' => 'text', 'label' => 'Four' ],
			],
			(object) []
		);

		// Two neighbours share one card; the third starts another.
		$this->assertSame( 2, substr_count( $html, '<section class="wp-flyout-section' ) );
		$this->assertSame( 1, substr_count( $html, '<details class="wp-flyout-section' ) );
		$this->assertSame( 1, substr_count( $html, '>Basics</h3>' ) );
		$this->assertStringContainsString( '<summary class="wp-flyout-section-title">Extra</summary>', $html );

		// Every card that opens closes.
		$this->assertSame( substr_count( $html, '<section' ), substr_count( $html, '</section>' ) );
		$this->assertSame( substr_count( $html, '<details' ), substr_count( $html, '</details>' ) );

		// And the key is spent here, not passed on as an unknown option.
		$this->assertStringNotContainsString( 'section="', $html );
	}

	/**
	 * A panel with no sections is drawn as it always was.
	 */
	public function test_no_sections_means_no_cards(): void {
		$this->assertStringNotContainsString(
			'wp-flyout-section',
			$this->render( [ 'type' => 'text', 'label' => 'Thing' ] )
		);
	}
}
