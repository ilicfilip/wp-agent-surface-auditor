<?php
/**
 * ASA004 Exposed_Write rule tests.
 *
 * @package ASA
 */

use ASA\Model\Finding;
use ASA\Rules\Exposed_Write;

/**
 * @covers \ASA\Rules\Exposed_Write
 */
class Exposed_Write_Test extends ASA_TestCase {

	public function test_silent_when_not_reachable() {
		$rule = new Exposed_Write();

		$descriptor = $this->descriptor(
			[ 'annotations' => [ 'readonly' => false, 'destructive' => true, 'idempotent' => null ] ]
		);

		$this->assertSame( [], $rule->evaluate( $descriptor, $this->exposure() ) );
	}

	public function test_silent_for_reachable_readonly_ability() {
		$rule = new Exposed_Write();

		$this->assertSame(
			[],
			$rule->evaluate( $this->descriptor(), $this->exposure( [ 'rest' => true ] ) )
		);
	}

	public function test_flags_reachable_destructive_ability() {
		$rule = new Exposed_Write();

		$descriptor = $this->descriptor(
			[ 'annotations' => [ 'readonly' => null, 'destructive' => true, 'idempotent' => null ] ]
		);
		$exposure   = $this->exposure( [ 'rest' => true ] );

		$finding = $this->assertSingleFinding( 'ASA004', $rule->evaluate( $descriptor, $exposure ) );
		$this->assertSame( Finding::SEVERITY_HIGH, $finding->severity );
		$this->assertSame( Finding::CONFIDENCE_HIGH, $finding->confidence );
		$this->assertStringContainsString( 'destructive', $finding->message );
		$this->assertStringContainsString( 'wp-abilities/v1', $finding->message );
	}

	public function test_flags_readonly_false_via_default_mcp_server() {
		$rule = new Exposed_Write();

		$descriptor = $this->descriptor(
			[ 'annotations' => [ 'readonly' => false, 'destructive' => null, 'idempotent' => null ] ]
		);
		$exposure   = $this->exposure(
			[
				'mcp_public'         => true,
				'mcp_default_server' => true,
				'adapter_active'     => true,
			]
		);

		$finding = $this->assertSingleFinding( 'ASA004', $rule->evaluate( $descriptor, $exposure ) );
		$this->assertStringContainsString( 'default MCP server', $finding->message );
	}

	public function test_intended_only_exposure_is_still_flagged_with_qualifier() {
		$rule = new Exposed_Write();

		$descriptor = $this->descriptor(
			[ 'annotations' => [ 'readonly' => null, 'destructive' => true, 'idempotent' => null ] ]
		);
		$exposure   = $this->exposure(
			[
				'mcp_public'        => true,
				'mcp_intended_only' => true,
			]
		);

		$finding = $this->assertSingleFinding( 'ASA004', $rule->evaluate( $descriptor, $exposure ) );
		$this->assertStringContainsString( 'intended, not yet live', $finding->message );
	}

	public function test_null_annotations_without_write_evidence_stay_silent() {
		$rule = new Exposed_Write();

		$descriptor = $this->descriptor(
			[ 'annotations' => [ 'readonly' => null, 'destructive' => null, 'idempotent' => null ] ]
		);

		// Undeclared intent alone is ASA009's job; without write indicators
		// there is no evidence of writes to report here.
		$this->assertSame( [], $rule->evaluate( $descriptor, $this->exposure( [ 'rest' => true ] ) ) );
	}

	public function test_null_annotations_with_write_indicators_fire_medium_confidence() {
		$rule = new Exposed_Write();

		$descriptor = $this->descriptor(
			[
				'annotations'      => [ 'readonly' => null, 'destructive' => null, 'idempotent' => null ],
				'execute_analysis' => [
					'resolved'         => true,
					'write_indicators' => [ 'wp_delete_post()' ],
				],
			]
		);

		$finding = $this->assertSingleFinding(
			'ASA004',
			$rule->evaluate( $descriptor, $this->exposure( [ 'rest' => true ] ) )
		);
		$this->assertSame( Finding::CONFIDENCE_MEDIUM, $finding->confidence );
		$this->assertStringContainsString( 'wp_delete_post()', $finding->message );
	}

	public function test_declared_readonly_with_write_indicators_is_not_asa004() {
		$rule = new Exposed_Write();

		// A readonly:true claim contradicted by indicators is ASA006's
		// mismatch, not an exposed-write finding.
		$descriptor = $this->descriptor(
			[
				'execute_analysis' => [
					'resolved'         => true,
					'write_indicators' => [ 'update_option()' ],
				],
			]
		);

		$this->assertSame( [], $rule->evaluate( $descriptor, $this->exposure( [ 'rest' => true ] ) ) );
	}
}
