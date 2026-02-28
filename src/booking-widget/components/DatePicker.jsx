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

function formatDateShort( dateStr ) {
	if ( ! dateStr ) return '';
	const [ year, month, day ] = dateStr.split( '-' ).map( Number );
	const d = new Date( year, month - 1, day );
	return d.toLocaleDateString( 'en-US', { month: 'short', day: 'numeric' } );
}

function addDays( localDate, days ) {
	const result = new Date( localDate );
	result.setDate( result.getDate() + days );
	return result;
}

/**
 * Convert a sorted array of YYYY-MM-DD strings into consecutive date ranges.
 * e.g. ['2025-06-10','2025-06-11','2025-06-13'] → [{start:'06-10',end:'06-11'},{start:'06-13',end:'06-13'}]
 */
function getBlockedRanges( sortedDates ) {
	if ( ! sortedDates.length ) return [];
	const ranges = [];
	let start = sortedDates[ 0 ];
	let prev  = sortedDates[ 0 ];

	for ( let i = 1; i < sortedDates.length; i++ ) {
		const curr     = sortedDates[ i ];
		const prevDate = parseLocalDate( prev );
		const currDate = parseLocalDate( curr );
		const diff     = ( currDate - prevDate ) / ( 1000 * 60 * 60 * 24 );

		if ( diff === 1 ) {
			prev = curr;
		} else {
			ranges.push( { start, end: prev } );
			start = curr;
			prev  = curr;
		}
	}
	ranges.push( { start, end: prev } );
	return ranges;
}

export default function DatePicker( { propertyId, onDatesSelected, onError } ) {
	const [ checkIn, setCheckIn ]               = useState( '' );
	const [ checkOut, setCheckOut ]             = useState( '' );
	const [ guests, setGuests ]                 = useState( 1 );
	const [ isChecking, setIsChecking ]         = useState( false );
	const [ isLoadingPricing, setIsLoadingPricing ] = useState( false );
	const [ blockedDates, setBlockedDates ]     = useState( [] );
	const [ availability, setAvailability ]     = useState( null );
	const minNights = propertyConfig.minNights || 1;
	const maxGuests = propertyConfig.maxGuests || 16;

	// Load blocked dates for the current month and the next 2 months
	useEffect( () => {
		loadBlockedDates();
	}, [ propertyId ] ); // eslint-disable-line react-hooks/exhaustive-deps

	async function loadBlockedDates() {
		try {
			const now    = new Date();
			const months = [];
			for ( let i = 0; i < 3; i++ ) {
				const d = new Date( now.getFullYear(), now.getMonth() + i, 1 );
				months.push( { year: d.getFullYear(), month: d.getMonth() + 1 } );
			}

			const results = await Promise.all(
				months.map( ( { year, month } ) =>
					apiFetch( {
						url:    `${ apiUrl }/calendar/${ propertyId }?year=${ year }&month=${ month }`,
						method: 'GET',
					} )
				)
			);

			const allRows = results.flat();
			if ( Array.isArray( allRows ) ) {
				setBlockedDates(
					allRows
						.filter( ( row ) => row.status !== 'available' )
						.map( ( row ) => row.date )
						.sort()
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
				url:    `${ apiUrl }/availability`,
				method: 'POST',
				data:   {
					property_id: propertyId,
					check_in:    checkIn,
					check_out:   checkOut,
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

		if ( nights < minNights ) {
			onError(
				`Minimum stay is ${ minNights } ${ minNights === 1 ? 'night' : 'nights' }. ` +
				`Please select a check-out date at least ${ minNights } ${ minNights === 1 ? 'night' : 'nights' } after check-in.`
			);
			return;
		}

		setIsLoadingPricing( true );

		try {
			const pricing = await apiFetch( {
				url:    `${ apiUrl }/pricing`,
				method: 'POST',
				data:   {
					property_id: propertyId,
					check_in:    checkIn,
					check_out:   checkOut,
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

	const today       = formatDate( new Date() );
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

	// Only show upcoming blocked ranges (today or later)
	const upcomingBlocked = blockedDates.filter( ( d ) => d >= today );
	const blockedRanges   = getBlockedRanges( upcomingBlocked );

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

			{ minNights > 1 && (
				<p className="str-min-nights-note">
					Minimum stay: { minNights } nights
				</p>
			) }

			{ blockedRanges.length > 0 && (
				<div className="str-blocked-dates-notice">
					<strong>Already booked:</strong>{ ' ' }
					{ blockedRanges.map( ( r, i ) => (
						<span key={ r.start }>
							{ i > 0 && ', ' }
							{ r.start === r.end
								? formatDateShort( r.start )
								: `${ formatDateShort( r.start ) }–${ formatDateShort( r.end ) }` }
						</span>
					) ) }
				</div>
			) }

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
