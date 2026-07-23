<?php
/**
 * ASA003 Auth_Only_Gate rule tests.
 *
 * @package ASA
 */

use ASA\Model\Finding;
use ASA\Rules\Auth_Only_Gate;

/**
 * @covers \ASA\Rules\Auth_Only_Gate
 */
class Auth_Only_Gate_Test extends ASA_TestCase {

	private function auth_only_analysis() {
		return [
			'resolved'                  => true,
			'returns_only_literal_true' => false,
			'calls_current_user_can'    => false,
			'calls_is_user_logged_in'   => true,
			'capability_checks'         => [],
			'write_indicators'          => [],
		];
	}

	public function test_silent_on_capability_gate() {
		$rule = new Auth_Only_Gate();

		$this->assertSame( [], $rule->evaluate( $this->descriptor(), $this->exposure() ) );
	}

	public function test_is_user_logged_in_by_name_is_high_confidence() {
		$rule = new Auth_Only_Gate();

		// Annotations undeclared, so the read-only downgrade does not apply
		// and this probes the confidence tier alone.
		$descriptor = $this->descriptor(
			[
				'permission_callback' => [
					'type'             => 'function',
					'name'             => 'is_user_logged_in',
					'source_available' => true,
				],
				'permission_analysis' => $this->auth_only_analysis(),
				'annotations'         => [ 'readonly' => null, 'destructive' => null, 'idempotent' => null ],
			]
		);

		$finding = $this->assertSingleFinding( 'ASA003', $rule->evaluate( $descriptor, $this->exposure() ) );
		$this->assertSame( Finding::SEVERITY_HIGH, $finding->severity );
		$this->assertSame( Finding::CONFIDENCE_HIGH, $finding->confidence );
	}

	public function test_source_based_auth_only_is_medium_confidence() {
		$rule = new Auth_Only_Gate();

		$descriptor = $this->descriptor(
			[
				'permission_callback' => [ 'type' => 'closure', 'name' => null, 'source_available' => true ],
				'permission_analysis' => $this->auth_only_analysis(),
				'annotations'         => [ 'readonly' => null, 'destructive' => null, 'idempotent' => null ],
			]
		);

		$finding = $this->assertSingleFinding( 'ASA003', $rule->evaluate( $descriptor, $this->exposure() ) );
		$this->assertSame( Finding::CONFIDENCE_MEDIUM, $finding->confidence );
	}

	public function test_auth_check_plus_capability_check_is_fine() {
		$rule = new Auth_Only_Gate();

		$analysis                           = $this->auth_only_analysis();
		$analysis['calls_current_user_can'] = true;
		$analysis['capability_checks']      = [ 'edit_posts' ];

		$descriptor = $this->descriptor( [ 'permission_analysis' => $analysis ] );

		$this->assertSame( [], $rule->evaluate( $descriptor, $this->exposure() ) );
	}

	/**
	 * A resolved execute source with no write indicators.
	 *
	 * @param string[] $write_indicators Indicators to report.
	 * @return array<string, mixed> An execute_analysis array.
	 */
	private function execute_analysis( array $write_indicators = [] ) {
		return [
			'resolved'                  => true,
			'returns_only_literal_true' => false,
			'calls_current_user_can'    => false,
			'calls_is_user_logged_in'   => false,
			'capability_checks'         => [],
			'write_indicators'          => $write_indicators,
		];
	}

	public function test_declared_readonly_without_write_indicators_is_downgraded() {
		$rule = new Auth_Only_Gate();

		// The core/get-user-info shape: a self-scoped read behind an
		// auth-only closure. Still reported, but not as a high.
		$descriptor = $this->descriptor(
			[
				'permission_callback' => [ 'type' => 'closure', 'name' => null, 'source_available' => true ],
				'permission_analysis' => $this->auth_only_analysis(),
				'annotations'         => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'execute_analysis'    => $this->execute_analysis(),
			]
		);

		$finding = $this->assertSingleFinding( 'ASA003', $rule->evaluate( $descriptor, $this->exposure() ) );
		$this->assertSame( Finding::SEVERITY_LOW, $finding->severity );
		$this->assertSame( Finding::CONFIDENCE_MEDIUM, $finding->confidence );
		$this->assertStringContainsString( 'scoped to the calling user', $finding->remediation );
	}

	public function test_readonly_claim_with_write_indicators_keeps_high_severity() {
		$rule = new Auth_Only_Gate();

		// A liar (also an ASA006): the readonly claim must not buy a downgrade.
		$descriptor = $this->descriptor(
			[
				'permission_callback' => [ 'type' => 'closure', 'name' => null, 'source_available' => true ],
				'permission_analysis' => $this->auth_only_analysis(),
				'annotations'         => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'execute_analysis'    => $this->execute_analysis( [ 'update_option()' ] ),
			]
		);

		$finding = $this->assertSingleFinding( 'ASA003', $rule->evaluate( $descriptor, $this->exposure() ) );
		$this->assertSame( Finding::SEVERITY_HIGH, $finding->severity );
	}

	public function test_readonly_claim_with_unreadable_execute_keeps_high_severity() {
		$rule = new Auth_Only_Gate();

		// No readable execute span is not evidence the ability does not write.
		$descriptor = $this->descriptor(
			[
				'permission_callback' => [ 'type' => 'closure', 'name' => null, 'source_available' => true ],
				'permission_analysis' => $this->auth_only_analysis(),
				'annotations'         => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'execute_analysis'    => [ 'resolved' => false ],
			]
		);

		$finding = $this->assertSingleFinding( 'ASA003', $rule->evaluate( $descriptor, $this->exposure() ) );
		$this->assertSame( Finding::SEVERITY_HIGH, $finding->severity );
	}

	public function test_undeclared_annotations_keep_high_severity() {
		$rule = new Auth_Only_Gate();

		$descriptor = $this->descriptor(
			[
				'permission_callback' => [ 'type' => 'closure', 'name' => null, 'source_available' => true ],
				'permission_analysis' => $this->auth_only_analysis(),
				'annotations'         => [ 'readonly' => null, 'destructive' => null, 'idempotent' => null ],
				'execute_analysis'    => $this->execute_analysis(),
			]
		);

		$finding = $this->assertSingleFinding( 'ASA003', $rule->evaluate( $descriptor, $this->exposure() ) );
		$this->assertSame( Finding::SEVERITY_HIGH, $finding->severity );
	}

	public function test_silent_when_unresolved() {
		$rule = new Auth_Only_Gate();

		// ASA002 owns the could-not-analyze info finding; no duplicate here.
		$descriptor = $this->descriptor( [ 'permission_analysis' => [ 'resolved' => false ] ] );

		$this->assertSame( [], $rule->evaluate( $descriptor, $this->exposure() ) );
	}
}
