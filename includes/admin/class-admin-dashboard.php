<?php
/**
 * Admin Dashboard — top-level menu and submenus.
 *
 * @package STRBooking\Admin
 */

namespace STRBooking\Admin;

use STRBooking\BookingManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers admin menus. The dashboard itself renders the Pricing Calendar.
 */
class AdminDashboard {

	/**
	 * @var BookingManager
	 */
	private BookingManager $booking_manager;

	public function __construct( BookingManager $booking_manager ) {
		$this->booking_manager = $booking_manager;

		add_action( 'admin_menu', array( $this, 'register_admin_menus' ), 10 );
	}

	/**
	 * Register top-level admin menu and submenus.
	 */
	public function register_admin_menus(): void {
		$cap = current_user_can( 'manage_options' ) ? 'manage_options' : 'str_booking_access';

		// Top-level menu
		add_menu_page(
			__( 'STR Booking', 'str-direct-booking' ),
			__( 'STR Booking', 'str-direct-booking' ),
			$cap,
			'str-booking',
			array( $this, 'render_dashboard_page' ),
			'dashicons-calendar-alt',
			25
		);

		// Dashboard submenu (replaces duplicate top-level entry)
		add_submenu_page(
			'str-booking',
			__( 'Dashboard', 'str-direct-booking' ),
			__( 'Dashboard', 'str-direct-booking' ),
			$cap,
			'str-booking',
			array( $this, 'render_dashboard_page' )
		);

		// Properties submenu
		add_submenu_page(
			'str-booking',
			__( 'Properties', 'str-direct-booking' ),
			__( 'Properties', 'str-direct-booking' ),
			$cap,
			'edit.php?post_type=str_property'
		);

		// Bookings submenu
		add_submenu_page(
			'str-booking',
			__( 'Bookings', 'str-direct-booking' ),
			__( 'Bookings', 'str-direct-booking' ),
			$cap,
			'edit.php?post_type=str_booking'
		);

		// Settings submenu — always requires manage_options
		add_submenu_page(
			'str-booking',
			__( 'Settings', 'str-direct-booking' ),
			__( 'Settings', 'str-direct-booking' ),
			'manage_options',
			'str-booking-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Render the main dashboard page — delegates to PricingCalendar.
	 */
	public function render_dashboard_page(): void {
		( new \STRBooking\Admin\PricingCalendar() )->render();
	}

	/**
	 * Render settings page.
	 */
	public function render_settings_page(): void {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'STR Booking Settings', 'str-direct-booking' ) . '</h1>';
		// License section has its own form posting to admin-post.php,
		// so it must be rendered outside the options.php form below.
		do_action( 'str_render_license_section' );
		echo '<form method="post" action="options.php">';
		settings_fields( 'str_booking_settings' );
		do_settings_sections( 'str-booking-settings' );
		submit_button();
		echo '</form>';
		echo '</div>';
	}
}
