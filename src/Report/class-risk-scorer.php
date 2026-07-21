<?php
/**
 * Rolls findings into a per-ability risk level.
 *
 * @package ASA
 */

namespace ASA\Report;

use ASA\Model\Finding;

defined( 'ABSPATH' ) || exit;

/**
 * Maps a set of findings to one risk level for the ability.
 *
 * Deliberately simple and explainable: the risk is the severity of the worst
 * finding ('none' when there are none). No weighted scores — a security
 * report the owner can't reconstruct in their head breeds mistrust.
 */
class Risk_Scorer {

	/**
	 * Risk level used when an ability has no findings.
	 */
	const RISK_NONE = 'none';

	/**
	 * Score one ability's findings.
	 *
	 * @param Finding[] $findings The ability's findings.
	 * @return string One of Finding::SEVERITY_ORDER, or 'none'.
	 */
	public function score( array $findings ) {
		$worst_rank = count( Finding::SEVERITY_ORDER );
		$worst      = self::RISK_NONE;

		foreach ( $findings as $finding ) {
			if ( ! $finding instanceof Finding ) {
				continue;
			}

			$rank = array_search( $finding->severity, Finding::SEVERITY_ORDER, true );
			if ( $rank !== false && $rank < $worst_rank ) {
				$worst_rank = $rank;
				$worst      = $finding->severity;
			}
		}

		return $worst;
	}
}
