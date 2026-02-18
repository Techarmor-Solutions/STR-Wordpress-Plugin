<?php
/**
 * Public REST API — booking endpoints.
 *
 * @package STRBooking\Frontend
 */

namespace STRBooking\Frontend;

use STRBooking\BookingManager;
use STRBooking\PaymentHandler;
use STRBooking\PricingEngine;

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
	 * POST /booking — create booking and return Stripe client_secret.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_booking( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$property_id = (int) $request->get_param( 'property_id' );
		$check_in    = sanitize_text_field( $request->get_param( 'check_in' ) );
		$check_out   = sanitize_text_field( $request->get_param( 'check_out' ) );
		$guests      = (int) ( $request->get_param( 'guest_count' ) ?: 1 );

		// Check availability
		if ( ! $this->booking_manager->check_availability( $property_id, $check_in, $check_out ) ) {
			return new \WP_Error( 'unavailable', 'Selected dates are not available.', array( 'status' => 409 ) );
		}

		// Calculate pricing
		$pricing = $this->pricing_engine->calculate( $property_id, $check_in, $check_out, $guests );
		if ( is_wp_error( $pricing ) ) {
			return new \WP_Error( $pricing->get_error_code(), $pricing->get_error_message(), array( 'status' => 400 ) );
		}

		// Create Stripe PaymentIntent
		$transfer_group = 'STR_BOOKING_' . uniqid( '', true );
		$intent         = $this->payment_handler->create_payment_intent( $pricing['total'], $transfer_group, $property_id );

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
			'total'                  => $pricing['total'],
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

		// Update PaymentIntent metadata with booking_id
		$this->payment_handler->update_payment_intent_metadata( $intent['id'], array( 'booking_id' => $booking_id ) );

		return new \WP_REST_Response(
			array(
				'booking_id'    => $booking_id,
				'client_secret' => $intent['client_secret'],
				'total'         => $pricing['total'],
			),
			201
		);
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
