<?php
/**
 * PDO/SQLite database wrapper with automatic schema creation.
 */

if ( ! defined( 'LICENSE_SERVER' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

class Database {

	private static ?PDO $pdo = null;

	public static function get(): PDO {
		if ( null === self::$pdo ) {
			$db_path = DB_PATH;
			$db_dir  = dirname( $db_path );

			if ( ! is_dir( $db_dir ) ) {
				mkdir( $db_dir, 0750, true );
			}

			self::$pdo = new PDO( 'sqlite:' . $db_path );
			self::$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
			self::$pdo->setAttribute( PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC );
			self::$pdo->exec( 'PRAGMA journal_mode=WAL;' );
			self::$pdo->exec( 'PRAGMA foreign_keys=ON;' );

			self::create_schema();
		}

		return self::$pdo;
	}

	private static function create_schema(): void {
		$db = self::$pdo;

		$db->exec( "
			CREATE TABLE IF NOT EXISTS licenses (
				id            INTEGER PRIMARY KEY AUTOINCREMENT,
				license_key   TEXT NOT NULL UNIQUE,
				key_hash      TEXT NOT NULL,
				customer_name TEXT NOT NULL,
				customer_email TEXT NOT NULL,
				status        TEXT NOT NULL DEFAULT 'active',
				activated_url TEXT DEFAULT NULL,
				notes         TEXT DEFAULT NULL,
				expires_at    TEXT DEFAULT NULL,
				created_at    TEXT NOT NULL,
				updated_at    TEXT NOT NULL
			);
		" );

		$db->exec( "CREATE INDEX IF NOT EXISTS idx_licenses_key_hash ON licenses(key_hash);" );
		$db->exec( "CREATE INDEX IF NOT EXISTS idx_licenses_status ON licenses(status);" );
		$db->exec( "CREATE INDEX IF NOT EXISTS idx_licenses_email ON licenses(customer_email);" );

		$db->exec( "
			CREATE TABLE IF NOT EXISTS license_activations (
				id             INTEGER PRIMARY KEY AUTOINCREMENT,
				license_id     INTEGER NOT NULL REFERENCES licenses(id),
				site_url       TEXT NOT NULL,
				plugin_version TEXT DEFAULT NULL,
				last_seen_at   TEXT NOT NULL,
				created_at     TEXT NOT NULL
			);
		" );

		$db->exec( "CREATE INDEX IF NOT EXISTS idx_activations_license ON license_activations(license_id);" );

		$db->exec( "
			CREATE TABLE IF NOT EXISTS audit_log (
				id         INTEGER PRIMARY KEY AUTOINCREMENT,
				license_id INTEGER REFERENCES licenses(id),
				event      TEXT NOT NULL,
				ip_address TEXT DEFAULT NULL,
				data       TEXT DEFAULT NULL,
				created_at TEXT NOT NULL
			);
		" );

		$db->exec( "
			CREATE TABLE IF NOT EXISTS rate_limits (
				ip_hash      TEXT PRIMARY KEY,
				count        INTEGER NOT NULL DEFAULT 1,
				window_start TEXT NOT NULL
			);
		" );
	}
}
