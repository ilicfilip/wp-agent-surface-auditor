<?php
/**
 * ASA009 No_Annotations rule tests.
 *
 * @package ASA
 */

use ASA\Model\Finding;
use ASA\Rules\No_Annotations;

/**
 * @covers \ASA\Rules\No_Annotations
 */
class No_Annotations_Test extends ASA_TestCase {

	public function test_silent_when_annotations_declared() {
		$rule = new No_Annotations();

		$this->assertSame( [], $rule->evaluate( $this->descriptor(), $this->exposure() ) );
	}

	public function test_flags_fully_undeclared_intent() {
		$rule = new No_Annotations();

		$descriptor = $this->descriptor(
			[ 'annotations' => [ 'readonly' => null, 'destructive' => null, 'idempotent' => null ] ]
		);

		$finding = $this->assertSingleFinding( 'ASA009', $rule->evaluate( $descriptor, $this->exposure() ) );
		$this->assertSame( Finding::SEVERITY_LOW, $finding->severity );
		$this->assertSame( Finding::CONFIDENCE_HIGH, $finding->confidence );
	}

	public function test_partial_declaration_counts_as_declared() {
		$rule = new No_Annotations();

		$descriptor = $this->descriptor(
			[ 'annotations' => [ 'readonly' => null, 'destructive' => false, 'idempotent' => null ] ]
		);

		$this->assertSame( [], $rule->evaluate( $descriptor, $this->exposure() ) );
	}

	public function test_idempotent_alone_does_not_declare_intent() {
		$rule = new No_Annotations();

		$descriptor = $this->descriptor(
			[ 'annotations' => [ 'readonly' => null, 'destructive' => null, 'idempotent' => true ] ]
		);

		$this->assertSingleFinding( 'ASA009', $rule->evaluate( $descriptor, $this->exposure() ) );
	}

	public function test_fires_even_when_unexposed() {
		$rule = new No_Annotations();

		$descriptor = $this->descriptor(
			[ 'annotations' => [ 'readonly' => null, 'destructive' => null, 'idempotent' => null ] ]
		);

		// Hygiene finding: intent should be declared before exposure ever happens.
		$this->assertSingleFinding( 'ASA009', $rule->evaluate( $descriptor, $this->exposure() ) );
	}
}
