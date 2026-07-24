<?php
/**
 * ASA006 Annotation_Mismatch rule tests.
 *
 * @package ASA
 */

use ASA\Model\Finding;
use ASA\Rules\Annotation_Mismatch;

/**
 * @covers \ASA\Rules\Annotation_Mismatch
 */
class Annotation_Mismatch_Test extends ASA_TestCase {

	private function writing_execute_analysis( $confirmed = false ) {
		return [
			'resolved'                  => true,
			'returns_only_literal_true' => null,
			'calls_current_user_can'    => false,
			'calls_is_user_logged_in'   => false,
			'capability_checks'         => [],
			'write_indicators'          => [ 'wp_insert_post()', 'update_option()' ],
			'has_confirmed_write'       => $confirmed,
		];
	}

	public function test_silent_when_claim_matches_clean_source() {
		$rule = new Annotation_Mismatch();

		$this->assertSame( [], $rule->evaluate( $this->descriptor(), $this->exposure() ) );
	}

	public function test_readonly_claim_with_write_indicators_fires_high_low() {
		$rule = new Annotation_Mismatch();

		$descriptor = $this->descriptor( [ 'execute_analysis' => $this->writing_execute_analysis() ] );

		$finding = $this->assertSingleFinding( 'ASA006', $rule->evaluate( $descriptor, $this->exposure() ) );
		$this->assertSame( Finding::SEVERITY_HIGH, $finding->severity );
		$this->assertSame( Finding::CONFIDENCE_LOW, $finding->confidence );
		$this->assertStringContainsString( 'readonly: true', $finding->message );
		$this->assertStringContainsString( 'wp_insert_post()', $finding->message );
		$this->assertStringContainsString( 'verify', $finding->message );
		// The readonly-claim harm story names the GET verb.
		$this->assertStringContainsString( 'GET', $finding->message );
	}

	public function test_confirmed_write_raises_confidence_to_medium() {
		$rule = new Annotation_Mismatch();

		$descriptor = $this->descriptor( [ 'execute_analysis' => $this->writing_execute_analysis( true ) ] );

		$finding = $this->assertSingleFinding( 'ASA006', $rule->evaluate( $descriptor, $this->exposure() ) );
		$this->assertSame( Finding::SEVERITY_HIGH, $finding->severity );
		$this->assertSame( Finding::CONFIDENCE_MEDIUM, $finding->confidence );
		$this->assertStringContainsString( 'the implementation performs a write', $finding->message );
	}

	public function test_destructive_false_claim_also_fires() {
		$rule = new Annotation_Mismatch();

		$descriptor = $this->descriptor(
			[
				'annotations'      => [ 'readonly' => null, 'destructive' => false, 'idempotent' => null ],
				'execute_analysis' => $this->writing_execute_analysis(),
			]
		);

		$finding = $this->assertSingleFinding( 'ASA006', $rule->evaluate( $descriptor, $this->exposure() ) );
		$this->assertStringContainsString( 'destructive: false', $finding->message );
		// destructive:false still POSTs — the GET harm note is readonly-only.
		$this->assertStringNotContainsString( 'over GET', $finding->message );
	}

	public function test_no_claim_means_no_mismatch() {
		$rule = new Annotation_Mismatch();

		// Undeclared intent is ASA009 / the ASA004 heuristic — not a mismatch.
		$descriptor = $this->descriptor(
			[
				'annotations'      => [ 'readonly' => null, 'destructive' => null, 'idempotent' => null ],
				'execute_analysis' => $this->writing_execute_analysis(),
			]
		);

		$this->assertSame( [], $rule->evaluate( $descriptor, $this->exposure() ) );
	}

	public function test_declared_writer_that_writes_is_consistent() {
		$rule = new Annotation_Mismatch();

		$descriptor = $this->descriptor(
			[
				'annotations'      => [ 'readonly' => false, 'destructive' => true, 'idempotent' => null ],
				'execute_analysis' => $this->writing_execute_analysis(),
			]
		);

		$this->assertSame( [], $rule->evaluate( $descriptor, $this->exposure() ) );
	}

	public function test_silent_when_execute_source_unresolved() {
		$rule = new Annotation_Mismatch();

		$descriptor = $this->descriptor( [ 'execute_analysis' => [ 'resolved' => false ] ] );

		$this->assertSame( [], $rule->evaluate( $descriptor, $this->exposure() ) );
	}
}
