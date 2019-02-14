<?php
/**
 * Project plugin information
 *
 * @package solegraphics
 */

namespace solegraphics\Project;

use WP_CLI;

/**
 * Helper class to make sure plugins are going to be versioned okay in the project
 */
class Plugin_Info {

	/**
	 * Response from the plugin_information api
	 *
	 * @var array
	 */
	public $info = array();

	/**
	 * Slug of plugin being requested
	 *
	 * @var string
	 */
	public $slug = '';

	/**
	 * Desired version to install.
	 *
	 * @var string
	 */
	public $desired_version;

	/**
	 * Get plugin information by slug
	 *
	 * @param string $slug plugin to look up.
	 * @param string $version desired plugin version.
	 */
	public function __construct( $slug, $version ) {
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

		$this->slug            = $slug;
		$this->desired_version = $version;
		$this->info            = plugins_api( 'plugin_information', array( 'slug' => $this->slug ) );
	}

	/**
	 * Check if the plugin information is from wordpress.org
	 *
	 * @return boolean
	 */
	public function is_on_wordpress() {
		return ! $this->is_custom();
	}

	/**
	 * Plugin is activated and provides their own
	 *
	 * @return boolean
	 */
	public function is_custom() {
		// If the api is unaware of the plugin assume it's custom.
		if ( is_wp_error( $this->info ) || $this->is_external() ) {
			return true;
		}

		return false;
	}

	/**
	 * Some custom plugins register their own update endpoints.
	 *
	 * @return boolean
	 */
	public function is_external() {
		if ( ! is_wp_error( $this->info ) ) {
			return isset( $this->info->external ) && $this->info->external;
		}

		return false;
	}

	/**
	 * Check if the provided version is still available for download.
	 * Sometimes wordpress.org will delist a plugin version.
	 *
	 * @param string $version version to check.
	 *
	 * @return boolean
	 */
	public function is_version_available( $version = null ) {
		if ( ! $version ) {
			$version = $this->desired_version;
		}
		// Custom plugin, won't have version information to query.
		if ( $this->is_custom() ) {
			return false;
		}

		// Using latest version.
		if ( $this->info->version === $version ) {
			return true;
		}

		// Not an availble historical version.
		if ( ! isset( $this->info->versions ) || ! array_key_exists( $version, $this->info->versions ) ) {
			return false;
		}

		// An available historical version.
		return true;
	}

	/**
	 * Get the recommended version if provided version is unavailable.
	 *
	 * @param  string $desired_version version to get alternate version for.
	 * @return string will return $desired_version if valid, else returns next closest version.
	 */
	public function get_recommended_verson( $desired_version = null ) {

		if ( $this->is_custom() ) {
			return $this->desired_version;
		}

		if ( ! $desired_version ) {
			$desired_version = $this->desired_version;
		}

		$recommended_version = $desired_version;

		if ( ! $this->is_version_available( $desired_version ) ) {
			// 1. collect all version numbers
			$latest_version = $this->info->version;
			$versions       = array_keys( $this->info->versions );
			$versions[]     = $desired_version;
			$versions[]     = $latest_version;

			// 2. sort the version list so they're in logical, "standardized" version order
			usort(
				$versions,
				function( $a, $b ) {
					return version_compare( $a, $b );
				}
			);

			// 3. use the sorted array to get versions "around" the desired version
			$version_position = array_search( $desired_version, $versions, true );
			$newer_version    = isset( $versions[ $version_position + 1 ] ) ? $versions[ $version_position + 1 ] : false;
			$older_version    = isset( $versions[ $version_position - 1 ] ) ? $versions[ $version_position - 1 ] : false;

			// 4. suggest the appropriate version
			if ( $newer_version && $newer_version !== $desired_version ) {
				$recommended_version = $newer_version;
			} elseif ( isset( $older_version ) && $older_version !== $desired_version ) {
				$recommended_version = $older_version;
			} else {
				$recommended_version = $latest_version;
			}
		}

		return $recommended_version;
	}
}
