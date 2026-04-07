<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::require_login();

$db     = Database::get();
$search = trim( $_GET['q'] ?? '' );
$filter = $_GET['status'] ?? 'all';
$page   = max( 1, (int) ( $_GET['page'] ?? 1 ) );
$per    = 50;
$offset = ( $page - 1 ) * $per;

// Build query.
$where  = [];
$params = [];

if ( $search !== '' ) {
	$where[]  = '(l.customer_name LIKE ? OR l.customer_email LIKE ? OR l.license_key LIKE ?)';
	$like     = '%' . $search . '%';
	$params   = array_merge( $params, [ $like, $like, $like ] );
}
if ( in_array( $filter, [ 'active', 'revoked', 'expired', 'archived' ], true ) ) {
	$where[]  = 'l.status = ?';
	$params[] = $filter;
} else {
	// Default: hide archived.
	$where[]  = "l.status != 'archived'";
}

$where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

$total_stmt = $db->prepare( "SELECT COUNT(*) FROM licenses l $where_sql" );
$total_stmt->execute( $params );
$total_rows = (int) $total_stmt->fetchColumn();
$total_pages = max( 1, (int) ceil( $total_rows / $per ) );

$rows_stmt = $db->prepare(
	"SELECT l.*, la.site_url, la.last_seen_at
	 FROM licenses l
	 LEFT JOIN license_activations la ON la.license_id = l.id
	 $where_sql
	 ORDER BY l.created_at DESC
	 LIMIT $per OFFSET $offset"
);
$rows_stmt->execute( $params );
$licenses = $rows_stmt->fetchAll();

$flash = Auth::get_flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Licenses — <?= htmlspecialchars( PRODUCT_NAME ) ?></title>
<?php require __DIR__ . '/partials/head.php'; ?>
</head>
<body>
<?php require __DIR__ . '/partials/nav.php'; ?>
<main class="container">
	<?php if ( $flash ) : ?>
		<div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>"><?= htmlspecialchars( $flash['message'] ) ?></div>
	<?php endif; ?>

	<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
		<h1 style="margin:0;">Licenses (<?= $total_rows ?>)</h1>
		<a href="generate.php" class="btn btn-primary">+ Generate Key</a>
	</div>

	<form method="get" style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;">
		<input type="text" name="q" value="<?= htmlspecialchars( $search ) ?>" placeholder="Search name, email, key…" style="padding:8px 12px;border:1px solid #ddd;border-radius:5px;font-size:14px;flex:1;min-width:200px;">
		<select name="status" style="padding:8px 12px;border:1px solid #ddd;border-radius:5px;font-size:14px;">
			<option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>All Statuses</option>
			<option value="active"   <?= $filter === 'active'   ? 'selected' : '' ?>>Active</option>
			<option value="revoked"  <?= $filter === 'revoked'  ? 'selected' : '' ?>>Revoked</option>
			<option value="expired"  <?= $filter === 'expired'  ? 'selected' : '' ?>>Expired</option>
			<option value="archived" <?= $filter === 'archived' ? 'selected' : '' ?>>Archived</option>
		</select>
		<button type="submit" class="btn btn-primary">Filter</button>
		<?php if ( $search || $filter !== 'all' ) : ?>
			<a href="licenses.php" class="btn" style="background:#e2e8f0;color:#333;">Clear</a>
		<?php endif; ?>
	</form>

	<?php if ( empty( $licenses ) ) : ?>
		<p style="color:#666;">No licenses found.</p>
	<?php else : ?>
		<table class="data-table">
			<thead>
				<tr>
					<th>Customer</th>
					<th>Email</th>
					<th>License Key</th>
					<th>Status</th>
					<th>Site</th>
					<th>Expires</th>
					<th>Created</th>
					<th>Actions</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $licenses as $lic ) : ?>
				<tr>
					<td><?= htmlspecialchars( $lic['customer_name'] ) ?></td>
					<td><?= htmlspecialchars( $lic['customer_email'] ) ?></td>
					<td style="font-family:monospace;font-size:13px;" title="<?= htmlspecialchars( $lic['license_key'] ) ?>">
						<?= htmlspecialchars( LicenseKey::mask( $lic['license_key'] ) ) ?>
						<button type="button" onclick="copyKey(this, '<?= htmlspecialchars( $lic['license_key'], ENT_QUOTES ) ?>')" style="background:none;border:none;cursor:pointer;color:#2271b1;font-size:12px;padding:0 4px;" title="Copy full key">⎘</button>
					</td>
					<td>
						<?php
						$badge_class = match( $lic['status'] ) {
							'active'   => 'badge-active',
							'revoked'  => 'badge-revoked',
							'archived' => 'badge-archived',
							default    => 'badge-expired',
						};
						?>
						<span class="badge <?= $badge_class ?>"><?= htmlspecialchars( $lic['status'] ) ?></span>
					</td>
					<td style="font-size:12px;color:#555;"><?= htmlspecialchars( $lic['site_url'] ?? '—' ) ?></td>
					<td style="font-size:12px;"><?= htmlspecialchars( $lic['expires_at'] ?? 'Lifetime' ) ?></td>
					<td style="font-size:12px;"><?= htmlspecialchars( substr( $lic['created_at'], 0, 10 ) ) ?></td>
					<td>
						<?php if ( $lic['status'] === 'active' ) : ?>
							<form method="post" action="revoke.php" style="display:inline;" onsubmit="return confirm('Revoke this license? Booking features will be disabled on their site within 24 hours.');">
								<input type="hidden" name="license_id" value="<?= (int) $lic['id'] ?>">
								<input type="hidden" name="action" value="revoke">
								<input type="hidden" name="_csrf" value="<?= htmlspecialchars( Auth::csrf_token() ) ?>">
								<button type="submit" class="btn btn-danger btn-sm">Revoke</button>
							</form>
							<form method="post" action="revoke.php" style="display:inline;" onsubmit="return confirm('Archive this license? It will be hidden from the main list.');">
								<input type="hidden" name="license_id" value="<?= (int) $lic['id'] ?>">
								<input type="hidden" name="action" value="archive">
								<input type="hidden" name="_csrf" value="<?= htmlspecialchars( Auth::csrf_token() ) ?>">
								<button type="submit" class="btn btn-sm" style="background:#e2e8f0;color:#555;">Archive</button>
							</form>
						<?php elseif ( $lic['status'] === 'revoked' ) : ?>
							<form method="post" action="revoke.php" style="display:inline;">
								<input type="hidden" name="license_id" value="<?= (int) $lic['id'] ?>">
								<input type="hidden" name="action" value="restore">
								<input type="hidden" name="_csrf" value="<?= htmlspecialchars( Auth::csrf_token() ) ?>">
								<button type="submit" class="btn btn-restore btn-sm">Restore</button>
							</form>
							<form method="post" action="revoke.php" style="display:inline;" onsubmit="return confirm('Archive this license? It will be hidden from the main list.');">
								<input type="hidden" name="license_id" value="<?= (int) $lic['id'] ?>">
								<input type="hidden" name="action" value="archive">
								<input type="hidden" name="_csrf" value="<?= htmlspecialchars( Auth::csrf_token() ) ?>">
								<button type="submit" class="btn btn-sm" style="background:#e2e8f0;color:#555;">Archive</button>
							</form>
						<?php elseif ( $lic['status'] === 'archived' ) : ?>
							<form method="post" action="revoke.php" style="display:inline;" onsubmit="return confirm('Unarchive this license? It will be restored to active.');">
								<input type="hidden" name="license_id" value="<?= (int) $lic['id'] ?>">
								<input type="hidden" name="action" value="unarchive">
								<input type="hidden" name="_csrf" value="<?= htmlspecialchars( Auth::csrf_token() ) ?>">
								<button type="submit" class="btn btn-restore btn-sm">Unarchive</button>
							</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $total_pages > 1 ) : ?>
			<div style="margin-top:16px;display:flex;gap:8px;align-items:center;">
				<?php for ( $p = 1; $p <= $total_pages; $p++ ) : ?>
					<a href="?page=<?= $p ?>&q=<?= urlencode( $search ) ?>&status=<?= urlencode( $filter ) ?>"
					   class="btn btn-sm <?= $p === $page ? 'btn-primary' : '' ?>"
					   style="<?= $p !== $page ? 'background:#e2e8f0;color:#333;' : '' ?>"><?= $p ?></a>
				<?php endfor; ?>
			</div>
		<?php endif; ?>
	<?php endif; ?>
</main>

<script>
function copyKey(btn, key) {
	navigator.clipboard.writeText(key).then(() => {
		var orig = btn.textContent;
		btn.textContent = '✓';
		setTimeout(() => btn.textContent = orig, 1500);
	});
}
</script>
</body>
</html>
