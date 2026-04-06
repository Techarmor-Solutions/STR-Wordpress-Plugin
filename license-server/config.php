<?php
/**
 * License Server Configuration
 *
 * SETUP INSTRUCTIONS:
 * 1. Copy this file to your server and fill in all values.
 * 2. Generate HMAC_SECRET with: php -r "echo bin2hex(random_bytes(32));"
 *    Then copy that same value into STR_LICENSE_SERVER_SECRET in the plugin's str-direct-booking.php.
 * 3. Generate ADMIN_PASSWORD_HASH with: php -r "echo password_hash('your_password', PASSWORD_BCRYPT);"
 * 4. Set DB_PATH to a directory outside your webroot if possible.
 * 5. Set ADMIN_IP_ALLOWLIST to your IP(s) for extra protection (optional).
 */

if ( ! defined( 'LICENSE_SERVER' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

// ── Database ──────────────────────────────────────────────────────────────────
// Path to the SQLite database file.
// Recommended: use an absolute path outside the webroot.
// Example: '/var/data/str_licenses.sqlite'
define( 'DB_PATH', __DIR__ . '/data/licenses.sqlite' );

// ── HMAC Secret ───────────────────────────────────────────────────────────────
// Must match STR_LICENSE_SERVER_SECRET in the plugin's str-direct-booking.php.
// Generate: php -r "echo bin2hex(random_bytes(32));"
define( 'HMAC_SECRET', 'REPLACE_WITH_64_CHAR_HEX' );

// ── Admin Credentials ─────────────────────────────────────────────────────────
// Generate hash: php -r "echo password_hash('your_password', PASSWORD_BCRYPT);"
define( 'ADMIN_USERNAME', 'admin' );
define( 'ADMIN_PASSWORD_HASH', 'REPLACE_WITH_BCRYPT_HASH' );

// ── Session ───────────────────────────────────────────────────────────────────
define( 'SESSION_LIFETIME', 3600 * 8 ); // 8 hours

// ── Rate Limiting ─────────────────────────────────────────────────────────────
define( 'RATE_LIMIT_MAX', 60 );     // max requests per window
define( 'RATE_LIMIT_WINDOW', 3600 ); // window in seconds (1 hour)

// ── Optional IP Allowlist for /admin ─────────────────────────────────────────
// Set to an empty array to allow all IPs, or list your IPs:
// define( 'ADMIN_IP_ALLOWLIST', [ '1.2.3.4', '5.6.7.8' ] );
define( 'ADMIN_IP_ALLOWLIST', [] );

// ── Product ───────────────────────────────────────────────────────────────────
define( 'PRODUCT_NAME', 'STR Direct Booking' );
define( 'PRODUCT_SLUG', 'str-direct-booking' );
