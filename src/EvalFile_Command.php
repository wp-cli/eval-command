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
	 * Cache for STDIN contents to support alias groups.
	 *
	 * When using alias groups, the same command is executed multiple times
	 * in separate processes. STDIN can only be read once, so we cache it
	 * in a temporary file that persists across processes.
	 *
	 * @var string|null
	 */
	private static $stdin_cache = null;

	/**
	 * Temporary file path for STDIN cache.
	 *
	 * @var string|null
	 */
	private static $stdin_cache_file = null;

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
	 * : One or more positional arguments to pass to the file. They are placed in the $args variable.
	 *
	 * [--skip-wordpress]
	 * : Load and execute file without loading WordPress.
	 *
	 * [--use-include]
	 * : Process the provided file via include instead of evaluating its contents.
	 *
	 * [--hook=<hook>]
	 * : Execute file after a specific WordPress hook has fired.
	 *
	 * @when before_wp_load
	 *
	 * ## EXAMPLES
	 *
	 *     # Execute file my-code.php and pass value1 and value2 arguments.
	 *     # Access arguments in $args array ($args[0] = value1, $args[1] = value2).
	 *     $ wp eval-file my-code.php value1 value2
	 *
	 *     # Execute file after the 'init' hook.
	 *     $ wp eval-file my-code.php --hook=init
	 */
	public function __invoke( $args, $assoc_args ) {
		$file = array_shift( $args );

		if ( '-' !== $file && ! file_exists( $file ) ) {
			WP_CLI::error( "'$file' does not exist." );
		}

		$use_include = Utils\get_flag_value( $assoc_args, 'use-include', false );

		if ( '-' === $file && $use_include ) {
				WP_CLI::error( '"-" and "--use-include" parameters cannot be used at the same time' );
		}

		$execute_closure = function () use ( $file, $args, $use_include ) {
			self::execute_eval( $file, $args, $use_include );
		};

		$hook           = Utils\get_flag_value( $assoc_args, 'hook' );
		$skip_wordpress = Utils\get_flag_value( $assoc_args, 'skip-wordpress' );

		if ( $hook && null !== $skip_wordpress ) {
			WP_CLI::error( 'The --hook parameter cannot be used with --skip-wordpress.' );
		}

		if ( $hook ) {
			WP_CLI::add_wp_hook( $hook, $execute_closure );
		}

		if ( null === $skip_wordpress ) {
			WP_CLI::get_runner()->load_wordpress();
		}

		if ( ! $hook ) {
			$execute_closure();
		}
	}

	/**
	 * Evaluate a provided file.
	 *
	 * @param string $file Filepath to execute, or - for STDIN.
	 * @param mixed  $positional_args Array of positional arguments to pass to the file.
	 * @param bool $use_include Process the provided file via include instead of evaluating its contents.
	 */
	private static function execute_eval( $file, $positional_args, $use_include ) {
		global $args;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		$args = $positional_args;
		unset( $positional_args );

		if ( '-' === $file ) {
			// Cache STDIN contents to support alias groups.
			// When using alias groups, each site runs in a separate process,
			// but they all share the same STDIN. Once STDIN is read, it's consumed.
			// We cache it in a temporary file that persists across processes.
			if ( null === self::$stdin_cache ) {
				// Get a unique identifier for the cache file
				// Use parent PID on Unix-like systems for sharing across child processes
				// On Windows, this feature may have limitations due to lack of posix_getppid()
				if ( function_exists( 'posix_getppid' ) ) {
					$cache_id = posix_getppid();
				} else {
					// On Windows or systems without POSIX, use an environment variable if available
					// or fall back to current PID (which won't share across processes)
					$cache_id = getenv( 'WP_CLI_STDIN_CACHE_ID' ) ?: getmypid();
				}
				self::$stdin_cache_file = sys_get_temp_dir() . '/wp-cli-eval-stdin-' . $cache_id . '.php';

				// Check if cache file already exists (created by a previous subprocess)
				if ( file_exists( self::$stdin_cache_file ) ) {
					$stdin_contents = file_get_contents( self::$stdin_cache_file );
					if ( false === $stdin_contents ) {
						WP_CLI::error( 'Failed to read from STDIN cache file.' );
					}
					self::$stdin_cache = (string) $stdin_contents;

					// Clean up old cache files (older than 1 hour) to prevent accumulation
					self::cleanup_old_cache_files();
				} else {
					// First process: read from STDIN and cache it
					$stdin_contents = file_get_contents( 'php://stdin' );
					if ( false === $stdin_contents ) {
						WP_CLI::error( 'Failed to read from STDIN.' );
					}
					self::$stdin_cache = (string) $stdin_contents;

					// Save to cache file for subsequent processes
					$write_result = file_put_contents( self::$stdin_cache_file, self::$stdin_cache );
					if ( false === $write_result ) {
						WP_CLI::error( 'Failed to write STDIN cache file.' );
					}

					// Clean up old cache files (older than 1 hour) to prevent accumulation
					self::cleanup_old_cache_files();

					// Note: We intentionally don't clean up the current cache file immediately.
					// If we delete it in the shutdown function, subsequent child processes
					// won't be able to read it. The cleanup_old_cache_files() method handles
					// removal of stale cache files.
				}
			}
			eval( '?>' . self::$stdin_cache );
		} elseif ( $use_include ) {
			include $file;
		} else {
			$file_contents = (string) file_get_contents( $file );

			// Adjust for __FILE__ and __DIR__ magic constants.
			$file_contents = Utils\replace_path_consts( $file_contents, $file );

			// Check for and remove she-bang.
			if ( 0 === strncmp( $file_contents, '#!', 2 ) ) {
				$file_contents = preg_replace( static::SHEBANG_PATTERN, '', $file_contents );
			}

			eval( '?>' . $file_contents );
		}
	}

	/**
	 * Clean up old STDIN cache files to prevent accumulation.
	 *
	 * Removes cache files older than 1 hour from the system temp directory.
	 * This is called when creating or reading a cache file to ensure stale
	 * files don't accumulate if processes terminate unexpectedly.
	 */
	private static function cleanup_old_cache_files() {
		$temp_dir      = sys_get_temp_dir();
		$cache_pattern = $temp_dir . '/wp-cli-eval-stdin-*.php';
		$cache_files   = glob( $cache_pattern );
		$one_hour_ago  = time() - 3600;

		if ( ! empty( $cache_files ) ) {
			foreach ( $cache_files as $cache_file ) {
				// Skip the current cache file
				if ( $cache_file === self::$stdin_cache_file ) {
					continue;
				}

				// Delete files older than 1 hour
				$file_time = filemtime( $cache_file );
				if ( false !== $file_time && $file_time < $one_hour_ago ) {
					@unlink( $cache_file );
				}
			}
		}
	}
}
