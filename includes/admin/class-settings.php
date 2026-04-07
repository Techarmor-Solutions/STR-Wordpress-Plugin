<?php
/**
 * Settings — WordPress Settings API integration.
 *
 * @package STRBooking\Admin
 */

namespace STRBooking\Admin;

use STRBooking\LicenseManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers all plugin settings via the WordPress Settings API.
 */
class Settings {

	/**
	 * @var LicenseManager
	 */
	private LicenseManager $license_manager;

	public function __construct( LicenseManager $license_manager ) {
		$this->license_manager = $license_manager;
		add_action( 'admin_init', array( $this, 'register_settings' ), 10 );
		add_action( 'admin_notices', array( $this, 'show_webhook_notice' ) );
		add_action( 'admin_head', array( $this, 'output_gateway_styles' ) );
		add_action( 'admin_footer', array( $this, 'output_gateway_script' ) );
		add_action( 'admin_post_str_validate_license', array( $this, 'handle_license_save' ) );
		add_action( 'admin_post_str_deactivate_license', array( $this, 'handle_license_deactivate' ) );
		add_action( 'str_render_license_section', array( $this, 'render_license_section' ) );
	}

	/**
	 * Return a sanitize callback that preserves the existing option value when
	 * an empty string is submitted — prevents password fields from clearing on save.
	 *
	 * @param string $option_name WP option name.
	 * @return \Closure
	 */
	private function preserve_existing_on_empty( string $option_name ): \Closure {
		return static function ( $value ) use ( $option_name ) {
			$value = sanitize_text_field( $value );
			if ( '' === $value ) {
				return get_option( $option_name, '' );
			}
			return $value;
		};
	}

	/**
	 * Register all plugin settings.
	 */
	public function register_settings(): void {
		// License section is rendered outside the main settings form via
		// the str_render_license_section action (avoids nested <form> issue).

		// Register gateway settings first so they appear at the top
		$this->register_gateway_settings();

		// ── Stripe Settings ──────────────────────────────────────────────────
		// Fields are registered for saving but rendered inline in the section
		// callback so the wrapping <div> properly contains the <table>.
		$stripe_fields = array(
			'str_booking_stripe_publishable_key'   => array(
				'label'       => __( 'Publishable Key', 'str-direct-booking' ),
				'type'        => 'text',
				'description' => __( 'Your Stripe publishable key (pk_live_... or pk_test_...)', 'str-direct-booking' ),
			),
			'str_booking_stripe_secret_key'        => array(
				'label'       => __( 'Secret Key', 'str-direct-booking' ),
				'type'        => 'password',
				'description' => __( 'Your Stripe secret key. Never share this.', 'str-direct-booking' ),
			),
			'str_booking_stripe_webhook_secret'    => array(
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
			$callback = 'password' === ( $field['type'] ?? 'text' )
				? $this->preserve_existing_on_empty( $option_name )
				: 'sanitize_text_field';
			register_setting( 'str_booking_settings', $option_name, array( 'sanitize_callback' => $callback ) );
		}

		// Empty title — we render the <h2> manually inside the div so JS can
		// show/hide the heading together with the fields.
		add_settings_section(
			'str_booking_stripe',
			'',
			function () use ( $stripe_fields ) {
				echo '<div id="str-stripe-section">';
				echo '<h2>' . esc_html__( 'Stripe Settings', 'str-direct-booking' ) . '</h2>';
				echo '<p>' . esc_html__( 'Configure your Stripe integration.', 'str-direct-booking' ) . '</p>';
				$this->render_fields_table( $stripe_fields );
				echo '</div>';
			},
			'str-booking-settings'
		);

		// ── Square Settings ───────────────────────────────────────────────────
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
			$callback = 'password' === ( $field['type'] ?? 'text' )
				? $this->preserve_existing_on_empty( $option_name )
				: 'sanitize_text_field';
			register_setting( 'str_booking_settings', $option_name, array( 'sanitize_callback' => $callback ) );
		}

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

		add_settings_section(
			'str_booking_square_creds',
			'',
			function () use ( $square_fields ) {
				echo '<div id="str-square-section">';
				echo '<h2>' . esc_html__( 'Square Settings', 'str-direct-booking' ) . '</h2>';
				echo '<p>' . esc_html__( 'Configure your Square integration.', 'str-direct-booking' ) . '</p>';
				$this->render_fields_table( $square_fields );
				// Environment select
				$env = get_option( 'str_booking_square_environment', 'sandbox' );
				echo '<table class="form-table" role="presentation"><tbody>';
				echo '<tr>';
				printf( '<th scope="row"><label for="str_booking_square_environment">%s</label></th>', esc_html__( 'Environment', 'str-direct-booking' ) );
				echo '<td>';
				echo '<select id="str_booking_square_environment" name="str_booking_square_environment">';
				printf( '<option value="sandbox"%s>%s</option>', selected( $env, 'sandbox', false ), esc_html__( 'Sandbox (testing)', 'str-direct-booking' ) );
				printf( '<option value="production"%s>%s</option>', selected( $env, 'production', false ), esc_html__( 'Production (live)', 'str-direct-booking' ) );
				echo '</select>';
				echo '<p class="description">' . esc_html__( 'Use Sandbox for testing. Switch to Production when ready to accept real payments.', 'str-direct-booking' ) . '</p>';
				echo '</td>';
				echo '</tr>';
				echo '</tbody></table>';
				echo '</div>';
			},
			'str-booking-settings'
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
			$callback = 'password' === ( $field['type'] ?? 'text' )
				? $this->preserve_existing_on_empty( $option_name )
				: 'sanitize_text_field';
			register_setting( 'str_booking_settings', $option_name, array( 'sanitize_callback' => $callback ) );
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
			array( 'sanitize_callback' => $this->preserve_existing_on_empty( 'str_booking_github_token' ) )
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
	 * Render the license section at the top of the settings page.
	 */
	public function render_license_section(): void {
		$info       = $this->license_manager->get_status_info();
		$stored_key = $this->license_manager->get_stored_key();
		$status     = $info['status'];

		$badge_colors = array(
			'active'  => '#00a32a',
			'grace'   => '#b5830e',
			'missing' => '#646970',
			'default' => '#d63638',
		);
		$badge_color = $badge_colors[ $status ] ?? $badge_colors['default'];

		$badge_labels = array(
			'active'       => __( 'Active', 'str-direct-booking' ),
			'grace'        => __( 'Grace Period', 'str-direct-booking' ),
			'missing'      => __( 'No License', 'str-direct-booking' ),
			'invalid'      => __( 'Invalid', 'str-direct-booking' ),
			'not_found'    => __( 'Key Not Found', 'str-direct-booking' ),
			'revoked'      => __( 'Revoked', 'str-direct-booking' ),
			'expired'      => __( 'Expired', 'str-direct-booking' ),
			'offline'      => __( 'Server Offline', 'str-direct-booking' ),
			'unknown'      => __( 'Unknown', 'str-direct-booking' ),
			'tampered'     => __( 'Verification Failed', 'str-direct-booking' ),
		);
		$badge_label = $badge_labels[ $status ] ?? ucfirst( $status );

		// Show result flash message from redirect.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['str_license_result'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$result = sanitize_text_field( wp_unslash( $_GET['str_license_result'] ) );
			if ( 'valid' === $result ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'License activated successfully.', 'str-direct-booking' ) . '</p></div>';
			} else {
				$flash_msg = isset( $_GET['str_license_message'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					? sanitize_text_field( wp_unslash( $_GET['str_license_message'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					: __( 'License key is invalid or could not be verified.', 'str-direct-booking' );
				printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( $flash_msg ) );
			}
		}

		?>
		<div id="str-license-section" style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:20px 24px;margin-bottom:20px;">
			<div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
				<h2 style="margin:0;"><?php esc_html_e( 'License', 'str-direct-booking' ); ?></h2>
				<span style="background:<?php echo esc_attr( $badge_color ); ?>;color:#fff;font-size:11px;font-weight:600;padding:2px 8px;border-radius:10px;text-transform:uppercase;letter-spacing:0.5px;">
					<?php echo esc_html( $badge_label ); ?>
				</span>
				<?php if ( ! empty( $info['expires_at'] ) ) : ?>
					<span style="color:#646970;font-size:13px;">
						<?php
						printf(
							/* translators: %s: expiry date */
							esc_html__( 'Expires: %s', 'str-direct-booking' ),
							esc_html( $info['expires_at'] )
						);
						?>
					</span>
				<?php endif; ?>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="str_validate_license" />
				<?php wp_nonce_field( 'str_validate_license_nonce', '_wpnonce_license' ); ?>

				<table class="form-table" role="presentation" style="margin-top:0;">
					<tbody>
						<tr>
							<th scope="row">
								<label for="str_booking_license_key"><?php esc_html_e( 'License Key', 'str-direct-booking' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									id="str_booking_license_key"
									name="str_booking_license_key"
									value="<?php echo esc_attr( $stored_key ); ?>"
									class="regular-text"
									placeholder="STRDB-XXXX-XXXX-XXXX-XXXX"
									style="font-family:monospace;"
								/>
								<p class="description"><?php esc_html_e( 'Enter the license key you received after purchase.', 'str-direct-booking' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>

				<p style="margin-top:12px;">
					<?php submit_button( __( 'Activate License', 'str-direct-booking' ), 'primary', 'submit_license', false ); ?>

					<?php if ( ! empty( $stored_key ) ) : ?>
						&nbsp;
						<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=str_deactivate_license' ), 'str_deactivate_license_nonce', '_wpnonce_deactivate' ) ); ?>"
							style="color:#d63638;"
							onclick="return confirm('<?php esc_attr_e( 'Deactivate this license? Booking features will be disabled.', 'str-direct-booking' ); ?>');">
							<?php esc_html_e( 'Deactivate License', 'str-direct-booking' ); ?>
						</a>
					<?php endif; ?>
				</p>
			</form>

			<?php if ( 'active' === $status && ! empty( $info['customer_name'] ) ) : ?>
				<p style="margin:0;color:#646970;font-size:13px;">
					<?php
					printf(
						/* translators: %s: customer name */
						esc_html__( 'Licensed to: %s', 'str-direct-booking' ),
						esc_html( $info['customer_name'] )
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handle license key activation form submission.
	 */
	public function handle_license_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'str-direct-booking' ) );
		}

		check_admin_referer( 'str_validate_license_nonce', '_wpnonce_license' );

		$key    = isset( $_POST['str_booking_license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['str_booking_license_key'] ) ) : '';
		$result = $this->license_manager->activate( $key );

		$redirect = admin_url( 'admin.php?page=str-booking-settings' );

		if ( $result['valid'] ) {
			wp_safe_redirect( add_query_arg( 'str_license_result', 'valid', $redirect ) );
		} else {
			wp_safe_redirect( add_query_arg( array(
				'str_license_result'  => 'invalid',
				'str_license_message' => urlencode( $result['message'] ?? '' ),
			), $redirect ) );
		}

		exit;
	}

	/**
	 * Handle license deactivation request.
	 */
	public function handle_license_deactivate(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'str-direct-booking' ) );
		}

		check_admin_referer( 'str_deactivate_license_nonce', '_wpnonce_deactivate' );

		$this->license_manager->deactivate();

		wp_safe_redirect( admin_url( 'admin.php?page=str-booking-settings' ) );
		exit;
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
	 * Render a complete form-table for an array of fields.
	 * Used by gateway section callbacks to keep the wrapping div valid.
	 *
	 * @param array $fields Keyed by option_name; each has 'label', 'type', 'description'.
	 */
	private function render_fields_table( array $fields ): void {
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( $fields as $option_name => $field ) {
			$type  = $field['type'] ?? 'text';
			$value = get_option( $option_name, '' );
			echo '<tr>';
			printf(
				'<th scope="row"><label for="%s">%s</label></th>',
				esc_attr( $option_name ),
				esc_html( $field['label'] )
			);
			echo '<td>';
			if ( 'password' === $type ) {
				$placeholder = ! empty( $value ) ? __( 'Saved — enter new value to change', 'str-direct-booking' ) : '';
				printf(
					'<input type="password" id="%s" name="%s" value="" placeholder="%s" class="regular-text" autocomplete="new-password" />',
					esc_attr( $option_name ),
					esc_attr( $option_name ),
					esc_attr( $placeholder )
				);
			} else {
				printf(
					'<input type="%s" id="%s" name="%s" value="%s" class="regular-text" />',
					esc_attr( $type ),
					esc_attr( $option_name ),
					esc_attr( $option_name ),
					esc_attr( $value )
				);
			}
			if ( ! empty( $field['description'] ) ) {
				printf( '<p class="description">%s</p>', esc_html( $field['description'] ) );
			}
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
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

		if ( 'password' === $type ) {
			$placeholder = ! empty( $value ) ? __( 'Saved — enter new value to change', 'str-direct-booking' ) : '';
			printf(
				'<input type="password" id="%s" name="%s" value="" placeholder="%s" class="regular-text" autocomplete="new-password"%s />',
				esc_attr( $option_name ),
				esc_attr( $option_name ),
				esc_attr( $placeholder ),
				$extra
			);
		} else {
			printf(
				'<input type="%s" id="%s" name="%s" value="%s" class="regular-text"%s />',
				esc_attr( $type ),
				esc_attr( $option_name ),
				esc_attr( $option_name ),
				esc_attr( $value ),
				$extra
			);
		}

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
