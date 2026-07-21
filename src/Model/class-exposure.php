<?php
/**
 * Per-ability agent-exposure resolution.
 *
 * @package ASA
 */

namespace ASA\Model;

defined( 'ABSPATH' ) || exit;

/**
 * Value object answering "can an authenticated agent reach this ability, and
 * through which channels?".
 *
 * Two channels exist as of WP 7.0:
 *  - Core REST: POST /wp-json/wp-abilities/v1/abilities/{name}/run, gated by
 *    meta.show_in_rest (no MCP Adapter involved at all).
 *  - MCP: the adapter's default server reaches every meta.mcp.public ability
 *    through its execute-ability meta-tool, and custom servers reach whatever
 *    ability names they explicitly enumerate.
 */
class Exposure {

	/**
	 * Reachable via the core wp-abilities/v1 run endpoint.
	 *
	 * @var bool
	 */
	public $rest = false;

	/**
	 * The meta.mcp.public flag as declared on the ability.
	 *
	 * @var bool
	 */
	public $mcp_public = false;

	/**
	 * Reachable through the default MCP server's execute-ability meta-tool.
	 * False when the adapter is absent or the default server is disabled.
	 *
	 * @var bool
	 */
	public $mcp_default_server = false;

	/**
	 * IDs of MCP servers that explicitly list this ability as a tool,
	 * resource, or prompt.
	 *
	 * @var string[]
	 */
	public $mcp_servers = [];

	/**
	 * Subset of $mcp_servers that are custom (non-default) servers — these
	 * carry the ability because their registration code named it, regardless
	 * of meta.mcp.public (input to ASA010).
	 *
	 * @var string[]
	 */
	public $mcp_custom_servers = [];

	/**
	 * Whether the MCP Adapter is present (class exists) on this site.
	 *
	 * @var bool
	 */
	public $adapter_active = false;

	/**
	 * True when MCP flags are declared but the adapter is absent or its
	 * server state could not be read in this request context — the MCP
	 * exposure shown is *intended*, not verified-live.
	 *
	 * @var bool
	 */
	public $mcp_intended_only = false;

	/**
	 * Whether any agent-reachable channel is open (or intended, when the
	 * adapter state is unreadable). This is the "exposed" input to the rules.
	 *
	 * @return bool True when at least one channel reaches this ability.
	 */
	public function is_agent_reachable() {
		if ( $this->rest || $this->mcp_default_server || $this->mcp_servers !== [] ) {
			return true;
		}

		// Intended exposure counts: the flag is the site owner's declared intent.
		return $this->mcp_intended_only && $this->mcp_public;
	}

	/**
	 * Export as a JSON-serializable array.
	 *
	 * @return array<string, mixed> The exposure as plain data.
	 */
	public function to_array() {
		return [
			'rest'               => $this->rest,
			'mcp_public'         => $this->mcp_public,
			'mcp_default_server' => $this->mcp_default_server,
			'mcp_servers'        => $this->mcp_servers,
			'mcp_custom_servers' => $this->mcp_custom_servers,
			'adapter_active'     => $this->adapter_active,
			'mcp_intended_only'  => $this->mcp_intended_only,
			'agent_reachable'    => $this->is_agent_reachable(),
		];
	}
}
