<?php
/**
 * ASA001 Missing_Permission rule tests.
 *
 * @package ASA
 */

use ASA\Model\Finding;
use ASA\Rules\Missing_Permission;

/**
 * @covers \ASA\Rules\Missing_Permission
 */
class Missing_Permission_Test extends ASA_TestCase {

	public function test_no_finding_for_present_callback() {
		$rule = new Missing_Permission();

		$this->assertSame( [], $rule->evaluate( $this->descriptor(), $this->exposure() ) );
	}

	public function test_flags_absent_callback_as_critical_high() {
		$rule = new Missing_Permission();

		$descriptor = $this->descriptor(
			[
				'class_name'          => 'My_Custom_Ability',
				'permission_callback' => [ 'type' => 'none' ],
			]
		);

		$finding = $this->assertSingleFinding( 'ASA001', $rule->evaluate( $descriptor, $this->exposure() ) );
		$this->assertSame( Finding::SEVERITY_CRITICAL, $finding->severity );
		$this->assertSame( Finding::CONFIDENCE_HIGH, $finding->confidence );
		$this->assertStringContainsString( 'My_Custom_Ability', $finding->message );
	}

	public function test_stays_silent_when_callbacks_unreadable() {
		$rule = new Missing_Permission();

		// Origin 'unavailable' means we do not KNOW the callback is missing —
		// the engine's ASA000 covers the uncertainty; no false alarm here.
		$descriptor = $this->descriptor(
			[
				'callback_origin'     => 'unavailable',
				'permission_callback' => [ 'type' => 'none' ],
			]
		);

		$this->assertSame( [], $rule->evaluate( $descriptor, $this->exposure() ) );
	}

	public function test_missing_type_key_is_not_flagged() {
		$rule = new Missing_Permission();

		$descriptor = $this->descriptor( [ 'permission_callback' => [] ] );

		$this->assertSame( [], $rule->evaluate( $descriptor, $this->exposure() ) );
	}
}
