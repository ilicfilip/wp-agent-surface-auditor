<?php
/**
 * Runs the rule catalog over one ability.
 *
 * @package ASA
 */

namespace ASA\Rules;

use ASA\Model\Ability_Descriptor;
use ASA\Model\Exposure;
use ASA\Model\Finding;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Evaluates every registered rule against an ability, accumulating findings.
 *
 * Order matters: composite rules (ASA005) consume the findings of earlier
 * rules via the `$prior_findings` parameter, so the permission smells run
 * first and composites run last.
 *
 * Fails safe per rule: a Throwable inside one rule becomes an ASA000 "could
 * not analyze" info finding instead of killing the audit.
 */
class Rule_Engine {

	/**
	 * Synthetic rule ID for "analysis was incomplete" findings.
	 */
	const COULD_NOT_ANALYZE = 'ASA000';

	/**
	 * The rules to run, in evaluation order.
	 *
	 * @var Rule[]
	 */
	private $rules;

	/**
	 * Constructor.
	 *
	 * @param Rule[]|null $rules Rules to run in order; null for the default
	 *                           catalog.
	 */
	public function __construct( $rules = null ) {
		$this->rules = $rules === null ? self::default_rules() : $rules;
	}

	/**
	 * The default rule catalog, in evaluation order.
	 *
	 * Composites that read prior findings (Exposed_Weak_Permission) come last.
	 *
	 * @return Rule[] The default rules.
	 */
	public static function default_rules() {
		return [
			new Missing_Permission(),
			new Unconditional_Allow(),
			new Auth_Only_Gate(),
			new Exposed_Write(),
			new Loose_Input_Schema(),
			new No_Annotations(),
			new Annotation_Mismatch(),
			new Broad_Cap_Destructive(),
			new Custom_Server_Exposure(),
			new Meta_Tool_Exposure(),
			new Exposed_Weak_Permission(),
		];
	}

	/**
	 * Run all rules over one ability.
	 *
	 * @param Ability_Descriptor $descriptor The ability under audit.
	 * @param Exposure           $exposure   Its resolved exposure.
	 * @return Finding[] All findings, in rule order.
	 */
	public function evaluate( Ability_Descriptor $descriptor, Exposure $exposure ) {
		$findings = [];

		// Honesty first: incomplete descriptors are labeled as such, so the
		// absence of findings on them is never mistaken for a clean pass.
		if ( $descriptor->analysis_errors !== [] ) {
			$findings[] = new Finding(
				self::COULD_NOT_ANALYZE,
				Finding::SEVERITY_INFO,
				Finding::CONFIDENCE_HIGH,
				'This ability could not be fully analyzed: ' . implode( ' ', $descriptor->analysis_errors )
					. ' Findings for it may be incomplete.',
				'Inspect this ability manually; the auditor could not read parts of its definition.'
			);
		}

		foreach ( $this->rules as $rule ) {
			try {
				$produced = $rule->evaluate( $descriptor, $exposure, $findings );
			} catch ( Throwable $t ) {
				$produced = [
					new Finding(
						self::COULD_NOT_ANALYZE,
						Finding::SEVERITY_INFO,
						Finding::CONFIDENCE_HIGH,
						'Rule ' . $rule->id() . ' failed while analyzing this ability: ' . $t->getMessage(),
						'Inspect this ability manually for the condition rule ' . $rule->id() . ' checks.'
					),
				];
			}

			foreach ( $produced as $finding ) {
				if ( $finding instanceof Finding ) {
					$findings[] = $finding;
				}
			}
		}

		return $findings;
	}
}
