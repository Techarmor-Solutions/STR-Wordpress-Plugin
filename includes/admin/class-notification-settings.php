<?php
/**
 * Notification Settings — admin page for customizing notification templates.
 *
 * @package STRBooking\Admin
 */

namespace STRBooking\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Notifications submenu page and saves template options.
 */
class NotificationSettings {

	/**
	 * Notification type definitions.
	 *
	 * @return array
	 */
	public static function get_notification_types(): array {
		return array(
			'booking_confirmation'  => array(
				'label'       => __( 'Booking Confirmation', 'str-direct-booking' ),
				'description' => __( 'Sent immediately when a booking is confirmed (payment succeeded).', 'str-direct-booking' ),
				'has_timing'  => false,
				'channels'    => array( 'email', 'sms' ),
			),
			'booking_reminder'      => array(
				'label'       => __( 'Booking Reminder', 'str-direct-booking' ),
				'description' => __( 'Sent N days before check-in as a general reminder.', 'str-direct-booking' ),
				'has_timing'  => true,
				'channels'    => array( 'email' ),
			),
			'payment_reminder'      => array(
				'label'       => __( 'Payment Reminder', 'str-direct-booking' ),
				'description' => __( 'Sent N days before an installment is due (multi-payment plans only).', 'str-direct-booking' ),
				'has_timing'  => true,
				'channels'    => array( 'email', 'sms' ),
			),
			'payment_received'      => array(
				'label'       => __( 'Payment Received', 'str-direct-booking' ),
				'description' => __( 'Sent immediately after each installment payment is processed.', 'str-direct-booking' ),
				'has_timing'  => false,
				'channels'    => array( 'email' ),
			),
			'pre_arrival'           => array(
				'label'       => __( 'Pre-Arrival', 'str-direct-booking' ),
				'description' => __( 'Sent N days before check-in with property details.', 'str-direct-booking' ),
				'has_timing'  => true,
				'channels'    => array( 'email', 'sms' ),
			),
			'check_in_instructions' => array(
				'label'       => __( 'Check-in Instructions', 'str-direct-booking' ),
				'description' => __( 'Sent on check-in day with door code, WiFi, etc.', 'str-direct-booking' ),
				'has_timing'  => true,
				'channels'    => array( 'sms' ),
			),
			'check_out_reminder'    => array(
				'label'       => __( 'Check-out Reminder', 'str-direct-booking' ),
				'description' => __( 'Sent N days before check-out.', 'str-direct-booking' ),
				'has_timing'  => true,
				'channels'    => array( 'email', 'sms' ),
			),
			'review_request'        => array(
				'label'       => __( 'Review Request', 'str-direct-booking' ),
				'description' => __( 'Sent N days after check-out asking for a review.', 'str-direct-booking' ),
				'has_timing'  => true,
				'channels'    => array( 'email' ),
			),
		);
	}

	/**
	 * Default template values for each notification type.
	 *
	 * @param string $type Notification type slug.
	 * @return array
	 */
	public static function get_defaults( string $type ): array {
		$defaults = array(
			'booking_confirmation'  => array(
				'enabled'       => true,
				'days_before'   => 0,
				'email_subject' => __( 'Your booking is confirmed!', 'str-direct-booking' ),
				'email_body'    => __( "Hi {guest_name},\n\nGreat news — your booking at {property_name} is confirmed!\n\nCheck-in: {check_in_date}\nCheck-out: {check_out_date}\nTotal: {total}\n\nWe look forward to hosting you!", 'str-direct-booking' ),
				'sms_body'      => __( "Hi {guest_name}, your booking at {property_name} is confirmed! Check-in: {check_in_date}. Reply STOP to unsubscribe.", 'str-direct-booking' ),
			),
			'booking_reminder'      => array(
				'enabled'       => true,
				'days_before'   => 7,
				'email_subject' => __( 'Your upcoming stay at {property_name}', 'str-direct-booking' ),
				'email_body'    => __( "Hi {guest_name},\n\nJust a reminder that your stay at {property_name} is coming up!\n\nCheck-in: {check_in_date}\nCheck-out: {check_out_date}\n\nWe can't wait to host you.", 'str-direct-booking' ),
				'sms_body'      => '',
			),
			'payment_reminder'      => array(
				'enabled'       => true,
				'days_before'   => 3,
				'email_subject' => __( 'Payment due soon for your booking at {property_name}', 'str-direct-booking' ),
				'email_body'    => __( "Hi {guest_name},\n\nThis is a reminder that installment #{installment_number} of {total} for your stay at {property_name} is due on {installment_due_date}.\n\nAmount due: {installment_amount}\n\nThis payment will be automatically charged to your card on file.", 'str-direct-booking' ),
				'sms_body'      => __( "Hi {guest_name}, payment #{installment_number} of {installment_amount} for {property_name} is due {installment_due_date}. It will be auto-charged.", 'str-direct-booking' ),
			),
			'payment_received'      => array(
				'enabled'       => true,
				'days_before'   => 0,
				'email_subject' => __( 'Payment received for your booking at {property_name}', 'str-direct-booking' ),
				'email_body'    => __( "Hi {guest_name},\n\nWe've received your payment of {installment_amount} for your upcoming stay at {property_name}.\n\nPayment #{installment_number} — {installment_amount}\nCheck-in: {check_in_date}\n\nThank you!", 'str-direct-booking' ),
				'sms_body'      => '',
			),
			'pre_arrival'           => array(
				'enabled'       => true,
				'days_before'   => 3,
				'email_subject' => __( 'Your stay is coming up — here\'s what to know', 'str-direct-booking' ),
				'email_body'    => __( "Hi {guest_name},\n\nYour stay at {property_name} starts {check_in_date}. Here are the details:\n\nAddress: {address}\nCheck-in time: {check_in_time}\nDoor code: {door_code}\nWiFi password: {wifi_password}\n\nContact: {host_phone}", 'str-direct-booking' ),
				'sms_body'      => __( "Hi {guest_name}, your stay at {property_name} starts {check_in_date}. We'll send check-in details the morning of arrival!", 'str-direct-booking' ),
			),
			'check_in_instructions' => array(
				'enabled'       => true,
				'days_before'   => 0,
				'email_subject' => __( 'Check-in instructions for today', 'str-direct-booking' ),
				'email_body'    => __( "Welcome to {property_name}!\n\nDoor code: {door_code}\nWiFi password: {wifi_password}\nCheck-in time: {check_in_time}\n\nQuestions? Contact us at {host_phone}", 'str-direct-booking' ),
				'sms_body'      => __( "Welcome to {property_name}! Door code: {door_code}. WiFi: {wifi_password}. Questions? Call {host_phone}", 'str-direct-booking' ),
			),
			'check_out_reminder'    => array(
				'enabled'       => true,
				'days_before'   => 1,
				'email_subject' => __( 'Check-out reminder', 'str-direct-booking' ),
				'email_body'    => __( "Hi {guest_name},\n\nJust a reminder that check-out at {property_name} is tomorrow at {check_out_time}.\n\nThank you so much for staying with us — it's been a pleasure!", 'str-direct-booking' ),
				'sms_body'      => __( "Hi {guest_name}, just a reminder that check-out is tomorrow at {check_out_time}. Thanks for staying!", 'str-direct-booking' ),
			),
			'review_request'        => array(
				'enabled'       => true,
				'days_before'   => 2,
				'email_subject' => __( 'How was your stay at {property_name}?', 'str-direct-booking' ),
				'email_body'    => __( "Hi {guest_name},\n\nThank you for staying at {property_name}! We hope you had a wonderful time.\n\nWe'd love to hear about your experience. Your feedback helps us improve and helps future guests make informed decisions.\n\nThank you again!", 'str-direct-booking' ),
				'sms_body'      => '',
			),
		);

		return $defaults[ $type ] ?? array(
			'enabled'       => true,
			'days_before'   => 0,
			'email_subject' => '',
			'email_body'    => '',
			'sms_body'      => '',
		);
	}

	/**
	 * Get a saved template, falling back to defaults.
	 *
	 * @param string $type Notification type slug.
	 * @return array
	 */
	public static function get_template( string $type ): array {
		$saved    = get_option( 'str_notif_' . $type, array() );
		$defaults = static::get_defaults( $type );

		if ( empty( $saved ) || ! is_array( $saved ) ) {
			return $defaults;
		}

		return wp_parse_args( $saved, $defaults );
	}

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_submenu' ), 20 );
		add_action( 'admin_post_str_save_notifications', array( $this, 'save_templates' ) );
	}

	/**
	 * Register the Notifications submenu under STR Booking.
	 */
	public function register_submenu(): void {
		add_submenu_page(
			'str-booking',
			__( 'Notifications', 'str-direct-booking' ),
			__( 'Notifications', 'str-direct-booking' ),
			'manage_options',
			'str-booking-notifications',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Save notification templates posted from the admin form.
	 */
	public function save_templates(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'str-direct-booking' ) );
		}

		check_admin_referer( 'str_save_notifications' );

		$types = static::get_notification_types();

		foreach ( array_keys( $types ) as $type ) {
			$raw = $_POST[ 'str_notif_' . $type ] ?? array();

			$template = array(
				'enabled'       => ! empty( $raw['enabled'] ),
				'days_before'   => isset( $raw['days_before'] ) ? absint( $raw['days_before'] ) : 0,
				'email_subject' => isset( $raw['email_subject'] ) ? sanitize_text_field( wp_unslash( $raw['email_subject'] ) ) : '',
				'email_body'    => isset( $raw['email_body'] ) ? wp_kses_post( wp_unslash( $raw['email_body'] ) ) : '',
				'sms_body'      => isset( $raw['sms_body'] ) ? sanitize_textarea_field( wp_unslash( $raw['sms_body'] ) ) : '',
			);

			update_option( 'str_notif_' . $type, $template );
		}

		wp_redirect(
			add_query_arg(
				array( 'page' => 'str-booking-notifications', 'saved' => '1' ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render the Notifications admin page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'str-direct-booking' ) );
		}

		$types = static::get_notification_types();
		$saved = isset( $_GET['saved'] ) && '1' === $_GET['saved'];

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Notification Templates', 'str-direct-booking' ); ?></h1>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Templates saved.', 'str-direct-booking' ); ?></p></div>
			<?php endif; ?>

			<div class="str-notif-vars" style="background:#fff;padding:16px 20px;margin:16px 0;border-left:4px solid #2271b1;max-width:900px">
				<strong><?php esc_html_e( 'Available template variables:', 'str-direct-booking' ); ?></strong>
				<p style="margin:8px 0 0;font-family:monospace;font-size:13px">
					{guest_name} &nbsp; {guest_email} &nbsp; {property_name} &nbsp; {check_in_date} &nbsp; {check_out_date}
					&nbsp; {check_in_time} &nbsp; {check_out_time} &nbsp; {total} &nbsp; {nights}
					&nbsp; {address} &nbsp; {door_code} &nbsp; {wifi_password} &nbsp; {host_phone}
					&nbsp; {booking_id} &nbsp; {installment_amount} &nbsp; {installment_due_date}
					&nbsp; {installment_number} &nbsp; {payment_plan_type}
				</p>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="str_save_notifications" />
				<?php wp_nonce_field( 'str_save_notifications' ); ?>

				<?php foreach ( $types as $type => $info ) : ?>
					<?php $tmpl = static::get_template( $type ); ?>
					<div class="str-notif-section postbox" style="max-width:900px;margin-bottom:20px">
						<div class="postbox-header" style="padding:0 16px">
							<h2 class="hndle" style="font-size:14px;padding:12px 0">
								<?php echo esc_html( $info['label'] ); ?>
								<span style="font-weight:400;color:#666;font-size:12px;margin-left:8px"><?php echo esc_html( $info['description'] ); ?></span>
							</h2>
						</div>
						<div class="inside" style="padding:16px">
							<table class="form-table" style="margin:0">
								<tbody>
									<tr>
										<th style="width:180px"><label><?php esc_html_e( 'Enabled', 'str-direct-booking' ); ?></label></th>
										<td>
											<input type="checkbox"
												name="str_notif_<?php echo esc_attr( $type ); ?>[enabled]"
												value="1"
												<?php checked( ! empty( $tmpl['enabled'] ) ); ?>
											/>
										</td>
									</tr>

									<?php if ( $info['has_timing'] ) : ?>
									<tr>
										<th><label for="str_notif_<?php echo esc_attr( $type ); ?>_days">
											<?php esc_html_e( 'Days Before', 'str-direct-booking' ); ?>
										</label></th>
										<td>
											<input type="number" min="0" max="365"
												id="str_notif_<?php echo esc_attr( $type ); ?>_days"
												name="str_notif_<?php echo esc_attr( $type ); ?>[days_before]"
												value="<?php echo absint( $tmpl['days_before'] ); ?>"
												style="width:80px"
											/>
											<span class="description">
												<?php
												if ( 'review_request' === $type ) {
													esc_html_e( 'days after check-out', 'str-direct-booking' );
												} else {
													esc_html_e( 'days before event', 'str-direct-booking' );
												}
												?>
											</span>
										</td>
									</tr>
									<?php endif; ?>

									<?php if ( in_array( 'email', $info['channels'], true ) ) : ?>
									<tr>
										<th><label for="str_notif_<?php echo esc_attr( $type ); ?>_subject">
											<?php esc_html_e( 'Email Subject', 'str-direct-booking' ); ?>
										</label></th>
										<td>
											<input type="text" class="large-text"
												id="str_notif_<?php echo esc_attr( $type ); ?>_subject"
												name="str_notif_<?php echo esc_attr( $type ); ?>[email_subject]"
												value="<?php echo esc_attr( $tmpl['email_subject'] ); ?>"
											/>
										</td>
									</tr>
									<tr>
										<th><label><?php esc_html_e( 'Email Body', 'str-direct-booking' ); ?></label></th>
										<td>
											<?php
											wp_editor(
												$tmpl['email_body'],
												'str_notif_' . $type . '_email_body',
												array(
													'textarea_name' => 'str_notif_' . $type . '[email_body]',
													'textarea_rows' => 8,
													'media_buttons' => false,
													'teeny'         => true,
													'quicktags'     => true,
												)
											);
											?>
										</td>
									</tr>
									<?php endif; ?>

									<?php if ( in_array( 'sms', $info['channels'], true ) ) : ?>
									<tr>
										<th><label for="str_notif_<?php echo esc_attr( $type ); ?>_sms">
											<?php esc_html_e( 'SMS Body', 'str-direct-booking' ); ?>
										</label></th>
										<td>
											<textarea
												id="str_notif_<?php echo esc_attr( $type ); ?>_sms"
												name="str_notif_<?php echo esc_attr( $type ); ?>[sms_body]"
												rows="3"
												class="large-text"
											><?php echo esc_textarea( $tmpl['sms_body'] ); ?></textarea>
											<p class="description"><?php esc_html_e( 'Keep under 160 characters for a single SMS segment.', 'str-direct-booking' ); ?></p>
										</td>
									</tr>
									<?php endif; ?>
								</tbody>
							</table>
						</div>
					</div>
				<?php endforeach; ?>

				<?php submit_button( __( 'Save Notification Templates', 'str-direct-booking' ) ); ?>
			</form>
		</div>
		<?php
	}
}
