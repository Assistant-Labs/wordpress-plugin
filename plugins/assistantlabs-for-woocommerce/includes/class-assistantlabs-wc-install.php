<?php
/**
 * The add-on's two tables: captured carts and the store-event outbox.
 *
 * @package AssistantLabs_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and upgrades the tables with dbDelta.
 */
class AssistantLabs_WC_Install {

	const DB_VERSION        = '1';
	const OPTION_DB_VERSION = 'assistantlabs_wc_db_version';

	/**
	 * Captured carts table name.
	 *
	 * @return string
	 */
	public static function carts_table() {
		global $wpdb;
		return $wpdb->prefix . 'assistantlabs_carts';
	}

	/**
	 * Event outbox table name.
	 *
	 * @return string
	 */
	public static function events_table() {
		global $wpdb;
		return $wpdb->prefix . 'assistantlabs_events';
	}

	/**
	 * Create the tables when they are missing or older than this version.
	 * Runs on activation and, cheaply, on every load (an update does not
	 * re-run the activation hook).
	 */
	public static function maybe_install() {
		if ( self::DB_VERSION === get_option( self::OPTION_DB_VERSION ) ) {
			return;
		}
		self::install();
	}

	/**
	 * Run dbDelta.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $wpdb->get_charset_collate();
		$carts   = self::carts_table();
		$events  = self::events_table();

		dbDelta(
			"CREATE TABLE {$carts} (
	token char(32) NOT NULL,
	email varchar(100) NOT NULL DEFAULT '',
	phone varchar(32) NOT NULL DEFAULT '',
	cart longtext NOT NULL,
	hash char(32) NOT NULL DEFAULT '',
	sent_at datetime NULL DEFAULT NULL,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (token),
	KEY email (email),
	KEY updated_at (updated_at)
) {$collate};
CREATE TABLE {$events} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	topic varchar(32) NOT NULL,
	dedupe varchar(64) NOT NULL DEFAULT '',
	payload longtext NOT NULL,
	attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
	created_at datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY dedupe (dedupe),
	KEY created_at (created_at)
) {$collate};"
		);
		update_option( self::OPTION_DB_VERSION, self::DB_VERSION, true );
	}
}
