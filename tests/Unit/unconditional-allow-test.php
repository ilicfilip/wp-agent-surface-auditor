<?php
/**
 * ASA002 Unconditional_Allow rule tests.
 *
 * @package ASA
 */

use ASA\Model\Finding;
use ASA\Rules\Rule_Engine;
use ASA\Rules\Unconditional_Allow;

/**
 * @covers \ASA\Rules\Unconditional_Allow
 */
class Unconditional_Allow_Test extends ASA_TestCase {

	public function test_silent_on_real_gate() {
		$rule = new Unconditional_Allow();

		$this->assertSame( [], $rule->evaluate( $this->descriptor(), $this->exposure() ) );
	}

	public function test_return_true_by_name_is_critical_high() {
		$rule = new Unconditional_Allow();

		$descriptor = $this->descriptor(
			[
				'permission_callback' => [
					'type'             => 'function',
					'name'             => '__return_true',
					'source_available' => false,
				],
				'permission_analysis' => [
					'resolved'                  => true,
					'returns_only_literal_true' => true,
					'calls_current_user_can'    => false,
					'calls_is_user_logged_in'   => false,
					'capability_checks'         => [],
					'write_indicators'          => [],
				],
			]
		);

		$finding = $this->assertSingleFinding( 'ASA002', $rule->evaluate( $descriptor, $this->exposure() ) );
		$this->assertSame( Finding::SEVERITY_CRITICAL, $finding->severity );
		$this->assertSame( Finding::CONFIDENCE_HIGH, $finding->confidence );
		$this->assertStringContainsString( '__return_true', $finding->message );
	}

	public function test_source_based_literal_true_is_critical_medium() {
		$rule = new Unconditional_Allow();

		$descriptor = $this->descriptor(
			[
				'permission_callback' => [ 'type' => 'closure', 'name' => null, 'source_available' => true ],
				'permission_analysis' => [
					'resolved'                  => true,
					'returns_only_literal_true' => true,
					'calls_current_user_can'    => false,
					'calls_is_user_logged_in'   => false,
					'capability_checks'         => [],
					'write_indicators'          => [],
				],
			]
		);

		$finding = $this->assertSingleFinding( 'ASA002', $rule->evaluate( $descriptor, $this->exposure() ) );
		$this->assertSame( Finding::CONFIDENCE_MEDIUM, $finding->confidence );
	}

	public function test_unresolved_source_emits_asa000_not_a_pass() {
		$rule = new Unconditional_Allow();

		$descriptor = $this->descriptor(
			[
				'permission_analysis' => [ 'resolved' => false, 'returns_only_literal_true' => null ],
			]
		);

		$finding = $this->assertSingleFinding(
			Rule_Engine::COULD_NOT_ANALYZE,
			$rule->evaluate( $descriptor, $this->exposure() )
		);
		$this->assertSame( Finding::SEVERITY_INFO, $finding->severity );
		$this->assertStringContainsString( 'could not be statically analyzed', $finding->message );
	}

	public function test_silent_when_callback_missing_or_unavailable() {
		$rule = new Unconditional_Allow();

		$this->assertSame(
			[],
			$rule->evaluate( $this->descriptor( [ 'permission_callback' => [ 'type' => 'none' ] ] ), $this->exposure() )
		);
		$this->assertSame(
			[],
			$rule->evaluate( $this->descriptor( [ 'callback_origin' => 'unavailable' ] ), $this->exposure() )
		);
	}
}
