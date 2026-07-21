<?php
/**
 * ASA010 Custom_Server_Exposure rule tests.
 *
 * @package ASA
 */

use ASA\Model\Finding;
use ASA\Rules\Custom_Server_Exposure;

/**
 * @covers \ASA\Rules\Custom_Server_Exposure
 */
class Custom_Server_Exposure_Test extends ASA_TestCase {

	public function test_silent_without_custom_servers() {
		$rule = new Custom_Server_Exposure();

		$exposure = $this->exposure(
			[
				'mcp_public'         => true,
				'mcp_default_server' => true,
				'mcp_servers'        => [ 'mcp-adapter-default-server' ],
			]
		);

		$this->assertSame( [], $rule->evaluate( $this->descriptor(), $exposure ) );
	}

	public function test_flags_custom_server_listing_as_info() {
		$rule = new Custom_Server_Exposure();

		$exposure = $this->exposure(
			[
				'mcp_servers'        => [ 'acme-agent-server' ],
				'mcp_custom_servers' => [ 'acme-agent-server' ],
			]
		);

		$finding = $this->assertSingleFinding( 'ASA010', $rule->evaluate( $this->descriptor(), $exposure ) );
		$this->assertSame( Finding::SEVERITY_INFO, $finding->severity );
		$this->assertSame( Finding::CONFIDENCE_HIGH, $finding->confidence );
		$this->assertStringContainsString( 'acme-agent-server', $finding->message );
	}
}
