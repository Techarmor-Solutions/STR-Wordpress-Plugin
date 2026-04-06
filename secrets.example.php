<?php
/**
 * Copy this file to secrets.php and fill in the values.
 * secrets.php is never committed to git.
 *
 * Generate HMAC_SECRET:      php -r "echo bin2hex(random_bytes(32));"
 * Generate ADMIN_PASSWORD_HASH: php -r "echo password_hash('yourpassword', PASSWORD_BCRYPT);"
 */

define( 'HMAC_SECRET',          'REPLACE_WITH_64_CHAR_HEX' );
define( 'ADMIN_USERNAME',       'admin' );
define( 'ADMIN_PASSWORD_HASH',  'REPLACE_WITH_BCRYPT_HASH' );
