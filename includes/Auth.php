<?php
/**
 * Single-user session authentication for the admin dashboard.
 */

if ( ! defined( 'LICENSE_SERVER' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

class Auth {

	/**
	 * Start or resume the session (called at the top of every admin page).
	 */
	public static function start(): void {
		if ( session_status() === PHP_SESSION_NONE ) {
			session_set_cookie_params( [
				'lifetime' => SESSION_LIFETIME,
				'path'     => '/',
				'secure'   => isset( $_SERVER['HTTPS'] ),
				'httponly' => true,
				'samesite' => 'Strict',
			] );
			session_start();
		}
	}

	/**
	 * Returns true if the current session has an authenticated admin.
	 */
	public static function is_logged_in(): bool {
		self::start();
		return ! empty( $_SESSION['admin_authenticated'] ) && $_SESSION['admin_authenticated'] === true;
	}

	/**
	 * Attempt a login with username + password. Returns true on success.
	 */
	public static function login( string $username, string $password ): bool {
		self::start();

		if ( $username !== ADMIN_USERNAME ) {
			return false;
		}

		if ( ! password_verify( $password, ADMIN_PASSWORD_HASH ) ) {
			return false;
		}

		// Regenerate session ID on privilege escalation to prevent fixation.
		session_regenerate_id( true );
		$_SESSION['admin_authenticated'] = true;
		$_SESSION['admin_login_time']    = time();

		return true;
	}

	/**
	 * Destroy the session (logout).
	 */
	public static function logout(): void {
		self::start();
		$_SESSION = [];
		session_destroy();
	}

	/**
	 * Require authentication or redirect to login page.
	 */
	public static function require_login(): void {
		self::check_ip_allowlist();

		if ( ! self::is_logged_in() ) {
			Response::redirect( '../admin/auth.php?next=' . urlencode( $_SERVER['REQUEST_URI'] ?? '' ) );
		}
	}

	/**
	 * Enforce IP allowlist for admin if configured.
	 */
	private static function check_ip_allowlist(): void {
		$allowlist = ADMIN_IP_ALLOWLIST;

		if ( empty( $allowlist ) ) {
			return;
		}

		$ip = $_SERVER['REMOTE_ADDR'] ?? '';

		if ( ! in_array( $ip, $allowlist, true ) ) {
			http_response_code( 403 );
			exit( 'Access denied.' );
		}
	}

	/**
	 * Verify a CSRF token submitted with an admin form.
	 *
	 * @param string $token Submitted token.
	 * @return bool
	 */
	public static function verify_csrf( string $token ): bool {
		self::start();
		$expected = $_SESSION['csrf_token'] ?? '';
		return ! empty( $expected ) && hash_equals( $expected, $token );
	}

	/**
	 * Generate (or retrieve) a CSRF token for the current session.
	 */
	public static function csrf_token(): string {
		self::start();
		if ( empty( $_SESSION['csrf_token'] ) ) {
			$_SESSION['csrf_token'] = bin2hex( random_bytes( 32 ) );
		}
		return $_SESSION['csrf_token'];
	}

	/**
	 * Set a flash message to display on next page load.
	 */
	public static function set_flash( string $type, string $message ): void {
		self::start();
		$_SESSION['flash'] = [ 'type' => $type, 'message' => $message ];
	}

	/**
	 * Read and clear any flash message. Returns null if none.
	 */
	public static function get_flash(): ?array {
		self::start();
		if ( ! empty( $_SESSION['flash'] ) ) {
			$flash = $_SESSION['flash'];
			unset( $_SESSION['flash'] );
			return $flash;
		}
		return null;
	}
}
