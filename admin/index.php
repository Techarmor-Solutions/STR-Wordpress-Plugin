<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::require_login();

$db = Database::get();

$counts = $db->query( "SELECT status, COUNT(*) as n FROM licenses GROUP BY status" )->fetchAll();
$by_status = array_column( $counts, 'n', 'status' );
$total     = array_sum( $by_status );
$active    = $by_status['active'] ?? 0;
$revoked   = $by_status['revoked'] ?? 0;
$expired   = $by_status['expired'] ?? 0;

$recent = $db->query(
	"SELECT l.customer_name, l.customer_email, la.site_url, la.last_seen_at
	 FROM license_activations la
	 JOIN licenses l ON l.id = la.license_id
	 ORDER BY la.last_seen_at DESC
	 LIMIT 10"
)->fetchAll();

$flash = Auth::get_flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Dashboard — <?= htmlspecialchars( PRODUCT_NAME ) ?> Licenses</title>
<?php require __DIR__ . '/partials/head.php'; ?>
</head>
<body>
<?php require __DIR__ . '/partials/nav.php'; ?>
<main class="container">
	<?php if ( $flash ) : ?>
		<div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>"><?= htmlspecialchars( $flash['message'] ) ?></div>
	<?php endif; ?>

	<h1>Dashboard</h1>

	<div class="stats">
		<div class="stat-card">
			<div class="stat-value"><?= $total ?></div>
			<div class="stat-label">Total Licenses</div>
		</div>
		<div class="stat-card stat-green">
			<div class="stat-value"><?= $active ?></div>
			<div class="stat-label">Active</div>
		</div>
		<div class="stat-card stat-red">
			<div class="stat-value"><?= $revoked ?></div>
			<div class="stat-label">Revoked</div>
		</div>
		<div class="stat-card stat-gray">
			<div class="stat-value"><?= $expired ?></div>
			<div class="stat-label">Expired</div>
		</div>
	</div>

	<h2 style="margin-top:32px;">Recent Activity</h2>
	<?php if ( empty( $recent ) ) : ?>
		<p style="color:#666;">No activations yet.</p>
	<?php else : ?>
		<table class="data-table">
			<thead><tr><th>Customer</th><th>Email</th><th>Site</th><th>Last Seen</th></tr></thead>
			<tbody>
			<?php foreach ( $recent as $row ) : ?>
				<tr>
					<td><?= htmlspecialchars( $row['customer_name'] ) ?></td>
					<td><?= htmlspecialchars( $row['customer_email'] ) ?></td>
					<td><?= htmlspecialchars( $row['site_url'] ) ?></td>
					<td><?= htmlspecialchars( $row['last_seen_at'] ) ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</main>
</body>
</html>
