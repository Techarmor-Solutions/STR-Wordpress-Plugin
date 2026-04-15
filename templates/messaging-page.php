<?php
/**
 * Guest messaging page — served at /str-messages/{token}/
 *
 * Self-contained HTML page (no WordPress template hierarchy).
 * $booking_id and $token are set by Messaging::serve_messaging_page().
 *
 * @package STRBooking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use STRBooking\STRBooking;

// Resolve booking details from the token passed in the URL.
$token      = get_query_var( 'str_message_token' );
$booking_id = STRBooking::get_instance()->messaging->get_booking_by_token( $token );

if ( ! $booking_id ) {
	wp_die( esc_html__( 'Invalid or expired messaging link.', 'str-direct-booking' ), 404 );
}

$property_id   = (int) get_post_meta( $booking_id, 'str_property_id', true );
$property_name = get_the_title( $property_id ) ?: get_the_title( $booking_id );
$guest_name    = get_post_meta( $booking_id, 'str_guest_name', true );
$check_in      = get_post_meta( $booking_id, 'str_check_in', true );
$check_out     = get_post_meta( $booking_id, 'str_check_out', true );

$check_in_fmt  = $check_in  ? date_i18n( get_option( 'date_format' ), strtotime( $check_in ) )  : '';
$check_out_fmt = $check_out ? date_i18n( get_option( 'date_format' ), strtotime( $check_out ) ) : '';

$api_url = rest_url( 'str-booking/v1' );
$nonce   = wp_create_nonce( 'wp_rest' );
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_locale() ); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php printf( esc_html__( 'Message your host — %s', 'str-direct-booking' ), esc_html( $property_name ) ); ?></title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; color: #1a1a2e; }
.str-msg-header { background: #1a1a2e; color: #fff; padding: 20px 24px; }
.str-msg-header h1 { font-size: 18px; font-weight: 600; margin-bottom: 4px; }
.str-msg-header p  { font-size: 13px; opacity: 0.7; }
.str-msg-wrap  { max-width: 680px; margin: 24px auto; padding: 0 16px 100px; }
.str-msg-thread {
	background: #fff; border-radius: 8px; border: 1px solid #e5e5e5;
	display: flex; flex-direction: column; min-height: 340px;
	max-height: calc(100vh - 280px); overflow-y: auto; padding: 20px;
}
.str-msg-bubble-wrap { display: flex; flex-direction: column; margin-bottom: 14px; }
.str-msg-bubble-wrap.host { align-items: flex-end; }
.str-msg-bubble { max-width: 80%; padding: 10px 14px; border-radius: 14px; font-size: 14px; line-height: 1.55; word-wrap: break-word; }
.str-msg-bubble.guest { background: #f0f0f0; color: #1d2327; border-bottom-left-radius: 4px; align-self: flex-start; }
.str-msg-bubble.host  { background: #1a1a2e; color: #fff; border-bottom-right-radius: 4px; align-self: flex-end; }
.str-msg-time { font-size: 11px; color: #aaa; margin-top: 4px; }
.str-msg-empty { color: #aaa; font-size: 14px; text-align: center; margin: auto; padding: 40px 0; }
.str-msg-compose { background: #fff; border-radius: 8px; border: 1px solid #e5e5e5; margin-top: 12px; padding: 14px; display: flex; gap: 10px; align-items: flex-end; }
.str-msg-compose textarea { flex: 1; border: 1px solid #ddd; border-radius: 6px; padding: 10px; font-size: 14px; font-family: inherit; resize: vertical; min-height: 72px; }
.str-msg-compose textarea:focus { outline: none; border-color: #1a1a2e; }
.str-msg-send { background: #1a1a2e; color: #fff; border: none; border-radius: 6px; padding: 10px 20px; font-size: 14px; font-weight: 600; cursor: pointer; }
.str-msg-send:disabled { opacity: 0.5; cursor: default; }
.str-msg-error { color: #dc2626; font-size: 13px; margin-top: 8px; }
</style>
</head>
<body>

<div class="str-msg-header">
	<h1><?php echo esc_html( $property_name ); ?></h1>
	<p>
		<?php
		if ( $check_in_fmt && $check_out_fmt ) {
			/* translators: 1: check-in date, 2: check-out date */
			printf( esc_html__( 'Check-in: %1$s &nbsp;·&nbsp; Check-out: %2$s', 'str-direct-booking' ), esc_html( $check_in_fmt ), esc_html( $check_out_fmt ) );
		}
		?>
	</p>
</div>

<div class="str-msg-wrap">
	<div id="str-msg-thread" class="str-msg-thread">
		<div class="str-msg-empty" id="str-msg-loading"><?php esc_html_e( 'Loading messages…', 'str-direct-booking' ); ?></div>
	</div>

	<div class="str-msg-compose">
		<textarea id="str-msg-text" placeholder="<?php esc_attr_e( 'Write a message to your host…', 'str-direct-booking' ); ?>"></textarea>
		<button class="str-msg-send" id="str-msg-send"><?php esc_html_e( 'Send', 'str-direct-booking' ); ?></button>
	</div>
	<div id="str-msg-error" class="str-msg-error" style="display:none"></div>
</div>

<script>
(function () {
	var apiUrl = <?php echo wp_json_encode( trailingslashit( $api_url ) ); ?>;
	var token  = <?php echo wp_json_encode( $token ); ?>;
	var guestName = <?php echo wp_json_encode( $guest_name ); ?>;

	function timeAgo(dateStr) {
		var diff = Math.floor((Date.now() - new Date(dateStr + 'Z').getTime()) / 1000);
		if (diff < 60)    return 'just now';
		if (diff < 3600)  return Math.floor(diff / 60) + 'm ago';
		if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
		return Math.floor(diff / 86400) + 'd ago';
	}

	function escHtml(s) {
		return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
	}

	function loadMessages() {
		fetch(apiUrl + 'messages/' + token + '?_=' + Date.now())
			.then(function(r) { return r.json(); })
			.then(function(data) {
				var thread = document.getElementById('str-msg-thread');
				document.getElementById('str-msg-loading').style.display = 'none';

				if (!data || !data.messages) return;

				// Remove old bubbles (keep loading element hidden)
				var old = thread.querySelectorAll('.str-msg-bubble-wrap');
				old.forEach(function(el) { el.remove(); });

				if (!data.messages.length) {
					document.getElementById('str-msg-loading').style.display = 'block';
					document.getElementById('str-msg-loading').textContent = 'No messages yet. Say hello!';
					return;
				}

				data.messages.forEach(function(m) {
					var isHost = m.sender === 'host';
					var wrap = document.createElement('div');
					wrap.className = 'str-msg-bubble-wrap' + (isHost ? ' host' : '');

					var bubble = document.createElement('div');
					bubble.className = 'str-msg-bubble ' + escHtml(m.sender);
					bubble.textContent = m.message;

					var time = document.createElement('div');
					time.className = 'str-msg-time';
					time.textContent = (isHost ? 'Your host' : (guestName || 'You')) + ' · ' + timeAgo(m.created_at);

					wrap.appendChild(bubble);
					wrap.appendChild(time);
					thread.appendChild(wrap);
				});

				thread.scrollTop = thread.scrollHeight;
			})
			.catch(function() {});
	}

	function sendMessage() {
		var textarea = document.getElementById('str-msg-text');
		var btn      = document.getElementById('str-msg-send');
		var errEl    = document.getElementById('str-msg-error');
		var msg      = textarea.value.trim();
		if (!msg) return;

		btn.disabled = true;
		btn.textContent = 'Sending…';
		errEl.style.display = 'none';

		fetch(apiUrl + 'messages/' + token, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ message: msg })
		})
		.then(function(r) {
			if (r.ok) {
				textarea.value = '';
				btn.disabled = false;
				btn.textContent = 'Send';
				loadMessages();
			} else {
				return r.json().then(function(data) {
					btn.disabled = false;
					btn.textContent = 'Send';
					errEl.textContent = (data && data.message) ? data.message : 'Could not send message. Please try again.';
					errEl.style.display = 'block';
				});
			}
		})
		.catch(function() {
			btn.disabled = false;
			btn.textContent = 'Send';
			errEl.textContent = 'Could not send message. Please try again.';
			errEl.style.display = 'block';
		});
	}

	document.getElementById('str-msg-send').addEventListener('click', sendMessage);
	document.getElementById('str-msg-text').addEventListener('keydown', function(e) {
		if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) sendMessage();
	});

	loadMessages();
	// Poll for new replies every 20 seconds
	setInterval(loadMessages, 20000);
})();
</script>
</body>
</html>
