<?php
/**
 * How the panels' scripts reach the REST API.
 *
 * @package SugarCommerce\Flyouts
 */

declare( strict_types=1 );

namespace SugarCommerce\Flyouts\Tests;

use SugarCommerce\Flyouts\Assets;
use SugarCommerce\Flyouts\Utils\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Every request goes through wp.apiFetch, which renews a nonce that ran out
 * while the screen was open, and is read the way a fetch Response was.
 */
final class RequestScriptTest extends TestCase {

	/**
	 * Run the scripts against a pretend page.
	 *
	 * @param string $scenario See tests/js/request.cjs.
	 *
	 * @return array<string, mixed>
	 */
	private function run_script( string $scenario ): array {
		$node = trim( (string) shell_exec( 'command -v node' ) );

		if ( '' === $node ) {
			$this->markTestSkipped( 'Node is not installed.' );
		}

		$output = shell_exec(
			escapeshellarg( $node ) . ' ' . escapeshellarg( __DIR__ . '/js/request.cjs' ) . ' '
			. escapeshellarg( dirname( __DIR__ ) . '/assets/js' ) . ' ' . escapeshellarg( $scenario ) . ' 2>&1'
		);

		$report = json_decode( (string) $output, true );
		$this->assertIsArray( $report, (string) $output );

		return $report;
	}

	public function test_the_core_scripts_need_api_fetch(): void {
		$GLOBALS['flyouts_test_scripts'] = [];

		Assets::register_assets();

		$this->assertContains( 'wp-api-fetch', $GLOBALS['flyouts_test_scripts'][ Runtime::handle( 'wp-flyout' ) ] );
	}

	public function test_a_request_goes_through_api_fetch(): void {
		$report = $this->run_script( 'ok' );

		$this->assertSame( [], $report['fetch'] );
		$this->assertSame( 'https://example.test/wp-json/flyouts/v1/save', $report['apiFetch'][0]['url'] );
		$this->assertSame( 'POST', $report['apiFetch'][0]['method'] );
		$this->assertSame( '{"name":""}', $report['apiFetch'][0]['body'] );
		$this->assertSame( [ 'success' => true, 'html' => '<p>ok</p>' ], $report['resolved'] );
	}

	public function test_a_refusal_keeps_its_message_code_and_data(): void {
		$report = $this->run_script( 'refused' );

		$this->assertSame(
			[ 'message' => 'Pick a name.', 'code' => 'invalid_field', 'data' => [ 'status' => 400, 'field' => 'name' ] ],
			$report['rejected']
		);
	}

	public function test_an_answer_that_is_not_json_says_something_went_wrong(): void {
		$this->assertSame( 'Something went wrong. Please try again.', $this->run_script( 'not-json' )['rejected']['message'] );
	}

	public function test_a_page_without_api_fetch_still_uses_fetch(): void {
		$report = $this->run_script( 'no-api-fetch' );

		$this->assertSame( [ 'https://example.test/wp-json/flyouts/v1/save' ], $report['fetch'] );
		$this->assertSame( [ 'from' => 'fetch' ], $report['resolved'] );
	}

	public function test_no_component_calls_fetch_itself(): void {
		foreach ( glob( dirname( __DIR__ ) . '/assets/js/{core,components}/*.js', GLOB_BRACE ) as $file ) {
			$this->assertDoesNotMatchRegularExpression( '/(?<![.\w])fetch\(\s*wpFlyout/', (string) file_get_contents( $file ), basename( $file ) );
		}
	}
}
