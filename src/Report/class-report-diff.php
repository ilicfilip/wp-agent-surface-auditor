<?php
/**
 * Diff two audit reports: what changed between a baseline and now.
 *
 * @package ASA
 */

namespace ASA\Report;

use ASA\Model\Finding;

defined( 'ABSPATH' ) || exit;

/**
 * Compares a previously-exported baseline report against a current one and
 * describes the delta: findings that appeared, findings that cleared,
 * abilities whose risk level moved, and abilities added or removed from the
 * surface.
 *
 * Pure and stateless — it reads two report arrays (the JSON shape produced by
 * Audit_Runner) and returns plain data. It never touches the registry, the
 * database, or the filesystem, so it is safe to unit-test without WordPress.
 *
 * A finding's identity for diffing is (ability name + rule id). Two runs that
 * both flag `foo/bar` with ASA005 are "the same finding" even if the wording
 * shifted; a rule that stops firing is "resolved"; one that starts is "new".
 */
class Report_Diff {

	/**
	 * Compute the delta from a baseline report to a current report.
	 *
	 * @param array<string, mixed> $baseline A report array (from a prior export).
	 * @param array<string, mixed> $current  The current report array.
	 * @return array<string, mixed> {
	 *     The delta.
	 *
	 *     @type array[] $new_findings      Findings present now, absent in baseline.
	 *     @type array[] $resolved_findings Findings present in baseline, gone now.
	 *     @type array[] $changed_risk      Abilities whose risk level moved.
	 *     @type string[] $new_abilities    Ability names added to the surface.
	 *     @type string[] $removed_abilities Ability names no longer present.
	 *     @type array<string, int> $summary Counts of each of the above.
	 * }
	 */
	public function diff( array $baseline, array $current ) {
		$base_findings = $this->index_findings( $baseline );
		$curr_findings = $this->index_findings( $current );

		$new_findings      = [];
		$resolved_findings = [];

		foreach ( $curr_findings as $key => $entry ) {
			if ( ! isset( $base_findings[ $key ] ) ) {
				$new_findings[] = $entry;
			}
		}
		foreach ( $base_findings as $key => $entry ) {
			if ( ! isset( $curr_findings[ $key ] ) ) {
				$resolved_findings[] = $entry;
			}
		}

		$base_risk = $this->index_risk( $baseline );
		$curr_risk = $this->index_risk( $current );

		$changed_risk = [];
		foreach ( $curr_risk as $name => $risk ) {
			if ( isset( $base_risk[ $name ] ) && $base_risk[ $name ] !== $risk ) {
				$changed_risk[] = [
					'ability' => $name,
					'from'    => $base_risk[ $name ],
					'to'      => $risk,
				];
			}
		}

		$new_abilities     = array_values( array_diff( array_keys( $curr_risk ), array_keys( $base_risk ) ) );
		$removed_abilities = array_values( array_diff( array_keys( $base_risk ), array_keys( $curr_risk ) ) );

		sort( $new_abilities );
		sort( $removed_abilities );

		return [
			'new_findings'      => $this->sort_findings( $new_findings ),
			'resolved_findings' => $this->sort_findings( $resolved_findings ),
			'changed_risk'      => $changed_risk,
			'new_abilities'     => $new_abilities,
			'removed_abilities' => $removed_abilities,
			'summary'           => [
				'new_findings'      => count( $new_findings ),
				'resolved_findings' => count( $resolved_findings ),
				'changed_risk'      => count( $changed_risk ),
				'new_abilities'     => count( $new_abilities ),
				'removed_abilities' => count( $removed_abilities ),
			],
		];
	}

	/**
	 * The most severe severity among a set of findings, or null when empty.
	 *
	 * Used by the CLI gate: "does this delta contain a new finding at or above
	 * the threshold?" is answered by comparing against SEVERITY_ORDER.
	 *
	 * @param array[] $findings Finding entries (each with a 'severity').
	 * @return string|null The worst severity present, or null.
	 */
	public function worst_severity( array $findings ) {
		foreach ( Finding::SEVERITY_ORDER as $severity ) {
			foreach ( $findings as $finding ) {
				if ( isset( $finding['severity'] ) && $finding['severity'] === $severity ) {
					return $severity;
				}
			}
		}
		return null;
	}

	/**
	 * Whether $severity is at or above $threshold in the severity order.
	 *
	 * @param string $severity  A severity constant.
	 * @param string $threshold The gate threshold.
	 * @return bool True when $severity is at least as severe as $threshold.
	 */
	public function meets_threshold( $severity, $threshold ) {
		$rank      = array_search( $severity, Finding::SEVERITY_ORDER, true );
		$threshold = array_search( $threshold, Finding::SEVERITY_ORDER, true );

		// Lower index = more severe. An unknown severity never meets the gate.
		return $rank !== false && $threshold !== false && $rank <= $threshold;
	}

	/**
	 * Index a report's findings by "ability|rule_id".
	 *
	 * @param array<string, mixed> $report A report array.
	 * @return array<string, array<string, mixed>> Keyed findings.
	 */
	private function index_findings( array $report ) {
		$index     = [];
		$abilities = isset( $report['abilities'] ) && is_array( $report['abilities'] ) ? $report['abilities'] : [];

		foreach ( $abilities as $ability ) {
			$name = $this->ability_name( $ability );
			if ( $name === null ) {
				continue;
			}

			$findings = isset( $ability['findings'] ) && is_array( $ability['findings'] ) ? $ability['findings'] : [];
			foreach ( $findings as $finding ) {
				$rule_id = isset( $finding['rule_id'] ) && is_string( $finding['rule_id'] ) ? $finding['rule_id'] : null;
				if ( $rule_id === null ) {
					continue;
				}

				$index[ $name . '|' . $rule_id ] = [
					'ability'    => $name,
					'rule_id'    => $rule_id,
					'severity'   => isset( $finding['severity'] ) ? $finding['severity'] : '',
					'confidence' => isset( $finding['confidence'] ) ? $finding['confidence'] : '',
					'message'    => isset( $finding['message'] ) ? $finding['message'] : '',
				];
			}
		}

		return $index;
	}

	/**
	 * Index a report's per-ability risk levels by ability name.
	 *
	 * @param array<string, mixed> $report A report array.
	 * @return array<string, string> name => risk level.
	 */
	private function index_risk( array $report ) {
		$index     = [];
		$abilities = isset( $report['abilities'] ) && is_array( $report['abilities'] ) ? $report['abilities'] : [];

		foreach ( $abilities as $ability ) {
			$name = $this->ability_name( $ability );
			if ( $name !== null ) {
				$index[ $name ] = isset( $ability['risk'] ) && is_string( $ability['risk'] ) ? $ability['risk'] : '';
			}
		}

		return $index;
	}

	/**
	 * Pull the ability name out of a report ability entry.
	 *
	 * @param mixed $ability One entry from report['abilities'].
	 * @return string|null The name, or null when malformed.
	 */
	private function ability_name( $ability ) {
		if ( is_array( $ability )
			&& isset( $ability['descriptor']['name'] )
			&& is_string( $ability['descriptor']['name'] ) ) {
			return $ability['descriptor']['name'];
		}
		return null;
	}

	/**
	 * Order findings by severity (worst first), then ability, then rule.
	 *
	 * @param array[] $findings Finding entries.
	 * @return array[] The sorted list.
	 */
	private function sort_findings( array $findings ) {
		usort(
			$findings,
			static function ( $a, $b ) {
				$rank_a = array_search( $a['severity'], Finding::SEVERITY_ORDER, true );
				$rank_b = array_search( $b['severity'], Finding::SEVERITY_ORDER, true );
				$rank_a = $rank_a === false ? PHP_INT_MAX : $rank_a;
				$rank_b = $rank_b === false ? PHP_INT_MAX : $rank_b;

				if ( $rank_a !== $rank_b ) {
					return $rank_a <=> $rank_b;
				}
				return ( $a['ability'] . $a['rule_id'] ) <=> ( $b['ability'] . $b['rule_id'] );
			}
		);

		return $findings;
	}
}
