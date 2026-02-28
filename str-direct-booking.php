<?php
/**
 * Plugin Name:       STR Direct Booking
 * Plugin URI:        https://github.com/str-direct-booking/plugin
 * Description:       Enable STR hosts to accept direct bookings, sync calendars with Airbnb/VRBO, split payments between co-hosts via Stripe Connect, and retain guest data.
 * Version:           1.0.3
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

define( 'STR_BOOKING_VERSION', '1.0.3' );
define( 'STR_BOOKING_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'STR_BOOKING_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'STR_BOOKING_DB_VERSION', '1.0.0' );
define( 'STR_BOOKING_GITHUB_USER', 'Techarmor-Solutions' );
define( 'STR_BOOKING_GITHUB_REPO', 'STR-Wordpress-Plugin' );

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
