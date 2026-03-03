/**
 * DatePicker — single range-selection calendar (Airbnb-style).
 *
 * @package STRBooking
 */

import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

const { apiUrl, nonce } = window.strBookingData || {};
const propertyConfig    = window.strBookingProperty || {};

if ( nonce ) {
	apiFetch.use( apiFetch.createNonceMiddleware( nonce ) );
}

// ── Date helpers ──────────────────────────────────────────────────────────────

/** Parse YYYY-MM-DD as local midnight (avoids UTC off-by-one in US timezones). */
function toDateObj( str ) {
	const [ y, m, d ] = str.split( '-' ).map( Number );
	return new Date( y, m - 1, d );
}

/** Format a Date object as YYYY-MM-DD. */
function toDateStr( date ) {
	const y = date.getFullYear();
	const m = String( date.getMonth() + 1 ).padStart( 2, '0' );
	const d = String( date.getDate() ).padStart( 2, '0' );
	return `${ y }-${ m }-${ d }`;
}

/** Return a new Date that is `n` days after `date` (non-mutating). */
function addDays( date, n ) {
	const result = new Date( date );
	result.setDate( result.getDate() + n );
	return result;
}

/** True when two Date objects represent the same calendar day. */
function isSameDay( a, b ) {
	return (
		a.getFullYear() === b.getFullYear() &&
		a.getMonth()    === b.getMonth()    &&
		a.getDate()     === b.getDate()
	);
}

/**
 * Scan forward from day-after-checkIn for the first blocked night.
 * Returns that first blocked date itself — checkout ON that day is valid
 * because the guest leaves in the morning before the next check-in arrives.
 * Returns null if no blocked date is found within 18 months.
 */
function computeMaxCheckout( checkIn, blockedDates ) {
	if ( blockedDates.size === 0 ) return null;
	const horizon = addDays( checkIn, 540 );
	let current   = addDays( checkIn, 1 );
	while ( current < horizon ) {
		if ( blockedDates.has( toDateStr( current ) ) ) {
			return current; // fresh Date from addDays — safe to return
		}
		current = addDays( current, 1 ); // new Date each iteration, no mutation
	}
	return null;
}

// ── CalendarGrid ──────────────────────────────────────────────────────────────

const DOW_LABELS = [ 'Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa' ];
const MONTH_NAMES = [
	'January', 'February', 'March', 'April', 'May', 'June',
	'July', 'August', 'September', 'October', 'November', 'December',
];

function CalendarGrid( {
	year,
	month, // 0-indexed
	checkIn,
	checkOut,
	hoverDate,
	blockedDates,
	minNights,
	maxNights, // 0 = no limit
	onDayClick,
	onDayHover,
	onDayLeave,
	onPrev,
	onNext,
} ) {
	const today = new Date();
	today.setHours( 0, 0, 0, 0 );

	// Checkout phase boundaries (computed before isClickable so it can use them).
	const minCO          = checkIn ? addDays( checkIn, minNights ) : null;
	const maxCO          = checkIn ? computeMaxCheckout( checkIn, blockedDates ) : null;
	// Upper bound from maxNights setting (0 = no limit).
	const maxCOByNights  = ( checkIn && maxNights ) ? addDays( checkIn, maxNights ) : null;

	// maxCO is itself a blocked date — treat it as a valid checkout boundary
	// (leave that morning, next guest checks in that day).
	const maxCOStr = maxCO ? toDateStr( maxCO ) : null;

	const firstDay    = new Date( year, month, 1 );
	const startOffset = firstDay.getDay();
	const daysInMonth = new Date( year, month + 1, 0 ).getDate();

	/**
	 * Whether a date is selectable by the user given the current phase.
	 */
	function isClickable( date ) {
		const str = toDateStr( date );
		if ( ! checkIn || checkOut ) {
			// Check-in phase: today or future, not blocked.
			return date >= today && ! blockedDates.has( str );
		}
		// Checkout phase: must be within [minCO, maxCO] and not exceed maxNights.
		// We do NOT reject blockedDates here because maxCO itself is a blocked
		// date (the first blocked night) but is a valid checkout day.
		if ( ! minCO || date < minCO              ) return false;
		if ( maxCO && date > maxCO                ) return false;
		if ( maxCOByNights && date > maxCOByNights ) return false;
		return true;
	}

	// rangeEnd: hover only highlights a date when it is actually a valid clickable
	// checkout. Without this guard, hovering a non-selectable date gets misleading
	// dark "range-end" styling even though clicking does nothing.
	const hoverIsValid = checkIn && ! checkOut && hoverDate && isClickable( hoverDate );
	const rangeEnd     = checkOut || ( hoverIsValid ? hoverDate : null );

	/** CSS classes for semantic/accessibility purposes (visual handled by dayStyle). */
	function classesFor( date ) {
		const classes   = [ 'str-bk-cal-day' ];
		const str       = toDateStr( date );
		const isBlocked = blockedDates.has( str );
		const isPast    = date < today;
		const isToday   = isSameDay( date, today );
		const isStart   = checkIn  && isSameDay( date, checkIn );
		const isEnd     = rangeEnd && isSameDay( date, rangeEnd );
		// During checkout phase, maxCO is a valid checkout despite being blocked.
		const isMaxCOBoundary = checkIn && ! checkOut && str === maxCOStr;

		if ( isPast                        ) classes.push( 'str-bk-cal-day--past' );
		if ( isToday                       ) classes.push( 'str-bk-cal-day--today' );
		if ( isBlocked && ! isMaxCOBoundary ) classes.push( 'str-bk-cal-day--blocked' );
		if ( isStart                       ) classes.push( 'str-bk-cal-day--range-start' );
		if ( isEnd                         ) classes.push( 'str-bk-cal-day--range-end' );

		// Checkout phase invalids (no isBlocked here — maxCO handles that).
		if ( checkIn && ! checkOut && ! isStart ) {
			const beforeMin        = minCO && date < minCO;
			const afterMax         = maxCO && date > maxCO;
			const afterMaxByNights = maxCOByNights && date > maxCOByNights;
			if ( isPast || beforeMin || afterMax || afterMaxByNights ) {
				classes.push( 'str-bk-cal-day--invalid' );
			}
		}

		// In-range.
		if ( checkIn && rangeEnd && date > checkIn && date < rangeEnd && ! isStart && ! isEnd ) {
			classes.push( 'str-bk-cal-day--in-range' );
		}

		// Hover preview (only when date is actually clickable).
		if ( checkIn && ! checkOut && hoverDate && isSameDay( date, hoverDate ) && isClickable( date ) ) {
			classes.push( 'str-bk-cal-day--hover-preview' );
		}

		return classes.join( ' ' );
	}

	/**
	 * Inline styles — these guarantee visual state even when the theme overrides CSS classes.
	 */
	function dayStyle( date ) {
		const str       = toDateStr( date );
		const isBlocked = blockedDates.has( str );
		const isPast    = date < today;
		const isStart   = checkIn  && isSameDay( date, checkIn );
		const isEnd     = rangeEnd && isSameDay( date, rangeEnd );
		const inRange   = checkIn && rangeEnd && date > checkIn && date < rangeEnd && ! isStart && ! isEnd;
		const isHover   = checkIn && ! checkOut && hoverDate && isSameDay( date, hoverDate ) && isClickable( date );
		const isMaxCOBoundary = checkIn && ! checkOut && str === maxCOStr;
		const beforeMin        = checkIn && ! checkOut && minCO && date < minCO;
		const afterMax         = checkIn && ! checkOut && maxCO && date > maxCO;
		const afterMaxByNights = checkIn && ! checkOut && maxCOByNights && date > maxCOByNights;
		const isInvalid        = ! isStart && ( isPast || beforeMin || afterMax || afterMaxByNights );

		// Range endpoints (highest priority).
		if ( isStart && isEnd ) return { background: '#1a1a2e', color: '#fff', borderRadius: '6px' };
		if ( isStart          ) return { background: '#1a1a2e', color: '#fff', borderRadius: '6px 0 0 6px' };

		// Hover preview.
		if ( isHover ) return { background: '#374151', color: '#fff', borderRadius: '0 6px 6px 0', cursor: 'pointer' };

		// Range end.
		if ( isEnd ) return { background: '#1a1a2e', color: '#fff', borderRadius: '0 6px 6px 0' };

		// In-range shading.
		if ( inRange ) return { background: 'rgba(26,26,46,0.08)', borderRadius: '0' };

		// Invalid during checkout selection.
		if ( isInvalid ) return { color: '#d1d5db', cursor: 'not-allowed' };

		// Blocked night — greyed out but no strikethrough (cleaner look).
		// Exception: maxCO boundary is blocked but IS a valid checkout day.
		if ( isBlocked && ! isMaxCOBoundary ) {
			return { color: '#cbd5e1', cursor: 'not-allowed', background: 'rgba(0,0,0,0.04)' };
		}

		// Past dates.
		if ( isPast ) return { color: '#d1d5db', cursor: 'default' };

		return {};
	}

	// Build cell list: weekday-offset empty cells + day cells.
	const cells = [];
	for ( let i = 0; i < startOffset; i++ ) {
		cells.push( { empty: true, key: `e${ i }` } );
	}
	for ( let d = 1; d <= daysInMonth; d++ ) {
		cells.push( { empty: false, date: new Date( year, month, d ) } );
	}

	return (
		<div className="str-bk-calendar">
			<div
				className="str-bk-cal-header"
				style={ { display: 'flex', flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' } }
			>
				<button
					className="str-bk-cal-nav-btn"
					onClick={ onPrev }
					aria-label="Previous month"
				>
					‹
				</button>
				<span className="str-bk-cal-title">
					{ MONTH_NAMES[ month ] } { year }
				</span>
				<button
					className="str-bk-cal-nav-btn"
					onClick={ onNext }
					aria-label="Next month"
				>
					›
				</button>
			</div>

			<div
				className="str-bk-cal-grid"
				style={ { display: 'grid', gridTemplateColumns: 'repeat(7, 1fr)' } }
			>
				{ DOW_LABELS.map( ( dow ) => (
					<div key={ dow } className="str-bk-cal-dow">{ dow }</div>
				) ) }

				{ cells.map( ( cell ) => {
					if ( cell.empty ) {
						return <div key={ cell.key } className="str-bk-cal-day str-bk-cal-day--empty" />;
					}
					const { date }  = cell;
					const clickable = isClickable( date );
					return (
						<div
							key={ toDateStr( date ) }
							className={ classesFor( date ) }
							style={ dayStyle( date ) }
							onClick={ clickable ? () => onDayClick( date ) : undefined }
							onMouseEnter={ () => onDayHover( date ) }
							onMouseLeave={ onDayLeave }
							role={ clickable ? 'button' : undefined }
							tabIndex={ clickable ? 0 : undefined }
							onKeyDown={ clickable ? ( e ) => e.key === 'Enter' && onDayClick( date ) : undefined }
							aria-label={ toDateStr( date ) }
						>
							{ date.getDate() }
						</div>
					);
				} ) }
			</div>
		</div>
	);
}

// ── STRDatePicker ─────────────────────────────────────────────────────────────

export default function STRDatePicker( { propertyId, onDatesSelected, onError } ) {
	const now = new Date();

	const [ checkIn,          setCheckIn ]          = useState( null );
	const [ checkOut,         setCheckOut ]         = useState( null );
	const [ hoverDate,        setHoverDate ]        = useState( null );
	const [ viewYear,         setViewYear ]         = useState( now.getFullYear() );
	const [ viewMonth,        setViewMonth ]        = useState( now.getMonth() );
	const [ guests,           setGuests ]           = useState( 1 );
	const [ blockedDates,     setBlockedDates ]     = useState( new Set() );
	const [ loadedMonths,     setLoadedMonths ]     = useState( new Set() );
	const [ availability,     setAvailability ]     = useState( null );
	const [ isChecking,       setIsChecking ]       = useState( false );
	const [ isLoadingPricing, setIsLoadingPricing ] = useState( false );
	const [ checkInError,     setCheckInError ]     = useState( null );

	// wp_localize_script serializes all values as strings — parse to integers.
	const minNights = parseInt( propertyConfig.minNights, 10 ) || 1;
	const maxNights = parseInt( propertyConfig.maxNights, 10 ) || 0; // 0 = no limit
	const maxGuests = parseInt( propertyConfig.maxGuests, 10 ) || 16;

	const nights =
		checkIn && checkOut
			? Math.round( ( checkOut - checkIn ) / 86400000 )
			: 0;

	// ── Blocked date loading ──────────────────────────────────────────────

	async function fetchMonthIfNeeded( year, month ) {
		const key = `${ year }-${ String( month ).padStart( 2, '0' ) }`;
		if ( loadedMonths.has( key ) ) return;
		setLoadedMonths( ( prev ) => new Set( prev ).add( key ) );
		try {
			const rows = await apiFetch( {
				url:    `${ apiUrl }/calendar/${ propertyId }?year=${ year }&month=${ month }`,
				method: 'GET',
			} );
			if ( Array.isArray( rows ) ) {
				setBlockedDates( ( prev ) => {
					const next = new Set( prev );
					rows.filter( ( r ) => r.status !== 'available' ).forEach( ( r ) => next.add( r.date ) );
					return next;
				} );
			}
		} catch ( e ) {
			// Non-fatal — the availability endpoint is the authoritative check.
		}
	}

	// On mount: pre-load current month + next 2 months.
	useEffect( () => {
		const d = new Date();
		for ( let i = 0; i < 3; i++ ) {
			const m = new Date( d.getFullYear(), d.getMonth() + i, 1 );
			fetchMonthIfNeeded( m.getFullYear(), m.getMonth() + 1 );
		}
	}, [ propertyId ] ); // eslint-disable-line react-hooks/exhaustive-deps

	// ── Month navigation ──────────────────────────────────────────────────

	function handlePrev() {
		let y = viewYear;
		let m = viewMonth - 1;
		if ( m < 0 ) { m = 11; y -= 1; }
		setViewYear( y );
		setViewMonth( m );
		fetchMonthIfNeeded( y, m + 1 );
	}

	function handleNext() {
		let y = viewYear;
		let m = viewMonth + 1;
		if ( m > 11 ) { m = 0; y += 1; }
		setViewYear( y );
		setViewMonth( m );
		fetchMonthIfNeeded( y, m + 1 );
	}

	// ── Day interaction ───────────────────────────────────────────────────

	function handleDayClick( date ) {
		if ( ! checkIn || checkOut ) {
			// Before committing this check-in, verify a valid checkout window exists.
			// maxCO is the first blocked night (checkout ON that day is valid).
			// If maxCO < minCO, there are zero selectable checkout dates.
			const tentativeMinCO = addDays( date, minNights );
			const tentativeMaxCO = computeMaxCheckout( date, blockedDates );

			if ( tentativeMaxCO && tentativeMaxCO < tentativeMinCO ) {
				setCheckInError(
					`No checkout dates are available within the minimum stay from this date — ` +
					`an existing booking starts too soon. Try a different arrival date.`
				);
				setHoverDate( null );
				return;
			}

			setCheckIn( date );
			setCheckOut( null );
			setAvailability( null );
			setHoverDate( null );
			setCheckInError( null );
		} else {
			// Set check-out.
			setCheckOut( date );
			setHoverDate( null );
			setAvailability( null );
		}
	}

	function handleDayHover( date ) {
		setHoverDate( date );
	}

	function handleDayLeave() {
		setHoverDate( null );
	}

	// ── Race-condition guard ──────────────────────────────────────────────
	// When blocked dates load/update AFTER the user already selected a check-in,
	// re-validate. If the newly-loaded data reveals no valid checkout window,
	// reset the check-in so the user picks a new one.
	useEffect( () => {
		if ( ! checkIn || checkOut || blockedDates.size === 0 ) return;
		const maxCO = computeMaxCheckout( checkIn, blockedDates );
		const minCO = addDays( checkIn, minNights );
		if ( maxCO && maxCO < minCO ) {
			setCheckIn( null );
			setCheckOut( null );
			setAvailability( null );
			setCheckInError(
				'An existing reservation is too close to that arrival date. Please choose a different check-in date.'
			);
		}
	}, [ blockedDates ] ); // eslint-disable-line react-hooks/exhaustive-deps

	// ── Availability check ────────────────────────────────────────────────

	useEffect( () => {
		if ( ! checkIn || ! checkOut ) return;

		let cancelled = false;
		setIsChecking( true );
		setAvailability( null );

		apiFetch( {
			url:    `${ apiUrl }/availability`,
			method: 'POST',
			data:   {
				property_id: propertyId,
				check_in:    toDateStr( checkIn ),
				check_out:   toDateStr( checkOut ),
			},
		} )
			.then( ( data ) => { if ( ! cancelled ) setAvailability( data.available ); } )
			.catch( () => {
				if ( ! cancelled ) onError( 'Could not check availability. Please try again.' );
			} )
			.finally( () => { if ( ! cancelled ) setIsChecking( false ); } );

		return () => { cancelled = true; };
	}, [ checkIn, checkOut ] ); // eslint-disable-line react-hooks/exhaustive-deps

	// ── Continue handler ──────────────────────────────────────────────────

	async function handleContinue() {
		if ( ! checkIn || ! checkOut || ! availability ) return;

		setIsLoadingPricing( true );
		try {
			const pricing = await apiFetch( {
				url:    `${ apiUrl }/pricing`,
				method: 'POST',
				data:   {
					property_id: propertyId,
					check_in:    toDateStr( checkIn ),
					check_out:   toDateStr( checkOut ),
					guests,
				},
			} );
			onDatesSelected( toDateStr( checkIn ), toDateStr( checkOut ), pricing );
		} catch ( err ) {
			onError( 'Could not calculate pricing. Please try again.' );
		} finally {
			setIsLoadingPricing( false );
		}
	}

	// ── Formatted display values ──────────────────────────────────────────

	const fmtOpts = { month: 'short', day: 'numeric', year: 'numeric' };
	const checkInDisplay  = checkIn  ? checkIn.toLocaleDateString(  'en-US', fmtOpts ) : '—';
	const checkOutDisplay = checkOut ? checkOut.toLocaleDateString( 'en-US', fmtOpts ) : '—';

	// ── Render ────────────────────────────────────────────────────────────

	return (
		<div className="str-date-picker">
			<h3>Select Your Dates</h3>

			{ /* Date range bar */ }
			<div
				className="str-date-range-bar"
				style={ { display: 'flex', flexDirection: 'row' } }
			>
				<div
					className={ `str-date-range-item${ ! checkIn ? ' is-active' : '' }` }
					style={ { flex: 1, display: 'flex', flexDirection: 'column' } }
				>
					<span className="str-date-range-label">Check-in</span>
					<span className="str-date-range-value">{ checkInDisplay }</span>
				</div>
				<span className="str-date-range-sep" style={ { alignSelf: 'center', padding: '0 8px' } }>→</span>
				<div
					className={ `str-date-range-item${ checkIn && ! checkOut ? ' is-active' : '' }` }
					style={ { flex: 1, display: 'flex', flexDirection: 'column' } }
				>
					<span className="str-date-range-label">Check-out</span>
					<span className="str-date-range-value">{ checkOutDisplay }</span>
				</div>
			</div>

			{ /* Context hint / error */ }
			{ checkInError ? (
				<p className="str-cal-hint" style={ { color: '#dc2626', fontStyle: 'normal', fontWeight: 500 } }>
					{ checkInError }
				</p>
			) : (
				<p className="str-cal-hint">
					{ ! checkIn && 'Select your check-in date' }
					{ checkIn && ! checkOut && 'Now select your check-out date' }
					{ checkIn && checkOut && `${ nights } night${ nights !== 1 ? 's' : '' } selected — click any date to change` }
				</p>
			) }

			<CalendarGrid
				year={ viewYear }
				month={ viewMonth }
				checkIn={ checkIn }
				checkOut={ checkOut }
				hoverDate={ hoverDate }
				blockedDates={ blockedDates }
				minNights={ minNights }
				maxNights={ maxNights }
				onDayClick={ handleDayClick }
				onDayHover={ handleDayHover }
				onDayLeave={ handleDayLeave }
				onPrev={ handlePrev }
				onNext={ handleNext }
			/>

			{ /* Calendar legend */ }
			<div className="str-bk-cal-legend" style={ { display: 'flex', flexDirection: 'row', gap: '16px' } }>
				<span className="str-bk-cal-legend-item" style={ { display: 'flex', alignItems: 'center', gap: '6px' } }>
					<span className="str-bk-cal-legend-dot str-bk-cal-legend-dot--available" />
					Available
				</span>
				<span className="str-bk-cal-legend-item" style={ { display: 'flex', alignItems: 'center', gap: '6px' } }>
					<span className="str-bk-cal-legend-dot str-bk-cal-legend-dot--blocked" />
					Booked
				</span>
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

			{ minNights > 1 && (
				<p className="str-min-nights-note">Minimum stay: { minNights } nights</p>
			) }

			{ isChecking && (
				<p className="str-status">Checking availability...</p>
			) }

			{ ! isChecking && availability === true && nights > 0 && (
				<p className="str-availability-ok">
					✓ Available for { nights } { nights === 1 ? 'night' : 'nights' }
				</p>
			) }

			{ ! isChecking && availability === false && (
				<p className="str-availability-unavailable">
					✗ Those dates overlap a reservation. Try a different check-out date.
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
