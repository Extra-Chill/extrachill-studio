<?php
/**
 * Roadie floating chat — standalone enqueue and mount.
 *
 * Renders a viewport-level chat widget on all studio.extrachill.com pages
 * for authenticated team members. The agent is resolved by slug from the
 * Data Machine agents table — no hardcoded IDs.
 *
 * @package ExtraChillStudio
 * @since 0.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue the Roadie chat script and styles.
 *
 * Fires on wp_enqueue_scripts so the assets load on every frontend page,
 * independent of whether the Studio block is on the page.
 *
 * @return void
 */
function extrachill_studio_enqueue_roadie_chat() {
	if ( ! is_user_logged_in() ) {
		return;
	}

	if ( function_exists( 'ec_is_team_member' ) && ! ec_is_team_member() ) {
		return;
	}

	$agent = extrachill_studio_resolve_roadie_agent();
	if ( ! $agent ) {
		return;
	}

	$build_dir = EXTRACHILL_STUDIO_PLUGIN_DIR . 'build/';
	$build_url = EXTRACHILL_STUDIO_PLUGIN_URL . 'build/';
	$asset_php = $build_dir . 'roadie.asset.php';

	if ( ! file_exists( $asset_php ) ) {
		return;
	}

	$asset = require $asset_php;

	wp_enqueue_script(
		'extrachill-roadie-chat',
		$build_url . 'roadie.js',
		$asset['dependencies'] ?? array(),
		$asset['version'] ?? EXTRACHILL_STUDIO_VERSION,
		array( 'in_footer' => true )
	);

	// CSS extracted by wp-scripts from the roadie entry (includes
	// @extrachill/chat/css and the Roadie panel styles).
	if ( file_exists( $build_dir . 'roadie.css' ) ) {
		wp_enqueue_style(
			'extrachill-roadie-chat',
			$build_url . 'roadie.css',
			array(),
			$asset['version'] ?? EXTRACHILL_STUDIO_VERSION
		);
	}

	wp_localize_script(
		'extrachill-roadie-chat',
		'ecRoadieConfig',
		array(
			'agentId'          => (int) $agent['agent_id'],
			'basePath'         => '/datamachine/v1/chat',
			'agentName'        => (string) $agent['agent_name'],
			'agentDescription' => __( 'Proofread drafts, manage socials, and run platform operations.', 'extrachill-studio' ),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'extrachill_studio_enqueue_roadie_chat' );

/**
 * Render the Roadie chat mount container in wp_footer.
 *
 * Only renders if the script was successfully enqueued (agent exists,
 * user is a team member, and the built assets exist).
 *
 * @return void
 */
function extrachill_studio_render_roadie_chat() {
	if ( ! wp_script_is( 'extrachill-roadie-chat', 'enqueued' ) ) {
		return;
	}

	echo '<div data-ec-roadie-chat></div>';
}
add_action( 'wp_footer', 'extrachill_studio_render_roadie_chat', 50 );

/**
 * Resolve the Roadie agent from the Data Machine agents table by slug.
 *
 * Uses a static cache so repeated calls within the same request are free.
 * Returns null if Data Machine is not active or the agent doesn't exist.
 *
 * @return array|null Agent row (agent_id, agent_slug, agent_name, …) or null.
 */
function extrachill_studio_resolve_roadie_agent() {
	static $agent = null;
	static $resolved = false;

	if ( $resolved ) {
		return $agent;
	}

	$resolved = true;

	// Data Machine must be active.
	if ( ! class_exists( '\DataMachine\Core\Database\Agents\Agents' ) ) {
		return null;
	}

	$repo  = new \DataMachine\Core\Database\Agents\Agents();
	$agent = $repo->get_by_slug( 'roadie' );

	return $agent;
}
