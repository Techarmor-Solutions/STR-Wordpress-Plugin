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
	 * Domain-separation prefix for signed messages. Must match the plugin.
	 */
	const SIGNATURE_CONTEXT = "str-license-v1\n";

	/**
	 * Request values echoed into the signed payload so a response can't be
	 * replayed against a different request or site.
	 *
	 * @var array{nonce: string, site_url: string, action: string}
	 */
	private static array $context = [ 'nonce' => '', 'site_url' => '', 'action' => '' ];

	/**
	 * Record the request values that must be bound into the signature.
	 */
	public static function set_context( string $nonce, string $site_url, string $action ): void {
		self::$context = [ 'nonce' => $nonce, 'site_url' => $site_url, 'action' => $action ];
	}

	/**
	 * Send a JSON response and exit.
	 *
	 * The response carries:
	 *  - the plain fields (read by plugin versions before Ed25519 support),
	 *  - `signed`: a JSON string of the fields plus request context and issued_at,
	 *  - `sig`: hex Ed25519 detached signature over SIGNATURE_CONTEXT . signed,
	 *  - `hmac`: legacy HMAC over everything else, for older plugin versions.
	 *
	 * @param array $data    Response data.
	 * @param int   $status  HTTP status code.
	 */
	public static function json( array $data, int $status = 200 ): never {
		if ( defined( 'SIGNING_SECRET_KEY' ) && preg_match( '/^[0-9a-f]{128}$/i', SIGNING_SECRET_KEY ) ) {
			$signed = json_encode(
				$data + self::$context + [ 'issued_at' => time() ],
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			);

			$data['signed'] = $signed;
			$data['sig']    = bin2hex(
				sodium_crypto_sign_detached( self::SIGNATURE_CONTEXT . $signed, sodium_hex2bin( SIGNING_SECRET_KEY ) )
			);
		}

		// Legacy HMAC — computed last, over every other field, which is exactly
		// what older plugin versions recompute after removing `hmac`.
		// Remove once no supported plugin version relies on it.
		if ( defined( 'HMAC_SECRET' ) && '' !== HMAC_SECRET ) {
			$data['hmac'] = hash_hmac( 'sha256', json_encode( $data, JSON_UNESCAPED_UNICODE ), HMAC_SECRET );
		}

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
