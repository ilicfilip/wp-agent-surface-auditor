<?php
/**
 * ASA009: No annotations declared.
 *
 * @package ASA
 */

namespace ASA\Rules;

use ASA\Model\Ability_Descriptor;
use ASA\Model\Exposure;
use ASA\Model\Finding;

defined( 'ABSPATH' ) || exit;

/**
 * Flags abilities whose read/write intent is undeclarable because neither
 * `readonly` nor `destructive` was annotated (core defaults both to null).
 *
 * Low severity but structurally important: without annotations, MCP clients
 * receive no readOnlyHint/destructiveHint, and every downstream judgment —
 * including this auditor's ASA004 — has to fall back to heuristics.
 */
class No_Annotations implements Rule {

	/**
	 * The catalog ID of this rule.
	 *
	 * @return string The rule ID.
	 */
	public function id() {
		return 'ASA009';
	}

	/**
	 * Evaluate one ability.
	 *
	 * @param Ability_Descriptor $descriptor     The ability under audit.
	 * @param Exposure           $exposure       Its resolved exposure.
	 * @param Finding[]          $prior_findings Findings from earlier rules.
	 * @return Finding[] Zero or one finding.
	 */
	public function evaluate( Ability_Descriptor $descriptor, Exposure $exposure, array $prior_findings = [] ) {
		if ( $descriptor->annotations['readonly'] !== null || $descriptor->annotations['destructive'] !== null ) {
			return [];
		}

		return [
			new Finding(
				$this->id(),
				Finding::SEVERITY_LOW,
				Finding::CONFIDENCE_HIGH,
				'This ability declares no readonly/destructive annotations — its read/write intent is unknown '
					. 'to agents, MCP clients, and this audit alike.',
				'Declare meta.annotations.readonly and meta.annotations.destructive so tooling can reason '
					. 'about what this ability does to the site.'
			),
		];
	}
}
