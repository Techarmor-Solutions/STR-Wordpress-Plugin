/**
 * MetricsPanel — admin dashboard with booking metrics.
 *
 * @package STRBooking
 */

import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import BookingCalendar from './BookingCalendar';
import PropertyList from './PropertyList';

const { apiUrl, nonce, currency, adminUrl } = window.strAdminData || {};

if ( nonce ) {
	apiFetch.use( apiFetch.createNonceMiddleware( nonce ) );
}

function formatCurrency( amount ) {
	return new Intl.NumberFormat( 'en-US', {
		style: 'currency',
		currency: ( currency || 'usd' ).toUpperCase(),
	} ).format( amount );
}

export default function MetricsPanel() {
	const [ metrics, setMetrics ] = useState( null );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		loadMetrics();
	}, [] );

	async function loadMetrics() {
		try {
			const data = await apiFetch( {
				url: `${ apiUrl }/admin/metrics`,
				method: 'GET',
			} );
			setMetrics( data );
		} catch ( err ) {
			setError( 'Failed to load metrics.' );
		} finally {
			setIsLoading( false );
		}
	}

	if ( isLoading ) {
		return <div className="str-admin-loading">Loading dashboard...</div>;
	}

	if ( error ) {
		return <div className="str-admin-error">{ error }</div>;
	}

	return (
		<div className="str-admin-dashboard">
			<div className="str-metrics-grid">
				<div className="str-metric-card">
					<div className="str-metric-value">{ metrics?.confirmed_bookings ?? 0 }</div>
					<div className="str-metric-label">Confirmed Bookings</div>
				</div>
				<div className="str-metric-card">
					<div className="str-metric-value">{ metrics?.pending_bookings ?? 0 }</div>
					<div className="str-metric-label">Pending Bookings</div>
				</div>
				<div className="str-metric-card">
					<div className="str-metric-value">
						{ formatCurrency( metrics?.total_revenue ?? 0 ) }
					</div>
					<div className="str-metric-label">Total Revenue</div>
				</div>
			</div>

			<div className="str-admin-actions">
				<a
					href={ `${ adminUrl }edit.php?post_type=str_property` }
					className="str-admin-btn"
				>
					Manage Properties
				</a>
				<a
					href={ `${ adminUrl }edit.php?post_type=str_booking` }
					className="str-admin-btn"
				>
					View All Bookings
				</a>
				<a
					href={ `${ adminUrl }admin.php?page=str-booking-settings` }
					className="str-admin-btn"
				>
					Settings
				</a>
			</div>

			<PropertyList />
		</div>
	);
}
