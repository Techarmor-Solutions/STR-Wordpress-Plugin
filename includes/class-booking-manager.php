<?php
/**
 * Booking Manager — core data layer for bookings.
 *
 * @package STRBooking
 */

namespace STRBooking;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstracts all booking CRUD and availability queries.
 */
class BookingManager {

	/**
	 * Valid booking statuses.
	 *
	 * @var string[]
	 */
	private const VALID_STATUSES = array( 'pending', 'confirmed', 'checked_in', 'checked_out', 'cancelled', 'refunded' );

	/**
	 * Create a new booking.
	 *
	 * @param array $data Booking data.
	 * @return int|\WP_Error New booking post ID or WP_Error.
	 */
	public function create_booking( array $data ): int|\WP_Error {
		$required = array( 'property_id', 'guest_name', 'guest_email', 'check_in', 'check_out', 'total' );
		foreach ( $required as $field ) {
			if ( empty( $data[ $field ] ) ) {
				return new \WP_Error( 'missing_field', sprintf( 'Required field missing: %s', $field ) );
			}
		}

		// Validate dates
		$check_in  = \DateTime::createFromFormat( 'Y-m-d', $data['check_in'] );
		$check_out = \DateTime::createFromFormat( 'Y-m-d', $data['check_out'] );

		if ( ! $check_in || ! $check_out || $check_out <= $check_in ) {
			return new \WP_Error( 'invalid_dates', 'Invalid check-in or check-out dates.' );
		}

		// Create post
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'str_booking',
				'post_title'  => sprintf(
					'Booking: %s — %s to %s',
					sanitize_text_field( $data['guest_name'] ),
					$data['check_in'],
					$data['check_out']
				),
				'post_status' => 'pending',
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Save meta
		$meta_map = array(
			'str_property_id'           => 'property_id',
			'str_guest_name'            => 'guest_name',
			'str_guest_email'           => 'guest_email',
			'str_guest_phone'           => 'guest_phone',
			'str_guest_count'           => 'guest_count',
			'str_check_in'              => 'check_in',
			'str_check_out'             => 'check_out',
			'str_nights'                => 'nights',
			'str_nightly_rate'          => 'nightly_rate',
			'str_subtotal'              => 'subtotal',
			'str_cleaning_fee'          => 'cleaning_fee',
			'str_security_deposit'      => 'security_deposit',
			'str_taxes'                 => 'taxes',
			'str_total'                 => 'total',
			'str_los_discount'          => 'los_discount',
			'str_stripe_payment_intent' => 'stripe_payment_intent',
			'str_stripe_transfer_group' => 'stripe_transfer_group',
			'str_special_requests'      => 'special_requests',
			'str_daily_breakdown'       => 'daily_breakdown',
		);

		foreach ( $meta_map as $meta_key => $data_key ) {
			if ( isset( $data[ $data_key ] ) ) {
				update_post_meta( $post_id, $meta_key, $data[ $data_key ] );
			}
		}

		return $post_id;
	}

	/**
	 * Get a booking by post ID.
	 *
	 * @param int $id Booking post ID.
	 * @return array|\WP_Error Booking data array or WP_Error.
	 */
	public function get_booking( int $id ): array|\WP_Error {
		$post = get_post( $id );

		if ( ! $post || 'str_booking' !== $post->post_type ) {
			return new \WP_Error( 'not_found', 'Booking not found.' );
		}

		return array(
			'id'                     => $post->ID,
			'status'                 => $post->post_status,
			'created_at'             => $post->post_date,
			'property_id'            => (int) get_post_meta( $id, 'str_property_id', true ),
			'guest_name'             => get_post_meta( $id, 'str_guest_name', true ),
			'guest_email'            => get_post_meta( $id, 'str_guest_email', true ),
			'guest_phone'            => get_post_meta( $id, 'str_guest_phone', true ),
			'guest_count'            => (int) get_post_meta( $id, 'str_guest_count', true ),
			'check_in'               => get_post_meta( $id, 'str_check_in', true ),
			'check_out'              => get_post_meta( $id, 'str_check_out', true ),
			'nights'                 => (int) get_post_meta( $id, 'str_nights', true ),
			'nightly_rate'           => (float) get_post_meta( $id, 'str_nightly_rate', true ),
			'subtotal'               => (float) get_post_meta( $id, 'str_subtotal', true ),
			'cleaning_fee'           => (float) get_post_meta( $id, 'str_cleaning_fee', true ),
			'security_deposit'       => (float) get_post_meta( $id, 'str_security_deposit', true ),
			'taxes'                  => (float) get_post_meta( $id, 'str_taxes', true ),
			'total'                  => (float) get_post_meta( $id, 'str_total', true ),
			'los_discount'           => (float) get_post_meta( $id, 'str_los_discount', true ),
			'stripe_payment_intent'  => get_post_meta( $id, 'str_stripe_payment_intent', true ),
			'stripe_charge_id'       => get_post_meta( $id, 'str_stripe_charge_id', true ),
			'stripe_transfer_group'  => get_post_meta( $id, 'str_stripe_transfer_group', true ),
			'transfers_processed'    => (bool) get_post_meta( $id, 'str_transfers_processed', true ),
			'deposit_released'       => (bool) get_post_meta( $id, 'str_deposit_released', true ),
			'special_requests'       => get_post_meta( $id, 'str_special_requests', true ),
			'daily_breakdown'        => get_post_meta( $id, 'str_daily_breakdown', true ),
		);
	}

	/**
	 * Update booking status.
	 *
	 * @param int    $id     Booking post ID.
	 * @param string $status New status.
	 * @return bool
	 */
	public function update_booking_status( int $id, string $status ): bool {
		if ( ! in_array( $status, self::VALID_STATUSES, true ) ) {
			return false;
		}

		$result = wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => $status,
			)
		);

		return ! is_wp_error( $result ) && $result > 0;
	}

	/**
	 * Get bookings for a property within a date range.
	 *
	 * @param int    $property_id Property post ID.
	 * @param string $start       Start date Y-m-d.
	 * @param string $end         End date Y-m-d.
	 * @return array
	 */
	public function get_bookings_for_property( int $property_id, string $start, string $end ): array {
		$args = array(
			'post_type'      => 'str_booking',
			'post_status'    => array( 'pending', 'confirmed', 'checked_in', 'checked_out' ),
			'posts_per_page' => -1,
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'   => 'str_property_id',
					'value' => $property_id,
					'type'  => 'NUMERIC',
				),
				array(
					'key'     => 'str_check_in',
					'value'   => $end,
					'compare' => '<',
					'type'    => 'DATE',
				),
				array(
					'key'     => 'str_check_out',
					'value'   => $start,
					'compare' => '>',
					'type'    => 'DATE',
				),
			),
		);

		$posts    = get_posts( $args );
		$bookings = array();

		foreach ( $posts as $post ) {
			$bookings[] = $this->get_booking( $post->ID );
		}

		return $bookings;
	}

	/**
	 * Check if dates are available for a property.
	 *
	 * @param int    $property_id Property post ID.
	 * @param string $checkin     Check-in date Y-m-d.
	 * @param string $checkout    Check-out date Y-m-d.
	 * @return bool True if available, false if not.
	 */
	public function check_availability( int $property_id, string $checkin, string $checkout ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'str_availability';

		// Check for any unavailable days in the date range (exclusive of checkout day)
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				WHERE property_id = %d
				AND date >= %s
				AND date < %s
				AND status != 'available'",
				$property_id,
				$checkin,
				$checkout
			)
		);

		if ( (int) $count > 0 ) {
			return false;
		}

		// Also check overlapping confirmed bookings
		$overlapping = $this->get_bookings_for_property( $property_id, $checkin, $checkout );

		return empty( $overlapping );
	}

	/**
	 * Mark dates as booked in availability table.
	 *
	 * @param int    $property_id Property ID.
	 * @param string $checkin     Check-in date.
	 * @param string $checkout    Checkout date.
	 * @param int    $booking_id  Booking post ID.
	 */
	public function mark_dates_booked( int $property_id, string $checkin, string $checkout, int $booking_id ): void {
		global $wpdb;

		$table   = $wpdb->prefix . 'str_availability';
		$current = new \DateTime( $checkin );
		$end     = new \DateTime( $checkout );

		while ( $current < $end ) {
			$date = $current->format( 'Y-m-d' );

			$wpdb->replace(
				$table,
				array(
					'property_id' => $property_id,
					'date'        => $date,
					'status'      => 'booked',
					'booking_id'  => $booking_id,
				),
				array( '%d', '%s', '%s', '%d' )
			);

			$current->modify( '+1 day' );
		}
	}

	/**
	 * Reset dates to available (e.g., on cancellation).
	 *
	 * @param int    $property_id Property ID.
	 * @param string $checkin     Check-in date.
	 * @param string $checkout    Checkout date.
	 */
	public function mark_dates_available( int $property_id, string $checkin, string $checkout ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'str_availability';

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table}
				WHERE property_id = %d
				AND date >= %s
				AND date < %s
				AND status = 'booked'",
				$property_id,
				$checkin,
				$checkout
			)
		);
	}

	/**
	 * Get dashboard metrics.
	 *
	 * @return array
	 */
	public function get_metrics(): array {
		$args_base = array(
			'post_type'      => 'str_booking',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);

		$confirmed = count(
			get_posts(
				array_merge( $args_base, array( 'post_status' => array( 'confirmed', 'checked_in', 'checked_out' ) ) )
			)
		);

		$pending = count(
			get_posts(
				array_merge( $args_base, array( 'post_status' => 'pending' ) )
			)
		);

		// Sum revenue from confirmed bookings
		$confirmed_ids = get_posts(
			array_merge( $args_base, array( 'post_status' => array( 'confirmed', 'checked_in', 'checked_out' ) ) )
		);

		$revenue = 0.0;
		foreach ( $confirmed_ids as $id ) {
			$total    = get_post_meta( $id, 'str_total', true );
			$deposit  = get_post_meta( $id, 'str_security_deposit', true );
			$revenue += (float) $total - (float) $deposit;
		}

		return array(
			'confirmed_bookings' => $confirmed,
			'pending_bookings'   => $pending,
			'total_revenue'      => round( $revenue, 2 ),
		);
	}
}
