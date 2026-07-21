<?php
/**
 * ASA001: Missing permission_callback.
 *
 * @package ASA
 */

namespace ASA\Rules;

use ASA\Model\Ability_Descriptor;
use ASA\Model\Exposure;
use ASA\Model\Finding;

defined( 'ABSPATH' ) || exit;

/**
 * Flags abilities that have no permission callback at all.
 *
 * The core wp_register_ability() function rejects registrations without a
 * valid permission_callback for the stock WP_Ability class, so this condition
 * can only arise through a custom `ability_class` (whose constructor bypasses
 * that validation) — rare, but the single worst possible state: every caller
 * passes the gate, and WP_Ability::check_permissions() only fails because the
 * callback is uncallable, which a subclass may well override.
 *
 * Fires only on positive knowledge: when the callbacks could not be read at
 * all (`callback_origin: unavailable`), the engine's ASA000 finding covers
 * the uncertainty and this rule stays silent rather than guessing.
 */
class Missing_Permission implements Rule {

	/**
	 * The catalog ID of this rule.
	 *
	 * @return string The rule ID.
	 */
	public function id() {
		return 'ASA001';
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

		if ( $type !== 'none' ) {
			return [];
		}

		return [
			new Finding(
				$this->id(),
				Finding::SEVERITY_CRITICAL,
				Finding::CONFIDENCE_HIGH,
				'No permission_callback is attached to this ability (registered via custom class '
					. $descriptor->class_name . '). Every caller passes the permission gate.',
				'Add a permission_callback that checks a specific capability with current_user_can().'
			),
		];
	}
}
