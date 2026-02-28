/**
 * PaymentForm — Stripe Elements or Square Web Payments SDK payment step.
 *
 * @package STRBooking
 */

import { useState, useEffect, useRef } from '@wordpress/element';
import { Elements, PaymentElement, useStripe, useElements } from '@stripe/react-stripe-js';
import { loadStripe } from '@stripe/stripe-js';
import apiFetch from '@wordpress/api-fetch';

const { apiUrl } = window.strBookingData || {};

// Stripe promise — created once at module level so loadStripe is never called twice.
const stripePromise = window.strBookingData?.stripePublishableKey
	? loadStripe( window.strBookingData.stripePublishableKey )
	: null;

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

// ── Square Payment Form ────────────────────────────────────────────────────────

function SquarePaymentForm( {
	propertyId,
	dates,
	pricing,
	guestDetails,
	paymentPlan,
	onSuccess,
	onBack,
	onError,
} ) {
	const [ isInitializing, setIsInitializing ] = useState( true );
	const [ isProcessing, setIsProcessing ] = useState( false );
	const cardRef = useRef( null );
	const squareCardRef = useRef( null );

	const { currency, squareAppId, squareLocationId } = window.strBookingData || {};
	const plan = paymentPlan?.plan || 'pay_in_full';

	useEffect( () => {
		let mounted = true;

		async function initSquare() {
			if ( ! window.Square ) {
				onError( 'Square payment SDK failed to load. Please refresh and try again.' );
				setIsInitializing( false );
				return;
			}

			try {
				const payments = window.Square.payments( squareAppId, squareLocationId );
				const card = await payments.card();
				squareCardRef.current = card;

				if ( mounted && cardRef.current ) {
					await card.attach( cardRef.current );
				}
			} catch ( err ) {
				if ( mounted ) {
					onError( err?.message || 'Could not initialize Square payment form.' );
				}
			} finally {
				if ( mounted ) setIsInitializing( false );
			}
		}

		initSquare();

		return () => {
			mounted = false;
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	async function handleSubmit( e ) {
		e.preventDefault();

		if ( ! squareCardRef.current || isProcessing ) return;

		setIsProcessing( true );

		try {
			const tokenResult = await squareCardRef.current.tokenize();

			if ( tokenResult.status !== 'OK' ) {
				const msg = tokenResult.errors?.map( ( err ) => err.message ).join( ' ' ) || 'Card tokenization failed.';
				onError( msg );
				setIsProcessing( false );
				return;
			}

			const result = await apiFetch( {
				url:    `${ apiUrl }/booking`,
				method: 'POST',
				data:   {
					property_id:      propertyId,
					check_in:         dates.checkIn,
					check_out:        dates.checkOut,
					guest_name:       guestDetails.guest_name,
					guest_email:      guestDetails.guest_email,
					guest_phone:      guestDetails.guest_phone || '',
					guest_count:      guestDetails.guest_count || 1,
					special_requests: guestDetails.special_requests || '',
					source_id:        tokenResult.token,
				},
			} );

			onSuccess( {
				bookingId: result.booking_id,
				total:     pricing?.total,
				currency,
				plan:      'pay_in_full',
			} );
		} catch ( err ) {
			onError( err?.message || 'Payment failed. Please try again.' );
			setIsProcessing( false );
		}
	}

	return (
		<div className="str-payment-form">
			<div className="str-payment-summary">
				<h3>Payment</h3>
				<p className="str-total-due">
					Total due today:{ ' ' }
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
				{ isInitializing && (
					<div className="str-payment-loading">
						<p>Loading payment form...</p>
					</div>
				) }

				<div
					id="str-square-card"
					ref={ cardRef }
					style={ { minHeight: isInitializing ? 0 : undefined } }
				/>

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
						disabled={ isInitializing || isProcessing }
					>
						{ isProcessing
							? 'Processing...'
							: `Pay ${ formatCurrency( pricing?.total, currency ) }` }
					</button>
				</div>

				<p className="str-secure-notice">
					🔒 Payment secured by Square. Your card details are never stored on this site.
				</p>
			</form>
		</div>
	);
}

// ── Stripe inner payment form (requires Elements context) ─────────────────────
// Split out so useStripe/useElements hooks are always inside an Elements provider.

function StripePaymentInner( {
	pricing,
	guestDetails,
	bookingId,
	depositAmount,
	plan,
	currency,
	installmentSchedule,
	onSuccess,
	onBack,
	onError,
} ) {
	const stripe   = useStripe();
	const elements = useElements();

	const [ isProcessing, setIsProcessing ]     = useState( false );
	const [ paymentConfirmed, setPaymentConfirmed ] = useState( false );

	async function handleSubmit( e ) {
		e.preventDefault();

		if ( ! stripe || ! elements ) return;

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

	if ( paymentConfirmed && installmentSchedule ) {
		return (
			<InstallmentSummary
				schedule={ installmentSchedule }
				plan={ plan }
				currency={ currency }
			/>
		);
	}

	const isDueToday    = plan !== 'pay_in_full';
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
				<PaymentElement id="str-payment-element" />

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

// ── Stripe outer form — fetches clientSecret then mounts Elements ─────────────

function StripePaymentForm( {
	propertyId,
	dates,
	pricing,
	guestDetails,
	paymentPlan,
	onSuccess,
	onBack,
	onError,
} ) {
	const [ clientSecret, setClientSecret ]           = useState( null );
	const [ bookingId, setBookingId ]                 = useState( null );
	const [ installmentSchedule, setInstallmentSchedule ] = useState( null );
	const [ isCreatingBooking, setIsCreatingBooking ] = useState( true ); // true = show loading on first render

	const { currency } = window.strBookingData || {};

	const plan          = paymentPlan?.plan || 'pay_in_full';
	const depositAmount = paymentPlan?.depositAmount || pricing?.total || 0;

	// Create booking + obtain clientSecret once on mount
	useEffect( () => {
		createBookingIntent();
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	async function createBookingIntent() {
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
		} catch ( err ) {
			onError(
				err?.message ||
					'Could not initialize payment. Please go back and try again.'
			);
		} finally {
			setIsCreatingBooking( false );
		}
	}

	if ( isCreatingBooking ) {
		return (
			<div className="str-payment-loading">
				<p>Preparing your booking...</p>
			</div>
		);
	}

	// Booking creation failed — show a back button so the user isn't stuck
	if ( ! clientSecret ) {
		return (
			<div className="str-btn-row">
				<button
					type="button"
					className="str-btn str-btn-secondary"
					onClick={ onBack }
				>
					← Back
				</button>
			</div>
		);
	}

	const appearance = {
		theme: 'stripe',
		variables: {
			colorPrimary: '#1a1a2e',
			borderRadius: '6px',
		},
	};

	return (
		<Elements stripe={ stripePromise } options={ { clientSecret, appearance } }>
			<StripePaymentInner
				pricing={ pricing }
				guestDetails={ guestDetails }
				bookingId={ bookingId }
				depositAmount={ depositAmount }
				plan={ plan }
				currency={ currency }
				installmentSchedule={ installmentSchedule }
				onSuccess={ onSuccess }
				onBack={ onBack }
				onError={ onError }
			/>
		</Elements>
	);
}

// ── Default export — switches on activeGateway ────────────────────────────────

export default function PaymentForm( props ) {
	const { activeGateway } = window.strBookingData || {};

	return activeGateway === 'square'
		? <SquarePaymentForm { ...props } />
		: <StripePaymentForm { ...props } />;
}

// ── InstallmentSummary (Stripe-only feature) ──────────────────────────────────

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
