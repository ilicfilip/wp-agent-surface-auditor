<?php
/**
 * ASA004: Agent-reachable and write/destructive.
 *
 * @package ASA
 */

namespace ASA\Rules;

use ASA\Model\Ability_Descriptor;
use ASA\Model\Exposure;
use ASA\Model\Finding;

defined( 'ABSPATH' ) || exit;

/**
 * Flags abilities an agent can reach that declare write intent.
 *
 * Two detection tiers:
 *  - annotation-based: `destructive: true` or `readonly: false` — confidence
 *    high (read from flags);
 *  - heuristic: annotations absent AND the execute callback's source contains
 *    known write indicators — confidence medium.
 *
 * This is by design not a "bug" finding — an exposed write ability may be
 * exactly what the site owner wants. It exists so that every such ability is
 * a *known, listed* decision instead of a surprise.
 */
class Exposed_Write implements Rule {

	/**
	 * The catalog ID of this rule.
	 *
	 * @return string The rule ID.
	 */
	public function id() {
		return 'ASA004';
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

		$destructive = $descriptor->annotations['destructive'] === true;
		$writes      = $descriptor->annotations['readonly'] === false;

		$intent     = null;
		$confidence = Finding::CONFIDENCE_HIGH;

		if ( $destructive ) {
			$intent = 'declares itself destructive';
		} elseif ( $writes ) {
			$intent = 'declares that it writes (readonly: false)';
		} elseif ( $descriptor->annotations['readonly'] === null && $descriptor->annotations['destructive'] === null ) {
			// Undeclared intent: fall back to the write-indicator heuristic.
			$indicators = $descriptor->execute_analysis['write_indicators'] ?? [];
			if ( ! empty( $descriptor->execute_analysis['resolved'] ) && is_array( $indicators ) && $indicators !== [] ) {
				$intent     = 'appears to write (no annotations declared; source contains '
					. implode( ', ', array_slice( $indicators, 0, 5 ) ) . ')';
				$confidence = Finding::CONFIDENCE_MEDIUM;
			}
		}

		if ( $intent === null ) {
			return [];
		}

		$channel = $this->channel_description( $exposure );

		return [
			new Finding(
				$this->id(),
				Finding::SEVERITY_HIGH,
				$confidence,
				'This ability ' . $intent . ' and is reachable by agents ' . $channel
					. '. A connected agent authenticating as a sufficiently-privileged user can perform this operation.',
				'Confirm this exposure is intentional. If not, remove meta.show_in_rest / meta.mcp.public or '
					. 'take it off the MCP server; if yes, ensure the permission_callback requires the narrowest '
					. 'capability that fits the operation.'
			),
		];
	}

	/**
	 * Describe which channels make the ability reachable.
	 *
	 * @param Exposure $exposure The resolved exposure.
	 * @return string Human-readable channel summary.
	 */
	private function channel_description( Exposure $exposure ) {
		$channels = [];

		if ( $exposure->rest ) {
			$channels[] = 'via the core wp-abilities/v1 REST run endpoint';
		}
		if ( $exposure->mcp_default_server ) {
			$channels[] = 'via the default MCP server';
		}
		if ( $exposure->mcp_servers !== [] ) {
			$channels[] = 'via MCP server(s): ' . implode( ', ', $exposure->mcp_servers );
		}
		if ( $channels === [] && $exposure->mcp_intended_only ) {
			$channels[] = 'via MCP once the adapter is active (meta.mcp.public is set; exposure is intended, not yet live)';
		}

		return implode( ', ', $channels );
	}
}
