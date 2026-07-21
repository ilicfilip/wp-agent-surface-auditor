<?php
/**
 * Normalized, JSON-safe snapshot of one registered Ability.
 *
 * @package ASA
 */

namespace ASA\Model;

defined( 'ABSPATH' ) || exit;

/**
 * Value object describing a registered Ability.
 *
 * Deliberately holds no live callables (the auditor never executes an
 * ability). Callbacks are represented by the JSON-safe descriptions produced
 * by Analysis\Callback_Inspector, which carry the source file/line span the
 * heuristic rules read.
 */
class Ability_Descriptor {

	/**
	 * Ability name including namespace, e.g. `my-plugin/my-ability`.
	 *
	 * @var string
	 */
	public $name = '';

	/**
	 * Human-readable label.
	 *
	 * @var string
	 */
	public $label = '';

	/**
	 * Ability description.
	 *
	 * @var string
	 */
	public $description = '';

	/**
	 * Category slug.
	 *
	 * @var string
	 */
	public $category = '';

	/**
	 * JSON Schema for the input, empty array when absent.
	 *
	 * @var array<string, mixed>
	 */
	public $input_schema = [];

	/**
	 * JSON Schema for the output, empty array when absent.
	 *
	 * @var array<string, mixed>
	 */
	public $output_schema = [];

	/**
	 * Full meta array as reported by WP_Ability::get_meta().
	 *
	 * @var array<string, mixed>
	 */
	public $meta = [];

	/**
	 * Normalized annotations: readonly / destructive / idempotent, each
	 * bool or null (null = undeclared).
	 *
	 * @var array<string, bool|null>
	 */
	public $annotations = [
		'readonly'    => null,
		'destructive' => null,
		'idempotent'  => null,
	];

	/**
	 * Whether meta.show_in_rest is true (core wp-abilities/v1 run endpoint).
	 *
	 * @var bool
	 */
	public $show_in_rest = false;

	/**
	 * Whether meta.mcp.public is true (MCP Adapter default-server opt-in).
	 *
	 * @var bool
	 */
	public $mcp_public = false;

	/**
	 * MCP component type from meta.mcp.type: 'tool' (default), 'resource'
	 * or 'prompt'.
	 *
	 * @var string
	 */
	public $mcp_type = 'tool';

	/**
	 * Concrete PHP class of the ability instance (WP_Ability or a subclass
	 * registered via the ability_class arg).
	 *
	 * @var string
	 */
	public $class_name = '';

	/**
	 * JSON-safe description of the permission callback.
	 *
	 * @var array<string, mixed>
	 */
	public $permission_callback = [];

	/**
	 * JSON-safe description of the execute callback.
	 *
	 * @var array<string, mixed>
	 */
	public $execute_callback = [];

	/**
	 * How the callbacks were obtained: 'filter' (wp_register_ability_args
	 * capture), 'reflection' (protected-property fallback), or 'unavailable'.
	 *
	 * @var string
	 */
	public $callback_origin = 'unavailable';

	/**
	 * Heuristic signals from the permission callback's source
	 * (Callback_Inspector::analyze()).
	 *
	 * @var array<string, mixed>
	 */
	public $permission_analysis = [ 'resolved' => false ];

	/**
	 * Heuristic signals from the execute callback's source
	 * (Callback_Inspector::analyze()).
	 *
	 * @var array<string, mixed>
	 */
	public $execute_analysis = [ 'resolved' => false ];

	/**
	 * Non-fatal problems encountered while reading this ability. A non-empty
	 * list means parts of the descriptor may be incomplete.
	 *
	 * @var string[]
	 */
	public $analysis_errors = [];

	/**
	 * Export as a JSON-serializable array.
	 *
	 * @return array<string, mixed> The descriptor as plain data.
	 */
	public function to_array() {
		return [
			'name'                => $this->name,
			'label'               => $this->label,
			'description'         => $this->description,
			'category'            => $this->category,
			'input_schema'        => $this->input_schema,
			'output_schema'       => $this->output_schema,
			'meta'                => $this->meta,
			'annotations'         => $this->annotations,
			'show_in_rest'        => $this->show_in_rest,
			'mcp_public'          => $this->mcp_public,
			'mcp_type'            => $this->mcp_type,
			'class_name'          => $this->class_name,
			'permission_callback' => $this->permission_callback,
			'execute_callback'    => $this->execute_callback,
			'callback_origin'     => $this->callback_origin,
			'permission_analysis' => $this->permission_analysis,
			'execute_analysis'    => $this->execute_analysis,
			'analysis_errors'     => $this->analysis_errors,
		];
	}
}
