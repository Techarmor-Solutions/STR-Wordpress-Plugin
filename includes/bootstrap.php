<?php
/**
 * Bootstrap — loaded by every page/endpoint to initialize the environment.
 */

define( 'LICENSE_SERVER', true );

// Enforce HTTPS in production (uncomment after deploying SSL).
// if ( empty( $_SERVER['HTTPS'] ) || $_SERVER['HTTPS'] === 'off' ) {
//     header( 'Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] );
//     exit;
// }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/LicenseKey.php';
require_once __DIR__ . '/Response.php';
require_once __DIR__ . '/Auth.php';

/**
 * Rate-limit API requests per IP.
 * Returns false and sends 429 if the limit is exceeded.
 */
function check_rate_limit(): bool {
	$ip_hash = hash( 'sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown' );
	$db      = Database::get();
	$now     = time();

	$stmt = $db->prepare( 'SELECT count, window_start FROM rate_limits WHERE ip_hash = ?' );
	$stmt->execute( [ $ip_hash ] );
	$row = $stmt->fetch();

	if ( ! $row || ( $now - strtotime( $row['window_start'] ) ) > RATE_LIMIT_WINDOW ) {
		$db->prepare( 'INSERT OR REPLACE INTO rate_limits (ip_hash, count, window_start) VALUES (?, 1, ?)' )
		   ->execute( [ $ip_hash, date( 'Y-m-d H:i:s', $now ) ] );
		return true;
	}

	if ( (int) $row['count'] >= RATE_LIMIT_MAX ) {
		http_response_code( 429 );
		header( 'Content-Type: application/json' );
		echo json_encode( [ 'valid' => false, 'status' => 'rate_limited', 'message' => 'Too many requests.' ] );
		exit;
	}

	$db->prepare( 'UPDATE rate_limits SET count = count + 1 WHERE ip_hash = ?' )
	   ->execute( [ $ip_hash ] );

	return true;
}

/**
 * Parse JSON request body. Returns array or sends 400 on failure.
 */
function parse_json_body(): array {
	$raw = file_get_contents( 'php://input' );
	if ( empty( $raw ) ) {
		Response::error( 'Empty request body.' );
	}
	$data = json_decode( $raw, true );
	if ( ! is_array( $data ) ) {
		Response::error( 'Invalid JSON.' );
	}
	return $data;
}

/**
 * Write to the audit log.
 */
function audit_log( ?int $license_id, string $event, array $extra = [] ): void {
	$db = Database::get();
	$db->prepare(
		'INSERT INTO audit_log (license_id, event, ip_address, data, created_at) VALUES (?, ?, ?, ?, ?)'
	)->execute( [
		$license_id,
		$event,
		$_SERVER['REMOTE_ADDR'] ?? null,
		! empty( $extra ) ? json_encode( $extra ) : null,
		date( 'Y-m-d H:i:s' ),
	] );
}
