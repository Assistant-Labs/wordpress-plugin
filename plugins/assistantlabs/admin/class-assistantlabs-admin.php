<?php
/**
 * WP admin: menu, screens and their form handlers.
 *
 * @package AssistantLabs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Native admin screens: Home, Chat on your site, Form leads, Pending changes.
 */
class AssistantLabs_Admin {

	const CAP = 'manage_options';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_assistantlabs_save_chat', array( __CLASS__, 'save_chat' ) );
		add_action( 'admin_post_assistantlabs_save_forms', array( __CLASS__, 'save_forms' ) );
		add_action( 'admin_post_assistantlabs_change', array( __CLASS__, 'change_action' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( ASSISTANTLABS_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Menu icon (AssistantLabs ring) as a data URI.
	 *
	 * @return string
	 */
	public static function icon() {
		$svg = '<svg viewBox="0 0 36 36" xmlns="http://www.w3.org/2000/svg"><defs><linearGradient x1="50%" y1="0%" x2="50%" y2="100%" id="a"><stop stop-color="#22D3EE" offset="0%"/><stop stop-color="#EF4444" offset="43.04%"/><stop stop-color="#4F46E5" offset="100%"/></linearGradient></defs><path d="M18 0c9.941 0 18 8.059 18 18s-8.059 18-18 18S0 27.941 0 18 8.059 0 18 0m0 2C9.163 2 2 9.163 2 18s7.163 16 16 16 16-7.163 16-16S26.837 2 18 2" fill="url(#a)" fill-rule="evenodd"/><circle cx="18" cy="18" r="7" fill="url(#a)"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Count bubble markup.
	 *
	 * @param int $count Count.
	 * @return string
	 */
	private static function bubble( $count ) {
		if ( $count < 1 ) {
			return '';
		}
		return sprintf( ' <span class="awaiting-mod count-%1$d"><span class="pending-count">%1$d</span></span>', (int) $count );
	}

	/**
	 * Register the menu.
	 */
	public static function menu() {
		$pending = AssistantLabs_Changes::pending_count();

		add_menu_page(
			__( 'AssistantLabs', 'assistantlabs' ),
			__( 'AssistantLabs', 'assistantlabs' ) . self::bubble( $pending ),
			self::CAP,
			'assistantlabs',
			array( __CLASS__, 'render_home' ),
			self::icon(),
			58
		);
		add_submenu_page( 'assistantlabs', __( 'AssistantLabs', 'assistantlabs' ), __( 'Home', 'assistantlabs' ), self::CAP, 'assistantlabs', array( __CLASS__, 'render_home' ) );
		add_submenu_page( 'assistantlabs', __( 'Chat on your site', 'assistantlabs' ), __( 'Chat on your site', 'assistantlabs' ), self::CAP, 'assistantlabs-chat', array( __CLASS__, 'render_chat' ) );
		add_submenu_page( 'assistantlabs', __( 'Form leads', 'assistantlabs' ), __( 'Form leads', 'assistantlabs' ), self::CAP, 'assistantlabs-forms', array( __CLASS__, 'render_forms' ) );
		add_submenu_page( 'assistantlabs', __( 'Pending changes', 'assistantlabs' ), __( 'Pending changes', 'assistantlabs' ) . self::bubble( $pending ), self::CAP, 'assistantlabs-changes', array( __CLASS__, 'render_changes' ) );
	}

	/**
	 * CSS/JS on our screens only.
	 *
	 * @param string $hook Screen hook.
	 */
	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'assistantlabs' ) ) {
			return;
		}
		wp_enqueue_style( 'assistantlabs-admin', ASSISTANTLABS_URL . 'assets/admin.css', array(), ASSISTANTLABS_VERSION );
		wp_enqueue_script( 'assistantlabs-admin', ASSISTANTLABS_URL . 'assets/admin.js', array(), ASSISTANTLABS_VERSION, true );
		wp_localize_script(
			'assistantlabs-admin',
			'assistantlabsAdmin',
			array(
				'confirmDiscard'    => __( 'Discard this change? A draft it created goes to the trash.', 'assistantlabs' ),
				'confirmDisconnect' => __( 'Disconnect this site from AssistantLabs? Your agent will stop working with it.', 'assistantlabs' ),
				'confirmPublish'    => __( 'Publish this change on your live site now?', 'assistantlabs' ),
			)
		);
	}

	/**
	 * "Settings" link on the Plugins screen.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=assistantlabs' ) ) . '">' . esc_html__( 'Settings', 'assistantlabs' ) . '</a>' );
		return $links;
	}

	/**
	 * Die unless the user may manage the plugin.
	 */
	private static function guard() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'assistantlabs' ), 403 );
		}
	}

	/**
	 * Print the queued notice.
	 */
	public static function notices() {
		$flash = AssistantLabs_Plugin::take_flash();
		if ( ! $flash ) {
			return;
		}
		$type = in_array( $flash['type'], array( 'success', 'error', 'warning', 'info' ), true ) ? $flash['type'] : 'info';
		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $type ), esc_html( $flash['message'] ) );
	}

	/**
	 * Render a view.
	 *
	 * @param string $view File name in admin/views.
	 * @param array  $vars Variables for the view.
	 */
	private static function view( $view, $vars = array() ) {
		self::guard();
		extract( $vars ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		include ASSISTANTLABS_DIR . 'admin/views/' . $view . '.php';
	}

	/**
	 * Home.
	 */
	public static function render_home() {
		$connected = AssistantLabs_Options::is_connected();
		$status    = $connected ? AssistantLabs_Connect::status() : null;
		self::view(
			'home',
			array(
				'connected' => $connected,
				'status'    => $status,
				'pending'   => AssistantLabs_Changes::pending_count(),
			)
		);
	}

	/**
	 * Chat on your site.
	 */
	public static function render_chat() {
		self::view(
			'chat',
			array(
				'connected' => AssistantLabs_Options::is_connected(),
				'settings'  => AssistantLabs_Options::chat(),
			)
		);
	}

	/**
	 * Form leads.
	 */
	public static function render_forms() {
		self::view(
			'forms',
			array(
				'connected' => AssistantLabs_Options::is_connected(),
				'settings'  => AssistantLabs_Options::forms(),
				'plugins'   => AssistantLabs_Forms::detected(),
			)
		);
	}

	/**
	 * Pending changes.
	 */
	public static function render_changes() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only tab switch.
		$tab   = isset( $_GET['tab'] ) && 'history' === $_GET['tab'] ? 'history' : 'pending';
		$state = 'history' === $tab ? array( AssistantLabs_Changes::PUBLISHED, AssistantLabs_Changes::DISCARDED ) : array( AssistantLabs_Changes::PENDING, AssistantLabs_Changes::SCHEDULED );
		$list  = AssistantLabs_Changes::list_changes( $state, 'history' === $tab ? 50 : 100 );
		self::view(
			'changes',
			array(
				'tab'     => $tab,
				'changes' => $list['items'],
			)
		);
	}

	/**
	 * Save chat settings.
	 */
	public static function save_chat() {
		self::guard();
		check_admin_referer( 'assistantlabs_save_chat' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$lang = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : 'auto';
		$settings = array(
			'enabled'  => ! empty( $_POST['enabled'] ),
			'position' => isset( $_POST['position'] ) && 'left' === $_POST['position'] ? 'left' : 'right',
			'lang'     => in_array( $lang, array( 'auto', 'he', 'en', 'ar', 'ru', 'es', 'fr', 'de' ), true ) ? $lang : 'auto',
			'hide_on'  => isset( $_POST['hide_on'] ) ? sanitize_textarea_field( wp_unslash( $_POST['hide_on'] ) ) : '',
		);
		// phpcs:enable
		update_option( AssistantLabs_Options::CHAT, $settings, true );
		AssistantLabs_Plugin::flash( 'success', __( 'Chat settings saved.', 'assistantlabs' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=assistantlabs-chat' ) );
		exit;
	}

	/**
	 * Save form-lead settings.
	 */
	public static function save_forms() {
		self::guard();
		check_admin_referer( 'assistantlabs_save_forms' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
		update_option( AssistantLabs_Options::FORMS, array( 'enabled' => ! empty( $_POST['enabled'] ) ), true );
		AssistantLabs_Plugin::flash( 'success', __( 'Form lead settings saved.', 'assistantlabs' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=assistantlabs-forms' ) );
		exit;
	}

	/**
	 * Publish now / schedule / discard from the Pending changes screen.
	 */
	public static function change_action() {
		self::guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked right below, per change.
		$id = isset( $_POST['change_id'] ) ? absint( $_POST['change_id'] ) : 0;
		check_admin_referer( 'assistantlabs_change_' . $id );
		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$at = isset( $_POST['at'] ) ? sanitize_text_field( wp_unslash( $_POST['at'] ) ) : '';
		// phpcs:enable

		$back = admin_url( 'admin.php?page=assistantlabs-changes' );

		if ( 'publish' === $op ) {
			$result = AssistantLabs_Changes::publish( $id );
			$ok     = __( 'Published. The change is live on your site.', 'assistantlabs' );
		} elseif ( 'schedule' === $op ) {
			$timestamp = AssistantLabs_Changes::parse_time( $at );
			if ( ! $timestamp || $timestamp <= time() ) {
				AssistantLabs_Plugin::flash( 'error', __( 'Pick a date and time in the future.', 'assistantlabs' ) );
				wp_safe_redirect( $back );
				exit;
			}
			$result = AssistantLabs_Changes::publish( $id, gmdate( 'c', $timestamp ) );
			/* translators: %s: date and time. */
			$ok = sprintf( __( 'Scheduled for %s.', 'assistantlabs' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) );
		} elseif ( 'discard' === $op ) {
			$result = AssistantLabs_Changes::discard( $id );
			$ok     = __( 'Discarded.', 'assistantlabs' );
		} else {
			$result = new WP_Error( 'invalid_op', __( 'Unknown action.', 'assistantlabs' ) );
			$ok     = '';
		}

		if ( is_wp_error( $result ) ) {
			AssistantLabs_Plugin::flash( 'error', $result->get_error_message() );
		} else {
			AssistantLabs_Plugin::flash( 'success', $ok );
		}
		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Format an ISO date for people, in the site's time zone.
	 *
	 * @param string|null $iso ISO-8601.
	 * @return string
	 */
	public static function human_date( $iso ) {
		if ( ! $iso ) {
			return '—';
		}
		$ts = strtotime( $iso );
		return $ts ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) : '—';
	}

	/**
	 * Links into the AssistantLabs app.
	 *
	 * @param array|WP_Error|null $status /site/status response.
	 * @return array{dashboard:string,inbox:string,settings:string}
	 */
	public static function app_links( $status ) {
		$base  = untrailingslashit( ASSISTANTLABS_APP_URL );
		$agent = (string) get_option( AssistantLabs_Options::ASSISTANT_URL, '' );
		$links = array(
			'dashboard' => $agent ? $agent : $base . '/app',
			'inbox'     => $base . '/app/inbox',
			'settings'  => $agent ? $agent : $base . '/app',
		);
		if ( is_array( $status ) && isset( $status['links'] ) && is_array( $status['links'] ) ) {
			foreach ( array_keys( $links ) as $key ) {
				if ( ! empty( $status['links'][ $key ] ) && is_string( $status['links'][ $key ] ) ) {
					$links[ $key ] = $status['links'][ $key ];
				}
			}
		}
		return $links;
	}
}
