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

function formatDate( dateStr ) {
	if ( ! dateStr || dateStr === 'today' ) return 'today';
	const [ year, month, day ] = dateStr.split( '-' ).map( Number );
	const d = new Date( year, month - 1, day );
	return d.toLocaleDateString( 'en-US', { month: 'short', day: 'numeric', year: 'numeric' } );
}

const PLAN_LABELS = {
	pay_in_full:  'Pay in Full',
	two_payment:  '2-Payment Plan',
	four_payment: '4-Payment Plan',
};

export default function PaymentForm( {
	propertyId,
	dates,
	pricing,
	guestDetails,
	paymentPlan,
	onSuccess,
	onBack,
	onError,
} ) {
	const stripe = useStripe();
	const elements = useElements();

	const [ clientSecret, setClientSecret ] = useState( null );
	const [ bookingId, setBookingId ] = useState( null );
	const [ installmentSchedule, setInstallmentSchedule ] = useState( null );
	const [ isCreatingBooking, setIsCreatingBooking ] = useState( false );
	const [ isProcessing, setIsProcessing ] = useState( false );
	const [ bookingCreated, setBookingCreated ] = useState( false );
	const [ paymentConfirmed, setPaymentConfirmed ] = useState( false );

	const { currency } = window.strBookingData || {};

	const plan         = paymentPlan?.plan || 'pay_in_full';
	const depositAmount = paymentPlan?.depositAmount || pricing?.total || 0;

	// Create booking and get client_secret on mount
	useState( () => {
		createBookingIntent();
	} );

	async function createBookingIntent() {
		setIsCreatingBooking( true );

		try {
			const body = {
				property_id:      propertyId,
				check_in:         dates.checkIn,
				check_out:        dates.checkOut,
				guest_name:       guestDetails.guest_name,
				guest_email:      guestDetails.guest_email,
				guest_phone:      guestDetails.guest_phone || '',
				guest_count:      guestDetails.guest_count || 1,
				special_requests: guestDetails.special_requests || '',
				payment_plan:     plan,
			};

			if ( 'four_payment' === plan ) {
				body.deposit_amount = depositAmount;
			}

			const result = await apiFetch( {
				url:    `${ apiUrl }/booking`,
				method: 'POST',
				data:   body,
			} );

			setClientSecret( result.client_secret );
			setBookingId( result.booking_id );

			if ( result.installment_schedule ) {
				setInstallmentSchedule( result.installment_schedule );
			}

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
						name:  guestDetails.guest_name,
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

		setPaymentConfirmed( true );
		setIsProcessing( false );

		onSuccess( {
			bookingId,
			total:    pricing?.total,
			currency,
			plan,
			depositAmount,
			installmentSchedule,
		} );
	}

	// Show installment summary after payment in the success state
	if ( paymentConfirmed && installmentSchedule ) {
		return (
			<InstallmentSummary
				schedule={ installmentSchedule }
				plan={ plan }
				currency={ currency }
			/>
		);
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

	const isDueToday = plan !== 'pay_in_full';
	const displayAmount = isDueToday ? depositAmount : pricing?.total;

	return (
		<div className="str-payment-form">
			<div className="str-payment-summary">
				<h3>Payment</h3>

				{ plan === 'pay_in_full' ? (
					<p className="str-total-due">
						Total due today:{ ' ' }
						<strong>{ formatCurrency( pricing?.total, currency ) }</strong>
					</p>
				) : (
					<>
						<p className="str-total-due">
							{ PLAN_LABELS[ plan ] } — Due today:{ ' ' }
							<strong>{ formatCurrency( depositAmount, currency ) }</strong>
						</p>
						<p style={ { color: '#666', fontSize: '13px', margin: '4px 0 0' } }>
							Booking total: { formatCurrency( pricing?.total, currency ) }
							{ ' ' }· Remaining{ ' ' }
							{ formatCurrency( ( pricing?.total || 0 ) - depositAmount, currency ) }{ ' ' }
							charged automatically per your plan
						</p>
					</>
				) }

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
							: `Pay ${ formatCurrency( displayAmount, currency ) }` }
					</button>
				</div>

				<p className="str-secure-notice">
					🔒 Payment secured by Stripe. Your card details are never stored on this site.
				</p>
			</form>
		</div>
	);
}

function InstallmentSummary( { schedule, plan, currency } ) {
	if ( ! schedule || ! schedule.length ) return null;

	return (
		<div className="str-installment-summary" style={ { padding: '16px', background: '#f7f7f7', borderRadius: '6px' } }>
			<h4 style={ { marginTop: 0 } }>{ PLAN_LABELS[ plan ] } — Your Payment Schedule</h4>
			<div style={ { display: 'grid', gap: '6px' } }>
				{ schedule.map( ( inst ) => (
					<div
						key={ inst.number }
						style={ {
							display:        'flex',
							justifyContent: 'space-between',
							fontSize:       '13px',
							padding:        '6px 0',
							borderBottom:   '1px solid #eee',
						} }
					>
						<span>
							Payment { inst.number }{ ' ' }
							{ inst.due_date === 'today' || inst.due_date === new Date().toISOString().slice( 0, 10 )
								? '(today)'
								: `— ${ formatDate( inst.due_date ) }` }
						</span>
						<strong>{ formatCurrency( inst.amount, currency ) }</strong>
					</div>
				) ) }
			</div>
			<p style={ { fontSize: '12px', color: '#666', marginBottom: 0 } }>
				Future payments will be automatically charged to your card on file.
			</p>
		</div>
	);
}
