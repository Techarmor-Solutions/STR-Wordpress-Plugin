<?php
/**
 * POST /api/deactivate — release the domain lock so the key can be moved to another site.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
	Response::error( 'Method not allowed.', 405 );
}

check_rate_limit();

$body = parse_json_body();

$key      = strtoupper( trim( $body['license_key'] ?? '' ) );
$site_url = trim( $body['site_url'] ?? '' );

if ( empty( $key ) ) {
	Response::error( 'license_key is required.' );
}

$db       = Database::get();
$key_hash = LicenseKey::hash( $key );

$stmt = $db->prepare( 'SELECT * FROM licenses WHERE key_hash = ?' );
$stmt->execute( [ $key_hash ] );
$license = $stmt->fetch();

if ( ! $license ) {
	// Return success anyway — idempotent.
	Response::json( [ 'deactivated' => true, 'message' => 'License not found (already deactivated).' ] );
}

// Clear domain lock.
$db->prepare( "UPDATE licenses SET activated_url = NULL, updated_at = ? WHERE id = ?" )
   ->execute( [ date( 'Y-m-d H:i:s' ), $license['id'] ] );

// Remove activation record for this specific site.
if ( ! empty( $site_url ) ) {
	$db->prepare( 'DELETE FROM license_activations WHERE license_id = ? AND site_url = ?' )
	   ->execute( [ $license['id'], $site_url ] );
}

audit_log( (int) $license['id'], 'deactivated', [ 'site_url' => $site_url ] );

Response::json( [ 'deactivated' => true, 'message' => 'License deactivated.' ] );
