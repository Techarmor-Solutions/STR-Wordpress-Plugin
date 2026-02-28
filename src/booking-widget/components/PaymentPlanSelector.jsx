/**
 * PaymentPlanSelector — lets guests choose between pay-in-full, 2-payment, or 4-payment plans.
 *
 * @package STRBooking
 */

import { useState, useMemo } from '@wordpress/element';

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
	const date = new Date( Number( year ), Number( month ) - 1, Number( day ) );
	return date.toLocaleDateString( 'en-US', { month: 'short', day: 'numeric', year: 'numeric' } );
}

/**
 * Add months to a Y-m-d string and return a new Y-m-d string.
 */
function addMonths( dateStr, months ) {
	const [ year, month, day ] = dateStr.split( '-' ).map( Number );
	const d = new Date( year, month - 1 + months, day );
	const y = d.getFullYear();
	const m = String( d.getMonth() + 1 ).padStart( 2, '0' );
	const dd = String( d.getDate() ).padStart( 2, '0' );
	return `${ y }-${ m }-${ dd }`;
}

/**
 * Subtract days from a Y-m-d string and return a new Y-m-d string.
 */
function subtractDays( dateStr, days ) {
	const [ year, month, day ] = dateStr.split( '-' ).map( Number );
	const d = new Date( year, month - 1, day - days );
	const y = d.getFullYear();
	const m = String( d.getMonth() + 1 ).padStart( 2, '0' );
	const dd = String( d.getDate() ).padStart( 2, '0' );
	return `${ y }-${ m }-${ dd }`;
}

export default function PaymentPlanSelector( {
	propertyId,
	pricing,
	checkin,
	eligiblePlans,
	planConfig,
	onSelect,
	onBack,
} ) {
	const total = pricing?.total || 0;

	const twoDepositPct  = planConfig?.two_deposit_pct || 50;
	const twoDaysBefore  = planConfig?.two_days_before || 42;
	const fourMinPct     = planConfig?.four_deposit_min_pct || 25;

	const twoDepositAmt = Math.round( ( total * twoDepositPct ) / 100 * 100 ) / 100;
	const twoRemainder  = Math.round( ( total - twoDepositAmt ) * 100 ) / 100;
	const twoDueDate    = subtractDays( checkin, twoDaysBefore );

	const fourMinDeposit = Math.round( ( total * fourMinPct ) / 100 * 100 ) / 100;

	const [ selectedPlan, setSelectedPlan ] = useState(
		eligiblePlans.includes( 'pay_in_full' ) ? 'pay_in_full' : ( eligiblePlans[ 0 ] || 'pay_in_full' )
	);
	const [ fourDeposit, setFourDeposit ] = useState( fourMinDeposit );

	const fourInstallments = useMemo( () => {
		const deposit = Math.max( fourDeposit, fourMinDeposit );
		const remainder = Math.round( ( total - deposit ) * 100 ) / 100;
		const base = Math.round( ( remainder / 3 ) * 100 ) / 100;
		const last = Math.round( ( remainder - base * 2 ) * 100 ) / 100;

		return [
			{ number: 2, amount: base, due_date: addMonths( checkin, -3 ) },
			{ number: 3, amount: base, due_date: addMonths( checkin, -2 ) },
			{ number: 4, amount: last, due_date: addMonths( checkin, -1 ) },
		];
	}, [ fourDeposit, total, checkin, fourMinDeposit ] );

	function handleContinue() {
		let depositAmount = total;
		let schedule      = null;

		if ( 'two_payment' === selectedPlan ) {
			depositAmount = twoDepositAmt;
			schedule = [
				{ number: 1, amount: twoDepositAmt, due_date: 'today', status: 'pending' },
				{ number: 2, amount: twoRemainder, due_date: twoDueDate, status: 'pending' },
			];
		} else if ( 'four_payment' === selectedPlan ) {
			const deposit = Math.max( fourDeposit, fourMinDeposit );
			depositAmount = deposit;
			schedule = [
				{ number: 1, amount: deposit, due_date: 'today', status: 'pending' },
				...fourInstallments,
			];
		}

		onSelect( { plan: selectedPlan, depositAmount, schedule } );
	}

	return (
		<div className="str-plan-selector">
			<h3>Choose Your Payment Plan</h3>
			<p style={ { color: '#666', marginTop: 0 } }>
				Total booking cost: <strong>{ formatCurrency( total ) }</strong>
			</p>

			<div className="str-plan-options">
				{ eligiblePlans.includes( 'pay_in_full' ) && (
					<label
						className={ `str-plan-option ${ selectedPlan === 'pay_in_full' ? 'is-selected' : '' }` }
						style={ planOptionStyle( selectedPlan === 'pay_in_full' ) }
					>
						<input
							type="radio"
							name="payment_plan"
							value="pay_in_full"
							checked={ selectedPlan === 'pay_in_full' }
							onChange={ () => setSelectedPlan( 'pay_in_full' ) }
							style={ { marginRight: '10px' } }
						/>
						<span>
							<strong>Pay in Full</strong>
							<span style={ { display: 'block', color: '#444', fontSize: '13px', marginTop: '2px' } }>
								{ formatCurrency( total ) } due today
							</span>
						</span>
					</label>
				) }

				{ eligiblePlans.includes( 'two_payment' ) && (
					<label
						className={ `str-plan-option ${ selectedPlan === 'two_payment' ? 'is-selected' : '' }` }
						style={ planOptionStyle( selectedPlan === 'two_payment' ) }
					>
						<input
							type="radio"
							name="payment_plan"
							value="two_payment"
							checked={ selectedPlan === 'two_payment' }
							onChange={ () => setSelectedPlan( 'two_payment' ) }
							style={ { marginRight: '10px' } }
						/>
						<span>
							<strong>2 Payments</strong>
							<span style={ { display: 'block', color: '#444', fontSize: '13px', marginTop: '2px' } }>
								{ formatCurrency( twoDepositAmt ) } today,{ ' ' }
								then { formatCurrency( twoRemainder ) } on { formatDate( twoDueDate ) }
							</span>
						</span>
					</label>
				) }

				{ eligiblePlans.includes( 'four_payment' ) && (
					<label
						className={ `str-plan-option ${ selectedPlan === 'four_payment' ? 'is-selected' : '' }` }
						style={ planOptionStyle( selectedPlan === 'four_payment' ) }
					>
						<input
							type="radio"
							name="payment_plan"
							value="four_payment"
							checked={ selectedPlan === 'four_payment' }
							onChange={ () => setSelectedPlan( 'four_payment' ) }
							style={ { marginRight: '10px' } }
						/>
						<span>
							<strong>4 Payments</strong>
							<span style={ { display: 'block', color: '#444', fontSize: '13px', marginTop: '2px' } }>
								Choose deposit + 3 equal installments
							</span>
						</span>
					</label>
				) }
			</div>

			{ selectedPlan === 'four_payment' && (
				<div className="str-four-payment-config" style={ { marginTop: '20px', padding: '16px', background: '#f7f7f7', borderRadius: '6px' } }>
					<label style={ { display: 'block', marginBottom: '8px', fontWeight: 600 } }>
						Initial deposit amount (minimum { formatCurrency( fourMinDeposit ) })
					</label>
					<div style={ { display: 'flex', alignItems: 'center', gap: '12px', flexWrap: 'wrap' } }>
						<span>$</span>
						<input
							type="number"
							min={ fourMinDeposit }
							max={ total }
							step="0.01"
							value={ fourDeposit }
							onChange={ ( e ) => {
								const val = parseFloat( e.target.value ) || fourMinDeposit;
								setFourDeposit( Math.min( Math.max( val, fourMinDeposit ), total ) );
							} }
							style={ { width: '120px', padding: '6px 8px', border: '1px solid #ccc', borderRadius: '4px' } }
						/>
					</div>

					<div style={ { marginTop: '16px' } }>
						<p style={ { margin: '0 0 8px', fontWeight: 600, fontSize: '13px' } }>Payment schedule:</p>
						<div style={ { display: 'grid', gap: '6px' } }>
							<div style={ installmentRowStyle }>
								<span>Payment 1 (today)</span>
								<strong>{ formatCurrency( Math.max( fourDeposit, fourMinDeposit ) ) }</strong>
							</div>
							{ fourInstallments.map( ( inst ) => (
								<div key={ inst.number } style={ installmentRowStyle }>
									<span>Payment { inst.number } — { formatDate( inst.due_date ) }</span>
									<strong>{ formatCurrency( inst.amount ) }</strong>
								</div>
							) ) }
						</div>
					</div>
				</div>
			) }

			{ selectedPlan === 'two_payment' && (
				<div className="str-two-payment-summary" style={ { marginTop: '16px', padding: '14px', background: '#f7f7f7', borderRadius: '6px' } }>
					<p style={ { margin: '0 0 8px', fontWeight: 600, fontSize: '13px' } }>Payment schedule:</p>
					<div style={ { display: 'grid', gap: '6px' } }>
						<div style={ installmentRowStyle }>
							<span>Payment 1 (today)</span>
							<strong>{ formatCurrency( twoDepositAmt ) }</strong>
						</div>
						<div style={ installmentRowStyle }>
							<span>Payment 2 — { formatDate( twoDueDate ) }</span>
							<strong>{ formatCurrency( twoRemainder ) }</strong>
						</div>
					</div>
				</div>
			) }

			<div className="str-btn-row" style={ { marginTop: '24px' } }>
				<button type="button" className="str-btn str-btn-secondary" onClick={ onBack }>
					← Back
				</button>
				<button type="button" className="str-btn str-btn-primary" onClick={ handleContinue }>
					Continue to Payment →
				</button>
			</div>
		</div>
	);
}

function planOptionStyle( isSelected ) {
	return {
		display: 'flex',
		alignItems: 'flex-start',
		padding: '14px 16px',
		marginBottom: '10px',
		border: `2px solid ${ isSelected ? '#1a1a2e' : '#ddd' }`,
		borderRadius: '6px',
		cursor: 'pointer',
		background: isSelected ? '#f0f0f8' : '#fff',
		transition: 'border-color 0.15s, background 0.15s',
	};
}

const installmentRowStyle = {
	display: 'flex',
	justifyContent: 'space-between',
	fontSize: '13px',
	padding: '4px 0',
	borderBottom: '1px solid #eee',
};
