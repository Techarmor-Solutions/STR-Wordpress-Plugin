<?php
/**
 * Pricing Calendar — month-view pricing and availability manager.
 *
 * Inspired by the Airbnb host calendar: each day shows its effective price,
 * booking info when reserved, and a click-in side panel to override pricing
 * or toggle availability.
 *
 * @package STRBooking\Admin
 */

namespace STRBooking\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Pricing Calendar admin page and registers its REST-connected scripts.
 */
class PricingCalendar {

	public function __construct() {
		// Nothing to wire at construction time; render() is called on demand.
	}

	/**
	 * Render the full pricing calendar page.
	 */
	public function render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$property_id = isset( $_GET['property_id'] ) ? absint( $_GET['property_id'] ) : 0;

		// Build property list for selector.
		$properties = get_posts( array(
			'post_type'      => 'str_property',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		) );

		if ( ! $property_id && ! empty( $properties ) ) {
			$property_id = $properties[0]->ID;
		}

		$property = $property_id ? get_post( $property_id ) : null;

		// Current month/year from query string or today.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$year  = isset( $_GET['cal_year'] )  ? (int) $_GET['cal_year']  : (int) date( 'Y' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$month = isset( $_GET['cal_month'] ) ? (int) $_GET['cal_month'] : (int) date( 'n' );

		// Clamp.
		if ( $month < 1 || $month > 12 ) {
			$month = (int) date( 'n' );
		}

		// Prev / next month links.
		$prev_ts    = mktime( 0, 0, 0, $month - 1, 1, $year );
		$next_ts    = mktime( 0, 0, 0, $month + 1, 1, $year );
		$prev_year  = (int) date( 'Y', $prev_ts );
		$prev_month = (int) date( 'n', $prev_ts );
		$next_year  = (int) date( 'Y', $next_ts );
		$next_month = (int) date( 'n', $next_ts );

		$base_url = admin_url( 'admin.php?page=str-pricing-calendar' );

		$prev_url = add_query_arg( array( 'property_id' => $property_id, 'cal_year' => $prev_year,  'cal_month' => $prev_month ), $base_url );
		$next_url = add_query_arg( array( 'property_id' => $property_id, 'cal_year' => $next_year,  'cal_month' => $next_month ), $base_url );

		// Property-level price settings.
		$base_rate     = $property_id ? (float) get_post_meta( $property_id, 'str_nightly_rate', true ) : 0;
		$weekday_price = $property_id ? ( (float) get_post_meta( $property_id, 'str_weekday_price', true ) ?: $base_rate ) : 0;
		$weekend_price = $property_id ? ( (float) get_post_meta( $property_id, 'str_weekend_price', true ) ?: $base_rate ) : 0;
		$los_discounts = $property_id ? get_post_meta( $property_id, 'str_los_discounts', true ) : '';
		$min_nights    = $property_id ? (int) get_post_meta( $property_id, 'str_min_nights', true ) : 0;
		$max_nights    = $property_id ? (int) get_post_meta( $property_id, 'str_max_nights', true ) : 0;
		$notice_days   = $property_id ? (int) get_post_meta( $property_id, 'str_advance_notice_days', true ) : 0;

		$currency_symbol = '$'; // Future: derive from settings.

		// Build calendar grid data (PHP-side, no AJAX on initial load).
		$cal_days = $this->build_month_data( $property_id, $year, $month, $weekday_price, $weekend_price );

		$month_name = date_i18n( 'F Y', mktime( 0, 0, 0, $month, 1, $year ) );
		?>
		<div class="wrap str-calendar-wrap">

		<style>
		.str-calendar-wrap { max-width:1200px; }
		.str-cal-header { display:flex; align-items:center; gap:16px; margin-bottom:20px; flex-wrap:wrap; }
		.str-cal-header h1 { margin:0; font-size:22px; }
		.str-cal-nav { display:flex; align-items:center; gap:8px; }
		.str-cal-nav a,
		.str-cal-nav button { padding:6px 12px; border:1px solid #ddd; border-radius:5px; background:#fff; font-size:13px; text-decoration:none; color:#1a1a2e; cursor:pointer; }
		.str-cal-nav a:hover, .str-cal-nav button:hover { background:#f0f0f0; }
		.str-cal-month-name { font-size:18px; font-weight:700; min-width:160px; }
		.str-cal-property-select { padding:6px 10px; border:1px solid #ddd; border-radius:5px; font-size:13px; }

		.str-cal-layout { display:grid; grid-template-columns:1fr 300px; gap:20px; align-items:start; }
		@media(max-width:900px){ .str-cal-layout { grid-template-columns:1fr; } }

		.str-cal-grid-wrap { background:#fff; border-radius:8px; border:1px solid #e2e8f0; overflow:hidden; }
		.str-cal-weekdays { display:grid; grid-template-columns:repeat(7,1fr); border-bottom:1px solid #e2e8f0; }
		.str-cal-weekday { padding:8px 4px; text-align:center; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:#888; }

		.str-cal-days { display:grid; grid-template-columns:repeat(7,1fr); }
		.str-cal-day { min-height:90px; padding:8px; border-right:1px solid #f0f2f5; border-bottom:1px solid #f0f2f5; cursor:pointer; position:relative; transition:background .15s; }
		.str-cal-day:nth-child(7n) { border-right:none; }
		.str-cal-day:hover { background:#f8faff; }
		.str-cal-day.str-cal-empty { background:#fafafa; cursor:default; }
		.str-cal-day.str-cal-booked { background:#f5f5f5; cursor:default; }
		.str-cal-day.str-cal-blocked { background:#fdf2f2; }
		.str-cal-day.str-cal-past { opacity:.45; cursor:default; }
		.str-cal-day.str-cal-selected { background:#1a1a2e; color:#fff; border-radius:0; }
		.str-cal-day.str-cal-today .str-cal-day-num { background:#d63638; color:#fff; border-radius:50%; width:24px; height:24px; display:flex; align-items:center; justify-content:center; font-size:13px; font-weight:700; }

		.str-cal-day-num { font-size:13px; font-weight:600; color:#1a1a2e; margin-bottom:4px; }
		.str-cal-day.str-cal-selected .str-cal-day-num { color:#fff; }
		.str-cal-day-price { font-size:12px; color:#555; }
		.str-cal-day.str-cal-selected .str-cal-day-price { color:#ccc; }
		.str-cal-day-price.is-override { color:#2271b1; font-weight:600; }
		.str-cal-day.str-cal-selected .str-cal-day-price.is-override { color:#7ab3e8; }
		.str-cal-day-booking { font-size:11px; color:#888; margin-top:4px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
		.str-cal-day-blocked-label { font-size:11px; color:#c0392b; font-weight:600; }

		/* Side panel */
		.str-cal-sidebar { display:flex; flex-direction:column; gap:16px; }
		.str-cal-info-card { background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px; }
		.str-cal-info-card h3 { margin:0 0 12px; font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:#888; }
		.str-cal-info-row { display:flex; justify-content:space-between; padding:5px 0; font-size:13px; border-bottom:1px solid #f0f2f5; }
		.str-cal-info-row:last-child { border-bottom:none; }
		.str-cal-info-row a { color:#2271b1; text-decoration:none; font-size:12px; }

		.str-cal-day-panel { background:#1a1a2e; color:#fff; border-radius:8px; padding:20px; display:none; }
		.str-cal-day-panel.is-open { display:block; }
		.str-cal-panel-date { font-size:15px; font-weight:700; margin-bottom:16px; display:flex; justify-content:space-between; align-items:center; }
		.str-cal-panel-close { background:none; border:none; color:#aaa; font-size:20px; cursor:pointer; line-height:1; padding:0; }
		.str-cal-panel-close:hover { color:#fff; }

		.str-cal-toggle-row { background:#2a2a3e; border-radius:6px; padding:12px 16px; display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; }
		.str-cal-toggle-label { font-size:14px; font-weight:500; }
		.str-cal-toggle-label .str-dot { display:inline-block; width:8px; height:8px; border-radius:50%; margin-right:6px; }
		.str-cal-toggle-label .str-dot.available { background:#16a34a; }
		.str-cal-toggle-label .str-dot.blocked { background:#d63638; }
		.str-cal-toggle-btns { display:flex; gap:4px; }
		.str-cal-toggle-btns button { padding:4px 10px; border:none; border-radius:4px; cursor:pointer; font-size:13px; font-weight:600; }
		.str-cal-toggle-btns .btn-unavailable { background:#4a2020; color:#f87171; }
		.str-cal-toggle-btns .btn-unavailable.active { background:#d63638; color:#fff; }
		.str-cal-toggle-btns .btn-available { background:#203a2a; color:#4ade80; }
		.str-cal-toggle-btns .btn-available.active { background:#16a34a; color:#fff; }

		.str-cal-price-card { background:#2a2a3e; border-radius:6px; padding:16px; margin-bottom:12px; }
		.str-cal-price-label { font-size:12px; color:#aaa; margin-bottom:6px; }
		.str-cal-price-input-wrap { display:flex; align-items:center; gap:8px; }
		.str-cal-price-input-wrap span { font-size:20px; color:#aaa; }
		.str-cal-price-input { background:transparent; border:none; border-bottom:2px solid #555; color:#fff; font-size:28px; font-weight:700; width:120px; padding:2px 0; outline:none; }
		.str-cal-price-input:focus { border-bottom-color:#2271b1; }
		.str-cal-price-clear { font-size:11px; color:#888; cursor:pointer; background:none; border:none; padding:0; margin-top:4px; }
		.str-cal-price-clear:hover { color:#aaa; }
		.str-cal-price-note { font-size:11px; color:#777; margin-top:4px; }

		.str-cal-save-btn { width:100%; padding:10px; background:#2271b1; color:#fff; border:none; border-radius:6px; font-size:14px; font-weight:600; cursor:pointer; margin-top:4px; }
		.str-cal-save-btn:hover { background:#135e96; }
		.str-cal-save-btn:disabled { background:#3a4a5a; cursor:not-allowed; }
		.str-cal-save-msg { font-size:12px; text-align:center; margin-top:8px; min-height:16px; }
		.str-cal-save-msg.ok { color:#4ade80; }
		.str-cal-save-msg.err { color:#f87171; }
		</style>

		<div class="str-cal-header">
			<h1><?php esc_html_e( 'Pricing Calendar', 'str-direct-booking' ); ?></h1>

			<?php if ( count( $properties ) > 1 ) : ?>
			<form method="get" style="display:inline-flex;gap:6px;align-items:center;">
				<input type="hidden" name="page" value="str-pricing-calendar">
				<input type="hidden" name="cal_year"  value="<?php echo esc_attr( $year ); ?>">
				<input type="hidden" name="cal_month" value="<?php echo esc_attr( $month ); ?>">
				<select name="property_id" class="str-cal-property-select" onchange="this.form.submit()">
					<?php foreach ( $properties as $prop ) : ?>
						<option value="<?php echo esc_attr( $prop->ID ); ?>" <?php selected( $prop->ID, $property_id ); ?>>
							<?php echo esc_html( $prop->post_title ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</form>
			<?php elseif ( $property ) : ?>
				<span style="font-weight:600;color:#555;"><?php echo esc_html( $property->post_title ); ?></span>
			<?php endif; ?>
		</div>

		<?php if ( ! $property_id || ! $property ) : ?>
			<div class="notice notice-warning"><p><?php esc_html_e( 'No properties found. Create a property first.', 'str-direct-booking' ); ?></p></div>
		</div>
		<?php return; ?>
		<?php endif; ?>

		<div class="str-cal-layout">

			<!-- Calendar grid -->
			<div>
				<div class="str-cal-nav" style="margin-bottom:12px;">
					<a href="<?php echo esc_url( $prev_url ); ?>">&#8249;</a>
					<span class="str-cal-month-name"><?php echo esc_html( $month_name ); ?></span>
					<a href="<?php echo esc_url( $next_url ); ?>">&#8250;</a>
				</div>

				<div class="str-cal-grid-wrap">
					<div class="str-cal-weekdays">
						<?php foreach ( array( 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' ) as $wd ) : ?>
							<div class="str-cal-weekday"><?php echo esc_html( $wd ); ?></div>
						<?php endforeach; ?>
					</div>

					<div class="str-cal-days" id="str-cal-days">
						<?php $this->render_calendar_cells( $cal_days, $year, $month, $currency_symbol ); ?>
					</div>
				</div>
			</div>

			<!-- Sidebar -->
			<div class="str-cal-sidebar">

				<!-- Day edit panel (hidden until day is clicked) -->
				<div class="str-cal-day-panel" id="str-cal-day-panel">
					<div class="str-cal-panel-date">
						<span id="str-panel-date-label"></span>
						<button class="str-cal-panel-close" onclick="strClosePanel()" title="Close">×</button>
					</div>

					<div class="str-cal-toggle-row">
						<span class="str-cal-toggle-label">
							<span class="str-dot available" id="str-panel-dot"></span>
							<span id="str-panel-avail-text"><?php esc_html_e( 'Available', 'str-direct-booking' ); ?></span>
						</span>
						<div class="str-cal-toggle-btns">
							<button class="btn-unavailable" id="str-btn-unavailable" onclick="strSetAvail(false)">✕</button>
							<button class="btn-available active" id="str-btn-available"  onclick="strSetAvail(true)">✓</button>
						</div>
					</div>

					<div class="str-cal-price-card">
						<div class="str-cal-price-label"><?php esc_html_e( 'Price per night', 'str-direct-booking' ); ?></div>
						<div class="str-cal-price-input-wrap">
							<span><?php echo esc_html( $currency_symbol ); ?></span>
							<input type="number" id="str-panel-price" class="str-cal-price-input" min="0" step="1" placeholder="—">
						</div>
						<button class="str-cal-price-clear" onclick="strClearOverride()" id="str-clear-btn" style="display:none;">
							<?php esc_html_e( '↩ Reset to base price', 'str-direct-booking' ); ?>
						</button>
						<div class="str-cal-price-note" id="str-price-note"></div>
					</div>

					<button class="str-cal-save-btn" id="str-save-btn" onclick="strSaveDay()">
						<?php esc_html_e( 'Save', 'str-direct-booking' ); ?>
					</button>
					<div class="str-cal-save-msg" id="str-save-msg"></div>
				</div>

				<!-- Price settings -->
				<div class="str-cal-info-card">
					<h3><?php esc_html_e( 'Price Settings', 'str-direct-booking' ); ?></h3>
					<?php if ( $weekday_price ) : ?>
					<div class="str-cal-info-row">
						<span><?php esc_html_e( 'Weekday (Mon–Thu)', 'str-direct-booking' ); ?></span>
						<span><strong><?php echo esc_html( $currency_symbol . number_format( $weekday_price, 0 ) ); ?></strong> / <?php esc_html_e( 'night', 'str-direct-booking' ); ?></span>
					</div>
					<?php endif; ?>
					<?php if ( $weekend_price ) : ?>
					<div class="str-cal-info-row">
						<span><?php esc_html_e( 'Weekend (Fri–Sun)', 'str-direct-booking' ); ?></span>
						<span><strong><?php echo esc_html( $currency_symbol . number_format( $weekend_price, 0 ) ); ?></strong> / <?php esc_html_e( 'night', 'str-direct-booking' ); ?></span>
					</div>
					<?php endif; ?>
					<?php if ( ! $weekday_price && ! $weekend_price && $base_rate ) : ?>
					<div class="str-cal-info-row">
						<span><?php esc_html_e( 'Base rate', 'str-direct-booking' ); ?></span>
						<span><strong><?php echo esc_html( $currency_symbol . number_format( $base_rate, 0 ) ); ?></strong> / <?php esc_html_e( 'night', 'str-direct-booking' ); ?></span>
					</div>
					<?php endif; ?>
					<?php if ( $los_discounts ) :
						$tiers = json_decode( $los_discounts, true ) ?: array();
						foreach ( $tiers as $tier ) : ?>
					<div class="str-cal-info-row">
						<span><?php printf( esc_html__( '%d+ night discount', 'str-direct-booking' ), (int) ( $tier['min_nights'] ?? 0 ) ); ?></span>
						<span><?php echo esc_html( round( (float) ( $tier['discount'] ?? 0 ) * 100 ) ); ?>%</span>
					</div>
					<?php endforeach; endif; ?>
					<div class="str-cal-info-row" style="border-top:1px solid #e2e8f0;margin-top:4px;padding-top:8px;">
						<a href="<?php echo esc_url( get_edit_post_link( $property_id ) ); ?>">
							<?php esc_html_e( 'Edit in Property Settings →', 'str-direct-booking' ); ?>
						</a>
					</div>
				</div>

				<!-- Availability settings -->
				<div class="str-cal-info-card">
					<h3><?php esc_html_e( 'Availability Settings', 'str-direct-booking' ); ?></h3>
					<div class="str-cal-info-row">
						<span><?php esc_html_e( 'Min nights', 'str-direct-booking' ); ?></span>
						<span><?php echo $min_nights ? esc_html( $min_nights ) : esc_html__( 'Not set', 'str-direct-booking' ); ?></span>
					</div>
					<div class="str-cal-info-row">
						<span><?php esc_html_e( 'Max nights', 'str-direct-booking' ); ?></span>
						<span><?php echo $max_nights ? esc_html( $max_nights ) : esc_html__( 'Not set', 'str-direct-booking' ); ?></span>
					</div>
					<div class="str-cal-info-row" style="border-top:1px solid #e2e8f0;margin-top:4px;padding-top:8px;">
						<a href="<?php echo esc_url( get_edit_post_link( $property_id ) ); ?>">
							<?php esc_html_e( 'Edit in Property Settings →', 'str-direct-booking' ); ?>
						</a>
					</div>
				</div>

			</div><!-- /sidebar -->
		</div><!-- /layout -->

		<script>
		(function(){
			var API_URL = '<?php echo esc_js( rest_url( 'str-booking/v1' ) ); ?>';
			var NONCE   = '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>';
			var PROP_ID = <?php echo (int) $property_id; ?>;
			var SYMBOL  = '<?php echo esc_js( $currency_symbol ); ?>';

			var state = {
				date:        null,
				isAvailable: true,
				basePrice:   0,
				override:    null,
				isBooked:    false,
			};

			// Attach click handlers to day cells.
			function init() {
				document.querySelectorAll('.str-cal-day[data-date]').forEach(function(cell) {
					cell.addEventListener('click', function() {
						var d = cell.dataset;
						if ( d.booked === '1' || d.past === '1' ) return;
						openPanel(
							d.date,
							d.available === '1',
							parseFloat(d.base)   || 0,
							d.override !== '' ? parseFloat(d.override) : null,
							d.booked === '1'
						);
						// Highlight selected cell.
						document.querySelectorAll('.str-cal-day').forEach(function(c){ c.classList.remove('str-cal-selected'); });
						cell.classList.add('str-cal-selected');
					});
				});
			}

			function openPanel(date, isAvailable, base, override, isBooked) {
				state.date        = date;
				state.isAvailable = isAvailable;
				state.basePrice   = base;
				state.override    = override;
				state.isBooked    = isBooked;

				var label = new Date(date + 'T00:00:00');
				var opts  = { month:'short', day:'numeric', year:'numeric' };
				document.getElementById('str-panel-date-label').textContent = label.toLocaleDateString(undefined, opts);

				updateAvailUI();
				updatePriceUI();
				document.getElementById('str-save-msg').textContent = '';
				document.getElementById('str-cal-day-panel').classList.add('is-open');
			}

			function updateAvailUI() {
				var dot  = document.getElementById('str-panel-dot');
				var txt  = document.getElementById('str-panel-avail-text');
				var btnU = document.getElementById('str-btn-unavailable');
				var btnA = document.getElementById('str-btn-available');
				dot.className = 'str-dot ' + (state.isAvailable ? 'available' : 'blocked');
				txt.textContent = state.isAvailable ? '<?php echo esc_js( __( 'Available', 'str-direct-booking' ) ); ?>' : '<?php echo esc_js( __( 'Unavailable', 'str-direct-booking' ) ); ?>';
				btnU.classList.toggle('active', !state.isAvailable);
				btnA.classList.toggle('active',  state.isAvailable);
			}

			function updatePriceUI() {
				var inp   = document.getElementById('str-panel-price');
				var note  = document.getElementById('str-price-note');
				var clrBt = document.getElementById('str-clear-btn');
				if ( state.override !== null ) {
					inp.value = state.override;
					clrBt.style.display = 'block';
					note.textContent = '<?php echo esc_js( __( 'Custom override active', 'str-direct-booking' ) ); ?>';
				} else {
					inp.value = state.basePrice || '';
					clrBt.style.display = 'none';
					note.textContent = '<?php echo esc_js( __( 'Base price for this day', 'str-direct-booking' ) ); ?>';
				}
			}

			window.strSetAvail = function(val) {
				state.isAvailable = val;
				updateAvailUI();
			};

			window.strClearOverride = function() {
				state.override = null;
				updatePriceUI();
			};

			window.strClosePanel = function() {
				document.getElementById('str-cal-day-panel').classList.remove('is-open');
				document.querySelectorAll('.str-cal-day').forEach(function(c){ c.classList.remove('str-cal-selected'); });
				state.date = null;
			};

			window.strSaveDay = function() {
				if ( ! state.date ) return;
				var btn = document.getElementById('str-save-btn');
				var msg = document.getElementById('str-save-msg');
				var inp = document.getElementById('str-panel-price');
				btn.disabled = true;
				msg.textContent = '';

				var priceVal = parseFloat(inp.value);
				var body = {
					date:         state.date,
					is_available: state.isAvailable,
					price_override: (!isNaN(priceVal) && priceVal !== state.basePrice) ? priceVal : null,
				};

				fetch(API_URL + '/admin/pricing-calendar/' + PROP_ID + '/day', {
					method:  'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
					body:    JSON.stringify(body),
				})
				.then(function(r){ return r.json(); })
				.then(function(data) {
					btn.disabled = false;
					if ( data.success ) {
						msg.className = 'str-cal-save-msg ok';
						msg.textContent = '<?php echo esc_js( __( 'Saved!', 'str-direct-booking' ) ); ?>';
						// Update state.
						state.override = data.price_override;
						state.isAvailable = data.status !== 'blocked';
						updatePriceUI();
						updateAvailUI();
						// Update cell in DOM.
						updateCell(state.date, data.status, data.price_override, state.basePrice);
					} else {
						msg.className = 'str-cal-save-msg err';
						msg.textContent = data.message || '<?php echo esc_js( __( 'Error saving.', 'str-direct-booking' ) ); ?>';
					}
				})
				.catch(function(){
					btn.disabled = false;
					msg.className = 'str-cal-save-msg err';
					msg.textContent = '<?php echo esc_js( __( 'Network error.', 'str-direct-booking' ) ); ?>';
				});
			};

			function updateCell(date, status, override, base) {
				var cell = document.querySelector('.str-cal-day[data-date="' + date + '"]');
				if ( ! cell ) return;
				var priceEl = cell.querySelector('.str-cal-day-price');
				var effective = (override !== null && override !== undefined) ? override : base;
				if ( priceEl ) {
					priceEl.textContent = SYMBOL + Math.round(effective);
					priceEl.classList.toggle('is-override', override !== null);
				}
				cell.dataset.available = (status !== 'blocked') ? '1' : '0';
				cell.dataset.override  = (override !== null && override !== undefined) ? override : '';
				cell.classList.toggle('str-cal-blocked', status === 'blocked');
				var blockedLbl = cell.querySelector('.str-cal-day-blocked-label');
				if ( status === 'blocked' ) {
					if (!blockedLbl) {
						var lbl = document.createElement('div');
						lbl.className = 'str-cal-day-blocked-label';
						lbl.textContent = '<?php echo esc_js( __( 'Blocked', 'str-direct-booking' ) ); ?>';
						cell.appendChild(lbl);
					}
				} else if ( blockedLbl ) {
					blockedLbl.remove();
				}
			}

			init();
		})();
		</script>
		</div>
		<?php
	}

	/**
	 * Build the full month data array for rendering.
	 *
	 * @param int   $property_id   Property ID.
	 * @param int   $year          Year.
	 * @param int   $month         Month (1-12).
	 * @param float $weekday_price Weekday base price.
	 * @param float $weekend_price Weekend base price.
	 * @return array
	 */
	private function build_month_data( int $property_id, int $year, int $month, float $weekday_price, float $weekend_price ): array {
		global $wpdb;

		if ( ! $property_id ) {
			return array();
		}

		$start = sprintf( '%04d-%02d-01', $year, $month );
		$end   = date( 'Y-m-d', strtotime( $start . ' +1 month' ) );
		$table = $wpdb->prefix . 'str_availability';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT date, status, price_override FROM {$table}
				WHERE property_id = %d AND date >= %s AND date < %s",
				$property_id,
				$start,
				$end
			),
			ARRAY_A
		);

		$av_map = array();
		foreach ( $rows as $row ) {
			$av_map[ $row['date'] ] = $row;
		}

		// Confirmed bookings → guest name on calendar.
		$bookings = get_posts( array(
			'post_type'      => 'str_booking',
			'post_status'    => array( 'confirmed', 'checked_in' ),
			'posts_per_page' => -1,
			'meta_query'     => array(
				array( 'key' => 'str_property_id', 'value' => $property_id, 'type' => 'NUMERIC', 'compare' => '=' ),
			),
		) );

		$booking_map = array();
		foreach ( $bookings as $bp ) {
			$ci = get_post_meta( $bp->ID, 'str_check_in',  true );
			$co = get_post_meta( $bp->ID, 'str_check_out', true );
			$gn = get_post_meta( $bp->ID, 'str_guest_name', true );
			if ( ! $ci || ! $co ) {
				continue;
			}
			$cur    = new \DateTime( $ci );
			$end_dt = new \DateTime( $co );
			while ( $cur < $end_dt ) {
				$ds = $cur->format( 'Y-m-d' );
				if ( $ds >= $start && $ds < $end ) {
					$booking_map[ $ds ] = array(
						'id'         => $bp->ID,
						'guest_name' => $gn,
						'check_in'   => $ci,
						'check_out'  => $co,
					);
				}
				$cur->modify( '+1 day' );
			}
		}

		$today   = date( 'Y-m-d' );
		$days    = array();
		$current = new \DateTime( $start );
		$end_dt  = new \DateTime( $end );

		while ( $current < $end_dt ) {
			$date       = $current->format( 'Y-m-d' );
			$dow        = (int) $current->format( 'N' ); // 1=Mon…7=Sun
			$is_weekend = in_array( $dow, array( 5, 6, 7 ), true );
			$base       = $is_weekend ? $weekend_price : $weekday_price;

			$av_row   = $av_map[ $date ] ?? null;
			$override = ( isset( $av_row['price_override'] ) && $av_row['price_override'] !== null )
				? (float) $av_row['price_override']
				: null;
			$status   = $av_row['status'] ?? 'available';
			$booking  = $booking_map[ $date ] ?? null;

			if ( $booking ) {
				$status = 'booked';
			}

			$days[] = array(
				'date'        => $date,
				'day_of_week' => (int) $current->format( 'w' ), // 0=Sun 6=Sat (JS-style)
				'base'        => $base,
				'override'    => $override,
				'effective'   => $override ?? $base,
				'status'      => $status,
				'is_past'     => $date < $today,
				'is_today'    => $date === $today,
				'booking'     => $booking,
			);

			$current->modify( '+1 day' );
		}

		return $days;
	}

	/**
	 * Render HTML cells for the calendar grid.
	 *
	 * @param array  $days            Month day data.
	 * @param int    $year            Year.
	 * @param int    $month           Month.
	 * @param string $currency_symbol Currency symbol.
	 */
	private function render_calendar_cells( array $days, int $year, int $month, string $currency_symbol ): void {
		// First day of month: what weekday? (0=Sun … 6=Sat).
		$first_dow = (int) date( 'w', mktime( 0, 0, 0, $month, 1, $year ) );

		// Leading empty cells.
		for ( $i = 0; $i < $first_dow; $i++ ) {
			echo '<div class="str-cal-day str-cal-empty"></div>';
		}

		foreach ( $days as $day ) {
			$classes = array( 'str-cal-day' );
			if ( 'booked'  === $day['status'] )               $classes[] = 'str-cal-booked';
			if ( 'blocked' === $day['status'] )               $classes[] = 'str-cal-blocked';
			if ( $day['is_past'] )                            $classes[] = 'str-cal-past';
			if ( $day['is_today'] )                           $classes[] = 'str-cal-today';

			$date_parts = explode( '-', $day['date'] );
			$day_num    = (int) $date_parts[2];

			$effective   = $day['effective'];
			$price_class = 'str-cal-day-price' . ( $day['override'] !== null ? ' is-override' : '' );
			$price_str   = $effective > 0 ? $currency_symbol . number_format( $effective, 0 ) : '';

			$data_attrs = sprintf(
				'data-date="%s" data-base="%s" data-override="%s" data-available="%s" data-booked="%s" data-past="%s"',
				esc_attr( $day['date'] ),
				esc_attr( (string) $day['base'] ),
				esc_attr( $day['override'] !== null ? (string) $day['override'] : '' ),
				( 'blocked' !== $day['status'] && 'booked' !== $day['status'] ) ? '1' : '0',
				'booked' === $day['status'] ? '1' : '0',
				$day['is_past'] ? '1' : '0'
			);

			printf( '<div class="%s" %s>', esc_attr( implode( ' ', $classes ) ), $data_attrs );

			// Day number.
			printf( '<div class="str-cal-day-num">%d</div>', $day_num );

			// Price.
			if ( $price_str && 'booked' !== $day['status'] ) {
				printf( '<div class="%s">%s</div>', esc_attr( $price_class ), esc_html( $price_str ) );
			}

			// Booking guest name.
			if ( $day['booking'] ) {
				$gn = $day['booking']['guest_name'] ?? '';
				printf( '<div class="str-cal-day-booking">%s</div>', esc_html( $gn ? $gn : __( 'Reserved', 'str-direct-booking' ) ) );
			}

			// Blocked label.
			if ( 'blocked' === $day['status'] ) {
				echo '<div class="str-cal-day-blocked-label">' . esc_html__( 'Blocked', 'str-direct-booking' ) . '</div>';
			}

			echo '</div>';
		}

		// Trailing empty cells to complete the last row.
		$total_cells = $first_dow + count( $days );
		$trailing    = ( 7 - ( $total_cells % 7 ) ) % 7;
		for ( $i = 0; $i < $trailing; $i++ ) {
			echo '<div class="str-cal-day str-cal-empty"></div>';
		}
	}
}
