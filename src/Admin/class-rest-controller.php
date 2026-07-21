<?php
/**
 * REST API controller for the audit report.
 *
 * @package ASA
 */

namespace ASA\Admin;

use ASA\Report\Audit_Runner;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the read-only 'asa/v1' routes.
 *
 * All routes require manage_options (capability filterable via
 * 'asa_capability'). No write routes exist — the only state this plugin
 * touches is the report transient, refreshed as a side effect of ?fresh=1.
 *
 * Registering on rest_api_init also means every route callback runs after
 * the MCP Adapter has initialized (it initializes on rest_api_init priority
 * 15, before dispatch), so the server inventory read here is live.
 */
class Rest_Controller {

	/**
	 * REST namespace for all auditor routes.
	 */
	const REST_NAMESPACE = 'asa/v1';

	/**
	 * Filter controlling the required capability.
	 */
	const CAPABILITY_FILTER = 'asa_capability';

	/**
	 * Hook the route registration onto rest_api_init.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register all v1 routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$fresh_arg = [
			'fresh' => [
				'description'       => __( 'Bypass the cached report and recompute.', 'agent-surface-auditor' ),
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			],
		];

		register_rest_route(
			self::REST_NAMESPACE,
			'/report',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_report' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => $fresh_arg,
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/abilities',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_abilities' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => $fresh_arg,
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/servers',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_servers' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => $fresh_arg,
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/export',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_export' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => $fresh_arg,
			]
		);
	}

	/**
	 * Permission callback shared by every route: manage_options only.
	 *
	 * @return bool Whether the current user may read the audit.
	 */
	public function check_permission() {
		/**
		 * Filters the capability required to read the audit report.
		 *
		 * @param string $capability Defaults to 'manage_options'.
		 */
		$capability = apply_filters( self::CAPABILITY_FILTER, 'manage_options' );

		return current_user_can( is_string( $capability ) ? $capability : 'manage_options' );
	}

	/**
	 * GET /report — the full audit report.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response The report.
	 */
	public function get_report( WP_REST_Request $request ) {
		$runner = new Audit_Runner();

		return rest_ensure_response( $runner->run( (bool) $request->get_param( 'fresh' ) ) );
	}

	/**
	 * GET /abilities — projection of the report's abilities list.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response The abilities with exposure and findings.
	 */
	public function get_abilities( WP_REST_Request $request ) {
		$runner = new Audit_Runner();
		$report = $runner->run( (bool) $request->get_param( 'fresh' ) );

		return rest_ensure_response( $report['abilities'] );
	}

	/**
	 * GET /servers — projection of the report's MCP server inventory.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response The server inventory plus adapter state.
	 */
	public function get_servers( WP_REST_Request $request ) {
		$runner = new Audit_Runner();
		$report = $runner->run( (bool) $request->get_param( 'fresh' ) );

		return rest_ensure_response(
			[
				'mcp_adapter' => $report['environment']['mcp_adapter'],
				'servers'     => $report['servers'],
			]
		);
	}

	/**
	 * GET /export — the report as a downloadable JSON attachment.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response The report with an attachment disposition.
	 */
	public function get_export( WP_REST_Request $request ) {
		$runner = new Audit_Runner();
		$report = $runner->run( (bool) $request->get_param( 'fresh' ) );

		$response = new WP_REST_Response( $report );
		$response->header(
			'Content-Disposition',
			'attachment; filename="agent-surface-report-' . gmdate( 'Ymd-His' ) . '.json"'
		);

		return $response;
	}
}
