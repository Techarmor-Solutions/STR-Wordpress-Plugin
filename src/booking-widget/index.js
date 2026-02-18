/**
 * Booking Widget entry point.
 *
 * @package STRBooking
 */

import { createRoot } from '@wordpress/element';
import BookingWidget from './components/BookingWidget';

// Skip mounting entirely in the Divi 5 visual builder context.
// The PHP shortcode callback already returns a static placeholder there,
// but this guard provides belt-and-suspenders protection.
if ( document.body.classList.contains( 'et-fb' ) || window.ETBuilderBackend ) {
	// Divi builder active — do nothing.
} else {
	document.querySelectorAll( '.str-booking-widget[data-property-id]' ).forEach( ( container ) => {
		const propertyId = parseInt( container.dataset.propertyId, 10 );
		if ( propertyId ) {
			const root = createRoot( container );
			root.render( <BookingWidget propertyId={ propertyId } /> );
		}
	} );
}
