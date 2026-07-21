<?php
/**
 * ASA007 Loose_Input_Schema rule tests.
 *
 * @package ASA
 */

use ASA\Model\Finding;
use ASA\Rules\Loose_Input_Schema;

/**
 * @covers \ASA\Rules\Loose_Input_Schema
 */
class Loose_Input_Schema_Test extends ASA_TestCase {

	public function test_silent_when_not_reachable() {
		$rule = new Loose_Input_Schema();

		$descriptor = $this->descriptor( [ 'input_schema' => [ 'type' => 'string' ] ] );

		$this->assertSame( [], $rule->evaluate( $descriptor, $this->exposure() ) );
	}

	public function test_absent_schema_is_not_flagged() {
		$rule = new Loose_Input_Schema();

		// Core forwards no input when no schema is declared.
		$descriptor = $this->descriptor( [ 'input_schema' => [] ] );

		$this->assertSame( [], $rule->evaluate( $descriptor, $this->exposure( [ 'rest' => true ] ) ) );
	}

	public function test_tight_schema_passes() {
		$rule = new Loose_Input_Schema();

		$this->assertSame(
			[],
			$rule->evaluate( $this->descriptor(), $this->exposure( [ 'rest' => true ] ) )
		);
	}

	public function test_flags_explicit_additional_properties_true() {
		$rule = new Loose_Input_Schema();

		$descriptor = $this->descriptor(
			[
				'input_schema' => [
					'type'                 => 'object',
					'additionalProperties' => true,
					'properties'           => [
						'id' => [ 'type' => 'integer', 'minimum' => 1 ],
					],
				],
			]
		);

		$finding = $this->assertSingleFinding(
			'ASA007',
			$rule->evaluate( $descriptor, $this->exposure( [ 'rest' => true ] ) )
		);
		$this->assertSame( Finding::SEVERITY_MEDIUM, $finding->severity );
		$this->assertStringContainsString( 'explicitly allows additionalProperties', $finding->message );
	}

	public function test_flags_unset_additional_properties_on_object() {
		$rule = new Loose_Input_Schema();

		$descriptor = $this->descriptor(
			[
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'id' => [ 'type' => 'integer', 'minimum' => 1 ],
					],
				],
			]
		);

		$finding = $this->assertSingleFinding(
			'ASA007',
			$rule->evaluate( $descriptor, $this->exposure( [ 'rest' => true ] ) )
		);
		$this->assertStringContainsString( 'does not set additionalProperties: false', $finding->message );
	}

	public function test_flags_unconstrained_scalars_including_nested() {
		$rule = new Loose_Input_Schema();

		$descriptor = $this->descriptor(
			[
				'input_schema' => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => [
						'query' => [ 'type' => 'string' ],
						'limit' => [ 'type' => 'integer' ],
						'tags'  => [
							'type'  => 'array',
							'items' => [ 'type' => 'string' ],
						],
					],
				],
			]
		);

		$finding = $this->assertSingleFinding(
			'ASA007',
			$rule->evaluate( $descriptor, $this->exposure( [ 'rest' => true ] ) )
		);
		$this->assertStringContainsString( 'query is an unconstrained string', $finding->message );
		$this->assertStringContainsString( 'limit is an unbounded integer', $finding->message );
		$this->assertStringContainsString( 'tags[] is an unconstrained string', $finding->message );
	}

	public function test_message_caps_listed_issues() {
		$rule = new Loose_Input_Schema();

		$properties = [];
		for ( $i = 1; $i <= 8; $i++ ) {
			$properties[ 'p' . $i ] = [ 'type' => 'string' ];
		}

		$descriptor = $this->descriptor(
			[
				'input_schema' => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => $properties,
				],
			]
		);

		$finding = $this->assertSingleFinding(
			'ASA007',
			$rule->evaluate( $descriptor, $this->exposure( [ 'rest' => true ] ) )
		);
		$this->assertStringContainsString( 'and 3 more', $finding->message );
	}

	public function test_constrained_scalar_root_schema_passes() {
		$rule = new Loose_Input_Schema();

		$descriptor = $this->descriptor(
			[
				'input_schema' => [
					'type' => 'string',
					'enum' => [ 'on', 'off' ],
				],
			]
		);

		$this->assertSame( [], $rule->evaluate( $descriptor, $this->exposure( [ 'rest' => true ] ) ) );
	}
}
