/**
 * Agent Surface dashboard.
 *
 * Reads the full report from GET asa/v1/report and renders three views:
 *   1. Summary header — risk posture at a glance, adapter state, re-run.
 *   2. Abilities table — sortable/filterable, expandable finding details.
 *   3. Servers — the MCP server inventory.
 *
 * This reads as a security report: a calm severity color language, and the
 * "agent-reachable + writes" figure is the headline. No vanity charts.
 *
 * @package
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Spinner, Notice, TabPanel } from '@wordpress/components';

import AbilitiesTable from './AbilitiesTable';
import Servers from './Servers';

const SEVERITIES = [ 'critical', 'high', 'medium', 'low', 'info' ];

/**
 * Severity/risk badge.
 *
 * @param {Object} props       Component props.
 * @param {string} props.level Severity or risk level.
 * @return {JSX.Element} The badge.
 */
export function LevelBadge( { level } ) {
	return (
		<span className={ `asa-badge asa-badge--${ level }` }>{ level }</span>
	);
}

/**
 * The summary header strip.
 *
 * @param {Object} props        Component props.
 * @param {Object} props.report The full report.
 * @return {JSX.Element} The summary view.
 */
function Summary( { report } ) {
	const { summary, environment, generated_at: generatedAt } = report;
	const adapter = environment.mcp_adapter;

	const cards = [
		{
			label: __(
				'Agent-reachable abilities that can write',
				'agent-surface-auditor'
			),
			value: summary.agent_reachable_write,
			highlight: summary.agent_reachable_write > 0,
		},
		{
			label: __( 'Agent-reachable abilities', 'agent-surface-auditor' ),
			value: summary.agent_reachable,
		},
		{
			label: __( 'Registered abilities', 'agent-surface-auditor' ),
			value: summary.total_abilities,
		},
		{
			label: __( 'Exposed via core REST', 'agent-surface-auditor' ),
			value: summary.rest_exposed,
		},
		{
			label: __( 'Opted into MCP (mcp.public)', 'agent-surface-auditor' ),
			value: summary.mcp_public,
		},
		{
			label: __(
				'Undeclared read/write intent',
				'agent-surface-auditor'
			),
			value: summary.undeclared_intent,
		},
	];

	return (
		<div className="asa-summary">
			<div className="asa-summary__cards">
				{ cards.map( ( card ) => (
					<div
						key={ card.label }
						className={
							'asa-summary__card' +
							( card.highlight
								? ' asa-summary__card--highlight'
								: '' )
						}
					>
						<span className="asa-summary__value">
							{ card.value }
						</span>
						<span className="asa-summary__label">
							{ card.label }
						</span>
					</div>
				) ) }
			</div>

			<div className="asa-summary__severities">
				{ SEVERITIES.map( ( severity ) => (
					<div key={ severity } className="asa-summary__severity">
						<LevelBadge level={ severity } />
						<span className="asa-summary__severity-count">
							{ report.summary.findings_by_severity[ severity ] ||
								0 }
						</span>
					</div>
				) ) }
			</div>

			<p className="asa-summary__meta">
				{ adapter.active
					? sprintf(
							/* translators: %s: adapter version. */
							__(
								'MCP Adapter active (v%s).',
								'agent-surface-auditor'
							),
							adapter.version || '?'
					  )
					: __(
							'MCP Adapter not installed — MCP exposure shown is intended, not live.',
							'agent-surface-auditor'
					  ) }
				{ adapter.note ? ` ${ adapter.note }` : '' }{ ' ' }
				{ sprintf(
					/* translators: %s: ISO timestamp. */
					__( 'Last audited: %s.', 'agent-surface-auditor' ),
					new Date( generatedAt ).toLocaleString()
				) }
				{ report.from_cache
					? ` ${ __( '(cached)', 'agent-surface-auditor' ) }`
					: '' }
			</p>

			<p className="asa-summary__disclaimer">
				{ __(
					'Absence of findings means "no issues detected", not "safe": static analysis detects smells, it cannot prove safety.',
					'agent-surface-auditor'
				) }
			</p>

			{ Array.isArray( report.coverage ) &&
				report.coverage.length > 0 && (
					<ul className="asa-summary__coverage">
						{ report.coverage.map( ( note ) => (
							<li
								key={ note.id }
								className="asa-summary__coverage-item"
							>
								<strong>{ note.summary }</strong>{ ' ' }
								{ note.detail }
							</li>
						) ) }
					</ul>
				) }
		</div>
	);
}

/**
 * The dashboard root component.
 *
 * @param {Object} props        Component props.
 * @param {Object} props.config Runtime config (restRoot, nonce, exportUrl).
 * @return {JSX.Element} The app.
 */
export default function App( { config } ) {
	const [ report, setReport ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	const load = useCallback(
		( fresh ) => {
			setLoading( true );
			setError( null );

			apiFetch( {
				url: `${ config.restRoot }report${ fresh ? '?fresh=1' : '' }`,
			} )
				.then( ( data ) => setReport( data ) )
				.catch( ( fetchError ) =>
					setError(
						fetchError?.message ||
							__(
								'Failed to load the report.',
								'agent-surface-auditor'
							)
					)
				)
				.finally( () => setLoading( false ) );
		},
		[ config.restRoot ]
	);

	useEffect( () => {
		load( false );
	}, [ load ] );

	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}

	if ( loading || ! report ) {
		return (
			<div className="asa-loading">
				<Spinner />
				<p>
					{ __(
						'Auditing the agent surface…',
						'agent-surface-auditor'
					) }
				</p>
			</div>
		);
	}

	return (
		<div className="asa-app">
			<div className="asa-toolbar">
				<Button
					variant="primary"
					onClick={ () => load( true ) }
					disabled={ loading }
				>
					{ __( 'Re-run audit', 'agent-surface-auditor' ) }
				</Button>
				<Button
					variant="secondary"
					href={ `${ config.exportUrl }?_wpnonce=${ config.nonce }` }
				>
					{ __( 'Export JSON', 'agent-surface-auditor' ) }
				</Button>
			</div>

			<Summary report={ report } />

			<TabPanel
				className="asa-tabs"
				tabs={ [
					{
						name: 'abilities',
						title: __( 'Abilities', 'agent-surface-auditor' ),
					},
					{
						name: 'servers',
						title: __( 'MCP servers', 'agent-surface-auditor' ),
					},
				] }
			>
				{ ( tab ) =>
					tab.name === 'abilities' ? (
						<AbilitiesTable abilities={ report.abilities } />
					) : (
						<Servers
							servers={ report.servers }
							adapter={ report.environment.mcp_adapter }
						/>
					)
				}
			</TabPanel>
		</div>
	);
}
