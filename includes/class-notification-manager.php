<?php
/**
 * Notification Manager — email and SMS notifications.
 *
 * @package STRBooking
 */

namespace STRBooking;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface for SMS providers.
 */
interface SMSProviderInterface {
	public function send( string $phone, string $message ): bool;
}

/**
 * Twilio SMS implementation.
 */
class TwilioSMSProvider implements SMSProviderInterface {

	private string $account_sid;
	private string $auth_token;
	private string $from_number;

	public function __construct( string $account_sid, string $auth_token, string $from_number ) {
		$this->account_sid = $account_sid;
		$this->auth_token  = $auth_token;
		$this->from_number = $from_number;
	}

	/**
	 * Send an SMS via Twilio REST API.
	 *
	 * @param string $phone   Recipient phone number.
	 * @param string $message Message body.
	 * @return bool
	 */
	public function send( string $phone, string $message ): bool {
		$url      = 'https://api.twilio.com/2010-04-01/Accounts/' . $this->account_sid . '/Messages.json';
		$response = wp_remote_post(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $this->account_sid . ':' . $this->auth_token ),
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => array(
					'To'   => $phone,
					'From' => $this->from_number,
					'Body' => $message,
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( 'STR Booking: Twilio error: ' . $response->get_error_message() );
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		return $code >= 200 && $code < 300;
	}
}

/**
 * Hook-driven notification manager for guest communication.
 */
class NotificationManager {

	/**
	 * @var SMSProviderInterface|null
	 */
	private ?SMSProviderInterface $sms_provider = null;

	public function __construct() {
		$this->maybe_init_sms();

		add_action( 'str_booking_confirmed', array( $this, 'send_confirmation' ), 10 );
		add_action( 'str_booking_confirmed', array( $this, 'schedule_notification_sequence' ), 20 );
		add_action( 'str_send_notification', array( $this, 'dispatch_notification' ), 10, 2 );
	}

	/**
	 * Initialize SMS provider if credentials are configured.
	 */
	private function maybe_init_sms(): void {
		$account_sid = get_option( 'str_booking_twilio_account_sid', '' );
		$auth_token  = get_option( 'str_booking_twilio_auth_token', '' );
		$from_number = get_option( 'str_booking_twilio_from_number', '' );

		if ( $account_sid && $auth_token && $from_number ) {
			$this->sms_provider = new TwilioSMSProvider( $account_sid, $auth_token, $from_number );
		}
	}

	/**
	 * Send immediate booking confirmation.
	 *
	 * @param int $booking_id Booking post ID.
	 */
	public function send_confirmation( int $booking_id ): void {
		$this->dispatch_notification( $booking_id, 'booking_confirmation' );
	}

	/**
	 * Schedule all follow-up notifications.
	 *
	 * @param int $booking_id Booking post ID.
	 */
	public function schedule_notification_sequence( int $booking_id ): void {
		$booking = $this->get_booking_data( $booking_id );

		if ( ! $booking ) {
			return;
		}

		$check_in_ts  = strtotime( $booking['check_in'] . ' ' . ( $booking['check_in_time'] ?: '15:00' ) );
		$check_out_ts = strtotime( $booking['check_out'] . ' ' . ( $booking['check_out_time'] ?: '11:00' ) );

		$schedule = array(
			// 3 days before check-in
			'pre_arrival'          => $check_in_ts - ( 3 * DAY_IN_SECONDS ),
			// Check-in day at 9am
			'check_in_instructions' => strtotime( $booking['check_in'] . ' 09:00:00' ),
			// Day before check-out at 6pm
			'check_out_reminder'   => $check_out_ts - ( 18 * HOUR_IN_SECONDS ),
			// 2 days after check-out
			'review_request'       => $check_out_ts + ( 2 * DAY_IN_SECONDS ),
		);

		foreach ( $schedule as $type => $timestamp ) {
			if ( $timestamp > time() ) {
				wp_schedule_single_event(
					$timestamp,
					'str_send_notification',
					array( $booking_id, $type )
				);
			}
		}
	}

	/**
	 * Dispatch a scheduled or immediate notification.
	 *
	 * @param int    $booking_id       Booking post ID.
	 * @param string $notification_type Notification type slug.
	 */
	public function dispatch_notification( int $booking_id, string $notification_type ): void {
		$booking = $this->get_booking_data( $booking_id );

		if ( ! $booking ) {
			return;
		}

		$channels = $this->get_channels_for_type( $notification_type );

		if ( in_array( 'email', $channels, true ) ) {
			$this->send_email_notification( $booking, $notification_type );
		}

		if ( in_array( 'sms', $channels, true ) && $this->sms_provider && ! empty( $booking['guest_phone'] ) ) {
			$this->send_sms_notification( $booking, $notification_type );
		}
	}

	/**
	 * Determine which channels to use for a notification type.
	 *
	 * @param string $type Notification type.
	 * @return string[]
	 */
	private function get_channels_for_type( string $type ): array {
		$map = array(
			'booking_confirmation'   => array( 'email', 'sms' ),
			'pre_arrival'            => array( 'email', 'sms' ),
			'check_in_instructions'  => array( 'sms' ),
			'check_out_reminder'     => array( 'email', 'sms' ),
			'review_request'         => array( 'email' ),
		);

		return $map[ $type ] ?? array( 'email' );
	}

	/**
	 * Send email notification.
	 *
	 * @param array  $booking Booking data array.
	 * @param string $type    Notification type.
	 */
	private function send_email_notification( array $booking, string $type ): void {
		$template_file = $this->get_template_path( $type . '.php' );

		if ( ! $template_file ) {
			return;
		}

		$body = $this->render_template( $template_file, $booking );

		$subject_map = array(
			'booking_confirmation'   => __( 'Your booking is confirmed!', 'str-direct-booking' ),
			'pre_arrival'            => __( 'Your stay is coming up — here\'s what to know', 'str-direct-booking' ),
			'check_in_instructions'  => __( 'Check-in instructions for today', 'str-direct-booking' ),
			'check_out_reminder'     => __( 'Check-out reminder', 'str-direct-booking' ),
			'review_request'         => __( 'How was your stay?', 'str-direct-booking' ),
		);

		$subject   = $subject_map[ $type ] ?? __( 'Booking update', 'str-direct-booking' );
		$from_name  = get_option( 'str_booking_from_name', get_bloginfo( 'name' ) );
		$from_email = get_option( 'str_booking_from_email', get_option( 'admin_email' ) );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			sprintf( 'From: %s <%s>', $from_name, $from_email ),
		);

		wp_mail( $booking['guest_email'], $subject, $body, $headers );
	}

	/**
	 * Send SMS notification.
	 *
	 * @param array  $booking Booking data array.
	 * @param string $type    Notification type.
	 */
	private function send_sms_notification( array $booking, string $type ): void {
		$messages = array(
			'booking_confirmation'   => "Hi {guest_name}, your booking at {property_name} is confirmed! Check-in: {check_in_date}. Reply STOP to unsubscribe.",
			'pre_arrival'            => "Hi {guest_name}, your stay at {property_name} starts {check_in_date}. We'll send check-in details the morning of arrival!",
			'check_in_instructions'  => "Welcome to {property_name}! Door code: {door_code}. WiFi: {wifi_password}. Questions? Call {host_phone}",
			'check_out_reminder'     => "Hi {guest_name}, just a reminder that check-out is tomorrow at {check_out_time}. Thanks for staying!",
			'review_request'         => null, // SMS not used for review request
		);

		$message_template = $messages[ $type ] ?? null;

		if ( ! $message_template ) {
			return;
		}

		$message = $this->replace_template_vars( $message_template, $booking );
		$this->sms_provider->send( $booking['guest_phone'], $message );
	}

	/**
	 * Render an email template with booking variables.
	 *
	 * @param string $template_path Absolute path to template file.
	 * @param array  $booking       Booking data.
	 * @return string Rendered HTML.
	 */
	private function render_template( string $template_path, array $booking ): string {
		ob_start();
		// Make booking data available in template scope
		$data = $booking;
		include $template_path;
		$html = ob_get_clean();

		return $this->replace_template_vars( $html, $booking );
	}

	/**
	 * Replace template variable placeholders with booking values.
	 *
	 * @param string $template Template string with {var} placeholders.
	 * @param array  $booking  Booking data.
	 * @return string
	 */
	private function replace_template_vars( string $template, array $booking ): string {
		$property_id = $booking['property_id'];

		$replacements = array(
			'{guest_name}'      => esc_html( $booking['guest_name'] ),
			'{guest_email}'     => esc_html( $booking['guest_email'] ),
			'{property_name}'   => esc_html( get_the_title( $property_id ) ),
			'{check_in_date}'   => esc_html( date_i18n( get_option( 'date_format' ), strtotime( $booking['check_in'] ) ) ),
			'{check_out_date}'  => esc_html( date_i18n( get_option( 'date_format' ), strtotime( $booking['check_out'] ) ) ),
			'{check_in_time}'   => esc_html( get_post_meta( $property_id, 'str_check_in_time', true ) ?: '3:00 PM' ),
			'{check_out_time}'  => esc_html( get_post_meta( $property_id, 'str_check_out_time', true ) ?: '11:00 AM' ),
			'{door_code}'       => esc_html( get_post_meta( $property_id, 'str_door_code', true ) ),
			'{wifi_password}'   => esc_html( get_post_meta( $property_id, 'str_wifi_password', true ) ),
			'{host_phone}'      => esc_html( get_post_meta( $property_id, 'str_host_phone', true ) ),
			'{address}'         => esc_html( get_post_meta( $property_id, 'str_address', true ) ),
			'{total}'           => number_format_i18n( $booking['total'], 2 ),
			'{nights}'          => (int) $booking['nights'],
			'{booking_id}'      => $booking['id'],
		);

		return str_replace( array_keys( $replacements ), array_values( $replacements ), $template );
	}

	/**
	 * Get template path, checking theme override first.
	 *
	 * @param string $filename Template filename.
	 * @return string|null Absolute path or null if not found.
	 */
	private function get_template_path( string $filename ): ?string {
		// Allow theme override: {theme}/str-direct-booking/email-templates/{filename}
		$theme_path = get_stylesheet_directory() . '/str-direct-booking/email-templates/' . $filename;

		if ( file_exists( $theme_path ) ) {
			return $theme_path;
		}

		$plugin_path = STR_BOOKING_PLUGIN_DIR . 'templates/email-templates/' . $filename;

		if ( file_exists( $plugin_path ) ) {
			return $plugin_path;
		}

		return null;
	}

	/**
	 * Get booking data with property meta merged in.
	 *
	 * @param int $booking_id Booking post ID.
	 * @return array|null
	 */
	private function get_booking_data( int $booking_id ): ?array {
		$post = get_post( $booking_id );

		if ( ! $post || 'str_booking' !== $post->post_type ) {
			return null;
		}

		$property_id = (int) get_post_meta( $booking_id, 'str_property_id', true );

		return array(
			'id'             => $booking_id,
			'status'         => $post->post_status,
			'property_id'    => $property_id,
			'guest_name'     => get_post_meta( $booking_id, 'str_guest_name', true ),
			'guest_email'    => get_post_meta( $booking_id, 'str_guest_email', true ),
			'guest_phone'    => get_post_meta( $booking_id, 'str_guest_phone', true ),
			'check_in'       => get_post_meta( $booking_id, 'str_check_in', true ),
			'check_out'      => get_post_meta( $booking_id, 'str_check_out', true ),
			'nights'         => (int) get_post_meta( $booking_id, 'str_nights', true ),
			'total'          => (float) get_post_meta( $booking_id, 'str_total', true ),
			'check_in_time'  => get_post_meta( $property_id, 'str_check_in_time', true ),
			'check_out_time' => get_post_meta( $property_id, 'str_check_out_time', true ),
		);
	}
}
