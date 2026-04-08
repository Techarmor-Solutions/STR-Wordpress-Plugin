<?php
/**
 * Plugin Name:       STR Direct Booking
 * Plugin URI:        https://github.com/str-direct-booking/plugin
 * Description:       Enable STR hosts to accept direct bookings, sync calendars with Airbnb/VRBO, split payments between co-hosts via Stripe Connect, and retain guest data.
 * Version:           1.2.4
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            STR Direct Booking
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       str-direct-booking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'STR_BOOKING_VERSION', '1.2.4' );
define( 'STR_BOOKING_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'STR_BOOKING_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'STR_BOOKING_DB_VERSION', '1.1.0' );
define( 'STR_BOOKING_GITHUB_USER', 'Techarmor-Solutions' );
define( 'STR_BOOKING_GITHUB_REPO', 'STR-Wordpress-Plugin' );

// License server — update STR_LICENSE_SERVER_URL to your deployment URL.
// Generate STR_LICENSE_SERVER_SECRET with: bin2hex(random_bytes(32))
// Both values must match the license-server/config.php on your server.
define( 'STR_LICENSE_SERVER_URL', 'https://license.techarmorsolutions.com/api' );
define( 'STR_LICENSE_SERVER_SECRET', '480f1c29c40794f985774e02cf63da0d585461c8444e633a6afa73dfca60adf1' );

// Load Composer autoloader
if ( file_exists( STR_BOOKING_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once STR_BOOKING_PLUGIN_DIR . 'vendor/autoload.php';
}

use STRBooking\Database\DatabaseManager;
use STRBooking\STRBooking;

register_activation_hook(
	__FILE__,
	function () {
		DatabaseManager::install();
		STRBooking::activate();
	}
);

register_deactivation_hook(
	__FILE__,
	function () {

		STRBooking::deactivate();
	}
);

add_action(
	'plugins_loaded',
	function () {
		STRBooking::get_instance();
	},
	10
);
