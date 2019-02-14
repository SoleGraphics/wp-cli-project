<?php

namespace solegraphics\Project;

use WP_CLI;

class Uninstall {
	/**
	 * Core files to remove.
	 * Wildcards are expanded via get_core_files();
	 *
	 * @var array
	 */
	private static $core_files = [
		'wp-admin',
		'wp-includes',
		'wp-content/plugins/index.php',
		'wp-content/index.php',
		'wp-*.php',
		'index.php',
		'license.txt',
		'readme.html',
		'xmlrpc.php',
	];

	/**
	 * Get all core files as needed
	 *
	 * @return array
	 */
	public static function get_core_files() {
		$files = [];
		foreach ( self::$core_files as $file ) {
			if ( strpos( $file, '*' ) !== false ) {
				$wildcard = array_map(
					function( $item ) {
						return str_replace( Helpers::get_project_root(), '', $item );
					},
					glob( Helpers::get_project_root( $file ) )
				);

				if ( $wildcard ) {
					$files = array_merge( $files, $wildcard );
				}
			} else {
				$files[] = $file;
			}
		}

		return $files;
	}

	/**
	 * Get all plugin files to be removed
	 *
	 * @return array
	 */
	public static function get_plugin_files() {
		$config  = Config::get_instance();
		$plugins = $config->plugins;

		if ( $config->default ) {
			$plugins = array_map(
				function( $item ) {
					return str_replace( Helpers::get_project_root(), '', $item );
				},
				glob( Helpers::get_project_root( 'wp-content/plugins/*' ) )
			);
		} else {
			$plugins = array_filter(
				$plugins,
				function( $item ) {
					return ! $item['custom'];
				}
			);

			$plugins = array_map(
				function( $item ) {
					return 'wp-content/plugins/' . $item['slug'];
				},
				$plugins
			);
		}

		return $plugins;
	}

	/**
	 * Removal status for files passed through the remove method
	 *
	 * @var array
	 */
	public static $results = [];

	/**
	 * Uninstall core files
	 */
	public static function core() {
		foreach ( self::get_core_files() as $file ) {
			if ( ! file_exists( Helpers::get_project_root( $file ) ) ) {
				self::$results[] = [
					'file'   => $file,
					'status' => 'not-found',
				];
			} else {
				self::$results[] = [
					'file'   => $file,
					'status' => self::remove( Helpers::get_project_root( $file ) ) ? 'removed' : 'failed',
				];
			}
		}
	}

	/**
	 * Remove all plugins
	 */
	public static function plugins() {
		foreach ( self::get_plugin_files() as $plugin ) {
			if ( ! file_exists( Helpers::get_project_root( $plugin ) ) ) {
				self::$results[] = [
					'file'   => $plugin,
					'status' => 'not-found',
				];
			} else {
				self::$results[] = [
					'file'   => $plugin,
					'status' => self::remove( Helpers::get_project_root( $plugin ) ) ? 'removed' : 'failed',
				];
			}
		}
	}

	/**
	 * Remove a file or directory.
	 * This is a recursive action and will empty an entire directory.
	 *
	 * @param string $file file or directory.
	 * @return boolean
	 */
	private static function remove( $file ) {
		$result = false;
		if ( file_exists( $file ) ) {
			if ( is_dir( $file ) ) {
				foreach ( scandir( $file ) as $item ) {
					if ( $item == '.' || $item == '..' ) {
						continue;
					}

					if ( ! self::remove( $file . DIRECTORY_SEPARATOR . $item ) ) {
						WP_CLI::warning( 'Unable to remove', $file . DIRECTORY_SEPARATOR . $item );
						return false;
					}
				}

				$result = rmdir( $file );
			} else {
				$result = unlink( $file );
			}
		}

		return $result;
	}
}
