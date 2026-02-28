<?php
/**
 * STRBooking — Singleton orchestrator.
 *
 * Instantiates all manager classes and wires up plugin hooks.
 * Contains zero business logic.
 *
 * @package STRBooking
 */

namespace STRBooking;

use STRBooking\Admin\AdminDashboard;
use STRBooking\Admin\CalendarSyncSettings;
use STRBooking\Admin\NotificationSettings;
use STRBooking\Admin\PropertyManager;
use STRBooking\Admin\Settings;
use STRBooking\Frontend\BookingWidget;
use STRBooking\Frontend\CalendarWidget;
use STRBooking\Frontend\PublicAPI;
use STRBooking\PluginUpdater;
use STRBooking\SquareHandler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin singleton orchestrator.
 */
class STRBooking {

	/**
	 * Singleton instance.
	 *
	 * @var static|null
	 */
	private static ?self $instance = null;

	/**
	 * @var BookingManager
	 */
	public BookingManager $booking_manager;

	/**
	 * @var PricingEngine
	 */
	public PricingEngine $pricing_engine;

	/**
	 * @var CohostManager
	 */
	public CohostManager $cohost_manager;

	/**
	 * @var PaymentHandler
	 */
	public PaymentHandler $payment_handler;

	/**
	 * @var CalendarSync
	 */
	public CalendarSync $calendar_sync;

	/**
	 * @var NotificationManager
	 */
	public NotificationManager $notification_manager;

	/**
	 * @var PropertyManager
	 */
	public PropertyManager $property_manager;

	/**
	 * @var PublicAPI
	 */
	public PublicAPI $public_api;

	/**
	 * @var BookingWidget
	 */
	public BookingWidget $booking_widget;

	/**
	 * @var CalendarWidget
	 */
	public CalendarWidget $calendar_widget;

	/**
	 * @var AdminDashboard
	 */
	public AdminDashboard $admin_dashboard;

	/**
	 * @var Settings
	 */
	public Settings $settings;

	/**
	 * @var PaymentPlanManager
	 */
	public PaymentPlanManager $payment_plan_manager;

	/**
	 * @var NotificationSettings
	 */
	public NotificationSettings $notification_settings;

	/**
	 * @var CalendarSyncSettings
	 */
	public CalendarSyncSettings $calendar_sync_settings;

	/**
	 * @var PluginUpdater
	 */
	public PluginUpdater $plugin_updater;

	/**
	 * @var SquareHandler
	 */
	public SquareHandler $square_handler;

	/**
	 * Get or create singleton instance.
	 *
	 * @return static
	 */
	public static function get_instance(): static {
		if ( null === static::$instance ) {
			static::$instance = new static();
		}

		return static::$instance;
	}

	/**
	 * Private constructor — instantiates all managers.
	 */
	private function __construct() {
		$this->booking_manager        = new BookingManager();
		$this->pricing_engine         = new PricingEngine();
		$this->cohost_manager         = new CohostManager();
		$this->payment_handler        = new PaymentHandler( $this->booking_manager, $this->cohost_manager );
		$this->payment_plan_manager   = new PaymentPlanManager();
		$this->calendar_sync          = new CalendarSync( $this->booking_manager );
		$this->notification_manager   = new NotificationManager();
		$this->property_manager       = new PropertyManager();
		$this->public_api             = new PublicAPI( $this->booking_manager, $this->pricing_engine, $this->payment_handler );
		$this->booking_widget         = new BookingWidget();
		$this->calendar_widget        = new CalendarWidget();
		$this->admin_dashboard        = new AdminDashboard( $this->booking_manager );
		$this->settings                = new Settings();
		$this->notification_settings   = new NotificationSettings();
		$this->calendar_sync_settings  = new CalendarSyncSettings();
		$this->plugin_updater          = new PluginUpdater( STR_BOOKING_PLUGIN_DIR . 'str-direct-booking.php' );
		$this->square_handler          = new SquareHandler();
	}

	/**
	 * Plugin activation handler.
	 */
	public static function activate(): void {
		// Schedule cron for calendar sync
		if ( ! wp_next_scheduled( 'str_calendar_sync_cron' ) ) {
			wp_schedule_event( time(), 'hourly', 'str_calendar_sync_cron' );
		}

		// Schedule cron to expire stale pending bookings
		if ( ! wp_next_scheduled( 'str_expire_pending_bookings' ) ) {
			wp_schedule_event( time(), 'hourly', 'str_expire_pending_bookings' );
		}

		// Flush rewrite rules for iCal endpoint and property slugs
		flush_rewrite_rules();
	}

	/**
	 * Plugin deactivation handler.
	 */
	public static function deactivate(): void {
		// Clear scheduled cron jobs
		wp_clear_scheduled_hook( 'str_calendar_sync_cron' );
		wp_clear_scheduled_hook( 'str_expire_pending_bookings' );

		// Clear any pending notification hooks
		wp_clear_scheduled_hook( 'str_send_notification' );
		wp_clear_scheduled_hook( 'str_booking_process_transfers' );
		wp_clear_scheduled_hook( 'str_charge_installment' );

		flush_rewrite_rules();
	}
}
