<?php
/**
 * Payment Plan Manager — installment scheduling and off-session charging.
 *
 * Supports Pay-in-Full, 2-Payment, and 4-Payment plans.
 * Multi-payment plans store only Stripe Customer/PaymentMethod IDs — no card data ever touches WordPress.
 *
 * @package STRBooking
 */

namespace STRBooking;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles payment plan creation, installment scheduling, and off-session charging.
 */
class PaymentPlanManager {

	public function __construct() {
		add_action( 'str_charge_installment', array( $this, 'charge_installment' ), 10, 2 );
	}

	/**
	 * Build the installments JSON and schedule WP-Cron events for each future installment.
	 *
	 * @param int    $booking_id       Booking post ID.
	 * @param string $plan             pay_in_full | two_payment | four_payment
	 * @param float  $deposit          Deposit amount already charged (dollars).
	 * @param float  $total            Booking total (dollars).
	 * @param string $checkin          Check-in date string (Y-m-d).
	 * @return array                   Installments array (first element = the deposit, already paid).
	 */
	public function create_installment_schedule( int $booking_id, string $plan, float $deposit, float $total, string $checkin ): array {
		$installments = array();

		if ( 'pay_in_full' === $plan ) {
			$installments[] = array(
				'number'                => 1,
				'amount'                => round( $total, 2 ),
				'due_date'              => date( 'Y-m-d' ),
				'status'                => 'paid',
				'stripe_payment_intent' => null,
			);

			update_post_meta( $booking_id, 'str_payment_installments', wp_json_encode( $installments ) );
			return $installments;
		}

		$checkin_ts = strtotime( $checkin );

		if ( 'two_payment' === $plan ) {
			$property_id    = (int) get_post_meta( $booking_id, 'str_property_id', true );
			$days_before    = (int) get_post_meta( $property_id, 'str_plan_two_days_before', true ) ?: 42;
			$remainder      = round( $total - $deposit, 2 );
			$due_ts         = $checkin_ts - ( $days_before * DAY_IN_SECONDS );

			$installments[] = array(
				'number'                => 1,
				'amount'                => round( $deposit, 2 ),
				'due_date'              => date( 'Y-m-d' ),
				'status'                => 'paid',
				'stripe_payment_intent' => null,
			);
			$installments[] = array(
				'number'                => 2,
				'amount'                => $remainder,
				'due_date'              => date( 'Y-m-d', $due_ts ),
				'status'                => 'pending',
				'stripe_payment_intent' => null,
			);

			if ( $due_ts > time() ) {
				wp_schedule_single_event( $due_ts, 'str_charge_installment', array( $booking_id, 2 ) );
			}
		} elseif ( 'four_payment' === $plan ) {
			$remainder      = round( $total - $deposit, 2 );
			$installment_amt = round( $remainder / 3, 2 );
			// Correct for rounding drift on last installment
			$last_amt       = round( $remainder - ( $installment_amt * 2 ), 2 );

			$installments[] = array(
				'number'                => 1,
				'amount'                => round( $deposit, 2 ),
				'due_date'              => date( 'Y-m-d' ),
				'status'                => 'paid',
				'stripe_payment_intent' => null,
			);

			$offsets = array( 3, 2, 1 ); // months before check-in
			foreach ( $offsets as $i => $months_before ) {
				$num    = $i + 2;
				$due_ts = strtotime( "-{$months_before} months", $checkin_ts );
				$amt    = ( $num === 4 ) ? $last_amt : $installment_amt;

				$installments[] = array(
					'number'                => $num,
					'amount'                => $amt,
					'due_date'              => date( 'Y-m-d', $due_ts ),
					'status'                => 'pending',
					'stripe_payment_intent' => null,
				);

				if ( $due_ts > time() ) {
					wp_schedule_single_event( $due_ts, 'str_charge_installment', array( $booking_id, $num ) );
				}
			}
		}

		update_post_meta( $booking_id, 'str_payment_installments', wp_json_encode( $installments ) );

		return $installments;
	}

	/**
	 * Charge a specific installment off-session using stored Stripe credentials.
	 *
	 * Called by WP-Cron hook `str_charge_installment`.
	 *
	 * @param int $booking_id         Booking post ID.
	 * @param int $installment_number Installment number (1-based).
	 * @return bool|\WP_Error
	 */
	public function charge_installment( int $booking_id, int $installment_number ): bool|\WP_Error {
		$installments = $this->get_schedule( $booking_id );

		if ( empty( $installments ) ) {
			return new \WP_Error( 'no_schedule', 'No installment schedule found.' );
		}

		$installment = null;
		foreach ( $installments as $inst ) {
			if ( (int) $inst['number'] === $installment_number ) {
				$installment = $inst;
				break;
			}
		}

		if ( ! $installment ) {
			return new \WP_Error( 'not_found', "Installment #{$installment_number} not found." );
		}

		if ( 'paid' === $installment['status'] ) {
			return true; // Already processed (idempotency)
		}

		$customer_id        = get_post_meta( $booking_id, 'str_stripe_customer_id', true );
		$payment_method_id  = get_post_meta( $booking_id, 'str_stripe_payment_method_id', true );

		if ( ! $customer_id || ! $payment_method_id ) {
			$this->update_installment_status( $booking_id, $installment_number, 'failed', '' );
			return new \WP_Error( 'missing_payment_method', 'No saved payment method on file.' );
		}

		$currency = get_option( 'str_booking_currency', 'usd' );

		$booking_post = get_post( $booking_id );
		$metadata     = array(
			'booking_id'          => $booking_id,
			'installment_number'  => $installment_number,
			'source'              => 'str_direct_booking',
		);

		$result = STRBooking::get_instance()->payment_handler->charge_off_session(
			$customer_id,
			$payment_method_id,
			(int) round( $installment['amount'] * 100 ),
			$currency,
			$metadata
		);

		if ( is_wp_error( $result ) ) {
			$this->update_installment_status( $booking_id, $installment_number, 'failed', '' );
			do_action( 'str_installment_failed', $booking_id, $installment_number, $result->get_error_message() );
			return $result;
		}

		$this->update_installment_status( $booking_id, $installment_number, 'paid', $result['id'] ?? '' );
		do_action( 'str_installment_paid', $booking_id, $installment_number, $installment['amount'] );

		return true;
	}

	/**
	 * Get the decoded installments array for a booking.
	 *
	 * @param int $booking_id Booking post ID.
	 * @return array
	 */
	public function get_schedule( int $booking_id ): array {
		$raw = get_post_meta( $booking_id, 'str_payment_installments', true );

		if ( empty( $raw ) ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Update the status (and optionally Stripe PI ID) of a single installment.
	 *
	 * @param int    $booking_id  Booking post ID.
	 * @param int    $number      Installment number (1-based).
	 * @param string $status      pending | paid | failed
	 * @param string $intent_id   Stripe PaymentIntent ID (empty string if unknown).
	 */
	public function update_installment_status( int $booking_id, int $number, string $status, string $intent_id ): void {
		$installments = $this->get_schedule( $booking_id );

		foreach ( $installments as &$inst ) {
			if ( (int) $inst['number'] === $number ) {
				$inst['status']                = $status;
				$inst['stripe_payment_intent'] = $intent_id ?: $inst['stripe_payment_intent'];
				break;
			}
		}
		unset( $inst );

		update_post_meta( $booking_id, 'str_payment_installments', wp_json_encode( $installments ) );
	}

	/**
	 * Return which payment plans are eligible for a given property + check-in date.
	 *
	 * @param int    $property_id Property post ID.
	 * @param string $checkin     Check-in date (Y-m-d).
	 * @return array              Array of eligible plan keys, e.g. ['pay_in_full', 'two_payment']
	 */
	public function get_eligible_plans( int $property_id, string $checkin ): array {
		$plans      = array();
		$days_until = (int) floor( ( strtotime( $checkin ) - time() ) / DAY_IN_SECONDS );

		// Pay-in-Full
		$full_enabled = get_post_meta( $property_id, 'str_plan_full_enabled', true );
		if ( '' === $full_enabled || (bool) $full_enabled ) {
			$plans[] = 'pay_in_full';
		}

		// 2-Payment — hidden if check-in is within configured days
		$two_enabled = (bool) get_post_meta( $property_id, 'str_plan_two_enabled', true );
		if ( $two_enabled ) {
			$two_days_before = (int) get_post_meta( $property_id, 'str_plan_two_days_before', true ) ?: 42;
			if ( $days_until > $two_days_before ) {
				$plans[] = 'two_payment';
			}
		}

		// 4-Payment — hidden if check-in ≤ 90 days away
		$four_enabled = (bool) get_post_meta( $property_id, 'str_plan_four_enabled', true );
		if ( $four_enabled && $days_until > 90 ) {
			$plans[] = 'four_payment';
		}

		return $plans;
	}

	/**
	 * Get payment plan configuration for a property (for JS localization).
	 *
	 * @param int $property_id Property post ID.
	 * @return array
	 */
	public function get_plan_config( int $property_id ): array {
		return array(
			'full_enabled'        => (bool) ( get_post_meta( $property_id, 'str_plan_full_enabled', true ) !== '0' ),
			'two_enabled'         => (bool) get_post_meta( $property_id, 'str_plan_two_enabled', true ),
			'two_deposit_pct'     => (int) ( get_post_meta( $property_id, 'str_plan_two_deposit_pct', true ) ?: 50 ),
			'two_days_before'     => (int) ( get_post_meta( $property_id, 'str_plan_two_days_before', true ) ?: 42 ),
			'four_enabled'        => (bool) get_post_meta( $property_id, 'str_plan_four_enabled', true ),
			'four_deposit_min_pct' => (int) ( get_post_meta( $property_id, 'str_plan_four_deposit_min_pct', true ) ?: 25 ),
		);
	}
}
