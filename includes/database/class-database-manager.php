<?php
/**
 * Database Manager
 *
 * @package STRBooking\Database
 */

namespace STRBooking\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages database table creation and migrations.
 */
class DatabaseManager {

	/**
	 * Install or upgrade database tables.
	 */
	public static function install(): void {
		global $wpdb;

		$current_version = get_option( 'str_booking_db_version', '0' );

		if ( version_compare( $current_version, STR_BOOKING_DB_VERSION, '>=' ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		// wp_str_availability — per-day availability status
		$sql_availability = "CREATE TABLE {$wpdb->prefix}str_availability (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  property_id bigint(20) unsigned NOT NULL,
  date date NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'available',
  price_override decimal(10,2) DEFAULT NULL,
  booking_id bigint(20) unsigned DEFAULT NULL,
  block_reason varchar(100) DEFAULT NULL,
  created_at datetime DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY property_date (property_id, date),
  KEY property_status (property_id, status),
  KEY booking_id (booking_id)
) $charset_collate;";

		// wp_str_cohosts — co-host Stripe Connect relationships
		$sql_cohosts = "CREATE TABLE {$wpdb->prefix}str_cohosts (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  property_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned DEFAULT NULL,
  stripe_account_id varchar(100) DEFAULT NULL,
  display_name varchar(200) DEFAULT NULL,
  email varchar(200) DEFAULT NULL,
  split_type varchar(20) NOT NULL DEFAULT 'percentage',
  split_value decimal(10,4) NOT NULL DEFAULT '0.0000',
  is_active tinyint(1) NOT NULL DEFAULT 1,
  created_at datetime DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY property_id (property_id),
  KEY user_id (user_id)
) $charset_collate;";

		// wp_str_calendar_imports — external iCal feed subscriptions
		$sql_calendar_imports = "CREATE TABLE {$wpdb->prefix}str_calendar_imports (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  property_id bigint(20) unsigned NOT NULL,
  feed_url text NOT NULL,
  platform varchar(50) DEFAULT NULL,
  last_synced datetime DEFAULT NULL,
  sync_status varchar(20) NOT NULL DEFAULT 'pending',
  sync_message text DEFAULT NULL,
  created_at datetime DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY property_id (property_id),
  KEY sync_status (sync_status)
) $charset_collate;";

		dbDelta( $sql_availability );
		dbDelta( $sql_cohosts );
		dbDelta( $sql_calendar_imports );

		// Run any pending migrations
		self::run_migrations( $current_version );

		update_option( 'str_booking_db_version', STR_BOOKING_DB_VERSION );
	}

	/**
	 * Run numbered migration files.
	 *
	 * @param string $current_version Current DB version.
	 */
	private static function run_migrations( string $current_version ): void {
		$migrations_dir = STR_BOOKING_PLUGIN_DIR . 'includes/database/migrations/';

		if ( ! is_dir( $migrations_dir ) ) {
			return;
		}

		$files = glob( $migrations_dir . '*.php' );
		if ( ! $files ) {
			return;
		}

		sort( $files );

		foreach ( $files as $file ) {
			$migration_key = 'str_migration_' . basename( $file, '.php' );

			if ( get_option( $migration_key ) ) {
				continue;
			}

			require_once $file;
			update_option( $migration_key, time() );
		}
	}
}
