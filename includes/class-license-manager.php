<?php
/**
 * License Manager — validates plugin license against the remote license server.
 *
 * @package STRBooking
 */

namespace STRBooking;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles license key storage, remote validation, caching, and feature gating.
 */
class LicenseManager {

	const OPTION_KEY         = 'str_booking_license_key';
	const OPTION_STATUS      = 'str_booking_license_status';
	const OPTION_LAST_CHECK  = 'str_booking_license_last_check';
	const TRANSIENT_CACHE    = 'str_license_validation_cache';
	const CRON_HOOK          = 'str_license_daily_check';
	const GRACE_PERIOD_SECS  = 72 * HOUR_IN_SECONDS;

	public function __construct() {
		add_action( self::CRON_HOOK, array( $this, 'run_daily_check' ) );
		add_action( 'admin_notices', array( $this, 'show_admin_notice' ) );
	}

	// ── Public API ───────────────────────────────────────────────────────────

	/**
	 * Single source of truth: is the current license valid?
	 */
	public function is_valid(): bool {
		$cached = $this->get_cached_status();

		if ( false !== $cached ) {
			return in_array( $cached['status'], array( 'active', 'grace' ), true );
		}

		// No cache — run a synchronous check (first load after transient expires).
		$key = $this->get_stored_key();
		if ( empty( $key ) ) {
			return false;
		}

		$result = $this->validate_with_server( $key );
		$this->store_result( $result );

		return $result['valid'];
	}

	/**
	 * Activate a license key (called on Settings save).
	 *
	 * @param string $key Raw license key.
	 * @return array { valid: bool, status: string, message: string }
	 */
	public function activate( string $key ): array {
		$key = strtoupper( sanitize_text_field( $key ) );

		if ( empty( $key ) ) {
			return array( 'valid' => false, 'status' => 'empty', 'message' => __( 'Please enter a license key.', 'str-direct-booking' ) );
		}

		// Clear any stale cached status before activating.
		delete_transient( self::TRANSIENT_CACHE );

		$result = $this->validate_with_server( $key, 'activate' );

		if ( $result['valid'] ) {
			update_option( self::OPTION_KEY, $key );
		}

		$this->store_result( $result );

		return $result;
	}

	/**
	 * Deactivate the current license (clear all stored data).
	 */
	public function deactivate(): void {
		$key = $this->get_stored_key();

		if ( ! empty( $key ) ) {
			$this->validate_with_server( $key, 'deactivate' );
		}

		delete_option( self::OPTION_KEY );
		delete_option( self::OPTION_STATUS );
		delete_option( self::OPTION_LAST_CHECK );
		delete_transient( self::TRANSIENT_CACHE );
	}

	/**
	 * Daily WP-Cron callback — re-validates and refreshes cache.
	 */
	public function run_daily_check(): void {
		$key = $this->get_stored_key();

		if ( empty( $key ) ) {
			return;
		}

		$result = $this->validate_with_server( $key );

		if ( ! $result['reachable'] ) {
			// Server unreachable — apply grace period logic.
			$last_check   = (int) get_option( self::OPTION_LAST_CHECK, 0 );
			$last_status  = get_option( self::OPTION_STATUS, 'invalid' );

			if ( 'active' === $last_status && ( time() - $last_check ) < self::GRACE_PERIOD_SECS ) {
				$this->set_cached_status( array(
					'status'     => 'grace',
					'valid'      => true,
					'message'    => __( 'License server unreachable. Operating in grace period.', 'str-direct-booking' ),
					'checked_at' => time(),
				), 6 * HOUR_IN_SECONDS );
				return;
			}

			// Grace period expired — lock features.
			$this->set_cached_status( array(
				'status'     => 'offline',
				'valid'      => false,
				'message'    => __( 'Cannot reach license server. Please check your internet connection.', 'str-direct-booking' ),
				'checked_at' => time(),
			), 6 * HOUR_IN_SECONDS );
			return;
		}

		$this->store_result( $result );
	}

	/**
	 * Show an admin notice when the license is invalid/missing (all admin pages).
	 */
	public function show_admin_notice(): void {
		if ( $this->is_valid() ) {
			return;
		}

		$key    = $this->get_stored_key();
		$cached = $this->get_cached_status();
		$status = $cached['status'] ?? ( empty( $key ) ? 'missing' : 'invalid' );

		$settings_url = admin_url( 'admin.php?page=str-booking-settings' );

		$messages = array(
			'missing'  => sprintf(
				/* translators: %s settings page link */
				__( 'STR Direct Booking requires a valid license key. <a href="%s">Enter your license key →</a>', 'str-direct-booking' ),
				esc_url( $settings_url )
			),
			'invalid'  => sprintf(
				__( 'STR Direct Booking: Invalid license key. <a href="%s">Update your license →</a>', 'str-direct-booking' ),
				esc_url( $settings_url )
			),
			'revoked'  => sprintf(
				__( 'STR Direct Booking: Your license has been revoked. <a href="%s">Contact support or update your license →</a>', 'str-direct-booking' ),
				esc_url( $settings_url )
			),
			'expired'  => sprintf(
				__( 'STR Direct Booking: Your license has expired. <a href="%s">Renew your license →</a>', 'str-direct-booking' ),
				esc_url( $settings_url )
			),
			'offline'  => sprintf(
				__( 'STR Direct Booking: Cannot reach the license server. Booking functionality is paused. <a href="%s">Settings →</a>', 'str-direct-booking' ),
				esc_url( $settings_url )
			),
		);

		$message = $messages[ $status ] ?? $messages['invalid'];
		$type    = ( 'offline' === $status ) ? 'notice-warning' : 'notice-error';

		printf(
			'<div class="notice %s"><p>%s</p></div>',
			esc_attr( $type ),
			wp_kses( $message, array( 'a' => array( 'href' => array() ) ) )
		);
	}

	/**
	 * Get the stored license key.
	 */
	public function get_stored_key(): string {
		return (string) get_option( self::OPTION_KEY, '' );
	}

	/**
	 * Get the current license status for display.
	 * Returns array with keys: status, valid, message, checked_at
	 */
	public function get_status_info(): array {
		$cached = $this->get_cached_status();

		if ( false !== $cached ) {
			return $cached;
		}

		$key = $this->get_stored_key();

		return array(
			'status'     => empty( $key ) ? 'missing' : 'unknown',
			'valid'      => false,
			'message'    => empty( $key ) ? __( 'No license key entered.', 'str-direct-booking' ) : __( 'License status unknown.', 'str-direct-booking' ),
			'checked_at' => 0,
		);
	}

	// ── Internal ─────────────────────────────────────────────────────────────

	/**
	 * Make a request to the license server.
	 *
	 * @param string $key     License key.
	 * @param string $action  'validate', 'activate', or 'deactivate'.
	 * @return array { valid, status, message, reachable, customer_name, expires_at }
	 */
	private function validate_with_server( string $key, string $action = 'validate' ): array {
		$server_url = defined( 'STR_LICENSE_SERVER_URL' ) ? STR_LICENSE_SERVER_URL : '';

		if ( empty( $server_url ) ) {
			return array(
				'valid'     => false,
				'status'    => 'config_error',
				'message'   => 'License server URL not configured.',
				'reachable' => false,
			);
		}

		$endpoint = trailingslashit( $server_url ) . ltrim( $action, '/' );
		if ( 'validate' === $action ) {
			$endpoint = trailingslashit( $server_url ) . 'validate';
		} elseif ( 'activate' === $action ) {
			$endpoint = trailingslashit( $server_url ) . 'activate';
		} elseif ( 'deactivate' === $action ) {
			$endpoint = trailingslashit( $server_url ) . 'deactivate';
		}

		$body = wp_json_encode( array(
			'license_key'     => $key,
			'site_url'        => home_url(),
			'plugin_version'  => defined( 'STR_BOOKING_VERSION' ) ? STR_BOOKING_VERSION : '0',
		) );

		$response = wp_remote_post(
			$endpoint,
			array(
				'body'        => $body,
				'headers'     => array( 'Content-Type' => 'application/json' ),
				'timeout'     => 15,
				'sslverify'   => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'valid'     => false,
				'status'    => 'unreachable',
				'message'   => $response->get_error_message(),
				'reachable' => false,
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			return array(
				'valid'     => false,
				'status'    => 'bad_response',
				'message'   => 'Invalid response from license server.',
				'reachable' => true,
			);
		}

		// Verify HMAC signature if secret is configured.
		// Use JSON_UNESCAPED_UNICODE to match the server's signing method exactly.
		if ( defined( 'STR_LICENSE_SERVER_SECRET' ) && ! empty( STR_LICENSE_SERVER_SECRET ) && isset( $data['hmac'] ) ) {
			$received_hmac = $data['hmac'];
			$payload       = $data;
			unset( $payload['hmac'] );
			$expected_hmac = hash_hmac( 'sha256', json_encode( $payload, JSON_UNESCAPED_UNICODE ), STR_LICENSE_SERVER_SECRET );

			if ( ! hash_equals( $expected_hmac, $received_hmac ) ) {
				return array(
					'valid'     => false,
					'status'    => 'tampered',
					'message'   => 'License server response signature mismatch.',
					'reachable' => true,
				);
			}
		}

		return array_merge(
			array(
				'valid'         => false,
				'status'        => 'invalid',
				'message'       => '',
				'reachable'     => true,
				'customer_name' => '',
				'expires_at'    => null,
			),
			$data
		);
	}

	/**
	 * Persist a validation result to transient + options.
	 */
	private function store_result( array $result ): void {
		$status = $result['status'] ?? ( $result['valid'] ? 'active' : 'invalid' );

		update_option( self::OPTION_STATUS, $status );

		if ( $result['valid'] ) {
			update_option( self::OPTION_LAST_CHECK, time() );
		}

		$ttl = $result['valid'] ? DAY_IN_SECONDS : ( 6 * HOUR_IN_SECONDS );
		$this->set_cached_status( array(
			'status'        => $status,
			'valid'         => (bool) $result['valid'],
			'message'       => $result['message'] ?? '',
			'customer_name' => $result['customer_name'] ?? '',
			'expires_at'    => $result['expires_at'] ?? null,
			'checked_at'    => time(),
		), $ttl );
	}

	/**
	 * Read validation result from transient.
	 *
	 * @return array|false
	 */
	private function get_cached_status() {
		return get_transient( self::TRANSIENT_CACHE );
	}

	/**
	 * Write validation result to transient.
	 *
	 * @param array $data Cache data.
	 * @param int   $ttl  Seconds until expiry.
	 */
	private function set_cached_status( array $data, int $ttl ): void {
		set_transient( self::TRANSIENT_CACHE, $data, $ttl );
	}
}
