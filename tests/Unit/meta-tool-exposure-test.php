<?php
/**
 * ASA011 Meta_Tool_Exposure rule tests.
 *
 * @package ASA
 */

use ASA\Model\Finding;
use ASA\Rules\Meta_Tool_Exposure;

/**
 * @covers \ASA\Rules\Meta_Tool_Exposure
 */
class Meta_Tool_Exposure_Test extends ASA_TestCase {

	public function test_silent_for_ordinary_ability() {
		$rule = new Meta_Tool_Exposure();

		$exposure = $this->exposure( [ 'rest' => true ] );

		$this->assertSame( [], $rule->evaluate( $this->descriptor(), $exposure ) );
	}

	public function test_flags_reachable_execute_ability_meta_tool() {
		$rule = new Meta_Tool_Exposure();

		$descriptor = $this->descriptor( [ 'name' => 'mcp-adapter/execute-ability' ] );
		$exposure   = $this->exposure(
			[
				'mcp_public'         => true,
				'mcp_default_server' => true,
				'mcp_servers'        => [ 'mcp-adapter-default-server' ],
			]
		);

		$finding = $this->assertSingleFinding( 'ASA011', $rule->evaluate( $descriptor, $exposure ) );
		$this->assertSame( Finding::SEVERITY_INFO, $finding->severity );
		$this->assertSame( Finding::CONFIDENCE_HIGH, $finding->confidence );
		$this->assertStringContainsString( 'meta-tool', $finding->message );
		$this->assertStringContainsString( 'mcp-adapter/execute-ability', $finding->message );
	}

	public function test_flags_playground_ability_meta_tool() {
		$rule = new Meta_Tool_Exposure();

		$descriptor = $this->descriptor( [ 'name' => 'playground_ability' ] );
		$exposure   = $this->exposure( [ 'rest' => true ] );

		$this->assertSingleFinding( 'ASA011', $rule->evaluate( $descriptor, $exposure ) );
	}

	public function test_silent_when_meta_tool_not_reachable() {
		$rule = new Meta_Tool_Exposure();

		// A meta-tool that is not exposed through any channel is not a surface.
		$descriptor = $this->descriptor( [ 'name' => 'mcp-adapter/execute-ability' ] );

		$this->assertSame( [], $rule->evaluate( $descriptor, $this->exposure() ) );
	}
}
