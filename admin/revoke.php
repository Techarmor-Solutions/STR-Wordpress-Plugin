<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::require_login();

if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
	Response::redirect( 'licenses.php' );
}

if ( ! Auth::verify_csrf( $_POST['_csrf'] ?? '' ) ) {
	Auth::set_flash( 'error', 'Invalid CSRF token.' );
	Response::redirect( 'licenses.php' );
}

$license_id = (int) ( $_POST['license_id'] ?? 0 );
$action     = $_POST['action'] ?? '';

if ( ! $license_id || ! in_array( $action, [ 'revoke', 'restore' ], true ) ) {
	Auth::set_flash( 'error', 'Invalid request.' );
	Response::redirect( 'licenses.php' );
}

$db   = Database::get();
$stmt = $db->prepare( 'SELECT id, status, customer_name FROM licenses WHERE id = ?' );
$stmt->execute( [ $license_id ] );
$license = $stmt->fetch();

if ( ! $license ) {
	Auth::set_flash( 'error', 'License not found.' );
	Response::redirect( 'licenses.php' );
}

if ( $action === 'revoke' ) {
	$db->prepare( "UPDATE licenses SET status='revoked', updated_at=? WHERE id=?" )
	   ->execute( [ date( 'Y-m-d H:i:s' ), $license_id ] );
	audit_log( $license_id, 'revoked_by_admin' );
	Auth::set_flash( 'success', 'License revoked for ' . $license['customer_name'] . '. Their site will be locked on the next daily check (within 24 hours).' );
} else {
	$db->prepare( "UPDATE licenses SET status='active', updated_at=? WHERE id=?" )
	   ->execute( [ date( 'Y-m-d H:i:s' ), $license_id ] );
	audit_log( $license_id, 'restored_by_admin' );
	Auth::set_flash( 'success', 'License restored for ' . $license['customer_name'] . '.' );
}

$redirect = $_POST['_redirect'] ?? 'licenses.php';
if ( ! preg_match( '/^[a-z0-9_\-\.]+\.php(\?[a-z0-9=&%_\-\.]*)?$/i', $redirect ) ) {
	$redirect = 'licenses.php';
}
Response::redirect( $redirect );
