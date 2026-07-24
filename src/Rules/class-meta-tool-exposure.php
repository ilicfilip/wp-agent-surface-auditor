<?php
/**
 * ASA011: Agent-reachable meta-tool (proxies execution of other abilities).
 *
 * @package ASA
 */

namespace ASA\Rules;

use ASA\Model\Ability_Descriptor;
use ASA\Model\Exposure;
use ASA\Model\Finding;

defined( 'ABSPATH' ) || exit;

/**
 * Awareness finding: this ability is a *meta-tool* — a single reachable tool
 * that lists, inspects, and executes other abilities rather than performing
 * one fixed operation.
 *
 * A meta-tool structurally defeats per-ability exposure control. It does not
 * matter which abilities the site marked `meta.mcp.public`: if one exposed
 * tool proxies execution of the whole registry, the exposure decision the
 * owner thinks they made (per-ability flags) is not the exposure that exists.
 * The MCP Adapter's default server ships exactly such a tool
 * (`mcp-adapter/execute-ability`); WordPress Playground's MCP support ships
 * `playground_ability` with the same shape.
 *
 * This is not inherently a defect — meta-tools are a deliberate design — but
 * it is precisely the kind of surface a site owner should be able to
 * enumerate, because it reframes every other exposure finding: a "clean,
 * not-mcp.public" ability is still reachable if a meta-tool can call it.
 * Info severity, high confidence (the identity is read from the ability name).
 */
class Meta_Tool_Exposure implements Rule {

	/**
	 * Known meta-tool ability names — tools that proxy execution of other
	 * abilities rather than performing one fixed operation. Extend as new
	 * meta-tools appear in the ecosystem; matched exactly by ability name.
	 *
	 * @var string[]
	 */
	const META_TOOL_NAMES = [
		'mcp-adapter/execute-ability',
		'playground_ability',
	];

	/**
	 * The catalog ID of this rule.
	 *
	 * @return string The rule ID.
	 */
	public function id() {
		return 'ASA011';
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
		if ( ! in_array( $descriptor->name, self::META_TOOL_NAMES, true ) ) {
			return [];
		}

		if ( ! $exposure->is_agent_reachable() ) {
			return [];
		}

		return [
			new Finding(
				$this->id(),
				Finding::SEVERITY_INFO,
				Finding::CONFIDENCE_HIGH,
				'"' . $descriptor->name . '" is a meta-tool: a single reachable tool that proxies '
					. 'listing, inspection, and execution of other abilities. Because it can invoke abilities '
					. 'by name, per-ability meta.mcp.public flags do not bound what an agent can reach through '
					. 'it — the effective MCP surface is wider than the per-ability exposure suggests.',
				'Confirm this meta-tool is meant to be agent-reachable. If it is, remember that securing the '
					. 'surface means gating the abilities it can proxy, not only the abilities you flagged '
					. 'mcp.public — the meta-tool ignores those flags.'
			),
		];
	}
}
