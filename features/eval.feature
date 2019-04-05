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
