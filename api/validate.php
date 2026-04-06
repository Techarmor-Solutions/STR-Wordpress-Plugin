<?php
/**
 * POST /api/validate — check if a license key is active.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
	Response::error( 'Method not allowed.', 405 );
}

check_rate_limit();

$body = parse_json_body();

$key        = strtoupper( trim( $body['license_key'] ?? '' ) );
$site_url   = trim( $body['site_url'] ?? '' );
$plugin_ver = trim( $body['plugin_version'] ?? '' );

if ( empty( $key ) ) {
	Response::error( 'license_key is required.' );
}

$db       = Database::get();
$key_hash = LicenseKey::hash( $key );

$stmt = $db->prepare( 'SELECT * FROM licenses WHERE key_hash = ?' );
$stmt->execute( [ $key_hash ] );
$license = $stmt->fetch();

if ( ! $license ) {
	audit_log( null, 'validate_not_found', [ 'site_url' => $site_url ] );
	Response::json( [ 'valid' => false, 'status' => 'not_found', 'message' => 'License key not found.' ], 404 );
}

// Check expiry.
if ( ! empty( $license['expires_at'] ) && strtotime( $license['expires_at'] ) < time() ) {
	if ( $license['status'] !== 'expired' ) {
		$db->prepare( "UPDATE licenses SET status='expired', updated_at=? WHERE id=?" )
		   ->execute( [ date( 'Y-m-d H:i:s' ), $license['id'] ] );
		$license['status'] = 'expired';
	}
	audit_log( (int) $license['id'], 'validate_expired', [ 'site_url' => $site_url ] );
	Response::json( [ 'valid' => false, 'status' => 'expired', 'message' => 'License has expired.' ] );
}

if ( $license['status'] === 'revoked' ) {
	audit_log( (int) $license['id'], 'validate_revoked', [ 'site_url' => $site_url ] );
	Response::json( [ 'valid' => false, 'status' => 'revoked', 'message' => 'This license has been revoked.' ] );
}

if ( $license['status'] !== 'active' ) {
	Response::json( [ 'valid' => false, 'status' => $license['status'], 'message' => 'License is not active.' ] );
}

// Update last_seen in activations table.
if ( ! empty( $site_url ) ) {
	$act = $db->prepare( 'SELECT id FROM license_activations WHERE license_id = ? AND site_url = ?' );
	$act->execute( [ $license['id'], $site_url ] );

	if ( $act->fetch() ) {
		$db->prepare( 'UPDATE license_activations SET last_seen_at = ?, plugin_version = ? WHERE license_id = ? AND site_url = ?' )
		   ->execute( [ date( 'Y-m-d H:i:s' ), $plugin_ver ?: null, $license['id'], $site_url ] );
	}
}

audit_log( (int) $license['id'], 'validate_ok', [ 'site_url' => $site_url ] );

Response::json( [
	'valid'         => true,
	'status'        => 'active',
	'customer_name' => $license['customer_name'],
	'expires_at'    => $license['expires_at'],
	'message'       => 'License is active.',
] );
