<?php

use WP_CLI\Utils;

class EvalFile_Command extends WP_CLI_Command {

	/**
	 * Regular expression pattern to match the shell shebang.
	 *
	 * @var string
	 */
	const SHEBANG_PATTERN = '/^(#!.*)$/m';

	/**
	 * Regular expression pattern to match __FILE__ and __DIR__ constants.
	 *
	 * We try to be smart and only replace the constants when they are not within quotes.
	 * Regular expressions being stateless, this is probably not 100% correct for edge cases.
	 *
	 * @see https://regex101.com/r/9hXp5d/4/
	 *
	 * @var string
	 */
	const FILE_DIR_PATTERN = '/(?>\'[^\']*?\')|(?>"[^"]*?")|(?<file>\b__FILE__\b)|(?<dir>\b__DIR__\b)/m';

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

		if ( null === Utils\get_flag_value( $assoc_args, 'skip-wordpress' ) ) {
			WP_CLI::get_runner()->load_wordpress();
		}

		self::execute_eval( $file, $args );
	}

	/**
	 * Evaluate a provided file.
	 *
	 * @param string $file Filepath to execute, or - for STDIN.
	 * @param mixed  $args Array of positional arguments to pass to the file.
	 */
	private static function execute_eval( $file, $args ) {
		if ( '-' === $file ) {
			eval( '?>' . file_get_contents( 'php://stdin' ) );
		} else {
			$file_contents = file_get_contents( $file );

			// Check for and remove she-bang.
			if ( 0 === strncmp( $file_contents, '#!', 2 ) ) {
				$file_contents = preg_replace( static::SHEBANG_PATTERN, '', $file_contents );
			}

			$file = realpath( $file );
			$dir  = dirname( $file );

			// Replace __FILE__ and __DIR__ constants with value of $file or $dir.
			$file_contents = preg_replace_callback(
				static::FILE_DIR_PATTERN,
				static function ( $matches ) use ( $file, $dir ) {
					if ( ! empty( $matches['file'] ) ) {
						return "'{$file}'";
					}

					if ( ! empty( $matches['dir'] ) ) {
						return "'{$dir}'";
					}

					return $matches[0];
				},
				$file_contents
			);

			eval( '?>' . $file_contents );
		}
	}
}

