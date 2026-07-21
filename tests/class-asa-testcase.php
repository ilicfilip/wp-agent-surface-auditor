<?php
/**
 * Shared base TestCase with descriptor/exposure factories.
 *
 * @package ASA
 */

use ASA\Model\Ability_Descriptor;
use ASA\Model\Exposure;
use ASA\Model\Finding;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Base test case: factories for synthetic descriptors and exposures so each
 * rule test states only the fields it cares about.
 */
abstract class ASA_TestCase extends TestCase {

	/**
	 * Build a descriptor with sane safe defaults, overridden per test.
	 *
	 * Defaults model a well-behaved ability: read-only annotations, a
	 * capability-checking named permission callback, constrained schema.
	 *
	 * @param array<string, mixed> $overrides Property overrides.
	 * @return Ability_Descriptor The descriptor.
	 */
	protected function descriptor( array $overrides = [] ) {
		$descriptor = new Ability_Descriptor();

		$descriptor->name            = 'test/ability';
		$descriptor->label           = 'Test Ability';
		$descriptor->description     = 'A test ability.';
		$descriptor->category        = 'testing';
		$descriptor->class_name      = 'WP_Ability';
		$descriptor->callback_origin = 'filter';
		$descriptor->annotations     = [
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		];
		$descriptor->input_schema    = [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'mode' => [
					'type' => 'string',
					'enum' => [ 'a', 'b' ],
				],
			],
		];

		$descriptor->permission_callback = [
			'type'             => 'function',
			'name'             => 'test_permission_gate',
			'file'             => '/tmp/test.php',
			'line_start'       => 1,
			'line_end'         => 3,
			'source_available' => true,
		];
		$descriptor->execute_callback    = $descriptor->permission_callback;

		// Safe analysis defaults: a resolved, capability-checking gate and a
		// non-writing implementation. Tests override what they probe.
		$descriptor->permission_analysis = [
			'resolved'                  => true,
			'returns_only_literal_true' => false,
			'calls_current_user_can'    => true,
			'calls_is_user_logged_in'   => false,
			'capability_checks'         => [ 'manage_options' ],
			'write_indicators'          => [],
		];
		$descriptor->execute_analysis    = [
			'resolved'                  => true,
			'returns_only_literal_true' => null,
			'calls_current_user_can'    => false,
			'calls_is_user_logged_in'   => false,
			'capability_checks'         => [],
			'write_indicators'          => [],
		];

		foreach ( $overrides as $property => $value ) {
			$descriptor->{$property} = $value;
		}

		return $descriptor;
	}

	/**
	 * Build an exposure, overridden per test. Defaults to unexposed.
	 *
	 * @param array<string, mixed> $overrides Property overrides.
	 * @return Exposure The exposure.
	 */
	protected function exposure( array $overrides = [] ) {
		$exposure = new Exposure();

		foreach ( $overrides as $property => $value ) {
			$exposure->{$property} = $value;
		}

		return $exposure;
	}

	/**
	 * Build a finding quickly.
	 *
	 * @param string $rule_id    Rule ID.
	 * @param string $severity   Severity.
	 * @param string $confidence Confidence.
	 * @return Finding The finding.
	 */
	protected function finding( $rule_id, $severity = Finding::SEVERITY_HIGH, $confidence = Finding::CONFIDENCE_HIGH ) {
		return new Finding( $rule_id, $severity, $confidence, 'msg', 'fix' );
	}

	/**
	 * Assert exactly one finding with the given rule id.
	 *
	 * @param string $rule_id  Expected rule ID.
	 * @param array  $findings Findings returned by a rule.
	 * @return Finding The single finding, for further assertions.
	 */
	protected function assertSingleFinding( $rule_id, array $findings ) {
		$this->assertCount( 1, $findings );
		$this->assertInstanceOf( Finding::class, $findings[0] );
		$this->assertSame( $rule_id, $findings[0]->rule_id );

		return $findings[0];
	}
}
