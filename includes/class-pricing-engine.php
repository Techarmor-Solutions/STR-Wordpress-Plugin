<?php
/**
 * Pricing Engine — calculates booking costs.
 *
 * @package STRBooking
 */

namespace STRBooking;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Calculates total booking price including seasonal overrides, LOS discounts, fees, and taxes.
 */
class PricingEngine {

	/**
	 * Calculate full pricing for a booking.
	 *
	 * @param int    $property_id Property post ID.
	 * @param string $checkin     Check-in date Y-m-d.
	 * @param string $checkout    Checkout date Y-m-d.
	 * @param int    $guests      Number of guests.
	 * @return array|\WP_Error Pricing breakdown or WP_Error.
	 */
	public function calculate( int $property_id, string $checkin, string $checkout, int $guests ): array|\WP_Error {
		$check_in_dt  = \DateTime::createFromFormat( 'Y-m-d', $checkin );
		$check_out_dt = \DateTime::createFromFormat( 'Y-m-d', $checkout );

		if ( ! $check_in_dt || ! $check_out_dt || $check_out_dt <= $check_in_dt ) {
			return new \WP_Error( 'invalid_dates', 'Invalid check-in or check-out dates.' );
		}

		$nights = (int) $check_in_dt->diff( $check_out_dt )->days;

		if ( $nights < 1 ) {
			return new \WP_Error( 'invalid_nights', 'Booking must be at least 1 night.' );
		}

		// Property meta
		$base_rate        = (float) get_post_meta( $property_id, 'str_nightly_rate', true );
		$cleaning_fee     = (float) get_post_meta( $property_id, 'str_cleaning_fee', true );
		$security_deposit = (float) get_post_meta( $property_id, 'str_security_deposit', true );
		$los_discounts    = get_post_meta( $property_id, 'str_los_discounts', true );

		// Tax rate: property override or global setting
		$tax_rate = (float) get_post_meta( $property_id, 'str_tax_rate', true );
		if ( ! $tax_rate ) {
			$tax_rate = (float) get_option( 'str_booking_tax_rate', 0 );
		}

		// Build daily breakdown with per-day overrides from availability table
		$daily_breakdown   = $this->get_daily_rates( $property_id, $checkin, $checkout, $base_rate );
		$nightly_subtotal  = array_sum( array_column( $daily_breakdown, 'rate' ) );
		$avg_nightly_rate  = $nights > 0 ? round( $nightly_subtotal / $nights, 2 ) : $base_rate;

		// Length-of-stay discount
		$los_discount = $this->calculate_los_discount( $nights, $los_discounts );
		$discounted_subtotal = round( $nightly_subtotal * ( 1 - $los_discount ), 2 );

		// Taxes applied on nightly subtotal after discount (not on fees/deposit)
		$taxes = round( $discounted_subtotal * $tax_rate, 2 );

		$total = round( $discounted_subtotal + $cleaning_fee + $taxes + $security_deposit, 2 );

		return array(
			'nights'            => $nights,
			'nightly_rate'      => $avg_nightly_rate,
			'nightly_subtotal'  => round( $nightly_subtotal, 2 ),
			'los_discount'      => round( $nightly_subtotal * $los_discount, 2 ),
			'los_discount_rate' => $los_discount,
			'cleaning_fee'      => $cleaning_fee,
			'security_deposit'  => $security_deposit,
			'taxes'             => $taxes,
			'tax_rate'          => $tax_rate,
			'total'             => $total,
			'daily_breakdown'   => $daily_breakdown,
		);
	}

	/**
	 * Build per-day rate array, applying price overrides from availability table.
	 *
	 * @param int    $property_id Property ID.
	 * @param string $checkin     Check-in date.
	 * @param string $checkout    Checkout date.
	 * @param float  $base_rate   Base nightly rate.
	 * @return array
	 */
	private function get_daily_rates( int $property_id, string $checkin, string $checkout, float $base_rate ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'str_availability';

		// Fetch overrides for this date range
		$overrides = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT date, price_override FROM {$table}
				WHERE property_id = %d
				AND date >= %s
				AND date < %s
				AND price_override IS NOT NULL",
				$property_id,
				$checkin,
				$checkout
			),
			ARRAY_A
		);

		$override_map = array();
		foreach ( $overrides as $row ) {
			$override_map[ $row['date'] ] = (float) $row['price_override'];
		}

		$breakdown  = array();
		$current    = new \DateTime( $checkin );
		$end        = new \DateTime( $checkout );

		while ( $current < $end ) {
			$date = $current->format( 'Y-m-d' );
			$breakdown[] = array(
				'date' => $date,
				'rate' => $override_map[ $date ] ?? $base_rate,
			);
			$current->modify( '+1 day' );
		}

		return $breakdown;
	}

	/**
	 * Calculate length-of-stay discount rate.
	 *
	 * @param int    $nights       Number of nights.
	 * @param string $los_json     JSON string of LOS discount tiers.
	 * @return float Discount rate (0.0–1.0).
	 */
	private function calculate_los_discount( int $nights, string $los_json ): float {
		if ( empty( $los_json ) ) {
			return 0.0;
		}

		$tiers = json_decode( $los_json, true );

		if ( ! is_array( $tiers ) ) {
			return 0.0;
		}

		// Sort descending by min_nights — apply highest qualifying tier
		usort( $tiers, fn( $a, $b ) => $b['min_nights'] <=> $a['min_nights'] );

		foreach ( $tiers as $tier ) {
			if ( isset( $tier['min_nights'], $tier['discount'] ) && $nights >= (int) $tier['min_nights'] ) {
				return (float) $tier['discount'];
			}
		}

		return 0.0;
	}
}
