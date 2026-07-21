/**
 * MCP servers view: the servers the adapter exposes and which
 * abilities each carries.
 *
 * @package
 */

import { __, sprintf } from '@wordpress/i18n';
import { Notice } from '@wordpress/components';

/**
 * One component list (tools / resources / prompts) of a server.
 *
 * @param {Object} props            Component props.
 * @param {string} props.title      Section title.
 * @param {Array}  props.components [{name, ability}] entries.
 * @return {JSX.Element|null} The list, or null when empty.
 */
function ComponentList( { title, components } ) {
	if ( ! components.length ) {
		return null;
	}

	return (
		<div className="asa-server__components">
			<h4>{ title }</h4>
			<ul>
				{ components.map( ( component ) => (
					<li key={ component.name }>
						<code>{ component.name }</code>
						{ component.ability && (
							<>
								{ ' ← ' }
								<code>{ component.ability }</code>
							</>
						) }
					</li>
				) ) }
			</ul>
		</div>
	);
}

/**
 * The servers view.
 *
 * @param {Object} props         Component props.
 * @param {Array}  props.servers Server inventory from the report.
 * @param {Object} props.adapter Adapter state from the report.
 * @return {JSX.Element} The view.
 */
export default function Servers( { servers, adapter } ) {
	if ( ! adapter.active ) {
		return (
			<Notice status="info" isDismissible={ false }>
				{ __(
					'MCP Adapter not installed — showing intended exposure from meta.mcp.public only. Abilities flagged as MCP-public become live the moment the adapter is activated.',
					'agent-surface-auditor'
				) }
			</Notice>
		);
	}

	if ( ! servers.length ) {
		return (
			<Notice status="info" isDismissible={ false }>
				{ adapter.note ||
					__(
						'No MCP servers were readable in this context.',
						'agent-surface-auditor'
					) }
			</Notice>
		);
	}

	return (
		<div className="asa-servers">
			{ servers.map( ( server ) => (
				<div key={ server.id } className="asa-server">
					<h3>
						{ server.name } <code>{ server.id }</code>
						{ server.is_default && (
							<span className="asa-badge asa-badge--info">
								{ __( 'default', 'agent-surface-auditor' ) }
							</span>
						) }
					</h3>
					<p className="asa-server__meta">
						{ server.description } — <code>{ server.url }</code> (
						{ sprintf(
							/* translators: %s: server version. */
							__( 'version %s', 'agent-surface-auditor' ),
							server.version
						) }
						)
					</p>
					{ server.is_default && (
						<p className="asa-server__note">
							{ __(
								'The default server reaches every ability with meta.mcp.public = true through its execute-ability meta-tool, even though those abilities are not listed below.',
								'agent-surface-auditor'
							) }
						</p>
					) }
					<ComponentList
						title={ __( 'Tools', 'agent-surface-auditor' ) }
						components={ server.tools }
					/>
					<ComponentList
						title={ __( 'Resources', 'agent-surface-auditor' ) }
						components={ server.resources }
					/>
					<ComponentList
						title={ __( 'Prompts', 'agent-surface-auditor' ) }
						components={ server.prompts }
					/>
				</div>
			) ) }
		</div>
	);
}
