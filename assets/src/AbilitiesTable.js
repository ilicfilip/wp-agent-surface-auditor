/**
 * The abilities table: sortable, filterable, expandable rows.
 *
 * Columns: Ability, category, Exposed?, read/write intent, permission status,
 * Risk. A row expands into the finding list (severity, confidence, message,
 * remediation) and the raw descriptor facts (schema presence, annotations,
 * callback source location).
 *
 * @package
 */

import { useState, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, SelectControl, CheckboxControl } from '@wordpress/components';

import { LevelBadge } from './App';

const RISK_ORDER = [ 'critical', 'high', 'medium', 'low', 'info', 'none' ];

/**
 * Shorten an absolute server path to a WP-root-relative one for display.
 * Absolute paths are noise (and needlessly disclose the server layout);
 * everything interesting starts at wp-includes/, wp-content/, or wp-admin/.
 *
 * @param {string} file Absolute file path from the descriptor.
 * @return {string} Shortened path.
 */
function shortPath( file ) {
	const match = /(wp-(?:includes|content|admin)\/.*)$/.exec( file || '' );
	return match
		? match[ 1 ]
		: ( file || '' ).split( '/' ).slice( -2 ).join( '/' );
}

/**
 * Describe the exposure channels of one ability, compactly.
 *
 * @param {Object} exposure The exposure record.
 * @return {string} e.g. "REST + MCP (default)".
 */
function exposureLabel( exposure ) {
	const channels = [];
	if ( exposure.rest ) {
		channels.push( __( 'REST', 'agent-surface-auditor' ) );
	}
	if ( exposure.mcp_default_server ) {
		channels.push( __( 'MCP (default)', 'agent-surface-auditor' ) );
	}
	if ( exposure.mcp_custom_servers.length > 0 ) {
		channels.push( __( 'MCP (custom)', 'agent-surface-auditor' ) );
	} else if (
		exposure.mcp_servers.length > 0 &&
		! exposure.mcp_default_server &&
		! exposure.mcp_custom_servers.length
	) {
		channels.push( __( 'MCP', 'agent-surface-auditor' ) );
	}
	if (
		! channels.length &&
		exposure.mcp_intended_only &&
		exposure.mcp_public
	) {
		channels.push( __( 'MCP (intended)', 'agent-surface-auditor' ) );
	}
	return channels.length
		? channels.join( ' + ' )
		: __( 'No', 'agent-surface-auditor' );
}

/**
 * Read/write intent cell: annotation-derived, with an unverified marker for
 * heuristic derivations.
 *
 * @param {Object} descriptor The ability descriptor.
 * @return {JSX.Element|string} Intent label.
 */
function intentLabel( descriptor ) {
	const { annotations, execute_analysis: executeAnalysis } = descriptor;

	if ( annotations.destructive === true ) {
		return __( 'destructive (declared)', 'agent-surface-auditor' );
	}
	if ( annotations.readonly === false ) {
		return __( 'writes (declared)', 'agent-surface-auditor' );
	}
	if ( annotations.readonly === true ) {
		return __( 'read-only (declared)', 'agent-surface-auditor' );
	}
	if ( executeAnalysis?.write_indicators?.length ) {
		return (
			<>
				{ __( 'writes?', 'agent-surface-auditor' ) }{ ' ' }
				<span
					className="asa-unverified"
					title={ __(
						'Derived heuristically from the callback source — unverified.',
						'agent-surface-auditor'
					) }
				>
					⚠
				</span>
			</>
		);
	}
	return __( 'undeclared', 'agent-surface-auditor' );
}

/**
 * Permission status cell, derived from findings + analysis.
 *
 * @param {Object} ability One report ability entry.
 * @return {string} One of ok / weak / auth-only / missing / unanalyzable.
 */
function permissionStatus( ability ) {
	const ruleIds = ability.findings.map( ( finding ) => finding.rule_id );

	if ( ruleIds.includes( 'ASA001' ) ) {
		return __( 'missing', 'agent-surface-auditor' );
	}
	if (
		ruleIds.includes( 'ASA002' ) ||
		ruleIds.includes( 'ASA008' ) ||
		ability.findings.some(
			( finding ) =>
				finding.rule_id === 'ASA003' && finding.severity !== 'low'
		)
	) {
		return __( 'weak', 'agent-surface-auditor' );
	}
	// A downgraded ASA003: authentication is plausibly the right gate for a
	// credibly read-only ability, so state the shape rather than judge it.
	if ( ruleIds.includes( 'ASA003' ) ) {
		return __( 'auth-only', 'agent-surface-auditor' );
	}
	if (
		ability.descriptor.callback_origin === 'unavailable' ||
		! ability.descriptor.permission_analysis?.resolved
	) {
		return __( 'unanalyzable', 'agent-surface-auditor' );
	}
	return __( 'no smells detected', 'agent-surface-auditor' );
}

/**
 * One expandable table row.
 *
 * @param {Object} props         Component props.
 * @param {Object} props.ability One report ability entry.
 * @return {JSX.Element} The row (plus detail row when expanded).
 */
function Row( { ability } ) {
	const [ expanded, setExpanded ] = useState( false );
	const { descriptor, exposure, findings, risk } = ability;

	return (
		<>
			<tr
				className={
					risk === 'critical' || risk === 'high'
						? `asa-row--${ risk }`
						: undefined
				}
			>
				<td>
					<Button
						className="asa-expander"
						icon={
							expanded ? 'arrow-down-alt2' : 'arrow-right-alt2'
						}
						label={ __(
							'Toggle details',
							'agent-surface-auditor'
						) }
						onClick={ () => setExpanded( ! expanded ) }
					/>
					<code>{ descriptor.name }</code>
				</td>
				<td>{ descriptor.category }</td>
				<td>{ exposureLabel( exposure ) }</td>
				<td>{ intentLabel( descriptor ) }</td>
				<td>{ permissionStatus( ability ) }</td>
				<td>
					<LevelBadge level={ risk } />
				</td>
			</tr>
			{ expanded && (
				<tr className="asa-detail-row">
					<td colSpan={ 6 }>
						{ findings.length === 0 && (
							<p className="asa-detail__clean">
								{ __(
									'No issues detected. (Not a guarantee of safety — see the catalog notes.)',
									'agent-surface-auditor'
								) }
							</p>
						) }
						{ findings.map( ( finding, index ) => (
							<div
								key={ index }
								className={ `asa-finding asa-finding--${ finding.severity }` }
							>
								<p className="asa-finding__head">
									<strong>{ finding.rule_id }</strong>{ ' ' }
									<LevelBadge level={ finding.severity } />{ ' ' }
									<span className="asa-confidence">
										{ __(
											'confidence:',
											'agent-surface-auditor'
										) }{ ' ' }
										{ finding.confidence }
									</span>
								</p>
								<p>{ finding.message }</p>
								<p className="asa-finding__remediation">
									<strong>
										{ __(
											'Remediation:',
											'agent-surface-auditor'
										) }
									</strong>{ ' ' }
									{ finding.remediation }
								</p>
							</div>
						) ) }
						<div className="asa-detail__facts">
							<p>
								<strong>
									{ __( 'Label:', 'agent-surface-auditor' ) }
								</strong>{ ' ' }
								{ descriptor.label } —{ ' ' }
								{ descriptor.description }
							</p>
							<p>
								<strong>
									{ __(
										'Annotations:',
										'agent-surface-auditor'
									) }
								</strong>{ ' ' }
								<code>
									{ JSON.stringify( descriptor.annotations ) }
								</code>{ ' ' }
								<strong>
									{ __(
										'Input schema:',
										'agent-surface-auditor'
									) }
								</strong>{ ' ' }
								{ Object.keys( descriptor.input_schema || {} )
									.length
									? __( 'declared', 'agent-surface-auditor' )
									: __(
											'none (no input is forwarded)',
											'agent-surface-auditor'
									  ) }
							</p>
							<p>
								<strong>
									{ __(
										'Permission callback:',
										'agent-surface-auditor'
									) }
								</strong>{ ' ' }
								<code>
									{ descriptor.permission_callback.name ||
										descriptor.permission_callback.type }
								</code>
								{ descriptor.permission_callback.file && (
									<>
										{ ' — ' }
										<code>
											{ shortPath(
												descriptor.permission_callback
													.file
											) }
											:
											{
												descriptor.permission_callback
													.line_start
											}
										</code>
									</>
								) }{ ' ' }
								<em>
									(
									{ __(
										'read via',
										'agent-surface-auditor'
									) }{ ' ' }
									{ descriptor.callback_origin })
								</em>
							</p>
						</div>
					</td>
				</tr>
			) }
		</>
	);
}

/**
 * The filterable abilities table.
 *
 * @param {Object} props           Component props.
 * @param {Array}  props.abilities Report ability entries.
 * @return {JSX.Element} The table view.
 */
export default function AbilitiesTable( { abilities } ) {
	const [ riskFilter, setRiskFilter ] = useState( 'all' );
	const [ exposedOnly, setExposedOnly ] = useState( false );
	const [ writesOnly, setWritesOnly ] = useState( false );

	const rows = useMemo( () => {
		return abilities
			.filter( ( ability ) => {
				if ( riskFilter !== 'all' && ability.risk !== riskFilter ) {
					return false;
				}
				if ( exposedOnly && ! ability.exposure.agent_reachable ) {
					return false;
				}
				if ( writesOnly ) {
					const { annotations, execute_analysis: executeAnalysis } =
						ability.descriptor;
					const writes =
						annotations.destructive === true ||
						annotations.readonly === false ||
						( executeAnalysis?.write_indicators?.length ?? 0 ) > 0;
					if ( ! writes ) {
						return false;
					}
				}
				return true;
			} )
			.sort(
				( a, b ) =>
					RISK_ORDER.indexOf( a.risk ) -
						RISK_ORDER.indexOf( b.risk ) ||
					a.descriptor.name.localeCompare( b.descriptor.name )
			);
	}, [ abilities, riskFilter, exposedOnly, writesOnly ] );

	return (
		<div className="asa-abilities">
			<div className="asa-filters">
				<SelectControl
					label={ __( 'Risk', 'agent-surface-auditor' ) }
					value={ riskFilter }
					options={ [
						{
							label: __( 'All risks', 'agent-surface-auditor' ),
							value: 'all',
						},
						...RISK_ORDER.map( ( level ) => ( {
							label: level,
							value: level,
						} ) ),
					] }
					onChange={ setRiskFilter }
					__nextHasNoMarginBottom
				/>
				<CheckboxControl
					label={ __(
						'Agent-reachable only',
						'agent-surface-auditor'
					) }
					checked={ exposedOnly }
					onChange={ setExposedOnly }
					__nextHasNoMarginBottom
				/>
				<CheckboxControl
					label={ __( 'Writes only', 'agent-surface-auditor' ) }
					checked={ writesOnly }
					onChange={ setWritesOnly }
					__nextHasNoMarginBottom
				/>
			</div>

			<table className="widefat striped asa-table">
				<thead>
					<tr>
						<th>{ __( 'Ability', 'agent-surface-auditor' ) }</th>
						<th>{ __( 'Category', 'agent-surface-auditor' ) }</th>
						<th>{ __( 'Exposed?', 'agent-surface-auditor' ) }</th>
						<th>{ __( 'Read/write', 'agent-surface-auditor' ) }</th>
						<th>{ __( 'Permission', 'agent-surface-auditor' ) }</th>
						<th>{ __( 'Risk', 'agent-surface-auditor' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ rows.map( ( ability ) => (
						<Row
							key={ ability.descriptor.name }
							ability={ ability }
						/>
					) ) }
					{ rows.length === 0 && (
						<tr>
							<td colSpan={ 6 }>
								{ __(
									'No abilities match the filters.',
									'agent-surface-auditor'
								) }
							</td>
						</tr>
					) }
				</tbody>
			</table>
		</div>
	);
}
