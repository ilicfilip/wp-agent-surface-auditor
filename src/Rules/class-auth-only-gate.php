<?php
/**
 * ASA003: Authentication-only permission gate.
 *
 * @package ASA
 */

namespace ASA\Rules;

use ASA\Model\Ability_Descriptor;
use ASA\Model\Exposure;
use ASA\Model\Finding;

defined( 'ABSPATH' ) || exit;

/**
 * Flags permission callbacks that only check *that* a user is logged in,
 * never *who* they are.
 *
 * Any subscriber — and any agent authenticating with any user's application
 * password — passes such a gate. Two tiers:
 *  - `is_user_logged_in` set directly as the callback (by name) — high;
 *  - a source span that consults is_user_logged_in() and never a capability
 *    — medium.
 */
class Auth_Only_Gate implements Rule {

	/**
	 * The catalog ID of this rule.
	 *
	 * @return string The rule ID.
	 */
	public function id() {
		return 'ASA003';
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

		$analysis = $descriptor->permission_analysis;
		if ( empty( $analysis['resolved'] ) ) {
			// ASA002 already emitted the could-not-analyze info finding.
			return [];
		}

		if ( empty( $analysis['calls_is_user_logged_in'] ) || ! empty( $analysis['calls_current_user_can'] ) ) {
			return [];
		}

		$by_name = ( $descriptor->permission_callback['name'] ?? null ) === 'is_user_logged_in';

		return [
			new Finding(
				$this->id(),
				Finding::SEVERITY_HIGH,
				$by_name ? Finding::CONFIDENCE_HIGH : Finding::CONFIDENCE_MEDIUM,
				$by_name
					? 'The permission_callback is is_user_logged_in — any authenticated user of any role passes, '
						. 'including any agent holding any application password.'
					: 'The permission_callback appears to gate only on is_user_logged_in(), with no capability '
						. 'check — any authenticated user of any role would pass.',
				'Check a specific capability with current_user_can() instead of (or in addition to) authentication.'
			),
		];
	}
}
