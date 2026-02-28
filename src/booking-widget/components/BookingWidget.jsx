/**
 * BookingWidget — multi-step booking flow orchestrator.
 *
 * @package STRBooking
 */

import { useState } from '@wordpress/element';
import { loadStripe } from '@stripe/stripe-js';
import { Elements } from '@stripe/react-stripe-js';
import DatePicker from './DatePicker';
import GuestForm from './GuestForm';
import PaymentPlanSelector from './PaymentPlanSelector';
import PaymentForm from './PaymentForm';
import Confirmation from './Confirmation';

const STEPS = {
	DATES: 'DATES',
	GUEST_DETAILS: 'GUEST_DETAILS',
	PLAN_SELECTION: 'PLAN_SELECTION',
	PAYMENT: 'PAYMENT',
	CONFIRMATION: 'CONFIRMATION',
};

const stripePromise = loadStripe(
	window.strBookingData?.stripePublishableKey || ''
);

export default function BookingWidget( { propertyId } ) {
	const [ step, setStep ] = useState( STEPS.DATES );
	const [ dates, setDates ] = useState( { checkIn: null, checkOut: null } );
	const [ pricing, setPricing ] = useState( null );
	const [ guestDetails, setGuestDetails ] = useState( {} );
	const [ paymentPlan, setPaymentPlan ] = useState( null );
	const [ bookingResult, setBookingResult ] = useState( null );
	const [ error, setError ] = useState( null );

	// Plan config is localized via strBookingProperty (set per-shortcode)
	const planConfig = window.strBookingProperty?.planConfig || {};

	// Compute eligible plans based on selected check-in date and per-property plan config
	function getEligiblePlans( checkin ) {
		const plans = [];

		// Pay-in-full: enabled by default unless explicitly disabled
		if ( planConfig.full_enabled !== false ) {
			plans.push( 'pay_in_full' );
		}

		if ( ! checkin ) return plans;

		const checkinTs  = new Date( checkin + 'T00:00:00' ).getTime();
		const nowTs      = Date.now();
		const daysUntil  = Math.floor( ( checkinTs - nowTs ) / 86400000 );

		// 2-payment: show only if check-in is more than two_days_before away
		if ( planConfig.two_enabled && daysUntil > ( planConfig.two_days_before || 42 ) ) {
			plans.push( 'two_payment' );
		}

		// 4-payment: show only if check-in is more than 90 days away
		if ( planConfig.four_enabled && daysUntil > 90 ) {
			plans.push( 'four_payment' );
		}

		return plans;
	}

	const eligiblePlans = getEligiblePlans( dates.checkIn );

	// Skip plan selection step if only pay_in_full is available
	const hasPlanChoice = eligiblePlans.length > 1;

	function handleDatesSelected( checkIn, checkOut, pricingData ) {
		setDates( { checkIn, checkOut } );
		setPricing( pricingData );
		setStep( STEPS.GUEST_DETAILS );
		setError( null );
	}

	function handleGuestDetailsSubmit( details ) {
		setGuestDetails( details );
		setError( null );
		if ( hasPlanChoice ) {
			setStep( STEPS.PLAN_SELECTION );
		} else {
			setPaymentPlan( { plan: 'pay_in_full', depositAmount: pricing?.total, schedule: null } );
			setStep( STEPS.PAYMENT );
		}
	}

	function handlePlanSelected( planData ) {
		setPaymentPlan( planData );
		setStep( STEPS.PAYMENT );
		setError( null );
	}

	function handlePaymentSuccess( result ) {
		setBookingResult( result );
		setStep( STEPS.CONFIRMATION );
	}

	function handleBack() {
		setError( null );
		if ( step === STEPS.GUEST_DETAILS ) {
			setStep( STEPS.DATES );
		} else if ( step === STEPS.PLAN_SELECTION ) {
			setStep( STEPS.GUEST_DETAILS );
		} else if ( step === STEPS.PAYMENT ) {
			setStep( hasPlanChoice ? STEPS.PLAN_SELECTION : STEPS.GUEST_DETAILS );
		}
	}

	const stepLabels = {
		[ STEPS.DATES ]: '1. Select Dates',
		[ STEPS.GUEST_DETAILS ]: '2. Your Details',
		[ STEPS.PLAN_SELECTION ]: '3. Payment Plan',
		[ STEPS.PAYMENT ]: hasPlanChoice ? '4. Payment' : '3. Payment',
		[ STEPS.CONFIRMATION ]: 'Confirmed!',
	};

	// Only show steps that are relevant in the nav
	const visibleSteps = hasPlanChoice
		? Object.values( STEPS )
		: Object.values( STEPS ).filter( ( s ) => s !== STEPS.PLAN_SELECTION );

	return (
		<div className="str-booking-widget">
			{ step !== STEPS.CONFIRMATION && (
				<nav className="str-steps">
					{ visibleSteps.map( ( s ) => (
						<span
							key={ s }
							className={ `str-step ${ step === s ? 'is-active' : '' } ${ isStepComplete( s, step, visibleSteps ) ? 'is-complete' : '' }` }
						>
							{ stepLabels[ s ] }
						</span>
					) ) }
				</nav>
			) }

			{ error && (
				<div className="str-error-message" role="alert">
					{ error }
				</div>
			) }

			{ step === STEPS.DATES && (
				<DatePicker
					propertyId={ propertyId }
					onDatesSelected={ handleDatesSelected }
					onError={ setError }
				/>
			) }

			{ step === STEPS.GUEST_DETAILS && (
				<GuestForm
					pricing={ pricing }
					dates={ dates }
					onSubmit={ handleGuestDetailsSubmit }
					onBack={ handleBack }
				/>
			) }

			{ step === STEPS.PLAN_SELECTION && (
				<PaymentPlanSelector
					propertyId={ propertyId }
					pricing={ pricing }
					checkin={ dates.checkIn }
					eligiblePlans={ eligiblePlans }
					planConfig={ planConfig }
					onSelect={ handlePlanSelected }
					onBack={ handleBack }
				/>
			) }

			{ step === STEPS.PAYMENT && (
				<Elements stripe={ stripePromise }>
					<PaymentForm
						propertyId={ propertyId }
						dates={ dates }
						pricing={ pricing }
						guestDetails={ guestDetails }
						paymentPlan={ paymentPlan }
						onSuccess={ handlePaymentSuccess }
						onBack={ handleBack }
						onError={ setError }
					/>
				</Elements>
			) }

			{ step === STEPS.CONFIRMATION && (
				<Confirmation
					bookingResult={ bookingResult }
					guestDetails={ guestDetails }
					dates={ dates }
					pricing={ pricing }
				/>
			) }
		</div>
	);
}

function isStepComplete( step, currentStep, visibleSteps ) {
	return visibleSteps.indexOf( step ) < visibleSteps.indexOf( currentStep );
}
