<?php
/**
 * ASA010: Exposed via a custom MCP server (awareness).
 *
 * @package ASA
 */

namespace ASA\Rules;

use ASA\Model\Ability_Descriptor;
use ASA\Model\Exposure;
use ASA\Model\Finding;

defined( 'ABSPATH' ) || exit;

/**
 * Inventory finding: this ability is explicitly listed on a custom
 * (non-default) MCP server.
 *
 * Custom servers ignore meta.mcp.public entirely — whoever registered the
 * server chose this ability by name. That is not a defect, but it is exactly
 * the kind of exposure a site owner should be able to enumerate, because it
 * exists outside the flag they were told controls MCP exposure.
 */
class Custom_Server_Exposure implements Rule {

	/**
	 * The catalog ID of this rule.
	 *
	 * @return string The rule ID.
	 */
	public function id() {
		return 'ASA010';
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
		if ( $exposure->mcp_custom_servers === [] ) {
			return [];
		}

		return [
			new Finding(
				$this->id(),
				Finding::SEVERITY_INFO,
				Finding::CONFIDENCE_HIGH,
				'This ability is explicitly listed on custom MCP server(s): '
					. implode( ', ', $exposure->mcp_custom_servers )
					. '. Custom servers expose abilities by name, independent of meta.mcp.public.',
				'Confirm the custom server is meant to carry this ability; exposure through it is controlled '
					. 'by the server\'s registration code, not by the ability\'s own meta flags.'
			),
		];
	}
}
