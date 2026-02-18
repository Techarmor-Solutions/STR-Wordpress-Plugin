/**
 * AvailabilityCalendar — public-facing read-only availability calendar.
 *
 * @package STRBooking
 */

import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

const { apiUrl, nonce } = window.strBookingData || {};

if ( nonce ) {
	apiFetch.use( apiFetch.createNonceMiddleware( nonce ) );
}

export default function AvailabilityCalendar( { propertyId } ) {
	const today = new Date();
	const [ year, setYear ] = useState( today.getFullYear() );
	const [ month, setMonth ] = useState( today.getMonth() + 1 );
	const [ calendarData, setCalendarData ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( false );

	useEffect( () => {
		loadCalendar();
	}, [ year, month ] ); // eslint-disable-line react-hooks/exhaustive-deps

	async function loadCalendar() {
		setIsLoading( true );
		try {
			const data = await apiFetch( {
				url: `${ apiUrl }/admin/availability/${ propertyId }?year=${ year }&month=${ month }`,
				method: 'GET',
			} );
			setCalendarData( Array.isArray( data ) ? data : [] );
		} catch ( err ) {
			// Silently fail
		} finally {
			setIsLoading( false );
		}
	}

	const statusMap = {};
	calendarData.forEach( ( row ) => {
		statusMap[ row.date ] = row.status;
	} );

	const firstDay = new Date( year, month - 1, 1 ).getDay();
	const daysInMonth = new Date( year, month, 0 ).getDate();
	const monthName = new Date( year, month - 1 ).toLocaleString( 'default', { month: 'long', year: 'numeric' } );

	function prevMonth() {
		// Don't allow navigating before current month
		if ( year === today.getFullYear() && month === today.getMonth() + 1 ) return;
		if ( month === 1 ) {
			setMonth( 12 );
			setYear( year - 1 );
		} else {
			setMonth( month - 1 );
		}
	}

	function nextMonth() {
		if ( month === 12 ) {
			setMonth( 1 );
			setYear( year + 1 );
		} else {
			setMonth( month + 1 );
		}
	}

	const isCurrentMonth = year === today.getFullYear() && month === today.getMonth() + 1;

	return (
		<div className="str-availability-cal">
			<div className="str-cal-nav">
				<button
					onClick={ prevMonth }
					disabled={ isCurrentMonth }
					aria-label="Previous month"
				>
					‹
				</button>
				<span className="str-cal-month-label">{ monthName }</span>
				<button onClick={ nextMonth } aria-label="Next month">›</button>
			</div>

			{ isLoading ? (
				<div className="str-cal-loading">Loading...</div>
			) : (
				<>
					<div className="str-cal-weekdays">
						{ [ 'Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa' ].map( ( d ) => (
							<div key={ d } className="str-cal-weekday">{ d }</div>
						) ) }
					</div>
					<div className="str-cal-days">
						{ Array.from( { length: firstDay } ).map( ( _, i ) => (
							<div key={ `e-${ i }` } className="str-cal-day str-cal-empty" />
						) ) }
						{ Array.from( { length: daysInMonth }, ( _, i ) => i + 1 ).map( ( day ) => {
							const dateStr = `${ year }-${ String( month ).padStart( 2, '0' ) }-${ String( day ).padStart( 2, '0' ) }`;
							const status = statusMap[ dateStr ] || 'available';
							const isPast = new Date( dateStr ) < today;
							return (
								<div
									key={ day }
									className={ [
										'str-cal-day',
										`str-cal-${ status }`,
										isPast ? 'str-cal-past' : '',
									].join( ' ' ) }
									aria-label={ `${ dateStr }: ${ status }` }
								>
									{ day }
								</div>
							);
						} ) }
					</div>
					<div className="str-cal-legend">
						<span className="str-legend-item">
							<span className="str-legend-dot str-cal-available" />
							Available
						</span>
						<span className="str-legend-item">
							<span className="str-legend-dot str-cal-booked" />
							Unavailable
						</span>
					</div>
				</>
			) }
		</div>
	);
}
