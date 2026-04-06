<?php
require_once __DIR__ . '/../includes/bootstrap.php';

Auth::start();

// Handle logout.
if ( isset( $_GET['logout'] ) ) {
	Auth::logout();
	Response::redirect( 'auth.php' );
}

// Handle login form submission.
$error = null;
if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
	$username = trim( $_POST['username'] ?? '' );
	$password = $_POST['password'] ?? '';

	if ( Auth::login( $username, $password ) ) {
		$next = isset( $_GET['next'] ) ? filter_var( $_GET['next'], FILTER_SANITIZE_URL ) : 'index.php';
		// Only allow relative redirects.
		if ( str_starts_with( $next, '/' ) || str_starts_with( $next, 'http' ) ) {
			$next = 'index.php';
		}
		Response::redirect( $next ?: 'index.php' );
	} else {
		$error = 'Invalid username or password.';
	}
}

// If already logged in, redirect to dashboard.
if ( Auth::is_logged_in() ) {
	Response::redirect( 'index.php' );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width,initial-scale=1">
	<title>Login — <?= htmlspecialchars( PRODUCT_NAME ) ?> License Server</title>
	<style>
		* { box-sizing: border-box; }
		body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f1f3f5; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
		.card { background: #fff; border-radius: 8px; padding: 40px; width: 360px; box-shadow: 0 2px 12px rgba(0,0,0,.1); }
		h1 { margin: 0 0 24px; font-size: 20px; color: #1a1a2e; }
		label { display: block; font-size: 13px; font-weight: 600; color: #444; margin-bottom: 4px; }
		input[type=text], input[type=password] { width: 100%; padding: 9px 12px; border: 1px solid #ddd; border-radius: 5px; font-size: 14px; margin-bottom: 16px; }
		button { width: 100%; padding: 10px; background: #2271b1; color: #fff; border: none; border-radius: 5px; font-size: 15px; cursor: pointer; font-weight: 600; }
		button:hover { background: #135e96; }
		.error { background: #fde8e8; color: #c53030; padding: 10px 14px; border-radius: 5px; font-size: 13px; margin-bottom: 16px; }
	</style>
</head>
<body>
<div class="card">
	<h1><?= htmlspecialchars( PRODUCT_NAME ) ?><br><small style="font-weight:400;color:#666;">License Server</small></h1>
	<?php if ( $error ) : ?>
		<div class="error"><?= htmlspecialchars( $error ) ?></div>
	<?php endif; ?>
	<form method="post">
		<label for="username">Username</label>
		<input type="text" id="username" name="username" autocomplete="username" required>
		<label for="password">Password</label>
		<input type="password" id="password" name="password" autocomplete="current-password" required>
		<button type="submit">Sign In</button>
	</form>
</div>
</body>
</html>
