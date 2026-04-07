<?php
/**
 * License Server Configuration
 *
 * Secrets are stored in secrets.php on the server (never in git).
 * Copy secrets.example.php to secrets.php and fill in the values.
 */

if ( ! defined( 'LICENSE_SERVER' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

// Load secrets from outside the webroot (not touched by Git deployments)
$secrets_file = dirname( __DIR__ ) . '/secrets.php';
if ( file_exists( $secrets_file ) ) {
	require_once $secrets_file;
}

// ── Database ──────────────────────────────────────────────────────────────────
// Stored outside public_html so Git deployments never wipe it.
define( 'DB_PATH', dirname( __DIR__ ) . '/license-data/licenses.sqlite' );

// ── HMAC Secret ───────────────────────────────────────────────────────────────
if ( ! defined( 'HMAC_SECRET' ) ) {
	define( 'HMAC_SECRET', '' );
}

// ── Admin Credentials ─────────────────────────────────────────────────────────
if ( ! defined( 'ADMIN_USERNAME' ) ) {
	define( 'ADMIN_USERNAME', 'admin' );
}
if ( ! defined( 'ADMIN_PASSWORD_HASH' ) ) {
	define( 'ADMIN_PASSWORD_HASH', '' );
}

// ── Session ───────────────────────────────────────────────────────────────────
define( 'SESSION_LIFETIME', 3600 * 8 ); // 8 hours

// ── Rate Limiting ─────────────────────────────────────────────────────────────
define( 'RATE_LIMIT_MAX', 60 );     // max requests per window
define( 'RATE_LIMIT_WINDOW', 3600 ); // window in seconds (1 hour)

// ── Optional IP Allowlist for /admin ─────────────────────────────────────────
define( 'ADMIN_IP_ALLOWLIST', [] );

// ── Product ───────────────────────────────────────────────────────────────────
define( 'PRODUCT_NAME', 'STR Direct Booking' );
define( 'PRODUCT_SLUG', 'str-direct-booking' );
