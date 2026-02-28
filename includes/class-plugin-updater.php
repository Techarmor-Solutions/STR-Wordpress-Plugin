<?php
/**
 * PluginUpdater — GitHub Releases-based auto-update integration.
 *
 * Hooks into WordPress update infrastructure to notify site admins
 * when a new version is published as a GitHub Release and allows
 * one-click updates directly from the Plugins list.
 *
 * @package STRBooking
 */

namespace STRBooking;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Connects WordPress plugin update checks to GitHub Releases.
 */
class PluginUpdater {

	/**
	 * Absolute path to the main plugin file.
	 *
	 * @var string
	 */
	private string $plugin_file;

	/**
	 * Plugin slug (directory name without .php).
	 *
	 * @var string
	 */
	private string $plugin_slug;

	/**
	 * Plugin basename (folder/file.php).
	 *
	 * @var string
	 */
	private string $plugin_basename;

	/**
	 * GitHub repository owner.
	 *
	 * @var string
	 */
	private string $github_user;

	/**
	 * GitHub repository name.
	 *
	 * @var string
	 */
	private string $github_repo;

	/**
	 * Transient cache key for the GitHub API response.
	 *
	 * @var string
	 */
	private string $cache_key = 'str_booking_github_release_cache';

	/**
	 * Cache TTL in seconds (12 hours).
	 *
	 * @var int
	 */
	private int $cache_ttl = 43200;

	/**
	 * Constructor.
	 *
	 * @param string $plugin_file Absolute path to the main plugin file.
	 */
	public function __construct( string $plugin_file ) {
		$this->plugin_file     = $plugin_file;
		$this->plugin_slug     = dirname( plugin_basename( $plugin_file ) );
		$this->plugin_basename = plugin_basename( $plugin_file );
		$this->github_user     = defined( 'STR_BOOKING_GITHUB_USER' ) ? STR_BOOKING_GITHUB_USER : '';
		$this->github_repo     = defined( 'STR_BOOKING_GITHUB_REPO' ) ? STR_BOOKING_GITHUB_REPO : '';

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_folder' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache' ), 10, 2 );
		add_filter( 'plugin_action_links_' . $this->plugin_basename, array( $this, 'add_force_check_link' ) );
		add_action( 'admin_init', array( $this, 'handle_force_check' ) );
		add_action( 'admin_notices', array( $this, 'force_check_admin_notice' ) );
		// Inject GitHub auth token into all HTTP requests that target GitHub
		add_filter( 'http_request_args', array( $this, 'maybe_add_github_auth' ), 10, 2 );
	}

	/**
	 * Return the currently-installed version by reading the plugin file header directly.
	 *
	 * Using get_plugin_data() instead of the STR_BOOKING_VERSION constant avoids a
	 * stale-constant problem: after WordPress replaces the plugin files the constant
	 * in the running PHP process still holds the old value, but the file on disk has
	 * already been updated.  Reading the file gives us the true installed version.
	 *
	 * @return string
	 */
	private function get_installed_version(): string {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$data = get_plugin_data( $this->plugin_file, false, false );
		return $data['Version'] ?? STR_BOOKING_VERSION;
	}

	/**
	 * Fetch the latest release data from GitHub, using a transient cache.
	 *
	 * @return array|null Decoded release array or null on failure.
	 */
	public function get_github_release(): ?array {
		$cached = get_transient( $this->cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		if ( empty( $this->github_user ) || empty( $this->github_repo ) ) {
			return null;
		}

		$api_url = sprintf(
			'https://api.github.com/repos/%s/%s/releases/latest',
			rawurlencode( $this->github_user ),
			rawurlencode( $this->github_repo )
		);

		$args = array(
			'headers' => array(
				'User-Agent' => 'WordPress/STR-Direct-Booking',
				'Accept'     => 'application/vnd.github+json',
			),
			'timeout' => 15,
		);

		$token = get_option( 'str_booking_github_token', '' );
		if ( ! empty( $token ) ) {
			$args['headers']['Authorization'] = 'Bearer ' . $token;
		}

		$response = wp_remote_get( $api_url, $args );

		if ( is_wp_error( $response ) ) {
			return null;
		}

		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return null;
		}

		set_transient( $this->cache_key, $data, $this->cache_ttl );

		return $data;
	}

	/**
	 * Inject the GitHub token into requests that target GitHub URLs.
	 *
	 * This ensures the zip download (zipball or release asset) succeeds for
	 * private repositories and avoids anonymous rate-limiting for public ones.
	 *
	 * @param array  $args HTTP request arguments.
	 * @param string $url  Request URL.
	 * @return array Modified arguments.
	 */
	public function maybe_add_github_auth( array $args, string $url ): array {
		if ( ! str_contains( $url, 'github.com' ) && ! str_contains( $url, 'githubusercontent.com' ) ) {
			return $args;
		}

		$token = get_option( 'str_booking_github_token', '' );
		if ( ! empty( $token ) ) {
			if ( ! isset( $args['headers'] ) ) {
				$args['headers'] = array();
			}
			$args['headers']['Authorization'] = 'Bearer ' . $token;
		}

		return $args;
	}

	/**
	 * Inject update data into the WordPress update transient when a newer version exists.
	 *
	 * @param object $transient The update_plugins site transient.
	 * @return object Modified transient.
	 */
	public function check_for_update( object $transient ): object {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_github_release();
		if ( null === $release ) {
			return $transient;
		}

		$tag         = $release['tag_name'] ?? '';
		$new_version = ltrim( $tag, 'v' );

		if ( ! $new_version ) {
			return $transient;
		}

		// Read from the file on disk so we get the true installed version even if
		// the PHP constant is stale (i.e. files just replaced in the same request).
		$installed_version = $this->get_installed_version();

		if ( ! version_compare( $new_version, $installed_version, '>' ) ) {
			// No update needed — make sure we're not lingering in the response list.
			unset( $transient->response[ $this->plugin_basename ] );
			return $transient;
		}

		// Prefer an attached .zip asset; fall back to the GitHub zipball.
		$download_url = $release['zipball_url'] ?? '';
		if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( isset( $asset['name'] ) && str_ends_with( $asset['name'], '.zip' ) ) {
					$download_url = $asset['browser_download_url'] ?? $download_url;
					break;
				}
			}
		}

		$update_data                   = new \stdClass();
		$update_data->slug             = $this->plugin_slug;
		$update_data->plugin           = $this->plugin_basename;
		$update_data->new_version      = $new_version;
		$update_data->url              = $release['html_url'] ?? '';
		$update_data->package          = $download_url;

		$transient->response[ $this->plugin_basename ] = $update_data;

		return $transient;
	}

	/**
	 * Populate the "View Details" popup for this plugin.
	 *
	 * @param mixed  $result Default result.
	 * @param string $action plugins_api action being called.
	 * @param object $args   Arguments passed to plugins_api.
	 * @return mixed Plugin info object or original $result.
	 */
	public function plugin_info( mixed $result, string $action, object $args ): mixed {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || $args->slug !== $this->plugin_slug ) {
			return $result;
		}

		$release = $this->get_github_release();
		if ( null === $release ) {
			return $result;
		}

		$tag     = $release['tag_name'] ?? '';
		$version = ltrim( $tag, 'v' );

		$info               = new \stdClass();
		$info->name         = 'STR Direct Booking';
		$info->slug         = $this->plugin_slug;
		$info->version      = $version;
		$info->author       = 'STR Direct Booking';
		$info->homepage     = $release['html_url'] ?? '';
		$info->requires     = '6.0';
		$info->download_link = $release['zipball_url'] ?? '';
		$info->sections     = array(
			'changelog' => $release['body'] ?? '',
		);

		return $info;
	}

	/**
	 * Rename GitHub's extracted folder to match the plugin slug.
	 *
	 * GitHub extracts zips to `{owner}-{repo}-{commit}/`; WordPress expects
	 * the folder to match the plugin slug so the plugin continues to work
	 * after the update.
	 *
	 * @param string   $source        Current source path.
	 * @param string   $remote_source Remote source directory.
	 * @param object   $upgrader      WP_Upgrader instance.
	 * @param array    $hook_extra    Extra hook data.
	 * @return string Corrected source path.
	 */
	public function fix_source_folder( string $source, string $remote_source, object $upgrader, array $hook_extra ): string {
		if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_basename ) {
			return $source;
		}

		global $wp_filesystem;

		$corrected = trailingslashit( $remote_source ) . trailingslashit( $this->plugin_slug );

		if ( $source === $corrected ) {
			return $source;
		}

		// Remove a stale corrected directory from a previous failed attempt so
		// move() doesn't silently fail because the destination already exists.
		if ( $wp_filesystem->exists( $corrected ) ) {
			$wp_filesystem->delete( $corrected, true );
		}

		if ( $wp_filesystem->move( $source, $corrected ) ) {
			return $corrected;
		}

		return $source;
	}

	/**
	 * Clear cached release data and the WordPress update transient after an update.
	 *
	 * Clearing update_plugins forces WordPress to re-read the newly-installed
	 * plugin version from disk on the very next request, preventing a stale
	 * "update available" entry from being shown immediately after the update.
	 *
	 * @param \WP_Upgrader $upgrader   Upgrader instance.
	 * @param array        $hook_extra Hook extra data.
	 */
	public function clear_cache( \WP_Upgrader $upgrader, array $hook_extra ): void {
		if (
			! isset( $hook_extra['action'], $hook_extra['type'] ) ||
			'update' !== $hook_extra['action'] ||
			'plugin' !== $hook_extra['type']
		) {
			return;
		}

		// Only clear when this specific plugin was part of the update batch.
		$updated_plugins = (array) ( $hook_extra['plugins'] ?? array() );
		if ( ! empty( $updated_plugins ) && ! in_array( $this->plugin_basename, $updated_plugins, true ) ) {
			return;
		}

		// Clear the GitHub API cache so the next check re-fetches.
		delete_transient( $this->cache_key );

		// Clear WordPress's own update transient so it re-reads the installed
		// version from the plugin file on the next request instead of serving
		// the stale comparison result that was computed pre-install.
		delete_site_transient( 'update_plugins' );
	}

	/**
	 * Add a "Force Update Check" link to this plugin's action links on the Plugins screen.
	 *
	 * @param array $links Existing action links.
	 * @return array Modified action links.
	 */
	public function add_force_check_link( array $links ): array {
		$url = wp_nonce_url(
			add_query_arg( 'str_force_update_check', '1', admin_url( 'plugins.php' ) ),
			'str_force_update_check'
		);
		$force_link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Force Update Check', 'str-direct-booking' ) . '</a>';
		array_unshift( $links, $force_link );
		return $links;
	}

	/**
	 * Handle the force-check request: verify nonce + cap, delete transient, redirect.
	 */
	public function handle_force_check(): void {
		if ( ! isset( $_GET['str_force_update_check'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'str-direct-booking' ) );
		}

		check_admin_referer( 'str_force_update_check' );

		delete_transient( $this->cache_key );
		delete_site_transient( 'update_plugins' );

		$redirect = add_query_arg(
			array(
				'str_update_cache_cleared' => '1',
				'paged'                    => isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : false,
			),
			admin_url( 'plugins.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Display an admin notice after a successful force-check cache clear.
	 */
	public function force_check_admin_notice(): void {
		if ( empty( $_GET['str_update_cache_cleared'] ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'plugins' !== $screen->id ) {
			return;
		}

		echo '<div class="notice notice-success is-dismissible"><p>'
			. esc_html__( 'Update cache cleared — WordPress will check for updates shortly.', 'str-direct-booking' )
			. '</p></div>';
	}
}
