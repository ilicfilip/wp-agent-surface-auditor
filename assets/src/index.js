/**
 * React entry point for the Agent Surface dashboard.
 *
 * Configures @wordpress/api-fetch with the localized REST nonce, then mounts
 * <App /> into the #asa-root element printed by Settings_Page::render().
 *
 * @package
 */

import { createRoot } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import domReady from '@wordpress/dom-ready';

import App from './App';
import './style.scss';

/**
 * Runtime config injected by wp_add_inline_script (window.asaAuditor).
 *
 * @type {{restRoot: string, nonce: string, exportUrl: string}}
 */
const config = window.asaAuditor || {
	restRoot: '/wp-json/asa/v1/',
	nonce: '',
	exportUrl: '/wp-json/asa/v1/export',
};

// Authenticate api-fetch with the REST nonce. WordPress's own wp-api-fetch
// already installs a root-URL middleware; we only add the nonce.
apiFetch.use( apiFetch.createNonceMiddleware( config.nonce ) );

domReady( () => {
	const rootElement = document.getElementById( 'asa-root' );
	if ( rootElement ) {
		createRoot( rootElement ).render( <App config={ config } /> );
	}
} );
