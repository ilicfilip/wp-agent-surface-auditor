<?php
/**
 * ASA006: Annotation mismatch — claims read-only, appears to write.
 *
 * @package ASA
 */

namespace ASA\Rules;

use ASA\Model\Ability_Descriptor;
use ASA\Model\Exposure;
use ASA\Model\Finding;

defined( 'ABSPATH' ) || exit;

/**
 * The mismatch detector: the ability asserts it does not modify the site
 * (`readonly: true`, or `destructive: false` with no readonly claim), but
 * its execute callback's source contains known write indicators.
 *
 * Annotations are self-reported by whoever wrote the ability and flow
 * straight into MCP clients as readOnlyHint/destructiveHint — agents make
 * autonomy decisions on them. A wrong claim is worse than no claim.
 *
 * Low confidence by nature: the indicator may sit on a dead path, or the
 * write may be into the ability's own cache. Presented as "verify", never as
 * an accusation.
 */
class Annotation_Mismatch implements Rule {

	/**
	 * The catalog ID of this rule.
	 *
	 * @return string The rule ID.
	 */
	public function id() {
		return 'ASA006';
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
		$claims_readonly        = $descriptor->annotations['readonly'] === true;
		$claims_non_destructive = $descriptor->annotations['destructive'] === false;

		if ( ! $claims_readonly && ! $claims_non_destructive ) {
			return [];
		}

		$analysis = $descriptor->execute_analysis;
		if ( empty( $analysis['resolved'] ) ) {
			return [];
		}

		$indicators = $analysis['write_indicators'] ?? [];
		if ( ! is_array( $indicators ) || $indicators === [] ) {
			return [];
		}

		$claim = $claims_readonly ? 'readonly: true' : 'destructive: false';

		return [
			new Finding(
				$this->id(),
				Finding::SEVERITY_MEDIUM,
				Finding::CONFIDENCE_LOW,
				'Annotation claims ' . $claim . ', but the execute callback\'s implementation appears to write ('
					. implode( ', ', array_slice( $indicators, 0, 5 ) ) . ') — verify. '
					. 'MCP clients receive this annotation as a hint and may act on it autonomously.',
				'Either correct the annotation to reflect what the ability does, or remove the write from the '
					. 'implementation. If the write is incidental (e.g. caching), document why the claim holds.'
			),
		];
	}
}
