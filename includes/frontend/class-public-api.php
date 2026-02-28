<?php
/**
 * Public REST API — booking endpoints.
 *
 * @package STRBooking\Frontend
 */

namespace STRBooking\Frontend;

use STRBooking\BookingManager;
use STRBooking\PaymentHandler;
use STRBooking\PaymentPlanManager;
use STRBooking\PricingEngine;
use STRBooking\SquareHandler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and handles all public REST API endpoints.
 */
class PublicAPI extends \WP_REST_Controller {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'str-booking/v1';

	/**
	 * @var BookingManager
	 */
	private BookingManager $booking_manager;

	/**
	 * @var PricingEngine
	 */
	private PricingEngine $pricing_engine;

	/**
	 * @var PaymentHandler
	 */
	private PaymentHandler $payment_handler;

	public function __construct( BookingManager $booking_manager, PricingEngine $pricing_engine, PaymentHandler $payment_handler ) {
		$this->booking_manager = $booking_manager;
		$this->pricing_engine  = $pricing_engine;
		$this->payment_handler = $payment_handler;

		add_action( 'rest_api_init', array( $this, 'register_routes' ), 10 );
	}

	/**
	 * Register all REST routes.
	 */
	public function register_routes(): void {
		// POST /availability
		register_rest_route(
			$this->namespace,
			'/availability',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'check_availability' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'property_id' => array( 'required' => true, 'type' => 'integer', 'minimum' => 1 ),
					'check_in'    => array( 'required' => true, 'type' => 'string' ),
					'check_out'   => array( 'required' => true, 'type' => 'string' ),
				),
			)
		);

		// POST /pricing
		register_rest_route(
			$this->namespace,
			'/pricing',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'get_pricing' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'property_id' => array( 'required' => true, 'type' => 'integer', 'minimum' => 1 ),
					'check_in'    => array( 'required' => true, 'type' => 'string' ),
					'check_out'   => array( 'required' => true, 'type' => 'string' ),
					'guests'      => array( 'required' => false, 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
				),
			)
		);

		// POST /booking (rate-limited)
		register_rest_route(
			$this->namespace,
			'/booking',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_booking' ),
				'permission_callback' => array( $this, 'check_booking_rate_limit' ),
				'args'                => array(
					'property_id'     => array( 'required' => true, 'type' => 'integer', 'minimum' => 1 ),
					'check_in'        => array( 'required' => true, 'type' => 'string' ),
					'check_out'       => array( 'required' => true, 'type' => 'string' ),
					'guest_name'      => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'guest_email'     => array( 'required' => true, 'type' => 'string', 'format' => 'email' ),
					'guest_phone'     => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'guest_count'     => array( 'required' => false, 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
					'special_requests' => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
					'payment_plan'    => array( 'required' => false, 'type' => 'string', 'default' => 'pay_in_full', 'sanitize_callback' => 'sanitize_text_field' ),
					'deposit_amount'  => array( 'required' => false, 'type' => 'number', 'minimum' => 0 ),
				),
			)
		);

		// GET /booking/{id}
		register_rest_route(
			$this->namespace,
			'/booking/(?P<id>[\d]+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_booking' ),
				'permission_callback' => array( $this, 'verify_guest_token' ),
				'args'                => array(
					'id' => array( 'required' => true, 'type' => 'integer', 'minimum' => 1 ),
				),
			)
		);

		// POST /stripe-webhook
		register_rest_route(
			$this->namespace,
			'/stripe-webhook',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this->payment_handler, 'handle_webhook' ),
				'permission_callback' => '__return_true',
			)
		);

		// GET /admin/metrics
		register_rest_route(
			$this->namespace,
			'/admin/metrics',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_admin_metrics' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		// GET /calendar/{property_id} — public per-month availability (date+status only)
		register_rest_route(
			$this->namespace,
			'/calendar/(?P<property_id>[\d]+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_public_calendar' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'property_id' => array( 'required' => true, 'type' => 'integer' ),
					'year'        => array( 'required' => false, 'type' => 'integer' ),
					'month'       => array( 'required' => false, 'type' => 'integer' ),
				),
			)
		);

		// GET /admin/availability-calendar (for admin calendar view)
		register_rest_route(
			$this->namespace,
			'/admin/availability/(?P<property_id>[\d]+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_availability_calendar' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'property_id' => array( 'required' => true, 'type' => 'integer' ),
					'year'        => array( 'required' => false, 'type' => 'integer' ),
					'month'       => array( 'required' => false, 'type' => 'integer' ),
				),
			)
		);
	}

	/**
	 * POST /availability — check date availability.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function check_availability( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$property_id = (int) $request->get_param( 'property_id' );
		$check_in    = sanitize_text_field( $request->get_param( 'check_in' ) );
		$check_out   = sanitize_text_field( $request->get_param( 'check_out' ) );

		$available = $this->booking_manager->check_availability( $property_id, $check_in, $check_out );

		return rest_ensure_response(
			array(
				'available'   => $available,
				'property_id' => $property_id,
				'check_in'    => $check_in,
				'check_out'   => $check_out,
			)
		);
	}

	/**
	 * POST /pricing — calculate pricing.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_pricing( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$property_id = (int) $request->get_param( 'property_id' );
		$check_in    = sanitize_text_field( $request->get_param( 'check_in' ) );
		$check_out   = sanitize_text_field( $request->get_param( 'check_out' ) );
		$guests      = (int) $request->get_param( 'guests' );

		$pricing = $this->pricing_engine->calculate( $property_id, $check_in, $check_out, $guests );

		if ( is_wp_error( $pricing ) ) {
			return new \WP_Error( $pricing->get_error_code(), $pricing->get_error_message(), array( 'status' => 400 ) );
		}

		return rest_ensure_response( $pricing );
	}

	/**
	 * POST /booking — create booking via Stripe or Square.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_booking( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$property_id = (int) $request->get_param( 'property_id' );
		$check_in    = sanitize_text_field( $request->get_param( 'check_in' ) );
		$check_out   = sanitize_text_field( $request->get_param( 'check_out' ) );
		$guests      = (int) ( $request->get_param( 'guest_count' ) ?: 1 );
		$gateway     = get_option( 'str_booking_payment_gateway', 'stripe' );
		$currency    = get_option( 'str_booking_currency', 'usd' );

		// Check availability
		if ( ! $this->booking_manager->check_availability( $property_id, $check_in, $check_out ) ) {
			return new \WP_Error( 'unavailable', 'Selected dates are not available.', array( 'status' => 409 ) );
		}

		// Calculate pricing
		$pricing = $this->pricing_engine->calculate( $property_id, $check_in, $check_out, $guests );
		if ( is_wp_error( $pricing ) ) {
			return new \WP_Error( $pricing->get_error_code(), $pricing->get_error_message(), array( 'status' => 400 ) );
		}

		$total = (float) $pricing['total'];

		if ( 'square' === $gateway ) {
			// ── Square branch: pay-in-full only ───────────────────────────────
			$source_id = sanitize_text_field( $request->get_param( 'source_id' ) ?? '' );

			if ( empty( $source_id ) ) {
				return new \WP_Error( 'missing_source', 'Square payment source token is required.', array( 'status' => 400 ) );
			}

			$booking_data = array(
				'property_id'           => $property_id,
				'guest_name'            => $request->get_param( 'guest_name' ),
				'guest_email'           => $request->get_param( 'guest_email' ),
				'guest_phone'           => $request->get_param( 'guest_phone' ) ?? '',
				'guest_count'           => $guests,
				'check_in'              => $check_in,
				'check_out'             => $check_out,
				'nights'                => $pricing['nights'],
				'nightly_rate'          => $pricing['nightly_rate'],
				'subtotal'              => $pricing['nightly_subtotal'],
				'cleaning_fee'          => $pricing['cleaning_fee'],
				'security_deposit'      => $pricing['security_deposit'],
				'taxes'                 => $pricing['taxes'],
				'total'                 => $total,
				'los_discount'          => $pricing['los_discount'],
				'stripe_payment_intent' => '',
				'stripe_transfer_group' => '',
				'special_requests'      => $request->get_param( 'special_requests' ) ?? '',
				'daily_breakdown'       => wp_json_encode( $pricing['daily_breakdown'] ),
			);

			$booking_id = $this->booking_manager->create_booking( $booking_data );

			if ( is_wp_error( $booking_id ) ) {
				return new \WP_Error( $booking_id->get_error_code(), $booking_id->get_error_message(), array( 'status' => 500 ) );
			}

			update_post_meta( $booking_id, 'str_payment_plan', 'pay_in_full' );

			$amount_cents = (int) round( $total * 100 );
			$result       = ( new SquareHandler() )->create_payment( $amount_cents, $currency, $source_id, $booking_id );

			if ( is_wp_error( $result ) ) {
				wp_update_post( array( 'ID' => $booking_id, 'post_status' => 'cancelled' ) );
				return new \WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 402 ) );
			}

			update_post_meta( $booking_id, 'str_square_payment_id', $result['payment']['id'] ?? '' );
			wp_update_post( array( 'ID' => $booking_id, 'post_status' => 'confirmed' ) );
			do_action( 'str_booking_confirmed', $booking_id );

			return new \WP_REST_Response(
				array(
					'booking_id' => $booking_id,
					'success'    => true,
					'total'      => $total,
				),
				201
			);
		}

		// ── Stripe branch (unchanged) ──────────────────────────────────────────
		$payment_plan = sanitize_text_field( $request->get_param( 'payment_plan' ) ?: 'pay_in_full' );

		// Validate payment plan value
		$valid_plans = array( 'pay_in_full', 'two_payment', 'four_payment' );
		if ( ! in_array( $payment_plan, $valid_plans, true ) ) {
			$payment_plan = 'pay_in_full';
		}

		$charge_amount = $total;

		// For multi-payment plans, determine the deposit amount
		$customer_id = null;
		if ( 'two_payment' === $payment_plan ) {
			$two_deposit_pct = (int) ( get_post_meta( $property_id, 'str_plan_two_deposit_pct', true ) ?: 50 );
			$charge_amount   = round( $total * $two_deposit_pct / 100, 2 );
		} elseif ( 'four_payment' === $payment_plan ) {
			$deposit_param = (float) ( $request->get_param( 'deposit_amount' ) ?: 0 );
			$four_min_pct  = (int) ( get_post_meta( $property_id, 'str_plan_four_deposit_min_pct', true ) ?: 25 );
			$min_deposit   = round( $total * $four_min_pct / 100, 2 );
			$charge_amount = ( $deposit_param >= $min_deposit ) ? round( $deposit_param, 2 ) : $min_deposit;
		}

		// For multi-payment plans, create a Stripe Customer so we can vault the payment method
		if ( 'pay_in_full' !== $payment_plan ) {
			$guest_email = sanitize_email( $request->get_param( 'guest_email' ) );
			$guest_name  = sanitize_text_field( $request->get_param( 'guest_name' ) );
			$customer    = $this->payment_handler->create_stripe_customer( $guest_email, $guest_name );

			if ( is_wp_error( $customer ) ) {
				return new \WP_Error( $customer->get_error_code(), $customer->get_error_message(), array( 'status' => 500 ) );
			}

			$customer_id = $customer;
		}

		// Create Stripe PaymentIntent (for deposit amount only on multi-pay plans)
		$transfer_group = 'STR_BOOKING_' . uniqid( '', true );
		$intent         = $this->payment_handler->create_payment_intent( $charge_amount, $transfer_group, $property_id, $customer_id );

		if ( is_wp_error( $intent ) ) {
			return new \WP_Error( $intent->get_error_code(), $intent->get_error_message(), array( 'status' => 500 ) );
		}

		// Create booking record
		$booking_data = array(
			'property_id'            => $property_id,
			'guest_name'             => $request->get_param( 'guest_name' ),
			'guest_email'            => $request->get_param( 'guest_email' ),
			'guest_phone'            => $request->get_param( 'guest_phone' ) ?? '',
			'guest_count'            => $guests,
			'check_in'               => $check_in,
			'check_out'              => $check_out,
			'nights'                 => $pricing['nights'],
			'nightly_rate'           => $pricing['nightly_rate'],
			'subtotal'               => $pricing['nightly_subtotal'],
			'cleaning_fee'           => $pricing['cleaning_fee'],
			'security_deposit'       => $pricing['security_deposit'],
			'taxes'                  => $pricing['taxes'],
			'total'                  => $total,
			'los_discount'           => $pricing['los_discount'],
			'stripe_payment_intent'  => $intent['id'],
			'stripe_transfer_group'  => $transfer_group,
			'special_requests'       => $request->get_param( 'special_requests' ) ?? '',
			'daily_breakdown'        => wp_json_encode( $pricing['daily_breakdown'] ),
		);

		$booking_id = $this->booking_manager->create_booking( $booking_data );

		if ( is_wp_error( $booking_id ) ) {
			return new \WP_Error( $booking_id->get_error_code(), $booking_id->get_error_message(), array( 'status' => 500 ) );
		}

		// Persist payment plan meta
		update_post_meta( $booking_id, 'str_payment_plan', $payment_plan );

		// Update PaymentIntent metadata with booking_id
		$this->payment_handler->update_payment_intent_metadata( $intent['id'], array( 'booking_id' => $booking_id ) );

		// Build installment schedule and schedule cron events
		$installment_schedule = array();
		if ( 'pay_in_full' !== $payment_plan ) {
			$plan_manager         = new PaymentPlanManager();
			$installment_schedule = $plan_manager->create_installment_schedule( $booking_id, $payment_plan, $charge_amount, $total, $check_in );
		}

		$response_data = array(
			'booking_id'    => $booking_id,
			'client_secret' => $intent['client_secret'],
			'total'         => $total,
			'payment_plan'  => $payment_plan,
			'charge_amount' => $charge_amount,
		);

		if ( ! empty( $installment_schedule ) ) {
			$response_data['installment_schedule'] = $installment_schedule;
		}

		return new \WP_REST_Response( $response_data, 201 );
	}

	/**
	 * GET /booking/{id} — fetch booking details.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_booking( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id      = (int) $request->get_param( 'id' );
		$booking = $this->booking_manager->get_booking( $id );

		if ( is_wp_error( $booking ) ) {
			return new \WP_Error( 'not_found', 'Booking not found.', array( 'status' => 404 ) );
		}

		// Remove sensitive fields
		unset( $booking['stripe_payment_intent'], $booking['stripe_charge_id'] );

		return rest_ensure_response( $booking );
	}

	/**
	 * GET /admin/metrics
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function get_admin_metrics( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response( $this->booking_manager->get_metrics() );
	}

	/**
	 * GET /calendar/{property_id} — public monthly availability (date + status only).
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function get_public_calendar( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$property_id = (int) $request->get_param( 'property_id' );
		$year        = (int) ( $request->get_param( 'year' ) ?: date( 'Y' ) );
		$month       = (int) ( $request->get_param( 'month' ) ?: date( 'n' ) );

		$start = sprintf( '%04d-%02d-01', $year, $month );
		$end   = date( 'Y-m-d', strtotime( $start . ' +1 month' ) );

		$table = $wpdb->prefix . 'str_availability';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT date, status FROM {$table}
				WHERE property_id = %d AND date >= %s AND date < %s",
				$property_id,
				$start,
				$end
			),
			ARRAY_A
		);

		return rest_ensure_response( $rows );
	}

	/**
	 * GET /admin/availability/{property_id}
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function get_availability_calendar( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$property_id = (int) $request->get_param( 'property_id' );
		$year        = (int) ( $request->get_param( 'year' ) ?: date( 'Y' ) );
		$month       = (int) ( $request->get_param( 'month' ) ?: date( 'n' ) );

		$start = sprintf( '%04d-%02d-01', $year, $month );
		$end   = date( 'Y-m-d', strtotime( $start . ' +1 month' ) );

		$table = $wpdb->prefix . 'str_availability';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT date, status, price_override, booking_id FROM {$table}
				WHERE property_id = %d AND date >= %s AND date < %s",
				$property_id,
				$start,
				$end
			),
			ARRAY_A
		);

		return rest_ensure_response( $rows );
	}

	/**
	 * Rate limiting permission callback for booking creation.
	 *
	 * @param \WP_REST_Request $request
	 * @return bool|\WP_Error
	 */
	public function check_booking_rate_limit( \WP_REST_Request $request ): bool|\WP_Error {
		$ip  = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );
		$key = 'str_rate_limit_' . md5( $ip );

		$attempts = (int) get_transient( $key );

		if ( $attempts >= 10 ) {
			return new \WP_Error(
				'rate_limited',
				'Too many booking attempts. Please try again later.',
				array( 'status' => 429 )
			);
		}

		set_transient( $key, $attempts + 1, HOUR_IN_SECONDS );

		return true;
	}

	/**
	 * Guest email token verification for GET /booking/{id}.
	 *
	 * @param \WP_REST_Request $request
	 * @return bool|\WP_Error
	 */
	public function verify_guest_token( \WP_REST_Request $request ): bool|\WP_Error {
		$id    = (int) $request->get_param( 'id' );
		$token = $request->get_header( 'X-STR-Guest-Token' );

		if ( empty( $token ) ) {
			return new \WP_Error( 'unauthorized', 'Guest token required.', array( 'status' => 401 ) );
		}

		$stored_email = get_post_meta( $id, 'str_guest_email', true );

		if ( empty( $stored_email ) || ! hash_equals( strtolower( $stored_email ), strtolower( $token ) ) ) {
			return new \WP_Error( 'forbidden', 'Invalid guest token.', array( 'status' => 403 ) );
		}

		return true;
	}
}
