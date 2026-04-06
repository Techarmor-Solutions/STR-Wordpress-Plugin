<nav>
	<span class="brand"><?= htmlspecialchars( PRODUCT_NAME ) ?></span>
	<a href="index.php" <?= basename( $_SERVER['PHP_SELF'] ) === 'index.php' ? 'class="active"' : '' ?>>Dashboard</a>
	<a href="licenses.php" <?= basename( $_SERVER['PHP_SELF'] ) === 'licenses.php' ? 'class="active"' : '' ?>>Licenses</a>
	<a href="generate.php" <?= basename( $_SERVER['PHP_SELF'] ) === 'generate.php' ? 'class="active"' : '' ?>>Generate Key</a>
	<a href="auth.php?logout=1" class="logout">Log Out</a>
</nav>
