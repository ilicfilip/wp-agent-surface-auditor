<?php
/**
 * ASA007: Missing or unconstrained input_schema on an exposed ability.
 *
 * @package ASA
 */

namespace ASA\Rules;

use ASA\Model\Ability_Descriptor;
use ASA\Model\Exposure;
use ASA\Model\Finding;

defined( 'ABSPATH' ) || exit;

/**
 * Flags loosely-typed inputs on agent-reachable abilities.
 *
 * An exposed ability with an unconstrained schema is an unvalidated function
 * call handed to an agent. Detected (all read directly from the schema —
 * confidence high):
 *  - `additionalProperties: true` declared explicitly at any object level;
 *  - object schemas that leave `additionalProperties` unset (the WP validator
 *    passes unknown properties through unless it is explicitly false);
 *  - string/number/integer parameters with no constraining facet
 *    (enum/pattern/format/maxLength for strings; minimum/maximum/enum for
 *    numbers).
 *
 * A deliberate design choice: an *absent* input_schema is NOT flagged.
 * WP_Ability::invoke_callback() only forwards input when a schema is declared,
 * so a schema-less ability receives no agent-controlled input at all —
 * flagging it would be a false positive.
 */
class Loose_Input_Schema implements Rule {

	/**
	 * Maximum number of offending parameters listed in the message.
	 */
	const MAX_LISTED = 5;

	/**
	 * The catalog ID of this rule.
	 *
	 * @return string The rule ID.
	 */
	public function id() {
		return 'ASA007';
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

		if ( $descriptor->input_schema === [] ) {
			// No schema means core forwards no input to the callback — there
			// is nothing for an agent to inject. See class docblock.
			return [];
		}

		$issues = $this->walk( $descriptor->input_schema, '(root)' );

		if ( $issues === [] ) {
			return [];
		}

		$listed  = array_slice( $issues, 0, self::MAX_LISTED );
		$omitted = count( $issues ) - count( $listed );
		$detail  = implode( '; ', $listed ) . ( $omitted > 0 ? '; and ' . $omitted . ' more' : '' );

		return [
			new Finding(
				$this->id(),
				Finding::SEVERITY_MEDIUM,
				Finding::CONFIDENCE_HIGH,
				'The input schema of this agent-reachable ability is loosely constrained: ' . $detail . '.',
				'Tighten the input_schema: set additionalProperties to false on objects, and constrain '
					. 'scalar parameters with enum, pattern, format, maxLength, or minimum/maximum bounds.'
			),
		];
	}

	/**
	 * Recursively collect looseness issues from a JSON-schema fragment.
	 *
	 * @param array<string, mixed> $schema The schema fragment.
	 * @param string               $path   Human-readable path of the fragment.
	 * @return string[] Issue descriptions.
	 */
	private function walk( array $schema, $path ) {
		$issues = [];
		$type   = $schema['type'] ?? null;

		if ( $type === 'object' || isset( $schema['properties'] ) ) {
			if ( ( $schema['additionalProperties'] ?? null ) === true ) {
				$issues[] = $path . ' explicitly allows additionalProperties';
			} elseif ( ! isset( $schema['additionalProperties'] ) ) {
				$issues[] = $path . ' does not set additionalProperties: false (unknown properties pass through)';
			}

			$properties = $schema['properties'] ?? [];
			if ( is_array( $properties ) ) {
				foreach ( $properties as $name => $property ) {
					if ( is_array( $property ) ) {
						$issues = array_merge( $issues, $this->walk( $property, $path === '(root)' ? (string) $name : $path . '.' . $name ) );
					}
				}
			}
		} elseif ( $type === 'string' ) {
			if ( ! isset( $schema['enum'] ) && ! isset( $schema['pattern'] ) && ! isset( $schema['format'] ) && ! isset( $schema['maxLength'] ) ) {
				$issues[] = $path . ' is an unconstrained string (no enum/pattern/format/maxLength)';
			}
		} elseif ( $type === 'number' || $type === 'integer' ) {
			if ( ! isset( $schema['enum'] ) && ! isset( $schema['minimum'] ) && ! isset( $schema['maximum'] ) ) {
				$issues[] = $path . ' is an unbounded ' . $type . ' (no minimum/maximum/enum)';
			}
		} elseif ( $type === 'array' && isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
			$issues = array_merge( $issues, $this->walk( $schema['items'], $path . '[]' ) );
		}

		return $issues;
	}
}
