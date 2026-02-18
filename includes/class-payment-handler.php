<?php
/**
 * Payment Handler — Stripe Connect integration.
 *
 * Uses Separate Charges + Transfers (not Destination Charges) for multi-cohost support.
 *
 * @package STRBooking
 */

namespace STRBooking;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles Stripe PaymentIntents, webhooks, and co-host transfer scheduling.
 */
class PaymentHandler {

	/**
	 * @var BookingManager
	 */
	private BookingManager $booking_manager;

	/**
	 * @var CohostManager
	 */
	private CohostManager $cohost_manager;

	public function __construct( BookingManager $booking_manager, CohostManager $cohost_manager ) {
		$this->booking_manager = $booking_manager;
		$this->cohost_manager  = $cohost_manager;

		add_action( 'str_booking_process_transfers', array( $this, 'process_scheduled_transfers' ), 10, 2 );
		add_action( 'rest_api_init', array( $this, 'register_oauth_routes' ), 10 );
	}

	/**
	 * Initialize Stripe with the configured secret key.
	 */
	private function init_stripe(): void {
		$secret_key = get_option( 'str_booking_stripe_secret_key', '' );

		if ( empty( $secret_key ) ) {
			return;
		}

		\Stripe\Stripe::setApiKey( $secret_key );
		\Stripe\Stripe::setAppInfo( 'STR Direct Booking', STR_BOOKING_VERSION, STR_BOOKING_PLUGIN_URL );
	}

	/**
	 * Create a Stripe PaymentIntent for a booking.
	 *
	 * @param float  $total          Total amount in dollars.
	 * @param string $transfer_group Transfer group identifier.
	 * @param int    $property_id    Property ID for metadata.
	 * @return array|\WP_Error PaymentIntent data or WP_Error.
	 */
	public function create_payment_intent( float $total, string $transfer_group, int $property_id ): array|\WP_Error {
		$this->init_stripe();

		$currency = get_option( 'str_booking_currency', 'usd' );

		try {
			$intent = \Stripe\PaymentIntent::create(
				array(
					'amount'         => (int) round( $total * 100 ), // Convert to cents
					'currency'       => strtolower( $currency ),
					'transfer_group' => $transfer_group,
					'metadata'       => array(
						'property_id' => $property_id,
						'source'      => 'str_direct_booking',
					),
					'automatic_payment_methods' => array( 'enabled' => true ),
				)
			);

			return array(
				'id'            => $intent->id,
				'client_secret' => $intent->client_secret,
				'amount'        => $intent->amount,
				'status'        => $intent->status,
			);
		} catch ( \Stripe\Exception\ApiErrorException $e ) {
			return new \WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Update PaymentIntent metadata.
	 *
	 * @param string $intent_id PaymentIntent ID.
	 * @param array  $metadata  Metadata to merge.
	 */
	public function update_payment_intent_metadata( string $intent_id, array $metadata ): void {
		$this->init_stripe();

		try {
			\Stripe\PaymentIntent::update( $intent_id, array( 'metadata' => $metadata ) );
		} catch ( \Stripe\Exception\ApiErrorException $e ) {
			// Log but don't surface — non-critical
			error_log( 'STR Booking: Failed to update PaymentIntent metadata: ' . $e->getMessage() );
		}
	}

	/**
	 * Handle incoming Stripe webhook.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_webhook( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$this->init_stripe();

		$payload        = $request->get_body();
		$sig_header     = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
		$webhook_secret = get_option( 'str_booking_stripe_webhook_secret', '' );

		if ( empty( $webhook_secret ) ) {
			return new \WP_Error( 'config_error', 'Webhook secret not configured.', array( 'status' => 500 ) );
		}

		try {
			$event = \Stripe\Webhook::constructEvent( $payload, $sig_header, $webhook_secret );
		} catch ( \Stripe\Exception\SignatureVerificationException $e ) {
			return new \WP_Error( 'invalid_signature', 'Webhook signature verification failed.', array( 'status' => 400 ) );
		}

		// Idempotency check
		$event_key = 'str_stripe_event_' . $event->id;
		if ( get_transient( $event_key ) ) {
			return rest_ensure_response( array( 'status' => 'already_processed' ) );
		}
		set_transient( $event_key, true, DAY_IN_SECONDS );

		// Route event types
		switch ( $event->type ) {
			case 'payment_intent.succeeded':
				$this->handle_payment_succeeded( $event->data->object );
				break;

			case 'payment_intent.payment_failed':
				$this->handle_payment_failed( $event->data->object );
				break;

			case 'charge.refunded':
				$this->handle_charge_refunded( $event->data->object );
				break;
		}

		return rest_ensure_response( array( 'status' => 'ok' ) );
	}

	/**
	 * Handle payment_intent.succeeded webhook event.
	 *
	 * @param object $intent Stripe PaymentIntent object.
	 */
	private function handle_payment_succeeded( object $intent ): void {
		$booking_id = (int) ( $intent->metadata->booking_id ?? 0 );

		if ( ! $booking_id ) {
			return;
		}

		// Update booking status to confirmed
		$this->booking_manager->update_booking_status( $booking_id, 'confirmed' );

		// Store charge ID
		$charge_id = $intent->latest_charge ?? '';
		if ( $charge_id ) {
			update_post_meta( $booking_id, 'str_stripe_charge_id', $charge_id );
		}

		// Mark availability
		$booking = $this->booking_manager->get_booking( $booking_id );
		if ( ! is_wp_error( $booking ) ) {
			$this->booking_manager->mark_dates_booked(
				$booking['property_id'],
				$booking['check_in'],
				$booking['check_out'],
				$booking_id
			);

			// Schedule co-host transfers for 24 hours after check-in
			$checkin_ts    = strtotime( $booking['check_in'] ) + DAY_IN_SECONDS;
			$transfer_group = get_post_meta( $booking_id, 'str_stripe_transfer_group', true );

			wp_schedule_single_event(
				$checkin_ts,
				'str_booking_process_transfers',
				array( $booking_id, $transfer_group )
			);

			// Fire confirmation action for notifications
			do_action( 'str_booking_confirmed', $booking_id );
		}
	}

	/**
	 * Handle payment_intent.payment_failed webhook event.
	 *
	 * @param object $intent Stripe PaymentIntent object.
	 */
	private function handle_payment_failed( object $intent ): void {
		$booking_id = (int) ( $intent->metadata->booking_id ?? 0 );

		if ( ! $booking_id ) {
			return;
		}

		$this->booking_manager->update_booking_status( $booking_id, 'cancelled' );
		do_action( 'str_booking_payment_failed', $booking_id );
	}

	/**
	 * Handle charge.refunded webhook event.
	 *
	 * @param object $charge Stripe Charge object.
	 */
	private function handle_charge_refunded( object $charge ): void {
		// Find booking by charge ID
		$bookings = get_posts(
			array(
				'post_type'      => 'str_booking',
				'meta_key'       => 'str_stripe_charge_id',
				'meta_value'     => $charge->id,
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		if ( empty( $bookings ) ) {
			return;
		}

		$booking_id = $bookings[0];
		$this->booking_manager->update_booking_status( $booking_id, 'refunded' );

		// Reset availability dates
		$booking = $this->booking_manager->get_booking( $booking_id );
		if ( ! is_wp_error( $booking ) ) {
			$this->booking_manager->mark_dates_available(
				$booking['property_id'],
				$booking['check_in'],
				$booking['check_out']
			);
		}

		do_action( 'str_booking_refunded', $booking_id );
	}

	/**
	 * Process scheduled co-host transfers (fires 24h after check-in).
	 *
	 * @param int    $booking_id     Booking post ID.
	 * @param string $transfer_group Stripe transfer group.
	 */
	public function process_scheduled_transfers( int $booking_id, string $transfer_group ): void {
		$this->init_stripe();

		// Prevent double-processing
		if ( get_post_meta( $booking_id, 'str_transfers_processed', true ) ) {
			return;
		}

		$booking = $this->booking_manager->get_booking( $booking_id );
		if ( is_wp_error( $booking ) ) {
			return;
		}

		$cohosts = $this->cohost_manager->get_property_cohosts( $booking['property_id'] );
		if ( empty( $cohosts ) ) {
			return;
		}

		$errors = array();

		foreach ( $cohosts as $cohost ) {
			if ( empty( $cohost['stripe_account_id'] ) ) {
				continue;
			}

			$amount = $this->cohost_manager->calculate_split( $booking_id, $cohost );
			$cents  = (int) round( $amount * 100 );

			if ( $cents <= 0 ) {
				continue;
			}

			try {
				$currency = get_option( 'str_booking_currency', 'usd' );

				\Stripe\Transfer::create(
					array(
						'amount'          => $cents,
						'currency'        => strtolower( $currency ),
						'destination'     => $cohost['stripe_account_id'],
						'transfer_group'  => $transfer_group,
						'metadata'        => array(
							'booking_id' => $booking_id,
							'cohost_id'  => $cohost['id'],
						),
					)
				);
			} catch ( \Stripe\Exception\ApiErrorException $e ) {
				$errors[] = sprintf( 'Cohost %d: %s', $cohost['id'], $e->getMessage() );
				error_log( 'STR Booking: Transfer failed for cohost ' . $cohost['id'] . ': ' . $e->getMessage() );
			}
		}

		if ( empty( $errors ) ) {
			update_post_meta( $booking_id, 'str_transfers_processed', true );
		}
	}

	/**
	 * Register Stripe Connect OAuth routes.
	 */
	public function register_oauth_routes(): void {
		register_rest_route(
			'str-booking/v1',
			'/stripe-connect/authorize',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'generate_oauth_url' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'property_id' => array( 'required' => true, 'type' => 'integer' ),
				),
			)
		);

		register_rest_route(
			'str-booking/v1',
			'/stripe-connect/callback',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_oauth_callback' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	/**
	 * Generate Stripe Connect OAuth authorization URL.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function generate_oauth_url( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$client_id   = get_option( 'str_booking_stripe_connect_client_id', '' );
		$property_id = (int) $request->get_param( 'property_id' );

		if ( empty( $client_id ) ) {
			return new \WP_Error( 'config_error', 'Stripe Connect client_id not configured.', array( 'status' => 500 ) );
		}

		$redirect_uri = rest_url( 'str-booking/v1/stripe-connect/callback' );
		$state        = wp_create_nonce( 'str_stripe_connect_' . $property_id );

		$url = add_query_arg(
			array(
				'response_type' => 'code',
				'client_id'     => $client_id,
				'scope'         => 'read_write',
				'redirect_uri'  => $redirect_uri,
				'state'         => $state . '|' . $property_id,
			),
			'https://connect.stripe.com/oauth/authorize'
		);

		return rest_ensure_response( array( 'url' => $url ) );
	}

	/**
	 * Handle Stripe Connect OAuth callback.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_oauth_callback( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$this->init_stripe();

		$code  = sanitize_text_field( $request->get_param( 'code' ) ?? '' );
		$state = sanitize_text_field( $request->get_param( 'state' ) ?? '' );

		if ( empty( $code ) ) {
			return new \WP_Error( 'oauth_error', 'No authorization code received.', array( 'status' => 400 ) );
		}

		// Parse state
		$parts       = explode( '|', $state, 2 );
		$nonce       = $parts[0] ?? '';
		$property_id = (int) ( $parts[1] ?? 0 );

		if ( ! wp_verify_nonce( $nonce, 'str_stripe_connect_' . $property_id ) ) {
			return new \WP_Error( 'invalid_state', 'Invalid OAuth state.', array( 'status' => 403 ) );
		}

		try {
			$response = \Stripe\OAuth::token(
				array(
					'grant_type' => 'authorization_code',
					'code'       => $code,
				)
			);

			$stripe_account_id = $response->stripe_user_id;

			// Store co-host record
			$cohost_manager = new CohostManager();
			$cohost_manager->add_cohost(
				$property_id,
				$stripe_account_id,
				'percentage',
				0.0,
				array( 'user_id' => get_current_user_id() )
			);

			return rest_ensure_response(
				array(
					'success'           => true,
					'stripe_account_id' => $stripe_account_id,
				)
			);
		} catch ( \Stripe\Exception\ApiErrorException $e ) {
			return new \WP_Error( 'oauth_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}
}
