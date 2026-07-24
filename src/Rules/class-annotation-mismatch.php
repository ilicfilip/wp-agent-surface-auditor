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
 * Annotations are self-reported by whoever wrote the ability and are not
 * merely advisory. Two concrete consequences:
 *
 *  1. HTTP verb. When a client executes a server-side ability, the annotation
 *     picks the method: `readonly: true` → GET, `destructive` + `idempotent`
 *     → DELETE, everything else → POST (WP 7.0 client-side Abilities dev note).
 *     So a `readonly: true` ability that actually writes has its
 *     state-changing call sent over **GET** — cacheable by proxies and
 *     browsers, recorded in server logs and Referer headers, prefetchable,
 *     and the classic shape of a CSRF-able side effect.
 *  2. Agent autonomy. The same flag reaches MCP clients as readOnlyHint; a
 *     "safe to call speculatively" claim on a writing ability invites an
 *     agent to invoke it without confirmation.
 *
 * A wrong claim is therefore worse than no claim. Confidence stays capped
 * (the indicator may sit on a dead path, or the write may be into the
 * ability's own cache): presented as "verify", never as an accusation.
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

		// A named write function, a $wpdb write method, or a query() carrying
		// literal write SQL is an unambiguous write — raise confidence from the
		// default `low` (which still covers bare query()/incidental-write cases)
		// to `medium`. The write may still sit on a dead path, so never `high`.
		$confirmed  = ! empty( $analysis['has_confirmed_write'] );
		$confidence = $confirmed ? Finding::CONFIDENCE_MEDIUM : Finding::CONFIDENCE_LOW;

		$qualifier = $confirmed
			? 'the implementation performs a write'
			: 'the implementation appears to write';

		// GET is the verb WP 7.0 selects for `readonly: true`; the harm only
		// applies to that claim (a `destructive: false` ability still POSTs).
		$verb_note = $claims_readonly
			? ' Because it is annotated readonly, WP 7.0 sends this ability\'s call over GET, so a write '
				. 'here travels as a cacheable, loggable, prefetchable request.'
			: '';

		return [
			new Finding(
				$this->id(),
				Finding::SEVERITY_HIGH,
				$confidence,
				'Annotation claims ' . $claim . ', but ' . $qualifier . ' ('
					. implode( ', ', array_slice( $indicators, 0, 5 ) ) . ') — verify.' . $verb_note
					. ' MCP clients also receive this annotation as a hint and may act on it autonomously.',
				'Either correct the annotation to reflect what the ability does, or remove the write from the '
					. 'implementation. If the write is incidental (e.g. caching), document why the claim holds.'
			),
		];
	}
}
