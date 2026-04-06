<?php
/**
 * JSON response helpers.
 */

if ( ! defined( 'LICENSE_SERVER' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

class Response {

	/**
	 * Send a JSON response and exit.
	 *
	 * @param array $data    Response data (HMAC will be appended automatically).
	 * @param int   $status  HTTP status code.
	 */
	public static function json( array $data, int $status = 200 ): never {
		// Append HMAC signature so the plugin can verify authenticity.
		$body          = json_encode( $data, JSON_UNESCAPED_UNICODE );
		$data['hmac']  = hash_hmac( 'sha256', $body, HMAC_SECRET );

		http_response_code( $status );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		echo json_encode( $data, JSON_UNESCAPED_UNICODE );
		exit;
	}

	/**
	 * Send a 400 error response.
	 */
	public static function error( string $message, int $status = 400 ): never {
		self::json( [ 'valid' => false, 'status' => 'error', 'message' => $message ], $status );
	}

	/**
	 * Redirect with a flash message stored in session.
	 */
	public static function redirect( string $url ): never {
		header( 'Location: ' . $url );
		exit;
	}
}
