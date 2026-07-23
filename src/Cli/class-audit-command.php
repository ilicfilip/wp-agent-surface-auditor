<?php
/**
 * WP-CLI command: `wp asa audit`.
 *
 * @package ASA
 */

namespace ASA\Cli;

use ASA\Report\Audit_Runner;
use ASA\Report\Report_Diff;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the agent-surface audit from the command line and, optionally, fails
 * the process when findings cross a threshold — turning the auditor into a
 * git-checkable CI gate.
 *
 * Read-only like every other path: this only reads the registry and prints.
 * The one thing it writes is the same short-lived report transient the
 * dashboard uses (bypassed by --fresh).
 */
class Audit_Command {

	/**
	 * Register the command with WP-CLI. No-op when WP-CLI is absent.
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		WP_CLI::add_command( 'asa audit', [ __CLASS__, 'audit' ] );
	}

	/**
	 * Audit the abilities surface exposed to AI agents.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output shape.
	 * ---
	 * default: summary
	 * options:
	 *   - summary
	 *   - table
	 *   - json
	 * ---
	 *
	 * [--fresh]
	 * : Bypass the cached report and recompute from the live registry.
	 *
	 * [--fail-on=<severity>]
	 * : Exit non-zero when any finding is at or above this severity.
	 * ---
	 * options:
	 *   - critical
	 *   - high
	 *   - medium
	 *   - low
	 *   - info
	 * ---
	 *
	 * [--baseline=<path>]
	 * : Path to a previously-exported report JSON to diff against. Prints the
	 * delta (new/resolved findings, risk changes, added/removed abilities).
	 *
	 * [--fail-on-new=<severity>]
	 * : With --baseline, exit non-zero when a *newly appeared* finding is at or
	 * above this severity. This is the regression gate for CI.
	 * ---
	 * options:
	 *   - critical
	 *   - high
	 *   - medium
	 *   - low
	 *   - info
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Human-readable summary.
	 *     wp asa audit
	 *
	 *     # Export a baseline to commit.
	 *     wp asa audit --fresh --format=json > asa-baseline.json
	 *
	 *     # CI gate: fail if a new high+ finding appeared since the baseline.
	 *     wp asa audit --baseline=asa-baseline.json --fail-on-new=high
	 *
	 * @param string[]             $args       Positional args (unused).
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public static function audit( $args, $assoc_args ) {
		unset( $args );

		$fresh  = isset( $assoc_args['fresh'] );
		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'summary';

		$report = ( new Audit_Runner() )->run( $fresh );

		if ( isset( $assoc_args['baseline'] ) ) {
			self::run_diff( $report, (string) $assoc_args['baseline'], $assoc_args );
			return;
		}

		switch ( $format ) {
			case 'json':
				WP_CLI::print_value( $report, [ 'format' => 'json' ] );
				break;
			case 'table':
				self::print_table( $report );
				break;
			default:
				self::print_summary( $report );
				break;
		}

		self::maybe_fail_on( $report, $assoc_args );
	}

	/**
	 * Print the compact summary line and severity counts.
	 *
	 * @param array<string, mixed> $report The report.
	 * @return void
	 */
	private static function print_summary( array $report ) {
		$summary = isset( $report['summary'] ) && is_array( $report['summary'] ) ? $report['summary'] : [];

		WP_CLI::log(
			sprintf(
				'Abilities: %d   Agent-reachable: %d   Reachable writes: %d',
				self::int_of( $summary, 'total_abilities' ),
				self::int_of( $summary, 'agent_reachable' ),
				self::int_of( $summary, 'agent_reachable_write' )
			)
		);

		$by_severity = isset( $summary['findings_by_severity'] ) && is_array( $summary['findings_by_severity'] )
			? $summary['findings_by_severity']
			: [];
		$parts       = [];
		foreach ( $by_severity as $severity => $count ) {
			$count = is_numeric( $count ) ? (int) $count : 0;
			if ( $count > 0 ) {
				$parts[] = $severity . ': ' . $count;
			}
		}

		WP_CLI::log( 'Findings — ' . ( $parts === [] ? 'none' : implode( '   ', $parts ) ) );
		WP_CLI::log( 'Absence of findings means no issues detected, not proof the surface is safe.' );
	}

	/**
	 * Print the per-ability table.
	 *
	 * @param array<string, mixed> $report The report.
	 * @return void
	 */
	private static function print_table( array $report ) {
		$abilities = isset( $report['abilities'] ) && is_array( $report['abilities'] ) ? $report['abilities'] : [];
		$rows      = [];

		foreach ( $abilities as $ability ) {
			$descriptor = isset( $ability['descriptor'] ) && is_array( $ability['descriptor'] ) ? $ability['descriptor'] : [];
			$exposure   = isset( $ability['exposure'] ) && is_array( $ability['exposure'] ) ? $ability['exposure'] : [];
			$findings   = isset( $ability['findings'] ) && is_array( $ability['findings'] ) ? $ability['findings'] : [];

			$rows[] = [
				'ability'   => isset( $descriptor['name'] ) ? (string) $descriptor['name'] : '',
				'reachable' => ! empty( $exposure['agent_reachable'] ) ? 'yes' : 'no',
				'risk'      => isset( $ability['risk'] ) ? (string) $ability['risk'] : '',
				'findings'  => (string) count( $findings ),
			];
		}

		WP_CLI\Utils\format_items( 'table', $rows, [ 'ability', 'reachable', 'risk', 'findings' ] );
	}

	/**
	 * Diff the current report against a baseline file and print the delta.
	 *
	 * @param array<string, mixed> $report     The current report.
	 * @param string               $path       Baseline JSON path.
	 * @param array<string,string> $assoc_args Flags (for --fail-on-new).
	 * @return void
	 */
	private static function run_diff( array $report, $path, array $assoc_args ) {
		if ( ! is_readable( $path ) ) {
			WP_CLI::error( sprintf( 'Baseline not readable: %s', $path ) );
			return;
		}

		$lines = file( $path );
		if ( ! is_array( $lines ) ) {
			WP_CLI::error( sprintf( 'Could not read baseline: %s', $path ) );
			return;
		}

		$baseline = json_decode( implode( '', $lines ), true );
		if ( ! is_array( $baseline ) || ! isset( $baseline['abilities'] ) ) {
			WP_CLI::error( 'Baseline is not a valid audit report (missing "abilities").' );
			return;
		}

		$differ = new Report_Diff();
		$delta  = $differ->diff( $baseline, $report );
		$counts = $delta['summary'];

		WP_CLI::log(
			sprintf(
				'New findings: %d   Resolved: %d   Risk changes: %d   New abilities: %d   Removed: %d',
				$counts['new_findings'],
				$counts['resolved_findings'],
				$counts['changed_risk'],
				$counts['new_abilities'],
				$counts['removed_abilities']
			)
		);

		foreach ( $delta['new_findings'] as $finding ) {
			WP_CLI::log(
				sprintf(
					'  + %s  %s [%s]  %s',
					$finding['ability'],
					$finding['rule_id'],
					$finding['severity'],
					$finding['message']
				)
			);
		}
		foreach ( $delta['resolved_findings'] as $finding ) {
			WP_CLI::log( sprintf( '  - %s  %s [%s]  resolved', $finding['ability'], $finding['rule_id'], $finding['severity'] ) );
		}
		foreach ( $delta['changed_risk'] as $change ) {
			WP_CLI::log( sprintf( '  ~ %s  risk %s -> %s', $change['ability'], $change['from'], $change['to'] ) );
		}

		if ( isset( $assoc_args['fail-on-new'] ) ) {
			$threshold = (string) $assoc_args['fail-on-new'];
			$breaches  = [];
			foreach ( $delta['new_findings'] as $finding ) {
				if ( $differ->meets_threshold( $finding['severity'], $threshold ) ) {
					$breaches[] = $finding;
				}
			}

			if ( $breaches !== [] ) {
				WP_CLI::error(
					sprintf(
						'%d new finding(s) at or above "%s" since the baseline.',
						count( $breaches ),
						$threshold
					)
				);
				return;
			}

			WP_CLI::success( sprintf( 'No new findings at or above "%s".', $threshold ) );
		}
	}

	/**
	 * Apply the --fail-on gate against the current report's findings.
	 *
	 * @param array<string, mixed> $report     The report.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	private static function maybe_fail_on( array $report, array $assoc_args ) {
		if ( ! isset( $assoc_args['fail-on'] ) ) {
			return;
		}

		$threshold   = (string) $assoc_args['fail-on'];
		$differ      = new Report_Diff();
		$by_severity = isset( $report['summary']['findings_by_severity'] ) && is_array( $report['summary']['findings_by_severity'] )
			? $report['summary']['findings_by_severity']
			: [];

		$breaching = 0;
		foreach ( $by_severity as $severity => $count ) {
			if ( $differ->meets_threshold( (string) $severity, $threshold ) ) {
				$breaching += is_numeric( $count ) ? (int) $count : 0;
			}
		}

		if ( $breaching > 0 ) {
			WP_CLI::error( sprintf( '%d finding(s) at or above "%s".', $breaching, $threshold ) );
			return;
		}

		WP_CLI::success( sprintf( 'No findings at or above "%s".', $threshold ) );
	}

	/**
	 * Read a numeric summary field as an int, defaulting to 0.
	 *
	 * @param array<string, mixed> $summary The summary array.
	 * @param string               $key     The field to read.
	 * @return int The value as int, or 0 when absent/non-numeric.
	 */
	private static function int_of( array $summary, $key ) {
		$value = $summary[ $key ] ?? 0;
		return is_numeric( $value ) ? (int) $value : 0;
	}
}
