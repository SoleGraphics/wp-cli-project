<?php
if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

spl_autoload_register(
	function( $class ) {
		$class = ltrim( $class, '\\' );

		if ( 0 !== stripos( $class, 'solegraphics\\Project\\' ) ) {
			return;
		}

		$parts = explode( '\\', $class );
		array_shift( $parts ); // Don't need "solegraphics\Project"
		array_shift( $parts );

		$last = array_pop( $parts ); // File should be 'class-[...].php'
		$last = 'class-' . $last . '.php';

		$parts[] = $last;
		$file    = dirname( __FILE__ ) . '/inc/' . str_replace( '_', '-', strtolower( implode( $parts, '/' ) ) );

		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

WP_CLI::add_command( 'project', 'solegraphics\Project\Command' );
