<?php
/**
 * ASA005: Agent-reachable and weakly/missing permission (composite).
 *
 * @package ASA
 */

namespace ASA\Rules;

use ASA\Model\Ability_Descriptor;
use ASA\Model\Exposure;
use ASA\Model\Finding;

defined( 'ABSPATH' ) || exit;

/**
 * The headline composite: an ability agents can reach whose permission gate
 * already tripped a permission smell (ASA001 missing, ASA002 unconditional
 * allow, ASA003 auth-only). This is the combination that should dominate the
 * summary's critical count.
 *
 * Runs last in the engine order and consumes prior findings rather than
 * re-detecting the smells. Confidence is inherited from the weakest
 * contributing finding — a composite is never more certain than its parts.
 */
class Exposed_Weak_Permission implements Rule {

	/**
	 * The permission-smell rules this composite builds on.
	 *
	 * @var string[]
	 */
	const PERMISSION_SMELLS = [ 'ASA001', 'ASA002', 'ASA003' ];

	/**
	 * The catalog ID of this rule.
	 *
	 * @return string The rule ID.
	 */
	public function id() {
		return 'ASA005';
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
		if ( ! $exposure->is_agent_reachable() ) {
			return [];
		}

		$contributing = [];
		foreach ( $prior_findings as $finding ) {
			if ( in_array( $finding->rule_id, self::PERMISSION_SMELLS, true ) ) {
				$contributing[] = $finding;
			}
		}

		if ( $contributing === [] ) {
			return [];
		}

		$rule_ids   = [];
		$confidence = Finding::CONFIDENCE_HIGH;
		foreach ( $contributing as $finding ) {
			$rule_ids[] = $finding->rule_id;

			// Inherit the weakest contributing confidence.
			if ( $finding->confidence === Finding::CONFIDENCE_LOW ) {
				$confidence = Finding::CONFIDENCE_LOW;
			} elseif ( $finding->confidence === Finding::CONFIDENCE_MEDIUM && $confidence === Finding::CONFIDENCE_HIGH ) {
				$confidence = Finding::CONFIDENCE_MEDIUM;
			}
		}

		return [
			new Finding(
				$this->id(),
				Finding::SEVERITY_CRITICAL,
				$confidence,
				'This ability is reachable by agents AND its permission gate is weak or missing '
					. '(see ' . implode( ', ', array_unique( $rule_ids ) ) . '). '
					. 'Any agent that can authenticate — even as a low-privileged user — may be able to invoke it.',
				'Fix the permission gate first (require a specific capability), then re-evaluate whether the '
					. 'ability needs to be agent-reachable at all.'
			),
		];
	}
}
