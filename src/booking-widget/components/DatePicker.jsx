/**
 * DatePicker — date selection with availability checking.
 *
 * Blocked dates are loaded up-front (18 months) and used to constrain
 * the checkout input's max attribute directly. The user cannot select
 * an invalid checkout — the browser natively disables out-of-range dates.
 *
 * @package STRBooking
 */

import { useState, useEffect } from '@wordpress/element';
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

/**
 * Given a check-in date string, compute:
 * - minCheckout: checkIn + minNights
 * - maxCheckout: day before the first blocked date on or after checkIn+1,
 *                or null if no blocked dates exist in that window
 */
function computeCheckoutWindow( checkIn, blockedDates, minNights ) {
	const checkInDate = parseLocalDate( checkIn );
	const minCheckout = formatDate( addDays( checkInDate, minNights ) );

	// Scan forward from checkIn+1 looking for the first blocked date
	let maxCheckout = null;
	const scan = new Date( checkInDate );
	scan.setDate( scan.getDate() + 1 ); // start day after check-in
	const horizon = addDays( checkInDate, 540 ); // 18 months ahead

	while ( scan < horizon ) {
		const dateStr = formatDate( scan );
		if ( blockedDates.has( dateStr ) ) {
			// maxCheckout = the day before this blocked date
			const prev = new Date( scan );
			prev.setDate( prev.getDate() - 1 );
			maxCheckout = formatDate( prev );
			break;
		}
		scan.setDate( scan.getDate() + 1 );
	}

	return { minCheckout, maxCheckout };
}

export default function DatePicker( { propertyId, onDatesSelected, onError } ) {
	const [ checkIn, setCheckIn ]                   = useState( '' );
	const [ checkOut, setCheckOut ]                 = useState( '' );
	const [ guests, setGuests ]                     = useState( 1 );
	const [ blockedDates, setBlockedDates ]         = useState( new Set() );
	const [ isLoadingDates, setIsLoadingDates ]     = useState( true );
	const [ availability, setAvailability ]         = useState( null ); // null | true | false
	const [ isChecking, setIsChecking ]             = useState( false );
	const [ isLoadingPricing, setIsLoadingPricing ] = useState( false );
	const [ noWindowMessage, setNoWindowMessage ]   = useState( '' );

	const minNights = propertyConfig.minNights || 1;
	const maxGuests = propertyConfig.maxGuests || 16;

	// Load 18 months of blocked dates on mount
	useEffect( () => {
		loadBlockedDates();
	}, [ propertyId ] ); // eslint-disable-line react-hooks/exhaustive-deps

	// Check availability whenever a complete check-in + check-out pair is set
	useEffect( () => {
		if ( ! checkIn || ! checkOut ) return;
		let cancelled = false;

		setIsChecking( true );
		setAvailability( null );

		apiFetch( {
			url:    `${ apiUrl }/availability`,
			method: 'POST',
			data:   { property_id: propertyId, check_in: checkIn, check_out: checkOut },
		} )
			.then( ( data ) => {
				if ( ! cancelled ) setAvailability( data.available );
			} )
			.catch( () => {
				if ( ! cancelled ) onError( 'Could not check availability. Please try again.' );
			} )
			.finally( () => {
				if ( ! cancelled ) setIsChecking( false );
			} );

		return () => { cancelled = true; };
	}, [ checkIn, checkOut ] ); // eslint-disable-line react-hooks/exhaustive-deps

	async function loadBlockedDates() {
		setIsLoadingDates( true );
		try {
			const now = new Date();
			const months = [];
			for ( let i = 0; i < 18; i++ ) {
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
			const blocked = new Set(
				allRows.filter( ( r ) => r.status !== 'available' ).map( ( r ) => r.date )
			);
			setBlockedDates( blocked );
		} catch ( e ) {
			// Non-fatal — availability API will still catch conflicts server-side
		} finally {
			setIsLoadingDates( false );
		}
	}

	function handleCheckInChange( value ) {
		setCheckIn( value );
		setCheckOut( '' );
		setAvailability( null );
		setNoWindowMessage( '' );

		if ( ! value ) return;

		const { minCheckout, maxCheckout } = computeCheckoutWindow( value, blockedDates, minNights );

		if ( maxCheckout && maxCheckout < minCheckout ) {
			setNoWindowMessage(
				`No availability from this check-in date (minimum ${ minNights }-night stay required). ` +
				`Please select a different check-in date.`
			);
		}
	}

	async function handleContinue() {
		if ( ! checkIn || ! checkOut || ! availability ) return;

		setIsLoadingPricing( true );

		try {
			const pricing = await apiFetch( {
				url:    `${ apiUrl }/pricing`,
				method: 'POST',
				data:   { property_id: propertyId, check_in: checkIn, check_out: checkOut, guests },
			} );

			onDatesSelected( checkIn, checkOut, pricing );
		} catch ( err ) {
			onError( 'Could not calculate pricing. Please try again.' );
		} finally {
			setIsLoadingPricing( false );
		}
	}

	// Derived values
	const today = formatDate( new Date() );

	const { minCheckout, maxCheckout } = checkIn
		? computeCheckoutWindow( checkIn, blockedDates, minNights )
		: { minCheckout: today, maxCheckout: null };

	const windowTooNarrow = checkIn && maxCheckout && maxCheckout < minCheckout;

	const nights =
		checkIn && checkOut
			? Math.round( ( parseLocalDate( checkOut ) - parseLocalDate( checkIn ) ) / 86400000 )
			: 0;

	return (
		<div className="str-date-picker">
			<h3>Select Your Dates</h3>

			{ isLoadingDates && <p className="str-status">Loading availability...</p> }

			<div className="str-field-group">
				<div className="str-field">
					<label htmlFor="str-checkin">Check-in Date</label>
					<input
						type="date"
						id="str-checkin"
						value={ checkIn }
						min={ today }
						onChange={ ( e ) => handleCheckInChange( e.target.value ) }
					/>
				</div>

				<div className="str-field">
					<label htmlFor="str-checkout">Check-out Date</label>
					<input
						type="date"
						id="str-checkout"
						value={ checkOut }
						min={ minCheckout }
						max={ maxCheckout || undefined }
						disabled={ ! checkIn || !! windowTooNarrow || isLoadingDates }
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
				<p className="str-min-nights-note">Minimum stay: { minNights } nights</p>
			) }

			{ windowTooNarrow && (
				<p className="str-availability-unavailable">{ noWindowMessage }</p>
			) }

			{ isChecking && <p className="str-status">Checking availability...</p> }

			{ ! isChecking && availability === true && nights > 0 && (
				<p className="str-availability-ok">
					✓ Available for { nights } { nights === 1 ? 'night' : 'nights' }
				</p>
			) }

			{ ! isChecking && availability === false && (
				<p className="str-availability-unavailable">
					✗ Those dates are not available. Please select different dates.
				</p>
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
