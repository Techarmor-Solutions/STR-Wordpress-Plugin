<?php
/**
 * Booking Widget — enqueues React bundle and registers shortcode.
 *
 * @package STRBooking\Frontend
 */

namespace STRBooking\Frontend;

use STRBooking\PaymentPlanManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles script enqueuing and [str_booking_form] shortcode.
 */
class BookingWidget {

	/**
	 * Set to true by render_shortcode() so the flag is available for late-enqueue hooks.
	 * The primary enqueue mechanism is has_shortcode() inside wp_enqueue_scripts.
	 *
	 * @var bool
	 */
	private static bool $enqueue_scripts = false;

	/**
	 * Whether the plugin has a valid license.
	 *
	 * @var bool
	 */
	private bool $licensed;

	public function __construct( bool $licensed = true ) {
		$this->licensed = $licensed;
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ), 10 );
		add_action( 'init', array( $this, 'register_shortcode' ), 10 );
	}

	/**
	 * Enqueue booking widget scripts and styles — only on pages that use the shortcode.
	 */
	public function enqueue_scripts(): void {
		// Only enqueue when the current page actually contains the shortcode.
		if ( ! self::$enqueue_scripts ) {
			$post = is_singular() ? get_post() : null;
			if ( ! $post || ! has_shortcode( $post->post_content, 'str_booking_form' ) ) {
				return;
			}
		}

		$asset_file = STR_BOOKING_PLUGIN_DIR . 'assets/js/booking-widget.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'str-booking-widget',
			STR_BOOKING_PLUGIN_URL . 'assets/js/booking-widget.js',
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

		$active_gateway = get_option( 'str_booking_payment_gateway', 'stripe' );

		if ( 'square' === $active_gateway ) {
			// Square Web Payments SDK — loaded from CDN
			wp_enqueue_script(
				'square-js',
				'https://web.squarecdn.com/v1/square.js',
				array(),
				null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
				false // Load in <head>
			);
		} else {
			// Stripe.js from CDN — never bundled (PCI requirement)
			// No version string — Stripe manages versioning
			wp_enqueue_script(
				'stripe-js',
				'https://js.stripe.com/v3/',
				array(),
				null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
				false // Load in <head> as required by Stripe
			);
		}

		// Pass configuration to React
		wp_localize_script(
			'str-booking-widget',
			'strBookingData',
			array(
				'apiUrl'              => rest_url( 'str-booking/v1' ),
				'nonce'               => wp_create_nonce( 'wp_rest' ),
				'stripePublishableKey' => get_option( 'str_booking_stripe_publishable_key', '' ),
				'currency'            => get_option( 'str_booking_currency', 'usd' ),
				'dateFormat'          => get_option( 'date_format', 'F j, Y' ),
				'siteUrl'             => get_bloginfo( 'url' ),
				'activeGateway'       => $active_gateway,
				'squareAppId'         => get_option( 'str_booking_square_application_id', '' ),
				'squareLocationId'    => get_option( 'str_booking_square_location_id', '' ),
				'squareEnvironment'   => get_option( 'str_booking_square_environment', 'sandbox' ),
			)
		);

		wp_add_inline_style(
			'str-booking-widget',
			'.str-bk-cal-day { position: relative; display: flex; flex-direction: column; align-items: center; justify-content: center; }
.str-bk-price { display: block; font-size: 10px; line-height: 1; margin-top: 2px; opacity: 0.75; pointer-events: none; white-space: nowrap; }'
		);

		wp_add_inline_script(
			'str-booking-widget',
			$this->get_price_injection_script(),
			'after'
		);
	}

	/**
	 * Returns the vanilla-JS snippet that injects nightly prices into calendar day cells.
	 */
	private function get_price_injection_script(): string {
		return <<<'JS'
(function () {
	var priceCache = {};
	var data = window.strBookingData || {};
	var apiUrl = data.apiUrl || '';
	var nonce  = data.nonce  || '';
	var currency = (data.currency || 'usd').toLowerCase();

	var symbols = { usd: '$', eur: '€', gbp: '£', cad: 'CA$', aud: 'A$', nzd: 'NZ$', chf: 'Fr', jpy: '¥', mxn: 'MX$' };

	function fmt(price) {
		if (!price) return '';
		var sym = symbols[currency] || (currency.toUpperCase() + '\u00a0');
		return sym + Math.round(price);
	}

	function fetchPrices(propertyId, year, month, cb) {
		var cacheKey = propertyId + ':' + year + '-' + String(month).padStart(2, '0');
		if (priceCache[cacheKey]) { cb(priceCache[cacheKey]); return; }
		fetch(apiUrl + '/calendar/' + propertyId + '?year=' + year + '&month=' + month, {
			headers: { 'X-WP-Nonce': nonce }
		})
		.then(function (r) { return r.json(); })
		.then(function (data) {
			var map = {};
			if (Array.isArray(data)) {
				data.forEach(function (e) { if (e.date && e.price != null) map[e.date] = e.price; });
			}
			priceCache[cacheKey] = map;
			cb(map);
		})
		.catch(function () {});
	}

	function injectPrices(container, propertyId) {
		var days = container.querySelectorAll('.str-bk-cal-day:not(.str-bk-cal-day--empty)');
		if (!days.length) return;
		var firstDate = null;
		days.forEach(function (d) { if (!firstDate) firstDate = d.getAttribute('aria-label'); });
		if (!firstDate || !/^\d{4}-\d{2}-\d{2}$/.test(firstDate)) return;
		var parts = firstDate.split('-');
		fetchPrices(propertyId, parseInt(parts[0]), parseInt(parts[1]), function (map) {
			days.forEach(function (day) {
				var dateStr = day.getAttribute('aria-label');
				if (!dateStr) return;
				var existing = day.querySelector('.str-bk-price');
				if (existing) existing.remove();
				var price = map[dateStr];
				if (price) {
					var span = document.createElement('span');
					span.className = 'str-bk-price';
					span.textContent = fmt(price);
					day.appendChild(span);
				}
			});
		});
	}

	function watchWidget(widget) {
		var propertyId = widget.getAttribute('data-property-id');
		if (!propertyId) return;
		var lastTitle = '';
		var ob = new MutationObserver(function () {
			var cal = widget.querySelector('.str-bk-calendar');
			if (!cal) return;
			var titleEl = cal.querySelector('.str-bk-cal-title');
			var title = titleEl ? titleEl.textContent : '';
			if (title !== lastTitle) { lastTitle = title; injectPrices(cal, propertyId); }
			else { injectPrices(cal, propertyId); }
		});
		ob.observe(widget, { childList: true, subtree: true });
		var cal = widget.querySelector('.str-bk-calendar');
		if (cal) injectPrices(cal, propertyId);
	}

	function init() {
		document.querySelectorAll('.str-booking-widget[data-property-id]').forEach(watchWidget);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
		// Also watch for React to mount the widget after DOMContentLoaded
		var bodyOb = new MutationObserver(function (_, obs) {
			var widgets = document.querySelectorAll('.str-booking-widget[data-property-id]');
			if (widgets.length) { init(); obs.disconnect(); }
		});
		bodyOb.observe(document.body, { childList: true, subtree: true });
	}
})();
JS;
	}

	/**
	 * Register the [str_booking_form] shortcode.
	 */
	public function register_shortcode(): void {
		add_shortcode( 'str_booking_form', array( $this, 'render_shortcode' ) );
	}

	/**
	 * Render booking form shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML markup.
	 */
	public function render_shortcode( array $atts ): string {
		if ( ! $this->licensed ) {
			return '<!-- STR Booking: valid license required -->';
		}

		$atts = shortcode_atts(
			array(
				'property_id' => 0,
			),
			$atts,
			'str_booking_form'
		);

		$property_id = absint( $atts['property_id'] );

		if ( ! $property_id ) {
			return '<p class="str-error">' . esc_html__( 'Invalid property ID.', 'str-direct-booking' ) . '</p>';
		}

		$property = get_post( $property_id );

		if ( ! $property || 'str_property' !== $property->post_type ) {
			return '<p class="str-error">' . esc_html__( 'Property not found.', 'str-direct-booking' ) . '</p>';
		}

		// Divi 5 builder preview — return a static placeholder instead of mounting React.
		// The live booking form renders normally on the published page.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['et_fb'] ) || isset( $_GET['et_pb_preview'] ) ) {
			return '<div class="str-booking-widget str-booking-preview-placeholder">'
				. '<p><strong>' . esc_html__( 'STR Booking Form', 'str-direct-booking' ) . '</strong>'
				. ' &mdash; ' . esc_html( get_the_title( $property_id ) ) . ' (#' . $property_id . ')</p>'
				. '<p><em>' . esc_html__( 'Live booking form renders on the published page.', 'str-direct-booking' ) . '</em></p>'
				. '</div>';
		}

		// Signal to enqueue_scripts() that scripts are needed (useful for late/widget contexts).
		self::$enqueue_scripts = true;

		$this->localize_property( $property_id );

		// Use a per-property ID so multiple instances on the same page work independently.
		return sprintf(
			'<div class="str-booking-widget" id="str-booking-widget-%1$d" data-property-id="%1$d"></div>',
			$property_id
		);
	}

	/**
	 * Pass property-specific data to the React bundle via wp_localize_script.
	 *
	 * @param int $property_id Post ID of the str_property.
	 */
	private function localize_property( int $property_id ): void {
		$plan_manager = new PaymentPlanManager();

		wp_localize_script(
			'str-booking-widget',
			'strBookingProperty',
			array(
				'id'           => $property_id,
				'name'         => get_the_title( $property_id ),
				'maxGuests'    => (int) get_post_meta( $property_id, 'str_max_guests', true ),
				'minNights'    => (int) get_post_meta( $property_id, 'str_min_nights', true ) ?: 1,
				'maxNights'    => (int) get_post_meta( $property_id, 'str_max_nights', true ), // 0 = no limit
				'checkInTime'  => get_post_meta( $property_id, 'str_check_in_time', true ) ?: '15:00',
				'checkOutTime' => get_post_meta( $property_id, 'str_check_out_time', true ) ?: '11:00',
				'planConfig'   => $plan_manager->get_plan_config( $property_id ),
			)
		);
	}
}
