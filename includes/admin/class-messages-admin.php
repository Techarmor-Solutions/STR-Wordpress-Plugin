<?php
/**
 * Messages Admin — host inbox for guest-host messaging.
 *
 * Registers the "Messages" submenu page and renders the two-panel inbox UI.
 * All data is fetched via the REST API using the admin nonce.
 *
 * @package STRBooking\Admin
 */

namespace STRBooking\Admin;

use STRBooking\Messaging;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin inbox page for guest–host messages.
 */
class MessagesAdmin {

	/**
	 * @var Messaging
	 */
	private Messaging $messaging;

	public function __construct( Messaging $messaging ) {
		$this->messaging = $messaging;
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
	}

	/**
	 * Register the Messages submenu under STR Booking.
	 */
	public function register_menu(): void {
		$unread = $this->messaging->get_unread_count();
		$label  = __( 'Messages', 'str-direct-booking' );

		if ( $unread > 0 ) {
			$label .= sprintf( ' <span class="awaiting-mod">%d</span>', $unread );
		}

		add_submenu_page(
			'str-booking',
			__( 'Messages', 'str-direct-booking' ),
			$label,
			'manage_options',
			'str-booking-messages',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the two-panel inbox page.
	 */
	public function render_page(): void {
		$api_url    = esc_url( rest_url( 'str-booking/v1' ) );
		$nonce      = wp_create_nonce( 'wp_rest' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$active_bid = isset( $_GET['booking_id'] ) ? absint( $_GET['booking_id'] ) : 0;
		?>
		<div class="wrap" style="max-width:1100px">
			<h1><?php esc_html_e( 'Messages', 'str-direct-booking' ); ?></h1>

			<div id="str-msg-app" style="display:flex;gap:0;border:1px solid #ddd;border-radius:6px;overflow:hidden;background:#fff;min-height:520px">

				<!-- Left panel: conversation list -->
				<div id="str-msg-list" style="width:320px;min-width:320px;border-right:1px solid #ddd;overflow-y:auto">
					<div style="padding:14px 16px;border-bottom:1px solid #eee;font-weight:600;font-size:13px;color:#555">
						<?php esc_html_e( 'All Conversations', 'str-direct-booking' ); ?>
					</div>
					<div id="str-msg-list-inner" style="padding:0">
						<p style="padding:16px;color:#888;font-size:13px"><?php esc_html_e( 'Loading…', 'str-direct-booking' ); ?></p>
					</div>
				</div>

				<!-- Right panel: thread + reply -->
				<div id="str-msg-thread-wrap" style="flex:1;display:flex;flex-direction:column">
					<?php if ( $active_bid ) : ?>
					<div id="str-msg-thread" style="flex:1;overflow-y:auto;padding:20px 24px">
						<p style="color:#888;font-size:13px"><?php esc_html_e( 'Loading messages…', 'str-direct-booking' ); ?></p>
					</div>
					<div id="str-msg-reply-box" style="border-top:1px solid #eee;padding:16px 24px;display:flex;gap:10px;align-items:flex-end">
						<textarea id="str-msg-reply-text" rows="3" placeholder="<?php esc_attr_e( 'Type a reply…', 'str-direct-booking' ); ?>"
							style="flex:1;padding:10px;border:1px solid #ddd;border-radius:5px;font-size:14px;resize:vertical;font-family:inherit"></textarea>
						<button id="str-msg-send-btn" class="button button-primary" style="padding:10px 20px;height:auto">
							<?php esc_html_e( 'Send', 'str-direct-booking' ); ?>
						</button>
					</div>
					<?php else : ?>
					<div style="flex:1;display:flex;align-items:center;justify-content:center;color:#aaa;font-size:15px">
						<?php esc_html_e( 'Select a conversation to view messages', 'str-direct-booking' ); ?>
					</div>
					<?php endif; ?>
				</div>

			</div>
		</div>

		<style>
		.str-msg-conv-item {
			display:block; padding:14px 16px; border-bottom:1px solid #f0f0f0;
			text-decoration:none; color:inherit; transition:background .1s;
			cursor:pointer;
		}
		.str-msg-conv-item:hover, .str-msg-conv-item.is-active { background:#f6f7f7; }
		.str-msg-conv-name { font-weight:600; font-size:13px; color:#1d2327; display:flex; align-items:center; gap:6px; }
		.str-msg-conv-meta { font-size:12px; color:#888; margin-top:2px; }
		.str-msg-conv-preview { font-size:12px; color:#555; margin-top:4px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
		.str-msg-unread-dot { display:inline-block; width:8px; height:8px; background:#2271b1; border-radius:50%; flex-shrink:0; }
		.str-msg-bubble { max-width:75%; padding:10px 14px; border-radius:14px; font-size:14px; line-height:1.5; word-wrap:break-word; margin-bottom:4px; }
		.str-msg-bubble.guest { background:#f0f0f0; color:#1d2327; border-bottom-left-radius:4px; align-self:flex-start; }
		.str-msg-bubble.host  { background:#2271b1; color:#fff; border-bottom-right-radius:4px; align-self:flex-end; }
		.str-msg-bubble-wrap  { display:flex; flex-direction:column; margin-bottom:12px; }
		.str-msg-bubble-wrap.host { align-items:flex-end; }
		.str-msg-time { font-size:11px; color:#aaa; margin-top:2px; }
		</style>

		<script>
		(function () {
			var apiUrl  = <?php echo wp_json_encode( $api_url ); ?>;
			var nonce   = <?php echo wp_json_encode( $nonce ); ?>;
			var activeBid = <?php echo (int) $active_bid; ?>;
			var pageUrl = <?php echo wp_json_encode( admin_url( 'admin.php?page=str-booking-messages' ) ); ?>;

			function apiFetch(path, opts) {
				opts = opts || {};
				opts.headers = Object.assign({ 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' }, opts.headers || {});
				var isGet = !opts.method || opts.method.toUpperCase() === 'GET';
				var url = apiUrl + path + (isGet ? (path.indexOf('?') >= 0 ? '&' : '?') + '_=' + Date.now() : '');
				return fetch(url, opts).then(function(r) {
					if (!r.ok) { return r.json().then(function(d) { return Promise.reject(d); }); }
					return r.json();
				});
			}

			function timeAgo(dateStr) {
				var diff = Math.floor((Date.now() - new Date(dateStr + 'Z').getTime()) / 1000);
				if (diff < 60)   return 'just now';
				if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
				if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
				return Math.floor(diff / 86400) + 'd ago';
			}

			function escHtml(s) {
				return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
			}

			// ---- Conversation list ----
			function loadConversations() {
				apiFetch('/admin/messages').then(function(convs) {
					var el = document.getElementById('str-msg-list-inner');
					if (!convs || !convs.length) {
						el.innerHTML = '<p style="padding:16px;color:#888;font-size:13px">No conversations yet.</p>';
						return;
					}
					el.innerHTML = convs.map(function(c) {
						var isActive = (c.booking_id === activeBid);
						var dot = c.has_unread ? '<span class="str-msg-unread-dot"></span>' : '';
						return '<a href="' + pageUrl + '&booking_id=' + c.booking_id + '" class="str-msg-conv-item' + (isActive ? ' is-active' : '') + '">'
							+ '<div class="str-msg-conv-name">' + dot + escHtml(c.guest_name) + '</div>'
							+ '<div class="str-msg-conv-meta">' + escHtml(c.property_name) + ' &middot; ' + timeAgo(c.last_message_at) + '</div>'
							+ '<div class="str-msg-conv-preview">' + escHtml(c.last_message) + '</div>'
							+ '</a>';
					}).join('');
				});
			}

			// ---- Thread ----
			function loadThread(bid) {
				if (!bid) return;
				apiFetch('/admin/messages/' + bid).then(function(data) {
					var el = document.getElementById('str-msg-thread');
					if (!el) return;
					if (!data || !data.messages) {
						el.innerHTML = '<p style="color:#888;font-size:13px">No messages yet.</p>';
						return;
					}
					// Mark as read
					apiFetch('/admin/messages/' + bid + '/read', { method: 'POST', body: '{}' });

					if (!data.messages.length) {
						el.innerHTML = '<p style="color:#888;font-size:13px">No messages yet.</p>';
						return;
					}

					el.innerHTML = data.messages.map(function(m) {
						var isHost = m.sender === 'host';
						return '<div class="str-msg-bubble-wrap' + (isHost ? ' host' : '') + '">'
							+ '<div class="str-msg-bubble ' + escHtml(m.sender) + '">' + escHtml(m.message) + '</div>'
							+ '<div class="str-msg-time">' + (isHost ? 'You' : escHtml(data.guest_name)) + ' &middot; ' + timeAgo(m.created_at) + '</div>'
							+ '</div>';
					}).join('');
					el.scrollTop = el.scrollHeight;
				});
			}

			// ---- Send reply ----
			var sendBtn = document.getElementById('str-msg-send-btn');
			if (sendBtn) {
				sendBtn.addEventListener('click', function() {
					var textarea = document.getElementById('str-msg-reply-text');
					var msg = textarea.value.trim();
					if (!msg) return;
					sendBtn.disabled = true;
					sendBtn.textContent = 'Sending…';
					apiFetch('/admin/messages/' + activeBid, {
						method: 'POST',
						body: JSON.stringify({ message: msg })
					}).then(function() {
						textarea.value = '';
						sendBtn.disabled = false;
						sendBtn.textContent = 'Send';
						loadThread(activeBid);
						loadConversations();
					}).catch(function(err) {
						sendBtn.disabled = false;
						sendBtn.textContent = 'Send';
						var msg = (err && err.message) ? err.message : 'Could not send reply. Please try again.';
						alert(msg);
					});
				});
			}

			// ---- Init ----
			loadConversations();
			if (activeBid) {
				loadThread(activeBid);
			}
			// Refresh thread and conversation list every 20 seconds
			setInterval(function() {
				loadConversations();
				if (activeBid) { loadThread(activeBid); }
			}, 20000);
		})();
		</script>
		<?php
	}
}
