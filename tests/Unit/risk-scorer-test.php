<?php
/**
 * Risk_Scorer tests.
 *
 * @package ASA
 */

use ASA\Model\Finding;
use ASA\Report\Risk_Scorer;

/**
 * @covers \ASA\Report\Risk_Scorer
 */
class Risk_Scorer_Test extends ASA_TestCase {

	public function test_no_findings_scores_none() {
		$scorer = new Risk_Scorer();

		$this->assertSame( Risk_Scorer::RISK_NONE, $scorer->score( [] ) );
	}

	public function test_worst_severity_wins() {
		$scorer = new Risk_Scorer();

		$risk = $scorer->score(
			[
				$this->finding( 'ASA009', Finding::SEVERITY_LOW ),
				$this->finding( 'ASA005', Finding::SEVERITY_CRITICAL ),
				$this->finding( 'ASA004', Finding::SEVERITY_HIGH ),
			]
		);

		$this->assertSame( Finding::SEVERITY_CRITICAL, $risk );
	}

	public function test_info_only_scores_info() {
		$scorer = new Risk_Scorer();

		$this->assertSame(
			Finding::SEVERITY_INFO,
			$scorer->score( [ $this->finding( 'ASA010', Finding::SEVERITY_INFO ) ] )
		);
	}

	public function test_unknown_severity_is_ignored() {
		$scorer = new Risk_Scorer();

		$risk = $scorer->score(
			[
				$this->finding( 'ASA00X', 'bogus' ),
				$this->finding( 'ASA009', Finding::SEVERITY_LOW ),
			]
		);

		$this->assertSame( Finding::SEVERITY_LOW, $risk );
	}
}
