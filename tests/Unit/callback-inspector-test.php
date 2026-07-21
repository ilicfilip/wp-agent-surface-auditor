<?php
/**
 * Callback_Inspector describe() + analyze() tests against real fixtures.
 *
 * @package ASA
 */

use ASA\Analysis\Callback_Inspector;

require_once dirname( __DIR__ ) . '/fixtures/fixture-callbacks.php';

/**
 * @covers \ASA\Analysis\Callback_Inspector
 */
class Callback_Inspector_Test extends ASA_TestCase {

	private function analyze_fixture( $callback ) {
		return Callback_Inspector::analyze( Callback_Inspector::describe( $callback ) );
	}

	public function test_describe_named_function_finds_source() {
		$info = Callback_Inspector::describe( 'asa_fixture_return_true' );

		$this->assertSame( 'function', $info['type'] );
		$this->assertSame( 'asa_fixture_return_true', $info['name'] );
		$this->assertTrue( $info['source_available'] );
		$this->assertStringContainsString( 'fixture-callbacks.php', $info['file'] );
	}

	public function test_describe_null_is_none() {
		$this->assertSame( 'none', Callback_Inspector::describe( null )['type'] );
	}

	public function test_return_true_fixture_flags_unconditional() {
		$analysis = $this->analyze_fixture( 'asa_fixture_return_true' );

		$this->assertTrue( $analysis['resolved'] );
		$this->assertTrue( $analysis['returns_only_literal_true'] );
		$this->assertFalse( $analysis['calls_current_user_can'] );
	}

	public function test_capability_gate_fixture_is_not_unconditional() {
		$analysis = $this->analyze_fixture( 'asa_fixture_capability_gate' );

		$this->assertTrue( $analysis['resolved'] );
		$this->assertFalse( $analysis['returns_only_literal_true'] );
		$this->assertTrue( $analysis['calls_current_user_can'] );
		$this->assertSame( [ 'manage_options' ], $analysis['capability_checks'] );
	}

	public function test_auth_only_fixture_detected() {
		$analysis = $this->analyze_fixture( 'asa_fixture_auth_only' );

		$this->assertTrue( $analysis['calls_is_user_logged_in'] );
		$this->assertFalse( $analysis['calls_current_user_can'] );
	}

	public function test_broad_cap_fixture_captures_capability() {
		$analysis = $this->analyze_fixture( 'asa_fixture_broad_cap' );

		$this->assertSame( [ 'read' ], $analysis['capability_checks'] );
	}

	public function test_writer_fixture_yields_write_indicators() {
		$analysis = $this->analyze_fixture( 'asa_fixture_writer' );

		$this->assertContains( 'wp_insert_post()', $analysis['write_indicators'] );
		$this->assertContains( 'update_option()', $analysis['write_indicators'] );
	}

	public function test_wpdb_writer_fixture_yields_method_indicator() {
		$analysis = $this->analyze_fixture( 'asa_fixture_wpdb_writer' );

		$this->assertContains( '->query()', $analysis['write_indicators'] );
	}

	public function test_reader_fixture_is_clean_despite_comment_and_string_mentions() {
		$analysis = $this->analyze_fixture( 'asa_fixture_reader' );

		$this->assertTrue( $analysis['resolved'] );
		$this->assertSame( [], $analysis['write_indicators'] );
	}

	public function test_wp_builtin_return_true_resolves_by_name() {
		// __return_true does not exist in the unit env; simulate the described
		// shape the reader would produce on a real site.
		$analysis = Callback_Inspector::analyze(
			[
				'type'             => 'function',
				'name'             => '__return_true',
				'file'             => null,
				'line_start'       => null,
				'line_end'         => null,
				'source_available' => false,
			]
		);

		$this->assertTrue( $analysis['resolved'] );
		$this->assertTrue( $analysis['returns_only_literal_true'] );
	}

	public function test_closure_returning_true_detected() {
		$closure  = function () {
			return true;
		};
		$analysis = $this->analyze_fixture( $closure );

		$this->assertTrue( $analysis['resolved'] );
		$this->assertTrue( $analysis['returns_only_literal_true'] );
	}

	public function test_arrow_function_returning_true_detected() {
		$closure  = static fn() => true;
		$analysis = $this->analyze_fixture( $closure );

		$this->assertTrue( $analysis['resolved'] );
		$this->assertTrue( $analysis['returns_only_literal_true'] );
	}

	public function test_unresolvable_source_reports_unresolved() {
		$analysis = Callback_Inspector::analyze(
			[
				'type'             => 'function',
				'name'             => 'strlen',
				'file'             => null,
				'line_start'       => null,
				'line_end'         => null,
				'source_available' => false,
			]
		);

		$this->assertFalse( $analysis['resolved'] );
		$this->assertNull( $analysis['returns_only_literal_true'] );
	}
}
