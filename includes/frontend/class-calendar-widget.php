<?php
/**
 * Calendar Widget — enqueues React bundle and registers [str_availability_calendar] shortcode.
 *
 * @package STRBooking\Frontend
 */

namespace STRBooking\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles script enqueuing and [str_availability_calendar] shortcode.
 */
class CalendarWidget {

	/**
	 * Set to true by render_shortcode() so the flag is available for late-enqueue hooks.
	 *
	 * @var bool
	 */
	private static bool $enqueue_scripts = false;

	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ), 10 );
		add_action( 'init', array( $this, 'register_shortcode' ), 10 );
	}

	/**
	 * Enqueue calendar widget scripts and styles — only on pages that use the shortcode.
	 */
	public function enqueue_scripts(): void {
		if ( ! self::$enqueue_scripts ) {
			$post = is_singular() ? get_post() : null;
			if ( ! $post || ! has_shortcode( $post->post_content, 'str_availability_calendar' ) ) {
				return;
			}
		}

		$asset_file = STR_BOOKING_PLUGIN_DIR . 'assets/js/calendar-widget.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'str-calendar-widget',
			STR_BOOKING_PLUGIN_URL . 'assets/js/calendar-widget.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_enqueue_style(
			'str-booking-widget',
			STR_BOOKING_PLUGIN_URL . 'assets/css/frontend.css',
			array(),
			STR_BOOKING_VERSION
		);

		wp_localize_script(
			'str-calendar-widget',
			'strCalendarData',
			array(
				'apiUrl' => rest_url( 'str-booking/v1' ),
				'nonce'  => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * Register the [str_availability_calendar] shortcode.
	 */
	public function register_shortcode(): void {
		add_shortcode( 'str_availability_calendar', array( $this, 'render_shortcode' ) );
	}

	/**
	 * Render availability calendar shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML markup.
	 */
	public function render_shortcode( array $atts ): string {
		$atts = shortcode_atts(
			array(
				'property_id' => 0,
			),
			$atts,
			'str_availability_calendar'
		);

		$property_id = absint( $atts['property_id'] );

		if ( ! $property_id ) {
			return '<p class="str-error">' . esc_html__( 'Invalid property ID.', 'str-direct-booking' ) . '</p>';
		}

		$property = get_post( $property_id );

		if ( ! $property || 'str_property' !== $property->post_type ) {
			return '<p class="str-error">' . esc_html__( 'Property not found.', 'str-direct-booking' ) . '</p>';
		}

		// Divi builder preview — return a static placeholder.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['et_fb'] ) || isset( $_GET['et_pb_preview'] ) ) {
			return '<div class="str-availability-cal str-booking-preview-placeholder">'
				. '<p><strong>' . esc_html__( 'STR Availability Calendar', 'str-direct-booking' ) . '</strong>'
				. ' &mdash; ' . esc_html( get_the_title( $property_id ) ) . ' (#' . $property_id . ')</p>'
				. '<p><em>' . esc_html__( 'Live calendar renders on the published page.', 'str-direct-booking' ) . '</em></p>'
				. '</div>';
		}

		self::$enqueue_scripts = true;

		return sprintf(
			'<div class="str-availability-calendar" data-property-id="%1$d"></div>',
			$property_id
		);
	}
}
