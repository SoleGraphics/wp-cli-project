<?php

namespace solegraphics\Project;

use WP_CLI;

class Helpers {
	public static $project_root;

	/**
	 * Output a suggested action
	 *
	 * @param string $msg message to display.
	 * @return void
	 */
	public static function suggest( $msg ) {
		WP_CLI::log( WP_CLI::colorize( '%GSuggest:%n ' . $msg ) );
	}

	/**
	 * Output formatted information
	 *
	 * @param string $msg infomration to display.
	 * @return void
	 */
	public static function inform( $msg ) {
		WP_CLI::log( WP_CLI::colorize( '%9Details:%n ' . $msg ) );
	}

	/**
	 * Is WordPress installed.
	 *
	 * @return boolean
	 */
	public static function core_is_installed() {
		$response = WP_CLI::launch_self(
			'core is-installed',
			[],
			[],
			false,
			true
		);

		return 0 === $response->return_code;
	}

	/**
	 * Get project root path or cwd.
	 *
	 * @return string
	 */
	public static function get_project_root( $file = '' ) {
		if ( self::$project_root ) {
			return self::$project_root . DIRECTORY_SEPARATOR . $file;
		}

		// Figure out where the project should be installed from.
		// only look look upward a short distance.
		$project_file = WP_CLI\Utils\find_file_upward(
			[ Config::$project_file_name, 'wp-load.php' ],
			getcwd(),
			function ( $dir ) {
				static $depth = 0;
				++$depth;
				return $depth > 4;
			}
		);

		if ( $project_file ) {
			self::$project_root = dirname( $project_file );
		} else {
			self::$project_root = getcwd();
		}

		return self::$project_root . DIRECTORY_SEPARATOR . $file;
	}
}
