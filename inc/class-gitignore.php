<?php

namespace solegraphics\Project;

use WP_CLI;
use WP_CLI\Utils;

class Gitignore {
	/**
	 * Location of gitignore file.
	 *
	 * @var string
	 */
	private $ignore_file;

	/**
	 * Original source of ignore file
	 *
	 * @var string
	 */
	protected $ignore_src;

	/**
	 * Source as an array of each line to be manipulated
	 *
	 * @var array
	 */
	protected $lines = array();

	/**
	 * Start index of project ignore block in the file
	 *
	 * @var integer
	 */
	private $project_start = 0;

	/**
	 * End index of project ignore block in the file
	 *
	 * @var integer
	 */
	private $project_end = 0;

	/**
	 * Ignore comment structure. These act as markers for easier parsing.
	 *
	 * @var array
	 */
	protected $sections = [
		'start'     => '### BEGIN WordPress Project Ignores ####',
		'end'       => '### END WordPress Project Ignores ####',
		'wordpress' => '# Ignore WordPress',
		'plugins'   => '# Plugins Keep',
		'themes'    => '# Themes Keep',
	];

	/**
	 * Instance the class with an ignore file
	 *
	 * @param string $ignore_path path to ignore file.
	 * @throws Exception File isn't writeable.
	 */
	public function __construct( $ignore_path = null ) {
		if ( $ignore_path ) {
			$this->ignore_file = Helpers::get_project_root( $ignore_path );
		} else {
			$this->ignore_file = Helpers::get_project_root( '.gitignore' );
		}

		if ( $this->load() ) {
			$this->parse_ignore();
		}
	}

	/**
	 * Check gitignore for specific ignore used to make sure specific plugins
	 * have an exception added
	 *
	 * @param string $path ignore to check for.
	 * @return boolean
	 */
	public function in_ignore( $path ) {
		return in_array( $path, $this->lines, true );
	}

	/**
	 * Add an ignore to gitignore. This will only manipulate a specific section.
	 * And will only manipulate without our projcet block of ignores.
	 *
	 * @param string $insertion Path to ignore.
	 * @param string $section Section of project ignore block to add to.
	 * @return boolean true if added
	 */
	public function add( $insertion, $section = 'plugins' ) {
		$lines     = $this->lines;
		$base_path = '';

		if ( ! array_key_exists( $section, $this->sections ) ) {
			WP_CLI::debug( "Unable to add to ignore file. Unknown setion '{$section}'" );
			return false;
		}

		if ( $this->in_ignore( $insertion ) ) {
			WP_CLI::debug( "Path {$insertion} already ignored" );
			return true;
		}

		$section_start   = array_search( $this->sections[ $section ], $lines, true );
		$insert_location = count( $lines ) - 1; // Default to end of file.

		// Section found; insert at the end.
		if ( false !== $section_start ) {
			$section_part = array_slice( $lines, $section_start );
			$section_end  = array_search( '', $section_part, true );

			if ( false !== $section_end ) {
				$insert_location = $section_start + $section_end;
			}
		}

		array_splice( $this->lines, $insert_location, 0, $insertion );

		return true;
	}

	/**
	 * Sort ignores in specified section.
	 *
	 * @param string $section section key to sort.
	 * @return boolean
	 */
	public function sort_section( $section ) {
		$lines = $this->lines;

		if ( ! array_key_exists( $section, $this->sections ) ) {
			WP_CLI::debug( "Unable to sort section in ignore file. Unknown setion '{$section}'" );
			return false;
		}

		$section_start = array_search( $this->sections[ $section ], $lines, true ) + 1; // skip the comment block.

		if ( false === $section_start ) {
			WP_CLI::debug( "Unable to sort section in ignore file. '{$section}' start not found" );
			return;
		}

		$section_part = array_slice( $lines, $section_start );
		$section_end  = array_search( '', $section_part, true );

		if ( false === $section_end ) {
			WP_CLI::debug( "Unable to sort section in ignore file. '{$section}' end not found" );
			return;
		}

		$section_block = array_slice( $section_part, 0, $section_end );
		sort( $section_block );
		array_splice( $lines, $section_start, $section_end, $section_block );
		$this->lines = $lines;
	}

	/**
	 * Remove ignore.
	 *
	 * @param string $path path to remove from ignore.
	 * @return boolean true if removed or false if not found.
	 */
	public function remove( $path ) {
		$lines    = $this->lines;
		$ignore   = trim( $path );
		$position = array_search( $ignore, $lines, true );

		if ( false !== $position ) {
			unset( $lines[ $position ] );
			return true;
		}

		return false;
	}

	/**
	 * Load up the ignore file
	 *
	 * @return boolean if loaded or not
	 */
	public function load() {
		if ( ! file_exists( $this->ignore_file ) ) {
			return false; // because there is no ignore file.
		}

		$ignore_src       = file_get_contents( $this->ignore_file );
		$this->ignore_src = trim( $ignore_src );

		return true;
	}

	/**
	 * Get an object of all ignore lines
	 *
	 * @param string $src source of .gitignore file.
	 * @return array lines by reference
	 */
	public function &parse_ignore( $src = null ) {
		if ( ! $src ) {
			$src = $this->ignore_src;
		}

		$block_start = $this->sections['start'];
		$block_end   = $this->sections['end'];
		$lines       = preg_split( '/\r\n|\n|\r/', trim( $src ) );

		// find the project block bounds.
		$project_block_start = array_search( $block_start, $lines, true );
		$project_block_end   = array_search( $block_end, $lines, true );

		if ( false === $project_block_start && false === $project_block_end ) {
			// no project block so we'll create one and append it to the existing lines.
			$template      = dirname( __FILE__ ) . '/templates/gitignore.mustache';
			$project_block = WP_CLI\Utils\mustache_render( $template, ABSPATH );
			$project_lines = preg_split( '/\r\n|\n|\r/', $project_block );
			$lines         = array_merge( $lines, $project_lines );

			// find the newly inserted project block bounds.
			$project_block_start = array_search( $block_start, $lines, true );
			$project_block_end   = array_search( $block_end, $lines, true );
		}

		$this->lines         = array_map( 'trim', $lines );
		$this->project_start = $project_block_start;
		$this->project_end   = $project_block_end + 1;

		return $this->lines;
	}

	/**
	 * Create ignore file when it is missing.
	 *
	 * @param string $path file to create.
	 * @return void
	 */
	public function create( $path = null ) {
		if ( ! $path ) {
			$path = $this->ignore_file;
		}

		if ( file_exists( $path ) ) {
			return;
		}

		$template         = dirname( __FILE__ ) . '/templates/gitignore.mustache';
		$this->ignore_src = WP_CLI\Utils\mustache_render( $template, ABSPATH );
		$this->parse_ignore();
		file_put_contents( $this->ignore_file, $this->ignore_src );
	}

	/**
	 * Save current structure to the ignore file
	 *
	 * @throws Exception When file isn't writeable.
	 * @return boolean
	 */
	public function save() {
		if ( ! file_exists( $this->ignore_file ) ) {
			WP_CLI::log( "Creating {$this->ignore_file}" );
			$this->create();
		}

		if ( ! is_writable( $this->ignore_file ) ) {
			throw new Exception( "{$basename} is not writable." );
		}

		// Prevent any funnybizness.
		if ( empty( $this->lines ) ) {
			return false;
		}

		// Make sure our sections are in logical order.
		$this->sort_section( 'plugins' );
		$this->sort_section( 'themes' );

		$content = implode( PHP_EOL, $this->lines );
		$result  = file_put_contents( $this->ignore_file, $content );

		if ( false === $result ) {
			WP_CLI::debug( 'Failed to update the ignore file' );
			return false;
		}

		$this->ignore_src = $result;

		return true;
	}
}
