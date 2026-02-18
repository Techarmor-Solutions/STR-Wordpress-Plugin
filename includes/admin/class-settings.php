<?php
/**
 * Settings — WordPress Settings API integration.
 *
 * @package STRBooking\Admin
 */

namespace STRBooking\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers all plugin settings via the WordPress Settings API.
 */
class Settings {

	public function __construct() {
		add_action( 'admin_init', array( $this, 'register_settings' ), 10 );
		add_action( 'admin_notices', array( $this, 'show_webhook_notice' ) );
	}

	/**
	 * Register all plugin settings.
	 */
	public function register_settings(): void {
		// ── Stripe Settings ──────────────────────────────────────────────────
		add_settings_section(
			'str_booking_stripe',
			__( 'Stripe Settings', 'str-direct-booking' ),
			function () {
				echo '<p>' . esc_html__( 'Configure your Stripe integration.', 'str-direct-booking' ) . '</p>';
			},
			'str-booking-settings'
		);

		$stripe_fields = array(
			'str_booking_stripe_publishable_key' => array(
				'label'       => __( 'Publishable Key', 'str-direct-booking' ),
				'type'        => 'text',
				'description' => __( 'Your Stripe publishable key (pk_live_... or pk_test_...)', 'str-direct-booking' ),
			),
			'str_booking_stripe_secret_key'      => array(
				'label'       => __( 'Secret Key', 'str-direct-booking' ),
				'type'        => 'password',
				'description' => __( 'Your Stripe secret key. Never share this.', 'str-direct-booking' ),
			),
			'str_booking_stripe_webhook_secret'  => array(
				'label'       => __( 'Webhook Secret', 'str-direct-booking' ),
				'type'        => 'password',
				'description' => __( 'The signing secret from your Stripe webhook endpoint.', 'str-direct-booking' ),
			),
			'str_booking_stripe_connect_client_id' => array(
				'label'       => __( 'Connect Client ID', 'str-direct-booking' ),
				'type'        => 'text',
				'description' => __( 'Your Stripe Connect platform client_id for co-host onboarding.', 'str-direct-booking' ),
			),
		);

		foreach ( $stripe_fields as $option_name => $field ) {
			register_setting( 'str_booking_settings', $option_name, array( 'sanitize_callback' => 'sanitize_text_field' ) );
			add_settings_field(
				$option_name,
				$field['label'],
				array( $this, 'render_field' ),
				'str-booking-settings',
				'str_booking_stripe',
				array(
					'option_name' => $option_name,
					'type'        => $field['type'],
					'description' => $field['description'],
				)
			);
		}

		// ── Taxes & Currency ─────────────────────────────────────────────────
		add_settings_section(
			'str_booking_taxes',
			__( 'Taxes & Currency', 'str-direct-booking' ),
			null,
			'str-booking-settings'
		);

		register_setting(
			'str_booking_settings',
			'str_booking_tax_rate',
			array(
				'sanitize_callback' => function ( $value ) {
					return max( 0, min( 1, (float) $value ) );
				},
			)
		);

		add_settings_field(
			'str_booking_tax_rate',
			__( 'Default Tax Rate', 'str-direct-booking' ),
			array( $this, 'render_field' ),
			'str-booking-settings',
			'str_booking_taxes',
			array(
				'option_name' => 'str_booking_tax_rate',
				'type'        => 'number',
				'step'        => '0.001',
				'min'         => '0',
				'max'         => '1',
				'description' => __( 'Enter as decimal (e.g., 0.12 for 12%). Can be overridden per property.', 'str-direct-booking' ),
			)
		);

		register_setting(
			'str_booking_settings',
			'str_booking_currency',
			array( 'sanitize_callback' => 'sanitize_text_field', 'default' => 'usd' )
		);

		add_settings_field(
			'str_booking_currency',
			__( 'Currency', 'str-direct-booking' ),
			array( $this, 'render_currency_field' ),
			'str-booking-settings',
			'str_booking_taxes'
		);

		// ── Notifications ────────────────────────────────────────────────────
		add_settings_section(
			'str_booking_notifications',
			__( 'Notifications', 'str-direct-booking' ),
			function () {
				echo '<p>' . esc_html__( 'Configure email and SMS settings.', 'str-direct-booking' ) . '</p>';
			},
			'str-booking-settings'
		);

		$notification_fields = array(
			'str_booking_from_name'  => array(
				'label'       => __( 'From Name', 'str-direct-booking' ),
				'type'        => 'text',
				'description' => __( 'Sender name for guest emails.', 'str-direct-booking' ),
			),
			'str_booking_from_email' => array(
				'label'       => __( 'From Email', 'str-direct-booking' ),
				'type'        => 'email',
				'description' => __( 'Sender email address for guest emails.', 'str-direct-booking' ),
			),
			'str_booking_twilio_account_sid' => array(
				'label'       => __( 'Twilio Account SID', 'str-direct-booking' ),
				'type'        => 'text',
				'description' => __( 'Optional. Required for SMS notifications.', 'str-direct-booking' ),
			),
			'str_booking_twilio_auth_token'  => array(
				'label'       => __( 'Twilio Auth Token', 'str-direct-booking' ),
				'type'        => 'password',
				'description' => __( 'Optional. Twilio authentication token.', 'str-direct-booking' ),
			),
			'str_booking_twilio_from_number' => array(
				'label'       => __( 'Twilio From Number', 'str-direct-booking' ),
				'type'        => 'text',
				'description' => __( 'Optional. Your Twilio phone number (e.g., +15551234567).', 'str-direct-booking' ),
			),
		);

		foreach ( $notification_fields as $option_name => $field ) {
			register_setting( 'str_booking_settings', $option_name, array( 'sanitize_callback' => 'sanitize_text_field' ) );
			add_settings_field(
				$option_name,
				$field['label'],
				array( $this, 'render_field' ),
				'str-booking-settings',
				'str_booking_notifications',
				array(
					'option_name' => $option_name,
					'type'        => $field['type'],
					'description' => $field['description'],
				)
			);
		}
	}

	/**
	 * Render a generic settings field.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_field( array $args ): void {
		$option_name = $args['option_name'];
		$type        = $args['type'] ?? 'text';
		$value       = get_option( $option_name, '' );
		$description = $args['description'] ?? '';

		$extra = '';
		if ( isset( $args['step'] ) ) {
			$extra .= sprintf( ' step="%s"', esc_attr( $args['step'] ) );
		}
		if ( isset( $args['min'] ) ) {
			$extra .= sprintf( ' min="%s"', esc_attr( $args['min'] ) );
		}
		if ( isset( $args['max'] ) ) {
			$extra .= sprintf( ' max="%s"', esc_attr( $args['max'] ) );
		}

		printf(
			'<input type="%s" id="%s" name="%s" value="%s" class="regular-text"%s />',
			esc_attr( $type ),
			esc_attr( $option_name ),
			esc_attr( $option_name ),
			'password' === $type ? '' : esc_attr( $value ),
			$extra
		);

		if ( $description ) {
			printf( '<p class="description">%s</p>', esc_html( $description ) );
		}
	}

	/**
	 * Render currency select field.
	 */
	public function render_currency_field(): void {
		$current = get_option( 'str_booking_currency', 'usd' );

		$currencies = array(
			'usd' => 'USD — US Dollar',
			'eur' => 'EUR — Euro',
			'gbp' => 'GBP — British Pound',
			'cad' => 'CAD — Canadian Dollar',
			'aud' => 'AUD — Australian Dollar',
			'jpy' => 'JPY — Japanese Yen',
			'mxn' => 'MXN — Mexican Peso',
		);

		echo '<select id="str_booking_currency" name="str_booking_currency">';
		foreach ( $currencies as $code => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $code ),
				selected( $current, $code, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	/**
	 * Show admin notice if webhook secret is not configured.
	 */
	public function show_webhook_notice(): void {
		$screen = get_current_screen();

		if ( ! $screen || ! str_contains( $screen->id, 'str-booking' ) ) {
			return;
		}

		$webhook_secret = get_option( 'str_booking_stripe_webhook_secret', '' );

		if ( ! empty( $webhook_secret ) ) {
			return;
		}

		$webhook_url = rest_url( 'str-booking/v1/stripe-webhook' );

		printf(
			'<div class="notice notice-warning"><p>%s <code>%s</code> %s <a href="%s">%s</a></p></div>',
			esc_html__( 'STR Booking: Stripe webhook is not configured. Add this URL to your Stripe dashboard:', 'str-direct-booking' ),
			esc_html( $webhook_url ),
			esc_html__( 'Then enter the webhook signing secret in', 'str-direct-booking' ),
			esc_url( admin_url( 'admin.php?page=str-booking-settings' ) ),
			esc_html__( 'Settings', 'str-direct-booking' )
		);
	}
}
