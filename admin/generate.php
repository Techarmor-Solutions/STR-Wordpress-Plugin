<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::require_login();

$generated = [];
$error     = null;

if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
	if ( ! Auth::verify_csrf( $_POST['_csrf'] ?? '' ) ) {
		$error = 'Invalid CSRF token. Please refresh and try again.';
	} else {
		$name    = trim( $_POST['customer_name'] ?? '' );
		$email   = trim( $_POST['customer_email'] ?? '' );
		$notes   = trim( $_POST['notes'] ?? '' );
		$expires = trim( $_POST['expires_at'] ?? '' );
		$count   = max( 1, min( 10, (int) ( $_POST['count'] ?? 1 ) ) );

		if ( empty( $name ) || empty( $email ) ) {
			$error = 'Customer name and email are required.';
		} elseif ( ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			$error = 'Please enter a valid email address.';
		} else {
			$db  = Database::get();
			$now = date( 'Y-m-d H:i:s' );

			for ( $i = 0; $i < $count; $i++ ) {
				$key  = LicenseKey::generate();
				$hash = LicenseKey::hash( $key );

				$db->prepare(
					'INSERT INTO licenses (license_key, key_hash, customer_name, customer_email, status, notes, expires_at, created_at, updated_at)
					 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
				)->execute( [
					$key,
					$hash,
					$name,
					$email,
					'active',
					$notes ?: null,
					$expires ?: null,
					$now,
					$now,
				] );

				$license_id = (int) $db->lastInsertId();
				audit_log( $license_id, 'created', [ 'email' => $email ] );

				$generated[] = $key;
			}
		}
	}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Generate Key — <?= htmlspecialchars( PRODUCT_NAME ) ?></title>
<?php require __DIR__ . '/partials/head.php'; ?>
</head>
<body>
<?php require __DIR__ . '/partials/nav.php'; ?>
<main class="container">
	<h1>Generate License Key</h1>

	<?php if ( ! empty( $generated ) ) : ?>
		<div class="alert alert-success">
			<?= count( $generated ) === 1 ? 'License key generated!' : count( $generated ) . ' license keys generated!' ?>
		</div>
		<div style="margin-bottom:24px;">
			<?php foreach ( $generated as $k ) : ?>
				<div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
					<div class="key-display"><?= htmlspecialchars( $k ) ?></div>
					<button type="button" onclick="copyKey(this, '<?= htmlspecialchars( $k, ENT_QUOTES ) ?>')" class="btn btn-primary btn-sm">Copy</button>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php if ( $error ) : ?>
		<div class="alert alert-error"><?= htmlspecialchars( $error ) ?></div>
	<?php endif; ?>

	<div class="form-card">
		<form method="post">
			<input type="hidden" name="_csrf" value="<?= htmlspecialchars( Auth::csrf_token() ) ?>">

			<div class="form-group">
				<label for="customer_name">Customer Name *</label>
				<input type="text" id="customer_name" name="customer_name" required value="<?= htmlspecialchars( $_POST['customer_name'] ?? '' ) ?>">
			</div>

			<div class="form-group">
				<label for="customer_email">Customer Email *</label>
				<input type="email" id="customer_email" name="customer_email" required value="<?= htmlspecialchars( $_POST['customer_email'] ?? '' ) ?>">
			</div>

			<div class="form-group">
				<label for="expires_at">Expiry Date</label>
				<input type="date" id="expires_at" name="expires_at" value="<?= htmlspecialchars( $_POST['expires_at'] ?? '' ) ?>">
				<div class="hint">Leave blank for a lifetime license.</div>
			</div>

			<div class="form-group">
				<label for="count">Number of Keys</label>
				<input type="number" id="count" name="count" min="1" max="10" value="<?= (int) ( $_POST['count'] ?? 1 ) ?>">
				<div class="hint">Generate 1–10 keys at once (e.g. for bulk sales).</div>
			</div>

			<div class="form-group">
				<label for="notes">Notes (internal)</label>
				<textarea id="notes" name="notes" placeholder="e.g. Paid via Stripe sub_xyz, monthly plan"><?= htmlspecialchars( $_POST['notes'] ?? '' ) ?></textarea>
			</div>

			<button type="submit" class="btn btn-primary">Generate Key(s)</button>
		</form>
	</div>
</main>

<script>
function copyKey(btn, key) {
	navigator.clipboard.writeText(key).then(() => {
		var orig = btn.textContent;
		btn.textContent = '✓ Copied';
		setTimeout(() => btn.textContent = orig, 2000);
	});
}
</script>
</body>
</html>
