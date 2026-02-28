<?php
/**
 * Property Manager — CPT registration and meta boxes.
 *
 * @package STRBooking\Admin
 */

namespace STRBooking\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers CPTs, post statuses, and post meta for properties and bookings.
 */
class PropertyManager {

	public function __construct() {
		add_action( 'init', array( $this, 'register_cpts' ), 10 );
		add_action( 'init', array( $this, 'register_post_statuses' ), 10 );
		add_action( 'init', array( $this, 'register_post_meta' ), 11 );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ), 10 );
		add_action( 'save_post_str_property', array( $this, 'save_property_meta' ), 10 );
		add_action( 'save_post_str_booking', array( $this, 'save_booking_meta' ), 10 );
		add_filter( 'manage_str_property_posts_columns', array( $this, 'add_shortcode_column' ) );
		add_action( 'manage_str_property_posts_custom_column', array( $this, 'render_shortcode_column' ), 10, 2 );
		// Booking list admin improvements
		add_action( 'pre_get_posts', array( $this, 'fix_booking_all_query' ) );
		add_filter( 'views_edit-str_booking', array( $this, 'booking_status_views' ) );
		add_action( 'admin_notices', array( $this, 'pending_bookings_notice' ) );
		add_action( 'wp_ajax_str_update_booking_status', array( $this, 'handle_booking_status_ajax' ) );
	}

	/**
	 * Register custom post types.
	 */
	public function register_cpts(): void {
		// STR Property CPT
		register_post_type(
			'str_property',
			array(
				'labels'       => array(
					'name'               => __( 'Properties', 'str-direct-booking' ),
					'singular_name'      => __( 'Property', 'str-direct-booking' ),
					'add_new_item'       => __( 'Add New Property', 'str-direct-booking' ),
					'edit_item'          => __( 'Edit Property', 'str-direct-booking' ),
					'view_item'          => __( 'View Property', 'str-direct-booking' ),
					'all_items'          => __( 'All Properties', 'str-direct-booking' ),
					'search_items'       => __( 'Search Properties', 'str-direct-booking' ),
				),
				'public'             => true,
				'show_ui'            => true,
				'show_in_rest'       => true,
				'show_in_menu'       => 'str-booking',
				'menu_position'      => 25,
				'supports'           => array( 'title', 'editor', 'thumbnail', 'custom-fields' ),
				'has_archive'        => false,
				'rewrite'            => array( 'slug' => 'property' ),
				'capability_type'    => 'post',
			)
		);

		// STR Booking CPT
		register_post_type(
			'str_booking',
			array(
				'labels'       => array(
					'name'               => __( 'Bookings', 'str-direct-booking' ),
					'singular_name'      => __( 'Booking', 'str-direct-booking' ),
					'add_new_item'       => __( 'Add New Booking', 'str-direct-booking' ),
					'edit_item'          => __( 'Edit Booking', 'str-direct-booking' ),
					'view_item'          => __( 'View Booking', 'str-direct-booking' ),
					'all_items'          => __( 'All Bookings', 'str-direct-booking' ),
					'search_items'       => __( 'Search Bookings', 'str-direct-booking' ),
				),
				'public'             => false,
				'show_ui'            => true,
				'show_in_rest'       => true,
				'show_in_menu'       => 'str-booking',
				'supports'           => array( 'title', 'custom-fields' ),
				'has_archive'        => false,
				'capability_type'    => 'post',
			)
		);
	}

	/**
	 * Register custom post statuses for bookings.
	 */
	public function register_post_statuses(): void {
		$statuses = array(
			'pending'    => __( 'Pending', 'str-direct-booking' ),
			'confirmed'  => __( 'Confirmed', 'str-direct-booking' ),
			'checked_in' => __( 'Checked In', 'str-direct-booking' ),
			'checked_out' => __( 'Checked Out', 'str-direct-booking' ),
			'cancelled'  => __( 'Cancelled', 'str-direct-booking' ),
			'refunded'   => __( 'Refunded', 'str-direct-booking' ),
		);

		foreach ( $statuses as $slug => $label ) {
			register_post_status(
				$slug,
				array(
					'label'                     => $label,
					'public'                    => false,
					'exclude_from_search'       => true,
					'show_in_admin_all_list'    => true,
					'show_in_admin_status_list' => true,
					/* translators: %s: count */
					'label_count'               => _n_noop( $label . ' <span class="count">(%s)</span>', $label . ' <span class="count">(%s)</span>', 'str-direct-booking' ),
				)
			);
		}
	}

	/**
	 * Register post meta for both CPTs.
	 */
	public function register_post_meta(): void {
		// Property meta
		$property_meta = array(
			'str_nightly_rate'              => array( 'type' => 'number', 'description' => 'Base nightly rate' ),
			'str_cleaning_fee'              => array( 'type' => 'number', 'description' => 'Cleaning fee' ),
			'str_security_deposit'          => array( 'type' => 'number', 'description' => 'Security deposit amount' ),
			'str_max_guests'                => array( 'type' => 'integer', 'description' => 'Maximum guests' ),
			'str_min_nights'                => array( 'type' => 'integer', 'description' => 'Minimum nights' ),
			'str_max_nights'                => array( 'type' => 'integer', 'description' => 'Maximum nights' ),
			'str_check_in_time'             => array( 'type' => 'string', 'description' => 'Check-in time (H:i)' ),
			'str_check_out_time'            => array( 'type' => 'string', 'description' => 'Check-out time (H:i)' ),
			'str_address'                   => array( 'type' => 'string', 'description' => 'Property address' ),
			'str_door_code'                 => array( 'type' => 'string', 'description' => 'Door access code' ),
			'str_wifi_password'             => array( 'type' => 'string', 'description' => 'WiFi password' ),
			'str_los_discounts'             => array( 'type' => 'string', 'description' => 'Length-of-stay discounts JSON' ),
			'str_tax_rate'                  => array( 'type' => 'number', 'description' => 'Property-specific tax rate override' ),
			'str_host_phone'                => array( 'type' => 'string', 'description' => 'Host phone number' ),
			'str_instant_book'              => array( 'type' => 'boolean', 'description' => 'Allow instant booking' ),
			// Payment plan settings
			'str_plan_full_enabled'         => array( 'type' => 'boolean', 'description' => 'Pay-in-full plan enabled' ),
			'str_plan_two_enabled'          => array( 'type' => 'boolean', 'description' => '2-payment plan enabled' ),
			'str_plan_two_deposit_pct'      => array( 'type' => 'integer', 'description' => '2-payment deposit percentage (1–99)' ),
			'str_plan_two_days_before'      => array( 'type' => 'integer', 'description' => '2-payment: days before check-in 2nd payment is due' ),
			'str_plan_four_enabled'         => array( 'type' => 'boolean', 'description' => '4-payment plan enabled' ),
			'str_plan_four_deposit_min_pct' => array( 'type' => 'integer', 'description' => '4-payment minimum deposit percentage' ),
		);

		foreach ( $property_meta as $key => $args ) {
			register_post_meta(
				'str_property',
				$key,
				array(
					'type'         => $args['type'],
					'description'  => $args['description'],
					'single'       => true,
					'show_in_rest' => true,
				)
			);
		}

		// Booking meta
		$booking_meta = array(
			'str_property_id'              => array( 'type' => 'integer', 'description' => 'Associated property ID' ),
			'str_guest_name'               => array( 'type' => 'string', 'description' => 'Guest full name' ),
			'str_guest_email'              => array( 'type' => 'string', 'description' => 'Guest email address' ),
			'str_guest_phone'              => array( 'type' => 'string', 'description' => 'Guest phone number' ),
			'str_guest_count'              => array( 'type' => 'integer', 'description' => 'Number of guests' ),
			'str_check_in'                 => array( 'type' => 'string', 'description' => 'Check-in date (Y-m-d)' ),
			'str_check_out'                => array( 'type' => 'string', 'description' => 'Check-out date (Y-m-d)' ),
			'str_nights'                   => array( 'type' => 'integer', 'description' => 'Number of nights' ),
			'str_nightly_rate'             => array( 'type' => 'number', 'description' => 'Nightly rate at booking' ),
			'str_subtotal'                 => array( 'type' => 'number', 'description' => 'Nightly subtotal' ),
			'str_cleaning_fee'             => array( 'type' => 'number', 'description' => 'Cleaning fee' ),
			'str_security_deposit'         => array( 'type' => 'number', 'description' => 'Security deposit' ),
			'str_taxes'                    => array( 'type' => 'number', 'description' => 'Tax amount' ),
			'str_total'                    => array( 'type' => 'number', 'description' => 'Total amount charged' ),
			'str_los_discount'             => array( 'type' => 'number', 'description' => 'Length-of-stay discount applied' ),
			'str_stripe_payment_intent'    => array( 'type' => 'string', 'description' => 'Stripe PaymentIntent ID' ),
			'str_stripe_charge_id'         => array( 'type' => 'string', 'description' => 'Stripe Charge ID' ),
			'str_stripe_transfer_group'    => array( 'type' => 'string', 'description' => 'Stripe Transfer Group ID' ),
			'str_transfers_processed'      => array( 'type' => 'boolean', 'description' => 'Co-host transfers completed' ),
			'str_deposit_released'         => array( 'type' => 'boolean', 'description' => 'Security deposit released' ),
			'str_special_requests'         => array( 'type' => 'string', 'description' => 'Guest special requests' ),
			'str_daily_breakdown'          => array( 'type' => 'string', 'description' => 'Per-day pricing JSON' ),
			// Payment plan meta
			'str_payment_plan'             => array( 'type' => 'string', 'description' => 'Payment plan type (pay_in_full|two_payment|four_payment)' ),
			'str_stripe_customer_id'       => array( 'type' => 'string', 'description' => 'Stripe Customer ID for off-session charges' ),
			'str_stripe_payment_method_id' => array( 'type' => 'string', 'description' => 'Stripe PaymentMethod ID for off-session charges' ),
			'str_payment_installments'     => array( 'type' => 'string', 'description' => 'JSON array of installment records' ),
		);

		foreach ( $booking_meta as $key => $args ) {
			register_post_meta(
				'str_booking',
				$key,
				array(
					'type'         => $args['type'],
					'description'  => $args['description'],
					'single'       => true,
					'show_in_rest' => true,
				)
			);
		}
	}

	/**
	 * Add meta boxes for property and booking edit screens.
	 */
	public function add_meta_boxes(): void {
		add_meta_box(
			'str_property_details',
			__( 'Property Details', 'str-direct-booking' ),
			array( $this, 'render_property_meta_box' ),
			'str_property',
			'normal',
			'high'
		);

		add_meta_box(
			'str_property_shortcode',
			__( 'Booking Form Shortcode', 'str-direct-booking' ),
			array( $this, 'render_shortcode_meta_box' ),
			'str_property',
			'side',
			'default'
		);

		add_meta_box(
			'str_booking_details',
			__( 'Booking Details', 'str-direct-booking' ),
			array( $this, 'render_booking_meta_box' ),
			'str_booking',
			'normal',
			'high'
		);

		add_meta_box(
			'str_booking_actions',
			__( 'Booking Actions', 'str-direct-booking' ),
			array( $this, 'render_booking_actions_meta_box' ),
			'str_booking',
			'side',
			'high'
		);
	}

	/**
	 * Render property meta box.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_property_meta_box( \WP_Post $post ): void {
		wp_nonce_field( 'str_property_meta', 'str_property_meta_nonce' );

		$fields = array(
			'str_nightly_rate'     => array( 'label' => 'Nightly Rate ($)', 'type' => 'number', 'step' => '0.01' ),
			'str_cleaning_fee'     => array( 'label' => 'Cleaning Fee ($)', 'type' => 'number', 'step' => '0.01' ),
			'str_security_deposit' => array( 'label' => 'Security Deposit ($)', 'type' => 'number', 'step' => '0.01' ),
			'str_max_guests'       => array( 'label' => 'Max Guests', 'type' => 'number', 'step' => '1' ),
			'str_min_nights'       => array( 'label' => 'Min Nights', 'type' => 'number', 'step' => '1' ),
			'str_max_nights'       => array( 'label' => 'Max Nights', 'type' => 'number', 'step' => '1' ),
			'str_check_in_time'    => array( 'label' => 'Check-in Time', 'type' => 'time' ),
			'str_check_out_time'   => array( 'label' => 'Check-out Time', 'type' => 'time' ),
			'str_address'          => array( 'label' => 'Address', 'type' => 'text' ),
			'str_door_code'        => array( 'label' => 'Door Code', 'type' => 'text' ),
			'str_wifi_password'    => array( 'label' => 'WiFi Password', 'type' => 'text' ),
			'str_host_phone'       => array( 'label' => 'Host Phone', 'type' => 'text' ),
			'str_tax_rate'         => array( 'label' => 'Tax Rate (0.00–1.00)', 'type' => 'number', 'step' => '0.001' ),
		);

		echo '<table class="form-table"><tbody>';
		foreach ( $fields as $key => $field ) {
			$value = get_post_meta( $post->ID, $key, true );
			printf(
				'<tr><th><label for="%1$s">%2$s</label></th><td><input type="%3$s" id="%1$s" name="%1$s" value="%4$s" step="%5$s" class="regular-text" /></td></tr>',
				esc_attr( $key ),
				esc_html( $field['label'] ),
				esc_attr( $field['type'] ),
				esc_attr( $value ),
				esc_attr( $field['step'] ?? '' )
			);
		}

		// LOS Discounts textarea
		$los = get_post_meta( $post->ID, 'str_los_discounts', true );
		echo '<tr><th><label for="str_los_discounts">LOS Discounts (JSON)</label></th>';
		echo '<td><textarea id="str_los_discounts" name="str_los_discounts" rows="4" class="large-text code">' . esc_textarea( $los ) . '</textarea>';
		echo '<p class="description">Example: [{"min_nights":7,"discount":0.10},{"min_nights":28,"discount":0.20}]</p></td></tr>';

		echo '</tbody></table>';

		// Payment Plans section
		echo '<h3 style="margin-top:24px;padding-bottom:8px;border-bottom:1px solid #ddd">' . esc_html__( 'Payment Plans', 'str-direct-booking' ) . '</h3>';
		echo '<table class="form-table"><tbody>';

		$plan_full_enabled  = get_post_meta( $post->ID, 'str_plan_full_enabled', true );
		$plan_two_enabled   = (bool) get_post_meta( $post->ID, 'str_plan_two_enabled', true );
		$plan_four_enabled  = (bool) get_post_meta( $post->ID, 'str_plan_four_enabled', true );
		$two_deposit_pct    = (int) ( get_post_meta( $post->ID, 'str_plan_two_deposit_pct', true ) ?: 50 );
		$two_days_before    = (int) ( get_post_meta( $post->ID, 'str_plan_two_days_before', true ) ?: 42 );
		$four_deposit_min   = (int) ( get_post_meta( $post->ID, 'str_plan_four_deposit_min_pct', true ) ?: 25 );
		// Default pay-in-full to enabled (empty string = true)
		$full_checked       = ( '' === $plan_full_enabled || (bool) $plan_full_enabled );

		printf(
			'<tr><th><label for="str_plan_full_enabled">%s</label></th><td><input type="checkbox" id="str_plan_full_enabled" name="str_plan_full_enabled" value="1" %s /></td></tr>',
			esc_html__( 'Pay-in-Full', 'str-direct-booking' ),
			checked( $full_checked, true, false )
		);

		printf(
			'<tr><th><label for="str_plan_two_enabled">%s</label></th><td><input type="checkbox" id="str_plan_two_enabled" name="str_plan_two_enabled" value="1" %s /></td></tr>',
			esc_html__( '2-Payment Plan', 'str-direct-booking' ),
			checked( $plan_two_enabled, true, false )
		);

		printf(
			'<tr id="str-two-payment-fields" style="%s"><th><label for="str_plan_two_deposit_pct">%s</label></th><td><input type="number" id="str_plan_two_deposit_pct" name="str_plan_two_deposit_pct" value="%d" min="1" max="99" style="width:80px" /> <span class="description">%s</span></td></tr>',
			$plan_two_enabled ? '' : 'display:none',
			esc_html__( '2-Pay: Deposit %', 'str-direct-booking' ),
			$two_deposit_pct,
			esc_html__( '% of total due at booking (default 50)', 'str-direct-booking' )
		);

		printf(
			'<tr id="str-two-payment-days" style="%s"><th><label for="str_plan_two_days_before">%s</label></th><td><input type="number" id="str_plan_two_days_before" name="str_plan_two_days_before" value="%d" min="1" max="365" style="width:80px" /> <span class="description">%s</span></td></tr>',
			$plan_two_enabled ? '' : 'display:none',
			esc_html__( '2-Pay: Days Before Check-in', 'str-direct-booking' ),
			$two_days_before,
			esc_html__( 'days before check-in that 2nd payment is due (default 42)', 'str-direct-booking' )
		);

		printf(
			'<tr><th><label for="str_plan_four_enabled">%s</label></th><td><input type="checkbox" id="str_plan_four_enabled" name="str_plan_four_enabled" value="1" %s /></td></tr>',
			esc_html__( '4-Payment Plan', 'str-direct-booking' ),
			checked( $plan_four_enabled, true, false )
		);

		printf(
			'<tr id="str-four-payment-fields" style="%s"><th><label for="str_plan_four_deposit_min_pct">%s</label></th><td><input type="number" id="str_plan_four_deposit_min_pct" name="str_plan_four_deposit_min_pct" value="%d" min="1" max="99" style="width:80px" /> <span class="description">%s</span></td></tr>',
			$plan_four_enabled ? '' : 'display:none',
			esc_html__( '4-Pay: Min Deposit %', 'str-direct-booking' ),
			$four_deposit_min,
			esc_html__( 'minimum % guest must pay upfront (default 25)', 'str-direct-booking' )
		);

		echo '</tbody></table>';

		// Inline JS to show/hide conditional plan fields
		?>
		<script>
		(function(){
			function toggleFields(checkboxId, rowIds) {
				var cb = document.getElementById(checkboxId);
				if (!cb) return;
				function update() {
					rowIds.forEach(function(id){
						var row = document.getElementById(id);
						if (row) row.style.display = cb.checked ? '' : 'none';
					});
				}
				cb.addEventListener('change', update);
				update();
			}
			toggleFields('str_plan_two_enabled', ['str-two-payment-fields','str-two-payment-days']);
			toggleFields('str_plan_four_enabled', ['str-four-payment-fields']);
		})();
		</script>
		<?php
	}

	/**
	 * Render booking meta box.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_booking_meta_box( \WP_Post $post ): void {
		$fields = array(
			'str_property_id'   => 'Property ID',
			'str_guest_name'    => 'Guest Name',
			'str_guest_email'   => 'Guest Email',
			'str_guest_phone'   => 'Guest Phone',
			'str_guest_count'   => 'Guest Count',
			'str_check_in'      => 'Check-in Date',
			'str_check_out'     => 'Check-out Date',
			'str_nights'        => 'Nights',
			'str_total'         => 'Total ($)',
			'str_stripe_payment_intent' => 'Stripe PaymentIntent',
		);

		echo '<table class="form-table"><tbody>';
		foreach ( $fields as $key => $label ) {
			$value = get_post_meta( $post->ID, $key, true );
			printf(
				'<tr><th>%s</th><td><code>%s</code></td></tr>',
				esc_html( $label ),
				esc_html( $value )
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * Render shortcode meta box in the property sidebar.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_shortcode_meta_box( \WP_Post $post ): void {
		if ( ! $post->ID || 'auto-draft' === $post->post_status ) {
			echo '<p>' . esc_html__( 'Save the property first to generate its shortcodes.', 'str-direct-booking' ) . '</p>';
			return;
		}

		$shortcodes = array(
			array(
				'id'    => 'str-shortcode-booking',
				'label' => __( 'Booking Form', 'str-direct-booking' ),
				'value' => '[str_booking_form property_id="' . $post->ID . '"]',
			),
			array(
				'id'    => 'str-shortcode-calendar',
				'label' => __( 'Availability Calendar', 'str-direct-booking' ),
				'value' => '[str_availability_calendar property_id="' . $post->ID . '"]',
			),
		);

		foreach ( $shortcodes as $sc ) :
			?>
			<p style="margin:12px 0 4px;font-weight:600;font-size:12px"><?php echo esc_html( $sc['label'] ); ?></p>
			<input
				type="text"
				id="<?php echo esc_attr( $sc['id'] ); ?>"
				value="<?php echo esc_attr( $sc['value'] ); ?>"
				readonly
				style="width:100%;font-family:monospace;font-size:11px"
			/>
			<button
				type="button"
				class="button button-secondary"
				style="margin-top:4px;width:100%"
				onclick="(function(){var el=document.getElementById('<?php echo esc_js( $sc['id'] ); ?>');el.select();document.execCommand('copy');el.blur();this.textContent='<?php echo esc_js( __( 'Copied!', 'str-direct-booking' ) ); ?>';setTimeout(function(){this.textContent='<?php echo esc_js( __( 'Copy', 'str-direct-booking' ) ); ?>';}.bind(this),2000);}).call(this)"
			><?php esc_html_e( 'Copy', 'str-direct-booking' ); ?></button>
			<?php
		endforeach;
	}

	/**
	 * Add a Shortcode column to the Properties list table.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public function add_shortcode_column( array $columns ): array {
		$columns['str_shortcode'] = __( 'Shortcode', 'str-direct-booking' );
		return $columns;
	}

	/**
	 * Render the Shortcode column value for each property row.
	 *
	 * @param string $column  Column slug.
	 * @param int    $post_id Post ID.
	 */
	public function render_shortcode_column( string $column, int $post_id ): void {
		if ( 'str_shortcode' !== $column ) {
			return;
		}
		printf(
			'<code>[str_booking_form property_id=&quot;%d&quot;]</code>',
			$post_id
		);
	}

	/**
	 * Save property meta box data.
	 *
	 * @param int $post_id Post ID.
	 */
	public function save_property_meta( int $post_id ): void {
		if ( ! isset( $_POST['str_property_meta_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['str_property_meta_nonce'] ) ), 'str_property_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$number_fields  = array( 'str_nightly_rate', 'str_cleaning_fee', 'str_security_deposit', 'str_max_guests', 'str_min_nights', 'str_max_nights', 'str_tax_rate' );
		$text_fields    = array( 'str_check_in_time', 'str_check_out_time', 'str_address', 'str_door_code', 'str_wifi_password', 'str_host_phone', 'str_los_discounts' );
		$integer_fields = array( 'str_plan_two_deposit_pct', 'str_plan_two_days_before', 'str_plan_four_deposit_min_pct' );
		$boolean_fields = array( 'str_plan_full_enabled', 'str_plan_two_enabled', 'str_plan_four_enabled' );

		foreach ( $number_fields as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				update_post_meta( $post_id, $key, floatval( $_POST[ $key ] ) );
			}
		}

		foreach ( $text_fields as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				update_post_meta( $post_id, $key, sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) );
			}
		}

		foreach ( $integer_fields as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				update_post_meta( $post_id, $key, absint( $_POST[ $key ] ) );
			}
		}

		foreach ( $boolean_fields as $key ) {
			update_post_meta( $post_id, $key, isset( $_POST[ $key ] ) ? true : false );
		}
	}

	/**
	 * Save booking meta (minimal — bookings are created via API).
	 *
	 * @param int $post_id Post ID.
	 */
	public function save_booking_meta( int $post_id ): void {
		// Booking meta is managed programmatically via BookingManager.
		// This hook is intentionally minimal to prevent admin overwrites.
	}

	// ── Booking admin improvements ────────────────────────────────────────────

	/**
	 * Ensure all custom booking statuses are included when viewing "All" bookings.
	 *
	 * @param \WP_Query $query Current query.
	 */
	public function fix_booking_all_query( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'edit-str_booking' !== $screen->id ) {
			return;
		}

		// Only override when no specific status filter is in the URL
		if ( empty( $_GET['post_status'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$query->set(
				'post_status',
				array( 'pending', 'confirmed', 'checked_in', 'checked_out', 'cancelled', 'refunded' )
			);
		}
	}

	/**
	 * Add correctly-counted status filter links to the bookings list table.
	 *
	 * @param array $views Existing view links.
	 * @return array Modified view links.
	 */
	public function booking_status_views( array $views ): array {
		$statuses = array(
			'pending'     => __( 'Pending', 'str-direct-booking' ),
			'confirmed'   => __( 'Confirmed', 'str-direct-booking' ),
			'checked_in'  => __( 'Checked In', 'str-direct-booking' ),
			'checked_out' => __( 'Checked Out', 'str-direct-booking' ),
			'cancelled'   => __( 'Cancelled', 'str-direct-booking' ),
			'refunded'    => __( 'Refunded', 'str-direct-booking' ),
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_status = isset( $_GET['post_status'] ) ? sanitize_key( $_GET['post_status'] ) : '';

		foreach ( $statuses as $status => $label ) {
			$count = (int) ( new \WP_Query( array(
				'post_type'      => 'str_booking',
				'post_status'    => $status,
				'posts_per_page' => -1,
				'fields'         => 'ids',
			) ) )->found_posts;

			$url            = admin_url( 'edit.php?post_type=str_booking&post_status=' . $status );
			$is_current     = ( $status === $current_status );
			$views[ $status ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( $url ),
				$is_current ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				$count
			);
		}

		return $views;
	}

	/**
	 * Show an admin notice when there are pending bookings awaiting confirmation.
	 */
	public function pending_bookings_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		// Show on the STR Booking dashboard and the bookings list
		if ( ! in_array( $screen->id, array( 'toplevel_page_str-booking', 'edit-str_booking' ), true ) ) {
			return;
		}

		$pending_count = (int) ( new \WP_Query( array(
			'post_type'      => 'str_booking',
			'post_status'    => 'pending',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) ) )->found_posts;

		if ( 0 === $pending_count ) {
			return;
		}

		$url = admin_url( 'edit.php?post_type=str_booking&post_status=pending' );

		printf(
			'<div class="notice notice-warning is-dismissible"><p>' .
			/* translators: %1$s: URL, %2$d: count */
			esc_html( _n(
				'STR Booking: %2$d booking is pending payment confirmation.',
				'STR Booking: %2$d bookings are pending payment confirmation.',
				$pending_count,
				'str-direct-booking'
			) ) .
			' <a href="%1$s">' . esc_html__( 'View pending bookings', 'str-direct-booking' ) . '</a></p></div>',
			esc_url( $url ),
			$pending_count
		);
	}

	/**
	 * Render the Booking Actions meta box (status change via AJAX).
	 *
	 * @param \WP_Post $post Current booking post.
	 */
	public function render_booking_actions_meta_box( \WP_Post $post ): void {
		$current = $post->post_status;

		$statuses = array(
			'pending'     => __( 'Pending', 'str-direct-booking' ),
			'confirmed'   => __( 'Confirmed', 'str-direct-booking' ),
			'checked_in'  => __( 'Checked In', 'str-direct-booking' ),
			'checked_out' => __( 'Checked Out', 'str-direct-booking' ),
			'cancelled'   => __( 'Cancelled', 'str-direct-booking' ),
			'refunded'    => __( 'Refunded', 'str-direct-booking' ),
		);

		$current_label = $statuses[ $current ] ?? $current;
		$nonce         = wp_create_nonce( 'str_booking_status_' . $post->ID );
		?>
		<p style="margin:0 0 8px">
			<strong><?php esc_html_e( 'Current Status', 'str-direct-booking' ); ?>:</strong>
			<span id="str-current-status-label" style="margin-left:6px;font-weight:600">
				<?php echo esc_html( $current_label ); ?>
			</span>
		</p>

		<select id="str-booking-status-select" style="width:100%;margin-bottom:8px">
			<?php foreach ( $statuses as $slug => $label ) : ?>
				<option value="<?php echo esc_attr( $slug ); ?>"<?php selected( $current, $slug ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<button
			type="button"
			id="str-update-status-btn"
			class="button button-primary"
			style="width:100%"
		>
			<?php esc_html_e( 'Update Status', 'str-direct-booking' ); ?>
		</button>

		<p id="str-status-msg" style="margin:8px 0 0;display:none;font-size:13px"></p>

		<script>
		(function() {
			var btn    = document.getElementById('str-update-status-btn');
			var select = document.getElementById('str-booking-status-select');
			var msg    = document.getElementById('str-status-msg');
			var label  = document.getElementById('str-current-status-label');

			if ( ! btn ) return;

			var labels = <?php echo wp_json_encode( $statuses ); ?>;

			btn.addEventListener('click', function() {
				btn.disabled    = true;
				btn.textContent = '<?php echo esc_js( __( 'Updating…', 'str-direct-booking' ) ); ?>';
				msg.style.display = 'none';

				var fd = new FormData();
				fd.append('action',     'str_update_booking_status');
				fd.append('booking_id', '<?php echo esc_js( (string) $post->ID ); ?>');
				fd.append('status',     select.value);
				fd.append('_wpnonce',   '<?php echo esc_js( $nonce ); ?>');

				fetch(ajaxurl, { method: 'POST', body: fd })
					.then(function(r) { return r.json(); })
					.then(function(res) {
						msg.style.display = '';
						if ( res.success ) {
							label.textContent    = labels[select.value] || select.value;
							msg.style.color      = '#00a32a';
							msg.textContent      = '<?php echo esc_js( __( 'Status updated successfully.', 'str-direct-booking' ) ); ?>';
						} else {
							msg.style.color = '#d63638';
							msg.textContent = res.data || '<?php echo esc_js( __( 'Update failed.', 'str-direct-booking' ) ); ?>';
						}
					})
					.catch(function() {
						msg.style.display = '';
						msg.style.color   = '#d63638';
						msg.textContent   = '<?php echo esc_js( __( 'Request failed. Please try again.', 'str-direct-booking' ) ); ?>';
					})
					.finally(function() {
						btn.disabled    = false;
						btn.textContent = '<?php echo esc_js( __( 'Update Status', 'str-direct-booking' ) ); ?>';
					});
			});
		})();
		</script>
		<?php
	}

	/**
	 * Handle AJAX booking status update from the Booking Actions meta box.
	 */
	public function handle_booking_status_ajax(): void {
		$booking_id = isset( $_POST['booking_id'] ) ? absint( $_POST['booking_id'] ) : 0;

		if ( ! $booking_id ) {
			wp_send_json_error( __( 'Invalid booking ID.', 'str-direct-booking' ) );
		}

		if ( ! check_ajax_referer( 'str_booking_status_' . $booking_id, '_wpnonce', false ) ) {
			wp_send_json_error( __( 'Security check failed.', 'str-direct-booking' ) );
		}

		if ( ! current_user_can( 'edit_post', $booking_id ) ) {
			wp_send_json_error( __( 'Permission denied.', 'str-direct-booking' ) );
		}

		$valid_statuses = array( 'pending', 'confirmed', 'checked_in', 'checked_out', 'cancelled', 'refunded' );
		$new_status     = isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : '';

		if ( ! in_array( $new_status, $valid_statuses, true ) ) {
			wp_send_json_error( __( 'Invalid status.', 'str-direct-booking' ) );
		}

		$booking_manager = new \STRBooking\BookingManager();
		$success         = $booking_manager->update_booking_status( $booking_id, $new_status );

		if ( ! $success ) {
			wp_send_json_error( __( 'Could not update status.', 'str-direct-booking' ) );
		}

		wp_send_json_success( array( 'status' => $new_status ) );
	}
}
