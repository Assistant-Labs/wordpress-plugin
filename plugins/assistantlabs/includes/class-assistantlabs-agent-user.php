<?php
/**
 * The dedicated "AssistantLabs" WordPress user every server call runs as.
 *
 * @package AssistantLabs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and looks up the agent user. Nobody can sign in as it.
 */
class AssistantLabs_Agent_User {

	const META_FLAG = '_assistantlabs_agent_user';

	/**
	 * Hooks: block interactive sign-in for the agent user.
	 */
	public static function init() {
		add_filter( 'wp_authenticate_user', array( __CLASS__, 'block_login' ), 10, 1 );
		add_filter( 'allow_password_reset', array( __CLASS__, 'block_password_reset' ), 10, 2 );
		add_filter( 'wp_is_application_passwords_available_for_user', array( __CLASS__, 'block_application_passwords' ), 10, 2 );
	}

	/**
	 * The agent user's id, or 0.
	 *
	 * @return int
	 */
	public static function id() {
		$id = (int) get_option( AssistantLabs_Options::USER_ID, 0 );
		if ( $id && get_userdata( $id ) ) {
			return $id;
		}
		return 0;
	}

	/**
	 * The role the agent user should have right now.
	 *
	 * @return string
	 */
	public static function desired_role() {
		if ( class_exists( 'WooCommerce' ) && get_role( 'shop_manager' ) ) {
			return 'shop_manager';
		}
		return 'editor';
	}

	/**
	 * Create the user if missing and make sure its role is current.
	 *
	 * @return int|WP_Error User id.
	 */
	public static function ensure() {
		$role = self::desired_role();
		$id   = self::id();

		if ( ! $id ) {
			// Reuse a user we created earlier (e.g. after a reinstall).
			$found = get_users(
				array(
					'meta_key'   => self::META_FLAG, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'number'     => 1,
					'fields'     => 'ID',
				)
			);
			$id = $found ? (int) $found[0] : 0;
		}

		if ( ! $id ) {
			$login = 'assistantlabs';
			if ( username_exists( $login ) ) {
				$login = 'assistantlabs-agent-' . strtolower( wp_generate_password( 4, false ) );
			}
			$email = 'wordpress-agent+' . substr( AssistantLabs_Options::site_id(), 0, 8 ) . '@assistantlabs.invalid';
			$id    = wp_insert_user(
				array(
					'user_login'   => $login,
					'user_pass'    => wp_generate_password( 64, true, true ),
					'user_email'   => $email,
					'display_name' => 'AssistantLabs',
					'first_name'   => 'AssistantLabs',
					'nickname'     => 'AssistantLabs',
					'role'         => $role,
					'description'  => __( 'Changes made by your AssistantLabs agent. Nobody can sign in as this user.', 'assistantlabs' ),
				)
			);
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			update_user_meta( $id, self::META_FLAG, '1' );
		}

		$user = get_userdata( $id );
		if ( $user && ! in_array( $role, (array) $user->roles, true ) ) {
			$user->set_role( $role );
		}
		update_option( AssistantLabs_Options::USER_ID, (int) $id, true );
		return (int) $id;
	}

	/**
	 * Is this the agent user?
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public static function is_agent( $user_id ) {
		return $user_id && '1' === get_user_meta( (int) $user_id, self::META_FLAG, true );
	}

	/**
	 * Run a callback as the agent user, then restore the previous user.
	 *
	 * @param callable $callback Callback.
	 * @return mixed Callback's return value.
	 */
	public static function run_as( $callback ) {
		$previous = get_current_user_id();
		$agent    = self::id();
		if ( ! $agent ) {
			$agent = self::ensure();
			if ( is_wp_error( $agent ) ) {
				return $agent;
			}
		}
		wp_set_current_user( $agent );
		try {
			return call_user_func( $callback );
		} finally {
			wp_set_current_user( $previous );
		}
	}

	/**
	 * Refuse password sign-in for the agent user.
	 *
	 * @param WP_User|WP_Error $user User being authenticated.
	 * @return WP_User|WP_Error
	 */
	public static function block_login( $user ) {
		if ( $user instanceof WP_User && self::is_agent( $user->ID ) ) {
			return new WP_Error( 'assistantlabs_agent_user', __( 'The AssistantLabs user cannot sign in.', 'assistantlabs' ) );
		}
		return $user;
	}

	/**
	 * No Application Passwords for the agent user (that path skips wp_authenticate_user).
	 *
	 * @param bool    $available Whether they are available.
	 * @param WP_User $user      User.
	 * @return bool
	 */
	public static function block_application_passwords( $available, $user ) {
		return $user instanceof WP_User && self::is_agent( $user->ID ) ? false : $available;
	}

	/**
	 * Refuse password resets for the agent user.
	 *
	 * @param bool $allow   Whether to allow.
	 * @param int  $user_id User id.
	 * @return bool
	 */
	public static function block_password_reset( $allow, $user_id ) {
		return self::is_agent( $user_id ) ? false : $allow;
	}
}
