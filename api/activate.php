<?php
/**
 * POST /api/activate — first-time activation; sets the domain lock.
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

if ( empty( $site_url ) ) {
	Response::error( 'site_url is required.' );
}

// Normalise site URL: strip trailing slash, lowercase scheme+host.
$parsed = parse_url( $site_url );
$site_domain = strtolower( ( $parsed['host'] ?? $site_url ) );

$db       = Database::get();
$key_hash = LicenseKey::hash( $key );

$stmt = $db->prepare( 'SELECT * FROM licenses WHERE key_hash = ?' );
$stmt->execute( [ $key_hash ] );
$license = $stmt->fetch();

if ( ! $license ) {
	audit_log( null, 'activate_not_found', [ 'site_url' => $site_url ] );
	Response::json( [ 'valid' => false, 'status' => 'not_found', 'message' => 'License key not found.' ], 404 );
}

// Expiry check.
if ( ! empty( $license['expires_at'] ) && strtotime( $license['expires_at'] ) < time() ) {
	audit_log( (int) $license['id'], 'activate_expired', [ 'site_url' => $site_url ] );
	Response::json( [ 'valid' => false, 'status' => 'expired', 'message' => 'License has expired.' ] );
}

if ( $license['status'] === 'revoked' ) {
	audit_log( (int) $license['id'], 'activate_revoked', [ 'site_url' => $site_url ] );
	Response::json( [ 'valid' => false, 'status' => 'revoked', 'message' => 'This license has been revoked.' ] );
}

// Domain lock — enforce one site per key.
if ( ! empty( $license['activated_url'] ) ) {
	$activated_domain = strtolower( parse_url( $license['activated_url'], PHP_URL_HOST ) ?? $license['activated_url'] );

	if ( $activated_domain !== $site_domain ) {
		audit_log( (int) $license['id'], 'activate_domain_mismatch', [
			'site_url'      => $site_url,
			'activated_url' => $license['activated_url'],
		] );
		Response::json( [
			'valid'   => false,
			'status'  => 'domain_mismatch',
			'message' => 'This license is already activated on a different domain. Contact support to transfer it.',
		] );
	}
} else {
	// First activation — record the domain.
	$db->prepare( "UPDATE licenses SET activated_url = ?, updated_at = ? WHERE id = ?" )
	   ->execute( [ $site_url, date( 'Y-m-d H:i:s' ), $license['id'] ] );
}

// Upsert activation record.
$act = $db->prepare( 'SELECT id FROM license_activations WHERE license_id = ? AND site_url = ?' );
$act->execute( [ $license['id'], $site_url ] );

if ( $act->fetch() ) {
	$db->prepare( 'UPDATE license_activations SET last_seen_at = ?, plugin_version = ? WHERE license_id = ? AND site_url = ?' )
	   ->execute( [ date( 'Y-m-d H:i:s' ), $plugin_ver ?: null, $license['id'], $site_url ] );
} else {
	$db->prepare( 'INSERT INTO license_activations (license_id, site_url, plugin_version, last_seen_at, created_at) VALUES (?, ?, ?, ?, ?)' )
	   ->execute( [ $license['id'], $site_url, $plugin_ver ?: null, date( 'Y-m-d H:i:s' ), date( 'Y-m-d H:i:s' ) ] );
}

audit_log( (int) $license['id'], 'activated', [ 'site_url' => $site_url ] );

Response::json( [
	'valid'         => true,
	'status'        => 'active',
	'activated'     => true,
	'customer_name' => $license['customer_name'],
	'expires_at'    => $license['expires_at'],
	'message'       => 'License activated successfully.',
] );
