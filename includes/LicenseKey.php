<?php
/**
 * License key generation and lookup helpers.
 */

if ( ! defined( 'LICENSE_SERVER' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

class LicenseKey {

	// Unambiguous Base32-style alphabet (no 0/O/1/I/L)
	const ALPHABET = 'ABCDEFGHJKMNPQRSTVWXYZ23456789';
	const PREFIX   = 'STRDB';

	/**
	 * Generate a unique license key of the form STRDB-XXXX-XXXX-XXXX-XXXX.
	 * Retries automatically if a collision occurs (astronomically unlikely).
	 *
	 * @return string
	 */
	public static function generate(): string {
		$db = Database::get();

		for ( $attempt = 0; $attempt < 10; $attempt++ ) {
			$key  = self::PREFIX . '-';
			$key .= self::random_segment() . '-';
			$key .= self::random_segment() . '-';
			$key .= self::random_segment() . '-';
			$key .= self::random_segment();

			$hash = hash( 'sha256', $key );
			$stmt = $db->prepare( 'SELECT id FROM licenses WHERE key_hash = ?' );
			$stmt->execute( [ $hash ] );

			if ( ! $stmt->fetch() ) {
				return $key;
			}
		}

		throw new RuntimeException( 'Failed to generate a unique license key after 10 attempts.' );
	}

	/**
	 * Return the SHA-256 hash of a key (for DB lookups — never store raw keys in indexes).
	 */
	public static function hash( string $key ): string {
		return hash( 'sha256', strtoupper( trim( $key ) ) );
	}

	/**
	 * Generate a 4-character random segment from the custom alphabet.
	 */
	private static function random_segment(): string {
		$alphabet = self::ALPHABET;
		$len      = strlen( $alphabet );
		$segment  = '';

		for ( $i = 0; $i < 4; $i++ ) {
			$segment .= $alphabet[ random_int( 0, $len - 1 ) ];
		}

		return $segment;
	}

	/**
	 * Mask a license key for display: STRDB-ABCD-****-****-WXYZ
	 */
	public static function mask( string $key ): string {
		$parts = explode( '-', $key );
		if ( count( $parts ) !== 5 ) {
			return '****-****-****-****';
		}
		$parts[2] = '****';
		$parts[3] = '****';
		return implode( '-', $parts );
	}
}
