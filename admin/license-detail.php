<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::require_login();

$db = Database::get();
$id = (int) ( $_GET['id'] ?? 0 );

if ( ! $id ) {
	header( 'Location: licenses.php' );
	exit;
}

$stmt = $db->prepare(
	"SELECT l.*, la.site_url, la.last_seen_at, la.plugin_version
	 FROM licenses l
	 LEFT JOIN license_activations la ON la.license_id = l.id
	 WHERE l.id = ?"
);
$stmt->execute( [ $id ] );
$lic = $stmt->fetch();

if ( ! $lic ) {
	header( 'Location: licenses.php' );
	exit;
}

// Handle edit form submission.
$errors  = [];
$success = false;
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['_action'] ) && $_POST['_action'] === 'edit' ) {
	if ( ! Auth::verify_csrf( $_POST['_csrf'] ?? '' ) ) {
		$errors[] = 'Invalid CSRF token.';
	} else {
		$name       = trim( sanitize( $_POST['customer_name'] ?? '' ) );
		$email      = trim( sanitize( $_POST['customer_email'] ?? '' ) );
		$notes      = trim( sanitize( $_POST['notes'] ?? '' ) );
		$expires_at = trim( sanitize( $_POST['expires_at'] ?? '' ) );

		if ( $name === '' )                              $errors[] = 'Customer name is required.';
		if ( $email !== '' && ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) $errors[] = 'Invalid email address.';
		if ( $expires_at !== '' && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $expires_at ) ) $errors[] = 'Expiry must be YYYY-MM-DD or blank for lifetime.';

		if ( empty( $errors ) ) {
			$db->prepare(
				"UPDATE licenses SET customer_name=?, customer_email=?, notes=?, expires_at=?, updated_at=? WHERE id=?"
			)->execute( [
				$name,
				$email,
				$notes ?: null,
				$expires_at ?: null,
				date( 'Y-m-d H:i:s' ),
				$id,
			] );

			// Re-fetch updated record.
			$stmt->execute( [ $id ] );
			$lic     = $stmt->fetch();
			$success = true;
		}
	}
}

function sanitize( string $v ): string {
	return htmlspecialchars_decode( strip_tags( $v ) );
}

$disconn_secs = 3 * 24 * 60 * 60;
function get_connection( array $lic, int $threshold ): array {
	if ( empty( $lic['last_seen_at'] ) ) {
		return [ 'label' => 'Never Connected', 'class' => 'badge-never' ];
	}
	$last = strtotime( $lic['last_seen_at'] );
	$days = round( ( time() - $last ) / 86400 );
	if ( ( time() - $last ) > $threshold ) {
		return [ 'label' => 'Disconnected — ' . $days . 'd ago', 'class' => 'badge-disconnected' ];
	}
	return [ 'label' => 'Connected' . ( $days === 0 ? ' (Today)' : ' (' . $days . 'd ago)' ), 'class' => 'badge-connected' ];
}

$conn        = get_connection( $lic, $disconn_secs );
$badge_class = match( $lic['status'] ) {
	'active'   => 'badge-active',
	'revoked'  => 'badge-revoked',
	'archived' => 'badge-archived',
	default    => 'badge-expired',
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars( $lic['customer_name'] ) ?> — License Detail</title>
<?php require __DIR__ . '/partials/head.php'; ?>
<style>
.badge-never        { background:#94a3b8;color:#fff; }
.badge-connected    { background:#16a34a;color:#fff; }
.badge-disconnected { background:#f97316;color:#fff; }
.detail-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:24px; }
.detail-card { background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px 24px; }
.detail-card h2 { margin:0 0 16px; font-size:14px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#888; }
.info-row { display:flex; justify-content:space-between; align-items:center; padding:7px 0; border-bottom:1px solid #f1f3f5; font-size:13px; }
.info-row:last-child { border-bottom:none; }
.info-label { color:#666; }
.info-value { font-weight:500; color:#1a1a2e; text-align:right; max-width:60%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.key-display { font-family:monospace; background:#f8f9fa; border:1px solid #e2e8f0; border-radius:5px; padding:10px 14px; font-size:14px; display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:16px; }
.key-display span { flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.icon-btn { background:none; border:none; cursor:pointer; padding:0 4px; font-size:15px; flex-shrink:0; }
label { display:block; font-size:13px; font-weight:600; color:#444; margin-bottom:4px; }
input[type=text], input[type=email], input[type=date], textarea {
	width:100%; padding:8px 10px; border:1px solid #ddd; border-radius:5px; font-size:13px; box-sizing:border-box; margin-bottom:14px;
}
textarea { resize:vertical; min-height:70px; }
@media (max-width: 700px) { .detail-grid { grid-template-columns:1fr; } }
</style>
</head>
<body>
<?php require __DIR__ . '/partials/nav.php'; ?>
<main class="container">

	<div style="margin-bottom:16px;">
		<a href="licenses.php" style="color:#2271b1;font-size:13px;text-decoration:none;">← Back to Licenses</a>
	</div>

	<div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;">
		<h1 style="margin:0;font-size:20px;"><?= htmlspecialchars( $lic['customer_name'] ) ?></h1>
		<span class="badge <?= $badge_class ?>"><?= htmlspecialchars( $lic['status'] ) ?></span>
		<span class="badge <?= $conn['class'] ?>"><?= htmlspecialchars( $conn['label'] ) ?></span>
	</div>

	<?php if ( $success ) : ?>
		<div class="alert alert-success" style="margin-bottom:16px;">License updated successfully.</div>
	<?php endif; ?>
	<?php foreach ( $errors as $e ) : ?>
		<div class="alert alert-error" style="margin-bottom:8px;"><?= htmlspecialchars( $e ) ?></div>
	<?php endforeach; ?>

	<!-- Key display -->
	<div class="detail-card" style="margin-bottom:20px;">
		<h2>License Key</h2>
		<div class="key-display">
			<span class="key-masked"><?= htmlspecialchars( LicenseKey::mask( $lic['license_key'] ) ) ?></span>
			<span class="key-full" style="display:none;"><?= htmlspecialchars( $lic['license_key'] ) ?></span>
			<button type="button" class="icon-btn" onclick="toggleKey()" title="Show/hide">👁</button>
			<button type="button" class="icon-btn" style="color:#2271b1;" onclick="copyKey('<?= htmlspecialchars( $lic['license_key'], ENT_QUOTES ) ?>')" title="Copy key">⎘ Copy</button>
		</div>
	</div>

	<div class="detail-grid">
		<!-- Connection info -->
		<div class="detail-card">
			<h2>Connection Info</h2>
			<div class="info-row">
				<span class="info-label">Site URL</span>
				<span class="info-value" title="<?= htmlspecialchars( $lic['site_url'] ?? '' ) ?>">
					<?= htmlspecialchars( $lic['site_url'] ?? '—' ) ?>
				</span>
			</div>
			<div class="info-row">
				<span class="info-label">Last Seen</span>
				<span class="info-value"><?= htmlspecialchars( $lic['last_seen_at'] ?? '—' ) ?></span>
			</div>
			<div class="info-row">
				<span class="info-label">Plugin Version</span>
				<span class="info-value"><?= htmlspecialchars( $lic['plugin_version'] ?? '—' ) ?></span>
			</div>
			<div class="info-row">
				<span class="info-label">Expires</span>
				<span class="info-value"><?= htmlspecialchars( $lic['expires_at'] ?? 'Lifetime' ) ?></span>
			</div>
			<div class="info-row">
				<span class="info-label">Created</span>
				<span class="info-value"><?= htmlspecialchars( substr( $lic['created_at'], 0, 10 ) ) ?></span>
			</div>
		</div>

		<!-- Edit form -->
		<div class="detail-card">
			<h2>Edit Details</h2>
			<form method="post">
				<input type="hidden" name="_action" value="edit">
				<input type="hidden" name="_csrf" value="<?= htmlspecialchars( Auth::csrf_token() ) ?>">

				<label for="customer_name">Customer Name</label>
				<input type="text" id="customer_name" name="customer_name" value="<?= htmlspecialchars( $lic['customer_name'] ) ?>" required>

				<label for="customer_email">Email</label>
				<input type="email" id="customer_email" name="customer_email" value="<?= htmlspecialchars( $lic['customer_email'] ) ?>">

				<label for="expires_at">Expiry Date <span style="font-weight:400;color:#888;">(leave blank for lifetime)</span></label>
				<input type="text" id="expires_at" name="expires_at" value="<?= htmlspecialchars( $lic['expires_at'] ?? '' ) ?>" placeholder="YYYY-MM-DD">

				<label for="notes">Notes</label>
				<textarea id="notes" name="notes"><?= htmlspecialchars( $lic['notes'] ?? '' ) ?></textarea>

				<button type="submit" class="btn btn-primary">Save Changes</button>
			</form>
		</div>
	</div>

	<!-- Actions -->
	<div class="detail-card">
		<h2>Actions</h2>
		<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
		<?php if ( $lic['status'] === 'active' ) : ?>
			<form method="post" action="revoke.php" onsubmit="return confirm('Revoke this license? Booking features will be disabled on their site within 24 hours.');">
				<input type="hidden" name="license_id" value="<?= (int) $lic['id'] ?>">
				<input type="hidden" name="action" value="revoke">
				<input type="hidden" name="_csrf" value="<?= htmlspecialchars( Auth::csrf_token() ) ?>">
				<input type="hidden" name="_redirect" value="license-detail.php?id=<?= (int) $lic['id'] ?>">
				<button type="submit" class="btn btn-danger">Revoke License</button>
			</form>
			<form method="post" action="revoke.php" onsubmit="return confirm('Archive this license? It will be hidden from the main list.');">
				<input type="hidden" name="license_id" value="<?= (int) $lic['id'] ?>">
				<input type="hidden" name="action" value="archive">
				<input type="hidden" name="_csrf" value="<?= htmlspecialchars( Auth::csrf_token() ) ?>">
				<input type="hidden" name="_redirect" value="licenses.php">
				<button type="submit" class="btn" style="background:#e2e8f0;color:#555;">Archive License</button>
			</form>
		<?php elseif ( $lic['status'] === 'revoked' ) : ?>
			<form method="post" action="revoke.php">
				<input type="hidden" name="license_id" value="<?= (int) $lic['id'] ?>">
				<input type="hidden" name="action" value="restore">
				<input type="hidden" name="_csrf" value="<?= htmlspecialchars( Auth::csrf_token() ) ?>">
				<input type="hidden" name="_redirect" value="license-detail.php?id=<?= (int) $lic['id'] ?>">
				<button type="submit" class="btn btn-restore">Restore License</button>
			</form>
			<form method="post" action="revoke.php" onsubmit="return confirm('Archive this license? It will be hidden from the main list.');">
				<input type="hidden" name="license_id" value="<?= (int) $lic['id'] ?>">
				<input type="hidden" name="action" value="archive">
				<input type="hidden" name="_csrf" value="<?= htmlspecialchars( Auth::csrf_token() ) ?>">
				<input type="hidden" name="_redirect" value="licenses.php">
				<button type="submit" class="btn" style="background:#e2e8f0;color:#555;">Archive License</button>
			</form>
		<?php elseif ( $lic['status'] === 'archived' ) : ?>
			<form method="post" action="revoke.php" onsubmit="return confirm('Unarchive this license? It will be restored to active.');">
				<input type="hidden" name="license_id" value="<?= (int) $lic['id'] ?>">
				<input type="hidden" name="action" value="unarchive">
				<input type="hidden" name="_csrf" value="<?= htmlspecialchars( Auth::csrf_token() ) ?>">
				<input type="hidden" name="_redirect" value="license-detail.php?id=<?= (int) $lic['id'] ?>">
				<button type="submit" class="btn btn-restore">Unarchive License</button>
			</form>
		<?php endif; ?>
		</div>
		<?php if ( $lic['status'] === 'active' ) : ?>
			<p style="font-size:12px;color:#888;margin-top:10px;">Revoking will disable booking functionality on the connected site within 24 hours.</p>
		<?php elseif ( $lic['status'] === 'archived' ) : ?>
			<p style="font-size:12px;color:#888;margin-top:10px;">This license is archived and hidden from the main list.</p>
		<?php endif; ?>
	</div>

</main>

<script>
function toggleKey() {
	var masked = document.querySelector('.key-masked');
	var full   = document.querySelector('.key-full');
	if ( full.style.display === 'none' ) {
		masked.style.display = 'none';
		full.style.display   = 'inline';
	} else {
		full.style.display   = 'none';
		masked.style.display = 'inline';
	}
}
function copyKey(key) {
	navigator.clipboard.writeText(key).then(() => {
		var btn = document.querySelector('.icon-btn[onclick^="copyKey"]');
		var orig = btn.textContent;
		btn.textContent = '✓ Copied';
		setTimeout(() => btn.textContent = orig, 1500);
	});
}
</script>
</body>
</html>
