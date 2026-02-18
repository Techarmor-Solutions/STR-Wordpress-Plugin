<?php
/**
 * Admin Dashboard — top-level menu and React-powered dashboard.
 *
 * @package STRBooking\Admin
 */

namespace STRBooking\Admin;

use STRBooking\BookingManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers admin menus and enqueues the admin React bundle.
 */
class AdminDashboard {

	/**
	 * @var BookingManager
	 */
	private BookingManager $booking_manager;

	public function __construct( BookingManager $booking_manager ) {
		$this->booking_manager = $booking_manager;

		add_action( 'admin_menu', array( $this, 'register_admin_menus' ), 10 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ), 10 );
	}

	/**
	 * Register top-level admin menu and submenus.
	 */
	public function register_admin_menus(): void {
		// Top-level menu
		add_menu_page(
			__( 'STR Booking', 'str-direct-booking' ),
			__( 'STR Booking', 'str-direct-booking' ),
			'manage_options',
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
			'manage_options',
			'str-booking',
			array( $this, 'render_dashboard_page' )
		);

		// Properties submenu
		add_submenu_page(
			'str-booking',
			__( 'Properties', 'str-direct-booking' ),
			__( 'Properties', 'str-direct-booking' ),
			'manage_options',
			'edit.php?post_type=str_property'
		);

		// Bookings submenu
		add_submenu_page(
			'str-booking',
			__( 'Bookings', 'str-direct-booking' ),
			__( 'Bookings', 'str-direct-booking' ),
			'manage_options',
			'edit.php?post_type=str_booking'
		);

		// Settings submenu
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
	 * Render the main dashboard page (React mounts here).
	 */
	public function render_dashboard_page(): void {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'STR Booking Dashboard', 'str-direct-booking' ) . '</h1>';
		echo '<div id="str-admin-dashboard"></div>';
		echo '</div>';
	}

	/**
	 * Render settings page.
	 */
	public function render_settings_page(): void {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'STR Booking Settings', 'str-direct-booking' ) . '</h1>';
		echo '<form method="post" action="options.php">';
		settings_fields( 'str_booking_settings' );
		do_settings_sections( 'str-booking-settings' );
		submit_button();
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Enqueue admin scripts on STR Booking pages.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_scripts( string $hook ): void {
		// Only load on STR Booking dashboard
		if ( 'toplevel_page_str-booking' !== $hook ) {
			return;
		}

		$asset_file = STR_BOOKING_PLUGIN_DIR . 'assets/js/admin-dashboard.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'str-admin-dashboard',
			STR_BOOKING_PLUGIN_URL . 'assets/js/admin-dashboard.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_enqueue_style(
			'str-admin-dashboard',
			STR_BOOKING_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			STR_BOOKING_VERSION
		);

		wp_localize_script(
			'str-admin-dashboard',
			'strAdminData',
			array(
				'apiUrl'   => rest_url( 'str-booking/v1' ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'adminUrl' => admin_url(),
				'currency' => get_option( 'str_booking_currency', 'usd' ),
			)
		);
	}
}
