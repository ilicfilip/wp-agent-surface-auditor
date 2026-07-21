<?php
/**
 * Resolves MCP Adapter state and per-ability MCP exposure.
 *
 * @package ASA
 */

namespace ASA\Registry;

use ASA\Model\Ability_Descriptor;
use ASA\Model\Exposure;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the MCP Adapter's in-process registry (never the MCP protocol) to
 * determine which servers exist and which abilities each one carries.
 *
 * Verified against mcp-adapter v0.5.0:
 *  - Detection: class_exists( \WP\MCP\Core\McpAdapter::class ). The adapter is
 *    not on wordpress.org and may be Composer-embedded in another plugin, so
 *    "is the plugin active" checks are wrong by design.
 *  - Timing: servers register inside the mcp_adapter_init action, which the
 *    adapter fires on rest_api_init priority 15 (init 20 under WP-CLI). On a
 *    plain admin request the server list is empty — the resolver then reports
 *    *intended* exposure from the meta flags and says so.
 *  - The default server exposes three meta-tools; every meta.mcp.public
 *    ability is reachable through its execute-ability tool without appearing
 *    in the server's own tool list. Custom servers enumerate ability names
 *    explicitly and ignore the flag.
 *
 * All adapter access is runtime-guarded and wrapped: an incompatible adapter
 * version degrades to "adapter present, not inspectable", never to a fatal.
 */
class Mcp_Exposure_Resolver {

	/**
	 * The adapter class name checked for detection.
	 */
	const ADAPTER_CLASS = '\\WP\\MCP\\Core\\McpAdapter';

	/**
	 * Minimum adapter version whose registry shape we know how to read.
	 */
	const MIN_SUPPORTED_ADAPTER = '0.5.0';

	/**
	 * The ability name of the default server's execute meta-tool.
	 */
	const EXECUTE_META_ABILITY = 'mcp-adapter/execute-ability';

	/**
	 * Cached adapter state for this request.
	 *
	 * @var array<string, mixed>|null
	 */
	private $adapter_state = null;

	/**
	 * Cached server inventory for this request.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private $servers = null;

	/**
	 * Describe the adapter installation on this site.
	 *
	 * @return array<string, mixed> {
	 *     Adapter state.
	 *
	 *     @type bool        $active      Adapter code is present.
	 *     @type string|null $version     Adapter version when readable.
	 *     @type bool        $supported   Version is one we can inspect (>= 0.5.0).
	 *     @type bool        $initialized mcp_adapter_init has fired in this request.
	 *     @type string      $note        Human-readable qualifier for the report.
	 * }
	 */
	public function adapter_state() {
		if ( $this->adapter_state !== null ) {
			return $this->adapter_state;
		}

		$state = [
			'active'      => false,
			'version'     => null,
			'supported'   => false,
			'initialized' => false,
			'note'        => '',
		];

		if ( ! class_exists( self::ADAPTER_CLASS ) ) {
			$state['note']       = 'MCP Adapter not installed — MCP exposure shown is intended (from meta.mcp.public), not live.';
			$this->adapter_state = $state;
			return $state;
		}

		$state['active'] = true;

		$version_constant = self::ADAPTER_CLASS . '::VERSION';
		if ( defined( $version_constant ) ) {
			$version          = constant( $version_constant );
			$state['version'] = is_string( $version ) ? $version : null;
		}

		$state['supported'] = is_string( $state['version'] )
			&& version_compare( $state['version'], self::MIN_SUPPORTED_ADAPTER, '>=' );

		$state['initialized'] = did_action( 'mcp_adapter_init' ) > 0;

		if ( ! $state['supported'] ) {
			$state['note'] = 'MCP Adapter version is older than 0.5.0 — server inventory is not inspectable; MCP exposure shown is intended, not live.';
		} elseif ( ! $state['initialized'] ) {
			$state['note'] = 'MCP Adapter has not initialized in this request context (it initializes on rest_api_init) — MCP exposure shown is intended, not live.';
		}

		$this->adapter_state = $state;
		return $state;
	}

	/**
	 * Inventory of registered MCP servers and the ability names they carry.
	 *
	 * @return array<int, array<string, mixed>> One entry per server: id, name,
	 *                                          description, version, route info,
	 *                                          is_default, and ability name
	 *                                          lists per component type.
	 */
	public function servers() {
		if ( $this->servers !== null ) {
			return $this->servers;
		}

		$this->servers = [];
		$state         = $this->adapter_state();

		if ( ! $state['active'] || ! $state['supported'] || ! $state['initialized'] ) {
			return $this->servers;
		}

		try {
			$adapter_class = ltrim( self::ADAPTER_CLASS, '\\' );
			$adapter       = $adapter_class::instance();

			foreach ( $adapter->get_servers() as $server ) {
				$this->servers[] = $this->describe_server( $server );
			}
		} catch ( Throwable $t ) {
			$this->servers = [];
		}

		return $this->servers;
	}

	/**
	 * Resolve the exposure of one ability across all channels.
	 *
	 * @param Ability_Descriptor $descriptor The ability being resolved.
	 * @return Exposure The resolved exposure.
	 */
	public function resolve( Ability_Descriptor $descriptor ) {
		$state    = $this->adapter_state();
		$servers  = $this->servers();
		$exposure = new Exposure();

		$exposure->rest           = $descriptor->show_in_rest;
		$exposure->mcp_public     = $descriptor->mcp_public;
		$exposure->adapter_active = $state['active'];

		// Live server state is only readable in an initialized, supported
		// adapter; otherwise MCP exposure degrades to declared intent.
		$live = $state['active'] && $state['supported'] && $state['initialized'];

		$exposure->mcp_intended_only = ! $live && $descriptor->mcp_public;

		foreach ( $servers as $server ) {
			$listed = in_array( $descriptor->name, $server['ability_names'], true );

			if ( $listed && ! in_array( $server['id'], $exposure->mcp_servers, true ) ) {
				$exposure->mcp_servers[] = $server['id'];

				if ( ! $server['is_default'] ) {
					$exposure->mcp_custom_servers[] = $server['id'];
				}
			}

			if ( $server['is_default'] && $descriptor->mcp_public ) {
				$exposure->mcp_default_server = true;
			}
		}

		return $exposure;
	}

	/**
	 * Normalize one McpServer into plain data.
	 *
	 * @param object $server A \WP\MCP\Core\McpServer instance.
	 * @return array<string, mixed> JSON-safe server description.
	 */
	private function describe_server( $server ) {
		$info = [
			'id'            => '',
			'name'          => '',
			'description'   => '',
			'version'       => '',
			'namespace'     => '',
			'route'         => '',
			'url'           => '',
			'is_default'    => false,
			'tools'         => [],
			'resources'     => [],
			'prompts'       => [],
			'ability_names' => [],
		];

		try {
			$info['id']          = (string) $server->get_server_id();
			$info['name']        = (string) $server->get_server_name();
			$info['description'] = (string) $server->get_server_description();
			$info['version']     = (string) $server->get_server_version();
			$info['namespace']   = (string) $server->get_server_route_namespace();
			$info['route']       = (string) $server->get_server_route();
			$info['url']         = rest_url( trim( $info['namespace'], '/' ) . '/' . trim( $info['route'], '/' ) );

			$info['tools']     = $this->collect_component_abilities( $server, 'tool' );
			$info['resources'] = $this->collect_component_abilities( $server, 'resource' );
			$info['prompts']   = $this->collect_component_abilities( $server, 'prompt' );

			$ability_names = [];
			foreach ( [ 'tools', 'resources', 'prompts' ] as $component_type ) {
				foreach ( $info[ $component_type ] as $component ) {
					if ( is_string( $component['ability'] ) && $component['ability'] !== '' ) {
						$ability_names[] = $component['ability'];
					}
				}
			}
			$info['ability_names'] = array_values( array_unique( $ability_names ) );

			// The default server is recognized by carrying the adapter's
			// execute-ability meta-tool (robust against a filtered server id).
			$info['is_default'] = in_array( self::EXECUTE_META_ABILITY, $info['ability_names'], true )
				|| $info['id'] === 'mcp-adapter-default-server';
		} catch ( Throwable $t ) {
			$info['error'] = 'Could not fully inspect this server: ' . $t->getMessage();
		}

		return $info;
	}

	/**
	 * List one component type of a server with its ability mapping.
	 *
	 * Uses the DTO lists for the component keys, then maps each entry back to
	 * its originating ability via the internal Mcp* wrappers' adapter meta
	 * (`adapter_meta['ability']`, set for ability-backed components in
	 * v0.5.0). Entries built from raw callables have no ability and map null.
	 *
	 * @param object $server A \WP\MCP\Core\McpServer instance.
	 * @param string $type   'tool', 'resource' or 'prompt'.
	 * @return array<int, array<string, string|null>> Component name/key plus
	 *                                                originating ability name.
	 */
	private function collect_component_abilities( $server, $type ) {
		$components = [];

		$list_method   = 'get_' . $type . 's';
		$lookup_method = 'get_mcp_' . $type;

		if ( ! method_exists( $server, $list_method ) ) {
			return $components;
		}

		$dtos = $server->{$list_method}();
		if ( ! is_array( $dtos ) ) {
			return $components;
		}

		foreach ( array_keys( $dtos ) as $key ) {
			$entry = [
				'name'    => (string) $key,
				'ability' => null,
			];

			try {
				if ( method_exists( $server, $lookup_method ) ) {
					$wrapper = $server->{$lookup_method}( (string) $key );
					if ( is_object( $wrapper ) && method_exists( $wrapper, 'get_adapter_meta' ) ) {
						$adapter_meta = $wrapper->get_adapter_meta();
						if ( is_array( $adapter_meta ) && isset( $adapter_meta['ability'] ) && is_string( $adapter_meta['ability'] ) ) {
							$entry['ability'] = $adapter_meta['ability'];
						}
					}
				}
			} catch ( Throwable $t ) {
				unset( $t );
			}

			$components[] = $entry;
		}

		return $components;
	}
}
