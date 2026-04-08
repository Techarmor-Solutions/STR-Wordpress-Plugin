\
<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::require_login();

$id = (int) ( $_GET['id'] ?? 0 );
if ( ! $id ) {
	Response::redirect( 'licenses.php' );
}

$db   = Database::get();
$stmt = $db->prepare(
	"SELECT l.*, la.site_url, la.last_seen_at, la.plugin_version
	 FROM licenses l
	 LEFT JOIN license_activations la ON la.license_id = l.id
	 WHERE l.id = ?"
);
$stmt->execute( [ $id ] );
$lic = $stmt->fetch();

if ( ! $lic ) {
	Auth::set_flash( 'error', 'License not found.' );
	Response::redirect( 'licenses.php' );
}

// Handle edit form submission.
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['_action'] ) && $_POST['_action'] === 'edit' ) {
	if ( ! Auth::verify_csrf( $_POST['_csrf'] ?? '' ) ) {
		Auth::set_flash( 'error', 'Invalid CSRF token.' );
		Response::redirect( 'license-detail.php?id=' . $id );
	}

	$name    = trim( $_POST['customer_name'] ?? '' );
	$email   = trim( $_POST['customer_email'] ?? '' );
	$expires = trim( $_POST['expires_at'] ?? '' );
	$notes   = trim( $_POST['notes'] ?? '' );

	if ( empty( $name ) ) {
		Auth::set_flash( 'error', 'Customer name is required.' );
		Response::redirect( 'license-detail.php?id=' . $id );
	}

	$expires_val = ( $expires !== '' && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $expires ) ) ? $expires : null;

	$db->prepare(
		"UPDATE licenses SET customer_name=?, customer_email=?, expires_at=?, notes=?, updated_at=? WHERE id=?"
	)->execute( [ $name, $email, $expires_val, $notes ?: null, date( 'Y-m-d H:i:s' ), $id ] );

	audit_log( $id, 'edited_by_admin' );
	Auth::set_flash( 'success', 'License updated.' );
	Response::redirect( 'license-detail.php?id=' . $id );
}

$flash = Auth::get_flash();

// Connection status helpers.
$conn_label = 'Never Connected';
$conn_class = 'badge-expired';
$conn_days  = '';
if ( ! empty( $lic['last_seen_at'] ) ) {
	$diff = (int) round( ( time() - strtotime( $lic['last_seen_at'] ) ) / 86400 );
	if ( $diff <= 2 ) {
		$conn_label = 'Connected';
		$conn_class = 'badge-connected';
		$conn_days  = '(' . $diff . 'd ago)';
	} else {
		$conn_label = 'Disconnected';
		$conn_class = 'badge-disconnected';
		$conn_days  = '(' . $diff . 'd ago)';
	}
}

$status_class = match( $lic['status'] ) {
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
<title><?= htmlspecialchars( $lic['customer_name'] ) ?> — <?= htmlspecialchars( PRODUCT_NAME ) ?></title>
<?php require __DIR__ . '/partials/head.php'; ?>
<style>
.detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
@media (max-width: 720px) { .detail-grid { grid-template-columns: 1fr; } }
.info-card { background: #fff; border-radius: 8px; padding: 24px; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
.info-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f0f2f5; font-size: 14px; }
.info-row:last-child { border-bottom: none; }
.info-label { color: #666; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: .4px; }
.info-value { color: #1a1a2e; text-align: right; }
.key-box { background: #fff; border-radius: 8px; padding: 24px; box-shadow: 0 1px 4px rgba(0,0,0,.08); margin-bottom: 20px; }
.key-box .key-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: #666; margin-bottom: 12px; }
.key-row { display: flex; align-items: center; gap: 12px; background: #f8f9fa; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px 16px; }
.key-text { font-family: monospace; font-size: 16px; letter-spacing: 1px; flex: 1; }
.badge-connected    { background: #e6f4ea; color: #137333; }
.badge-disconnected { background: #fff3e0; color: #b35c00; }
.actions-card { background: #fff; border-radius: 8px; padding: 24px; box-shadow: 0 1px 4px rgba(0,0,0,.08); margin-bottom: 20px; }
.actions-row { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; margin-bottom: 8px; }
.action-hint { font-size: 12px; color: #888; }
</style>
</head>
<body>
<?php require __DIR__ . '/partials/nav.php'; ?>
<main class="container">
	<?php if ( $flash ) : ?>
		<div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>"><?= htmlspecialchars( $flash['message'] ) ?></div>
	<?php endif; ?>

	<div style="margin-bottom:16px;">
		<a href="licenses.php" style="font-size:13px;color:#2271b1;text-decoration:none;">← Back to Licenses</a>
	</div>

	<div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap;">
		<h1 style="margin:0;"><?= htmlspecialchars( $lic['customer_name'] ) ?></h1>
		<span class="badge <?= $status_class ?>"><?= strtoupper( htmlspecialchars( $lic['status'] ) ) ?></span>
		<?php if ( ! empty( $lic['last_seen_at'] ) ) : ?>
			<span class="badge <?= $conn_class ?>"><?= $conn_label . ( $conn_days ? ' ' . $conn_days : '' ) ?></span>
		<?php endif; ?>
	</div>

	<!-- License Key -->
	<div class="key-box">
		<div class="key-label">License Key</div>
		<div class="key-row">
			<span class="key-text" id="key-display"><?= htmlspecialchars( LicenseKey::mask( $lic['license_key'] ) ) ?></span>
			<button type="button" id="key-toggle" onclick="toggleKey()" title="Show/hide key" style="background:none;border:none;cursor:pointer;font-size:18px;">👁</button>
			<button type="button" onclick="copyFull()" title="Copy full key" style="background:#e2e8f0;border:none;cursor:pointer;padding:6px 12px;border-radius:4px;font-size:12px;font-weight:600;" id="copy-btn">⎘ Copy</button>
		</div>
	</div>

	<!-- Info + Edit -->
	<div class="detail-grid">
		<div class="info-card">
			<h2>Connection Info</h2>
			<div class="info-row"><span class="info-label">Site URL</span><span class="info-value"><?= htmlspecialchars( $lic['site_url'] ?? '—' ) ?></span></div>
			<div class="info-row"><span class="info-label">Last Seen</span><span class="info-value"><?= htmlspecialchars( $lic['last_seen_at'] ?? '—' ) ?></span></div>
			<div class="info-row"><span class="info-label">Plugin Version</span><span class="info-value"><?= htmlspecialchars( $lic['plugin_version'] ?? '—' ) ?></span></div>
			<div class="info-row"><span class="info-label">Expires</span><span class="info-value"><?= htmlspecialchars( $lic['expires_at'] ?? 'Lifetime' ) ?></span></div>
			<div class="info-row"><span class="info-label">Created</span><span class="info-value"><?= htmlspecialchars( substr( $lic['created_at'], 0, 10 ) ) ?></span></div>
		</div>

		<div class="info-card">
			<h2>Edit Details</h2>
			<form method="post">
				<input type="hidden" name="_action" value="edit">
				<input type="hidden" name="_csrf" value="<?= htmlspecialchars( Auth::csrf_token() ) ?>">
				<div class="form-group">
					<label>Customer Name</label>
					<input type="text" name="customer_name" value="<?= htmlspecialchars( $lic['customer_name'] ) ?>" required>
				</div>
				<div class="form-group">
					<label>Email</label>
					<input type="email" name="customer_email" value="<?= htmlspecialchars( $lic['customer_email'] ) ?>">
				</div>
				<div class="form-group">
					<label>Expiry Date <span style="font-weight:400;color:#888;">(leave blank for lifetime)</span></label>
					<input type="text" name="expires_at" value="<?= htmlspecialchars( $lic['expires_at'] ?? '' ) ?>" placeholder="YYYY-MM-DD">
				</div>
				<div class="form-group">
					<label>Notes</label>
					<textarea name="notes"><?= htmlspecialchars( $lic['notes'] ?? '' ) ?></textarea>
				</div>
				<button type="submit" class="btn btn-primary">Save Changes</button>
			</form>
		</div>
	</div>

	<!-- Actions -->
	<div class="actions-card">
		<h2>Actions</h2>
		<div class="actions-row">
			<?php if ( $lic['status'] === 'active' ) : ?>
				<form method="post" action="revoke.php" onsubmit="return confirm('Revoke this license? Booking features will be disabled on their site within 24 hours.');">
					<input type="hidden" name="license_id" value="<?= (int) $lic['id'] ?>">
					<input type="hidden" name="action" value="revoke">
					<input type="hidden" name="_redirect" value="license-detail.php?id=<?= (int) $lic['id'] ?>">
					<input type="hidden" name="_csrf" value="<?= htmlspecialchars( Auth::csrf_token() ) ?>">
					<button type="submit" class="btn btn-danger">Revoke License</button>
				</form>
				<form method="post" action="revoke.php" onsubmit="return confirm('Archive this license? It will be hidden from the main list.');">
					<input type="hidden" name="license_id" value="<?= (int) $lic['id'] ?>">
					<input type="hidden" name="action" value="archive">
					<input type="hidden" name="_redirect" value="licenses.php">
					<input type="hidden" name="_csrf" value="<?= htmlspecialchars( Auth::csrf_token() ) ?>">
					<button type="submit" class="btn" style="background:#e2e8f0;color:#555;">Archive License</button>
				</form>
			<?php elseif ( $lic['status'] === 'revoked' ) : ?>
				<form method="post" action="revoke.php">
					<input type="hidden" name="license_id" value="<?= (int) $lic['id'] ?>">
					<input type="hidden" name="action" value="restore">
					<input type="hidden" name="_redirect" value="license-detail.php?id=<?= (int) $lic['id'] ?>">
					<input type="hidden" name="_csrf" value="<?= htmlspecialchars( Auth::csrf_token() ) ?>">
					<button type="submit" class="btn btn-restore">Restore License</button>
				</form>
				<form method="post" action="revoke.php" onsubmit="return confirm('Archive this license? It will be hidden from the main list.');">
					<input type="hidden" name="license_id" value="<?= (int) $lic['id'] ?>">
					<input type="hidden" name="action" value="archive">
					<input type="hidden" name="_redirect" value="licenses.php">
					<input type="hidden" name="_csrf" value="<?= htmlspecialchars( Auth::csrf_token() ) ?>">
					<button type="submit" class="btn" style="background:#e2e8f0;color:#555;">Archive License</button>
				</form>
			<?php elseif ( $lic['status'] === 'archived' ) : ?>
				<form method="post" action="revoke.php" onsubmit="return confirm('Unarchive this license? It will be restored to active.');">
					<input type="hidden" name="license_id" value="<?= (int) $lic['id'] ?>">
					<input type="hidden" name="action" value="unarchive">
					<input type="hidden" name="_redirect" value="license-detail.php?id=<?= (int) $lic['id'] ?>">
					<input type="hidden" name="_csrf" value="<?= htmlspecialchars( Auth::csrf_token() ) ?>">
					<button type="submit" class="btn btn-restore">Unarchive License</button>
				</form>
			<?php endif; ?>
		</div>
		<p class="action-hint">
			<?php if ( $lic['status'] === 'active' ) : ?>
				Revoking will disable booking functionality on the connected site within 24 hours.
			<?php elseif ( $lic['status'] === 'archived' ) : ?>
				This license is archived and hidden from the main list.
			<?php endif; ?>
		</p>
	</div>
</main>

<script>
const fullKey = <?= json_encode( $lic['license_key'] ) ?>;
const maskedKey = <?= json_encode( LicenseKey::mask( $lic['license_key'] ) ) ?>;
let revealed = false;

function toggleKey() {
	revealed = ! revealed;
	document.getElementById('key-display').textContent = revealed ? fullKey : maskedKey;
	document.getElementById('key-toggle').textContent = revealed ? '🙈' : '👁';
}

function copyFull() {
	navigator.clipboard.writeText(fullKey).then(() => {
		var btn = document.getElementById('copy-btn');
		btn.textContent = '✓ Copied';
		setTimeout(() => btn.textContent = '⎘ Copy', 1500);
	});
}
</script>
</body>
</html>
