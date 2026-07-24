/**
 * Client-side (JavaScript) abilities panel.
 *
 * WordPress 7.0's client-side Abilities API registers abilities in the browser
 * via `registerAbility()` into the `@wordpress/abilities` store — abilities the
 * PHP registry (and therefore the rest of this audit) never sees. This panel is
 * the one place we can surface them, because it runs in the same browser that
 * holds the store.
 *
 * It is strictly read-only and best-effort: it dynamically imports the store,
 * lists what it finds, and renders NOTHING when the store is absent or empty.
 * That last part matters — on installs where core has not wired up the client
 * integration module (WP 7.0.2 ships the files but registers nothing), the
 * store never populates, and a panel that rendered "0 client-side abilities"
 * would falsely imply we checked and the site is clear. Silence defers to the
 * report's standing `coverage` note, which states the honest limit. Nothing is
 * ever sent back to the server; the names never leave the browser.
 *
 * @package
 */

import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Best-effort read of the client-side abilities store.
 *
 * Resolves to an array of { name, label } (possibly empty) when the store is
 * reachable, or null when the client-side Abilities API is not present on this
 * install. Never throws.
 *
 * @return {Promise<Array<{name: string, label: string}>|null>} Abilities, or null when unavailable.
 */
async function readClientAbilities() {
	let mod;
	try {
		// `webpackIgnore` keeps this as a literal runtime import: @wordpress/
		// abilities is a WP script *module* resolved by the browser's import
		// map, not a bundled dependency. On installs without the client-side
		// API the import rejects (no import-map entry), which we treat as "not
		// available" rather than an error.
		mod = await import( /* webpackIgnore: true */ '@wordpress/abilities' );
	} catch ( e ) {
		return null;
	}

	if ( ! mod || typeof mod.getAbilities !== 'function' ) {
		return null;
	}

	try {
		const raw = mod.getAbilities();
		if ( ! Array.isArray( raw ) ) {
			return null;
		}

		return raw.map( ( ability ) => ( {
			name:
				typeof ability?.name === 'string'
					? ability.name
					: String( ability?.name ?? '' ),
			label: typeof ability?.label === 'string' ? ability.label : '',
		} ) );
	} catch ( e ) {
		return null;
	}
}

/**
 * The client-side abilities disclosure panel.
 *
 * Renders only when at least one client-side ability is actually present.
 * Absent/empty store → renders null (the report's coverage note covers that
 * case honestly).
 *
 * @return {JSX.Element|null} The panel, or null when there is nothing to show.
 */
export default function ClientAbilities() {
	const [ abilities, setAbilities ] = useState( null );

	useEffect( () => {
		let cancelled = false;

		// The store may populate slightly after mount (core-abilities fetches
		// server abilities over REST). One short retry covers that window
		// without spinning.
		const attempt = ( retriesLeft ) => {
			readClientAbilities().then( ( result ) => {
				if ( cancelled ) {
					return;
				}
				if (
					( result === null || result.length === 0 ) &&
					retriesLeft > 0
				) {
					setTimeout( () => attempt( retriesLeft - 1 ), 750 );
					return;
				}
				setAbilities( result );
			} );
		};

		attempt( 2 );

		return () => {
			cancelled = true;
		};
	}, [] );

	// Nothing found, or API not present: stay silent and let the coverage note
	// carry the disclosure. Never render a misleading "0".
	if ( ! Array.isArray( abilities ) || abilities.length === 0 ) {
		return null;
	}

	return (
		<div className="asa-client-abilities">
			<h3 className="asa-client-abilities__title">
				{ __(
					'Client-side abilities detected (not analyzed)',
					'agent-surface-auditor'
				) }{ ' ' }
				<span
					className="asa-unverified"
					role="img"
					aria-label={ __(
						'Read live from your browser, not server-audited',
						'agent-surface-auditor'
					) }
					title={ __(
						'Read live from your browser, not server-audited',
						'agent-surface-auditor'
					) }
				>
					⚠
				</span>
			</h3>
			<p className="asa-client-abilities__note">
				{ __(
					'These abilities are registered in the browser (WordPress 7.0 client-side Abilities API). They never reach the PHP registry, so none of the checks in this report apply to them. Their permission callback runs in the browser and is advisory, not a server-side access boundary — treat each as unaudited. Read live from this browser; nothing here is sent to the server.',
					'agent-surface-auditor'
				) }
			</p>
			<ul className="asa-client-abilities__list">
				{ abilities.map( ( ability ) => (
					<li key={ ability.name }>
						<code>{ ability.name }</code>
						{ ability.label ? ` — ${ ability.label }` : '' }
					</li>
				) ) }
			</ul>
		</div>
	);
}
