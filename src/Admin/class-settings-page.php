<?php
/**
 * Admin page: Tools -> Agent Surface.
 *
 * @package ASA
 */

namespace ASA\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the "Agent Surface" admin page under the Tools menu and enqueues
 * the compiled React dashboard.
 *
 * The page itself is a thin shell: render() prints a single root element and
 * enqueue_assets() loads the @wordpress/scripts build (build/index.js +
 * build/index.asset.php deps + build/style-index.css). A small config object
 * carrying the REST root and a 'wp_rest' nonce is handed to the bundle via an
 * inline script so the dashboard can talk to the 'asa/v1' namespace.
 *
 * Assets only load on our own screen — never site-wide.
 */
class Settings_Page {

	/**
	 * Admin page slug (the ?page= value).
	 */
	const PAGE_SLUG = 'agent-surface';

	/**
	 * Capability required to view the page (kept in sync with the REST
	 * routes' default; both filterable via 'asa_capability').
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Script/style handle for the compiled dashboard bundle.
	 */
	const ASSET_HANDLE = 'asa-dashboard';

	/**
	 * The hook suffix returned by add_submenu_page(), used to gate enqueues.
	 *
	 * @var string|null
	 */
	private $hook_suffix = null;

	/**
	 * Hook the page registration into the admin.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Add the "Agent Surface" submenu under Tools.
	 *
	 * @return void
	 */
	public function add_menu() {
		$capability = apply_filters( Rest_Controller::CAPABILITY_FILTER, self::CAPABILITY );

		$this->hook_suffix = add_submenu_page(
			'tools.php',
			__( 'Agent Surface', 'agent-surface-auditor' ),
			__( 'Agent Surface', 'agent-surface-auditor' ),
			is_string( $capability ) ? $capability : self::CAPABILITY,
			self::PAGE_SLUG,
			[ $this, 'render' ]
		);
	}

	/**
	 * Render the page shell.
	 *
	 * Prints a heading and the root element the React app mounts into. All
	 * real UI is rendered client-side from the enqueued bundle.
	 *
	 * @return void
	 */
	public function render() {
		$capability = apply_filters( Rest_Controller::CAPABILITY_FILTER, self::CAPABILITY );
		if ( ! current_user_can( is_string( $capability ) ? $capability : self::CAPABILITY ) ) {
			return;
		}

		printf(
			'<div class="wrap"><h1>%s</h1><div id="asa-root"></div></div>',
			esc_html__( 'Agent Surface', 'agent-surface-auditor' )
		);
	}

	/**
	 * Enqueue the compiled dashboard assets, only on our screen.
	 *
	 * Reads the @wordpress/scripts-generated asset manifest for the
	 * dependency array and version hash, and injects the runtime config
	 * (REST root + nonce + export URL) as an inline script that runs before
	 * the bundle.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		// Only load on our own page.
		if ( null === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		$build_dir = ASA_PLUGIN_DIR . 'build/';
		$build_url = ASA_PLUGIN_URL . 'build/';

		$script_path = $build_dir . 'index.js';
		$asset_path  = $build_dir . 'index.asset.php';
		// wp-scripts emits the extracted stylesheet as style-index.css.
		$style_path = $build_dir . 'style-index.css';

		// Without a build, there is nothing to enqueue; show a notice instead.
		if ( ! file_exists( $script_path ) ) {
			add_action( 'admin_notices', [ $this, 'render_missing_build_notice' ] );
			return;
		}

		// Pull deps + version from the generated manifest when present.
		$asset = [
			'dependencies' => [ 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n', 'wp-dom-ready' ],
			'version'      => ASA_VERSION,
		];

		if ( file_exists( $asset_path ) ) {
			$manifest = include $asset_path;
			if ( is_array( $manifest ) ) {
				$asset = array_merge( $asset, $manifest );
			}
		}

		wp_enqueue_script(
			self::ASSET_HANDLE,
			$build_url . 'index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		if ( file_exists( $style_path ) ) {
			wp_enqueue_style(
				self::ASSET_HANDLE,
				$build_url . 'style-index.css',
				[ 'wp-components' ],
				$asset['version']
			);
		}

		$config = [
			'restRoot'  => esc_url_raw( rest_url( Rest_Controller::REST_NAMESPACE . '/' ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'exportUrl' => esc_url_raw( rest_url( Rest_Controller::REST_NAMESPACE . '/export' ) ),
		];

		// Hand the config to the bundle before it runs.
		wp_add_inline_script(
			self::ASSET_HANDLE,
			'window.asaAuditor = ' . wp_json_encode( $config ) . ';',
			'before'
		);

		// Allow translated strings inside the JS bundle.
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( self::ASSET_HANDLE, 'agent-surface-auditor' );
		}

		$this->maybe_enqueue_client_abilities();
	}

	/**
	 * Populate the browser-side @wordpress/abilities store, but only if core
	 * actually ships and registers its client integration module.
	 *
	 * WordPress 7.0's client-side Abilities API is delivered as script modules
	 * (`@wordpress/abilities` + `@wordpress/core-abilities`), not classic
	 * scripts. On installs where core has not yet wired the integration module
	 * up (it ships the files but registers nothing in 7.0.2), there is no store
	 * to populate — so we enqueue strictly on a registered-module check and
	 * skip silently otherwise. The dashboard's client-side panel feature-
	 * detects the result and stays hidden when the store never appears, so this
	 * never fabricates a "0 client-side abilities" reading. Read-only: enqueuing
	 * core's own module adds no auditor surface of our own.
	 *
	 * @return void
	 */
	private function maybe_enqueue_client_abilities() {
		if ( ! function_exists( 'wp_script_modules' ) || ! function_exists( 'wp_enqueue_script_module' ) ) {
			return;
		}

		$module_id = '@wordpress/core-abilities';

		try {
			$modules = wp_script_modules();

			// Only enqueue what core has actually registered; guessing an
			// unregistered id would emit a broken import. The registry is
			// private, so read it defensively and treat any failure as "absent".
			$registered = ( new \ReflectionObject( $modules ) )->getProperty( 'registered' );
			$registered->setAccessible( true );
			$ids = $registered->getValue( $modules );

			if ( is_array( $ids ) && isset( $ids[ $module_id ] ) ) {
				wp_enqueue_script_module( $module_id );
			}
		} catch ( \Throwable $e ) {
			// Fail safe: no store population, panel stays hidden. Never fatal.
			unset( $e );
		}
	}

	/**
	 * Admin notice shown when the React build is missing.
	 *
	 * @return void
	 */
	public function render_missing_build_notice() {
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'Agent Surface Auditor: the dashboard assets have not been built yet. Run "npm install && npm run build" in the plugin directory.', 'agent-surface-auditor' )
		);
	}
}
