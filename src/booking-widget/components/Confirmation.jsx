/**
 * Confirmation — booking success screen.
 *
 * @package STRBooking
 */

const { currency } = window.strBookingData || {};

function formatCurrency( amount ) {
	return new Intl.NumberFormat( 'en-US', {
		style: 'currency',
		currency: ( currency || 'usd' ).toUpperCase(),
	} ).format( amount );
}

function formatDate( dateStr ) {
	if ( ! dateStr ) return '';
	const [ year, month, day ] = dateStr.split( '-' );
	const date = new Date( year, month - 1, day );
	return date.toLocaleDateString( 'en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' } );
}

export default function Confirmation( { bookingResult, guestDetails, dates, pricing } ) {
	return (
		<div className="str-confirmation">
			<div className="str-confirmation-icon">✓</div>
			<h2>Booking Confirmed!</h2>
			<p className="str-confirmation-sub">
				A confirmation has been sent to <strong>{ guestDetails?.guest_email }</strong>
			</p>

			{ bookingResult?.bookingId && (
				<p className="str-confirmation-number">
					Booking #{ bookingResult.bookingId }
				</p>
			) }

			<div className="str-confirmation-details">
				<div className="str-detail-row">
					<span>Check-in</span>
					<span>{ formatDate( dates?.checkIn ) }</span>
				</div>
				<div className="str-detail-row">
					<span>Check-out</span>
					<span>{ formatDate( dates?.checkOut ) }</span>
				</div>
				<div className="str-detail-row">
					<span>Nights</span>
					<span>{ pricing?.nights }</span>
				</div>
				<div className="str-detail-row str-total">
					<span>Total Charged</span>
					<span>{ formatCurrency( pricing?.total ) }</span>
				</div>
			</div>

			<p className="str-confirmation-next">
				You'll receive pre-arrival instructions 3 days before check-in, and
				check-in details on the morning of your arrival.
			</p>
		</div>
	);
}
