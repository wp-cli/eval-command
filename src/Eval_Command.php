<?php

use WP_CLI\Utils;

class Eval_Command extends WP_CLI_Command {

	/**
	 * Executes arbitrary PHP code.
	 *
	 * Note: because code is executed within a method, global variables need
	 * to be explicitly globalized.
	 *
	 * ## OPTIONS
	 *
	 * <php-code>
	 * : The code to execute, as a string.
	 *
	 * [--skip-wordpress]
	 * : Execute code without loading WordPress.
	 *
	 * [--hook=<hook>]
	 * : Execute code after a specific WordPress hook has fired.
	 *
	 * ## EXAMPLES
	 *
	 *     # Display WordPress content directory.
	 *     $ wp eval 'echo WP_CONTENT_DIR;'
	 *     /var/www/wordpress/wp-content
	 *
	 *     # Generate a random number.
	 *     $ wp eval 'echo rand();' --skip-wordpress
	 *     479620423
	 *
	 *     # Execute code after WordPress is fully loaded.
	 *     $ wp eval 'echo "Current user: " . wp_get_current_user()->user_login;' --hook=wp_loaded
	 *     Current user: admin
	 *
	 * @when before_wp_load
	 */
	public function __invoke( $args, $assoc_args ) {

		// @phpstan-ignore closure.unusedUse
		$execute_closure = function () use ( $args, $assoc_args ) {
			eval( $args[0] );
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
}
