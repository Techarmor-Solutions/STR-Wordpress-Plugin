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
		add_action( 'admin_head', array( $this, 'output_gateway_styles' ) );
		add_action( 'admin_footer', array( $this, 'output_gateway_script' ) );
	}

	/**
	 * Register all plugin settings.
	 */
	public function register_settings(): void {
		// Register gateway settings first so they appear at the top
		$this->register_gateway_settings();

		// ── Stripe Settings ──────────────────────────────────────────────────
		add_settings_section(
			'str_booking_stripe',
			__( 'Stripe Settings', 'str-direct-booking' ),
			function () {
				echo '<div id="str-stripe-section">';
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

		// Stripe section closing div — rendered via a dummy field with no label
		add_settings_field(
			'str_booking_stripe_section_end',
			'',
			function () {
				echo '</div><!-- #str-stripe-section -->';
			},
			'str-booking-settings',
			'str_booking_stripe'
		);

		// ── Square Settings ───────────────────────────────────────────────────
		add_settings_section(
			'str_booking_square_creds',
			__( 'Square Settings', 'str-direct-booking' ),
			function () {
				echo '<div id="str-square-section">';
				echo '<p>' . esc_html__( 'Configure your Square integration.', 'str-direct-booking' ) . '</p>';
			},
			'str-booking-settings'
		);

		$square_fields = array(
			'str_booking_square_application_id' => array(
				'label'       => __( 'Application ID', 'str-direct-booking' ),
				'type'        => 'text',
				'description' => __( 'Your Square application ID (used by the Web Payments SDK on the frontend).', 'str-direct-booking' ),
			),
			'str_booking_square_access_token'   => array(
				'label'       => __( 'Access Token', 'str-direct-booking' ),
				'type'        => 'password',
				'description' => __( 'Your Square access token. Never share this.', 'str-direct-booking' ),
			),
			'str_booking_square_location_id'    => array(
				'label'       => __( 'Location ID', 'str-direct-booking' ),
				'type'        => 'text',
				'description' => __( 'Your Square location ID.', 'str-direct-booking' ),
			),
		);

		foreach ( $square_fields as $option_name => $field ) {
			register_setting( 'str_booking_settings', $option_name, array( 'sanitize_callback' => 'sanitize_text_field' ) );
			add_settings_field(
				$option_name,
				$field['label'],
				array( $this, 'render_field' ),
				'str-booking-settings',
				'str_booking_square_creds',
				array(
					'option_name' => $option_name,
					'type'        => $field['type'],
					'description' => $field['description'],
				)
			);
		}

		// Square environment select
		register_setting(
			'str_booking_settings',
			'str_booking_square_environment',
			array(
				'sanitize_callback' => function ( $value ) {
					return in_array( $value, array( 'sandbox', 'production' ), true ) ? $value : 'sandbox';
				},
				'default' => 'sandbox',
			)
		);

		add_settings_field(
			'str_booking_square_environment',
			__( 'Environment', 'str-direct-booking' ),
			array( $this, 'render_square_environment_field' ),
			'str-booking-settings',
			'str_booking_square_creds'
		);

		// Square section closing div
		add_settings_field(
			'str_booking_square_section_end',
			'',
			function () {
				echo '</div><!-- #str-square-section -->';
			},
			'str-booking-settings',
			'str_booking_square_creds'
		);

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

		// ── Updates ───────────────────────────────────────────────────────────
		add_settings_section(
			'str_booking_updates',
			__( 'Updates', 'str-direct-booking' ),
			function () {
				echo '<p>' . esc_html__( 'Configure automatic updates from GitHub Releases.', 'str-direct-booking' ) . '</p>';
			},
			'str-booking-settings'
		);

		register_setting(
			'str_booking_settings',
			'str_booking_github_token',
			array( 'sanitize_callback' => 'sanitize_text_field' )
		);

		add_settings_field(
			'str_booking_github_token',
			__( 'GitHub Personal Access Token', 'str-direct-booking' ),
			array( $this, 'render_field' ),
			'str-booking-settings',
			'str_booking_updates',
			array(
				'option_name' => 'str_booking_github_token',
				'type'        => 'password',
				'description' => __( 'Required only for private repositories. Leave blank for public repos. Generate at GitHub → Settings → Developer Settings → Personal Access Tokens.', 'str-direct-booking' ),
			)
		);
	}

	/**
	 * Register the payment gateway selector and Square credential options.
	 */
	private function register_gateway_settings(): void {
		// Gateway selector option
		register_setting(
			'str_booking_settings',
			'str_booking_payment_gateway',
			array(
				'sanitize_callback' => function ( $value ) {
					return in_array( $value, array( 'stripe', 'square' ), true ) ? $value : 'stripe';
				},
				'default' => 'stripe',
			)
		);

		// Gateway selector section — renders at top of settings page
		add_settings_section(
			'str_booking_gateway',
			__( 'Payment Gateway', 'str-direct-booking' ),
			array( $this, 'render_gateway_cards' ),
			'str-booking-settings'
		);
	}

	/**
	 * Render the gateway card selector UI.
	 */
	public function render_gateway_cards(): void {
		$current = get_option( 'str_booking_payment_gateway', 'stripe' );
		?>
		<p><?php esc_html_e( 'Choose how you collect payments from guests.', 'str-direct-booking' ); ?></p>

		<div class="str-gateway-cards">

			<label class="str-gateway-card <?php echo 'stripe' === $current ? 'str-gateway-card--active' : ''; ?>" for="str-gateway-stripe">
				<div class="str-gateway-card__header">
					<input
						type="radio"
						id="str-gateway-stripe"
						name="str_booking_payment_gateway"
						value="stripe"
						<?php checked( $current, 'stripe' ); ?>
					/>
					<strong><?php esc_html_e( 'Stripe', 'str-direct-booking' ); ?></strong>
					<?php if ( 'stripe' === $current ) : ?>
						<span class="str-gateway-badge"><?php esc_html_e( 'Active', 'str-direct-booking' ); ?></span>
					<?php endif; ?>
				</div>
				<ul class="str-gateway-features">
					<li class="str-feature--yes"><?php esc_html_e( 'Pay in full', 'str-direct-booking' ); ?></li>
					<li class="str-feature--yes"><?php esc_html_e( 'Payment plans', 'str-direct-booking' ); ?></li>
					<li class="str-feature--yes"><?php esc_html_e( 'Co-host splits', 'str-direct-booking' ); ?></li>
					<li class="str-feature--yes"><?php esc_html_e( 'Installments', 'str-direct-booking' ); ?></li>
				</ul>
			</label>

			<label class="str-gateway-card <?php echo 'square' === $current ? 'str-gateway-card--active' : ''; ?>" for="str-gateway-square">
				<div class="str-gateway-card__header">
					<input
						type="radio"
						id="str-gateway-square"
						name="str_booking_payment_gateway"
						value="square"
						<?php checked( $current, 'square' ); ?>
					/>
					<strong><?php esc_html_e( 'Square', 'str-direct-booking' ); ?></strong>
					<?php if ( 'square' === $current ) : ?>
						<span class="str-gateway-badge"><?php esc_html_e( 'Active', 'str-direct-booking' ); ?></span>
					<?php endif; ?>
				</div>
				<ul class="str-gateway-features">
					<li class="str-feature--yes"><?php esc_html_e( 'Pay in full', 'str-direct-booking' ); ?></li>
					<li class="str-feature--no"><?php esc_html_e( 'Payment plans', 'str-direct-booking' ); ?></li>
					<li class="str-feature--no"><?php esc_html_e( 'Co-host splits', 'str-direct-booking' ); ?></li>
					<li class="str-feature--no"><?php esc_html_e( 'Installments', 'str-direct-booking' ); ?></li>
				</ul>
			</label>

		</div>

		<p class="str-gateway-notice">
			<span class="dashicons dashicons-warning"></span>
			<?php esc_html_e( 'Co-host splits and payment plans require Stripe.', 'str-direct-booking' ); ?>
		</p>
		<?php
	}

	/**
	 * Output gateway card styles in admin head (only on settings page).
	 */
	public function output_gateway_styles(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! str_contains( $screen->id, 'str-booking' ) ) {
			return;
		}
		?>
		<style>
		.str-gateway-cards {
			display: flex;
			gap: 16px;
			margin: 12px 0 8px;
			flex-wrap: wrap;
		}
		.str-gateway-card {
			display: block;
			width: 220px;
			padding: 16px;
			border: 2px solid #ddd;
			border-radius: 8px;
			background: #fff;
			cursor: pointer;
			transition: border-color 0.15s, box-shadow 0.15s;
			box-sizing: border-box;
		}
		.str-gateway-card:hover {
			border-color: #999;
		}
		.str-gateway-card--active {
			border-color: #2271b1;
			box-shadow: 0 0 0 2px rgba(34,113,177,0.15);
			background: #f0f6fc;
		}
		.str-gateway-card__header {
			display: flex;
			align-items: center;
			gap: 8px;
			margin-bottom: 10px;
		}
		.str-gateway-card__header strong {
			font-size: 15px;
		}
		.str-gateway-badge {
			margin-left: auto;
			background: #2271b1;
			color: #fff;
			font-size: 10px;
			font-weight: 600;
			padding: 2px 6px;
			border-radius: 10px;
			text-transform: uppercase;
			letter-spacing: 0.5px;
		}
		.str-gateway-features {
			margin: 0;
			padding: 0;
			list-style: none;
		}
		.str-gateway-features li {
			font-size: 13px;
			line-height: 1.6;
			padding-left: 18px;
			position: relative;
			color: #444;
		}
		.str-gateway-features li::before {
			position: absolute;
			left: 0;
			font-weight: bold;
		}
		.str-feature--yes::before {
			content: "✓";
			color: #00a32a;
		}
		.str-feature--no::before {
			content: "✗";
			color: #d63638;
		}
		.str-gateway-notice {
			margin-top: 8px;
			color: #856404;
			font-size: 13px;
		}
		.str-gateway-notice .dashicons {
			font-size: 16px;
			vertical-align: middle;
			color: #b5830e;
		}
		</style>
		<?php
	}

	/**
	 * Output gateway toggle JS in admin footer (only on settings page).
	 */
	public function output_gateway_script(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! str_contains( $screen->id, 'str-booking' ) ) {
			return;
		}
		?>
		<script>
		(function() {
			document.addEventListener('DOMContentLoaded', function() {
				var stripeSection = document.getElementById('str-stripe-section');
				var squareSection = document.getElementById('str-square-section');
				var radios = document.querySelectorAll('input[name="str_booking_payment_gateway"]');
				var cards  = document.querySelectorAll('.str-gateway-card');

				function applyGateway(val) {
					if (!stripeSection || !squareSection) return;

					stripeSection.style.display = (val === 'stripe') ? '' : 'none';
					squareSection.style.display = (val === 'square') ? '' : 'none';

					cards.forEach(function(card) {
						var radio = card.querySelector('input[type="radio"]');
						if (!radio) return;
						card.classList.toggle('str-gateway-card--active', radio.value === val);
					});
				}

				// Set initial state
				var checked = document.querySelector('input[name="str_booking_payment_gateway"]:checked');
				applyGateway(checked ? checked.value : 'stripe');

				// Listen for changes
				radios.forEach(function(radio) {
					radio.addEventListener('change', function() {
						applyGateway(this.value);
					});
				});
			});
		})();
		</script>
		<?php
	}

	/**
	 * Render Square environment select field.
	 */
	public function render_square_environment_field(): void {
		$current = get_option( 'str_booking_square_environment', 'sandbox' );
		?>
		<select id="str_booking_square_environment" name="str_booking_square_environment">
			<option value="sandbox" <?php selected( $current, 'sandbox' ); ?>><?php esc_html_e( 'Sandbox (testing)', 'str-direct-booking' ); ?></option>
			<option value="production" <?php selected( $current, 'production' ); ?>><?php esc_html_e( 'Production (live)', 'str-direct-booking' ); ?></option>
		</select>
		<p class="description"><?php esc_html_e( 'Use Sandbox for testing. Switch to Production when ready to accept real payments.', 'str-direct-booking' ); ?></p>
		<?php
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
	 * Show admin notice if webhook secret is not configured (Stripe only).
	 */
	public function show_webhook_notice(): void {
		$screen = get_current_screen();

		if ( ! $screen || ! str_contains( $screen->id, 'str-booking' ) ) {
			return;
		}

		// Only show webhook notice when Stripe is the active gateway
		if ( 'stripe' !== get_option( 'str_booking_payment_gateway', 'stripe' ) ) {
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
