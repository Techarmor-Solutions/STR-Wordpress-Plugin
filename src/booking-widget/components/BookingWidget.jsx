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
import PaymentForm from './PaymentForm';
import Confirmation from './Confirmation';

const STEPS = {
	DATES: 'DATES',
	GUEST_DETAILS: 'GUEST_DETAILS',
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
	const [ bookingResult, setBookingResult ] = useState( null );
	const [ error, setError ] = useState( null );

	function handleDatesSelected( checkIn, checkOut, pricingData ) {
		setDates( { checkIn, checkOut } );
		setPricing( pricingData );
		setStep( STEPS.GUEST_DETAILS );
		setError( null );
	}

	function handleGuestDetailsSubmit( details ) {
		setGuestDetails( details );
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
		} else if ( step === STEPS.PAYMENT ) {
			setStep( STEPS.GUEST_DETAILS );
		}
	}

	const stepLabels = {
		[ STEPS.DATES ]: '1. Select Dates',
		[ STEPS.GUEST_DETAILS ]: '2. Your Details',
		[ STEPS.PAYMENT ]: '3. Payment',
		[ STEPS.CONFIRMATION ]: 'Confirmed!',
	};

	return (
		<div className="str-booking-widget">
			{ step !== STEPS.CONFIRMATION && (
				<nav className="str-steps">
					{ Object.values( STEPS ).map( ( s ) => (
						<span
							key={ s }
							className={ `str-step ${ step === s ? 'is-active' : '' } ${ isStepComplete( s, step ) ? 'is-complete' : '' }` }
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

			{ step === STEPS.PAYMENT && (
				<Elements stripe={ stripePromise }>
					<PaymentForm
						propertyId={ propertyId }
						dates={ dates }
						pricing={ pricing }
						guestDetails={ guestDetails }
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

function isStepComplete( step, currentStep ) {
	const order = Object.values( STEPS );
	return order.indexOf( step ) < order.indexOf( currentStep );
}
