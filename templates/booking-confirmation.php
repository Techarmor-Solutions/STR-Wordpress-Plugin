<?php
/**
 * Booking confirmation page template.
 * Used when displaying booking details to guests after payment.
 *
 * @package STRBooking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// This template is rendered via the booking widget's Confirmation component.
// The div below is the React mount target.
?>
<div id="str-booking-confirmation" data-booking-id="<?php echo esc_attr( get_query_var( 'str_booking_id', '' ) ); ?>">
	<p><?php esc_html_e( 'Loading your booking details...', 'str-direct-booking' ); ?></p>
</div>
