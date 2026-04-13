<?php
/**
 * Notification Manager — email and SMS notifications.
 *
 * @package STRBooking
 */

namespace STRBooking;

use STRBooking\Admin\NotificationSettings;

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
		add_action( 'str_installment_paid', array( $this, 'send_payment_received' ), 10, 3 );
		add_action( 'str_installment_failed', array( $this, 'handle_installment_failed' ), 10, 3 );

		// Messaging notifications
		add_action( 'str_guest_message_sent', array( $this, 'notify_host_of_message' ), 10, 2 );
		add_action( 'str_host_message_sent',  array( $this, 'notify_guest_of_reply' ),  10, 2 );
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

		// Load timing from saved templates
		$pre_arrival_days    = (int) ( NotificationSettings::get_template( 'pre_arrival' )['days_before'] ?? 3 );
		$checkout_reminder_days = (int) ( NotificationSettings::get_template( 'check_out_reminder' )['days_before'] ?? 1 );
		$review_days         = (int) ( NotificationSettings::get_template( 'review_request' )['days_before'] ?? 2 );
		$booking_reminder_days = (int) ( NotificationSettings::get_template( 'booking_reminder' )['days_before'] ?? 7 );

		$schedule = array(
			'booking_reminder'      => $check_in_ts - ( $booking_reminder_days * DAY_IN_SECONDS ),
			'pre_arrival'           => $check_in_ts - ( $pre_arrival_days * DAY_IN_SECONDS ),
			'check_in_instructions' => strtotime( $booking['check_in'] . ' 09:00:00' ),
			'check_out_reminder'    => $check_out_ts - ( $checkout_reminder_days * DAY_IN_SECONDS ),
			'review_request'        => $check_out_ts + ( $review_days * DAY_IN_SECONDS ),
		);

		foreach ( $schedule as $type => $timestamp ) {
			$tmpl = NotificationSettings::get_template( $type );
			if ( ! empty( $tmpl['enabled'] ) && $timestamp > time() ) {
				wp_schedule_single_event(
					$timestamp,
					'str_send_notification',
					array( $booking_id, $type )
				);
			}
		}

		// Schedule payment reminders for multi-payment plan bookings
		$payment_plan = get_post_meta( $booking_id, 'str_payment_plan', true );
		if ( in_array( $payment_plan, array( 'two_payment', 'four_payment' ), true ) ) {
			$this->schedule_payment_reminders( $booking_id );
		}
	}

	/**
	 * Schedule payment reminder notifications for each pending installment.
	 *
	 * @param int $booking_id Booking post ID.
	 */
	private function schedule_payment_reminders( int $booking_id ): void {
		$plan_manager = new PaymentPlanManager();
		$installments = $plan_manager->get_schedule( $booking_id );
		$reminder_tmpl = NotificationSettings::get_template( 'payment_reminder' );

		if ( empty( $reminder_tmpl['enabled'] ) ) {
			return;
		}

		$days_before = (int) ( $reminder_tmpl['days_before'] ?? 3 );

		foreach ( $installments as $inst ) {
			if ( 'pending' !== $inst['status'] || empty( $inst['due_date'] ) ) {
				continue;
			}

			$due_ts      = strtotime( $inst['due_date'] );
			$reminder_ts = $due_ts - ( $days_before * DAY_IN_SECONDS );

			if ( $reminder_ts > time() ) {
				wp_schedule_single_event(
					$reminder_ts,
					'str_send_notification',
					array( $booking_id, 'payment_reminder_' . $inst['number'] )
				);
			}
		}
	}

	/**
	 * Send a payment_received notification immediately after an installment is charged.
	 *
	 * @param int    $booking_id          Booking post ID.
	 * @param int    $installment_number  Installment number.
	 * @param float  $amount              Amount charged.
	 */
	public function send_payment_received( int $booking_id, int $installment_number, float $amount ): void {
		$booking = $this->get_booking_data( $booking_id );

		if ( ! $booking ) {
			return;
		}

		$booking['installment_number'] = $installment_number;
		$booking['installment_amount'] = $amount;

		$this->send_email_notification( $booking, 'payment_received' );
	}

	/**
	 * Handle a failed installment charge.
	 *
	 * @param int    $booking_id         Booking post ID.
	 * @param int    $installment_number Installment number.
	 * @param string $error_message      Stripe error message.
	 */
	public function handle_installment_failed( int $booking_id, int $installment_number, string $error_message ): void {
		error_log( sprintf( 'STR Booking: Installment #%d failed for booking %d: %s', $installment_number, $booking_id, $error_message ) );
	}

	/**
	 * Dispatch a scheduled or immediate notification.
	 *
	 * @param int    $booking_id        Booking post ID.
	 * @param string $notification_type Notification type slug (may include installment number suffix).
	 */
	public function dispatch_notification( int $booking_id, string $notification_type ): void {
		$booking = $this->get_booking_data( $booking_id );

		if ( ! $booking ) {
			return;
		}

		// Handle payment_reminder_{n} types — extract installment data
		$base_type = $notification_type;
		if ( str_starts_with( $notification_type, 'payment_reminder_' ) ) {
			$installment_number = (int) substr( $notification_type, strlen( 'payment_reminder_' ) );
			$plan_manager       = new PaymentPlanManager();
			$schedule           = $plan_manager->get_schedule( $booking_id );

			foreach ( $schedule as $inst ) {
				if ( (int) $inst['number'] === $installment_number ) {
					$booking['installment_number'] = $inst['number'];
					$booking['installment_amount'] = $inst['amount'];
					$booking['installment_due_date'] = $inst['due_date'];
					break;
				}
			}

			$base_type = 'payment_reminder';
		}

		// Check if the template is enabled
		$tmpl = NotificationSettings::get_template( $base_type );
		if ( isset( $tmpl['enabled'] ) && ! $tmpl['enabled'] ) {
			return;
		}

		$channels = $this->get_channels_for_type( $base_type );

		if ( in_array( 'email', $channels, true ) ) {
			$this->send_email_notification( $booking, $base_type );
		}

		if ( in_array( 'sms', $channels, true ) && $this->sms_provider && ! empty( $booking['guest_phone'] ) ) {
			$this->send_sms_notification( $booking, $base_type );
		}
	}

	/**
	 * Determine which channels to use for a notification type.
	 *
	 * @param string $type Notification type.
	 * @return string[]
	 */
	private function get_channels_for_type( string $type ): array {
		$types = NotificationSettings::get_notification_types();

		if ( isset( $types[ $type ]['channels'] ) ) {
			return $types[ $type ]['channels'];
		}

		return array( 'email' );
	}

	/**
	 * Send email notification using the customizable template from WP options.
	 *
	 * Falls back to a template file if available, then to inline defaults.
	 *
	 * @param array  $booking Booking data array.
	 * @param string $type    Notification type.
	 */
	private function send_email_notification( array $booking, string $type ): void {
		$tmpl   = NotificationSettings::get_template( $type );
		$subject = $this->replace_template_vars( $tmpl['email_subject'] ?? '', $booking );
		$body    = $this->replace_template_vars( $tmpl['email_body'] ?? '', $booking );

		if ( empty( $subject ) || empty( $body ) ) {
			return;
		}

		// Convert plain-text newlines to <br> if body looks like plain text
		if ( false === strpos( $body, '<' ) ) {
			$body = nl2br( esc_html( $body ) );
		}

		$property_id = $booking['property_id'] ?? 0;

		// Property-level from name/email override, falling back to global settings.
		$from_name  = ( $property_id && get_post_meta( $property_id, 'str_from_name', true ) )
			? get_post_meta( $property_id, 'str_from_name', true )
			: get_option( 'str_booking_from_name', get_bloginfo( 'name' ) );
		$from_email = ( $property_id && get_post_meta( $property_id, 'str_from_email', true ) )
			? get_post_meta( $property_id, 'str_from_email', true )
			: get_option( 'str_booking_from_email', get_option( 'admin_email' ) );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			sprintf( 'From: %s <%s>', $from_name, $from_email ),
		);

		// BCC admin on booking confirmation if configured.
		if ( 'booking_confirmation' === $type && get_option( 'str_booking_admin_copy_enabled' ) ) {
			$admin_email = get_option( 'str_booking_admin_copy_email', get_option( 'admin_email' ) );
			if ( ! empty( $admin_email ) ) {
				$headers[] = 'Bcc: ' . sanitize_email( $admin_email );
			}
		}

		wp_mail( $booking['guest_email'], $subject, $body, $headers );
	}

	/**
	 * Send SMS notification using the customizable template from WP options.
	 *
	 * @param array  $booking Booking data array.
	 * @param string $type    Notification type.
	 */
	private function send_sms_notification( array $booking, string $type ): void {
		$tmpl = NotificationSettings::get_template( $type );
		$body = trim( $tmpl['sms_body'] ?? '' );

		if ( empty( $body ) ) {
			return;
		}

		$message = $this->replace_template_vars( $body, $booking );
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

		$payment_plan_labels = array(
			'pay_in_full'  => __( 'Pay in Full', 'str-direct-booking' ),
			'two_payment'  => __( '2-Payment Plan', 'str-direct-booking' ),
			'four_payment' => __( '4-Payment Plan', 'str-direct-booking' ),
		);
		$payment_plan = get_post_meta( $booking['id'], 'str_payment_plan', true ) ?: 'pay_in_full';

		$installment_amount   = isset( $booking['installment_amount'] )
			? number_format_i18n( (float) $booking['installment_amount'], 2 )
			: '';
		$installment_due_date = isset( $booking['installment_due_date'] )
			? date_i18n( get_option( 'date_format' ), strtotime( $booking['installment_due_date'] ) )
			: '';
		$installment_number   = isset( $booking['installment_number'] )
			? (int) $booking['installment_number']
			: '';

		$replacements = array(
			'{guest_name}'           => esc_html( $booking['guest_name'] ),
			'{guest_email}'          => esc_html( $booking['guest_email'] ),
			'{property_name}'        => esc_html( get_the_title( $property_id ) ),
			'{check_in_date}'        => esc_html( date_i18n( get_option( 'date_format' ), strtotime( $booking['check_in'] ) ) ),
			'{check_out_date}'       => esc_html( date_i18n( get_option( 'date_format' ), strtotime( $booking['check_out'] ) ) ),
			'{check_in_time}'        => esc_html( get_post_meta( $property_id, 'str_check_in_time', true ) ?: '3:00 PM' ),
			'{check_out_time}'       => esc_html( get_post_meta( $property_id, 'str_check_out_time', true ) ?: '11:00 AM' ),
			'{door_code}'            => esc_html( $this->resolve_door_code( $property_id, $booking ) ),
			'{wifi_password}'        => esc_html( get_post_meta( $property_id, 'str_wifi_password', true ) ),
			'{host_phone}'           => esc_html( get_post_meta( $property_id, 'str_host_phone', true ) ),
			'{address}'              => esc_html( get_post_meta( $property_id, 'str_address', true ) ),
			'{total}'                => number_format_i18n( $booking['total'], 2 ),
			'{nights}'               => (int) $booking['nights'],
			'{booking_id}'           => $booking['id'],
			'{installment_amount}'   => $installment_amount,
			'{installment_due_date}' => esc_html( $installment_due_date ),
			'{installment_number}'   => $installment_number,
			'{payment_plan_type}'    => esc_html( $payment_plan_labels[ $payment_plan ] ?? $payment_plan ),
			'{google_calendar_url}'  => esc_url( $this->build_google_calendar_url( $booking, $property_id ) ),
			'{message_url}'          => esc_url( \STRBooking\STRBooking::get_instance()->messaging->get_message_url( (int) $booking['id'] ) ),
		);

		return str_replace( array_keys( $replacements ), array_values( $replacements ), $template );
	}

	/**
	 * Build a Google Calendar "Add to Calendar" URL for a booking.
	 *
	 * Uses an all-day event spanning check-in through checkout (end date is
	 * exclusive in Google Calendar, so checkout day itself is the boundary).
	 *
	 * @param array $booking     Booking data array.
	 * @param int   $property_id Property post ID.
	 * @return string Google Calendar URL.
	 */
	private function build_google_calendar_url( array $booking, int $property_id ): string {
		$property_name = get_the_title( $property_id );

		$check_in  = new \DateTime( $booking['check_in'] );
		$check_out = new \DateTime( $booking['check_out'] );
		// Google Calendar all-day end date is exclusive, so add 1 day so the
		// checkout date itself is visible on the guest's calendar.
		$check_out->modify( '+1 day' );

		return add_query_arg(
			array(
				'action'  => 'TEMPLATE',
				'text'    => 'Stay at ' . $property_name,
				'dates'   => $check_in->format( 'Ymd' ) . '/' . $check_out->format( 'Ymd' ),
				'details' => 'Booking #' . $booking['id'],
			),
			'https://calendar.google.com/calendar/render'
		);
	}

	/**
	 * Resolve the door code for a booking.
	 *
	 * If the property is configured to use the last 4 digits of the guest's
	 * phone number, strip non-digits and return the last 4; otherwise return
	 * the manually-set door code.
	 *
	 * @param int   $property_id Property post ID.
	 * @param array $booking     Booking data array.
	 * @return string
	 */
	private function resolve_door_code( int $property_id, array $booking ): string {
		if ( get_post_meta( $property_id, 'str_door_code_use_phone', true ) ) {
			$phone  = $booking['guest_phone'] ?? '';
			$digits = preg_replace( '/\D/', '', $phone );
			if ( strlen( $digits ) >= 4 ) {
				return substr( $digits, -4 );
			}
		}
		return get_post_meta( $property_id, 'str_door_code', true );
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

	// -------------------------------------------------------------------------
	// Messaging notifications
	// -------------------------------------------------------------------------

	/**
	 * Notify the host when a guest sends a message.
	 * Hooked into str_guest_message_sent.
	 *
	 * @param int    $booking_id Booking post ID.
	 * @param string $message    Guest's message text.
	 */
	public function notify_host_of_message( int $booking_id, string $message ): void {
		$property_id   = (int) get_post_meta( $booking_id, 'str_property_id', true );
		$guest_name    = get_post_meta( $booking_id, 'str_guest_name', true );
		$property_name = get_the_title( $property_id );

		// Determine host email (same logic as send_email_notification).
		$host_email = get_post_meta( $property_id, 'str_from_email', true )
			?: get_option( 'str_booking_from_email', get_option( 'admin_email' ) );

		if ( ! $host_email ) {
			return;
		}

		$inbox_url = admin_url( 'admin.php?page=str-booking-messages&booking_id=' . $booking_id );
		$subject   = sprintf( __( 'New message from %s — %s', 'str-direct-booking' ), $guest_name, $property_name );

		$body = '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;max-width:560px;margin:0 auto;padding:32px 24px;background:#f5f5f5">'
			. '<div style="background:#fff;border-radius:8px;padding:32px;border:1px solid #e5e5e5">'
			. '<h2 style="margin:0 0 8px;font-size:18px;color:#1a1a2e">💬 New message from ' . esc_html( $guest_name ) . '</h2>'
			. '<p style="margin:0 0 20px;font-size:13px;color:#888">' . esc_html( $property_name ) . '</p>'
			. '<blockquote style="margin:0 0 24px;padding:14px 18px;background:#f8f9fa;border-left:3px solid #1a1a2e;border-radius:4px;font-size:15px;color:#333">'
			. nl2br( esc_html( $message ) )
			. '</blockquote>'
			. '<a href="' . esc_url( $inbox_url ) . '" style="display:inline-block;background:#1a1a2e;color:#fff;text-decoration:none;padding:12px 24px;border-radius:6px;font-size:14px;font-weight:600">View &amp; Reply →</a>'
			. '</div></div>';

		wp_mail( $host_email, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	/**
	 * Notify the guest when the host sends a reply.
	 * Hooked into str_host_message_sent.
	 *
	 * @param int    $booking_id Booking post ID.
	 * @param string $message    Host's reply text.
	 */
	public function notify_guest_of_reply( int $booking_id, string $message ): void {
		$property_id   = (int) get_post_meta( $booking_id, 'str_property_id', true );
		$guest_email   = get_post_meta( $booking_id, 'str_guest_email', true );
		$guest_name    = get_post_meta( $booking_id, 'str_guest_name', true );
		$property_name = get_the_title( $property_id );

		if ( ! $guest_email ) {
			return;
		}

		$messaging     = \STRBooking\STRBooking::get_instance()->messaging;
		$message_url   = $messaging->get_message_url( $booking_id );

		$from_name  = get_post_meta( $property_id, 'str_from_name', true )
			?: get_option( 'str_booking_from_name', get_bloginfo( 'name' ) );
		$from_email = get_post_meta( $property_id, 'str_from_email', true )
			?: get_option( 'str_booking_from_email', get_option( 'admin_email' ) );

		$subject = sprintf( __( 'Reply from your host — %s', 'str-direct-booking' ), $property_name );

		$body = '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;max-width:560px;margin:0 auto;padding:32px 24px;background:#f5f5f5">'
			. '<div style="background:#fff;border-radius:8px;padding:32px;border:1px solid #e5e5e5">'
			. '<h2 style="margin:0 0 8px;font-size:18px;color:#1a1a2e">💬 ' . esc_html__( 'Your host replied', 'str-direct-booking' ) . '</h2>'
			. '<p style="margin:0 0 20px;font-size:13px;color:#888">Hi ' . esc_html( $guest_name ) . ', you have a new reply from your host at ' . esc_html( $property_name ) . '.</p>'
			. '<blockquote style="margin:0 0 24px;padding:14px 18px;background:#1a1a2e;border-radius:8px;font-size:15px;color:#fff">'
			. nl2br( esc_html( $message ) )
			. '</blockquote>'
			. ( $message_url
				? '<a href="' . esc_url( $message_url ) . '" style="display:inline-block;background:#1a1a2e;color:#fff;text-decoration:none;padding:12px 24px;border-radius:6px;font-size:14px;font-weight:600">View conversation →</a>'
				: ''
			)
			. '</div></div>';

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $from_name . ' <' . $from_email . '>',
		);

		wp_mail( $guest_email, $subject, $body, $headers );
	}
}
