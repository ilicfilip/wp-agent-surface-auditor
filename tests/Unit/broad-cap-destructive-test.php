<?php
/**
 * ASA008 Broad_Cap_Destructive rule tests.
 *
 * @package ASA
 */

use ASA\Model\Finding;
use ASA\Rules\Broad_Cap_Destructive;

/**
 * @covers \ASA\Rules\Broad_Cap_Destructive
 */
class Broad_Cap_Destructive_Test extends ASA_TestCase {

	private function gate_analysis( array $capabilities ) {
		return [
			'resolved'                  => true,
			'returns_only_literal_true' => false,
			'calls_current_user_can'    => true,
			'calls_is_user_logged_in'   => false,
			'capability_checks'         => $capabilities,
			'write_indicators'          => [],
		];
	}

	public function test_silent_for_readonly_ability_with_broad_cap() {
		$rule = new Broad_Cap_Destructive();

		$descriptor = $this->descriptor( [ 'permission_analysis' => $this->gate_analysis( [ 'read' ] ) ] );

		$this->assertSame( [], $rule->evaluate( $descriptor, $this->exposure() ) );
	}

	public function test_flags_destructive_gated_on_read() {
		$rule = new Broad_Cap_Destructive();

		$descriptor = $this->descriptor(
			[
				'annotations'         => [ 'readonly' => null, 'destructive' => true, 'idempotent' => null ],
				'permission_analysis' => $this->gate_analysis( [ 'read' ] ),
			]
		);

		$finding = $this->assertSingleFinding( 'ASA008', $rule->evaluate( $descriptor, $this->exposure() ) );
		$this->assertSame( Finding::SEVERITY_HIGH, $finding->severity );
		$this->assertSame( Finding::CONFIDENCE_MEDIUM, $finding->confidence );
		$this->assertStringContainsString( 'read', $finding->message );
	}

	public function test_readonly_false_also_counts_as_destructive_side() {
		$rule = new Broad_Cap_Destructive();

		$descriptor = $this->descriptor(
			[
				'annotations'         => [ 'readonly' => false, 'destructive' => null, 'idempotent' => null ],
				'permission_analysis' => $this->gate_analysis( [ 'exist' ] ),
			]
		);

		$this->assertSingleFinding( 'ASA008', $rule->evaluate( $descriptor, $this->exposure() ) );
	}

	public function test_strong_capability_alongside_broad_one_passes() {
		$rule = new Broad_Cap_Destructive();

		$descriptor = $this->descriptor(
			[
				'annotations'         => [ 'readonly' => null, 'destructive' => true, 'idempotent' => null ],
				'permission_analysis' => $this->gate_analysis( [ 'read', 'delete_posts' ] ),
			]
		);

		$this->assertSame( [], $rule->evaluate( $descriptor, $this->exposure() ) );
	}

	public function test_silent_without_detected_capabilities() {
		$rule = new Broad_Cap_Destructive();

		$descriptor = $this->descriptor(
			[
				'annotations'         => [ 'readonly' => null, 'destructive' => true, 'idempotent' => null ],
				'permission_analysis' => $this->gate_analysis( [] ),
			]
		);

		// No literal capability captured: could be a variable/dynamic check.
		// ASA002/003 cover the truly-weak shapes; guessing here would be noise.
		$this->assertSame( [], $rule->evaluate( $descriptor, $this->exposure() ) );
	}
}
