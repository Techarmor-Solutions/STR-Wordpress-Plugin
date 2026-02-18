/**
 * Calendar Widget entry point (standalone availability calendar).
 *
 * @package STRBooking
 */

import { render } from '@wordpress/element';
import AvailabilityCalendar from './components/AvailabilityCalendar';

const containers = document.querySelectorAll( '.str-availability-calendar' );

containers.forEach( ( container ) => {
	const propertyId = parseInt( container.dataset.propertyId, 10 );
	if ( propertyId ) {
		render( <AvailabilityCalendar propertyId={ propertyId } />, container );
	}
} );
