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
 * password — passes such a gate. Two confidence tiers:
 *  - `is_user_logged_in` set directly as the callback (by name) — high;
 *  - a source span that consults is_user_logged_in() and never a capability
 *    — medium.
 *
 * Severity is downgraded to `low` when the ability declares `readonly: true`
 * and no write indicators were found in its execute source. For a read that
 * is scoped to the calling user (WP core's `core/get-user-info` is the
 * reference case) authentication *is* the appropriate gate — there is no
 * narrower capability to require. The finding still fires, because whether
 * the returned data is genuinely self-scoped is not something we can prove
 * statically; it is reported as a fact to confirm, not a weakness to fix.
 *
 * A declared `readonly: true` contradicted by write indicators keeps full
 * severity — that combination is ASA006's mismatch and must not be softened.
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

		$gate = $by_name
			? 'The permission_callback is is_user_logged_in — any authenticated user of any role passes, '
				. 'including any agent holding any application password.'
			: 'The permission_callback appears to gate only on is_user_logged_in(), with no capability '
				. 'check — any authenticated user of any role would pass.';

		if ( $this->is_declared_read_only( $descriptor ) ) {
			return [
				new Finding(
					$this->id(),
					Finding::SEVERITY_LOW,
					$by_name ? Finding::CONFIDENCE_HIGH : Finding::CONFIDENCE_MEDIUM,
					$gate . ' The ability declares readonly: true and no write indicators were found in its '
						. 'execute callback, so an authentication-only gate may well be correct here — that is '
						. 'the expected shape for a read scoped to the calling user.',
					'Confirm the data returned is scoped to the calling user. If it can expose another user\'s '
						. 'or the site\'s data, require a specific capability with current_user_can() instead.'
				),
			];
		}

		return [
			new Finding(
				$this->id(),
				Finding::SEVERITY_HIGH,
				$by_name ? Finding::CONFIDENCE_HIGH : Finding::CONFIDENCE_MEDIUM,
				$gate,
				'Check a specific capability with current_user_can() instead of (or in addition to) authentication.'
			),
		];
	}

	/**
	 * Whether the ability is credibly read-only: it declares readonly: true
	 * and its execute source produced no write indicators.
	 *
	 * An unresolved execute source is not treated as evidence of absence —
	 * without a readable span we cannot say the ability does not write, so
	 * the downgrade is withheld.
	 *
	 * @param Ability_Descriptor $descriptor The ability under audit.
	 * @return bool True when the read-only claim is corroborated.
	 */
	private function is_declared_read_only( Ability_Descriptor $descriptor ) {
		if ( ( $descriptor->annotations['readonly'] ?? null ) !== true ) {
			return false;
		}

		$execute = $descriptor->execute_analysis;

		return ! empty( $execute['resolved'] ) && empty( $execute['write_indicators'] );
	}
}
