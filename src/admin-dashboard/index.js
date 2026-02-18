/**
 * Admin Dashboard entry point.
 *
 * @package STRBooking
 */

import { render } from '@wordpress/element';
import AdminDashboard from './components/MetricsPanel';

const container = document.getElementById( 'str-admin-dashboard' );

if ( container ) {
	render( <AdminDashboard />, container );
}
