<?php
/**
 * License Server Configuration
 *
 * Secrets are read from environment variables set in Hostinger hPanel.
 * Never hardcode secrets in this file.
 *
 * Required environment variables:
 *   LICENSE_HMAC_SECRET      — 64-char hex, generate with: php -r "echo bin2hex(random_bytes(32));"
 *   LICENSE_ADMIN_USERNAME   — admin panel username
 *   LICENSE_ADMIN_PASSWORD_HASH — bcrypt hash, generate with: php -r "echo password_hash('yourpassword', PASSWORD_BCRYPT);"
 */

if ( ! defined( 'LICENSE_SERVER' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

// ── Database ──────────────────────────────────────────────────────────────────
define( 'DB_PATH', __DIR__ . '/data/licenses.sqlite' );

// ── HMAC Secret ───────────────────────────────────────────────────────────────
define( 'HMAC_SECRET', getenv( 'LICENSE_HMAC_SECRET' ) ?: '' );

// ── Admin Credentials ─────────────────────────────────────────────────────────
define( 'ADMIN_USERNAME', getenv( 'LICENSE_ADMIN_USERNAME' ) ?: 'admin' );
define( 'ADMIN_PASSWORD_HASH', getenv( 'LICENSE_ADMIN_PASSWORD_HASH' ) ?: '' );

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
