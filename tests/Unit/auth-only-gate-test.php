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

		$descriptor = $this->descriptor(
			[
				'permission_callback' => [
					'type'             => 'function',
					'name'             => 'is_user_logged_in',
					'source_available' => true,
				],
				'permission_analysis' => $this->auth_only_analysis(),
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

	public function test_silent_when_unresolved() {
		$rule = new Auth_Only_Gate();

		// ASA002 owns the could-not-analyze info finding; no duplicate here.
		$descriptor = $this->descriptor( [ 'permission_analysis' => [ 'resolved' => false ] ] );

		$this->assertSame( [], $rule->evaluate( $descriptor, $this->exposure() ) );
	}
}
