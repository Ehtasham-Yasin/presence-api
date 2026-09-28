<?php
/**
 * Tests for the admin bar debugger.
 *
 * @package Presence_API
 *
 * @group presence
 */

// The plugin loads this only under WP_DEBUG, which the suite does not set.
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/debugger-admin-bar.php';

class WP_Test_Presence_Debugger_Admin_Bar extends WP_Presence_UnitTestCase {

	/**
	 * @covers ::wp_presence_debugger_heartbeat_received
	 */
	public function test_heartbeat_says_nothing_to_a_subscriber() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$response = wp_presence_debugger_heartbeat_received( array(), array( 'presence-fragments' => array( 'debugger' => true ) ) );

		$this->assertSame( array(), $response );
	}

	/**
	 * @covers ::wp_presence_debugger_heartbeat_received
	 * @covers ::wp_presence_debugger_admin_bar_node
	 */
	public function test_lists_every_client_in_the_rooms_the_user_is_in() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$other = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $admin );

		wp_set_presence( 'postType/post:1', 'editor-1', array(), $admin );
		wp_set_presence( 'postType/post:1', 'gse-42', array(), $other );
		wp_set_presence( 'postType/post:2', 'gse-43', array(), $other );

		$response = wp_presence_debugger_heartbeat_received( array(), array( 'presence-fragments' => array( 'debugger' => true ) ) );
		$markup   = $response['presence-fragments']['debugger'];

		$this->assertStringContainsString( 'gse-42', $markup, 'Another client in a room the user is in should be listed.' );
		$this->assertStringNotContainsString( 'postType/post:2', $markup, 'A room the user is not in should be left out.' );
	}
}
