<?php
/**
 * Messaging — guest-host messaging per booking.
 *
 * Handles token generation, message storage, and notification dispatch.
 * Registers the guest-facing messaging page URL via rewrite rules.
 *
 * @package STRBooking
 */

namespace STRBooking;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core messaging service.
 */
class Messaging {

	public function __construct() {
		add_action( 'init', array( $this, 'register_rewrite_rules' ), 10 );
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		add_action( 'template_redirect', array( $this, 'serve_messaging_page' ) );
		add_action( 'str_booking_confirmed', array( $this, 'generate_token' ), 5 );
	}

	/**
	 * Register rewrite rule for the guest messaging page.
	 * URL: /str-messages/{32-char hex token}/
	 */
	public function register_rewrite_rules(): void {
		add_rewrite_rule(
			'^str-messages/([a-f0-9]{32})/?$',
			'index.php?str_message_token=$matches[1]',
			'top'
		);
	}

	/**
	 * Expose str_message_token as a recognised query var.
	 */
	public function add_query_var( array $vars ): array {
		$vars[] = 'str_message_token';
		return $vars;
	}

	/**
	 * Serve the self-contained guest messaging page when the rewrite rule matches.
	 */
	public function serve_messaging_page(): void {
		$token = get_query_var( 'str_message_token' );
		if ( ! $token ) {
			return;
		}

		$booking_id = $this->get_booking_by_token( $token );

		$template = STR_BOOKING_PLUGIN_DIR . 'templates/messaging-page.php';
		if ( ! file_exists( $template ) ) {
			wp_die( 'Messaging page template not found.' );
		}

		// Make booking data available to the template.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		require $template;
		exit;
	}

	// -------------------------------------------------------------------------
	// Token management
	// -------------------------------------------------------------------------

	/**
	 * Generate a unique 32-char hex token for a booking and store it as post meta.
	 * Hooked into str_booking_confirmed so every confirmed booking gets a token.
	 *
	 * @param int $booking_id Booking post ID.
	 * @return string The generated token.
	 */
	public function generate_token( int $booking_id ): string {
		$existing = get_post_meta( $booking_id, 'str_message_token', true );
		if ( $existing ) {
			return $existing;
		}

		$token = bin2hex( random_bytes( 16 ) );
		update_post_meta( $booking_id, 'str_message_token', $token );
		return $token;
	}

	/**
	 * Get the message token for a booking.
	 *
	 * @param int $booking_id Booking post ID.
	 * @return string Token or empty string.
	 */
	public function get_token( int $booking_id ): string {
		return (string) get_post_meta( $booking_id, 'str_message_token', true );
	}

	/**
	 * Look up a booking by its message token.
	 *
	 * @param string $token 32-char hex token.
	 * @return int|null Booking post ID or null if not found.
	 */
	public function get_booking_by_token( string $token ): ?int {
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			return null;
		}

		$posts = get_posts(
			array(
				'post_type'      => 'str_booking',
				'post_status'    => array( 'pending', 'confirmed', 'checked_in', 'checked_out', 'cancelled', 'refunded' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => 'str_message_token',
						'value' => $token,
					),
				),
			)
		);

		return ! empty( $posts ) ? (int) $posts[0] : null;
	}

	/**
	 * Build the guest-facing messaging URL for a booking.
	 *
	 * @param int $booking_id Booking post ID.
	 * @return string Full URL or empty string if no token.
	 */
	public function get_message_url( int $booking_id ): string {
		$token = $this->get_token( $booking_id );
		if ( ! $token ) {
			return '';
		}
		return home_url( '/str-messages/' . $token . '/' );
	}

	// -------------------------------------------------------------------------
	// Message CRUD
	// -------------------------------------------------------------------------

	/**
	 * Retrieve all messages for a booking, oldest first.
	 *
	 * @param int $booking_id Booking post ID.
	 * @return array List of message rows (id, booking_id, sender, message, created_at, read_at).
	 */
	public function get_messages( int $booking_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'str_messages';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, booking_id, sender, message, created_at, read_at
				 FROM {$table}
				 WHERE booking_id = %d
				 ORDER BY created_at ASC",
				$booking_id
			),
			ARRAY_A
		) ?: array();
	}

	/**
	 * Insert a new message and trigger the appropriate notification.
	 *
	 * @param int    $booking_id Booking post ID.
	 * @param string $sender     'guest' or 'host'.
	 * @param string $message    Message text.
	 * @return int New message ID.
	 */
	public function send_message( int $booking_id, string $sender, string $message ): int {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'str_messages',
			array(
				'booking_id' => $booking_id,
				'sender'     => $sender,
				'message'    => $message,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s' )
		);

		$message_id = (int) $wpdb->insert_id;

		// Fire notifications.
		if ( 'guest' === $sender ) {
			do_action( 'str_guest_message_sent', $booking_id, $message );
		} else {
			do_action( 'str_host_message_sent', $booking_id, $message );
		}

		return $message_id;
	}

	/**
	 * Mark messages from the other party as read.
	 *
	 * @param int    $booking_id Booking post ID.
	 * @param string $reader     'guest' or 'host' — messages sent by the OTHER party get marked read.
	 */
	public function mark_read( int $booking_id, string $reader ): void {
		global $wpdb;

		$other_sender = ( 'host' === $reader ) ? 'guest' : 'host';

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}str_messages
				 SET read_at = %s
				 WHERE booking_id = %d AND sender = %s AND read_at IS NULL",
				current_time( 'mysql' ),
				$booking_id,
				$other_sender
			)
		);
	}

	/**
	 * Count bookings that have unread guest messages (for the admin menu badge).
	 *
	 * @return int Number of bookings with unread messages from guests.
	 */
	public function get_unread_count(): int {
		global $wpdb;

		$result = $wpdb->get_var(
			"SELECT COUNT(DISTINCT booking_id)
			 FROM {$wpdb->prefix}str_messages
			 WHERE sender = 'guest' AND read_at IS NULL"
		);

		return (int) $result;
	}
}
