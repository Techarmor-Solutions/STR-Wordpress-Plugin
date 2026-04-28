<?php
/**
 * Calendar Sync — iCal import/export.
 *
 * @package STRBooking
 */

namespace STRBooking;

use Kigkonsult\Icalcreator\Vcalendar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles iCal feed imports (Airbnb/VRBO) and exports for external subscriptions.
 */
class CalendarSync {

	/**
	 * @var BookingManager
	 */
	private BookingManager $booking_manager;

	public function __construct( BookingManager $booking_manager ) {
		$this->booking_manager = $booking_manager;

		add_action( 'init', array( $this, 'register_rewrite_rules' ), 10 );
		add_action( 'template_redirect', array( $this, 'serve_ical_feed' ), 10 );
		add_filter( 'cron_schedules', array( $this, 'add_cron_intervals' ), 10 );
		add_action( 'str_calendar_sync_cron', array( $this, 'run_all_imports' ), 10 );
	}

	/**
	 * Register iCal feed rewrite rule.
	 * Note: flush_rewrite_rules() is called only on activation, not here.
	 */
	public function register_rewrite_rules(): void {
		// Accepts both /str-calendar/{id}.ics (preferred, for external platforms) and /str-calendar/{id}/ (legacy).
		add_rewrite_rule(
			'^str-calendar/([0-9]+)\.ics$',
			'index.php?str_ical_property_id=$matches[1]',
			'top'
		);

		add_rewrite_rule(
			'^str-calendar/([0-9]+)/?$',
			'index.php?str_ical_property_id=$matches[1]',
			'top'
		);

		add_filter(
			'query_vars',
			function ( array $vars ): array {
				$vars[] = 'str_ical_property_id';
				return $vars;
			}
		);
	}

	/**
	 * Serve iCal feed when rewrite rule matches.
	 */
	public function serve_ical_feed(): void {
		$property_id = (int) get_query_var( 'str_ical_property_id' );

		if ( ! $property_id ) {
			return;
		}

		$ical = $this->generate_ical_export( $property_id );

		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: inline; filename="str-calendar-' . $property_id . '.ics"' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );

		echo $ical; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Generate iCal export for a property.
	 *
	 * @param int $property_id Property post ID.
	 * @return string iCal content.
	 */
	public function generate_ical_export( int $property_id ): string {
		$property = get_post( $property_id );
		if ( ! $property || 'str_property' !== $property->post_type ) {
			return '';
		}

		$host   = (string) parse_url( get_bloginfo( 'url' ), PHP_URL_HOST );
		$events = array();

		// Confirmed bookings: past 3 months through next 12 months.
		$past_start = date( 'Y-m-d', strtotime( '-3 months' ) );
		$future_end = date( 'Y-m-d', strtotime( '+12 months' ) );
		$bookings   = array_merge(
			$this->booking_manager->get_bookings_for_property( $property_id, $past_start, date( 'Y-m-d' ) ),
			$this->booking_manager->get_bookings_for_property( $property_id, date( 'Y-m-d' ), $future_end )
		);

		foreach ( $bookings as $booking ) {
			if ( is_wp_error( $booking ) ) {
				continue;
			}
			if ( ! in_array( $booking['status'], array( 'confirmed', 'checked_in', 'checked_out' ), true ) ) {
				continue;
			}
			// check_out is already the exclusive end date (day guest departs).
			$events[] = array(
				'uid'   => 'str-booking-' . $booking['id'] . '@' . $host,
				'start' => $booking['check_in'],
				'end'   => $booking['check_out'],
			);
		}

		// Blocked dates from external calendar imports.
		global $wpdb;
		$avail_table    = $wpdb->prefix . 'str_availability';
		$blocked        = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT date FROM {$avail_table}
				WHERE property_id = %d AND status = 'blocked' AND date >= %s",
				$property_id,
				$past_start
			),
			ARRAY_A
		);
		$blocked_ranges = $this->group_consecutive_dates( array_column( $blocked, 'date' ) );

		foreach ( $blocked_ranges as $range ) {
			$end_dt = new \DateTime( $range['end'] );
			$end_dt->modify( '+1 day' ); // DTEND is exclusive in iCal.
			$events[] = array(
				'uid'   => 'str-blocked-' . md5( $range['start'] . $range['end'] . $property_id ) . '@' . $host,
				'start' => $range['start'],
				'end'   => $end_dt->format( 'Y-m-d' ),
			);
		}

		return $this->build_ical_string( $property_id, $events );
	}

	/**
	 * Build a raw RFC 5545 iCal string from a list of blocked-date events.
	 *
	 * @param int   $property_id Property post ID (used for calendar name).
	 * @param array $events      Array of ['uid', 'start' (Y-m-d), 'end' (Y-m-d)] entries.
	 * @return string
	 */
	private function build_ical_string( int $property_id, array $events ): string {
		$CRLF  = "\r\n";
		$now   = gmdate( 'Ymd\THis\Z' );
		$lines = array(
			'BEGIN:VCALENDAR',
			'PRODID:-//STR Direct Booking//Calendar 1.0//EN',
			'VERSION:2.0',
			'CALSCALE:GREGORIAN',
			$this->ical_fold( 'X-WR-CALNAME:' . get_the_title( $property_id ) ),
		);

		foreach ( $events as $event ) {
			$lines[] = 'BEGIN:VEVENT';
			$lines[] = 'UID:' . $event['uid'];
			$lines[] = 'DTSTAMP:' . $now;
			$lines[] = 'DTSTART;VALUE=DATE:' . gmdate( 'Ymd', strtotime( $event['start'] ) );
			$lines[] = 'DTEND;VALUE=DATE:' . gmdate( 'Ymd', strtotime( $event['end'] ) );
			$lines[] = 'SUMMARY:Not available';
			$lines[] = 'TRANSP:OPAQUE';
			$lines[] = 'END:VEVENT';
		}

		$lines[] = 'END:VCALENDAR';
		return implode( $CRLF, $lines ) . $CRLF;
	}

	/**
	 * Fold a single iCal property line to the 75-byte limit per RFC 5545 §3.1.
	 *
	 * @param string $line The full property line (no CRLF).
	 * @return string Folded line (no trailing CRLF — caller adds via implode).
	 */
	private function ical_fold( string $line ): string {
		if ( strlen( $line ) <= 75 ) {
			return $line;
		}
		$folded = substr( $line, 0, 75 );
		$rest   = substr( $line, 75 );
		while ( strlen( $rest ) > 0 ) {
			$folded .= "\r\n " . substr( $rest, 0, 74 );
			$rest    = substr( $rest, 74 );
		}
		return $folded;
	}

	/**
	 * Group consecutive date strings into start/end ranges.
	 *
	 * @param string[] $dates Array of Y-m-d strings.
	 * @return array Array of ['start' => 'Y-m-d', 'end' => 'Y-m-d'] ranges.
	 */
	private function group_consecutive_dates( array $dates ): array {
		if ( empty( $dates ) ) {
			return array();
		}

		sort( $dates );
		$ranges  = array();
		$start   = $dates[0];
		$current = $dates[0];

		for ( $i = 1; $i < count( $dates ); $i++ ) {
			$expected = date( 'Y-m-d', strtotime( $current . ' +1 day' ) );

			if ( $dates[ $i ] === $expected ) {
				$current = $dates[ $i ];
			} else {
				$ranges[] = array( 'start' => $start, 'end' => $current );
				$start    = $dates[ $i ];
				$current  = $dates[ $i ];
			}
		}

		$ranges[] = array( 'start' => $start, 'end' => $current );

		return $ranges;
	}

	/**
	 * Add hourly cron interval.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public function add_cron_intervals( array $schedules ): array {
		if ( ! isset( $schedules['str_hourly'] ) ) {
			$schedules['str_hourly'] = array(
				'interval' => HOUR_IN_SECONDS,
				'display'  => __( 'STR Booking: Every Hour', 'str-direct-booking' ),
			);
		}

		return $schedules;
	}

	/**
	 * Run all calendar feed imports (cron action).
	 */
	public function run_all_imports(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'str_calendar_imports';

		$feeds = $wpdb->get_results(
			"SELECT * FROM {$table} WHERE sync_status != 'disabled' ORDER BY last_synced ASC",
			ARRAY_A
		);

		foreach ( $feeds as $feed ) {
			$this->import_feed( (int) $feed['id'], $feed );
		}
	}

	/**
	 * Import a single iCal feed.
	 *
	 * @param int   $feed_id Feed record ID.
	 * @param array $feed    Feed data from DB.
	 */
	private function import_feed( int $feed_id, array $feed ): void {
		global $wpdb;

		$table       = $wpdb->prefix . 'str_calendar_imports';
		$property_id = (int) $feed['property_id'];

		// Update last_synced timestamp
		$wpdb->update(
			$table,
			array(
				'last_synced' => current_time( 'mysql' ),
				'sync_status' => 'running',
			),
			array( 'id' => $feed_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		$response = wp_remote_get(
			$feed['feed_url'],
			array(
				'timeout'    => 30,
				'user-agent' => 'STRBooking/' . STR_BOOKING_VERSION,
			)
		);

		if ( is_wp_error( $response ) ) {
			$wpdb->update(
				$table,
				array(
					'sync_status'  => 'error',
					'sync_message' => $response->get_error_message(),
				),
				array( 'id' => $feed_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			return;
		}

		$ical_data = wp_remote_retrieve_body( $response );

		if ( empty( $ical_data ) ) {
			$wpdb->update(
				$table,
				array(
					'sync_status'  => 'error',
					'sync_message' => 'Empty response from feed URL.',
				),
				array( 'id' => $feed_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			return;
		}

		try {
			$this->parse_and_block_dates( $property_id, $ical_data, $feed['platform'] ?? 'unknown' );

			$wpdb->update(
				$table,
				array(
					'sync_status'  => 'success',
					'sync_message' => null,
				),
				array( 'id' => $feed_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		} catch ( \Exception $e ) {
			$wpdb->update(
				$table,
				array(
					'sync_status'  => 'error',
					'sync_message' => $e->getMessage(),
				),
				array( 'id' => $feed_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		}
	}

	/**
	 * Parse iCal data and block dates in availability table.
	 *
	 * @param int    $property_id Property ID.
	 * @param string $ical_data   Raw iCal string.
	 * @param string $platform    Source platform label.
	 */
	private function parse_and_block_dates( int $property_id, string $ical_data, string $platform ): void {
		global $wpdb;

		$avail_table = $wpdb->prefix . 'str_availability';

		$calendar = new Vcalendar();
		$calendar->parse( $ical_data );

		$vevents = $calendar->getComponents( Vcalendar::VEVENT );

		foreach ( $vevents as $vevent ) {
			$dtstart = $vevent->getDtstart();
			$dtend   = $vevent->getDtend();

			if ( ! $dtstart || ! $dtend ) {
				continue;
			}

			// Normalize to Y-m-d (date-only), stripping timezone complications
			$start_date = $dtstart->format( 'Y-m-d' );
			$end_date   = $dtend->format( 'Y-m-d' ); // iCal DTEND is exclusive

			$current = new \DateTime( $start_date );
			$end     = new \DateTime( $end_date );

			while ( $current < $end ) {
				$date = $current->format( 'Y-m-d' );

				$wpdb->replace(
					$avail_table,
					array(
						'property_id'  => $property_id,
						'date'         => $date,
						'status'       => 'blocked',
						'block_reason' => $platform,
					),
					array( '%d', '%s', '%s', '%s' )
				);

				$current->modify( '+1 day' );
			}
		}
	}

	/**
	 * Run imports for a single property (used by manual sync button).
	 *
	 * @param int $property_id Property post ID.
	 * @return int Number of feeds processed.
	 */
	public function sync_property( int $property_id ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'str_calendar_imports';

		$feeds = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE property_id = %d AND sync_status != 'disabled'",
				$property_id
			),
			ARRAY_A
		);

		foreach ( $feeds as $feed ) {
			$this->import_feed( (int) $feed['id'], $feed );
		}

		return count( $feeds );
	}

	/**
	 * Delete a calendar feed record.
	 *
	 * @param int $feed_id Feed row ID.
	 * @return bool
	 */
	public function delete_feed( int $feed_id ): bool {
		global $wpdb;

		$result = $wpdb->delete(
			$wpdb->prefix . 'str_calendar_imports',
			array( 'id' => $feed_id ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Add a calendar feed import record.
	 *
	 * @param int    $property_id Property ID.
	 * @param string $feed_url    iCal feed URL.
	 * @param string $platform    Platform name (airbnb, vrbo, etc.).
	 * @return int|\WP_Error Inserted row ID or WP_Error.
	 */
	public function add_feed( int $property_id, string $feed_url, string $platform = 'unknown' ): int|\WP_Error {
		global $wpdb;

		$table  = $wpdb->prefix . 'str_calendar_imports';
		$result = $wpdb->insert(
			$table,
			array(
				'property_id' => $property_id,
				'feed_url'    => $feed_url,
				'platform'    => $platform,
				'sync_status' => 'pending',
			),
			array( '%d', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return new \WP_Error( 'db_error', 'Failed to add calendar feed.' );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Get all calendar feeds for a property.
	 *
	 * @param int $property_id Property ID.
	 * @return array
	 */
	public function get_feeds( int $property_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'str_calendar_imports';

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE property_id = %d ORDER BY id ASC",
				$property_id
			),
			ARRAY_A
		);

		return $results ?: array();
	}
}
