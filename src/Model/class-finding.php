<?php
/**
 * One rule finding against one ability.
 *
 * @package ASA
 */

namespace ASA\Model;

defined( 'ABSPATH' ) || exit;

/**
 * Value object for a single finding: which rule fired, how bad it is, how
 * sure we are, and what to do about it.
 *
 * Confidence is first-class: `high` means read directly from a field or flag,
 * `medium` a reflection-based heuristic, `low` a weak signal. The absence of
 * findings is "no issues detected", never "safe".
 */
class Finding {

	const SEVERITY_CRITICAL = 'critical';
	const SEVERITY_HIGH     = 'high';
	const SEVERITY_MEDIUM   = 'medium';
	const SEVERITY_LOW      = 'low';
	const SEVERITY_INFO     = 'info';

	const CONFIDENCE_HIGH   = 'high';
	const CONFIDENCE_MEDIUM = 'medium';
	const CONFIDENCE_LOW    = 'low';

	/**
	 * Severity order, most severe first. Shared by the scorer and sorters.
	 *
	 * @var string[]
	 */
	const SEVERITY_ORDER = [
		self::SEVERITY_CRITICAL,
		self::SEVERITY_HIGH,
		self::SEVERITY_MEDIUM,
		self::SEVERITY_LOW,
		self::SEVERITY_INFO,
	];

	/**
	 * ID of the rule that produced this finding, e.g. 'ASA004'.
	 *
	 * @var string
	 */
	public $rule_id;

	/**
	 * Severity: one of the SEVERITY_* constants.
	 *
	 * @var string
	 */
	public $severity;

	/**
	 * Confidence: one of the CONFIDENCE_* constants.
	 *
	 * @var string
	 */
	public $confidence;

	/**
	 * Human-readable description of what was detected.
	 *
	 * @var string
	 */
	public $message;

	/**
	 * Actionable guidance for the ability's author or the site owner.
	 *
	 * @var string
	 */
	public $remediation;

	/**
	 * Constructor.
	 *
	 * @param string $rule_id     Rule ID, e.g. 'ASA004'.
	 * @param string $severity    One of the SEVERITY_* constants.
	 * @param string $confidence  One of the CONFIDENCE_* constants.
	 * @param string $message     What was detected.
	 * @param string $remediation What to do about it.
	 */
	public function __construct( $rule_id, $severity, $confidence, $message, $remediation ) {
		$this->rule_id     = $rule_id;
		$this->severity    = $severity;
		$this->confidence  = $confidence;
		$this->message     = $message;
		$this->remediation = $remediation;
	}

	/**
	 * Export as a JSON-serializable array.
	 *
	 * @return array<string, string> The finding as plain data.
	 */
	public function to_array() {
		return [
			'rule_id'     => $this->rule_id,
			'severity'    => $this->severity,
			'confidence'  => $this->confidence,
			'message'     => $this->message,
			'remediation' => $this->remediation,
		];
	}
}
