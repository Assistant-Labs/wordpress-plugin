<?php
/**
 * Plugin Name:       AssistantLabs
 * Plugin URI:        https://assistantlabs.io/wordpress
 * Description:       Connect your site to an AssistantLabs AI agent: chat on your site, form leads in your CRM, and manage pages and posts from Claude, ChatGPT or WhatsApp. Every change waits for your approval.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            AssistantLabs
 * Author URI:        https://assistantlabs.io
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       assistantlabs
 *
 * @package AssistantLabs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ASSISTANTLABS_VERSION', '1.0.0' );
define( 'ASSISTANTLABS_FILE', __FILE__ );
define( 'ASSISTANTLABS_DIR', plugin_dir_path( __FILE__ ) );
define( 'ASSISTANTLABS_URL', plugin_dir_url( __FILE__ ) );

// Override either of these in wp-config.php (e.g. for local development).
if ( ! defined( 'ASSISTANTLABS_API_URL' ) ) {
	define( 'ASSISTANTLABS_API_URL', 'https://server-150134556021.us-central1.run.app' );
}
if ( ! defined( 'ASSISTANTLABS_APP_URL' ) ) {
	define( 'ASSISTANTLABS_APP_URL', 'https://assistantlabs.io' );
}

require_once ASSISTANTLABS_DIR . 'includes/class-assistantlabs-options.php';
require_once ASSISTANTLABS_DIR . 'includes/class-assistantlabs-signer.php';
require_once ASSISTANTLABS_DIR . 'includes/class-assistantlabs-api-client.php';
require_once ASSISTANTLABS_DIR . 'includes/class-assistantlabs-agent-user.php';
require_once ASSISTANTLABS_DIR . 'includes/class-assistantlabs-connect.php';
require_once ASSISTANTLABS_DIR . 'includes/class-assistantlabs-content.php';
require_once ASSISTANTLABS_DIR . 'includes/class-assistantlabs-seo.php';
require_once ASSISTANTLABS_DIR . 'includes/class-assistantlabs-change-handler.php';
require_once ASSISTANTLABS_DIR . 'includes/class-assistantlabs-post-change-handlers.php';
require_once ASSISTANTLABS_DIR . 'includes/class-assistantlabs-changes.php';
require_once ASSISTANTLABS_DIR . 'includes/class-assistantlabs-preview.php';
require_once ASSISTANTLABS_DIR . 'includes/class-assistantlabs-rest.php';
require_once ASSISTANTLABS_DIR . 'includes/class-assistantlabs-widget.php';
require_once ASSISTANTLABS_DIR . 'includes/class-assistantlabs-forms.php';
require_once ASSISTANTLABS_DIR . 'includes/class-assistantlabs-freshness.php';
require_once ASSISTANTLABS_DIR . 'includes/class-assistantlabs-plugin.php';

if ( is_admin() ) {
	require_once ASSISTANTLABS_DIR . 'admin/class-assistantlabs-admin.php';
}

register_activation_hook( __FILE__, array( 'AssistantLabs_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AssistantLabs_Plugin', 'deactivate' ) );

AssistantLabs_Plugin::instance();
