<?php
/**
 * Co-host Manager — CRUD for co-host Stripe Connect relationships.
 *
 * @package STRBooking
 */

namespace STRBooking;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages co-host data in wp_str_cohosts table.
 */
class CohostManager {

	/**
	 * Get all co-hosts for a property.
	 *
	 * @param int $property_id Property post ID.
	 * @return array
	 */
	public function get_property_cohosts( int $property_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'str_cohosts';

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE property_id = %d AND is_active = 1 ORDER BY id ASC",
				$property_id
			),
			ARRAY_A
		);

		return $results ?: array();
	}

	/**
	 * Add a co-host to a property.
	 *
	 * @param int    $property_id      Property post ID.
	 * @param string $stripe_account_id Stripe Connect account ID.
	 * @param string $split_type       'percentage' or 'fixed'.
	 * @param float  $split_value      Split value (0.30 = 30% or dollar amount).
	 * @param array  $extras           Optional extra data (user_id, display_name, email).
	 * @return int|\WP_Error Inserted row ID or WP_Error.
	 */
	public function add_cohost( int $property_id, string $stripe_account_id, string $split_type, float $split_value, array $extras = array() ): int|\WP_Error {
		global $wpdb;

		if ( ! in_array( $split_type, array( 'percentage', 'fixed' ), true ) ) {
			return new \WP_Error( 'invalid_split_type', 'split_type must be "percentage" or "fixed".' );
		}

		if ( 'percentage' === $split_type && ( $split_value < 0 || $split_value > 1 ) ) {
			return new \WP_Error( 'invalid_split_value', 'Percentage split must be between 0 and 1.' );
		}

		$table  = $wpdb->prefix . 'str_cohosts';
		$result = $wpdb->insert(
			$table,
			array(
				'property_id'       => $property_id,
				'user_id'           => $extras['user_id'] ?? null,
				'stripe_account_id' => $stripe_account_id,
				'display_name'      => $extras['display_name'] ?? null,
				'email'             => $extras['email'] ?? null,
				'split_type'        => $split_type,
				'split_value'       => $split_value,
				'is_active'         => 1,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%f', '%d' )
		);

		if ( false === $result ) {
			return new \WP_Error( 'db_error', 'Failed to insert co-host record.' );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Remove (deactivate) a co-host.
	 *
	 * @param int $cohost_id Co-host record ID.
	 * @return bool
	 */
	public function remove_cohost( int $cohost_id ): bool {
		global $wpdb;

		$table  = $wpdb->prefix . 'str_cohosts';
		$result = $wpdb->update(
			$table,
			array( 'is_active' => 0 ),
			array( 'id' => $cohost_id ),
			array( '%d' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Calculate the transfer amount for a co-host given a booking.
	 *
	 * @param int   $booking_id Booking post ID.
	 * @param array $cohost     Co-host row from DB.
	 * @return float Amount in dollars to transfer.
	 */
	public function calculate_split( int $booking_id, array $cohost ): float {
		// Transferable amount = total - security_deposit
		$total   = (float) get_post_meta( $booking_id, 'str_total', true );
		$deposit = (float) get_post_meta( $booking_id, 'str_security_deposit', true );
		$base    = $total - $deposit;

		if ( 'percentage' === $cohost['split_type'] ) {
			return round( $base * (float) $cohost['split_value'], 2 );
		}

		// Fixed amount — cap at base amount
		return min( round( (float) $cohost['split_value'], 2 ), $base );
	}

	/**
	 * Update a co-host's Stripe account ID (used after OAuth callback).
	 *
	 * @param int    $cohost_id         Co-host record ID.
	 * @param string $stripe_account_id Stripe Connect account ID.
	 * @return bool
	 */
	public function update_stripe_account( int $cohost_id, string $stripe_account_id ): bool {
		global $wpdb;

		$table  = $wpdb->prefix . 'str_cohosts';
		$result = $wpdb->update(
			$table,
			array( 'stripe_account_id' => $stripe_account_id ),
			array( 'id' => $cohost_id ),
			array( '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}
}
