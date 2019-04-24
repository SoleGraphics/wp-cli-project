<?php

namespace solegraphics\Project;

use WP_CLI;
use WP_CLI\Utils;
use WP_CLI\Dispatcher\Subcommand;

/**
 * Manage your WordPress projects without having to worry about WordPress or plugin versions.
 */
class Command extends WP_CLI {

	/**
	 * Config class helper
	 *
	 * @var Config
	 */
	public $config;

	/**
	 * Installed version of WordPress
	 *
	 * @var string
	 */
	public $installed_version;

	/**
	 * Flag to schedule save after command has ended.
	 *
	 * @var boolean
	 */
	public static $schedule_save;

	/**
	 * Skip default wp content when downloading core
	 * Skips hello.php & akismet plugins
	 * Skips twenty[n] themes
	 *
	 * @var boolean
	 */
	public $skip_content = true;

	/**
	 * All commands have access to config.
	 */
	public function __construct() {
		$this->config            = Config::get_instance();
		$this->installed_version = $this->config->installed_version;
	}

	/**
	 * Initialize a new project. Will walk through a new install, or convert an existing WordPress install.
	 *
	 * @when before_wp_load
	 */
	public function init() {
		// Likely a fresh install.
		if ( ! Helpers::core_is_installed() && ! $this->installed_version ) {
			$this->config->config['wp_version'] = \cli\prompt( 'Desired WordPress version', 'latest' );
			self::$schedule_save                = true;
			$this->install();
		} else {
			WP_CLI::success( 'Looks like WordPress is already installed and ready to go.' );
			WP_CLI::runcommand( 'project save' );
		}
	}

	/**
	 * Installs WordPress and plugins based off the project file.
	 *
	 * @when before_wp_load
	 */
	public function install() {
		$this->download_core();
		$this->check_core();

		if ( ! Helpers::core_is_installed() ) {
			$this->install_core();
		}

		WP_CLI::runcommand( 'project install_plugins --skip-plugins' );

		// Dispatch scheduled save if needed.
		if ( self::$schedule_save ) {
			WP_CLI::runcommand( 'project save' );
		}

		$this->enableDebug();
		WP_CLI::success( 'Project Installed!' );
	}

	/**
	 * Install plugins indicated in the config.
	 *
	 * @when after_wp_load
	 */
	public function install_plugins() {
		$install_plugin = 'wp plugin install %s --version=%s --skip-plugins';

		foreach ( $this->config->plugins as $plugin ) {
			if ( ! $plugin['custom'] ) {
				$plugin_info = new Plugin_Info( $plugin['slug'], $plugin['version'] );

				if ( $plugin_info->is_version_available() ) {
					WP_CLI::log( 'Installing Plugin ' . $plugin['slug'] . ' ' . $plugin['version'] );
					WP_CLI::launch( WP_CLI\Utils\esc_cmd( $install_plugin, $plugin['slug'], $plugin['version'], $plugin['active'] ), false );
				} else {
					$recommended_version = $plugin_info->get_recommended_verson();

					WP_CLI::warning( WP_CLI::colorize( "Version %R{$plugin['version']} of {$plugin['slug']}%n is no longer available for download." ) );

					$choices = [
						'update' => 'Install next available version ' . $recommended_version . ' instead',
						'skip'   => 'Skip plugin',
					];

					$choice = \cli\menu( $choices, 'update', 'Choose how you would like to proceed with ' . $plugin['slug'] );

					switch ( $choice ) {
						case 'update':
							WP_CLI::log( 'Installing ' . $plugin['slug'] . ' ' . $recommended_version );
							WP_CLI::launch( WP_CLI\Utils\esc_cmd( $install_plugin . ' --force', $plugin['slug'], $recommended_version ), false );
							self::$schedule_save = true;
							break;

						default:
							WP_CLI::warning( 'Skipping ' . $plugin['slug'] );
							break;
					}
				}
			}
		}

		foreach ( $this->config->plugins as $plugin ) {
			if ( $plugin['active'] ) {
				WP_CLI::log( 'Activating Plugin ' . $plugin['slug'] . ' ' . $plugin['version'] );
				WP_CLI::launch( 'wp plugin activate ' . $plugin['slug'] . ' --skip-plugins' );
			}
		}

		if ( self::$schedule_save ) {
			WP_CLI::runcommand( 'project save' );
		}
	}

	/**
	 * Remove all WordPress core files, and non-custom plugin files.
	 *
	 * @when before_wp_load
	 */
	public function uninstall() {
		if ( $this->config->default ) {
			WP_CLI::confirm( WP_CLI::colorize( '%RNot a cli project%n. Continuing and remove WordPress core and %9all%n plugins?' ) );
		}

		WP_CLI::warning( WP_CLI::colorize( '%RTHIS IS A DESTRUCTIVE PROCESS!%n' ) );
		WP_CLI::warning( 'Files and directories to be removed (relative to ' . Helpers::get_project_root() . '):' );

		$plugin_removal = Uninstall::get_plugin_files();
		$core_removal   = Uninstall::get_core_files();

		array_map(
			function( $item ) {
				WP_CLI::line( $item );
			},
			array_merge( $core_removal, $plugin_removal )
		);

		WP_CLI::confirm( WP_CLI::colorize( '%RDelete WordPress core and non-custom plugins%n?' ) );

		WP_CLI::warning( WP_CLI::colorize( '%RDeleting WordPress core files%n' ) );
		Uninstall::core();

		WP_CLI::warning( WP_CLI::colorize( '%RDeleting plugins%n' ) );
		Uninstall::plugins();

		WP_CLI::success( 'WordPress and plugin files successfully removed.' );

		WP_CLI\Utils\format_items( 'table', Uninstall::$results, [ 'file', 'status' ] );
	}

	/**
	 * Save the current WordPress and plugin state to the project file.
	 *
	 * @when after_wp_load
	 */
	public function save() {
		self::$schedule_save = false;
		Helpers::inform( 'Saving Project...' );
		$this->config->save();
	}

	/**
	 * Upgrade core and all plugins and save the project
	 *
	 * @when after_wp_load
	 */
	public function upgrade() {
		WP_CLI::runcommand( 'core update' );
		WP_CLI::runcommand( 'plugin update --all' );
		$this->save();
	}

	/**
	 * Enable debug log vars in wp-config
	 */
	public function enableDebug() {
		WP_CLI::runcommand( 'config set WP_ENV development --type=constant' );
		WP_CLI::runcommand( 'config set WP_DEBUG true --type=constant --raw' );
		WP_CLI::runcommand( 'config set WP_DEBUG_LOG true --type=constant --raw' );
		WP_CLI::runcommand( 'config set WP_DEBUG_DISPLAY false --type=constant --raw' );
	}


	/**
	 * Get project specified version of WordPress
	 *
	 * @return boolean
	 */
	private function download_core() {
		if ( $this->installed_version ) {
			return false;
		}

		Helpers::inform( '%GDownloading WordPress ' . $this->config->wp_version . '%n' );
		$download = WP_CLI::launch( WP_CLI\Utils\esc_cmd( 'wp core download --version=%s --path=%s --skip-content', $this->config->wp_version, Helpers::get_project_root() ), false, true );

		$this->installed_version  = $this->config->installed_version;
		$this->config->wp_version = $this->installed_version;
		return true;
	}

	/**
	 * Go through standard WordPress install.
	 *
	 * @return void
	 */
	private function install_core() {
		// Not configured... so let's configure it.
		if ( ! file_exists( ABSPATH . '/wp-config.php' ) ) {
			WP_CLI::warning( 'wp-config.php needs to be created.' );
			$create_config = 'wp config create --dbname=%s --dbuser=%s --dbpass=%s --dbhost=%s --dbprefix=%s --skip-check';

			$db_name   = \cli\prompt( 'DB_NAME' );
			$db_user   = \cli\prompt( 'DB_USER' );
			$db_pass   = \cli\prompt( 'DB_PASS' );
			$db_prefix = \cli\prompt( 'DB_PREFIX', 'wp_' );
			$db_host   = \cli\prompt( 'DB_HOST', 'localhost' );

			WP_CLI::launch( WP_CLI\Utils\esc_cmd( $create_config, $db_name, $db_user, $db_pass, $db_host, $db_prefix ), false, true );
		}

		if ( ! Helpers::core_is_installed() ) {
			$db_check = WP_CLI::runcommand(
				'db check',
				[
					'return'     => 'all',
					'exit_error' => false,
					'launch'     => true,
				]
			);

			// zero assumes the check went fine.
			if ( 0 !== $db_check->return_code ) {
				WP_CLI::warning( 'The database needs to be created.' );
				if ( 'y' === \cli\choose( 'Created the database from details in wp-config.php and continue', 'yn', 'y' ) ) {
					WP_CLI::runcommand(
						'db create',
						[
							'exit_error' => false,
							'launch'     => true,
						]
					);
				} else {
					WP_CLI::error( 'Database not created. Unable to continue installation.' );
					exit;
				}
			}

			WP_CLI::success( 'Starting WordPress install process...' );

			$core_install = 'wp core install --url=%s --title=%s --admin_user=%s --admin_email=%s --admin_password=%s';

			$url      = \cli\prompt( 'Site Url' );
			$title    = \cli\prompt( 'Site Title' );
			$user     = \cli\prompt( 'Admin User' );
			$email    = \cli\prompt( 'Admin Email' );
			$password = \cli\prompt( 'Admin Password' );

			WP_CLI::launch( WP_CLI\Utils\esc_cmd( $core_install, $url, $title, $user, $email, $password ), false, true );
		} else {
			WP_CLI::success( 'WordPress appears to already be installed!' );
		}
	}

	/**
	 * Prompt for WordPress core update.
	 *
	 * @return void
	 */
	private function check_core() {
		$update_core = 'wp core update --version=%s --skip-plugins';

		// Verify WordPress version installed.
		switch ( version_compare( $this->installed_version, $this->config->wp_version ) ) {
			case '-1':
				// Behind config.
				Helpers::inform( '%GUpdating to WordPress v' . $this->config->wp_version . '%n to match project settings.' );
				WP_CLI::launch( WP_CLI\Utils\esc_cmd( $update_core, $this->config->wp_version ) );
				WP_CLI::success( '%GUpdated to WordPress v' . $this->config->wp_version . '%n!' );
				break;
			case '0':
				WP_CLI::success( WP_CLI::colorize( '%GWordPress v' . $this->config->wp_version . '%n already installed.' ) );
				// In sync with config.
				break;
			case '1':
				// Somehow you're ahead!
				WP_CLI::warning( WP_CLI::colorize( '%YWordPress v' . $this->installed_version . '%n installed, project configured for %GWordPress v' . $this->config->wp_version . '%n' ) );

				$choices = [
					'update_project' => 'Update project to use installed version ' . $this->installed_version . '.',
					'downgrade_wp'   => 'Downgrade my installed version to ' . $this->config->wp_version . '.',
					'skip'           => 'Do nothing, I\'ll take care of this later.',
				];

				$choice = \cli\menu( $choices, 'update_project', 'Make a choice for how would you like to proceed' );

				switch ( $choice ) {
					case 'update_project':
						self::$schedule_save = true;
						break;
					case 'downgrade_wp':
						Helpers::inform( 'Downgrading WordPress to ' . $this->config->wp_version );
						WP_CLI::launch( WP_CLI\Utils\esc_cmd( $update_core . ' --force', $this->config->wp_version ) );
						WP_CLI::success( WP_CLI::colorize( '%GDowngraded to WordPress v' . $this->config->wp_version . '%n!' ) );
						break;
					case 'skip':
					default:
						Helpers::inform( 'Don\'t forget to resolve the installed version of WordPress discrepancy.' );
						break;
				}

				break;
		}
	}
}
