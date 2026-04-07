<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::require_login();

$db             = Database::get();
$search         = trim( $_GET['q'] ?? '' );
$filter         = $_GET['status'] ?? 'all';
$conn_filter    = $_GET['connection'] ?? 'all';
$page           = max( 1, (int) ( $_GET['page'] ?? 1 ) );
$per            = 50;
$offset         = ( $page - 1 ) * $per;
$disconn_secs   = 3 * 24 * 60 * 60;
$disconn_cutoff = date( 'Y-m-d H:i:s', time() - $disconn_secs );

$where  = [];
$params = [];

if ( $search !== '' ) {
	$where[]  = '(l.customer_name LIKE ? OR l.customer_email LIKE ? OR l.license_key LIKE ?)';
	$like     = '%' . $search . '%';
	$params   = array_merge( $params, [ $like, $like, $like ] );
}
if ( in_array( $filter, [ 'active', 'revoked', 'expired' ], true ) ) {
	$where[]  = 'l.status = ?';
	$params[] = $filter;
}
if ( $conn_filter === 'never' ) {
	$where[] = 'la.last_seen_at IS NULL';
} elseif ( $conn_filter === 'connected' ) {
	$where[]  = 'la.last_seen_at >= ?';
	$params[] = $disconn_cutoff;
} elseif ( $conn_filter === 'disconnected' ) {
	$where[]  = 'la.last_seen_at IS NOT NULL AND la.last_seen_at < ?';
	$params[] = $disconn_cutoff;
}

$where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

$total_stmt = $db->prepare( "SELECT COUNT(*) FROM licenses l LEFT JOIN license_activations la ON la.license_id = l.id $where_sql" );
$total_stmt->execute( $params );
$total_rows  = (int) $total_stmt->fetchColumn();
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

function get_connection( array $lic, int $threshold ): array {
	if ( empty( $lic['last_seen_at'] ) ) {
		return [ 'label' => 'Never Connected', 'class' => 'badge-never', 'sub' => '' ];
	}
	$last = strtotime( $lic['last_seen_at'] );
	$days = round( ( time() - $last ) / 86400 );
	if ( ( time() - $last ) > $threshold ) {
		return [ 'label' => 'Disconnected', 'class' => 'badge-disconnected', 'sub' => $days . 'd ago' ];
	}
	return [ 'label' => 'Connected', 'class' => 'badge-connected', 'sub' => $days === 0 ? 'Today' : $days . 'd ago' ];
}

$flash = Auth::get_flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Licenses — <?= htmlspecialchars( PRODUCT_NAME ) ?></title>
<?php require __DIR__ . '/partials/head.php'; ?>
<style>
.badge-never        { background:#94a3b8; color:#fff; }
.badge-connected    { background:#16a34a; color:#fff; }
.badge-disconnected { background:#f97316; color:#fff; }

.licenses-table { width:100%; border-collapse:collapse; table-layout:fixed; }
.licenses-table th,
.licenses-table td { padding:10px 12px; text-align:left; border-bottom:1px solid #e8ecef; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-size:13px; }
.licenses-table thead th { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#888; background:#f8f9fa; }
.licenses-table tbody tr:hover { background:#f5f7fa; }

.col-name    { width:14%; }
.col-key     { width:24%; }
.col-status  { width:9%; }
.col-conn    { width:16%; }
.col-site    { width:22%; }
.col-expires { width:8%; }
.col-actions { width:7%; }

.conn-sub  { display:block; font-size:10px; color:#999; margin-top:1px; }
.name-link { color:#1a1a2e; text-decoration:none; font-weight:500; }
.name-link:hover { color:#2271b1; text-decoration:underline; }
.key-wrap  { display:flex; align-items:center; gap:2px; min-width:0; }
.key-wrap span { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; flex:1; min-width:0; font-family:monospace; font-size:12px; }
.icon-btn  { background:none; border:none; cursor:pointer; padding:0 3px; font-size:13px; flex-shrink:0; }
</style>
</head>
<body>
<?php require __DIR__ . '/partials/nav.php'; ?>
<main class="container">

	<?php if ( $flash ) : ?>
		<div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>"><?= htmlspecialchars( $flash['message'] ) ?></div>
	<?php endif; ?>

	<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
		<h1 style="margin:0;font-size:20px;">Licenses (<?= $total_rows ?>)</h1>
		<a href="generate.php" class="btn btn-primary">+ Generate Key</a>
	</div>

	<form method="get" style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;">
		<input type="text" name="q" value="<?= htmlspecialchars( $search ) ?>" placeholder="Search name, email, key…"
			style="padding:7px 10px;border:1px solid #ddd;border-radius:5px;font-size:13px;flex:1;min-width:160px;">
		<select name="status" style="padding:7px 10px;border:1px solid #ddd;border-radius:5px;font-size:13px;">
			<option value="all"     <?= $filter === 'all'     ? 'selected' : '' ?>>All Statuses</option>
			<option value="active"  <?= $filter === 'active'  ? 'selected' : '' ?>>Active</option>
			<option value="revoked" <?= $filter === 'revoked' ? 'selected' : '' ?>>Revoked</option>
			<option value="expired" <?= $filter === 'expired' ? 'selected' : '' ?>>Expired</option>
		</select>
		<select name="connection" style="padding:7px 10px;border:1px solid #ddd;border-radius:5px;font-size:13px;">
			<option value="all"          <?= $conn_filter === 'all'          ? 'selected' : '' ?>>All Connections</option>
			<option value="connected"    <?= $conn_filter === 'connected'    ? 'selected' : '' ?>>Connected</option>
			<option value="disconnected" <?= $conn_filter === 'disconnected' ? 'selected' : '' ?>>Disconnected</option>
			<option value="never"        <?= $conn_filter === 'never'        ? 'selected' : '' ?>>Never Connected</option>
		</select>
		<button type="submit" class="btn btn-primary" style="padding:7px 14px;font-size:13px;">Filter</button>
		<?php if ( $search || $filter !== 'all' || $conn_filter !== 'all' ) : ?>
			<a href="licenses.php" class="btn" style="background:#e2e8f0;color:#333;padding:7px 14px;font-size:13px;">Clear</a>
		<?php endif; ?>
	</form>

	<?php if ( empty( $licenses ) ) : ?>
		<p style="color:#666;">No licenses found.</p>
	<?php else : ?>
		<table class="licenses-table">
			<thead>
				<tr>
					<th class="col-name">Customer</th>
					<th class="col-key">License Key</th>
					<th class="col-status">Status</th>
					<th class="col-conn">Connection</th>
					<th class="col-site">Site</th>
					<th class="col-expires">Expires</th>
					<th class="col-actions">Actions</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $licenses as $lic ) :
				$conn        = get_connection( $lic, $disconn_secs );
				$badge_class = match( $lic['status'] ) {
					'active'  => 'badge-active',
					'revoked' => 'badge-revoked',
					default   => 'badge-expired',
				};
			?>
				<tr>
					<td class="col-name" title="<?= htmlspecialchars( $lic['customer_name'] ) ?>">
						<a href="license-detail.php?id=<?= (int) $lic['id'] ?>" class="name-link">
							<?= htmlspecialchars( $lic['customer_name'] ) ?>
						</a>
					</td>
					<td class="col-key">
						<div class="key-wrap">
							<span class="key-masked"><?= htmlspecialchars( LicenseKey::mask( $lic['license_key'] ) ) ?></span>
							<span class="key-full" style="display:none;"><?= htmlspecialchars( $lic['license_key'] ) ?></span>
							<button type="button" class="icon-btn" onclick="toggleKey(this)" title="Show/hide">👁</button>
							<button type="button" class="icon-btn" style="color:#2271b1;" onclick="copyKey(this,'<?= htmlspecialchars( $lic['license_key'], ENT_QUOTES ) ?>')" title="Copy key">⎘</button>
						</div>
					</td>
					<td class="col-status"><span class="badge <?= $badge_class ?>"><?= htmlspecialchars( $lic['status'] ) ?></span></td>
					<td class="col-conn">
						<span class="badge <?= $conn['class'] ?>"><?= htmlspecialchars( $conn['label'] ) ?></span>
						<?php if ( $conn['sub'] ) : ?>
							<span class="conn-sub"><?= htmlspecialchars( $conn['sub'] ) ?></span>
						<?php endif; ?>
					</td>
					<td class="col-site" title="<?= htmlspecialchars( $lic['site_url'] ?? '' ) ?>" style="color:#555;">
						<?= htmlspecialchars( $lic['site_url'] ?? '—' ) ?>
					</td>
					<td class="col-expires"><?= htmlspecialchars( $lic['expires_at'] ?? 'Lifetime' ) ?></td>
					<td class="col-actions">
						<?php if ( $lic['status'] === 'active' ) : ?>
							<form method="post" action="revoke.php" style="display:inline;" onsubmit="return confirm('Revoke this license?');">
								<input type="hidden" name="license_id" value="<?= (int) $lic['id'] ?>">
								<input type="hidden" name="action" value="revoke">
								<input type="hidden" name="_csrf" value="<?= htmlspecialchars( Auth::csrf_token() ) ?>">
								<button type="submit" class="btn btn-danger btn-sm">Revoke</button>
							</form>
						<?php elseif ( $lic['status'] === 'revoked' ) : ?>
							<form method="post" action="revoke.php" style="display:inline;">
								<input type="hidden" name="license_id" value="<?= (int) $lic['id'] ?>">
								<input type="hidden" name="action" value="restore">
								<input type="hidden" name="_csrf" value="<?= htmlspecialchars( Auth::csrf_token() ) ?>">
								<button type="submit" class="btn btn-restore btn-sm">Restore</button>
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
					<a href="?page=<?= $p ?>&q=<?= urlencode( $search ) ?>&status=<?= urlencode( $filter ) ?>&connection=<?= urlencode( $conn_filter ) ?>"
					   class="btn btn-sm <?= $p === $page ? 'btn-primary' : '' ?>"
					   style="<?= $p !== $page ? 'background:#e2e8f0;color:#333;' : '' ?>"><?= $p ?></a>
				<?php endfor; ?>
			</div>
		<?php endif; ?>
	<?php endif; ?>
</main>

<script>
function toggleKey(btn) {
	var wrap   = btn.closest('.key-wrap');
	var masked = wrap.querySelector('.key-masked');
	var full   = wrap.querySelector('.key-full');
	if ( full.style.display === 'none' ) {
		masked.style.display = 'none';
		full.style.display   = 'inline';
	} else {
		full.style.display   = 'none';
		masked.style.display = 'inline';
	}
}
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
