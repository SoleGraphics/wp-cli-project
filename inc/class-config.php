<?php

namespace solegraphics\Project;

use WP_CLI;
use WP_CLI\Utils;

class Config {
	/**
	 * Loaded configuration object
	 *
	 * @var array
	 */
	public $config = array();

	/**
	 * Actual WP config state.
	 *
	 * @var array
	 */
	private $live_config = array();

	/**
	 * State of loaded configuration.
	 *
	 * @var boolean
	 */
	private $default = true;


	/**
	 * GitIgnore helper class instance
	 *
	 * @var Gitignore
	 */
	public $gitignore;

	/**
	 * Currently installed WordPress version if known.
	 *
	 * @var string
	 */
	protected $installed_version;

	/**
	 * Name of config file.
	 *
	 * @var string
	 */
	public static $project_file_name = 'wp-cli-project.json';

	/**
	 * Location of config file.
	 *
	 * @var string
	 */
	private $project_file;

	/**
	 * Root of project
	 *
	 * @var string
	 */
	public $project_root;


	/**
	 * Singleton instance
	 *
	 * @var Config
	 */
	private static $instance;

	/**
	 * Basic
	 *
	 * @return Config
	 */
	public static function get_instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Prepare class
	 */
	private function __construct() {
		$this->gitignore    = new Gitignore();
		$this->project_file = Helpers::get_project_root( self::$project_file_name );
		$this->load();
	}

	/**
	 * Load config file if it exists, else simple defaults.
	 *
	 * @return array loaded configuration array
	 */
	public function load() {
		if ( ! file_exists( $this->project_file ) ) {
			$this->default = true;
			$this->config  = array(
				'wp_version' => 'latest',
				'theme'      => '',
				'plugins'    => array(),
			);
		} else {
			$contents     = file_get_contents( $this->project_file );
			$this->config = json_decode( $contents, true );

			if ( ! $this->config ) {
				WP_CLI::error( 'Empty Configuration File! Please delete ' . $this->project_file . ' and try again.' );
				exit;
			}

			$this->default = false;
		}

		return $this->config;
	}

	/**
	 * Check if current config is default or loaded from file.
	 *
	 * @return boolean
	 */
	public function is_default() {
		return $this->default;
	}

	/**
	 * Save current configuration to the project
	 *
	 * @return void
	 */
	public function save() {

		WP_CLI::log( 'Scheduled Save' );

		if ( empty( $this->config ) ) {
			$this->load();
		}

		if ( empty( $this->config ) ) {
			/** This shouldn't happen, but we all know how code be :) */
			WP_CLI::error( 'Empty config! Not sure how this happened, but I can\'t save an empty file, sorry :( ' );
			return;
		}

		if ( ! file_exists( $this->project_file ) ) {
			WP_CLI::log( WP_CLI::colorize( 'Creating %c' . $this->project_file . '%n' ) );
		}

		$config_save = $this->config;

		if ( ! $this->default ) {
			$this->get_live_config();
			$this->resolve_plugin_versions();
			$config_save = $this->live_config;
		}

		$fp = fopen( $this->project_file, 'w' );
		fwrite( $fp, json_encode( $config_save, JSON_PRETTY_PRINT ) );
		fclose( $fp );

		$this->config = $config_save;
		$this->gitignore->save();

		WP_CLI::success( 'Project Saved!' );
	}

	/**
	 * Validate and prompt action for plugin issues
	 *
	 * @return void
	 */
	public function resolve_plugin_versions() {
		if ( $this->default ) {
			return;
		}

		if ( empty( $this->live_config ) ) {
			$this->live_config = $this->get_live_config();
		}

		$gitignore = $this->gitignore;

		// Verify plugin config...
		foreach ( $this->live_config['plugins'] as &$plugin ) {
			$slug             = $plugin['slug'];
			$ignore_exception = '!/wp-content/plugins/' . $slug;

			/**
			 * Custom plugin but not in version control.
			 */
			if ( $plugin['custom'] && ! $gitignore->in_ignore( $ignore_exception ) ) {
				if ( 'y' === \cli\choose( "%9Update .gitignore%n to keep %G{$slug}%n", 'yn', 'y' ) ) {
					$gitignore->add( $ignore_exception, 'plugins' );
				}

				continue;
			}

			/**
			 * If recommended is set, this means the current version is no longer
			 * available for download. We have to do something about that by
			 * either saving the current version in the repository, or updating.
			 */
			if ( isset( $plugin['recommended'] ) ) {
				if ( ! $gitignore->in_ignore( $ignore_exception ) ) {
					WP_CLI::warning( WP_CLI::colorize( "%R{$slug} {$plugin['version']}%n is nolonger available for download on wordpress.org." ) );

					$choices = [
						'keep'     => WP_CLI::colorize( 'Keep %C' . $plugin['version'] . '%n and add rule to .gitignore' ),
						'skip'     => WP_CLI::colorize( 'Don\'t save this plugin in the project' ),
						'continue' => 'Ignore for now. I\'ll manage the issue myself.',
					];

					// options for upgrade or updating updating.
					if ( isset( $plugin['latest'] ) ) {
						// possible to update 1 version or upgrade to latest.
						$choices = [
							'upgrade' => WP_CLI::colorize( 'Upgrade to latest %G' . $plugin['latest'] . '%n' ),
							'update'  => WP_CLI::colorize( 'Update to closest %G' . $plugin['recommended'] . '%n' ),
						] + $choices;
					} else {
						// next version is latest version.
						$choices = [
							'update' => WP_CLI::colorize( 'Update to latest version %Gv' . $plugin['recommended'] . '%n' ),
						] + $choices;
					}

					$choice = \cli\menu( $choices, 'update', WP_CLI::colorize( 'Make a choice for how would you like to proceed with %G' . $slug . '%n' ) );

					switch ( $choice ) {
						case 'upgrade':
							Helpers::inform( '%GUpgrading ' . $slug . ' to latest version ' . $plugin['recommended'] . '%n' );
							WP_CLI::launch(
								WP_CLI\Utils\esc_cmd( 'wp plugin update %s', $slug ),
								array(
									'exit_error' => false,
								)
							);
							$plugin['version'] = $plugin['latest'];
							break;

						case 'update':
							$update_plugin = 'wp plugin update %s --version=%s';
							Helpers::inform( '%GUpdating ' . $slug . ' to ' . $plugin['recommended'] . '%n' );
							WP_CLI::launch(
								WP_CLI\Utils\esc_cmd( $update_plugin, $slug, $plugin['recommended'] ),
								array(
									'exit_error' => false,
								)
							);
							$plugin['version'] = $plugin['recommended'];
							break;

						case 'keep':
							Helpers::inform( '%GAdding .gitignore rule%n to keep ' . $slug . ' ' . $plugin['version'] . ' in version control.' );
							$gitignore->add( $ignore_exception );
							break;

						case 'skip':
							Helpers::inform( '%GSkipping ' . $slug . ' ' . $plugin['version'] . '%n. It will not be included with the project.' );
							unset( $new_config['plugins'][ $slug ] );
							break;

						default:
							Helpers::inform( '%gSaving ' . $slug . ' ' . $plugin['version'] . '%n with project. Don\'t forget to resolve version issues.' );
							break;
					}
				}
			}

			// helper field cleanup.
			unset( $plugin['latest'] );
			unset( $plugin['recommended'] );
		}
	}

	/**
	 * Scrape current project to generate a new config object based of current
	 * project conditions. (core version, & plugin versions);
	 *
	 * @return array
	 */
	public function get_live_config() {
		if ( ! empty( $this->live_config ) ) {
			return $this->live_config;
		}

		$config = [
			'wp_version' => $this->installed_version,
			'theme'      => $this->get_active_theme(),
			'plugins'    => $this->get_plugin_array(),
		];

		$this->live_config = $config;

		return $config;
	}

	/**
	 * Get the currently active theme
	 *
	 * @return string
	 */
	private function get_active_theme() {
		if ( $this->default ) {
			return '';
		}
		$theme = get_stylesheet();

		return $theme;
	}


	/**
	 * Gets all plugins, version and if they're active as an array
	 *
	 * @return array
	 */
	private static function get_plugin_array() {
		if ( ! function_exists( 'get_plugins' ) ) {
			/** Bootstrap plugin helpers */
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		/** Bootstrap plugin API so we can do some extra checks on the plugins */
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

		$all_plugins    = get_plugins();
		$plugins_config = array();
		$plugins_path   = str_replace( home_url( '/' ), '', plugins_url() );
		$progress       = \WP_CLI\Utils\make_progress_bar( 'Checking plugins versions for issues', count( $all_plugins ) );

		foreach ( $all_plugins as $plugin_path => $plugin ) {
			$slug        = explode( '/', $plugin_path )[0];
			$plugin_info = new Plugin_Info( $slug, $plugin['Version'] );

			// Store basic plugin info.
			$plugins_config[] = array(
				'slug'    => $slug,
				'version' => $plugin['Version'],
				'active'  => is_plugin_active( $plugin_path ),
				'custom'  => $plugin_info->is_custom(),
			);

			// Wordpress.org plugin with inavlid version.
			if ( $plugin_info->is_on_wordpress() && ! $plugin_info->is_version_available( $plugin['Version'] ) ) {
				$plugins_config[ $slug ]['recommended'] = $plugin_info->get_recommended_verson();

				// if not the latest version, store that too for later use.
				if ( $plugins_config[ $slug ]['recommended'] !== $plugin_info->info->version ) {
					$plugins_config[ $slug ]['latest'] = $plugin_info->info->version;
				}
			}

			$progress->tick();
		}

		$progress->finish();

		usort(
			$plugins_config,
			function( $a, $b ) {
				return strcmp( $a['slug'], $b['slug'] );
			}
		);

		return $plugins_config;
	}

	/**
	 * Get config value.
	 *
	 * @param string $name value to look up.
	 * @return mixed value
	 */
	public function __get( $name ) {
		// Get from config object if exists.
		if ( array_key_exists( $name, $this->config ) ) {
			return $this->config[ $name ];
		}

		// Magic var to get installed version.
		if ( 'installed_version' === $name ) {
			if ( ! $this->installed_version && file_exists( ABSPATH . 'wp-includes/version.php' ) ) {
				include ABSPATH . 'wp-includes/version.php';
				$this->installed_version = $wp_version;
			}

			return $this->installed_version;
		}

		// get local property.
		if ( isset( $this->$name ) ) {
			return $this->$name;
		}

		$trace = debug_backtrace();
		trigger_error(
			'Undefined property via __get(): ' . $name . ' in ' . $trace[0]['file'] . ' on line ' . $trace[0]['line'],
			E_USER_NOTICE
		);
		return null;
	}
}
