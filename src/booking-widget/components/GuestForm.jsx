/**
 * GuestForm — guest contact details and pricing summary.
 *
 * @package STRBooking
 */

import { useState } from '@wordpress/element';

const { currency } = window.strBookingData || {};

function formatCurrency( amount ) {
	return new Intl.NumberFormat( 'en-US', {
		style: 'currency',
		currency: ( currency || 'usd' ).toUpperCase(),
	} ).format( amount );
}

function formatDate( dateStr ) {
	if ( ! dateStr ) return '';
	const [ year, month, day ] = dateStr.split( '-' );
	const date = new Date( year, month - 1, day );
	return date.toLocaleDateString( 'en-US', { month: 'long', day: 'numeric', year: 'numeric' } );
}

export default function GuestForm( { pricing, dates, onSubmit, onBack } ) {
	const [ form, setForm ] = useState( {
		guest_name: '',
		guest_email: '',
		guest_phone: '',
		special_requests: '',
	} );
	const [ errors, setErrors ] = useState( {} );

	function handleChange( e ) {
		const { name, value } = e.target;
		setForm( ( prev ) => ( { ...prev, [ name ]: value } ) );
		setErrors( ( prev ) => ( { ...prev, [ name ]: '' } ) );
	}

	function validate() {
		const newErrors = {};
		if ( ! form.guest_name.trim() ) {
			newErrors.guest_name = 'Full name is required.';
		}
		if ( ! form.guest_email.trim() || ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( form.guest_email ) ) {
			newErrors.guest_email = 'A valid email address is required.';
		}
		return newErrors;
	}

	function handleSubmit( e ) {
		e.preventDefault();
		const newErrors = validate();
		if ( Object.keys( newErrors ).length > 0 ) {
			setErrors( newErrors );
			return;
		}
		onSubmit( form );
	}

	return (
		<div className="str-guest-form">
			<div className="str-pricing-summary">
				<h3>Booking Summary</h3>
				<div className="str-summary-row">
					<span>Dates</span>
					<span>{ formatDate( dates.checkIn ) } → { formatDate( dates.checkOut ) }</span>
				</div>
				<div className="str-summary-row">
					<span>
						{ formatCurrency( pricing?.nightly_rate ) } × { pricing?.nights }{ ' ' }
						{ pricing?.nights === 1 ? 'night' : 'nights' }
					</span>
					<span>{ formatCurrency( pricing?.nightly_subtotal ) }</span>
				</div>
				{ pricing?.los_discount > 0 && (
					<div className="str-summary-row str-discount">
						<span>Length-of-stay discount</span>
						<span>-{ formatCurrency( pricing.los_discount ) }</span>
					</div>
				) }
				<div className="str-summary-row">
					<span>Cleaning fee</span>
					<span>{ formatCurrency( pricing?.cleaning_fee ) }</span>
				</div>
				{ pricing?.security_deposit > 0 && (
					<div className="str-summary-row">
						<span>Security deposit</span>
						<span>{ formatCurrency( pricing.security_deposit ) }</span>
					</div>
				) }
				<div className="str-summary-row">
					<span>Taxes</span>
					<span>{ formatCurrency( pricing?.taxes ) }</span>
				</div>
				<div className="str-summary-row str-total">
					<span>Total</span>
					<span>{ formatCurrency( pricing?.total ) }</span>
				</div>
			</div>

			<form onSubmit={ handleSubmit } noValidate>
				<h3>Your Details</h3>

				<div className="str-field">
					<label htmlFor="guest_name">Full Name *</label>
					<input
						type="text"
						id="guest_name"
						name="guest_name"
						value={ form.guest_name }
						onChange={ handleChange }
						autoComplete="name"
						required
					/>
					{ errors.guest_name && (
						<span className="str-field-error">{ errors.guest_name }</span>
					) }
				</div>

				<div className="str-field">
					<label htmlFor="guest_email">Email Address *</label>
					<input
						type="email"
						id="guest_email"
						name="guest_email"
						value={ form.guest_email }
						onChange={ handleChange }
						autoComplete="email"
						required
					/>
					{ errors.guest_email && (
						<span className="str-field-error">{ errors.guest_email }</span>
					) }
				</div>

				<div className="str-field">
					<label htmlFor="guest_phone">Phone Number</label>
					<input
						type="tel"
						id="guest_phone"
						name="guest_phone"
						value={ form.guest_phone }
						onChange={ handleChange }
						autoComplete="tel"
					/>
				</div>

				<div className="str-field">
					<label htmlFor="special_requests">Special Requests</label>
					<textarea
						id="special_requests"
						name="special_requests"
						value={ form.special_requests }
						onChange={ handleChange }
						rows={ 3 }
						placeholder="Allergies, accessibility needs, late arrival, etc."
					/>
				</div>

				<div className="str-btn-row">
					<button type="button" className="str-btn str-btn-secondary" onClick={ onBack }>
						← Back
					</button>
					<button type="submit" className="str-btn str-btn-primary">
						Continue to Payment →
					</button>
				</div>
			</form>
		</div>
	);
}
