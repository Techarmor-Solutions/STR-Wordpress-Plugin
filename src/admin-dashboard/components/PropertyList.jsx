/**
 * PropertyList — admin list of properties with booking counts.
 *
 * @package STRBooking
 */

import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import BookingCalendar from './BookingCalendar';

const { adminUrl } = window.strAdminData || {};

export default function PropertyList() {
	const [ properties, setProperties ] = useState( [] );
	const [ selectedProperty, setSelectedProperty ] = useState( null );
	const [ isLoading, setIsLoading ] = useState( true );

	useEffect( () => {
		loadProperties();
	}, [] );

	async function loadProperties() {
		try {
			const data = await apiFetch( {
				path: '/wp/v2/str_property?per_page=100&status=publish',
				method: 'GET',
			} );
			setProperties( Array.isArray( data ) ? data : [] );
			if ( data?.length > 0 ) {
				setSelectedProperty( data[ 0 ].id );
			}
		} catch ( err ) {
			// Silently fail
		} finally {
			setIsLoading( false );
		}
	}

	if ( isLoading ) {
		return <p>Loading properties...</p>;
	}

	if ( properties.length === 0 ) {
		return (
			<div className="str-no-properties">
				<p>No properties found.</p>
				<a href={ `${ adminUrl }post-new.php?post_type=str_property` } className="str-admin-btn">
					Add Your First Property
				</a>
			</div>
		);
	}

	return (
		<div className="str-property-section">
			<h3>Properties</h3>

			<div className="str-property-tabs">
				{ properties.map( ( property ) => (
					<button
						key={ property.id }
						className={ `str-property-tab ${ selectedProperty === property.id ? 'is-active' : '' }` }
						onClick={ () => setSelectedProperty( property.id ) }
					>
						{ property.title?.rendered || `Property #${ property.id }` }
					</button>
				) ) }
			</div>

			{ selectedProperty && (
				<div className="str-property-detail">
					<div className="str-property-actions">
						<a
							href={ `${ adminUrl }post.php?post=${ selectedProperty }&action=edit` }
							className="str-admin-btn str-admin-btn-sm"
						>
							Edit Property
						</a>
					</div>
					<BookingCalendar propertyId={ selectedProperty } />
				</div>
			) }
		</div>
	);
}
