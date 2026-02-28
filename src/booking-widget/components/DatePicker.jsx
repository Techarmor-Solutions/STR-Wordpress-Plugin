/**
 * DatePicker — date selection with availability checking.
 *
 * @package STRBooking
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

const { apiUrl, nonce } = window.strBookingData || {};
const propertyConfig = window.strBookingProperty || {};

// Configure apiFetch nonce
if ( nonce ) {
	apiFetch.use( apiFetch.createNonceMiddleware( nonce ) );
}

/**
 * Parse a YYYY-MM-DD string as LOCAL midnight, not UTC midnight.
 * new Date("YYYY-MM-DD") parses as UTC which causes date math to be
 * off by one day in negative-offset timezones (most of the US).
 */
function parseLocalDate( dateStr ) {
	const [ year, month, day ] = dateStr.split( '-' ).map( Number );
	return new Date( year, month - 1, day );
}

function formatDate( date ) {
	if ( ! date ) return '';
	const d = date instanceof Date ? date : new Date( date );
	const year = d.getFullYear();
	const month = String( d.getMonth() + 1 ).padStart( 2, '0' );
	const day = String( d.getDate() ).padStart( 2, '0' );
	return `${ year }-${ month }-${ day }`;
}

function addDays( localDate, days ) {
	const result = new Date( localDate );
	result.setDate( result.getDate() + days );
	return result;
}

export default function DatePicker( { propertyId, onDatesSelected, onError } ) {
	const [ checkIn, setCheckIn ] = useState( '' );
	const [ checkOut, setCheckOut ] = useState( '' );
	const [ guests, setGuests ] = useState( 1 );
	const [ isChecking, setIsChecking ] = useState( false );
	const [ isLoadingPricing, setIsLoadingPricing ] = useState( false );
	const [ blockedDates, setBlockedDates ] = useState( [] );
	const [ availability, setAvailability ] = useState( null );
	const minNights = propertyConfig.minNights || 1;
	const maxGuests = propertyConfig.maxGuests || 16;

	// Load blocked dates for the current and next month
	useEffect( () => {
		loadBlockedDates();
	}, [ propertyId ] ); // eslint-disable-line react-hooks/exhaustive-deps

	async function loadBlockedDates() {
		try {
			const now = new Date();
			const data = await apiFetch( {
				url: `${ apiUrl }/calendar/${ propertyId }?year=${ now.getFullYear() }&month=${ now.getMonth() + 1 }`,
				method: 'GET',
			} );

			if ( Array.isArray( data ) ) {
				setBlockedDates(
					data
						.filter( ( row ) => row.status !== 'available' )
						.map( ( row ) => row.date )
				);
			}
		} catch ( err ) {
			// Non-critical — just won't show blocked dates visually
		}
	}

	const checkAvailability = useCallback( async () => {
		if ( ! checkIn || ! checkOut ) return;

		setIsChecking( true );
		setAvailability( null );

		try {
			const data = await apiFetch( {
				url: `${ apiUrl }/availability`,
				method: 'POST',
				data: {
					property_id: propertyId,
					check_in: checkIn,
					check_out: checkOut,
				},
			} );

			setAvailability( data.available );
		} catch ( err ) {
			onError( 'Could not check availability. Please try again.' );
		} finally {
			setIsChecking( false );
		}
	}, [ checkIn, checkOut, propertyId, onError ] );

	useEffect( () => {
		if ( checkIn && checkOut ) {
			checkAvailability();
		}
	}, [ checkIn, checkOut, checkAvailability ] );

	async function handleContinue() {
		if ( ! checkIn || ! checkOut || ! availability ) return;

		setIsLoadingPricing( true );

		try {
			const pricing = await apiFetch( {
				url: `${ apiUrl }/pricing`,
				method: 'POST',
				data: {
					property_id: propertyId,
					check_in: checkIn,
					check_out: checkOut,
					guests,
				},
			} );

			onDatesSelected( checkIn, checkOut, pricing );
		} catch ( err ) {
			onError( 'Could not calculate pricing. Please try again.' );
		} finally {
			setIsLoadingPricing( false );
		}
	}

	const today = formatDate( new Date() );
	const minCheckout = checkIn
		? formatDate( addDays( parseLocalDate( checkIn ), minNights ) )
		: today;

	const nights =
		checkIn && checkOut
			? Math.round(
					( parseLocalDate( checkOut ) - parseLocalDate( checkIn ) ) /
						( 1000 * 60 * 60 * 24 )
			  )
			: 0;

	return (
		<div className="str-date-picker">
			<h3>Select Your Dates</h3>

			<div className="str-field-group">
				<div className="str-field">
					<label htmlFor="str-checkin">Check-in Date</label>
					<input
						type="date"
						id="str-checkin"
						value={ checkIn }
						min={ today }
						onChange={ ( e ) => {
							setCheckIn( e.target.value );
							setCheckOut( '' );
							setAvailability( null );
						} }
					/>
				</div>

				<div className="str-field">
					<label htmlFor="str-checkout">Check-out Date</label>
					<input
						type="date"
						id="str-checkout"
						value={ checkOut }
						min={ minCheckout }
						disabled={ ! checkIn }
						onChange={ ( e ) => {
							setCheckOut( e.target.value );
							setAvailability( null );
						} }
					/>
				</div>

				<div className="str-field">
					<label htmlFor="str-guests">Guests</label>
					<select
						id="str-guests"
						value={ guests }
						onChange={ ( e ) => setGuests( parseInt( e.target.value, 10 ) ) }
					>
						{ Array.from( { length: maxGuests }, ( _, i ) => i + 1 ).map( ( n ) => (
							<option key={ n } value={ n }>
								{ n } { n === 1 ? 'guest' : 'guests' }
							</option>
						) ) }
					</select>
				</div>
			</div>

			{ isChecking && (
				<p className="str-status">Checking availability...</p>
			) }

			{ ! isChecking && availability === true && nights > 0 && (
				<div className="str-availability-ok">
					<p>
						✓ Available for { nights } { nights === 1 ? 'night' : 'nights' }
					</p>
				</div>
			) }

			{ ! isChecking && availability === false && (
				<div className="str-availability-unavailable">
					<p>✗ Sorry, those dates are not available. Please select different dates.</p>
				</div>
			) }

			<button
				className="str-btn str-btn-primary"
				disabled={ ! availability || isLoadingPricing }
				onClick={ handleContinue }
			>
				{ isLoadingPricing ? 'Calculating...' : 'Continue →' }
			</button>
		</div>
	);
}
