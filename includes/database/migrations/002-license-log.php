<?php
/**
 * Migration 002 — Create license audit log table.
 *
 * @package STRBooking\Database
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

require_once ABSPATH . 'wp-admin/includes/upgrade.php';

$charset_collate = $wpdb->get_charset_collate();

$sql = "CREATE TABLE {$wpdb->prefix}str_license_log (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event varchar(50) NOT NULL,
  key_hash varchar(64) NOT NULL,
  site_url varchar(500) DEFAULT NULL,
  server_response text DEFAULT NULL,
  created_at datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY event (event),
  KEY created_at (created_at)
) $charset_collate;";

dbDelta( $sql );
