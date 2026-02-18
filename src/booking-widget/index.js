/**
 * Booking Widget entry point.
 *
 * @package STRBooking
 */

import { render } from '@wordpress/element';
import BookingWidget from './components/BookingWidget';

const container = document.getElementById( 'str-booking-widget' );

if ( container ) {
	const propertyId = parseInt( container.dataset.propertyId, 10 );
	render( <BookingWidget propertyId={ propertyId } />, container );
}
