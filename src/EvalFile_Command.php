<?php

class EvalFile_Command extends WP_CLI_Command {

	/**
	 * Loads and executes a PHP file.
	 *
	 * Note: because code is executed within a method, global variables need
	 * to be explicitly globalized.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : The path to the PHP file to execute.  Use '-' to run code from STDIN.
	 *
	 * [<arg>...]
	 * : One or more arguments to pass to the file. They are placed in the $args variable.
	 *
	 * [--skip-wordpress]
	 * : Load and execute file without loading WordPress.
	 *
	 * @when before_wp_load
	 *
	 * ## EXAMPLES
	 *
	 *     wp eval-file my-code.php value1 value2
	 */
	public function __invoke( $args, $assoc_args ) {
		$file = array_shift( $args );

		if ( '-' !== $file && ! file_exists( $file ) ) {
			WP_CLI::error( "'$file' does not exist." );
		}

		if ( null === \WP_CLI\Utils\get_flag_value( $assoc_args, 'skip-wordpress' ) ) {
			WP_CLI::get_runner()->load_wordpress();
		}

		self::_eval( $file, $args );
	}

	private static function _eval( $file, $args ) {
		if ( '-' === $file ) {
			eval( '?>' . file_get_contents( 'php://stdin' ) );
		} else {
			$file_contents = file_get_contents( $file );

			// Check for and remove she-bang.
			if ( 0 === strpos( $file_contents, '#!' ) ) {
				$file_contents = preg_replace( '/^(#!.*)$/im', '', $file_contents );
			}

			$file = realpath( $file );

			// Replace __FILE__ constant with value of $file.
			// We try to be smart and only replace the constant when it is not within quotes.
			// Regular expressions being stateless, this is probably not 100% correct for edge cases.
			// See https://regex101.com/r/9hXp5d/1
			$file_contents = preg_replace_callback(
				'/(?>\'[^\']*?\')|(?>"[^"]*?")|(?<target>__FILE__)/m',
				function ( $matches ) use ( $file ) {
					if ( array_key_exists( 'target', $matches ) ) {
						return "'{$file}'";
					}

					return $matches[0];
				},
				$file_contents
			);

			eval( '?>' . $file_contents );
		}
	}
}

