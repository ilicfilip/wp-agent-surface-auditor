<?php
/**
 * ASA002: Unconditional-allow permission callback.
 *
 * @package ASA
 */

namespace ASA\Rules;

use ASA\Model\Ability_Descriptor;
use ASA\Model\Exposure;
use ASA\Model\Finding;

defined( 'ABSPATH' ) || exit;

/**
 * Flags permission callbacks that allow every caller.
 *
 * Two detection tiers:
 *  - `__return_true` resolved by name — confidence high;
 *  - a source span in which every return statement returns literal `true`
 *    (and no capability is consulted) — confidence medium, since the span
 *    heuristic can be fooled.
 *
 * When the callback exists but its source could not be analyzed at all, an
 * ASA000 info finding is emitted instead of a silent pass — never a false
 * pass.
 */
class Unconditional_Allow implements Rule {

	/**
	 * The catalog ID of this rule.
	 *
	 * @return string The rule ID.
	 */
	public function id() {
		return 'ASA002';
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
		if ( $descriptor->callback_origin === 'unavailable' ) {
			return [];
		}

		$type = $descriptor->permission_callback['type'] ?? 'unknown';
		if ( $type === 'none' ) {
			// ASA001's territory.
			return [];
		}

		$analysis = $descriptor->permission_analysis;

		if ( empty( $analysis['resolved'] ) ) {
			return [
				new Finding(
					Rule_Engine::COULD_NOT_ANALYZE,
					Finding::SEVERITY_INFO,
					Finding::CONFIDENCE_HIGH,
					'The permission_callback could not be statically analyzed (no readable source), '
						. 'so permission smells (ASA002/003/008) were not evaluated for this ability.',
					'Review the permission_callback manually; the auditor cannot see inside it.'
				),
			];
		}

		if ( ( $analysis['returns_only_literal_true'] ?? null ) !== true || ! empty( $analysis['calls_current_user_can'] ) ) {
			return [];
		}

		$by_name = ( $descriptor->permission_callback['name'] ?? null ) === '__return_true';

		return [
			new Finding(
				$this->id(),
				Finding::SEVERITY_CRITICAL,
				$by_name ? Finding::CONFIDENCE_HIGH : Finding::CONFIDENCE_MEDIUM,
				$by_name
					? 'The permission_callback is __return_true — every caller is allowed, unconditionally.'
					: 'The permission_callback appears to return literal true on every path, with no capability '
						. 'check — every caller would be allowed.',
				'Replace the callback with a real gate that checks a specific capability via current_user_can().'
			),
		];
	}
}
