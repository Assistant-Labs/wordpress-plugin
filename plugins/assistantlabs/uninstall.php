<?php
/**
 * Uninstall: remove options, transients, scheduled publishes and change records.
 *
 * The AssistantLabs WordPress user is kept on purpose: revisions and drafts it
 * wrote stay attributed to it. Its role is removed, so it can no longer edit
 * anything.
 *
 * @package AssistantLabs
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Keep the user for attribution, but take away everything it could do.
$assistantlabs_agent_user_id = (int) get_option( 'assistantlabs_user_id', 0 );
if ( $assistantlabs_agent_user_id && get_userdata( $assistantlabs_agent_user_id ) ) {
	$assistantlabs_agent_user = new WP_User( $assistantlabs_agent_user_id );
	$assistantlabs_agent_user->set_role( '' );
}

$assistantlabs_options = array(
	'assistantlabs_site_id',
	'assistantlabs_site_secret',
	'assistantlabs_assistant_id',
	'assistantlabs_assistant_name',
	'assistantlabs_assistant_url',
	'assistantlabs_workspace_name',
	'assistantlabs_connect_mode',
	'assistantlabs_connected_at',
	'assistantlabs_user_id',
	'assistantlabs_site_changed_at',
	'assistantlabs_knowledge_requested_at',
	'assistantlabs_chat',
	'assistantlabs_forms',
);
foreach ( $assistantlabs_options as $assistantlabs_option ) {
	delete_option( $assistantlabs_option );
}

// Transients: PKCE state, status cache, admin notices.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_assistantlabs_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_assistantlabs_' ) . '%'
	)
);

// Change records (our own bookkeeping; the drafts they point to are left alone).
$assistantlabs_changes = get_posts(
	array(
		'post_type'      => 'al_change',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
foreach ( $assistantlabs_changes as $assistantlabs_change_id ) {
	wp_clear_scheduled_hook( 'assistantlabs_publish_change', array( (int) $assistantlabs_change_id ) );
	wp_delete_post( $assistantlabs_change_id, true );
}
