<?php
/**
 * Report_Diff tests: baseline-vs-current deltas and the gate helpers.
 *
 * @package ASA
 */

use ASA\Report\Report_Diff;

/**
 * @covers \ASA\Report\Report_Diff
 */
class Report_Diff_Test extends ASA_TestCase {

	/**
	 * Build a minimal report array with the given abilities.
	 *
	 * @param array[] $abilities Ability entries (descriptor/findings/risk).
	 * @return array<string, mixed> A report-shaped array.
	 */
	private function report( array $abilities ) {
		return [ 'abilities' => $abilities ];
	}

	/**
	 * One ability entry with a name, risk, and findings.
	 *
	 * @param string  $name     Ability name.
	 * @param string  $risk     Risk level.
	 * @param array[] $findings Finding arrays.
	 * @return array<string, mixed> The ability entry.
	 */
	private function ability( $name, $risk, array $findings = [] ) {
		return [
			'descriptor' => [ 'name' => $name ],
			'risk'       => $risk,
			'findings'   => $findings,
		];
	}

	/**
	 * One finding array.
	 *
	 * @param string $rule_id  Rule ID.
	 * @param string $severity Severity.
	 * @return array<string, string> The finding.
	 */
	private function fnd( $rule_id, $severity ) {
		return [
			'rule_id'    => $rule_id,
			'severity'   => $severity,
			'confidence' => 'high',
			'message'    => $rule_id . ' fired',
		];
	}

	public function test_identical_reports_have_empty_delta() {
		$report = $this->report( [ $this->ability( 'a/one', 'high', [ $this->fnd( 'ASA005', 'critical' ) ] ) ] );

		$delta = ( new Report_Diff() )->diff( $report, $report );

		$this->assertSame( 0, $delta['summary']['new_findings'] );
		$this->assertSame( 0, $delta['summary']['resolved_findings'] );
		$this->assertSame( 0, $delta['summary']['changed_risk'] );
		$this->assertSame( [], $delta['new_abilities'] );
		$this->assertSame( [], $delta['removed_abilities'] );
	}

	public function test_new_finding_is_reported() {
		$baseline = $this->report( [ $this->ability( 'a/one', 'none' ) ] );
		$current  = $this->report( [ $this->ability( 'a/one', 'critical', [ $this->fnd( 'ASA005', 'critical' ) ] ) ] );

		$delta = ( new Report_Diff() )->diff( $baseline, $current );

		$this->assertSame( 1, $delta['summary']['new_findings'] );
		$this->assertSame( 'a/one', $delta['new_findings'][0]['ability'] );
		$this->assertSame( 'ASA005', $delta['new_findings'][0]['rule_id'] );
		$this->assertCount( 1, $delta['changed_risk'] );
		$this->assertSame( 'none', $delta['changed_risk'][0]['from'] );
		$this->assertSame( 'critical', $delta['changed_risk'][0]['to'] );
	}

	public function test_resolved_finding_is_reported() {
		$baseline = $this->report( [ $this->ability( 'a/one', 'high', [ $this->fnd( 'ASA003', 'high' ) ] ) ] );
		$current  = $this->report( [ $this->ability( 'a/one', 'none' ) ] );

		$delta = ( new Report_Diff() )->diff( $baseline, $current );

		$this->assertSame( 1, $delta['summary']['resolved_findings'] );
		$this->assertSame( 'ASA003', $delta['resolved_findings'][0]['rule_id'] );
		$this->assertSame( 0, $delta['summary']['new_findings'] );
	}

	public function test_added_and_removed_abilities() {
		$baseline = $this->report( [ $this->ability( 'a/gone', 'none' ) ] );
		$current  = $this->report( [ $this->ability( 'a/fresh', 'none' ) ] );

		$delta = ( new Report_Diff() )->diff( $baseline, $current );

		$this->assertSame( [ 'a/fresh' ], $delta['new_abilities'] );
		$this->assertSame( [ 'a/gone' ], $delta['removed_abilities'] );
	}

	public function test_new_findings_sorted_worst_first() {
		$baseline = $this->report( [ $this->ability( 'a/one', 'none' ) ] );
		$current  = $this->report(
			[
				$this->ability(
					'a/one',
					'critical',
					[ $this->fnd( 'ASA009', 'low' ), $this->fnd( 'ASA005', 'critical' ) ]
				),
			]
		);

		$delta = ( new Report_Diff() )->diff( $baseline, $current );

		$this->assertSame( 'critical', $delta['new_findings'][0]['severity'] );
		$this->assertSame( 'low', $delta['new_findings'][1]['severity'] );
	}

	public function test_meets_threshold_is_severity_ordered() {
		$differ = new Report_Diff();

		$this->assertTrue( $differ->meets_threshold( 'critical', 'high' ) );
		$this->assertTrue( $differ->meets_threshold( 'high', 'high' ) );
		$this->assertFalse( $differ->meets_threshold( 'medium', 'high' ) );
		$this->assertFalse( $differ->meets_threshold( 'nonsense', 'high' ) );
	}

	public function test_worst_severity_picks_the_top() {
		$differ = new Report_Diff();

		$this->assertSame(
			'high',
			$differ->worst_severity( [ $this->fnd( 'ASA009', 'low' ), $this->fnd( 'ASA004', 'high' ) ] )
		);
		$this->assertNull( $differ->worst_severity( [] ) );
	}

	public function test_malformed_entries_are_skipped() {
		$baseline = $this->report( [ [ 'no_descriptor' => true ], $this->ability( 'a/one', 'none' ) ] );
		$current  = $this->report( [ $this->ability( 'a/one', 'none' ) ] );

		$delta = ( new Report_Diff() )->diff( $baseline, $current );

		$this->assertSame( [], $delta['new_abilities'] );
		$this->assertSame( [], $delta['removed_abilities'] );
	}
}
