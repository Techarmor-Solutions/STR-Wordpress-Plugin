<?php
/**
 * Generate an Ed25519 key pair for signing license-server responses.
 *
 * Usage: php bin/generate-signing-key.php
 *
 * SIGNING_SECRET_KEY goes in license-server/config.php on the server only.
 * STR_LICENSE_SERVER_PUBLIC_KEY goes in the plugin's str-direct-booking.php.
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$pair = sodium_crypto_sign_keypair();

echo "SIGNING_SECRET_KEY (server config.php — keep private):\n";
echo bin2hex( sodium_crypto_sign_secretkey( $pair ) ), "\n\n";
echo "STR_LICENSE_SERVER_PUBLIC_KEY (plugin — safe to publish):\n";
echo bin2hex( sodium_crypto_sign_publickey( $pair ) ), "\n";
