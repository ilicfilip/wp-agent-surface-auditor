<?php
/**
 * Orchestrates one audit run: read → resolve → evaluate → report.
 *
 * @package ASA
 */

namespace ASA\Report;

use ASA\Model\Finding;
use ASA\Registry\Ability_Registry_Reader;
use ASA\Registry\Mcp_Exposure_Resolver;
use ASA\Rules\Rule_Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Computes the audit report from the live registries.
 *
 * Stateless except for one optional convenience: the finished report is
 * cached in a short-lived transient so dashboard reloads are instant. The
 * transient is the only thing this plugin ever writes to keep the audit
 * read-only; a fresh run bypasses and replaces it.
 */
class Audit_Runner {

	/**
	 * Transient name for the cached report.
	 */
	const CACHE_KEY = 'asa_last_report';

	/**
	 * Cache lifetime in seconds.
	 */
	const CACHE_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Produce the full report, from cache when allowed.
	 *
	 * @param bool $fresh Bypass and refresh the cached report.
	 * @return array<string, mixed> The JSON-serializable report.
	 */
	public function run( $fresh = false ) {
		if ( ! $fresh ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) && isset( $cached['generated_at'] ) ) {
				$cached['from_cache'] = true;
				return $cached;
			}
		}

		$report = $this->compute();

		set_transient( self::CACHE_KEY, $report, self::CACHE_TTL );

		return $report;
	}

	/**
	 * Compute the report from the live registries.
	 *
	 * @return array<string, mixed> The JSON-serializable report.
	 */
	private function compute() {
		global $wp_version;

		$reader   = new Ability_Registry_Reader();
		$resolver = new Mcp_Exposure_Resolver();
		$engine   = new Rule_Engine();
		$scorer   = new Risk_Scorer();

		$descriptors   = $reader->read();
		$adapter_state = $resolver->adapter_state();
		$servers       = $resolver->servers();

		$abilities = [];
		$summary   = [
			'total_abilities'       => 0,
			'rest_exposed'          => 0,
			'mcp_public'            => 0,
			'agent_reachable'       => 0,
			'declared_write'        => 0,
			'undeclared_intent'     => 0,
			'agent_reachable_write' => 0,
			'analysis_errors'       => 0,
			'findings_by_severity'  => array_fill_keys( Finding::SEVERITY_ORDER, 0 ),
			'abilities_by_risk'     => array_fill_keys(
				array_merge( Finding::SEVERITY_ORDER, [ Risk_Scorer::RISK_NONE ] ),
				0
			),
		];

		foreach ( $descriptors as $descriptor ) {
			$exposure = $resolver->resolve( $descriptor );
			$findings = $engine->evaluate( $descriptor, $exposure );
			$risk     = $scorer->score( $findings );

			// Annotation-declared write intent: destructive, or explicitly
			// not read-only.
			$declares_write = $descriptor->annotations['destructive'] === true
				|| $descriptor->annotations['readonly'] === false;

			$undeclared = $descriptor->annotations['readonly'] === null
				&& $descriptor->annotations['destructive'] === null;

			++$summary['total_abilities'];
			$summary['rest_exposed']          += $exposure->rest ? 1 : 0;
			$summary['mcp_public']            += $exposure->mcp_public ? 1 : 0;
			$summary['agent_reachable']       += $exposure->is_agent_reachable() ? 1 : 0;
			$summary['declared_write']        += $declares_write ? 1 : 0;
			$summary['undeclared_intent']     += $undeclared ? 1 : 0;
			$summary['agent_reachable_write'] += ( $declares_write && $exposure->is_agent_reachable() ) ? 1 : 0;
			$summary['analysis_errors']       += $descriptor->analysis_errors === [] ? 0 : 1;

			foreach ( $findings as $finding ) {
				if ( isset( $summary['findings_by_severity'][ $finding->severity ] ) ) {
					++$summary['findings_by_severity'][ $finding->severity ];
				}
			}
			++$summary['abilities_by_risk'][ $risk ];

			$finding_arrays = [];
			foreach ( $findings as $finding ) {
				$finding_arrays[] = $finding->to_array();
			}

			$abilities[] = [
				'descriptor' => $descriptor->to_array(),
				'exposure'   => $exposure->to_array(),
				'findings'   => $finding_arrays,
				'risk'       => $risk,
			];
		}

		// Strip the internal ability_names index from the public server view.
		$public_servers = [];
		foreach ( $servers as $server ) {
			unset( $server['ability_names'] );
			$public_servers[] = $server;
		}

		return [
			'generated_at' => gmdate( 'c' ),
			'from_cache'   => false,
			'environment'  => [
				'plugin_version' => ASA_VERSION,
				'wp_version'     => is_string( $wp_version ) ? $wp_version : '',
				'php_version'    => PHP_VERSION,
				'mcp_adapter'    => $adapter_state,
			],
			'summary'      => $summary,
			'servers'      => $public_servers,
			'abilities'    => $abilities,
		];
	}
}
