/**
 * PaymentForm — Stripe Elements payment step.
 *
 * @package STRBooking
 */

import { useState } from '@wordpress/element';
import { PaymentElement, useStripe, useElements } from '@stripe/react-stripe-js';
import apiFetch from '@wordpress/api-fetch';

const { apiUrl } = window.strBookingData || {};

function formatCurrency( amount, currency = 'usd' ) {
	return new Intl.NumberFormat( 'en-US', {
		style: 'currency',
		currency: currency.toUpperCase(),
	} ).format( amount );
}

export default function PaymentForm( {
	propertyId,
	dates,
	pricing,
	guestDetails,
	onSuccess,
	onBack,
	onError,
} ) {
	const stripe = useStripe();
	const elements = useElements();

	const [ clientSecret, setClientSecret ] = useState( null );
	const [ bookingId, setBookingId ] = useState( null );
	const [ isCreatingBooking, setIsCreatingBooking ] = useState( false );
	const [ isProcessing, setIsProcessing ] = useState( false );
	const [ bookingCreated, setBookingCreated ] = useState( false );

	const { currency } = window.strBookingData || {};

	// Create booking and get client_secret on mount
	useState( () => {
		createBookingIntent();
	} );

	async function createBookingIntent() {
		setIsCreatingBooking( true );

		try {
			const result = await apiFetch( {
				url: `${ apiUrl }/booking`,
				method: 'POST',
				data: {
					property_id: propertyId,
					check_in: dates.checkIn,
					check_out: dates.checkOut,
					guest_name: guestDetails.guest_name,
					guest_email: guestDetails.guest_email,
					guest_phone: guestDetails.guest_phone || '',
					guest_count: guestDetails.guest_count || 1,
					special_requests: guestDetails.special_requests || '',
				},
			} );

			setClientSecret( result.client_secret );
			setBookingId( result.booking_id );
			setBookingCreated( true );
		} catch ( err ) {
			onError(
				err?.message ||
					'Could not initialize payment. Please go back and try again.'
			);
		} finally {
			setIsCreatingBooking( false );
		}
	}

	async function handleSubmit( e ) {
		e.preventDefault();

		if ( ! stripe || ! elements || ! clientSecret ) {
			return;
		}

		setIsProcessing( true );

		const { error } = await stripe.confirmPayment( {
			elements,
			confirmParams: {
				return_url: window.location.href,
				payment_method_data: {
					billing_details: {
						name: guestDetails.guest_name,
						email: guestDetails.guest_email,
						phone: guestDetails.guest_phone || undefined,
					},
				},
			},
			redirect: 'if_required',
		} );

		if ( error ) {
			onError( error.message );
			setIsProcessing( false );
			return;
		}

		// Payment succeeded
		onSuccess( {
			bookingId,
			total: pricing?.total,
			currency,
		} );
	}

	if ( isCreatingBooking ) {
		return (
			<div className="str-payment-loading">
				<p>Preparing your booking...</p>
			</div>
		);
	}

	if ( ! bookingCreated ) {
		return null;
	}

	const stripeOptions = {
		clientSecret,
		appearance: {
			theme: 'stripe',
			variables: {
				colorPrimary: '#1a1a2e',
				borderRadius: '6px',
			},
		},
	};

	return (
		<div className="str-payment-form">
			<div className="str-payment-summary">
				<h3>Payment</h3>
				<p className="str-total-due">
					Total due:{ ' ' }
					<strong>{ formatCurrency( pricing?.total, currency ) }</strong>
				</p>
				{ pricing?.security_deposit > 0 && (
					<p className="str-deposit-note">
						Includes { formatCurrency( pricing.security_deposit, currency ) } security
						deposit, refundable after check-out.
					</p>
				) }
			</div>

			<form onSubmit={ handleSubmit }>
				{ clientSecret && (
					<PaymentElement
						id="str-payment-element"
						options={ stripeOptions }
					/>
				) }

				<div className="str-btn-row" style={ { marginTop: '24px' } }>
					<button
						type="button"
						className="str-btn str-btn-secondary"
						onClick={ onBack }
						disabled={ isProcessing }
					>
						← Back
					</button>
					<button
						type="submit"
						className="str-btn str-btn-primary"
						disabled={ ! stripe || ! elements || isProcessing }
					>
						{ isProcessing
							? 'Processing...'
							: `Pay ${ formatCurrency( pricing?.total, currency ) }` }
					</button>
				</div>

				<p className="str-secure-notice">
					🔒 Payment secured by Stripe. Your card details are never stored on this site.
				</p>
			</form>
		</div>
	);
}
