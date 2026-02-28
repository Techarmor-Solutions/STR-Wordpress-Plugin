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

		if ( ! version_compare( $new_version, STR_BOOKING_VERSION, '>' ) ) {
			return $transient;
		}

		// Find the attached zip asset, falling back to the zipball.
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

		$info            = new \stdClass();
		$info->name      = 'STR Direct Booking';
		$info->slug      = $this->plugin_slug;
		$info->version   = $version;
		$info->author    = 'STR Direct Booking';
		$info->homepage  = $release['html_url'] ?? '';
		$info->requires  = '6.0';
		$info->sections  = array(
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

		if ( $wp_filesystem->move( $source, $corrected ) ) {
			return $corrected;
		}

		return $source;
	}

	/**
	 * Clear the cached release data after a plugin update completes.
	 *
	 * @param \WP_Upgrader $upgrader   Upgrader instance.
	 * @param array        $hook_extra Hook extra data.
	 */
	public function clear_cache( \WP_Upgrader $upgrader, array $hook_extra ): void {
		if (
			isset( $hook_extra['action'], $hook_extra['type'] ) &&
			'update' === $hook_extra['action'] &&
			'plugin' === $hook_extra['type']
		) {
			delete_transient( $this->cache_key );
		}
	}
}
