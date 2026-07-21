<?php
/**
 * ASA008: Broad capability gating a destructive operation.
 *
 * @package ASA
 */

namespace ASA\Rules;

use ASA\Model\Ability_Descriptor;
use ASA\Model\Exposure;
use ASA\Model\Finding;

defined( 'ABSPATH' ) || exit;

/**
 * Flags destructive abilities whose permission gate only checks a
 * capability that effectively every user has.
 *
 * `read` and `exist` are granted to subscribers — a gate like
 * current_user_can( 'read' ) on a destructive ability is authentication-only
 * in practice. Fires only when ALL detected capability checks are broad; one
 * strong capability alongside a broad one is treated as a real gate.
 */
class Broad_Cap_Destructive implements Rule {

	/**
	 * Capabilities that essentially every registered user holds.
	 *
	 * @var string[]
	 */
	const BROAD_CAPABILITIES = [ 'read', 'exist', 'level_0' ];

	/**
	 * The catalog ID of this rule.
	 *
	 * @return string The rule ID.
	 */
	public function id() {
		return 'ASA008';
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
		$destructive = $descriptor->annotations['destructive'] === true
			|| $descriptor->annotations['readonly'] === false;

		if ( ! $destructive ) {
			return [];
		}

		$analysis = $descriptor->permission_analysis;
		if ( empty( $analysis['resolved'] ) ) {
			return [];
		}

		$capabilities = $analysis['capability_checks'] ?? [];
		if ( ! is_array( $capabilities ) || $capabilities === [] ) {
			return [];
		}

		foreach ( $capabilities as $capability ) {
			if ( ! in_array( strtolower( (string) $capability ), self::BROAD_CAPABILITIES, true ) ) {
				return [];
			}
		}

		return [
			new Finding(
				$this->id(),
				Finding::SEVERITY_HIGH,
				Finding::CONFIDENCE_MEDIUM,
				'This ability declares write/destructive intent but its permission gate only checks '
					. implode( ', ', array_map( 'strval', $capabilities ) )
					. ' — a capability effectively every registered user (and thus every authenticated agent) holds.',
				'Gate destructive operations on a capability that actually scopes them, e.g. delete_posts, '
					. 'manage_options, or a purpose-built custom capability.'
			),
		];
	}
}
