<?php
/**
 * Square Handler — Square Payments API via wp_remote_post.
 *
 * Handles pay-in-full charges through Square's REST API without
 * requiring a Composer dependency.
 *
 * @package STRBooking
 */

namespace STRBooking;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Integrates with Square Payments API using WordPress HTTP API.
 */
class SquareHandler {

	/**
	 * Square API access token.
	 *
	 * @var string
	 */
	private string $access_token;

	/**
	 * Square location ID.
	 *
	 * @var string
	 */
	private string $location_id;

	/**
	 * Square API base URL (sandbox or production).
	 *
	 * @var string
	 */
	private string $base_url;

	public function __construct() {
		$this->access_token = get_option( 'str_booking_square_access_token', '' );
		$this->location_id  = get_option( 'str_booking_square_location_id', '' );
		$environment        = get_option( 'str_booking_square_environment', 'sandbox' );

		$this->base_url = ( 'production' === $environment )
			? 'https://connect.squareup.com'
			: 'https://connect.squareupsandbox.com';
	}

	/**
	 * Create a Square payment for a booking.
	 *
	 * @param int    $amount_cents Amount in smallest currency unit (cents).
	 * @param string $currency     ISO 4217 currency code (e.g. 'usd').
	 * @param string $source_id    Card nonce/token from Square Web Payments SDK.
	 * @param int    $booking_id   WordPress post ID of the booking record.
	 * @return array|\WP_Error    Decoded response body on success; WP_Error on failure.
	 */
	public function create_payment( int $amount_cents, string $currency, string $source_id, int $booking_id ): array|\WP_Error {
		$body = array(
			'source_id'        => $source_id,
			'idempotency_key'  => 'str_booking_' . $booking_id,
			'amount_money'     => array(
				'amount'   => $amount_cents,
				'currency' => strtoupper( $currency ),
			),
			'location_id'      => $this->location_id,
		);

		$response = wp_remote_post(
			$this->base_url . '/v2/payments',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->access_token,
					'Content-Type'  => 'application/json',
					'Square-Version' => '2024-01-18',
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'square_request_failed',
				$response->get_error_message(),
				array( 'status' => 500 )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$raw_body    = wp_remote_retrieve_body( $response );
		$decoded     = json_decode( $raw_body, true );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$error_message = 'Square payment failed.';

			if ( ! empty( $decoded['errors'] ) && is_array( $decoded['errors'] ) ) {
				$first_error   = reset( $decoded['errors'] );
				$error_message = $first_error['detail'] ?? $first_error['category'] ?? $error_message;
			}

			return new \WP_Error(
				'square_payment_failed',
				$error_message,
				array( 'status' => 402, 'square_response' => $decoded )
			);
		}

		return $decoded;
	}
}
