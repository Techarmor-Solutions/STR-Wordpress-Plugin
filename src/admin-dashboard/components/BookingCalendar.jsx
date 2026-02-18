/**
 * BookingCalendar — admin availability calendar view.
 *
 * @package STRBooking
 */

import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

const { apiUrl } = window.strAdminData || {};

const STATUS_COLORS = {
	available: '#22c55e',
	booked: '#ef4444',
	blocked: '#f97316',
};

const STATUS_LABELS = {
	available: 'Available',
	booked: 'Booked',
	blocked: 'Blocked (external)',
};

export default function BookingCalendar( { propertyId } ) {
	const today = new Date();
	const [ year, setYear ] = useState( today.getFullYear() );
	const [ month, setMonth ] = useState( today.getMonth() + 1 );
	const [ calendarData, setCalendarData ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( false );

	useEffect( () => {
		if ( propertyId ) {
			loadCalendar();
		}
	}, [ propertyId, year, month ] ); // eslint-disable-line react-hooks/exhaustive-deps

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

	if ( ! propertyId ) {
		return <p>Select a property to view its calendar.</p>;
	}

	return (
		<div className="str-booking-calendar">
			<div className="str-cal-header">
				<button onClick={ prevMonth }>‹</button>
				<h4>{ monthName }</h4>
				<button onClick={ nextMonth }>›</button>
			</div>

			{ isLoading ? (
				<p>Loading...</p>
			) : (
				<>
					<div className="str-cal-grid str-cal-weekdays">
						{ [ 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' ].map( ( d ) => (
							<div key={ d } className="str-cal-weekday">{ d }</div>
						) ) }
					</div>
					<div className="str-cal-grid str-cal-days">
						{ Array.from( { length: firstDay } ).map( ( _, i ) => (
							<div key={ `empty-${ i }` } className="str-cal-day str-cal-empty" />
						) ) }
						{ Array.from( { length: daysInMonth }, ( _, i ) => i + 1 ).map( ( day ) => {
							const dateStr = `${ year }-${ String( month ).padStart( 2, '0' ) }-${ String( day ).padStart( 2, '0' ) }`;
							const status = statusMap[ dateStr ] || 'available';
							return (
								<div
									key={ day }
									className={ `str-cal-day str-cal-status-${ status }` }
									title={ STATUS_LABELS[ status ] || status }
									style={ { backgroundColor: STATUS_COLORS[ status ] || '#e5e7eb' } }
								>
									{ day }
								</div>
							);
						} ) }
					</div>
					<div className="str-cal-legend">
						{ Object.entries( STATUS_LABELS ).map( ( [ status, label ] ) => (
							<span key={ status } className="str-cal-legend-item">
								<span
									className="str-cal-legend-dot"
									style={ { backgroundColor: STATUS_COLORS[ status ] } }
								/>
								{ label }
							</span>
						) ) }
					</div>
				</>
			) }
		</div>
	);
}
