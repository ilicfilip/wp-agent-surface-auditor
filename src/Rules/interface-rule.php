<?php
/**
 * Contract for one audit rule.
 *
 * @package ASA
 */

namespace ASA\Rules;

use ASA\Model\Ability_Descriptor;
use ASA\Model\Exposure;

defined( 'ABSPATH' ) || exit;

/**
 * One independently-testable check from the catalog.
 *
 * Rules are pure: they read the descriptor and exposure, never global state,
 * and never execute anything. A rule that detects nothing returns an empty
 * array — it must not emit "looks safe" findings.
 */
interface Rule {

	/**
	 * The catalog ID of this rule, e.g. 'ASA004'.
	 *
	 * @return string The rule ID.
	 */
	public function id();

	/**
	 * Evaluate one ability.
	 *
	 * @param Ability_Descriptor   $descriptor     The ability under audit.
	 * @param Exposure             $exposure       Its resolved exposure.
	 * @param \ASA\Model\Finding[] $prior_findings Findings already produced for
	 *                                             this ability by earlier rules
	 *                                             in the engine's order. Lets
	 *                                             composite rules (ASA005) build
	 *                                             on the permission smells
	 *                                             without re-detecting them.
	 * @return \ASA\Model\Finding[] Zero or more findings.
	 */
	public function evaluate( Ability_Descriptor $descriptor, Exposure $exposure, array $prior_findings = [] );
}
