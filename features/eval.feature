Feature: Evaluating PHP code and files.

  Scenario: Basics
    Given a WP install

    When I run `wp eval 'var_dump(defined("WP_CONTENT_DIR"));'`
    Then STDOUT should contain:
      """
      bool(true)
      """

    Given a script.php file:
      """
      <?php
      WP_CLI::line( implode( ' ', $args ) );
      """

    When I run `wp eval-file script.php foo bar`
    Then STDOUT should contain:
      """
      foo bar
      """

    Given a script.sh file:
      """
      #! /bin/bash
      <?php
      WP_CLI::line( implode( ' ', $args ) );
      """

    When I run `wp eval-file script.sh foo bar`
    Then STDOUT should contain:
      """
      foo bar
      """
    But STDOUT should not contain:
      """
      #!
      """

  Scenario: Has access to associative args
    Given a WP installation
    And a wp-cli.yml file:
      """
      eval:
        foo: bar
      post list:
        format: count
      """

    When I run `wp eval 'echo json_encode( $assoc_args );'`
    Then STDOUT should be JSON containing:
      """
      {"foo": "bar"}
      """

  Scenario: Eval without WordPress install
    Given an empty directory

    When I try `wp eval 'var_dump(defined("WP_CONTENT_DIR"));'`
    Then STDERR should contain:
      """
      Error: This does not seem to be a WordPress install
      """
    And the return code should be 1

    When I run `wp eval 'var_dump(defined("WP_CONTENT_DIR"));' --skip-wordpress`
    Then STDOUT should contain:
      """
      bool(false)
      """

  Scenario: Eval file without WordPress install
    Given an empty directory
    And a script.php file:
      """
      <?php
      var_dump(defined("WP_CONTENT_DIR"));
      """

    When I try `wp eval-file script.php`
    Then STDERR should contain:
      """
      Error: This does not seem to be a WordPress install
      """
    And the return code should be 1

    When I run `wp eval-file script.php --skip-wordpress`
    Then STDOUT should contain:
      """
      bool(false)
      """

  Scenario: Eval stdin with args
    Given an empty directory
    And a script.php file:
      """
      <?php
      WP_CLI::line( implode( ' ', $args ) );
      """

    When I run `cat script.php | wp eval-file - x y z --skip-wordpress`
    Then STDOUT should contain:
      """
      x y z
      """

  @require-php-7.0
  Scenario: Eval stdin with use-include parameter without WordPress install
    Given an empty directory
    And a script.php file:
      """
      <?php
      declare(strict_types=1);
      WP_CLI::line( implode( ' ', $args ) );
      """

    When I try `cat script.php | wp eval-file - foo bar --skip-wordpress --use-include`
    Then STDERR should be:
      """
      Error: "-" and "--use-include" parameters cannot be used at the same time
      """
    And the return code should be 1

  @require-php-7.0
  Scenario: Eval file with use-include parameter without WordPress install
    Given an empty directory
    And a script.php file:
      """
      <?php
      declare(strict_types=1);
      WP_CLI::line( implode( ' ', $args ) );
      """

    When I run `wp eval-file script.php foo bar --skip-wordpress --use-include`
    Then STDOUT should contain:
      """
      foo bar
      """

  @require-php-7.0
  Scenario: Eval stdin with use-include parameter
    Given a WP install
    And a script.php file:
      """
      <?php
      declare(strict_types=1);
      WP_CLI::line( implode( ' ', $args ) );
      """
    When I try `cat script.php | wp eval-file - foo bar --use-include`
    Then STDERR should be:
      """
      Error: "-" and "--use-include" parameters cannot be used at the same time
      """
    And the return code should be 1

  @require-php-7.0
  Scenario: Eval file with use-include parameter
    Given a WP install
    And a script.php file:
      """
      <?php
      declare(strict_types=1);
      WP_CLI::line( implode( ' ', $args ) );
      """

    When I run `wp eval-file script.php foo bar --use-include`
    Then STDOUT should contain:
      """
      foo bar
      """

  Scenario: Eval-file will use the correct __FILE__ constant value
    Given an empty directory
    And a script.php file:
      """
      <?php
      echo __FILE__;
      """

    When I run `wp eval-file script.php --skip-wordpress`
    Then STDOUT should contain:
      """
      /script.php
      """
    And STDOUT should not contain:
      """
      eval()'d code
      """

  Scenario: Eval-file will not replace __FILE__ when quoted
    Given an empty directory
    And a script.php file:
      """
      <?php
      echo '__FILE__';
      echo "__FILE__";
      echo '"__FILE__"';
      echo "'__FILE__'";

      echo ' foo __FILE__ bar ';
      echo " foo __FILE__ bar ";
      echo '" foo __FILE__ bar "';
      echo "' foo __FILE__ bar '";
      """

    When I run `wp eval-file script.php --skip-wordpress`
    Then STDOUT should contain:
      """
      __FILE__
      """
    And STDOUT should not contain:
      """
      /script.php
      """
    And STDOUT should not contain:
      """
      eval()'d code
      """

  Scenario: Eval-file can handle both quoted and unquoted __FILE__ correctly
    Given an empty directory
    And a script.php file:
      """
      <?php
      echo ' __FILE__ => ' . __FILE__;
      """

    When I run `wp eval-file script.php --skip-wordpress`
    Then STDOUT should contain:
      """
      __FILE__ =>
      """
    And STDOUT should contain:
      """
      /script.php
      """
    And STDOUT should not contain:
      """
      eval()'d code
      """

  Scenario: Eval-file will use the correct __FILE__ constant value
    Given an empty directory
    And a script.php file:
      """
      <?php
      echo __FILE__ . PHP_EOL;
      """
    And a dir_script.php file:
      """
      <?php
      echo __DIR__ . '/script.php' . PHP_EOL;
      """
    And I run `wp eval-file script.php --skip-wordpress`
    And save STDOUT as {FILE_OUTPUT}

    When I run `wp eval-file dir_script.php --skip-wordpress`
    Then STDOUT should be:
      """
      {FILE_OUTPUT}
      """
    And STDOUT should not contain:
      """
      eval()'d code
      """

  Scenario: Eval with --hook flag
    Given a WP install

    When I run `wp eval 'echo "Hook: " . current_action();' --hook=init`
    Then STDOUT should contain:
      """
      Hook: init
      """

    When I run `wp eval 'echo "Hook: " . current_action();' --hook=wp_loaded`
    Then STDOUT should contain:
      """
      Hook: wp_loaded
      """

  Scenario: Eval-file with --hook flag
    Given a WP install
    And a hook-script.php file:
      """
      <?php
      echo "Hook: " . current_action() . "\n";
      echo "Is admin: " . (is_admin() ? 'yes' : 'no') . "\n";
      """

    When I run `wp eval-file hook-script.php --hook=init`
    Then STDOUT should contain:
      """
      Hook: init
      """
    And STDOUT should contain:
      """
      Is admin:
      """

    When I run `wp eval-file hook-script.php --hook=wp_loaded`
    Then STDOUT should contain:
      """
      Hook: wp_loaded
      """

  Scenario: Eval with --hook and --skip-wordpress should error
    Given a WP install

    When I try `wp eval 'echo "test";' --hook=init --skip-wordpress`
    Then STDERR should contain:
      """
      Error: The --hook parameter cannot be used with --skip-wordpress.
      """
    And the return code should be 1

  Scenario: Eval-file with --hook and --skip-wordpress should error
    Given an empty directory
    And a script.php file:
      """
      <?php
      echo "test";
      """

    When I try `wp eval-file script.php --hook=init --skip-wordpress`
    Then STDERR should contain:
      """
      Error: The --hook parameter cannot be used with --skip-wordpress.
      """
    And the return code should be 1

  Scenario: Eval-file with --hook and positional arguments
    Given a WP install
    And a args-script.php file:
      """
      <?php
      echo "Hook: " . current_action() . "\n";
      echo "Args: " . implode(' ', $args) . "\n";
      """

    When I run `wp eval-file args-script.php arg1 arg2 --hook=init`
    Then STDOUT should contain:
      """
      Hook: init
      """
    And STDOUT should contain:
      """
      Args: arg1 arg2
      """

