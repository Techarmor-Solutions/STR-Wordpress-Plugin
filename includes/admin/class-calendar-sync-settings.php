<?php
/**
 * Calendar Sync Settings — admin page for managing iCal feed imports and exports.
 *
 * @package STRBooking\Admin
 */

namespace STRBooking\Admin;

use STRBooking\STRBooking;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Calendar Sync submenu page.
 *
 * Allows hosts to:
 *  - Add Airbnb / VRBO / Booking.com iCal import URLs per property
 *  - See last-sync status and trigger a manual sync
 *  - Copy the WordPress iCal export URL for each property to paste into external platforms
 *  - Delete feeds they no longer need
 */
class CalendarSyncSettings {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_submenu' ), 25 );
		add_action( 'admin_post_str_add_calendar_feed', array( $this, 'handle_add_feed' ) );
		add_action( 'admin_post_str_delete_calendar_feed', array( $this, 'handle_delete_feed' ) );
		add_action( 'admin_post_str_sync_now', array( $this, 'handle_sync_now' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Register the Calendar Sync submenu page.
	 */
	public function register_submenu(): void {
		add_submenu_page(
			'str-booking',
			__( 'Calendar Sync', 'str-direct-booking' ),
			__( 'Calendar Sync', 'str-direct-booking' ),
			'manage_options',
			'str-booking-calendar-sync',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue inline CSS on our page only.
	 */
	public function enqueue_scripts( string $hook ): void {
		if ( 'str-booking_page_str-booking-calendar-sync' !== $hook ) {
			return;
		}

		wp_add_inline_style(
			'wp-admin',
			'
			.str-sync-card { background:#fff; border:1px solid #ddd; border-radius:4px; margin-bottom:20px; }
			.str-sync-card-head { display:flex; align-items:center; justify-content:space-between; padding:14px 18px; border-bottom:1px solid #eee; }
			.str-sync-card-head h3 { margin:0; font-size:14px; }
			.str-sync-card-body { padding:18px; }
			.str-export-url-box { display:flex; gap:8px; align-items:center; margin-bottom:20px; }
			.str-export-url-box input { flex:1; font-family:monospace; font-size:12px; background:#f6f7f7; }
			.str-feeds-table { width:100%; border-collapse:collapse; }
			.str-feeds-table th { text-align:left; padding:8px 10px; background:#f6f7f7; border-bottom:2px solid #ddd; font-size:12px; font-weight:600; }
			.str-feeds-table td { padding:10px; border-bottom:1px solid #f0f0f0; vertical-align:middle; font-size:13px; }
			.str-feeds-table tr:last-child td { border-bottom:none; }
			.str-feed-url { font-family:monospace; font-size:11px; color:#555; max-width:320px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; display:block; }
			.str-badge { display:inline-block; padding:2px 8px; border-radius:3px; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.4px; }
			.str-badge-success { background:#d1fae5; color:#065f46; }
			.str-badge-error   { background:#fee2e2; color:#991b1b; }
			.str-badge-pending { background:#fef9c3; color:#854d0e; }
			.str-badge-running { background:#dbeafe; color:#1e40af; }
			.str-add-feed-form { display:grid; grid-template-columns:1fr 160px 2fr auto; gap:10px; align-items:end; }
			.str-add-feed-form label { display:block; font-size:12px; font-weight:600; margin-bottom:4px; }
			.str-add-feed-form input, .str-add-feed-form select { width:100%; }
			.str-sync-actions { display:flex; gap:8px; align-items:center; }
			.str-platform-icon { display:inline-block; width:18px; height:18px; border-radius:3px; vertical-align:middle; margin-right:5px; font-size:10px; font-weight:700; text-align:center; line-height:18px; color:#fff; }
			.str-platform-airbnb    { background:#ff5a5f; }
			.str-platform-vrbo      { background:#3d6eff; }
			.str-platform-booking   { background:#003580; }
			.str-platform-other     { background:#888; }
			.str-empty-feeds { color:#888; font-style:italic; padding:12px 0; }
			.str-how-to { background:#f0f6fc; border-left:4px solid #2271b1; padding:14px 18px; margin-bottom:24px; font-size:13px; line-height:1.6; }
			.str-how-to strong { display:block; margin-bottom:6px; font-size:13px; }
			.str-how-to ol { margin:8px 0 0 18px; }
			.str-how-to li { margin-bottom:4px; }
			@media (max-width: 900px) {
				.str-add-feed-form { grid-template-columns:1fr 1fr; }
				.str-add-feed-form .str-url-col { grid-column:1/-1; }
			}
			'
		);
	}

	/**
	 * Handle POST: add a new calendar feed.
	 */
	public function handle_add_feed(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'str-direct-booking' ) );
		}

		check_admin_referer( 'str_add_calendar_feed' );

		$property_id = absint( $_POST['property_id'] ?? 0 );
		$feed_url    = esc_url_raw( wp_unslash( $_POST['feed_url'] ?? '' ) );
		$platform    = sanitize_key( $_POST['platform'] ?? 'other' );

		$valid_platforms = array( 'airbnb', 'vrbo', 'booking', 'other' );
		if ( ! in_array( $platform, $valid_platforms, true ) ) {
			$platform = 'other';
		}

		if ( ! $property_id || empty( $feed_url ) ) {
			wp_redirect( $this->page_url( array( 'error' => 'missing_fields' ) ) );
			exit;
		}

		// Validate it looks like an iCal URL
		if ( ! filter_var( $feed_url, FILTER_VALIDATE_URL ) ) {
			wp_redirect( $this->page_url( array( 'error' => 'invalid_url' ) ) );
			exit;
		}

		$result = STRBooking::get_instance()->calendar_sync->add_feed( $property_id, $feed_url, $platform );

		if ( is_wp_error( $result ) ) {
			wp_redirect( $this->page_url( array( 'error' => 'db_error' ) ) );
			exit;
		}

		// Kick off an immediate import for this property
		STRBooking::get_instance()->calendar_sync->sync_property( $property_id );

		wp_redirect( $this->page_url( array( 'added' => '1', 'property' => $property_id ) ) );
		exit;
	}

	/**
	 * Handle POST: delete a calendar feed.
	 */
	public function handle_delete_feed(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'str-direct-booking' ) );
		}

		$feed_id     = absint( $_POST['feed_id'] ?? 0 );
		$property_id = absint( $_POST['property_id'] ?? 0 );

		check_admin_referer( 'str_delete_feed_' . $feed_id );

		STRBooking::get_instance()->calendar_sync->delete_feed( $feed_id );

		wp_redirect( $this->page_url( array( 'deleted' => '1', 'property' => $property_id ) ) );
		exit;
	}

	/**
	 * Handle POST: manually trigger a sync for one property.
	 */
	public function handle_sync_now(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'str-direct-booking' ) );
		}

		$property_id = absint( $_POST['property_id'] ?? 0 );

		check_admin_referer( 'str_sync_now_' . $property_id );

		$count = STRBooking::get_instance()->calendar_sync->sync_property( $property_id );

		wp_redirect( $this->page_url( array( 'synced' => $count, 'property' => $property_id ) ) );
		exit;
	}

	/**
	 * Render the Calendar Sync admin page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'str-direct-booking' ) );
		}

		// Fetch all str_property posts
		$properties = get_posts(
			array(
				'post_type'      => 'str_property',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$notice        = $this->get_notice();
		$active_prop   = absint( $_GET['property'] ?? ( $properties[0]->ID ?? 0 ) );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Calendar Sync', 'str-direct-booking' ); ?></h1>
			<p style="color:#666;margin-top:-6px"><?php esc_html_e( 'Connect Airbnb, VRBO, and other platform calendars so their bookings block dates here automatically.', 'str-direct-booking' ); ?></p>

			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
					<p><?php echo esc_html( $notice['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<div class="str-how-to">
				<strong>How two-way sync works</strong>
				<ol>
					<li><strong>Import (Airbnb/VRBO → here):</strong> Paste the iCal export URL from each platform into the form below. Dates blocked on those platforms will show as unavailable on your booking form.</li>
					<li><strong>Export (here → Airbnb/VRBO):</strong> Copy your property's export URL below and paste it into Airbnb and VRBO's "import calendar" field. Direct bookings made here will block those platforms.</li>
				</ol>
				<p style="margin:8px 0 0"><strong>Sync frequency:</strong> Automatic every hour via WP-Cron. Use the "Sync Now" button to import immediately.</p>
			</div>

			<?php if ( empty( $properties ) ) : ?>
				<p><?php esc_html_e( 'No properties found. Add a property first under STR Booking → Properties.', 'str-direct-booking' ); ?></p>
			<?php else : ?>

			<?php foreach ( $properties as $property ) :
				$feeds       = STRBooking::get_instance()->calendar_sync->get_feeds( $property->ID );
				$export_url  = home_url( '/str-calendar/' . $property->ID . '/' );
				$is_open     = ( $active_prop === $property->ID );
			?>

			<div class="str-sync-card">
				<div class="str-sync-card-head">
					<h3>
						<?php echo esc_html( $property->post_title ); ?>
						<span style="font-weight:400;color:#888;font-size:12px;margin-left:8px">(ID: <?php echo $property->ID; ?>)</span>
						<?php if ( ! empty( $feeds ) ) : ?>
							<span style="font-weight:400;color:#666;font-size:12px;margin-left:8px">
								<?php echo count( $feeds ); ?> feed<?php echo count( $feeds ) !== 1 ? 's' : ''; ?>
							</span>
						<?php endif; ?>
					</h3>
					<div class="str-sync-actions">
						<?php if ( ! empty( $feeds ) ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0">
							<input type="hidden" name="action" value="str_sync_now" />
							<input type="hidden" name="property_id" value="<?php echo $property->ID; ?>" />
							<?php wp_nonce_field( 'str_sync_now_' . $property->ID ); ?>
							<button type="submit" class="button button-secondary" style="font-size:12px">
								↻ <?php esc_html_e( 'Sync Now', 'str-direct-booking' ); ?>
							</button>
						</form>
						<?php endif; ?>
					</div>
				</div>

				<div class="str-sync-card-body">

					<!-- Export URL -->
					<p style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.4px;color:#555;margin:0 0 6px">
						<?php esc_html_e( 'Your Export URL — paste this into Airbnb & VRBO', 'str-direct-booking' ); ?>
					</p>
					<div class="str-export-url-box">
						<input
							type="text"
							id="str-export-url-<?php echo $property->ID; ?>"
							value="<?php echo esc_attr( $export_url ); ?>"
							readonly
							onclick="this.select()"
						/>
						<button
							type="button"
							class="button button-secondary"
							onclick="
								var el = document.getElementById('str-export-url-<?php echo $property->ID; ?>');
								el.select();
								document.execCommand('copy');
								this.textContent = '<?php echo esc_js( __( 'Copied!', 'str-direct-booking' ) ); ?>';
								var btn = this;
								setTimeout(function(){ btn.textContent = '<?php echo esc_js( __( 'Copy', 'str-direct-booking' ) ); ?>'; }, 2000);
							"
						><?php esc_html_e( 'Copy', 'str-direct-booking' ); ?></button>
					</div>

					<!-- Existing feeds table -->
					<?php if ( ! empty( $feeds ) ) : ?>
					<p style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.4px;color:#555;margin:16px 0 8px">
						<?php esc_html_e( 'Import Feeds', 'str-direct-booking' ); ?>
					</p>
					<table class="str-feeds-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Platform', 'str-direct-booking' ); ?></th>
								<th><?php esc_html_e( 'Feed URL', 'str-direct-booking' ); ?></th>
								<th><?php esc_html_e( 'Last Synced', 'str-direct-booking' ); ?></th>
								<th><?php esc_html_e( 'Status', 'str-direct-booking' ); ?></th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $feeds as $feed ) :
								$platform_key = $feed['platform'] ?? 'other';
								$platform_labels = array(
									'airbnb'  => 'Airbnb',
									'vrbo'    => 'VRBO',
									'booking' => 'Booking.com',
									'other'   => 'Other',
								);
								$status_badge = array(
									'success' => 'str-badge-success',
									'error'   => 'str-badge-error',
									'pending' => 'str-badge-pending',
									'running' => 'str-badge-running',
								);
								$badge_class = $status_badge[ $feed['sync_status'] ] ?? 'str-badge-pending';
								$last_synced = $feed['last_synced']
									? human_time_diff( strtotime( $feed['last_synced'] ), current_time( 'timestamp' ) ) . ' ago'
									: __( 'Never', 'str-direct-booking' );
							?>
							<tr>
								<td>
									<span class="str-platform-icon str-platform-<?php echo esc_attr( $platform_key ); ?>">
										<?php echo esc_html( strtoupper( substr( $platform_key, 0, 1 ) ) ); ?>
									</span>
									<?php echo esc_html( $platform_labels[ $platform_key ] ?? ucfirst( $platform_key ) ); ?>
								</td>
								<td>
									<span class="str-feed-url" title="<?php echo esc_attr( $feed['feed_url'] ); ?>">
										<?php echo esc_html( $feed['feed_url'] ); ?>
									</span>
									<?php if ( ! empty( $feed['sync_message'] ) && 'error' === $feed['sync_status'] ) : ?>
									<span style="display:block;color:#991b1b;font-size:11px;margin-top:3px">
										<?php echo esc_html( $feed['sync_message'] ); ?>
									</span>
									<?php endif; ?>
								</td>
								<td style="white-space:nowrap;color:#666"><?php echo esc_html( $last_synced ); ?></td>
								<td>
									<span class="str-badge <?php echo esc_attr( $badge_class ); ?>">
										<?php echo esc_html( $feed['sync_status'] ); ?>
									</span>
								</td>
								<td>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
										onsubmit="return confirm('<?php echo esc_js( __( 'Remove this feed? Dates already blocked will remain until cleared.', 'str-direct-booking' ) ); ?>')"
									>
										<input type="hidden" name="action" value="str_delete_calendar_feed" />
										<input type="hidden" name="feed_id" value="<?php echo absint( $feed['id'] ); ?>" />
										<input type="hidden" name="property_id" value="<?php echo absint( $feed['property_id'] ); ?>" />
										<?php wp_nonce_field( 'str_delete_feed_' . $feed['id'] ); ?>
										<button type="submit" class="button button-link-delete" style="font-size:12px">
											<?php esc_html_e( 'Remove', 'str-direct-booking' ); ?>
										</button>
									</form>
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<?php else : ?>
					<p class="str-empty-feeds"><?php esc_html_e( 'No import feeds added yet. Add one below.', 'str-direct-booking' ); ?></p>
					<?php endif; ?>

					<!-- Add feed form -->
					<p style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.4px;color:#555;margin:20px 0 10px">
						<?php esc_html_e( 'Add Import Feed', 'str-direct-booking' ); ?>
					</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="str_add_calendar_feed" />
						<input type="hidden" name="property_id" value="<?php echo $property->ID; ?>" />
						<?php wp_nonce_field( 'str_add_calendar_feed' ); ?>

						<div class="str-add-feed-form">
							<div>
								<label for="str-platform-<?php echo $property->ID; ?>"><?php esc_html_e( 'Platform', 'str-direct-booking' ); ?></label>
								<select id="str-platform-<?php echo $property->ID; ?>" name="platform">
									<option value="airbnb">Airbnb</option>
									<option value="vrbo">VRBO</option>
									<option value="booking">Booking.com</option>
									<option value="other"><?php esc_html_e( 'Other', 'str-direct-booking' ); ?></option>
								</select>
							</div>
							<div class="str-url-col" style="grid-column:span 2">
								<label for="str-feed-url-<?php echo $property->ID; ?>"><?php esc_html_e( 'iCal Feed URL (.ics)', 'str-direct-booking' ); ?></label>
								<input
									type="url"
									id="str-feed-url-<?php echo $property->ID; ?>"
									name="feed_url"
									placeholder="https://www.airbnb.com/calendar/ical/..."
									required
									style="width:100%"
								/>
							</div>
							<div style="padding-top:0">
								<label style="visibility:hidden">submit</label>
								<button type="submit" class="button button-primary">
									<?php esc_html_e( 'Add & Sync', 'str-direct-booking' ); ?>
								</button>
							</div>
						</div>

						<p class="description" style="margin-top:8px">
							<?php
							printf(
								/* translators: 1: Airbnb help, 2: VRBO help */
								esc_html__( 'Find your iCal URL in: %1$s or %2$s', 'str-direct-booking' ),
								'<strong>' . esc_html__( 'Airbnb → Listing → Availability → Export calendar', 'str-direct-booking' ) . '</strong>',
								'<strong>' . esc_html__( 'VRBO → Calendar → Import/Export → Export', 'str-direct-booking' ) . '</strong>'
							);
							?>
						</p>
					</form>

				</div><!-- .str-sync-card-body -->
			</div><!-- .str-sync-card -->

			<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Build the page URL with optional query params.
	 *
	 * @param array $args Query args to add.
	 * @return string
	 */
	private function page_url( array $args = array() ): string {
		return add_query_arg(
			array_merge( array( 'page' => 'str-booking-calendar-sync' ), $args ),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Read query-string notice flags and return a [type, message] array.
	 *
	 * @return array|null
	 */
	private function get_notice(): ?array {
		if ( isset( $_GET['added'] ) ) {
			return array( 'type' => 'success', 'message' => __( 'Feed added and synced successfully.', 'str-direct-booking' ) );
		}
		if ( isset( $_GET['deleted'] ) ) {
			return array( 'type' => 'success', 'message' => __( 'Feed removed.', 'str-direct-booking' ) );
		}
		if ( isset( $_GET['synced'] ) ) {
			$count = absint( $_GET['synced'] );
			return array(
				'type'    => 'success',
				'message' => sprintf(
					/* translators: %d: number of feeds synced */
					_n( 'Synced %d feed.', 'Synced %d feeds.', $count, 'str-direct-booking' ),
					$count
				),
			);
		}
		if ( isset( $_GET['error'] ) ) {
			$errors = array(
				'missing_fields' => __( 'Please fill in all required fields.', 'str-direct-booking' ),
				'invalid_url'    => __( 'The feed URL is not a valid URL.', 'str-direct-booking' ),
				'db_error'       => __( 'Could not save the feed. Check your database.', 'str-direct-booking' ),
			);
			$msg = $errors[ $_GET['error'] ] ?? __( 'An error occurred.', 'str-direct-booking' );
			return array( 'type' => 'error', 'message' => $msg );
		}

		return null;
	}
}
