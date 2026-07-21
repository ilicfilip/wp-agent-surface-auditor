<?php
/**
 * ASA005 Exposed_Weak_Permission composite rule tests.
 *
 * @package ASA
 */

use ASA\Model\Finding;
use ASA\Rules\Exposed_Weak_Permission;

/**
 * @covers \ASA\Rules\Exposed_Weak_Permission
 */
class Exposed_Weak_Permission_Test extends ASA_TestCase {

	public function test_silent_without_permission_smells() {
		$rule = new Exposed_Weak_Permission();

		$prior = [ $this->finding( 'ASA009', Finding::SEVERITY_LOW ) ];

		$this->assertSame(
			[],
			$rule->evaluate( $this->descriptor(), $this->exposure( [ 'rest' => true ] ), $prior )
		);
	}

	public function test_silent_when_smell_exists_but_not_reachable() {
		$rule = new Exposed_Weak_Permission();

		$prior = [ $this->finding( 'ASA001', Finding::SEVERITY_CRITICAL ) ];

		$this->assertSame( [], $rule->evaluate( $this->descriptor(), $this->exposure(), $prior ) );
	}

	public function test_fires_critical_on_reachable_plus_smell() {
		$rule = new Exposed_Weak_Permission();

		$prior = [ $this->finding( 'ASA001', Finding::SEVERITY_CRITICAL, Finding::CONFIDENCE_HIGH ) ];

		$finding = $this->assertSingleFinding(
			'ASA005',
			$rule->evaluate( $this->descriptor(), $this->exposure( [ 'rest' => true ] ), $prior )
		);
		$this->assertSame( Finding::SEVERITY_CRITICAL, $finding->severity );
		$this->assertSame( Finding::CONFIDENCE_HIGH, $finding->confidence );
		$this->assertStringContainsString( 'ASA001', $finding->message );
	}

	public function test_inherits_weakest_contributing_confidence() {
		$rule = new Exposed_Weak_Permission();

		$prior = [
			$this->finding( 'ASA001', Finding::SEVERITY_CRITICAL, Finding::CONFIDENCE_HIGH ),
			$this->finding( 'ASA003', Finding::SEVERITY_HIGH, Finding::CONFIDENCE_MEDIUM ),
		];

		$finding = $this->assertSingleFinding(
			'ASA005',
			$rule->evaluate( $this->descriptor(), $this->exposure( [ 'rest' => true ] ), $prior )
		);
		$this->assertSame( Finding::CONFIDENCE_MEDIUM, $finding->confidence );
		$this->assertStringContainsString( 'ASA001', $finding->message );
		$this->assertStringContainsString( 'ASA003', $finding->message );
	}

	public function test_intended_only_mcp_exposure_counts_as_reachable() {
		$rule = new Exposed_Weak_Permission();

		$prior    = [ $this->finding( 'ASA002', Finding::SEVERITY_CRITICAL, Finding::CONFIDENCE_MEDIUM ) ];
		$exposure = $this->exposure(
			[
				'mcp_public'        => true,
				'mcp_intended_only' => true,
			]
		);

		$finding = $this->assertSingleFinding( 'ASA005', $rule->evaluate( $this->descriptor(), $exposure, $prior ) );
		$this->assertSame( Finding::CONFIDENCE_MEDIUM, $finding->confidence );
	}
}
